<?php
/**
 * 講師申請・審査.
 *
 * 申請フォーム（/apply）の受け口と、講師レコードの読み書きを担当する。
 * 画面の描画は templates/page-apply.php、審査 UI は inc/admin/instructors.php。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

/**
 * 講師ロールのスラッグ.
 */
define( 'SSB_INSTRUCTOR_ROLE', 'ssb_instructor' );

/**
 * 自分の講座を管理できるケーパビリティ.
 */
define( 'SSB_CAP_MANAGE_COURSES', 'ssb_manage_own_courses' );

/* -------------------------------------------------------------------------
 * ロール
 * ---------------------------------------------------------------------- */

/**
 * 講師ロールを登録する.
 *
 * wp-admin へのアクセスは不要なため read だけ与える。マイページはフロント側。
 *
 * @return void
 */
function ssb_register_instructor_role() {
	add_role(
		SSB_INSTRUCTOR_ROLE,
		'講師',
		array(
			'read'                  => true,
			SSB_CAP_MANAGE_COURSES  => true,
		)
	);
}
add_action( 'after_switch_theme', 'ssb_register_instructor_role' );

/**
 * ロールが無ければ登録する.
 *
 * get_role() は読み込み済みの $wp_roles を見るだけなので毎リクエスト呼んでも安い。
 * テーマを切り替え直さなくてもロールが復旧する。
 *
 * @return void
 */
function ssb_maybe_register_instructor_role() {
	if ( get_role( SSB_INSTRUCTOR_ROLE ) ) {
		return;
	}

	ssb_register_instructor_role();
}
add_action( 'init', 'ssb_maybe_register_instructor_role' );

/* -------------------------------------------------------------------------
 * ステータス
 * ---------------------------------------------------------------------- */

/**
 * 講師ステータスの一覧を返す.
 *
 * @return array<string,string> ステータス => 表示名。
 */
function ssb_instructor_statuses() {
	return array(
		'pending'  => '審査中',
		'approved' => '承認済み',
		'rejected' => '却下',
	);
}

/**
 * 講師ステータスの表示名を返す.
 *
 * @param string $status ステータス。
 * @return string
 */
function ssb_instructor_status_label( $status ) {
	$statuses = ssb_instructor_statuses();

	return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
}

/* -------------------------------------------------------------------------
 * データアクセス
 * ---------------------------------------------------------------------- */

/**
 * ID から講師を取得する.
 *
 * @param int $id 講師ID。
 * @return object|null
 */
function ssb_get_instructor( $id ) {
	global $wpdb;

	$table = ssb_table( 'instructors' );

	return $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", (int) $id )
	);
}

/**
 * WordPress ユーザーIDから講師を取得する.
 *
 * @param int $user_id ユーザーID。
 * @return object|null
 */
function ssb_get_instructor_by_user_id( $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return null;
	}

	$table = ssb_table( 'instructors' );

	return $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM `{$table}` WHERE user_id = %d LIMIT 1", $user_id )
	);
}

/**
 * 講師の一覧を取得する.
 *
 * テーブル名は ssb_table() のホワイトリスト由来で、外部入力は $status のみ。
 * $status は prepare() で束縛する。
 *
 * @param string $status 絞り込むステータス。空なら全件。
 * @return object[]
 */
function ssb_get_instructors( $status = '' ) {
	global $wpdb;

	$table = ssb_table( 'instructors' );

	if ( '' !== $status ) {
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE status = %s ORDER BY applied_at DESC, id DESC",
				$status
			)
		);
	}

	return $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY applied_at DESC, id DESC" );
}

/**
 * 講師を登録する.
 *
 * @param array<string,mixed> $data user_id / display_name / email / profile / course_plan。
 * @return int 作成された講師ID。失敗時は 0。
 */
function ssb_insert_instructor( $data ) {
	global $wpdb;

	$ok = $wpdb->insert(
		ssb_table( 'instructors' ),
		array(
			'user_id'      => (int) $data['user_id'],
			'status'       => 'pending',
			'display_name' => $data['display_name'],
			'profile'      => $data['profile'],
			'course_plan'  => $data['course_plan'],
			'email'        => $data['email'],
			'applied_at'   => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * 講師のステータスを更新する.
 *
 * @param int    $id     講師ID。
 * @param string $status pending / approved / rejected。
 * @return bool
 */
function ssb_set_instructor_status( $id, $status ) {
	global $wpdb;

	if ( ! array_key_exists( $status, ssb_instructor_statuses() ) ) {
		return false;
	}

	$data   = array( 'status' => $status );
	$format = array( '%s' );

	if ( 'approved' === $status ) {
		$data['approved_at'] = current_time( 'mysql' );
		$format[]            = '%s';
	}

	$result = $wpdb->update(
		ssb_table( 'instructors' ),
		$data,
		array( 'id' => (int) $id ),
		$format,
		array( '%d' )
	);

	return false !== $result;
}

/* -------------------------------------------------------------------------
 * 申請フォームの処理
 * ---------------------------------------------------------------------- */

/**
 * 申請フォームの送信を処理する.
 *
 * @return void
 */
function ssb_handle_apply() {
	check_admin_referer( 'ssb_apply', 'ssb_apply_nonce' );

	$input = array(
		'display_name' => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
		'email'        => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
		'profile'      => isset( $_POST['profile'] ) ? sanitize_textarea_field( wp_unslash( $_POST['profile'] ) ) : '',
		'course_plan'  => isset( $_POST['course_plan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['course_plan'] ) ) : '',
	);

	$errors = array();

	if ( '' === $input['display_name'] ) {
		$errors[] = '表示名を入力してください。';
	}
	if ( ! is_email( $input['email'] ) ) {
		$errors[] = 'メールアドレスを正しい形式で入力してください。';
	}
	if ( '' === $input['profile'] ) {
		$errors[] = '自己紹介を入力してください。';
	}
	if ( '' === $input['course_plan'] ) {
		$errors[] = '希望する講座内容を入力してください。';
	}

	if ( $errors ) {
		ssb_apply_fail( $errors, $input );
	}

	// 申請者の WordPress ユーザーを決める。
	if ( is_user_logged_in() ) {
		$user_id = get_current_user_id();
	} else {
		if ( email_exists( $input['email'] ) ) {
			ssb_apply_fail(
				array( 'このメールアドレスは既に登録されています。ログインしてから申請してください。' ),
				$input
			);
		}

		$user_id = ssb_create_instructor_user( $input['email'], $input['display_name'] );

		if ( is_wp_error( $user_id ) ) {
			ssb_apply_fail(
				array( 'ユーザーの作成に失敗しました：' . $user_id->get_error_message() ),
				$input
			);
		}
	}

	// 二重申請を防ぐ。
	$existing = ssb_get_instructor_by_user_id( $user_id );
	if ( $existing ) {
		ssb_apply_fail(
			array( 'すでに申請を受け付けています（現在の状態：' . ssb_instructor_status_label( $existing->status ) . '）。' ),
			$input
		);
	}

	// ロールを付与する。既存ユーザーの権限は消さずに追加する。
	$user = get_user_by( 'id', $user_id );
	if ( $user && ! in_array( SSB_INSTRUCTOR_ROLE, (array) $user->roles, true ) ) {
		$user->add_role( SSB_INSTRUCTOR_ROLE );
	}

	$instructor_id = ssb_insert_instructor( array_merge( $input, array( 'user_id' => $user_id ) ) );

	if ( ! $instructor_id ) {
		ssb_apply_fail( array( '申請の保存に失敗しました。時間をおいて再度お試しください。' ), $input );
	}

	ssb_mail_new_application( ssb_get_instructor( $instructor_id ) );

	wp_safe_redirect( add_query_arg( 'ssb_applied', '1', ssb_get_page_url( 'apply' ) ) );
	exit;
}
add_action( 'admin_post_ssb_apply', 'ssb_handle_apply' );
add_action( 'admin_post_nopriv_ssb_apply', 'ssb_handle_apply' );

/**
 * 申請者用の WordPress ユーザーを作成する.
 *
 * パスワードはこちらで通知せず、WordPress 標準のパスワード設定メールを送る。
 *
 * @param string $email        メールアドレス。
 * @param string $display_name 表示名。
 * @return int|WP_Error ユーザーID。
 */
function ssb_create_instructor_user( $email, $display_name ) {
	$base = sanitize_user( (string) strstr( $email, '@', true ), true );

	if ( '' === $base ) {
		$base = 'instructor';
	}

	$login  = $base;
	$suffix = 2;
	while ( username_exists( $login ) ) {
		$login = $base . $suffix;
		$suffix++;
	}

	$user_id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24, true, true ),
			'display_name' => $display_name,
			'nickname'     => $display_name,
			'role'         => SSB_INSTRUCTOR_ROLE,
		)
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	// 本人にパスワード設定リンクを送る（運営宛ての通知は別途こちらで送る）。
	wp_new_user_notification( $user_id, null, 'user' );

	return (int) $user_id;
}

/* -------------------------------------------------------------------------
 * フラッシュメッセージ
 * ---------------------------------------------------------------------- */

/**
 * エラーを保存して申請ページに戻す.
 *
 * 未ログインでも使えるようトランジェントに預け、キーだけを URL で渡す。
 *
 * @param string[]            $errors エラーメッセージ。
 * @param array<string,mixed> $input  入力値（再表示用）。
 * @return void
 */
function ssb_apply_fail( $errors, $input ) {
	ssb_flash_redirect(
		ssb_get_page_url( 'apply' ),
		array(
			'errors' => $errors,
			'input'  => $input,
		)
	);
}

/**
 * 直前の申請エラーと入力値を取り出す.
 *
 * 一度読んだら消す（リロードでエラーが残らないように）。
 *
 * @return array<string,mixed>|null errors / input。無ければ null。
 */
function ssb_get_apply_feedback() {
	return ssb_flash_take();
}

/* -------------------------------------------------------------------------
 * 運営用の項目・削除
 * ---------------------------------------------------------------------- */

/**
 * 面接日と管理メモを更新する.
 *
 * どちらも運営だけが見る項目で、講師には通知しない。
 *
 * @param int    $id           講師ID。
 * @param string $interview_at 面接日時（Y-m-d H:i:s）。空文字なら未設定に戻す。
 * @param string $admin_note   管理メモ。
 * @return bool
 */
function ssb_update_instructor_admin_fields( $id, $interview_at, $admin_note ) {
	global $wpdb;

	$result = $wpdb->update(
		ssb_table( 'instructors' ),
		array(
			'interview_at' => '' === $interview_at ? null : $interview_at,
			'admin_note'   => $admin_note,
		),
		array( 'id' => (int) $id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	return false !== $result;
}

/**
 * 講師が持っている講座の件数を返す.
 *
 * @param int $instructor_id 講師ID。
 * @return int
 */
function ssb_count_instructor_courses( $instructor_id ) {
	global $wpdb;

	$table = ssb_table( 'courses' );

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE instructor_id = %d", (int) $instructor_id )
	);
}

/**
 * 講師申請を削除する.
 *
 * 却下された人が再申請できるようにするための操作。
 * WordPress ユーザーアカウント自体は消さず、講師ロールだけ外す。
 * 講座を持っている場合は、予約データが孤立するため削除しない。
 *
 * @param int $id 講師ID。
 * @return true|WP_Error
 */
function ssb_delete_instructor( $id ) {
	global $wpdb;

	$instructor = ssb_get_instructor( $id );

	if ( ! $instructor ) {
		return new WP_Error( 'ssb_not_found', '対象の申請が見つかりません。' );
	}

	$courses = ssb_count_instructor_courses( $id );
	if ( $courses > 0 ) {
		return new WP_Error(
			'ssb_has_courses',
			sprintf( '講座が %d 件登録されているため削除できません。', $courses )
		);
	}

	$deleted = $wpdb->delete( ssb_table( 'instructors' ), array( 'id' => (int) $id ), array( '%d' ) );

	if ( ! $deleted ) {
		return new WP_Error( 'ssb_delete_failed', '削除に失敗しました。' );
	}

	$user = get_user_by( 'id', (int) $instructor->user_id );

	if ( $user && in_array( SSB_INSTRUCTOR_ROLE, (array) $user->roles, true ) ) {
		$user->remove_role( SSB_INSTRUCTOR_ROLE );

		// ロールが空だとログイン後に何もできなくなるため、購読者を残す。
		if ( ! $user->roles ) {
			$user->set_role( 'subscriber' );
		}
	}

	return true;
}

/* -------------------------------------------------------------------------
 * マイページ（アクセス制御と共通のフラッシュ）
 * ---------------------------------------------------------------------- */

/**
 * ログイン中の承認済み講師を返す.
 *
 * マイページの各操作は必ずこれで本人を特定すること。
 *
 * @return object|null 承認済み講師でなければ null。
 */
function ssb_current_instructor() {
	if ( ! is_user_logged_in() ) {
		return null;
	}

	$instructor = ssb_get_instructor_by_user_id( get_current_user_id() );

	if ( ! $instructor || 'approved' !== $instructor->status ) {
		return null;
	}

	return $instructor;
}

/**
 * マイページのアクセス制限.
 *
 * 未ログインはログイン画面へ、承認済みでなければトップへ。
 *
 * @return void
 */
function ssb_restrict_mypage() {
	if ( ! is_page() ) {
		return;
	}

	if ( 'mypage' !== (string) get_post_field( 'post_name', get_queried_object_id() ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( ssb_login_url( ssb_get_page_url( 'mypage' ) ) );
		exit;
	}

	if ( ! ssb_current_instructor() ) {
		// ログイン済みだが講師ではない場合は申請ページへ案内する。
		wp_safe_redirect( ssb_get_page_url( 'apply' ) );
		exit;
	}
}
add_action( 'template_redirect', 'ssb_restrict_mypage' );

/**
 * マイページの URL を返す.
 *
 * @param array<string,string> $args クエリ引数。
 * @return string
 */
function ssb_mypage_url( $args = array() ) {
	return $args ? add_query_arg( $args, ssb_get_page_url( 'mypage' ) ) : ssb_get_page_url( 'mypage' );
}

/**
 * エラーと入力値を預けてマイページへ戻す.
 *
 * @param string[]             $errors エラーメッセージ。
 * @param array<string,mixed>  $input  入力値（再表示用）。
 * @param array<string,string> $args   戻り先のクエリ引数。
 * @return void
 */
function ssb_mypage_fail( $errors, $input, $args = array() ) {
	set_transient(
		'ssb_mypage_' . get_current_user_id(),
		array(
			'errors' => $errors,
			'input'  => $input,
		),
		10 * MINUTE_IN_SECONDS
	);

	wp_safe_redirect( ssb_mypage_url( $args ) );
	exit;
}

/**
 * 成功メッセージ付きでマイページへ戻す.
 *
 * @param string               $message メッセージキー。
 * @param array<string,string> $args    戻り先のクエリ引数。
 * @return void
 */
function ssb_mypage_done( $message, $args = array() ) {
	wp_safe_redirect( ssb_mypage_url( array_merge( $args, array( 'ssb_msg' => $message ) ) ) );
	exit;
}

/**
 * 直前のマイページ操作のエラーと入力値を取り出す.
 *
 * 一度読んだら消す。
 *
 * @return array<string,mixed>|null
 */
function ssb_get_mypage_feedback() {
	if ( ! is_user_logged_in() ) {
		return null;
	}

	$key  = 'ssb_mypage_' . get_current_user_id();
	$data = get_transient( $key );

	if ( ! is_array( $data ) ) {
		return null;
	}

	delete_transient( $key );

	return $data;
}

/* -------------------------------------------------------------------------
 * プロフィール編集
 * ---------------------------------------------------------------------- */

/**
 * プロフィールを更新する.
 *
 * 申請時の course_plan は審査の記録なので、ここでは編集させない。
 *
 * @param int                 $id   講師ID。
 * @param array<string,mixed> $data display_name / email / profile。
 * @return bool
 */
function ssb_update_instructor_profile( $id, $data ) {
	global $wpdb;

	$result = $wpdb->update(
		ssb_table( 'instructors' ),
		array(
			'display_name' => $data['display_name'],
			'email'        => $data['email'],
			'profile'      => $data['profile'],
		),
		array( 'id' => (int) $id ),
		array( '%s', '%s', '%s' ),
		array( '%d' )
	);

	return false !== $result;
}

/**
 * プロフィール保存の受け口.
 *
 * @return void
 */
function ssb_handle_save_profile() {
	check_admin_referer( 'ssb_save_profile', 'ssb_profile_nonce' );

	$instructor = ssb_current_instructor();

	if ( ! $instructor ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	$input = array(
		'display_name' => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
		'email'        => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
		'profile'      => isset( $_POST['profile'] ) ? sanitize_textarea_field( wp_unslash( $_POST['profile'] ) ) : '',
	);

	$errors = array();

	if ( '' === $input['display_name'] ) {
		$errors[] = '表示名を入力してください。';
	}
	if ( ! is_email( $input['email'] ) ) {
		$errors[] = 'メールアドレスを正しい形式で入力してください。';
	}
	if ( '' === $input['profile'] ) {
		$errors[] = '自己紹介を入力してください。';
	}

	if ( $errors ) {
		ssb_mypage_fail( $errors, $input, array( 'tab' => 'profile' ) );
	}

	if ( ! ssb_update_instructor_profile( $instructor->id, $input ) ) {
		ssb_mypage_fail( array( '保存に失敗しました。時間をおいて再度お試しください。' ), $input, array( 'tab' => 'profile' ) );
	}

	ssb_mypage_done( 'profile_saved', array( 'tab' => 'profile' ) );
}
add_action( 'admin_post_ssb_save_profile', 'ssb_handle_save_profile' );

/* -------------------------------------------------------------------------
 * 未ログインでも使えるフラッシュメッセージ
 * ---------------------------------------------------------------------- */

/**
 * データを預けてリダイレクトする.
 *
 * 未ログインだとセッションに頼れないため、トランジェントに預けて
 * 取り出し用のトークンだけを URL に載せる。
 *
 * @param string              $url  リダイレクト先。
 * @param array<string,mixed> $data errors / input など。
 * @return void
 */
function ssb_flash_redirect( $url, $data ) {
	$token = wp_generate_uuid4();

	set_transient( 'ssb_flash_' . $token, $data, 10 * MINUTE_IN_SECONDS );

	wp_safe_redirect( add_query_arg( 'ssb_err', $token, $url ) );
	exit;
}

/**
 * 預けたデータを取り出す（1回だけ）.
 *
 * @return array<string,mixed>|null
 */
function ssb_flash_take() {
	if ( empty( $_GET['ssb_err'] ) ) {
		return null;
	}

	$token = sanitize_key( wp_unslash( $_GET['ssb_err'] ) );
	$data  = get_transient( 'ssb_flash_' . $token );

	if ( ! is_array( $data ) ) {
		return null;
	}

	delete_transient( 'ssb_flash_' . $token );

	return $data;
}

/* -------------------------------------------------------------------------
 * ログイン（WordPress 標準画面は使わず自前のページで受ける）
 * ---------------------------------------------------------------------- */

/**
 * ログインページの URL を返す.
 *
 * @param string $redirect_to ログイン後の遷移先。
 * @return string
 */
function ssb_login_url( $redirect_to = '' ) {
	$url = ssb_get_page_url( 'login' );

	return $redirect_to ? add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url ) : $url;
}

/**
 * ログインの受け口.
 *
 * @return void
 */
function ssb_handle_login() {
	check_admin_referer( 'ssb_login', 'ssb_login_nonce' );

	$redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
	$user_login  = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';

	// パスワードは sanitize すると文字が落ちるので加工しない。
	$password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';

	$back = ssb_login_url( $redirect_to );

	if ( '' === $user_login || '' === $password ) {
		ssb_flash_redirect(
			$back,
			array(
				'errors' => array( 'ユーザー名（メールアドレス）とパスワードを入力してください。' ),
				'input'  => array( 'log' => $user_login ),
			)
		);
	}

	$user = wp_signon(
		array(
			'user_login'    => $user_login,
			'user_password' => $password,
			'remember'      => ! empty( $_POST['rememberme'] ),
		),
		is_ssl()
	);

	if ( is_wp_error( $user ) ) {
		// アカウントの有無を知られないよう、原因は区別せず同じ文言にする。
		ssb_flash_redirect(
			$back,
			array(
				'errors' => array( 'ユーザー名（メールアドレス）またはパスワードが正しくありません。' ),
				'input'  => array( 'log' => $user_login ),
			)
		);
	}

	wp_set_current_user( $user->ID );

	wp_safe_redirect( $redirect_to ? $redirect_to : ssb_get_page_url( 'mypage' ) );
	exit;
}
add_action( 'admin_post_nopriv_ssb_login', 'ssb_handle_login' );
add_action( 'admin_post_ssb_login', 'ssb_handle_login' );

/**
 * パスワード再設定メールの受け口.
 *
 * @return void
 */
function ssb_handle_lostpassword() {
	check_admin_referer( 'ssb_lostpassword', 'ssb_lostpassword_nonce' );

	$login_page = add_query_arg( 'action', 'lostpassword', ssb_get_page_url( 'login' ) );
	$user_login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';

	if ( '' === $user_login ) {
		ssb_flash_redirect(
			$login_page,
			array( 'errors' => array( 'メールアドレスまたはユーザー名を入力してください。' ) )
		);
	}

	retrieve_password( $user_login );

	// 登録の有無を知られないよう、結果にかかわらず同じ画面を返す。
	wp_safe_redirect( add_query_arg( 'sent', '1', $login_page ) );
	exit;
}
add_action( 'admin_post_nopriv_ssb_lostpassword', 'ssb_handle_lostpassword' );
add_action( 'admin_post_ssb_lostpassword', 'ssb_handle_lostpassword' );
