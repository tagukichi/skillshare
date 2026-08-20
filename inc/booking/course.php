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
 * @param array<string,mixed> $data instructor_id / title / description / content / target / image_id / price / duration_min / status。
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
			'content'       => $data['content'],
			'target'        => $data['target'],
			'image_id'      => (int) $data['image_id'],
			'price'         => (int) $data['price'],
			'duration_min'  => (int) $data['duration_min'],
			'status'        => $data['status'],
			'created_at'    => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
	);

	return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * 講座を更新する.
 *
 * instructor_id は更新しない（付け替えを防ぐため）。
 *
 * @param int                 $id   講座ID。
 * @param array<string,mixed> $data title / description / content / target / image_id / price / duration_min / status。
 * @return bool
 */
function ssb_update_course( $id, $data ) {
	global $wpdb;

	$result = $wpdb->update(
		ssb_table( 'courses' ),
		array(
			'title'        => $data['title'],
			'description'  => $data['description'],
			'content'      => $data['content'],
			'target'       => $data['target'],
			'image_id'     => (int) $data['image_id'],
			'price'        => (int) $data['price'],
			'duration_min' => (int) $data['duration_min'],
			'status'       => $data['status'],
		),
		array( 'id' => (int) $id ),
		array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ),
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
		'content'      => isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '',
		'target'       => isset( $_POST['target'] ) ? sanitize_textarea_field( wp_unslash( $_POST['target'] ) ) : '',
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

	// 画像。入れ替え・削除のときは古い添付を残さない。
	$current  = $course_id ? ssb_get_course( $course_id ) : null;
	$image_id = $current ? (int) $current->image_id : 0;

	if ( ! empty( $_POST['remove_image'] ) ) {
		ssb_delete_course_image( $image_id );
		$image_id = 0;
	}

	$uploaded = ssb_handle_course_image_upload();

	if ( is_wp_error( $uploaded ) ) {
		ssb_mypage_fail( array( $uploaded->get_error_message() ), $input, $back );
	}

	if ( $uploaded > 0 ) {
		ssb_delete_course_image( $image_id );
		$image_id = $uploaded;
	}

	$input['image_id'] = $image_id;

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

/* -------------------------------------------------------------------------
 * イメージ画像
 * ---------------------------------------------------------------------- */

/**
 * アップロードできる画像の上限サイズ（バイト）.
 */
define( 'SSB_COURSE_MAX_IMAGE_SIZE', 5 * MB_IN_BYTES );

/**
 * 受け付ける画像の MIME タイプ.
 *
 * @return string[]
 */
function ssb_course_image_mimes() {
	return array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
}

/**
 * 講座イメージ画像のアップロードを処理する.
 *
 * 講師には upload_files 権限を与えていないため、ここで自前に検証してから
 * media_handle_upload() に渡す。
 *
 * @return int|WP_Error 添付ID。アップロードが無ければ 0。
 */
function ssb_handle_course_image_upload() {
	if ( empty( $_FILES['image'] ) || empty( $_FILES['image']['name'] ) ) {
		return 0;
	}

	$error = isset( $_FILES['image']['error'] ) ? (int) $_FILES['image']['error'] : UPLOAD_ERR_NO_FILE;

	if ( UPLOAD_ERR_NO_FILE === $error ) {
		return 0;
	}

	if ( UPLOAD_ERR_OK !== $error ) {
		return new WP_Error( 'ssb_upload_error', '画像のアップロードに失敗しました。ファイルサイズをご確認ください。' );
	}

	$size = isset( $_FILES['image']['size'] ) ? (int) $_FILES['image']['size'] : 0;

	if ( $size > SSB_COURSE_MAX_IMAGE_SIZE ) {
		return new WP_Error(
			'ssb_image_too_large',
			sprintf( '画像は %d MB 以下にしてください。', (int) ( SSB_COURSE_MAX_IMAGE_SIZE / MB_IN_BYTES ) )
		);
	}

	$tmp  = isset( $_FILES['image']['tmp_name'] ) ? sanitize_text_field( $_FILES['image']['tmp_name'] ) : '';
	$name = isset( $_FILES['image']['name'] ) ? sanitize_file_name( $_FILES['image']['name'] ) : '';

	// 拡張子ではなく中身を見て判定する。
	$check = wp_check_filetype_and_ext( $tmp, $name );

	if ( empty( $check['type'] ) || ! in_array( $check['type'], ssb_course_image_mimes(), true ) ) {
		return new WP_Error( 'ssb_bad_image', '画像は JPEG / PNG / WebP / GIF のいずれかを選んでください。' );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$attachment_id = media_handle_upload(
		'image',
		0,
		array(),
		array(
			'test_form' => false,
			'mimes'     => array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'webp'         => 'image/webp',
				'gif'          => 'image/gif',
			),
		)
	);

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	return (int) $attachment_id;
}

/**
 * 講座イメージ画像の添付を削除する.
 *
 * 他の講座がまだ使っている場合は消さない。
 *
 * @param int $attachment_id 添付ID。
 * @return void
 */
function ssb_delete_course_image( $attachment_id ) {
	global $wpdb;

	$attachment_id = (int) $attachment_id;

	if ( $attachment_id <= 0 ) {
		return;
	}

	$table = ssb_table( 'courses' );

	$in_use = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE image_id = %d", $attachment_id )
	);

	if ( $in_use > 1 ) {
		return;
	}

	wp_delete_attachment( $attachment_id, true );
}

/**
 * 講座イメージ画像の URL を返す.
 *
 * @param object $course 講座レコード。
 * @param string $size   画像サイズ。
 * @return string 未設定なら空文字。
 */
function ssb_course_image_url( $course, $size = 'large' ) {
	$image_id = isset( $course->image_id ) ? (int) $course->image_id : 0;

	if ( $image_id <= 0 ) {
		return '';
	}

	return (string) wp_get_attachment_image_url( $image_id, $size );
}

/* -------------------------------------------------------------------------
 * 公開されている講座の取得（トップ・講座一覧・講座詳細で使う）
 * ---------------------------------------------------------------------- */

/**
 * 公開中の講座を返す.
 *
 * 承認済み講師の published な講座のみ。講師が却下・削除された講座は出さない。
 *
 * @param int $limit 取得件数。0 なら全件。
 * @return object[]
 */
function ssb_get_published_courses( $limit = 0 ) {
	global $wpdb;

	$courses     = ssb_table( 'courses' );
	$instructors = ssb_table( 'instructors' );

	$sql = "SELECT c.*, i.display_name AS instructor_name
		FROM `{$courses}` c
		INNER JOIN `{$instructors}` i ON i.id = c.instructor_id
		WHERE c.status = 'published' AND i.status = 'approved'
		ORDER BY c.created_at DESC, c.id DESC";

	if ( $limit > 0 ) {
		return $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT %d', (int) $limit ) );
	}

	return $wpdb->get_results( $sql );
}

/**
 * 公開中の講座を1件返す.
 *
 * @param int $id 講座ID。
 * @return object|null 非公開・講師未承認なら null。
 */
function ssb_get_published_course( $id ) {
	global $wpdb;

	$courses     = ssb_table( 'courses' );
	$instructors = ssb_table( 'instructors' );

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT c.*, i.display_name AS instructor_name, i.profile AS instructor_profile
			FROM `{$courses}` c
			INNER JOIN `{$instructors}` i ON i.id = c.instructor_id
			WHERE c.id = %d AND c.status = 'published' AND i.status = 'approved'
			LIMIT 1",
			(int) $id
		)
	);
}

/**
 * 講座詳細の URL を返す.
 *
 * @param int $id 講座ID。
 * @return string
 */
function ssb_course_url( $id ) {
	return home_url( '/courses/' . (int) $id . '/' );
}

/* -------------------------------------------------------------------------
 * /courses/{id} のルーティング
 * ---------------------------------------------------------------------- */

/**
 * 講座詳細のリライトルールを追加する.
 *
 * 固定ページ /courses の子ページとして解決されないよう top で登録する。
 *
 * @return void
 */
function ssb_add_rewrite_rules() {
	add_rewrite_rule( '^courses/([0-9]+)/?$', 'index.php?ssb_course_id=$matches[1]', 'top' );
}
add_action( 'init', 'ssb_add_rewrite_rules' );

/**
 * クエリ変数を登録する.
 *
 * @param string[] $vars クエリ変数。
 * @return string[]
 */
function ssb_query_vars( $vars ) {
	$vars[] = 'ssb_course_id';

	return $vars;
}
add_filter( 'query_vars', 'ssb_query_vars' );

/* -------------------------------------------------------------------------
 * 削除
 * ---------------------------------------------------------------------- */

/**
 * 講座を削除する.
 *
 * 予約データを壊さないよう、次のいずれかに当てはまる場合は削除しない。
 * - 予約済み・仮押さえ中の枠がある
 * - 支払い済みの予約がある（決済履歴は残す）
 *
 * 削除できる場合は、未完了の予約と枠、イメージ画像もまとめて片付ける。
 *
 * @param int $id 講座ID。
 * @return true|WP_Error
 */
function ssb_delete_course( $id ) {
	global $wpdb;

	$id     = (int) $id;
	$course = ssb_get_course( $id );

	if ( ! $course ) {
		return new WP_Error( 'ssb_course_not_found', '対象の講座が見つかりません。' );
	}

	$slots    = ssb_table( 'slots' );
	$bookings = ssb_table( 'bookings' );

	// 期限切れの仮押さえは先に解放しておく（無駄に削除を止めないため）。
	ssb_release_expired_holds();

	$locked = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM `{$slots}` WHERE course_id = %d AND status IN ('held','booked')",
			$id
		)
	);

	if ( $locked > 0 ) {
		return new WP_Error(
			'ssb_course_has_active_slots',
			sprintf( '予約済み・仮押さえ中の枠が %d 件あるため削除できません。', $locked )
		);
	}

	$paid = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM `{$bookings}` b
			INNER JOIN `{$slots}` s ON s.id = b.slot_id
			WHERE s.course_id = %d AND b.status = 'paid'",
			$id
		)
	);

	if ( $paid > 0 ) {
		return new WP_Error(
			'ssb_course_has_bookings',
			sprintf( '決済済みの予約が %d 件あるため削除できません。', $paid )
		);
	}

	// 未完了（pending / cancelled）の予約を片付けてから枠を消す。
	$wpdb->query(
		$wpdb->prepare(
			"DELETE b FROM `{$bookings}` b
			INNER JOIN `{$slots}` s ON s.id = b.slot_id
			WHERE s.course_id = %d",
			$id
		)
	);

	$wpdb->delete( $slots, array( 'course_id' => $id ), array( '%d' ) );

	$deleted = $wpdb->delete( ssb_table( 'courses' ), array( 'id' => $id ), array( '%d' ) );

	if ( ! $deleted ) {
		return new WP_Error( 'ssb_course_delete_failed', '講座の削除に失敗しました。' );
	}

	ssb_delete_course_image( (int) $course->image_id );

	return true;
}

/**
 * 講座の削除の受け口.
 *
 * @return void
 */
function ssb_handle_delete_course() {
	check_admin_referer( 'ssb_delete_course', 'ssb_delete_course_nonce' );

	$instructor = ssb_current_instructor();

	if ( ! $instructor ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	$back      = array( 'tab' => 'courses' );
	$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;

	// 自分の講座かどうかを必ず確認する。
	if ( ! $course_id || ! ssb_get_own_course( $course_id, $instructor->id ) ) {
		ssb_mypage_done( 'course_forbidden', $back );
	}

	$result = ssb_delete_course( $course_id );

	if ( is_wp_error( $result ) ) {
		ssb_mypage_done( $result->get_error_code(), $back );
	}

	ssb_mypage_done( 'course_deleted', $back );
}
add_action( 'admin_post_ssb_delete_course', 'ssb_handle_delete_course' );

/**
 * すべての講座を返す（管理画面の絞り込み用）.
 *
 * @return object[] 講師名を含む。
 */
function ssb_get_all_courses() {
	global $wpdb;

	$courses     = ssb_table( 'courses' );
	$instructors = ssb_table( 'instructors' );

	return $wpdb->get_results(
		"SELECT c.*, i.display_name AS instructor_name
		FROM `{$courses}` c
		INNER JOIN `{$instructors}` i ON i.id = c.instructor_id
		ORDER BY i.display_name ASC, c.title ASC"
	);
}
