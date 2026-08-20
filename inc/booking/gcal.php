<?php
/**
 * Googleカレンダー連携（ICS取得・招待用ICS生成）.
 *
 * 招待メールに添付する .ics の生成をここで行う。
 * ICS URL の読み取りと除外表示は実装順序 11 で追加する。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * ICS の組み立て
 * ---------------------------------------------------------------------- */

/**
 * ICS のテキスト値をエスケープする.
 *
 * RFC 5545 で意味を持つ文字（バックスラッシュ・セミコロン・カンマ）を退避し、
 * 改行は \n という2文字に置き換える。
 *
 * @param string $text 元のテキスト。
 * @return string
 */
function ssb_ics_escape( $text ) {
	$text  = (string) $text;
	$slash = chr( 92 );

	$text = str_replace( $slash, $slash . $slash, $text );
	$text = str_replace( ';', $slash . ';', $text );
	$text = str_replace( ',', $slash . ',', $text );

	return (string) preg_replace( '/\r\n|\r|\n/', $slash . 'n', $text );
}

/**
 * 1行を 75 オクテットで折り返す.
 *
 * 継続行は先頭に空白を置く。日本語が途中で割れないよう文字単位で数える。
 *
 * @param string $line 折り返す行。
 * @return string
 */
function ssb_ics_fold( $line ) {
	if ( strlen( $line ) <= 75 ) {
		return $line;
	}

	$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
	$out   = '';
	$len   = 0;

	foreach ( (array) $chars as $char ) {
		$bytes = strlen( $char );

		if ( $len + $bytes > 73 ) {
			$out .= "\r\n ";
			$len  = 1;
		}

		$out .= $char;
		$len += $bytes;
	}

	return $out;
}

/**
 * ローカルの壁時計を ICS の UTC 表記にする.
 *
 * TZID を使うと VTIMEZONE も添える必要があるため、UTC に寄せて Z 付きで書く。
 *
 * @param string $datetime DATETIME 文字列（サイトのタイムゾーン）。
 * @return string 例: 20260821T010000Z。
 */
function ssb_ics_utc( $datetime ) {
	$local = new DateTimeImmutable( (string) $datetime, wp_timezone() );

	return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
}

/**
 * 予約から招待用の ICS を組み立てる.
 *
 * @param object $context ssb_get_booking_context() の戻り値。
 * @return string ICS 本文。
 */
function ssb_build_invite_ics( $context ) {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	$summary = sprintf( '%s（%s 様）', $context->course_title, $context->name );

	$description = array(
		'受講者：' . $context->name,
		'メール：' . $context->email,
	);

	if ( '' !== (string) $context->note ) {
		$description[] = '';
		$description[] = '相談内容：';
		$description[] = (string) $context->note;
	}

	$lines = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Skillshare//Booking//JA',
		'CALSCALE:GREGORIAN',
		'METHOD:REQUEST',
		'BEGIN:VEVENT',
		'UID:ssb-booking-' . (int) $context->id . '@' . $host,
		'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
		'DTSTART:' . ssb_ics_utc( $context->start_at ),
		'DTEND:' . ssb_ics_utc( $context->end_at ),
		'SUMMARY:' . ssb_ics_escape( $summary ),
		'DESCRIPTION:' . ssb_ics_escape( implode( "\n", $description ) ),
		'ORGANIZER;CN=' . ssb_ics_escape( ssb_site_name() ) . ':mailto:' . ssb_admin_email(),
		'ATTENDEE;CN=' . ssb_ics_escape( (string) $context->instructor_name )
			. ';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $context->instructor_email,
		'STATUS:CONFIRMED',
		'SEQUENCE:0',
		'END:VEVENT',
		'END:VCALENDAR',
	);

	return implode( "\r\n", array_map( 'ssb_ics_fold', $lines ) ) . "\r\n";
}

/* -------------------------------------------------------------------------
 * 添付
 * ---------------------------------------------------------------------- */

/**
 * 次に送るメールへ添付する ICS を出し入れする.
 *
 * wp_mail() はファイルパスでしか添付できず MIME タイプも指定できないため、
 * phpmailer_init で直接載せる。ここはその受け渡し役。
 *
 * @param string|null $ics 設定する ICS。null なら現在値を返すだけ。
 * @return string
 */
function ssb_pending_ics( $ics = null ) {
	static $current = '';

	if ( null !== $ics ) {
		$current = (string) $ics;
	}

	return $current;
}

/**
 * ICS を text/calendar として添付する.
 *
 * @param object $phpmailer PHPMailer インスタンス。
 * @return void
 */
function ssb_attach_pending_ics( $phpmailer ) {
	$ics = ssb_pending_ics();

	if ( '' === $ics ) {
		return;
	}

	$phpmailer->addStringAttachment( $ics, 'invite.ics', 'base64', 'text/calendar; method=REQUEST; charset=UTF-8' );
}
add_action( 'phpmailer_init', 'ssb_attach_pending_ics' );
