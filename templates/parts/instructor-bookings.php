<?php
/**
 * 講師マイページの予約一覧（表）.
 *
 * 呼び出し側で $ssb_list に予約の配列を入れておくこと。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $ssb_list ) ) {
	return;
}
?>
<table class="ssb-table">
	<thead>
		<tr>
			<th scope="col">日時</th>
			<th scope="col">講座</th>
			<th scope="col">受講者</th>
			<th scope="col">相談内容</th>
			<th scope="col">金額</th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $ssb_list as $ssb_row ) : ?>
			<tr>
				<td>
					<?php echo esc_html( mysql2date( 'Y/n/j (D) H:i', $ssb_row->start_at ) ); ?>
					–
					<?php echo esc_html( mysql2date( 'H:i', $ssb_row->end_at ) ); ?>
				</td>
				<td><?php echo esc_html( $ssb_row->course_title ); ?></td>
				<td>
					<?php echo esc_html( $ssb_row->name ); ?><br>
					<a href="<?php echo esc_url( 'mailto:' . $ssb_row->email ); ?>"><?php echo esc_html( $ssb_row->email ); ?></a>
				</td>
				<td>
					<?php if ( '' !== (string) $ssb_row->note ) : ?>
						<?php echo nl2br( esc_html( (string) $ssb_row->note ) ); ?>
					<?php else : ?>
						<span class="ssb-muted">—</span>
					<?php endif; ?>
				</td>
				<td class="ssb-price"><?php echo esc_html( number_format( (int) $ssb_row->amount ) ); ?> 円</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
