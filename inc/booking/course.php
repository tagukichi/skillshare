<?php
/**
 * 講座.
 *
 * 講師マイページからの作成・編集・公開/非公開を担当する。
 * 操作対象が本人の講座かどうかは ssb_get_own_course() で必ず検証すること。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * ステータス
 * ---------------------------------------------------------------------- */

/**
 * 講座ステータスの一覧を返す.
 *
 * @return array<string,string> ステータス => 表示名。
 */
function ssb_course_statuses() {
	return array(
		'draft'     => '非公開',
		'published' => '公開中',
	);
}

/**
 * 講座ステータスの表示名を返す.
 *
 * @param string $status ステータス。
 * @return string
 */
function ssb_course_status_label( $status ) {
	$statuses = ssb_course_statuses();

	return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
}

/* -------------------------------------------------------------------------
 * データアクセス
 * ---------------------------------------------------------------------- */

/**
 * ID から講座を取得する.
 *
 * @param int $id 講座ID。
 * @return object|null
 */
function ssb_get_course( $id ) {
	global $wpdb;

	$table = ssb_table( 'courses' );

	return $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", (int) $id )
	);
}

/**
 * 講師の講座一覧を取得する.
 *
 * @param int $instructor_id 講師ID。
 * @return object[]
 */
function ssb_get_courses_by_instructor( $instructor_id ) {
	global $wpdb;

	$table = ssb_table( 'courses' );

	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE instructor_id = %d ORDER BY created_at DESC, id DESC",
			(int) $instructor_id
		)
	);
}

/**
 * 本人の講座だけを返す.
 *
 * 他人の講座IDを送られても掴めないようにするための関門。
 * マイページ側の操作は必ずこれを通すこと。
 *
 * @param int $course_id     講座ID。
 * @param int $instructor_id 講師ID。
 * @return object|null 本人の講座でなければ null。
 */
function ssb_get_own_course( $course_id, $instructor_id ) {
	$course = ssb_get_course( $course_id );

	if ( ! $course || (int) $course->instructor_id !== (int) $instructor_id ) {
		return null;
	}

	return $course;
}

/**
 * 講座を作成する.
 *
 * @param array<string,mixed> $data instructor_id / title / description / price / duration_min / status。
 * @return int 作成された講座ID。失敗時は 0。
 */
function ssb_insert_course( $data ) {
	global $wpdb;

	$ok = $wpdb->insert(
		ssb_table( 'courses' ),
		array(
			'instructor_id' => (int) $data['instructor_id'],
			'title'         => $data['title'],
			'description'   => $data['description'],
			'price'         => (int) $data['price'],
			'duration_min'  => (int) $data['duration_min'],
			'status'        => $data['status'],
			'created_at'    => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
	);

	return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * 講座を更新する.
 *
 * instructor_id は更新しない（付け替えを防ぐため）。
 *
 * @param int                 $id   講座ID。
 * @param array<string,mixed> $data title / description / price / duration_min / status。
 * @return bool
 */
function ssb_update_course( $id, $data ) {
	global $wpdb;

	$result = $wpdb->update(
		ssb_table( 'courses' ),
		array(
			'title'        => $data['title'],
			'description'  => $data['description'],
			'price'        => (int) $data['price'],
			'duration_min' => (int) $data['duration_min'],
			'status'       => $data['status'],
		),
		array( 'id' => (int) $id ),
		array( '%s', '%s', '%d', '%d', '%s' ),
		array( '%d' )
	);

	return false !== $result;
}

/**
 * 講座の公開状態を切り替える.
 *
 * @param int    $id     講座ID。
 * @param string $status draft / published。
 * @return bool
 */
function ssb_set_course_status( $id, $status ) {
	global $wpdb;

	if ( ! array_key_exists( $status, ssb_course_statuses() ) ) {
		return false;
	}

	$result = $wpdb->update(
		ssb_table( 'courses' ),
		array( 'status' => $status ),
		array( 'id' => (int) $id ),
		array( '%s' ),
		array( '%d' )
	);

	return false !== $result;
}

/* -------------------------------------------------------------------------
 * マイページからの操作
 * ---------------------------------------------------------------------- */

/**
 * 価格の下限（円）.
 *
 * Stripe の JPY 決済は 50 円未満を受け付けないため、ここで弾いておく。
 */
define( 'SSB_COURSE_MIN_PRICE', 50 );

/**
 * 価格の上限（円）.
 */
define( 'SSB_COURSE_MAX_PRICE', 1000000 );

/**
 * 所要時間の上限（分）.
 */
define( 'SSB_COURSE_MAX_DURATION', 480 );

/**
 * 講座の作成・更新の受け口.
 *
 * @return void
 */
function ssb_handle_save_course() {
	check_admin_referer( 'ssb_save_course', 'ssb_course_nonce' );

	$instructor = ssb_current_instructor();

	if ( ! $instructor ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;

	// 編集の場合は本人の講座かを必ず確認する。
	if ( $course_id && ! ssb_get_own_course( $course_id, $instructor->id ) ) {
		ssb_mypage_done( 'course_forbidden', array( 'tab' => 'courses' ) );
	}

	$back = array(
		'tab'    => 'courses',
		'course' => $course_id ? (string) $course_id : 'new',
	);

	// 数値は再表示のため生の文字列のまま持ち回る。
	$input = array(
		'title'        => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
		'description'  => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
		'price'        => isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '',
		'duration_min' => isset( $_POST['duration_min'] ) ? sanitize_text_field( wp_unslash( $_POST['duration_min'] ) ) : '',
		'status'       => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft',
	);

	$errors = array();

	if ( '' === $input['title'] ) {
		$errors[] = '講座タイトルを入力してください。';
	} elseif ( mb_strlen( $input['title'] ) > 255 ) {
		$errors[] = '講座タイトルは255文字以内で入力してください。';
	}

	if ( ! ctype_digit( $input['price'] ) ) {
		$errors[] = '価格は半角数字で入力してください。';
	} elseif ( (int) $input['price'] < SSB_COURSE_MIN_PRICE || (int) $input['price'] > SSB_COURSE_MAX_PRICE ) {
		$errors[] = sprintf(
			'価格は %d 円以上 %d 円以下で入力してください。',
			SSB_COURSE_MIN_PRICE,
			SSB_COURSE_MAX_PRICE
		);
	}

	if ( ! ctype_digit( $input['duration_min'] ) ) {
		$errors[] = '所要時間は半角数字で入力してください。';
	} elseif ( (int) $input['duration_min'] < 1 || (int) $input['duration_min'] > SSB_COURSE_MAX_DURATION ) {
		$errors[] = sprintf( '所要時間は 1 分以上 %d 分以下で入力してください。', SSB_COURSE_MAX_DURATION );
	}

	if ( ! array_key_exists( $input['status'], ssb_course_statuses() ) ) {
		$input['status'] = 'draft';
	}

	if ( $errors ) {
		ssb_mypage_fail( $errors, $input, $back );
	}

	if ( $course_id ) {
		if ( ! ssb_update_course( $course_id, $input ) ) {
			ssb_mypage_fail( array( '保存に失敗しました。' ), $input, $back );
		}

		ssb_mypage_done( 'course_updated', array( 'tab' => 'courses' ) );
	}

	$new_id = ssb_insert_course( array_merge( $input, array( 'instructor_id' => $instructor->id ) ) );

	if ( ! $new_id ) {
		ssb_mypage_fail( array( '講座の作成に失敗しました。' ), $input, $back );
	}

	ssb_mypage_done( 'course_created', array( 'tab' => 'courses' ) );
}
add_action( 'admin_post_ssb_save_course', 'ssb_handle_save_course' );

/**
 * 講座の公開／非公開を切り替える受け口.
 *
 * @return void
 */
function ssb_handle_toggle_course() {
	check_admin_referer( 'ssb_toggle_course', 'ssb_toggle_nonce' );

	$instructor = ssb_current_instructor();

	if ( ! $instructor ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
	$course    = $course_id ? ssb_get_own_course( $course_id, $instructor->id ) : null;

	if ( ! $course ) {
		ssb_mypage_done( 'course_forbidden', array( 'tab' => 'courses' ) );
	}

	$next = 'published' === $course->status ? 'draft' : 'published';

	if ( ! ssb_set_course_status( $course_id, $next ) ) {
		ssb_mypage_done( 'error', array( 'tab' => 'courses' ) );
	}

	ssb_mypage_done( 'published' === $next ? 'course_published' : 'course_unpublished', array( 'tab' => 'courses' ) );
}
add_action( 'admin_post_ssb_toggle_course', 'ssb_handle_toggle_course' );
