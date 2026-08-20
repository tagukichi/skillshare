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

/* -------------------------------------------------------------------------
 * ICS の読み取り（除外フィルタ用）
 * ---------------------------------------------------------------------- */

/**
 * キャッシュの有効時間（秒）.
 */
define( 'SSB_GCAL_CACHE_TTL', HOUR_IN_SECONDS );

/**
 * 繰り返し予定を展開する期間（日）.
 *
 * 枠の一括生成の上限（186日）より少し広く取る。
 */
define( 'SSB_GCAL_WINDOW_DAYS', 190 );

/**
 * ICS URL として受け付けられるかを判定する.
 *
 * SPEC 4.4 のとおり Googleカレンダーの限定公開URLだけを許す。
 *
 * @param string $url URL。
 * @return bool
 */
function ssb_is_valid_gcal_url( $url ) {
	return (bool) preg_match( '#^https://calendar\.google\.com/#', (string) $url );
}

/**
 * ICS の行を組み立て直す（折り返しの解除）.
 *
 * @param string $ics ICS 本文。
 * @return string[] 行の配列。
 */
function ssb_gcal_unfold( $ics ) {
	$ics = str_replace( array( "\r\n", "\r" ), "\n", (string) $ics );
	$ics = str_replace( array( "\n ", "\n\t" ), '', $ics );

	return explode( "\n", $ics );
}

/**
 * ICS のプロパティ行を名前・パラメータ・値に分解する.
 *
 * 例: DTSTART;TZID=Asia/Tokyo:20260821T100000
 *
 * @param string $line 行。
 * @return array<string,mixed>|null name / params / value。
 */
function ssb_gcal_parse_line( $line ) {
	$colon = strpos( $line, ':' );

	if ( false === $colon ) {
		return null;
	}

	$head  = substr( $line, 0, $colon );
	$value = substr( $line, $colon + 1 );
	$parts = explode( ';', $head );
	$name  = strtoupper( array_shift( $parts ) );

	$params = array();

	foreach ( $parts as $part ) {
		$pair = explode( '=', $part, 2 );

		if ( 2 === count( $pair ) ) {
			$params[ strtoupper( $pair[0] ) ] = trim( $pair[1], '"' );
		}
	}

	return array(
		'name'   => $name,
		'params' => $params,
		'value'  => $value,
	);
}

/**
 * ICS の日時を Unix タイムスタンプにする.
 *
 * @param array<string,string> $params パラメータ（TZID / VALUE）。
 * @param string               $value  日時の値。
 * @return array<string,mixed>|null ts / all_day。
 */
function ssb_gcal_parse_datetime( $params, $value ) {
	$site  = wp_timezone();
	$value = trim( $value );

	if ( isset( $params['VALUE'] ) && 'DATE' === strtoupper( $params['VALUE'] ) ) {
		$date = DateTimeImmutable::createFromFormat( 'Ymd|', $value, $site );

		return $date ? array(
			'ts'      => $date->getTimestamp(),
			'all_day' => true,
		) : null;
	}

	if ( str_ends_with( $value, 'Z' ) ) {
		$date = DateTimeImmutable::createFromFormat( 'Ymd\THis\Z', $value, new DateTimeZone( 'UTC' ) );

		return $date ? array(
			'ts'      => $date->getTimestamp(),
			'all_day' => false,
		) : null;
	}

	$tz = $site;

	if ( ! empty( $params['TZID'] ) ) {
		try {
			$tz = new DateTimeZone( $params['TZID'] );
		} catch ( Exception $e ) {
			$tz = $site;
		}
	}

	$date = DateTimeImmutable::createFromFormat( 'Ymd\THis', $value, $tz );

	return $date ? array(
		'ts'      => $date->getTimestamp(),
		'all_day' => false,
	) : null;
}

/**
 * 繰り返し予定を展開する.
 *
 * SPEC 4.4 のとおり簡易対応。FREQ=DAILY と FREQ=WEEKLY のみ扱い、
 * それ以外は繰り返さず元の1件として扱う。
 * WEEKLY で BYDAY と INTERVAL が同時指定された場合、INTERVAL は無視する。
 *
 * @param int    $start_ts     開始（Unix時刻）。
 * @param int    $end_ts       終了（Unix時刻）。
 * @param string $rrule        RRULE の値。空なら繰り返しなし。
 * @param int    $window_start 展開する期間の開始。
 * @param int    $window_end   展開する期間の終了。
 * @return array<int,array<int,int>> [開始, 終了] の配列。
 */
function ssb_gcal_expand( $start_ts, $end_ts, $rrule, $window_start, $window_end ) {
	$duration = max( 0, $end_ts - $start_ts );
	$out      = array();

	$single = function () use ( $start_ts, $end_ts, $window_start, $window_end ) {
		return ( $end_ts > $window_start && $start_ts < $window_end )
			? array( array( $start_ts, $end_ts ) )
			: array();
	};

	if ( '' === (string) $rrule ) {
		return $single();
	}

	$rules = array();

	foreach ( explode( ';', $rrule ) as $pair ) {
		$kv = explode( '=', $pair, 2 );

		if ( 2 === count( $kv ) ) {
			$rules[ strtoupper( trim( $kv[0] ) ) ] = strtoupper( trim( $kv[1] ) );
		}
	}

	$freq = isset( $rules['FREQ'] ) ? $rules['FREQ'] : '';

	if ( 'DAILY' !== $freq && 'WEEKLY' !== $freq ) {
		return $single();
	}

	$interval = isset( $rules['INTERVAL'] ) ? max( 1, (int) $rules['INTERVAL'] ) : 1;
	$count    = isset( $rules['COUNT'] ) ? (int) $rules['COUNT'] : 0;
	$until    = 0;

	if ( ! empty( $rules['UNTIL'] ) ) {
		$parsed = ssb_gcal_parse_datetime( array(), $rules['UNTIL'] );

		if ( ! $parsed ) {
			$parsed = ssb_gcal_parse_datetime( array( 'VALUE' => 'DATE' ), $rules['UNTIL'] );
		}

		$until = $parsed ? $parsed['ts'] : 0;
	}

	$byday = array();

	if ( ! empty( $rules['BYDAY'] ) ) {
		$map = array(
			'SU' => 0,
			'MO' => 1,
			'TU' => 2,
			'WE' => 3,
			'TH' => 4,
			'FR' => 5,
			'SA' => 6,
		);

		foreach ( explode( ',', $rules['BYDAY'] ) as $day ) {
			$day = preg_replace( '/[^A-Z]/', '', $day );

			if ( isset( $map[ $day ] ) ) {
				$byday[] = $map[ $day ];
			}
		}
	}

	$cursor  = ( new DateTimeImmutable( '@' . $start_ts ) )->setTimezone( wp_timezone() );
	$emitted = 0;
	$guard   = 0;

	while ( $guard < 3000 ) {
		$guard++;

		$ts = $cursor->getTimestamp();

		if ( $ts > $window_end ) {
			break;
		}

		if ( $until && $ts > $until ) {
			break;
		}

		$matches = true;

		if ( 'WEEKLY' === $freq && $byday ) {
			$matches = in_array( (int) $cursor->format( 'w' ), $byday, true );
		}

		if ( $matches ) {
			if ( $ts + $duration > $window_start ) {
				$out[] = array( $ts, $ts + $duration );
			}

			$emitted++;

			if ( $count && $emitted >= $count ) {
				break;
			}
		}

		if ( 'DAILY' === $freq ) {
			$cursor = $cursor->modify( '+' . $interval . ' days' );
		} elseif ( $byday ) {
			$cursor = $cursor->modify( '+1 day' );
		} else {
			$cursor = $cursor->modify( '+' . ( $interval * 7 ) . ' days' );
		}
	}

	return $out;
}

/**
 * ICS を解析して、予定が入っている時間帯を返す.
 *
 * キャンセル済みの予定と、空き時間として登録された予定（TRANSP:TRANSPARENT）は除く。
 *
 * @param string $ics          ICS 本文。
 * @param int    $window_start 対象期間の開始（Unix時刻）。
 * @param int    $window_end   対象期間の終了（Unix時刻）。
 * @return array<int,array<int,int>> [開始, 終了] の配列。
 */
function ssb_gcal_parse( $ics, $window_start, $window_end ) {
	$busy    = array();
	$inside  = false;
	$event   = array();

	foreach ( ssb_gcal_unfold( $ics ) as $line ) {
		$line = rtrim( $line );
		$upper = strtoupper( $line );

		if ( 'BEGIN:VEVENT' === $upper ) {
			$inside = true;
			$event  = array();
			continue;
		}

		if ( 'END:VEVENT' === $upper ) {
			$inside = false;

			$skip = ( isset( $event['status'] ) && 'CANCELLED' === $event['status'] )
				|| ( isset( $event['transp'] ) && 'TRANSPARENT' === $event['transp'] );

			if ( ! $skip && ! empty( $event['start'] ) ) {
				$start = $event['start'];
				$end   = ! empty( $event['end'] ) ? $event['end'] : null;

				if ( ! $end ) {
					// DTEND が無い終日予定はその日いっぱいとして扱う。
					$end = array( 'ts' => $start['ts'] + ( $start['all_day'] ? DAY_IN_SECONDS : 0 ) );
				}

				$busy = array_merge(
					$busy,
					ssb_gcal_expand( $start['ts'], $end['ts'], isset( $event['rrule'] ) ? $event['rrule'] : '', $window_start, $window_end )
				);
			}

			if ( count( $busy ) > 3000 ) {
				break;
			}

			continue;
		}

		if ( ! $inside ) {
			continue;
		}

		$parsed = ssb_gcal_parse_line( $line );

		if ( ! $parsed ) {
			continue;
		}

		switch ( $parsed['name'] ) {
			case 'DTSTART':
				$event['start'] = ssb_gcal_parse_datetime( $parsed['params'], $parsed['value'] );
				break;
			case 'DTEND':
				$event['end'] = ssb_gcal_parse_datetime( $parsed['params'], $parsed['value'] );
				break;
			case 'RRULE':
				$event['rrule'] = trim( $parsed['value'] );
				break;
			case 'STATUS':
				$event['status'] = strtoupper( trim( $parsed['value'] ) );
				break;
			case 'TRANSP':
				$event['transp'] = strtoupper( trim( $parsed['value'] ) );
				break;
		}
	}

	return $busy;
}

/* -------------------------------------------------------------------------
 * 取得とキャッシュ
 * ---------------------------------------------------------------------- */

/**
 * ICS を取得する.
 *
 * URL は第三者に知られると予定が見えてしまうため、ログには出さない（SPEC 4.4）。
 *
 * @param string $url ICS URL。
 * @return string|WP_Error ICS 本文。
 */
function ssb_gcal_fetch( $url ) {
	if ( ! ssb_is_valid_gcal_url( $url ) ) {
		return new WP_Error( 'ssb_gcal_bad_url', 'ICS URL の形式が正しくありません。' );
	}

	$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'ssb_gcal_unreachable', 'カレンダーに接続できませんでした。' );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'ssb_gcal_http_' . $code, 'カレンダーの取得に失敗しました（HTTP ' . $code . '）。' );
	}

	$body = (string) wp_remote_retrieve_body( $response );

	if ( ! str_contains( $body, 'BEGIN:VCALENDAR' ) ) {
		return new WP_Error( 'ssb_gcal_not_ics', '取得した内容がカレンダー形式ではありません。' );
	}

	return $body;
}

/**
 * キャッシュがまだ使えるかを返す.
 *
 * @param string|null $fetched_at 最終取得日時（サイトのタイムゾーン）。
 * @return bool
 */
function ssb_gcal_cache_is_fresh( $fetched_at ) {
	if ( empty( $fetched_at ) ) {
		return false;
	}

	$fetched = new DateTimeImmutable( (string) $fetched_at, wp_timezone() );

	return ( time() - $fetched->getTimestamp() ) < SSB_GCAL_CACHE_TTL;
}

/**
 * 解析結果をキャッシュに保存する.
 *
 * @param int                       $instructor_id 講師ID。
 * @param array<int,array<int,int>> $busy          予定の時間帯。
 * @return void
 */
function ssb_save_gcal_cache( $instructor_id, $busy ) {
	global $wpdb;

	$wpdb->update(
		ssb_table( 'instructors' ),
		array(
			'gcal_cache'      => wp_json_encode( $busy ),
			'gcal_fetched_at' => current_time( 'mysql' ),
		),
		array( 'id' => (int) $instructor_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);
}

/**
 * ICS URL を登録する.
 *
 * URL を変えたらキャッシュは捨てる。
 *
 * @param int    $instructor_id 講師ID。
 * @param string $url           ICS URL。
 * @return bool
 */
function ssb_set_gcal_url( $instructor_id, $url ) {
	global $wpdb;

	$result = $wpdb->update(
		ssb_table( 'instructors' ),
		array(
			'gcal_ics_url'    => $url,
			'gcal_cache'      => null,
			'gcal_fetched_at' => null,
		),
		array( 'id' => (int) $instructor_id ),
		array( '%s', '%s', '%s' ),
		array( '%d' )
	);

	return false !== $result;
}

/**
 * ICS 連携を解除する.
 *
 * @param int $instructor_id 講師ID。
 * @return bool
 */
function ssb_clear_gcal( $instructor_id ) {
	return ssb_set_gcal_url( $instructor_id, '' );
}

/**
 * 講師の「予定が入っている時間帯」を返す.
 *
 * キャッシュが1時間以内なら再取得しない。取得に失敗した場合は前回のキャッシュを使い、
 * それも無ければ空を返す。連携の失敗で予約を止めない（SPEC 4.4）。
 *
 * @param object|null $instructor 講師レコード。
 * @return array<int,array<int,int>> [開始, 終了] の配列。
 */
function ssb_gcal_busy_for_instructor( $instructor ) {
	if ( ! $instructor || '' === (string) $instructor->gcal_ics_url ) {
		return array();
	}

	$cached = json_decode( (string) $instructor->gcal_cache, true );
	$cached = is_array( $cached ) ? $cached : null;

	if ( null !== $cached && ssb_gcal_cache_is_fresh( $instructor->gcal_fetched_at ) ) {
		return $cached;
	}

	$ics = ssb_gcal_fetch( $instructor->gcal_ics_url );

	if ( is_wp_error( $ics ) ) {
		// URL は記録しない。
		ssb_log(
			'Googleカレンダーの取得に失敗',
			array(
				'instructor_id' => (int) $instructor->id,
				'code'          => $ics->get_error_code(),
			)
		);

		return null !== $cached ? $cached : array();
	}

	$busy = ssb_gcal_parse( $ics, time(), time() + SSB_GCAL_WINDOW_DAYS * DAY_IN_SECONDS );

	ssb_save_gcal_cache( (int) $instructor->id, $busy );

	return $busy;
}

/**
 * 講座の担当講師の予定を返す.
 *
 * @param int $course_id 講座ID。
 * @return array<int,array<int,int>>
 */
function ssb_gcal_busy_for_course( $course_id ) {
	$course = ssb_get_course( $course_id );

	if ( ! $course ) {
		return array();
	}

	return ssb_gcal_busy_for_instructor( ssb_get_instructor( (int) $course->instructor_id ) );
}

/**
 * 指定の時間帯が予定と重なるかを返す.
 *
 * @param int                       $start_ts 開始（Unix時刻）。
 * @param int                       $end_ts   終了（Unix時刻）。
 * @param array<int,array<int,int>> $busy     予定の時間帯。
 * @return bool
 */
function ssb_overlaps_busy( $start_ts, $end_ts, $busy ) {
	foreach ( $busy as $range ) {
		if ( ! isset( $range[0], $range[1] ) ) {
			continue;
		}

		if ( $start_ts < (int) $range[1] && (int) $range[0] < $end_ts ) {
			return true;
		}
	}

	return false;
}

/* -------------------------------------------------------------------------
 * マイページからの操作
 * ---------------------------------------------------------------------- */

/**
 * 現在キャッシュしている予定の件数を返す.
 *
 * @param object|null $instructor 講師レコード。
 * @return int
 */
function ssb_gcal_cached_count( $instructor ) {
	if ( ! $instructor ) {
		return 0;
	}

	$cached = json_decode( (string) $instructor->gcal_cache, true );

	return is_array( $cached ) ? count( $cached ) : 0;
}

/**
 * ICS URL の登録の受け口.
 *
 * 保存前に実際に取得できるか確かめる。入力値は控えに残さない（URL を再表示しないため）。
 *
 * @return void
 */
function ssb_handle_save_gcal() {
	check_admin_referer( 'ssb_save_gcal', 'ssb_gcal_nonce' );

	$instructor = ssb_current_instructor();

	if ( ! $instructor ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	$back = array( 'tab' => 'gcal' );
	$url  = isset( $_POST['gcal_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['gcal_url'] ) ) ) : '';

	if ( '' === $url ) {
		ssb_mypage_fail( array( 'ICS URL を入力してください。' ), array(), $back );
	}

	if ( ! ssb_is_valid_gcal_url( $url ) ) {
		ssb_mypage_fail(
			array( 'Googleカレンダーの「非公開URL（iCal形式）」を貼り付けてください。https://calendar.google.com/ で始まるものだけ登録できます。' ),
			array(),
			$back
		);
	}

	$ics = ssb_gcal_fetch( $url );

	if ( is_wp_error( $ics ) ) {
		ssb_mypage_fail( array( 'カレンダーを読み取れませんでした：' . $ics->get_error_message() ), array(), $back );
	}

	ssb_set_gcal_url( (int) $instructor->id, $url );

	$busy = ssb_gcal_parse( $ics, time(), time() + SSB_GCAL_WINDOW_DAYS * DAY_IN_SECONDS );
	ssb_save_gcal_cache( (int) $instructor->id, $busy );

	ssb_mypage_done( 'gcal_saved', array_merge( $back, array( 'events' => (string) count( $busy ) ) ) );
}
add_action( 'admin_post_ssb_save_gcal', 'ssb_handle_save_gcal' );

/**
 * ICS を取り直す受け口.
 *
 * @return void
 */
function ssb_handle_refresh_gcal() {
	check_admin_referer( 'ssb_refresh_gcal', 'ssb_gcal_refresh_nonce' );

	$instructor = ssb_current_instructor();

	if ( ! $instructor ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	$back = array( 'tab' => 'gcal' );

	if ( '' === (string) $instructor->gcal_ics_url ) {
		ssb_mypage_done( 'gcal_missing', $back );
	}

	$ics = ssb_gcal_fetch( $instructor->gcal_ics_url );

	if ( is_wp_error( $ics ) ) {
		ssb_mypage_fail( array( 'カレンダーを読み取れませんでした：' . $ics->get_error_message() ), array(), $back );
	}

	$busy = ssb_gcal_parse( $ics, time(), time() + SSB_GCAL_WINDOW_DAYS * DAY_IN_SECONDS );
	ssb_save_gcal_cache( (int) $instructor->id, $busy );

	ssb_mypage_done( 'gcal_saved', array_merge( $back, array( 'events' => (string) count( $busy ) ) ) );
}
add_action( 'admin_post_ssb_refresh_gcal', 'ssb_handle_refresh_gcal' );

/**
 * ICS 連携の解除の受け口.
 *
 * @return void
 */
function ssb_handle_clear_gcal() {
	check_admin_referer( 'ssb_clear_gcal', 'ssb_gcal_clear_nonce' );

	$instructor = ssb_current_instructor();

	if ( ! $instructor ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	ssb_clear_gcal( (int) $instructor->id );

	ssb_mypage_done( 'gcal_cleared', array( 'tab' => 'gcal' ) );
}
add_action( 'admin_post_ssb_clear_gcal', 'ssb_handle_clear_gcal' );
