<?php
/**
 * 各種メール送信.
 *
 * 本文はプレーンテキスト。wp_mail() の失敗はサイトを止めないよう握りつぶさず、
 * 戻り値を呼び出し側に返すだけにする。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

/**
 * 運営の宛先アドレスを返す.
 *
 * @return string
 */
function ssb_admin_email() {
	return (string) get_option( 'admin_email' );
}

/**
 * サイト名を返す（メールの件名用）.
 *
 * @return string
 */
function ssb_site_name() {
	return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
}

/* -------------------------------------------------------------------------
 * 講師申請・審査
 * ---------------------------------------------------------------------- */

/**
 * 運営宛て：新規の講師申請通知.
 *
 * @param object|null $instructor 講師レコード。
 * @return bool
 */
function ssb_mail_new_application( $instructor ) {
	if ( ! $instructor ) {
		return false;
	}

	$subject = sprintf( '[%s] 講師申請がありました', ssb_site_name() );

	$body = implode(
		"\n",
		array(
			'新しい講師申請を受け付けました。',
			'',
			'表示名　　　：' . $instructor->display_name,
			'メール　　　：' . $instructor->email,
			'申請日時　　：' . $instructor->applied_at,
			'',
			'--- 自己紹介 ---',
			$instructor->profile,
			'',
			'--- 希望する講座内容 ---',
			$instructor->course_plan,
			'',
			'審査はこちらから：',
			admin_url( 'admin.php?page=ssb-instructors' ),
		)
	);

	return wp_mail( ssb_admin_email(), $subject, $body );
}

/**
 * 講師宛て：申請が承認された.
 *
 * @param object|null $instructor 講師レコード。
 * @return bool
 */
function ssb_mail_instructor_approved( $instructor ) {
	if ( ! $instructor ) {
		return false;
	}

	$subject = sprintf( '[%s] 講師申請を承認しました', ssb_site_name() );

	$body = implode(
		"\n",
		array(
			$instructor->display_name . ' 様',
			'',
			'講師申請を承認しました。マイページから講座の作成と予約枠の登録ができます。',
			'',
			'マイページ：',
			ssb_get_page_url( 'mypage' ),
			'',
			'ログインには申請時のメールアドレスをご利用ください。',
			'パスワードが未設定の場合は、別途お送りしているパスワード設定メールからご登録ください。',
			'',
			ssb_site_name(),
		)
	);

	return wp_mail( $instructor->email, $subject, $body );
}

/**
 * 講師宛て：申請が却下された.
 *
 * @param object|null $instructor 講師レコード。
 * @return bool
 */
function ssb_mail_instructor_rejected( $instructor ) {
	if ( ! $instructor ) {
		return false;
	}

	$subject = sprintf( '[%s] 講師申請の審査結果について', ssb_site_name() );

	$body = implode(
		"\n",
		array(
			$instructor->display_name . ' 様',
			'',
			'このたびは講師申請をいただきありがとうございました。',
			'慎重に検討いたしましたが、今回は見送らせていただくこととなりました。',
			'',
			'ご不明な点がありましたら、本メールにご返信ください。',
			'',
			ssb_site_name(),
		)
	);

	return wp_mail( $instructor->email, $subject, $body );
}

/* -------------------------------------------------------------------------
 * 予約確定
 * ---------------------------------------------------------------------- */

/**
 * 予約内容を本文用の行にする.
 *
 * @param object $context ssb_get_booking_context() の戻り値。
 * @return string[]
 */
function ssb_booking_mail_lines( $context ) {
	$lines = array(
		'講座　　　：' . $context->course_title,
		'日時　　　：' . mysql2date( 'Y年n月j日(D) H:i', $context->start_at )
			. '〜' . mysql2date( 'H:i', $context->end_at ),
		'講師　　　：' . $context->instructor_name,
		'お名前　　：' . $context->name,
		'メール　　：' . $context->email,
		'金額　　　：' . number_format( (int) $context->amount ) . ' 円（税込）',
	);

	if ( '' !== (string) $context->note ) {
		$lines[] = '';
		$lines[] = '--- 相談内容 ---';
		$lines[] = (string) $context->note;
	}

	return $lines;
}

/**
 * 受講者宛て：予約内容の確認.
 *
 * @param object|null $context 予約の詳細。
 * @return bool
 */
function ssb_mail_booking_customer( $context ) {
	if ( ! $context ) {
		return false;
	}

	$subject = sprintf( '[%s] ご予約が確定しました', ssb_site_name() );

	$body = implode(
		"\n",
		array_merge(
			array(
				$context->name . ' 様',
				'',
				'お申し込みありがとうございます。ご予約が確定しました。',
				'',
			),
			ssb_booking_mail_lines( $context ),
			array(
				'',
				'当日のご案内は、講師からご連絡いたします。',
				'',
				'ご都合が悪くなった場合は、下記からキャンセルできます（全額返金）。',
				ssb_cancel_url( $context ),
				'',
				'ご不明な点がありましたら、本メールにご返信ください。',
				'',
				ssb_site_name(),
			)
		)
	);

	return wp_mail( $context->email, $subject, $body );
}

/**
 * 講師宛て：予約通知（.ics 添付）.
 *
 * カレンダーに取り込めるよう、招待用の .ics を text/calendar で添付する。
 *
 * @param object|null $context 予約の詳細。
 * @return bool
 */
function ssb_mail_booking_instructor( $context ) {
	if ( ! $context ) {
		return false;
	}

	$subject = sprintf( '[%s] 予約が入りました', ssb_site_name() );

	$body = implode(
		"\n",
		array_merge(
			array(
				$context->instructor_name . ' 様',
				'',
				'講座に予約が入りました。',
				'',
			),
			ssb_booking_mail_lines( $context ),
			array(
				'',
				'添付の invite.ics をお使いのカレンダーに取り込むと予定が登録できます。',
				'',
				'マイページ：',
				ssb_get_page_url( 'mypage' ),
				'',
				ssb_site_name(),
			)
		)
	);

	ssb_pending_ics( ssb_build_invite_ics( $context ) );
	$sent = wp_mail( $context->instructor_email, $subject, $body );
	ssb_pending_ics( '' );

	return $sent;
}

/**
 * 運営宛て：予約通知.
 *
 * @param object|null $context 予約の詳細。
 * @return bool
 */
function ssb_mail_booking_admin( $context ) {
	if ( ! $context ) {
		return false;
	}

	$subject = sprintf( '[%s] 予約が確定しました（#%d）', ssb_site_name(), (int) $context->id );

	$body = implode(
		"\n",
		array_merge(
			array(
				'予約が確定しました。',
				'',
				'予約ID　　：' . (int) $context->id,
				'決済日時　：' . $context->paid_at,
				'Stripe　　：' . $context->stripe_payment_intent,
				'',
			),
			ssb_booking_mail_lines( $context ),
			array(
				'',
				'講師への振込対象：' . $context->instructor_name . '（' . $context->instructor_email . '）',
			)
		)
	);

	return wp_mail( ssb_admin_email(), $subject, $body );
}

/**
 * 予約確定時のメールをまとめて送る.
 *
 * 1通失敗しても残りは送る。結果はログに残す。
 *
 * @param object|null $context 予約の詳細。
 * @return void
 */
function ssb_mail_booking_confirmed( $context ) {
	if ( ! $context ) {
		return;
	}

	$results = array(
		'customer'   => ssb_mail_booking_customer( $context ),
		'instructor' => ssb_mail_booking_instructor( $context ),
		'admin'      => ssb_mail_booking_admin( $context ),
	);

	foreach ( $results as $to => $ok ) {
		if ( ! $ok ) {
			ssb_log( '予約確定メールの送信に失敗', array( 'booking_id' => $context->id, 'to' => $to ) );
		}
	}
}

/* -------------------------------------------------------------------------
 * キャンセル
 * ---------------------------------------------------------------------- */

/**
 * キャンセルを3者へ通知する.
 *
 * 講師宛てには取り消し用の .ics を添付し、カレンダーの予定を消せるようにする。
 *
 * @param object|null $context 予約の詳細（キャンセル後の状態）。
 * @return void
 */
function ssb_mail_booking_cancelled( $context ) {
	if ( ! $context ) {
		return;
	}

	$by      = ssb_cancelled_by_label( (string) $context->cancelled_by );
	$details = ssb_booking_mail_lines( $context );

	// 受講者へ.
	$customer = implode(
		"\n",
		array_merge(
			array(
				$context->name . ' 様',
				'',
				'下記のご予約をキャンセルし、全額を返金いたしました。',
				'（' . $by . 'による手続きです）',
				'',
			),
			$details,
			array(
				'',
				'返金はご利用のカード会社を経由するため、明細に反映されるまで数日かかることがあります。',
				'',
				ssb_site_name(),
			)
		)
	);

	$ok_customer = wp_mail( $context->email, sprintf( '[%s] ご予約をキャンセルしました', ssb_site_name() ), $customer );

	// 講師へ（取り消しの .ics 付き）.
	$instructor = implode(
		"\n",
		array_merge(
			array(
				$context->instructor_name . ' 様',
				'',
				'下記の予約がキャンセルされました。（' . $by . 'による手続きです）',
				'受講料は全額返金済みです。予約枠は再び予約可能な状態に戻しました。',
				'',
			),
			$details,
			array(
				'',
				'添付の invite.ics を開くと、カレンダーの予定を取り消せます。',
				'',
				ssb_site_name(),
			)
		)
	);

	ssb_pending_ics( ssb_build_invite_ics( $context, 'CANCEL' ) );
	$ok_instructor = wp_mail( $context->instructor_email, sprintf( '[%s] 予約がキャンセルされました', ssb_site_name() ), $instructor );
	ssb_pending_ics( '' );

	// 運営へ.
	$admin = implode(
		"\n",
		array_merge(
			array(
				'予約がキャンセルされ、全額返金しました。',
				'',
				'予約ID　　：' . (int) $context->id,
				'実行者　　：' . $by,
				'キャンセル：' . $context->cancelled_at,
				'返金ID　　：' . $context->stripe_refund_id,
				'',
			),
			$details
		)
	);

	$ok_admin = wp_mail( ssb_admin_email(), sprintf( '[%s] 予約がキャンセルされました（#%d）', ssb_site_name(), (int) $context->id ), $admin );

	foreach ( array( 'customer' => $ok_customer, 'instructor' => $ok_instructor, 'admin' => $ok_admin ) as $to => $ok ) {
		if ( ! $ok ) {
			ssb_log( 'キャンセル通知メールの送信に失敗', array( 'booking_id' => (int) $context->id, 'to' => $to ) );
		}
	}
}
