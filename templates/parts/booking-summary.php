<?php
/**
 * 予約内容の表示.
 *
 * 呼び出し側で $ssb_context（ssb_get_booking_context の戻り値）を用意しておくこと。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $ssb_context ) ) {
	return;
}
?>
<table class="ssb-table">
	<tbody>
		<tr>
			<th scope="row">講座</th>
			<td><?php echo esc_html( $ssb_context->course_title ); ?></td>
		</tr>
		<tr>
			<th scope="row">日時</th>
			<td>
				<?php echo esc_html( mysql2date( 'Y年n月j日(D) H:i', $ssb_context->start_at ) ); ?>
				〜
				<?php echo esc_html( mysql2date( 'H:i', $ssb_context->end_at ) ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">講師</th>
			<td><?php echo esc_html( $ssb_context->instructor_name ); ?></td>
		</tr>
		<tr>
			<th scope="row">お名前</th>
			<td><?php echo esc_html( $ssb_context->name ); ?></td>
		</tr>
		<tr>
			<th scope="row">メールアドレス</th>
			<td><?php echo esc_html( $ssb_context->email ); ?></td>
		</tr>
		<tr>
			<th scope="row">金額</th>
			<td><?php echo esc_html( number_format( (int) $ssb_context->amount ) ); ?> 円（税込）</td>
		</tr>
		<tr>
			<th scope="row">状態</th>
			<td><?php echo esc_html( ssb_booking_status_label( $ssb_context->status ) ); ?></td>
		</tr>
	</tbody>
</table>
