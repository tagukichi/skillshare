<?php
/**
 * 管理画面：講師審査.
 *
 * 「スキルシェア」トップレベルメニューをここで登録する。
 * 予約一覧（inc/admin/bookings.php）は実装順序 12 でこのメニューにぶら下げる。
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
 * 承認・却下を処理する.
 *
 * @return void
 */
function ssb_handle_review_instructor() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( '権限がありません。' ), 403 );
	}

	check_admin_referer( 'ssb_review_instructor' );

	$id       = isset( $_POST['instructor_id'] ) ? absint( wp_unslash( $_POST['instructor_id'] ) ) : 0;
	$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

	$decisions = array(
		'approve' => 'approved',
		'reject'  => 'rejected',
	);

	if ( ! $id || ! isset( $decisions[ $decision ] ) || ! ssb_get_instructor( $id ) ) {
		ssb_admin_redirect_instructors( 'error' );
	}

	$status = $decisions[ $decision ];

	if ( ! ssb_set_instructor_status( $id, $status ) ) {
		ssb_admin_redirect_instructors( 'error' );
	}

	// 更新後のレコードでメールを送る（approved_at を含めるため）。
	$instructor = ssb_get_instructor( $id );

	if ( 'approved' === $status ) {
		ssb_mail_instructor_approved( $instructor );
	} else {
		ssb_mail_instructor_rejected( $instructor );
	}

	ssb_admin_redirect_instructors( $status );
}
add_action( 'admin_post_ssb_review_instructor', 'ssb_handle_review_instructor' );

/**
 * 講師申請一覧へリダイレクトする.
 *
 * @param string $message メッセージキー。
 * @return void
 */
function ssb_admin_redirect_instructors( $message ) {
	wp_safe_redirect( ssb_admin_instructors_url( array( 'ssb_msg' => $message ) ) );
	exit;
}

/**
 * 講師申請一覧を描画する.
 *
 * @return void
 */
function ssb_admin_instructors_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( '権限がありません。' ), 403 );
	}

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

	$message = isset( $_GET['ssb_msg'] ) ? sanitize_key( wp_unslash( $_GET['ssb_msg'] ) ) : '';

	$notices = array(
		'approved' => array( 'success', '承認しました。講師に通知メールを送信しました。' ),
		'rejected' => array( 'success', '却下しました。講師に通知メールを送信しました。' ),
		'error'    => array( 'error', '処理できませんでした。対象の申請が見つかりません。' ),
	);
	?>
	<div class="wrap">
		<h1>講師申請</h1>

		<?php if ( isset( $notices[ $message ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notices[ $message ][0] ); ?> is-dismissible">
				<p><?php echo esc_html( $notices[ $message ][1] ); ?></p>
			</div>
		<?php endif; ?>

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

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width:18%;">表示名</th>
					<th scope="col" style="width:22%;">連絡先</th>
					<th scope="col" style="width:10%;">状態</th>
					<th scope="col" style="width:14%;">申請日時</th>
					<th scope="col" style="width:14%;">承認日時</th>
					<th scope="col" style="width:22%;">操作</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $list ) : ?>
				<tr><td colspan="6">該当する申請はありません。</td></tr>
			<?php endif; ?>

			<?php foreach ( $list as $row ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $row->display_name ); ?></strong></td>
					<td><a href="<?php echo esc_url( 'mailto:' . $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></td>
					<td><?php echo esc_html( ssb_instructor_status_label( $row->status ) ); ?></td>
					<td><?php echo esc_html( substr( (string) $row->applied_at, 0, 16 ) ); ?></td>
					<td><?php echo esc_html( $row->approved_at ? substr( (string) $row->approved_at, 0, 16 ) : '—' ); ?></td>
					<td>
						<?php if ( 'approved' !== $row->status ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'ssb_review_instructor' ); ?>
								<input type="hidden" name="action" value="ssb_review_instructor">
								<input type="hidden" name="instructor_id" value="<?php echo esc_attr( (string) $row->id ); ?>">
								<input type="hidden" name="decision" value="approve">
								<button type="submit" class="button button-primary">承認</button>
							</form>
						<?php endif; ?>

						<?php if ( 'rejected' !== $row->status ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'ssb_review_instructor' ); ?>
								<input type="hidden" name="action" value="ssb_review_instructor">
								<input type="hidden" name="instructor_id" value="<?php echo esc_attr( (string) $row->id ); ?>">
								<input type="hidden" name="decision" value="reject">
								<button type="submit" class="button" onclick="return confirm('この申請を却下します。よろしいですか？');">却下</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td colspan="6" style="background:#fbfbfb;">
						<p style="margin:0 0 8px;"><strong>自己紹介</strong><br>
							<?php echo nl2br( esc_html( (string) $row->profile ) ); ?></p>
						<p style="margin:0;"><strong>希望する講座内容</strong><br>
							<?php echo nl2br( esc_html( (string) $row->course_plan ) ); ?></p>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
