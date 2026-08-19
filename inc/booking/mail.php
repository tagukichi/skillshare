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
