<?php
/**
 * テーブルと固定ページの作成.
 *
 * テーマ有効化時（after_switch_theme）にテーブルと SPEC 5 の固定ページを作成する。
 * スキーマや管理対象ページを変更したら SSB_DB_VERSION を上げること。管理画面アクセス時に
 * バージョン差分を検知して自動で再実行する。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

/**
 * インストール処理のバージョン。スキーマや管理対象の固定ページを変更したら必ず上げる。
 * 上げると次に wp-admin を開いた時点で ssb_install() が再実行される。
 */
define( 'SSB_DB_VERSION', '1.4.0' );

/**
 * バージョンを保存するオプション名.
 */
define( 'SSB_DB_VERSION_OPTION', 'ssb_db_version' );

/**
 * テーブル名を返す.
 *
 * テーブル名は $wpdb->prepare() でプレースホルダ化できないため、
 * ここでホワイトリストとして一元管理し、呼び出し側で文字列連結しないこと。
 *
 * @param string $name instructors|courses|slots|bookings.
 * @return string テーブル名。未知のキーなら空文字。
 */
function ssb_table( $name ) {
	$tables = ssb_get_tables();

	if ( ! isset( $tables[ $name ] ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html( sprintf( '未知のテーブルキー: %s', $name ) ), '0.1.0' );
		return '';
	}

	return $tables[ $name ];
}

/**
 * 全テーブル名を返す.
 *
 * @return array<string,string> キー => テーブル名。
 */
function ssb_get_tables() {
	global $wpdb;

	return array(
		'instructors' => $wpdb->prefix . 'ssb_instructors',
		'courses'     => $wpdb->prefix . 'ssb_courses',
		'slots'       => $wpdb->prefix . 'ssb_slots',
		'bookings'    => $wpdb->prefix . 'ssb_bookings',
	);
}

/**
 * スキーマ定義（dbDelta 用の CREATE TABLE 文）を返す.
 *
 * dbDelta は書式に厳密なので、以下を守ること。
 * - 1カラム1行
 * - PRIMARY KEY の後ろは半角スペース2つ
 * - INDEX ではなく KEY を使い、必ずインデックス名を付ける
 *
 * @return string[] CREATE TABLE 文の配列。
 */
function ssb_get_schema() {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$tables          = ssb_get_tables();

	$schema = array();

	// 講師.
	$schema[] = "CREATE TABLE {$tables['instructors']} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		status varchar(20) NOT NULL DEFAULT 'pending',
		display_name varchar(100) NOT NULL DEFAULT '',
		profile text,
		course_plan text,
		email varchar(255) NOT NULL DEFAULT '',
		gcal_ics_url text,
		gcal_cache longtext,
		gcal_fetched_at datetime DEFAULT NULL,
		applied_at datetime DEFAULT NULL,
		approved_at datetime DEFAULT NULL,
		interview_at datetime DEFAULT NULL,
		admin_note text,
		PRIMARY KEY  (id),
		KEY user_id (user_id)
	) {$charset_collate};";

	// 講座.
	$schema[] = "CREATE TABLE {$tables['courses']} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		instructor_id bigint(20) unsigned NOT NULL DEFAULT 0,
		title varchar(255) NOT NULL DEFAULT '',
		description text,
		content text,
		target text,
		image_id bigint(20) unsigned NOT NULL DEFAULT 0,
		price int unsigned NOT NULL DEFAULT 0,
		duration_min int unsigned NOT NULL DEFAULT 0,
		status varchar(20) NOT NULL DEFAULT 'draft',
		created_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY instructor_id (instructor_id)
	) {$charset_collate};";

	// 予約枠.
	$schema[] = "CREATE TABLE {$tables['slots']} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		course_id bigint(20) unsigned NOT NULL DEFAULT 0,
		start_at datetime DEFAULT NULL,
		end_at datetime DEFAULT NULL,
		status varchar(20) NOT NULL DEFAULT 'open',
		hold_token varchar(64) DEFAULT NULL,
		hold_expires_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY course_start (course_id,start_at),
		KEY status_expires (status,hold_expires_at)
	) {$charset_collate};";

	/*
	 * 予約.
	 *
	 * stripe_session_id は Webhook の重複処理を防ぐためユニーク。
	 * Checkout セッション作成前の pending 行は NULL を入れること。
	 * MySQL のユニークインデックスは NULL の重複を許すが空文字は許さないため、
	 * 空文字を入れると2件目の pending 作成で必ず失敗する。
	 */
	$schema[] = "CREATE TABLE {$tables['bookings']} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		slot_id bigint(20) unsigned NOT NULL DEFAULT 0,
		name varchar(100) NOT NULL DEFAULT '',
		email varchar(255) NOT NULL DEFAULT '',
		note text,
		amount int unsigned NOT NULL DEFAULT 0,
		status varchar(20) NOT NULL DEFAULT 'pending',
		stripe_session_id varchar(255) DEFAULT NULL,
		stripe_payment_intent varchar(255) DEFAULT NULL,
		created_at datetime DEFAULT NULL,
		paid_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY stripe_session_id (stripe_session_id),
		KEY slot_id (slot_id)
	) {$charset_collate};";

	return $schema;
}

/**
 * テーブルを作成・更新する.
 *
 * dbDelta() は差分のみ適用するため、既存データは失われない。
 *
 * @return void
 */
function ssb_install() {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	foreach ( ssb_get_schema() as $sql ) {
		dbDelta( $sql );
	}

	ssb_install_pages();

	// /courses/{id} のリライトルールを反映させる。
	flush_rewrite_rules( false );

	update_option( SSB_DB_VERSION_OPTION, SSB_DB_VERSION );
}
add_action( 'after_switch_theme', 'ssb_install' );

/**
 * バージョンが古ければテーブルを作り直す.
 *
 * テーマを切り替え直さなくてもスキーマ変更が反映されるようにするための保険。
 *
 * @return void
 */
function ssb_maybe_install() {
	if ( get_option( SSB_DB_VERSION_OPTION ) === SSB_DB_VERSION ) {
		return;
	}

	ssb_install();
}
add_action( 'admin_init', 'ssb_maybe_install' );

/**
 * テーマが管理する固定ページの定義.
 *
 * 実装順序に合わせてステップごとに追加していく。
 * ここに登録したページは有効化時に自動作成され、template のファイルが
 * templates/ 配下から読み込まれる（functions.php の ssb_template_include）。
 *
 * @return array<string,array<string,string>> スラッグ => title / template。
 */
function ssb_get_managed_pages() {
	return array(
		'courses' => array(
			'title'    => '講座一覧',
			'template' => 'page-courses.php',
		),
		'apply'   => array(
			'title'    => '講師申請',
			'template' => 'page-apply.php',
		),
		'login'   => array(
			'title'    => 'ログイン',
			'template' => 'page-login.php',
		),
		'mypage'  => array(
			'title'    => '講師マイページ',
			'template' => 'page-mypage.php',
		),
	);
}

/**
 * 固定ページを作成する.
 *
 * 同じスラッグのページが既にあれば何もしない。タイトルや本文は上書きしないので、
 * 運営が管理画面で文言を編集しても再インストールで消えない。
 *
 * @return void
 */
function ssb_install_pages() {
	foreach ( ssb_get_managed_pages() as $slug => $page ) {
		if ( ssb_get_page_by_slug( $slug ) ) {
			continue;
		}

		wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_name'      => $slug,
				'post_title'     => $page['title'],
				'post_status'    => 'publish',
				'post_content'   => '',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
	}
}

/**
 * スラッグから固定ページを取得する.
 *
 * @param string $slug スラッグ。
 * @return WP_Post|null 見つからなければ null。
 */
function ssb_get_page_by_slug( $slug ) {
	$page = get_page_by_path( $slug );

	return ( $page instanceof WP_Post ) ? $page : null;
}

/**
 * 管理対象ページの URL を返す.
 *
 * ページが未作成でも壊れないよう、その場合はスラッグから URL を組み立てる。
 *
 * @param string $slug スラッグ。
 * @return string
 */
function ssb_get_page_url( $slug ) {
	$page = ssb_get_page_by_slug( $slug );

	return $page ? (string) get_permalink( $page ) : home_url( '/' . $slug . '/' );
}
