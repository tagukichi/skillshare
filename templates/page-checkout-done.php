<?php
/**
 * 予約完了（/booking/done）.
 *
 * ここは表示のみ。予約の確定は Webhook が行う（SPEC 4.6）。
 * Webhook が届く前にこの画面が開くこともあるため、その場合は確認中と伝える。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

$ssb_session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
$ssb_booking    = '' !== $ssb_session_id ? ssb_get_booking_by_session( $ssb_session_id ) : null;
$ssb_context    = $ssb_booking ? ssb_get_booking_context( (int) $ssb_booking->id ) : null;

get_header();
?>

<?php if ( ! $ssb_context ) : ?>

	<h1 class="ssb-page__title">ご予約の確認</h1>
	<p>お申し込みの内容を確認できませんでした。</p>
	<p class="ssb-muted">
		決済が完了している場合は、確認メールをお送りしています。
		お心当たりがない場合は、お手数ですが運営までお問い合わせください。
	</p>
	<p><a class="ssb-button" href="<?php echo esc_url( ssb_get_page_url( 'courses' ) ); ?>">講座一覧へ</a></p>

<?php elseif ( 'paid' === $ssb_context->status ) : ?>

	<h1 class="ssb-page__title">ご予約が確定しました</h1>

	<div class="ssb-notice ssb-notice--success">
		<p>お申し込みありがとうございます。確認メールをお送りしました。</p>
	</div>

	<?php require __DIR__ . '/parts/booking-summary.php'; ?>

	<p><a class="ssb-button ssb-button--secondary" href="<?php echo esc_url( ssb_get_page_url( 'courses' ) ); ?>">講座一覧へ</a></p>

<?php else : ?>

	<h1 class="ssb-page__title">決済を確認しています</h1>

	<div class="ssb-notice">
		<p>決済の確認中です。確定しだい、ご登録のメールアドレスに確認メールをお送りします。</p>
		<p class="ssb-muted">この画面を閉じていただいて構いません。</p>
	</div>

	<?php require __DIR__ . '/parts/booking-summary.php'; ?>

<?php endif; ?>

<?php
get_footer();
