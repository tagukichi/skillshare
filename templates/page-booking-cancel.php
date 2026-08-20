<?php
/**
 * 予約のキャンセル（/booking/cancel）.
 *
 * 予約確認メールに載せたリンクから開く。ログインは不要で、
 * 予約IDとトークンの組が一致した場合だけ内容を表示する。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

$ssb_id     = isset( $_GET['booking'] ) ? absint( wp_unslash( $_GET['booking'] ) ) : 0;
$ssb_token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
$ssb_done   = ! empty( $_GET['cancelled'] );

$ssb_flash  = ssb_flash_take();
$ssb_errors = isset( $ssb_flash['errors'] ) ? (array) $ssb_flash['errors'] : array();

$ssb_booking = ( $ssb_id && '' !== $ssb_token ) ? ssb_get_booking_by_cancel_token( $ssb_id, $ssb_token ) : null;
$ssb_context = $ssb_booking ? ssb_get_booking_context( $ssb_id ) : null;

get_header();
?>

<h1 class="ssb-page__title">予約のキャンセル</h1>

<?php if ( $ssb_errors ) : ?>
	<div class="ssb-notice ssb-notice--error">
		<ul>
			<?php foreach ( $ssb_errors as $ssb_error ) : ?>
				<li><?php echo esc_html( $ssb_error ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php if ( ! $ssb_context ) : ?>

	<p>ご予約を確認できませんでした。</p>
	<p class="ssb-muted">
		予約確認メールに記載のリンクからお進みください。
		リンクが見当たらない場合や、うまく開けない場合は運営までご連絡ください。
	</p>
	<p><a class="ssb-button" href="<?php echo esc_url( home_url( '/' ) ); ?>">トップへ</a></p>

<?php elseif ( $ssb_done || 'cancelled' === $ssb_context->status ) : ?>

	<div class="ssb-notice ssb-notice--success">
		<p>ご予約をキャンセルしました。受講料は全額返金いたします。</p>
		<p class="ssb-muted">
			返金はご利用のカード会社を経由するため、明細に反映されるまで数日かかることがあります。
		</p>
	</div>

	<?php require __DIR__ . '/parts/booking-summary.php'; ?>

	<p><a class="ssb-button ssb-button--secondary" href="<?php echo esc_url( ssb_get_page_url( 'courses' ) ); ?>">講座一覧へ</a></p>

<?php elseif ( ! ssb_customer_can_cancel( $ssb_context ) ) : ?>

	<div class="ssb-notice">
		<p>この画面からはキャンセルできません。</p>
		<p class="ssb-muted">
			開始時刻を過ぎているか、まだ決済が完了していない可能性があります。
			お手数ですが運営までご連絡ください。
		</p>
	</div>

	<?php require __DIR__ . '/parts/booking-summary.php'; ?>

<?php else : ?>

	<p>下記のご予約をキャンセルします。よろしければボタンを押してください。</p>
	<p class="ssb-muted">キャンセルすると受講料は<strong>全額返金</strong>されます。取り消しはできません。</p>

	<?php require __DIR__ . '/parts/booking-summary.php'; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssb_cancel_booking', 'ssb_cancel_nonce' ); ?>
		<input type="hidden" name="action" value="ssb_cancel_booking">
		<input type="hidden" name="booking" value="<?php echo esc_attr( (string) $ssb_context->id ); ?>">
		<input type="hidden" name="token" value="<?php echo esc_attr( $ssb_token ); ?>">

		<p>
			<button type="submit" class="ssb-button"
				onclick="return confirm('このご予約をキャンセルします。よろしいですか？');">キャンセルする（全額返金）</button>
			<a class="ssb-button ssb-button--secondary" href="<?php echo esc_url( ssb_course_url( $ssb_context->course_id ) ); ?>">やめる</a>
		</p>
	</form>

<?php endif; ?>

<?php
get_footer();
