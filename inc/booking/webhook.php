<?php
/**
 * Stripe Webhook（REST エンドポイント）.
 *
 * 決済の確定はここでしか行わない。success_url の画面は表示のみ（SPEC 4.6）。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

/**
 * 署名の許容ずれ（秒）.
 */
define( 'SSB_WEBHOOK_TOLERANCE', 300 );

/**
 * REST ルートを登録する.
 *
 * permission_callback は __return_true。認証は署名検証で行う。
 *
 * @return void
 */
function ssb_register_webhook_route() {
	register_rest_route(
		'ssb/v1',
		'/stripe-webhook',
		array(
			'methods'             => 'POST',
			'callback'            => 'ssb_rest_stripe_webhook',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'ssb_register_webhook_route' );

/**
 * Stripe-Signature ヘッダーを検証する.
 *
 * 署名対象は「タイムスタンプ . 生のリクエストボディ」の HMAC-SHA256。
 * 比較は hash_equals() で行い、古いタイムスタンプは再送攻撃を防ぐため弾く。
 *
 * @param string $payload   生のリクエストボディ。
 * @param string $header    Stripe-Signature ヘッダーの値。
 * @param string $secret    Webhook 署名シークレット。
 * @param int    $tolerance 許容するずれ（秒）。
 * @return true|WP_Error
 */
function ssb_verify_stripe_signature( $payload, $header, $secret, $tolerance = SSB_WEBHOOK_TOLERANCE ) {
	if ( '' === (string) $header ) {
		return new WP_Error( 'ssb_sig_missing', '署名ヘッダーがありません。' );
	}

	$timestamp  = 0;
	$signatures = array();

	foreach ( explode( ',', $header ) as $part ) {
		$pair = explode( '=', trim( $part ), 2 );

		if ( 2 !== count( $pair ) ) {
			continue;
		}

		if ( 't' === $pair[0] ) {
			$timestamp = (int) $pair[1];
		} elseif ( 'v1' === $pair[0] ) {
			$signatures[] = $pair[1];
		}
	}

	if ( $timestamp <= 0 ) {
		return new WP_Error( 'ssb_sig_no_timestamp', '署名にタイムスタンプがありません。' );
	}

	if ( ! $signatures ) {
		return new WP_Error( 'ssb_sig_no_v1', '対応する署名方式がありません。' );
	}

	if ( $tolerance > 0 && abs( time() - $timestamp ) > $tolerance ) {
		return new WP_Error( 'ssb_sig_too_old', '署名の時刻が離れすぎています。' );
	}

	$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

	foreach ( $signatures as $signature ) {
		if ( hash_equals( $expected, $signature ) ) {
			return true;
		}
	}

	return new WP_Error( 'ssb_sig_mismatch', '署名が一致しません。' );
}

/**
 * Webhook の受け口.
 *
 * @param WP_REST_Request $request リクエスト。
 * @return WP_REST_Response
 */
function ssb_rest_stripe_webhook( WP_REST_Request $request ) {
	$secret = ssb_stripe_webhook_secret();

	if ( '' === $secret ) {
		ssb_log( 'Webhook シークレットが未設定のため検証できない' );

		return new WP_REST_Response( array( 'error' => 'unconfigured' ), 500 );
	}

	$payload = (string) $request->get_body();
	$header  = (string) $request->get_header( 'stripe-signature' );

	$verified = ssb_verify_stripe_signature( $payload, $header, $secret );

	if ( is_wp_error( $verified ) ) {
		ssb_log( 'Webhook の署名検証に失敗', array( 'reason' => $verified->get_error_code() ) );

		return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 400 );
	}

	$event = json_decode( $payload, true );

	if ( ! is_array( $event ) || empty( $event['type'] ) ) {
		ssb_log( 'Webhook の本文を解釈できない' );

		return new WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
	}

	// 扱うのは決済完了だけ。それ以外は受け取ったことだけ返す。
	if ( 'checkout.session.completed' !== $event['type'] ) {
		return new WP_REST_Response( array( 'ignored' => $event['type'] ), 200 );
	}

	$session = isset( $event['data']['object'] ) ? $event['data']['object'] : array();

	try {
		ssb_complete_booking_from_session( $session );
	} catch ( Throwable $e ) {
		// 500 を返す前に必ず記録する（SPEC 4.6）。
		ssb_log( '予約確定の処理で例外', array( 'message' => $e->getMessage() ) );

		return new WP_REST_Response( array( 'error' => 'internal' ), 500 );
	}

	return new WP_REST_Response( array( 'received' => true ), 200 );
}

/**
 * Checkout セッションから予約を確定する.
 *
 * 同じイベントが2回届いても二重に確定しない。
 *
 * @param array<string,mixed> $session Checkout セッションのオブジェクト。
 * @return bool 確定したなら true。
 */
function ssb_complete_booking_from_session( $session ) {
	$metadata   = isset( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();
	$booking_id = isset( $metadata['booking_id'] ) ? (int) $metadata['booking_id'] : 0;
	$token      = isset( $metadata['hold_token'] ) ? (string) $metadata['hold_token'] : '';
	$session_id = isset( $session['id'] ) ? (string) $session['id'] : '';

	$booking = $booking_id ? ssb_get_booking( $booking_id ) : null;

	// metadata が欠けていてもセッションIDから引けるようにしておく。
	if ( ! $booking && '' !== $session_id ) {
		$booking = ssb_get_booking_by_session( $session_id );
	}

	if ( ! $booking ) {
		ssb_log( '予約が見つからない', array( 'booking_id' => $booking_id, 'session_id' => $session_id ) );

		return false;
	}

	// 冪等性：すでに確定済みなら何もしない。
	if ( 'paid' === $booking->status ) {
		return true;
	}

	if ( isset( $session['payment_status'] ) && 'paid' !== $session['payment_status'] ) {
		ssb_log( '未払いのセッションを受信', array( 'booking_id' => $booking->id, 'payment_status' => $session['payment_status'] ) );

		return false;
	}

	$intent = '';

	if ( isset( $session['payment_intent'] ) ) {
		$intent = is_array( $session['payment_intent'] )
			? (string) ( $session['payment_intent']['id'] ?? '' )
			: (string) $session['payment_intent'];
	}

	ssb_mark_booking_paid( (int) $booking->id, $intent );

	$slot = ssb_get_slot( (int) $booking->slot_id );

	if ( $slot && 'open' === $slot->status ) {
		// 仮押さえが外れていても、誰も取っていなければそのまま確定させる。
		ssb_log( '仮押さえが解放された状態で決済が完了した', array( 'booking_id' => $booking->id, 'slot_id' => $booking->slot_id ) );
	}

	if ( ! ssb_mark_slot_booked( (int) $booking->slot_id, $token ) ) {
		// 仮押さえが切れて他の人に取られたなど。決済は成立しているので運営の対応が要る。
		ssb_log(
			'決済は成立したが枠を確保できなかった。手動での確認が必要',
			array( 'booking_id' => $booking->id, 'slot_id' => $booking->slot_id )
		);
	}

	// 確定後の内容でメールを送る（paid_at と payment_intent を含めるため読み直す）。
	ssb_mail_booking_confirmed( ssb_get_booking_context( (int) $booking->id ) );

	return true;
}
