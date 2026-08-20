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
define( 'SSB_DB_VERSION', '1.6.0' );

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
		// 親を先に定義しておくこと（作成順にそのまま使う）。
		'booking' => array(
			'title'    => '予約',
			'template' => '',
		),
		'booking/done' => array(
			'title'    => '予約完了',
			'template' => 'page-checkout-done.php',
		),
		// 規約類は専用テンプレートを持たず、運営が管理画面で本文を編集する。
		'terms'         => array(
			'title'    => '利用規約',
			'template' => '',
		),
		'tokushoho'     => array(
			'title'    => '特定商取引法に基づく表記',
			'template' => '',
		),
		'cancel-policy' => array(
			'title'    => 'キャンセルポリシー',
			'template' => '',
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
	foreach ( ssb_get_managed_pages() as $path => $page ) {
		if ( ssb_get_page_by_slug( $path ) ) {
			continue;
		}

		// 'booking/done' のような入れ子は、親を引いてから子として作る。
		$slug   = $path;
		$parent = 0;

		if ( false !== strpos( $path, '/' ) ) {
			$parts       = explode( '/', $path );
			$slug        = array_pop( $parts );
			$parent_page = ssb_get_page_by_slug( implode( '/', $parts ) );

			if ( ! $parent_page ) {
				continue;
			}

			$parent = $parent_page->ID;
		}

		wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_name'      => $slug,
				'post_parent'    => $parent,
				'post_title'     => $page['title'],
				'post_status'    => 'publish',
				'post_content'   => ssb_seed_page_content( $path ),
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

/**
 * 規約類ページの雛形本文を返す.
 *
 * ページの新規作成時にだけ使う。事業者情報や金額など運営が決める箇所は
 * 記入欄のまま残してある。公開前に必ず内容を埋め、専門家の確認を受けること。
 *
 * @param string $path ページのパス。
 * @return string 本文。雛形が無ければ空文字。
 */
function ssb_seed_page_content( $path ) {
	if ( 'terms' === $path ) {
		return implode(
			"\n\n",
			array(
				'<p>本規約は、当サイトが提供する単発相談サービス（以下「本サービス」）の利用条件を定めるものです。受講者および講師は、本規約に同意のうえ本サービスをご利用ください。</p>',
				'<h2>第1条（適用）</h2><p>本規約は、本サービスの利用に関する当サイトと利用者との間の一切の関係に適用されます。</p>',
				'<h2>第2条（利用登録）</h2><p>講師として出品を希望する方は、所定の方法で申請を行い、当サイトの審査を経て承認された場合に登録が完了します。当サイトは、審査の結果を理由の開示なく通知することがあります。</p>',
				'<h2>第3条（予約と決済）</h2><p>受講者は、講師が登録した予約枠から希望の日時を選び、クレジットカードにより受講料をお支払いいただきます。決済が完了した時点で予約が確定します。受講料は当サイトが受領します。</p>',
				'<h2>第4条（キャンセル）</h2><p>キャンセルの取り扱いは<a href="/cancel-policy/">キャンセルポリシー</a>に定めるとおりとします。</p>',
				'<h2>第5条（禁止事項）</h2><p>利用者は、法令または公序良俗に違反する行為、当サイトの運営を妨害する行為、他の利用者の権利を侵害する行為、本サービスを通じて知り得た情報を目的外に利用する行為を行ってはなりません。</p>',
				'<h2>第6条（免責事項）</h2><p>当サイトは、講師が提供する相談内容の正確性、有用性、特定目的への適合性について保証しません。相談の結果生じた損害について、当サイトの故意または重過失による場合を除き、責任を負いません。</p>',
				'<h2>第7条（規約の変更）</h2><p>当サイトは、必要と判断した場合、利用者に通知することなく本規約を変更できるものとします。</p>',
				'<p><em>（このページは雛形です。事業の実態に合わせて内容を確認・修正し、公開前に専門家のご確認をお願いします。）</em></p>',
			)
		);
	}

	if ( 'tokushoho' === $path ) {
		return implode(
			"\n\n",
			array(
				'<p>特定商取引法に基づき、以下のとおり表示します。</p>',
				'<table><tbody>'
					. '<tr><th>販売事業者名</th><td>（記入してください）</td></tr>'
					. '<tr><th>運営責任者</th><td>（記入してください）</td></tr>'
					. '<tr><th>所在地</th><td>（記入してください）</td></tr>'
					. '<tr><th>電話番号</th><td>（記入してください）</td></tr>'
					. '<tr><th>メールアドレス</th><td>（記入してください）</td></tr>'
					. '<tr><th>販売価格</th><td>各講座のページに表示された金額（税込）</td></tr>'
					. '<tr><th>商品代金以外の必要料金</th><td>インターネット接続にかかる通信料は利用者のご負担となります</td></tr>'
					. '<tr><th>支払方法</th><td>クレジットカード決済</td></tr>'
					. '<tr><th>支払時期</th><td>予約手続きの完了時</td></tr>'
					. '<tr><th>サービスの提供時期</th><td>予約時に指定された日時</td></tr>'
					. '<tr><th>キャンセル・返金</th><td><a href="/cancel-policy/">キャンセルポリシー</a>に定めるとおり</td></tr>'
					. '<tr><th>動作環境</th><td>（オンライン相談に必要な環境を記入してください）</td></tr>'
					. '</tbody></table>',
				'<p><em>（このページは雛形です。「（記入してください）」の箇所は法令上の必須記載事項です。公開前に必ずご記入ください。）</em></p>',
			)
		);
	}

	if ( 'cancel-policy' === $path ) {
		return implode(
			"\n\n",
			array(
				'<p>ご予約のキャンセルおよび日程変更の取り扱いは以下のとおりです。</p>',
				'<h2>受講者からのキャンセル</h2>'
					. '<table><tbody>'
					. '<tr><th>受講日の◯日前まで</th><td>（記入してください：例 全額返金）</td></tr>'
					. '<tr><th>受講日の◯日前〜前日</th><td>（記入してください：例 50%を返金）</td></tr>'
					. '<tr><th>当日および無連絡の不参加</th><td>（記入してください：例 返金なし）</td></tr>'
					. '</tbody></table>',
				'<h2>講師都合によるキャンセル</h2><p>講師の都合により実施できなくなった場合は、全額を返金します。日程の振り替えをご希望の場合は運営までご連絡ください。</p>',
				'<h2>キャンセルのご連絡</h2><p>キャンセルをご希望の場合は、予約確認メールに記載の連絡先までご連絡ください。返金は決済に使用したクレジットカードへの返金処理により行います。</p>',
				'<p><em>（このページは雛形です。返金の条件と割合は運営方針に合わせてご記入ください。）</em></p>',
			)
		);
	}

	return '';
}
