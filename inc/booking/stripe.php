<?php
/**
 * Stripe Checkout セッション作成.
 *
 * API キーは wp-config.php の定数から読む。DB には保存しない。
 * Composer が使えない環境も想定し、API 呼び出しは wp_remote_post() で行う。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checkout セッションの有効期限（分）.
 *
 * Stripe は 30 分未満を受け付けないため、時計のずれを見込んで少し長めにする。
 */
define( 'SSB_CHECKOUT_EXPIRES_MINUTES', 32 );

/**
 * Checkout へ進むときに延長する仮押さえの長さ（分）.
 *
 * セッションより長く保つ。決済がぎりぎりで完了しても枠が空いていない状態を避ける。
 */
define( 'SSB_CHECKOUT_HOLD_MINUTES', 35 );

/**
 * Stripe API のベースURL.
 */
define( 'SSB_STRIPE_API', 'https://api.stripe.com/v1' );

/* -------------------------------------------------------------------------
 * 設定とログ
 * ---------------------------------------------------------------------- */

/**
 * ログを残す.
 *
 * SPEC 4.6 が Webhook でのログを必須としているため、WP_DEBUG の設定に関わらず残す。
 * 記録するのは失敗時だけなので、通常運用でログが膨らむことはない。
 * 秘密鍵や ICS URL は決して渡さないこと。
 *
 * @param string $message メッセージ。
 * @param mixed  $context 付随情報。
 * @return void
 */
function ssb_log( $message, $context = null ) {
	$line = '[skillshare] ' . $message;

	if ( null !== $context ) {
		$line .= ' ' . wp_json_encode( $context );
	}

	error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Stripe の秘密鍵を返す.
 *
 * @return string 未設定なら空文字。
 */
function ssb_stripe_secret() {
	return defined( 'SSB_STRIPE_SECRET' ) ? (string) SSB_STRIPE_SECRET : '';
}

/**
 * Webhook の署名シークレットを返す.
 *
 * @return string 未設定なら空文字。
 */
function ssb_stripe_webhook_secret() {
	return defined( 'SSB_STRIPE_WEBHOOK_SECRET' ) ? (string) SSB_STRIPE_WEBHOOK_SECRET : '';
}

/* -------------------------------------------------------------------------
 * API
 * ---------------------------------------------------------------------- */

/**
 * Stripe API を呼ぶ.
 *
 * @param string              $path   /checkout/sessions のようなパス。
 * @param array<string,mixed> $params フォームパラメータ（入れ子可）。
 * @return array<string,mixed>|WP_Error デコードしたレスポンス。
 */
function ssb_stripe_post( $path, $params ) {
	$secret = ssb_stripe_secret();

	if ( '' === $secret ) {
		return new WP_Error( 'ssb_stripe_unconfigured', '決済の設定が完了していません。運営までお問い合わせください。' );
	}

	$response = wp_remote_post(
		SSB_STRIPE_API . $path,
		array(
			'timeout' => 30,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $secret,
				'Content-Type'   => 'application/x-www-form-urlencoded',
				'Stripe-Version' => '2024-06-20',
			),
			// 入れ子の配列は a[b][c]=v 形式に展開される。Stripe はこの形を受け付ける。
			'body'    => http_build_query( $params, '', '&' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		ssb_log( 'Stripe への接続に失敗', array( 'path' => $path, 'error' => $response->get_error_message() ) );

		return new WP_Error( 'ssb_stripe_unreachable', '決済サービスに接続できませんでした。時間をおいて再度お試しください。' );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
		$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : '不明なエラー';

		ssb_log( 'Stripe がエラーを返した', array( 'path' => $path, 'status' => $code, 'detail' => $detail ) );

		return new WP_Error( 'ssb_stripe_error', '決済の準備に失敗しました：' . $detail );
	}

	return $body;
}

/**
 * Checkout セッションを作る.
 *
 * 金額はフロントから受け取らず、必ず講座レコードの price を使う（SPEC 6）。
 *
 * @param object $booking 予約レコード。
 * @param object $context 予約に紐づく枠・講座の情報（ssb_get_booking_context）。
 * @return array<string,mixed>|WP_Error
 */
function ssb_create_checkout_session( $booking, $context ) {
	$success = add_query_arg(
		'session_id',
		'{CHECKOUT_SESSION_ID}',
		ssb_get_page_url( 'booking/done' )
	);

	// {CHECKOUT_SESSION_ID} はプレースホルダなのでエンコードを戻す。
	$success = str_replace( '%7BCHECKOUT_SESSION_ID%7D', '{CHECKOUT_SESSION_ID}', $success );

	$metadata = array(
		'booking_id' => (string) $booking->id,
		'slot_id'    => (string) $booking->slot_id,
		'hold_token' => (string) $context->hold_token,
	);

	$params = array(
		'mode'                => 'payment',
		'success_url'         => $success,
		'cancel_url'          => ssb_course_url( $context->course_id ),
		'client_reference_id' => (string) $booking->id,
		'customer_email'      => $booking->email,
		'expires_at'          => time() + SSB_CHECKOUT_EXPIRES_MINUTES * MINUTE_IN_SECONDS,
		'line_items'          => array(
			array(
				'quantity'   => 1,
				'price_data' => array(
					'currency'     => 'jpy',
					'unit_amount'  => (int) $context->price,
					'product_data' => array(
						'name'        => $context->course_title,
						'description' => mysql2date( 'Y年n月j日(D) H:i', $context->start_at ) . '〜'
							. mysql2date( 'H:i', $context->end_at ) . '／講師：' . $context->instructor_name,
					),
				),
			),
		),
		'metadata'            => $metadata,
		'payment_intent_data' => array(
			'metadata' => $metadata,
		),
	);

	return ssb_stripe_post( '/checkout/sessions', $params );
}

/* -------------------------------------------------------------------------
 * 決済の開始
 * ---------------------------------------------------------------------- */

/**
 * エラーを見せて講座ページへ戻す.
 *
 * @param string $back    戻り先URL。
 * @param string $message メッセージ。
 * @return void
 */
function ssb_checkout_fail( $back, $message ) {
	ssb_flash_redirect( $back, array( 'errors' => array( $message ) ) );
}

/**
 * 申し込みフォームを受けて Checkout へ送る.
 *
 * @return void
 */
function ssb_handle_start_checkout() {
	check_admin_referer( 'ssb_start_checkout', 'ssb_checkout_nonce' );

	$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
	$slot_id   = isset( $_POST['slot_id'] ) ? absint( wp_unslash( $_POST['slot_id'] ) ) : 0;
	$token     = isset( $_POST['hold_token'] ) ? sanitize_text_field( wp_unslash( $_POST['hold_token'] ) ) : '';

	$course = $course_id ? ssb_get_published_course( $course_id ) : null;
	$back   = $course ? ssb_course_url( $course_id ) : ssb_get_page_url( 'courses' );
	$slot   = $slot_id ? ssb_get_slot( $slot_id ) : null;

	if ( ! $course || ! $slot || (int) $slot->course_id !== $course_id ) {
		ssb_checkout_fail( $back, 'この講座の枠が見つかりませんでした。もう一度お選びください。' );
	}

	// 仮押さえを持っている本人か、期限内かを確認する。
	if ( ! ssb_slot_holds_token( $slot, $token ) || $slot->hold_expires_at < current_time( 'mysql' ) ) {
		ssb_checkout_fail( $back, '確保の時間が過ぎたか、別の方が予約されました。お手数ですが、もう一度お選びください。' );
	}

	$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$note  = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

	if ( '' === $name ) {
		ssb_checkout_fail( $back, 'お名前を入力してください。' );
	}

	if ( ! is_email( $email ) ) {
		ssb_checkout_fail( $back, 'メールアドレスを正しい形式で入力してください。' );
	}

	// Checkout セッションの有効期限に合わせて仮押さえを伸ばす。
	ssb_extend_hold( $slot_id, $token, SSB_CHECKOUT_HOLD_MINUTES );

	// 金額はフォームからではなく講座レコードから取る。
	$booking_id = ssb_insert_booking(
		array(
			'slot_id' => $slot_id,
			'name'    => $name,
			'email'   => $email,
			'note'    => $note,
			'amount'  => (int) $course->price,
		)
	);

	if ( ! $booking_id ) {
		ssb_checkout_fail( $back, '予約の作成に失敗しました。時間をおいて再度お試しください。' );
	}

	$booking = ssb_get_booking( $booking_id );
	$context = ssb_get_booking_context( $booking_id );

	$session = ssb_create_checkout_session( $booking, $context );

	if ( is_wp_error( $session ) ) {
		ssb_cancel_booking( $booking_id );
		ssb_checkout_fail( $back, $session->get_error_message() );
	}

	if ( empty( $session['id'] ) || empty( $session['url'] ) ) {
		ssb_cancel_booking( $booking_id );
		ssb_log( 'Checkout セッションの応答が不正', $session );
		ssb_checkout_fail( $back, '決済の準備に失敗しました。時間をおいて再度お試しください。' );
	}

	ssb_set_booking_session( $booking_id, $session['id'] );

	// 遷移先は Stripe のドメインなので wp_safe_redirect() は使えない。
	wp_redirect( $session['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	exit;
}
add_action( 'admin_post_ssb_start_checkout', 'ssb_handle_start_checkout' );
add_action( 'admin_post_nopriv_ssb_start_checkout', 'ssb_handle_start_checkout' );
