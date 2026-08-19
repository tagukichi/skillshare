<?php
/**
 * 管理画面：講師審査.
 *
 * 「スキルシェア」トップレベルメニューをここで登録する。
 * 予約一覧（inc/admin/bookings.php）は実装順序 12 でこのメニューにぶら下げる。
 *
 * 一覧と詳細を1つのページコールバックで出し分ける（id があれば詳細）。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

/**
 * 管理メニューの親スラッグ.
 */
define( 'SSB_ADMIN_MENU_SLUG', 'ssb-instructors' );

/**
 * 管理メニューを登録する.
 *
 * @return void
 */
function ssb_admin_menu() {
	add_menu_page(
		'スキルシェア',
		'スキルシェア',
		'manage_options',
		SSB_ADMIN_MENU_SLUG,
		'ssb_admin_instructors_page',
		'dashicons-welcome-learn-more',
		30
	);

	add_submenu_page(
		SSB_ADMIN_MENU_SLUG,
		'講師申請',
		'講師申請',
		'manage_options',
		SSB_ADMIN_MENU_SLUG,
		'ssb_admin_instructors_page'
	);
}
add_action( 'admin_menu', 'ssb_admin_menu' );

/* -------------------------------------------------------------------------
 * URL
 * ---------------------------------------------------------------------- */

/**
 * 講師申請一覧の URL を返す.
 *
 * @param array<string,string> $args 追加のクエリ引数。
 * @return string
 */
function ssb_admin_instructors_url( $args = array() ) {
	return add_query_arg(
		array_merge( array( 'page' => SSB_ADMIN_MENU_SLUG ), $args ),
		admin_url( 'admin.php' )
	);
}

/**
 * 講師詳細の URL を返す.
 *
 * @param int                  $id   講師ID。
 * @param array<string,string> $args 追加のクエリ引数。
 * @return string
 */
function ssb_admin_instructor_url( $id, $args = array() ) {
	return ssb_admin_instructors_url( array_merge( array( 'id' => (string) (int) $id ), $args ) );
}

/**
 * 処理後のリダイレクト先へ飛ばす.
 *
 * @param string $message メッセージキー。
 * @param int    $id      詳細に戻る場合の講師ID。0 なら一覧へ。
 * @return void
 */
function ssb_admin_redirect_instructors( $message, $id = 0 ) {
	$url = $id
		? ssb_admin_instructor_url( $id, array( 'ssb_msg' => $message ) )
		: ssb_admin_instructors_url( array( 'ssb_msg' => $message ) );

	wp_safe_redirect( $url );
	exit;
}

/* -------------------------------------------------------------------------
 * 日時の変換（datetime-local <-> DATETIME）
 * ---------------------------------------------------------------------- */

/**
 * datetime-local の値を DATETIME 文字列にする.
 *
 * 壁時計の時刻をそのまま保存するため、タイムゾーン変換は行わない。
 *
 * @param string $raw 例: 2026-08-20T14:30。
 * @return string 例: 2026-08-20 14:30:00。不正なら空文字。
 */
function ssb_parse_datetime_local( $raw ) {
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/', $raw, $m ) ) {
		return '';
	}

	if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return '';
	}

	if ( (int) $m[4] > 23 || (int) $m[5] > 59 ) {
		return '';
	}

	return sprintf( '%s-%s-%s %s:%s:00', $m[1], $m[2], $m[3], $m[4], $m[5] );
}

/**
 * DATETIME 文字列を datetime-local の値にする.
 *
 * @param string|null $value DATETIME 文字列。
 * @return string
 */
function ssb_to_datetime_local( $value ) {
	$value = (string) $value;

	if ( '' === $value || str_starts_with( $value, '0000' ) ) {
		return '';
	}

	return str_replace( ' ', 'T', substr( $value, 0, 16 ) );
}

/**
 * 一覧・詳細で使う日時表示.
 *
 * @param string|null $value DATETIME 文字列。
 * @return string 未設定なら em ダッシュ。
 */
function ssb_format_datetime( $value ) {
	$value = (string) $value;

	if ( '' === $value || str_starts_with( $value, '0000' ) ) {
		return '—';
	}

	return substr( $value, 0, 16 );
}

/* -------------------------------------------------------------------------
 * 操作の受け口
 * ---------------------------------------------------------------------- */

/**
 * 権限と nonce をまとめて検証する.
 *
 * @param string $nonce_action nonce のアクション名。
 * @return void
 */
function ssb_admin_guard( $nonce_action ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( '権限がありません。' ), 403 );
	}

	check_admin_referer( $nonce_action );
}

/**
 * 送信元が詳細画面かどうかを返す.
 *
 * @return bool
 */
function ssb_admin_came_from_detail() {
	return isset( $_POST['return_to'] ) && 'detail' === sanitize_key( wp_unslash( $_POST['return_to'] ) );
}

/**
 * POST された講師IDを返す.
 *
 * @return int
 */
function ssb_admin_posted_instructor_id() {
	return isset( $_POST['instructor_id'] ) ? absint( wp_unslash( $_POST['instructor_id'] ) ) : 0;
}

/**
 * 承認・却下を処理する.
 *
 * @return void
 */
function ssb_handle_review_instructor() {
	ssb_admin_guard( 'ssb_review_instructor' );

	$id       = ssb_admin_posted_instructor_id();
	$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
	$back     = ssb_admin_came_from_detail() ? $id : 0;

	$decisions = array(
		'approve' => 'approved',
		'reject'  => 'rejected',
	);

	if ( ! $id || ! isset( $decisions[ $decision ] ) || ! ssb_get_instructor( $id ) ) {
		ssb_admin_redirect_instructors( 'error' );
	}

	$status = $decisions[ $decision ];

	if ( ! ssb_set_instructor_status( $id, $status ) ) {
		ssb_admin_redirect_instructors( 'error', $back );
	}

	// 更新後のレコードでメールを送る（approved_at を含めるため）。
	$instructor = ssb_get_instructor( $id );

	if ( 'approved' === $status ) {
		ssb_mail_instructor_approved( $instructor );
	} else {
		ssb_mail_instructor_rejected( $instructor );
	}

	ssb_admin_redirect_instructors( $status, $back );
}
add_action( 'admin_post_ssb_review_instructor', 'ssb_handle_review_instructor' );

/**
 * 面接日と管理メモを保存する.
 *
 * @return void
 */
function ssb_handle_save_instructor() {
	ssb_admin_guard( 'ssb_save_instructor' );

	$id = ssb_admin_posted_instructor_id();

	if ( ! $id || ! ssb_get_instructor( $id ) ) {
		ssb_admin_redirect_instructors( 'error' );
	}

	$raw_interview = isset( $_POST['interview_at'] ) ? sanitize_text_field( wp_unslash( $_POST['interview_at'] ) ) : '';
	$interview_at  = '' === $raw_interview ? '' : ssb_parse_datetime_local( $raw_interview );

	if ( '' !== $raw_interview && '' === $interview_at ) {
		ssb_admin_redirect_instructors( 'bad_date', $id );
	}

	$admin_note = isset( $_POST['admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : '';

	if ( ! ssb_update_instructor_admin_fields( $id, $interview_at, $admin_note ) ) {
		ssb_admin_redirect_instructors( 'error', $id );
	}

	ssb_admin_redirect_instructors( 'saved', $id );
}
add_action( 'admin_post_ssb_save_instructor', 'ssb_handle_save_instructor' );

/**
 * 講師申請を削除する.
 *
 * 削除後は対象が無くなるので必ず一覧へ戻す。
 *
 * @return void
 */
function ssb_handle_delete_instructor() {
	ssb_admin_guard( 'ssb_delete_instructor' );

	$id = ssb_admin_posted_instructor_id();

	if ( ! $id ) {
		ssb_admin_redirect_instructors( 'error' );
	}

	$result = ssb_delete_instructor( $id );

	if ( is_wp_error( $result ) ) {
		ssb_admin_redirect_instructors( $result->get_error_code(), $id );
	}

	ssb_admin_redirect_instructors( 'deleted' );
}
add_action( 'admin_post_ssb_delete_instructor', 'ssb_handle_delete_instructor' );

/**
 * 画面上部に出す通知の定義.
 *
 * @return array<string,array<int,string>> メッセージキー => [種別, 文言]。
 */
function ssb_admin_instructor_notices() {
	return array(
		'approved'          => array( 'success', '承認しました。講師に通知メールを送信しました。' ),
		'rejected'          => array( 'success', '却下しました。講師に通知メールを送信しました。' ),
		'saved'             => array( 'success', '保存しました。' ),
		'deleted'           => array( 'success', '申請を削除しました。同じ方が再度申請できます。' ),
		'bad_date'          => array( 'error', '面接日の形式が正しくありません。' ),
		'ssb_has_courses'   => array( 'error', '講座が登録されているため削除できません。' ),
		'ssb_not_found'     => array( 'error', '対象の申請が見つかりません。' ),
		'ssb_delete_failed' => array( 'error', '削除に失敗しました。' ),
		'error'             => array( 'error', '処理できませんでした。' ),
	);
}

/* -------------------------------------------------------------------------
 * 画面
 * ---------------------------------------------------------------------- */

/**
 * 講師申請ページ。id があれば詳細、無ければ一覧.
 *
 * @return void
 */
function ssb_admin_instructors_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( '権限がありません。' ), 403 );
	}

	$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

	if ( $id ) {
		ssb_admin_instructor_detail( $id );
		return;
	}

	ssb_admin_instructor_list();
}

/**
 * 直前の操作結果を通知として出す.
 *
 * @return void
 */
function ssb_admin_render_notice() {
	$message = isset( $_GET['ssb_msg'] ) ? sanitize_key( wp_unslash( $_GET['ssb_msg'] ) ) : '';
	$notices = ssb_admin_instructor_notices();

	if ( ! isset( $notices[ $message ] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
		esc_attr( $notices[ $message ][0] ),
		esc_html( $notices[ $message ][1] )
	);
}

/**
 * 承認・却下・削除のボタンを出す.
 *
 * @param object $row       講師レコード。
 * @param string $return_to list|detail。処理後の戻り先。
 * @return void
 */
function ssb_admin_instructor_actions( $row, $return_to ) {
	$post_url = admin_url( 'admin-post.php' );
	?>
	<?php if ( 'approved' !== $row->status ) : ?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline;">
			<?php wp_nonce_field( 'ssb_review_instructor' ); ?>
			<input type="hidden" name="action" value="ssb_review_instructor">
			<input type="hidden" name="instructor_id" value="<?php echo esc_attr( (string) $row->id ); ?>">
			<input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>">
			<input type="hidden" name="decision" value="approve">
			<button type="submit" class="button button-primary">承認</button>
		</form>
	<?php endif; ?>

	<?php if ( 'rejected' !== $row->status ) : ?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline;">
			<?php wp_nonce_field( 'ssb_review_instructor' ); ?>
			<input type="hidden" name="action" value="ssb_review_instructor">
			<input type="hidden" name="instructor_id" value="<?php echo esc_attr( (string) $row->id ); ?>">
			<input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>">
			<input type="hidden" name="decision" value="reject">
			<button type="submit" class="button" onclick="return confirm('この申請を却下します。よろしいですか？');">却下</button>
		</form>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline;">
		<?php wp_nonce_field( 'ssb_delete_instructor' ); ?>
		<input type="hidden" name="action" value="ssb_delete_instructor">
		<input type="hidden" name="instructor_id" value="<?php echo esc_attr( (string) $row->id ); ?>">
		<button type="submit" class="button button-link-delete"
			onclick="return confirm('この申請を削除します。面接日やメモも消えます。\nログインアカウントは残るので、本人はログインのうえ再申請できます。\n\nよろしいですか？');">削除</button>
	</form>
	<?php
}

/**
 * 講師申請の一覧を描画する.
 *
 * @return void
 */
function ssb_admin_instructor_list() {
	$statuses = ssb_instructor_statuses();

	$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	if ( '' !== $filter && ! isset( $statuses[ $filter ] ) ) {
		$filter = '';
	}

	// 件数表示のため一度で全件取り、絞り込みは PHP 側で行う（PoC の規模なら十分）。
	$all    = ssb_get_instructors();
	$counts = array_fill_keys( array_keys( $statuses ), 0 );

	foreach ( $all as $row ) {
		if ( isset( $counts[ $row->status ] ) ) {
			$counts[ $row->status ]++;
		}
	}

	$list = $all;
	if ( '' !== $filter ) {
		$list = array_values(
			array_filter(
				$all,
				static function ( $row ) use ( $filter ) {
					return $row->status === $filter;
				}
			)
		);
	}
	?>
	<div class="wrap">
		<h1>講師申請</h1>

		<?php ssb_admin_render_notice(); ?>

		<ul class="subsubsub">
			<li>
				<a href="<?php echo esc_url( ssb_admin_instructors_url() ); ?>" class="<?php echo '' === $filter ? 'current' : ''; ?>">
					すべて <span class="count">(<?php echo esc_html( (string) count( $all ) ); ?>)</span>
				</a>
			</li>
			<?php foreach ( $statuses as $key => $label ) : ?>
				<li>
					 |
					<a href="<?php echo esc_url( ssb_admin_instructors_url( array( 'status' => $key ) ) ); ?>" class="<?php echo $filter === $key ? 'current' : ''; ?>">
						<?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( (string) $counts[ $key ] ); ?>)</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<table class="wp-list-table widefat fixed">
			<thead>
				<tr>
					<th scope="col" style="width:20%;">表示名</th>
					<th scope="col" style="width:20%;">連絡先</th>
					<th scope="col" style="width:9%;">状態</th>
					<th scope="col" style="width:13%;">申請日時</th>
					<th scope="col" style="width:13%;">面接日</th>
					<th scope="col" style="width:25%;">操作</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $list ) : ?>
				<tr><td colspan="6">該当する申請はありません。</td></tr>
			<?php endif; ?>

			<?php foreach ( $list as $row ) : ?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( ssb_admin_instructor_url( $row->id ) ); ?>"><?php echo esc_html( $row->display_name ); ?></a></strong>
					</td>
					<td><a href="<?php echo esc_url( 'mailto:' . $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></td>
					<td><?php echo esc_html( ssb_instructor_status_label( $row->status ) ); ?></td>
					<td><?php echo esc_html( ssb_format_datetime( $row->applied_at ) ); ?></td>
					<td><?php echo esc_html( ssb_format_datetime( $row->interview_at ) ); ?></td>
					<td><?php ssb_admin_instructor_actions( $row, 'list' ); ?></td>
				</tr>
				<tr>
					<td colspan="6" style="border-top:none;padding-top:0;">
						<details>
							<summary style="cursor:pointer;color:#2271b1;">申請内容を開く</summary>
							<div style="padding:10px 0 4px;">
								<p style="margin:0 0 8px;"><strong>自己紹介</strong><br>
									<?php echo nl2br( esc_html( (string) $row->profile ) ); ?></p>
								<p style="margin:0 0 8px;"><strong>希望する講座内容</strong><br>
									<?php echo nl2br( esc_html( (string) $row->course_plan ) ); ?></p>
								<?php if ( '' !== (string) $row->admin_note ) : ?>
									<p style="margin:0;"><strong>管理メモ</strong><br>
										<?php echo nl2br( esc_html( (string) $row->admin_note ) ); ?></p>
								<?php endif; ?>
							</div>
						</details>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * 講師申請の詳細を描画する.
 *
 * 申請内容は読み取り専用。運営が編集できるのは面接日と管理メモのみ。
 *
 * @param int $id 講師ID。
 * @return void
 */
function ssb_admin_instructor_detail( $id ) {
	$row = ssb_get_instructor( $id );

	if ( ! $row ) {
		?>
		<div class="wrap">
			<h1>講師申請</h1>
			<div class="notice notice-error"><p>対象の申請が見つかりません。削除された可能性があります。</p></div>
			<p><a href="<?php echo esc_url( ssb_admin_instructors_url() ); ?>">&laquo; 一覧に戻る</a></p>
		</div>
		<?php
		return;
	}

	$user    = get_user_by( 'id', (int) $row->user_id );
	$courses = ssb_count_instructor_courses( $row->id );
	?>
	<div class="wrap">
		<h1>
			<?php echo esc_html( $row->display_name ); ?>
			<span style="font-size:13px;font-weight:400;color:#646970;">（<?php echo esc_html( ssb_instructor_status_label( $row->status ) ); ?>）</span>
		</h1>

		<p><a href="<?php echo esc_url( ssb_admin_instructors_url() ); ?>">&laquo; 一覧に戻る</a></p>

		<?php ssb_admin_render_notice(); ?>

		<h2>申請内容</h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">連絡先</th>
					<td><a href="<?php echo esc_url( 'mailto:' . $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></td>
				</tr>
				<tr>
					<th scope="row">ログインアカウント</th>
					<td>
						<?php if ( $user ) : ?>
							<?php echo esc_html( $user->user_login ); ?>
							<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>">（ユーザー編集）</a>
						<?php else : ?>
							<span style="color:#b32d2e;">アカウントが見つかりません</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">申請日時</th>
					<td><?php echo esc_html( ssb_format_datetime( $row->applied_at ) ); ?></td>
				</tr>
				<tr>
					<th scope="row">承認日時</th>
					<td><?php echo esc_html( ssb_format_datetime( $row->approved_at ) ); ?></td>
				</tr>
				<tr>
					<th scope="row">登録済みの講座</th>
					<td><?php echo esc_html( (string) $courses ); ?> 件</td>
				</tr>
				<tr>
					<th scope="row">自己紹介</th>
					<td><?php echo nl2br( esc_html( (string) $row->profile ) ); ?></td>
				</tr>
				<tr>
					<th scope="row">希望する講座内容</th>
					<td><?php echo nl2br( esc_html( (string) $row->course_plan ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<h2>運営メモ</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ssb_save_instructor' ); ?>
			<input type="hidden" name="action" value="ssb_save_instructor">
			<input type="hidden" name="instructor_id" value="<?php echo esc_attr( (string) $row->id ); ?>">

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="ssb-interview-at">面接日</label></th>
						<td>
							<input type="datetime-local" id="ssb-interview-at" name="interview_at"
								value="<?php echo esc_attr( ssb_to_datetime_local( $row->interview_at ) ); ?>">
							<p class="description">空欄にすると未設定に戻ります。講師には通知されません。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ssb-admin-note">メモ</label></th>
						<td>
							<textarea id="ssb-admin-note" name="admin_note" rows="8" class="large-text"><?php echo esc_textarea( (string) $row->admin_note ); ?></textarea>
							<p class="description">面接の所感や連絡履歴など。運営だけが見られます。</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( '保存' ); ?>
		</form>

		<h2>審査</h2>
		<p><?php ssb_admin_instructor_actions( $row, 'detail' ); ?></p>
		<p class="description">
			削除すると面接日とメモも消えます。ログインアカウントは残るため、本人はログインのうえ再申請できます。
		</p>
	</div>
	<?php
}
