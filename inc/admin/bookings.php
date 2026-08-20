<?php
/**
 * 管理画面：予約一覧・売上集計.
 *
 * メニューの親（スキルシェア）は inc/admin/instructors.php で登録している。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

/**
 * 予約一覧のページスラッグ.
 */
define( 'SSB_ADMIN_BOOKINGS_SLUG', 'ssb-bookings' );

/**
 * 予約一覧をメニューに追加する.
 *
 * @return void
 */
function ssb_admin_bookings_menu() {
	add_submenu_page(
		SSB_ADMIN_MENU_SLUG,
		'予約一覧',
		'予約一覧',
		'manage_options',
		SSB_ADMIN_BOOKINGS_SLUG,
		'ssb_admin_bookings_page'
	);
}
add_action( 'admin_menu', 'ssb_admin_bookings_menu', 11 );

/**
 * 予約一覧の URL を返す.
 *
 * @param array<string,string> $args 追加のクエリ引数。
 * @return string
 */
function ssb_admin_bookings_url( $args = array() ) {
	return add_query_arg(
		array_merge( array( 'page' => SSB_ADMIN_BOOKINGS_SLUG ), $args ),
		admin_url( 'admin.php' )
	);
}

/**
 * リクエストから絞り込み条件を組み立てる.
 *
 * @return array<string,mixed>
 */
function ssb_admin_booking_filters() {
	$statuses = ssb_booking_statuses();

	$status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
	if ( '' !== $status && ! isset( $statuses[ $status ] ) ) {
		$status = '';
	}

	$month = isset( $_REQUEST['month'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['month'] ) ) : '';
	if ( '' !== $month && ! ssb_month_range( $month ) ) {
		$month = '';
	}

	$min = isset( $_REQUEST['min_amount'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['min_amount'] ) ) : '';
	$max = isset( $_REQUEST['max_amount'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['max_amount'] ) ) : '';

	return array(
		'status'        => $status,
		'instructor_id' => isset( $_REQUEST['instructor_id'] ) ? absint( wp_unslash( $_REQUEST['instructor_id'] ) ) : 0,
		'course_id'     => isset( $_REQUEST['course_id'] ) ? absint( wp_unslash( $_REQUEST['course_id'] ) ) : 0,
		'month'         => $month,
		'min_amount'    => ctype_digit( $min ) ? $min : '',
		'max_amount'    => ctype_digit( $max ) ? $max : '',
	);
}

/**
 * CSV に出す1行分を組み立てる.
 *
 * @param object $row 予約の行。
 * @return array<int,string>
 */
function ssb_booking_csv_row( $row ) {
	return array(
		(string) $row->id,
		(string) $row->created_at,
		(string) $row->paid_at,
		ssb_booking_status_label( $row->status ),
		(string) $row->course_title,
		(string) $row->instructor_name,
		(string) $row->instructor_email,
		(string) $row->name,
		(string) $row->email,
		(string) (int) $row->amount,
		(string) $row->start_at,
		(string) $row->end_at,
		(string) $row->stripe_session_id,
		(string) $row->stripe_payment_intent,
		(string) $row->note,
	);
}

/**
 * 予約一覧を CSV で書き出す.
 *
 * Excel でそのまま開けるよう UTF-8 の BOM を付ける。
 *
 * @return void
 */
function ssb_handle_export_bookings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( '権限がありません。' ), 403 );
	}

	check_admin_referer( 'ssb_export_bookings' );

	$rows = ssb_query_bookings( ssb_admin_booking_filters() );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename=ssb-bookings-' . wp_date( 'Ymd-His' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );

	// BOM。これが無いと Excel が文字化けする。
	fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

	fputcsv(
		$out,
		array(
			'予約ID', '申込日時', '決済日時', 'ステータス', '講座', '講師', '講師メール',
			'受講者名', '受講者メール', '金額', '開始日時', '終了日時',
			'Stripeセッション', 'PaymentIntent', '相談内容',
		)
	);

	foreach ( $rows as $row ) {
		fputcsv( $out, ssb_booking_csv_row( $row ) );
	}

	fclose( $out );
	exit;
}
add_action( 'admin_post_ssb_export_bookings', 'ssb_handle_export_bookings' );

/**
 * 予約一覧のページを描画する.
 *
 * @return void
 */
function ssb_admin_bookings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( '権限がありません。' ), 403 );
	}

	$filters     = ssb_admin_booking_filters();
	$rows        = ssb_query_bookings( $filters );
	$statuses    = ssb_booking_statuses();
	$instructors = ssb_get_instructors();
	$courses     = ssb_get_all_courses();
	$summary     = ssb_booking_monthly_summary( $filters['instructor_id'] );

	$paid_count = 0;
	$paid_total = 0;

	foreach ( $rows as $row ) {
		if ( 'paid' === $row->status ) {
			$paid_count++;
			$paid_total += (int) $row->amount;
		}
	}

	// 絞り込みを引き継いだままエクスポートする。
	$export_args = array( 'action' => 'ssb_export_bookings' );

	foreach ( $filters as $key => $value ) {
		if ( '' !== $value && 0 !== $value ) {
			$export_args[ $key ] = (string) $value;
		}
	}

	$export_url = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), 'ssb_export_bookings' );
	?>
	<div class="wrap">
		<h1>予約一覧</h1>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( SSB_ADMIN_BOOKINGS_SLUG ); ?>">

			<select name="instructor_id">
				<option value="0">すべての講師</option>
				<?php foreach ( $instructors as $one ) : ?>
					<option value="<?php echo esc_attr( (string) $one->id ); ?>" <?php selected( $filters['instructor_id'], (int) $one->id ); ?>>
						<?php echo esc_html( $one->display_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="course_id">
				<option value="0">すべての講座</option>
				<?php foreach ( $courses as $one ) : ?>
					<option value="<?php echo esc_attr( (string) $one->id ); ?>" <?php selected( $filters['course_id'], (int) $one->id ); ?>>
						<?php echo esc_html( $one->title ); ?>（<?php echo esc_html( $one->instructor_name ); ?>）
					</option>
				<?php endforeach; ?>
			</select>

			<select name="status">
				<option value="">すべての状態</option>
				<?php foreach ( $statuses as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input type="month" name="month" value="<?php echo esc_attr( $filters['month'] ); ?>" aria-label="申込月">

			<input type="number" name="min_amount" min="0" step="1" style="width:8em;" placeholder="金額の下限"
				value="<?php echo esc_attr( $filters['min_amount'] ); ?>">
			<input type="number" name="max_amount" min="0" step="1" style="width:8em;" placeholder="上限"
				value="<?php echo esc_attr( $filters['max_amount'] ); ?>">

			<button type="submit" class="button">絞り込む</button>
			<a class="button" href="<?php echo esc_url( ssb_admin_bookings_url() ); ?>">条件をクリア</a>
		</form>

		<p>
			<?php echo esc_html( (string) count( $rows ) ); ?> 件
			（確定 <?php echo esc_html( (string) $paid_count ); ?> 件 / 売上 <?php echo esc_html( number_format( $paid_total ) ); ?> 円）
			&nbsp;
			<a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>">CSV でダウンロード</a>
		</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width:5%;">ID</th>
					<th scope="col" style="width:13%;">申込日時</th>
					<th scope="col" style="width:18%;">講座</th>
					<th scope="col" style="width:12%;">講師</th>
					<th scope="col" style="width:16%;">受講者</th>
					<th scope="col" style="width:14%;">受講日時</th>
					<th scope="col" style="width:10%;">金額</th>
					<th scope="col" style="width:12%;">状態</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="8">該当する予約はありません。</td></tr>
			<?php endif; ?>

			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $row->id ); ?></td>
					<td><?php echo esc_html( ssb_format_datetime( $row->created_at ) ); ?></td>
					<td><?php echo esc_html( $row->course_title ); ?></td>
					<td><?php echo esc_html( $row->instructor_name ); ?></td>
					<td>
						<?php echo esc_html( $row->name ); ?><br>
						<a href="<?php echo esc_url( 'mailto:' . $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a>
					</td>
					<td><?php echo esc_html( ssb_format_datetime( $row->start_at ) ); ?></td>
					<td><?php echo esc_html( number_format( (int) $row->amount ) ); ?> 円</td>
					<td>
						<?php echo esc_html( ssb_booking_status_label( $row->status ) ); ?>
						<?php if ( $row->paid_at ) : ?>
							<br><span class="description"><?php echo esc_html( ssb_format_datetime( $row->paid_at ) ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2 style="margin-top:40px;">講師別の月次売上</h2>
		<p class="description">
			決済済みの予約を、決済日の月で集計したものです。講師への振込作業にお使いください。
			<?php if ( $filters['instructor_id'] ) : ?>
				（絞り込み中の講師のみ表示しています）
			<?php endif; ?>
		</p>

		<table class="wp-list-table widefat fixed striped" style="max-width:720px;">
			<thead>
				<tr>
					<th scope="col" style="width:20%;">対象月</th>
					<th scope="col" style="width:40%;">講師</th>
					<th scope="col" style="width:15%;">件数</th>
					<th scope="col" style="width:25%;">売上（税込）</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $summary ) : ?>
				<tr><td colspan="4">決済済みの予約はまだありません。</td></tr>
			<?php endif; ?>

			<?php foreach ( $summary as $line ) : ?>
				<tr>
					<td><?php echo esc_html( str_replace( '-', '年', $line['month'] ) ); ?>月</td>
					<td><?php echo esc_html( $line['instructor_name'] ); ?></td>
					<td><?php echo esc_html( (string) $line['count'] ); ?> 件</td>
					<td><?php echo esc_html( number_format( $line['total'] ) ); ?> 円</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
