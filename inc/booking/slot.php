<?php
/**
 * 予約枠・仮押さえ.
 *
 * 枠は講座に紐づき、長さは講座の duration_min をそのまま使う。
 * 日時はサイトのタイムゾーンの壁時計をそのまま保存する（UTC 変換はしない）。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

/**
 * 一度の一括生成で作れる枠の上限.
 *
 * 入力ミスで大量の行が入るのを防ぐための安全弁。
 */
define( 'SSB_MAX_SLOTS_PER_RUN', 500 );

/**
 * 一括生成で指定できる期間の上限（日）.
 */
define( 'SSB_MAX_SLOT_RANGE_DAYS', 186 );

/* -------------------------------------------------------------------------
 * ステータス
 * ---------------------------------------------------------------------- */

/**
 * 枠ステータスの一覧を返す.
 *
 * @return array<string,string> ステータス => 表示名。
 */
function ssb_slot_statuses() {
	return array(
		'open'   => '空き',
		'held'   => '仮押さえ中',
		'booked' => '予約済み',
		'closed' => '停止中',
	);
}

/**
 * 枠ステータスの表示名を返す.
 *
 * @param string $status ステータス。
 * @return string
 */
function ssb_slot_status_label( $status ) {
	$statuses = ssb_slot_statuses();

	return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
}

/* -------------------------------------------------------------------------
 * 仮押さえの解放
 * ---------------------------------------------------------------------- */

/**
 * 期限切れの仮押さえを解放する.
 *
 * cron は使わず、枠を読む直前に必ず呼ぶ（SPEC 4.5）。
 *
 * @return int 解放した件数。
 */
function ssb_release_expired_holds() {
	global $wpdb;

	$table = ssb_table( 'slots' );

	$result = $wpdb->query(
		$wpdb->prepare(
			"UPDATE `{$table}`
			SET status = 'open', hold_token = NULL, hold_expires_at = NULL
			WHERE status = 'held' AND hold_expires_at IS NOT NULL AND hold_expires_at < %s",
			current_time( 'mysql' )
		)
	);

	return (int) $result;
}

/* -------------------------------------------------------------------------
 * データアクセス
 * ---------------------------------------------------------------------- */

/**
 * ID から枠を取得する.
 *
 * @param int $id 枠ID。
 * @return object|null
 */
function ssb_get_slot( $id ) {
	global $wpdb;

	$table = ssb_table( 'slots' );

	return $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", (int) $id )
	);
}

/**
 * 講座の枠を取得する.
 *
 * 読む前に必ず期限切れの仮押さえを解放する。
 *
 * @param int    $course_id 講座ID。
 * @param string $from      この日時以降のみ（Y-m-d H:i:s）。空なら全期間。
 * @return object[]
 */
function ssb_get_slots_by_course( $course_id, $from = '' ) {
	global $wpdb;

	ssb_release_expired_holds();

	$table = ssb_table( 'slots' );

	if ( '' !== $from ) {
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE course_id = %d AND start_at >= %s ORDER BY start_at ASC",
				(int) $course_id,
				$from
			)
		);
	}

	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE course_id = %d ORDER BY start_at ASC",
			(int) $course_id
		)
	);
}

/**
 * 講師が持つすべての講座の枠を取得する.
 *
 * @param int    $instructor_id 講師ID。
 * @param string $from          この日時以降のみ。
 * @return object[] course_title を含む。
 */
function ssb_get_slots_by_instructor( $instructor_id, $from = '' ) {
	global $wpdb;

	ssb_release_expired_holds();

	$slots   = ssb_table( 'slots' );
	$courses = ssb_table( 'courses' );

	$where = '';
	$args  = array( (int) $instructor_id );

	if ( '' !== $from ) {
		$where  = ' AND s.start_at >= %s';
		$args[] = $from;
	}

	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT s.*, c.title AS course_title, c.duration_min
			FROM `{$slots}` s
			INNER JOIN `{$courses}` c ON c.id = s.course_id
			WHERE c.instructor_id = %d{$where}
			ORDER BY s.start_at ASC",
			$args
		)
	);
}

/**
 * 同じ講座・同じ開始日時の枠が既にあるかを返す.
 *
 * @param int    $course_id 講座ID。
 * @param string $start_at  開始日時（Y-m-d H:i:s）。
 * @return bool
 */
function ssb_slot_exists( $course_id, $start_at ) {
	global $wpdb;

	$table = ssb_table( 'slots' );

	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM `{$table}` WHERE course_id = %d AND start_at = %s LIMIT 1",
			(int) $course_id,
			$start_at
		)
	);
}

/**
 * 枠を1件作る.
 *
 * @param int    $course_id 講座ID。
 * @param string $start_at  開始日時。
 * @param string $end_at    終了日時。
 * @return int 作成された枠ID。失敗時は 0。
 */
function ssb_insert_slot( $course_id, $start_at, $end_at ) {
	global $wpdb;

	$ok = $wpdb->insert(
		ssb_table( 'slots' ),
		array(
			'course_id' => (int) $course_id,
			'start_at'  => $start_at,
			'end_at'    => $end_at,
			'status'    => 'open',
		),
		array( '%d', '%s', '%s', '%s' )
	);

	return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * 枠を削除する.
 *
 * 予約済み・仮押さえ中の枠は消させない。
 *
 * @param int $id 枠ID。
 * @return true|WP_Error
 */
function ssb_delete_slot( $id ) {
	global $wpdb;

	$slot = ssb_get_slot( $id );

	if ( ! $slot ) {
		return new WP_Error( 'ssb_slot_not_found', '対象の枠が見つかりません。' );
	}

	if ( ! in_array( $slot->status, array( 'open', 'closed' ), true ) ) {
		return new WP_Error( 'ssb_slot_locked', '予約済み・仮押さえ中の枠は削除できません。' );
	}

	$deleted = $wpdb->delete( ssb_table( 'slots' ), array( 'id' => (int) $id ), array( '%d' ) );

	if ( ! $deleted ) {
		return new WP_Error( 'ssb_slot_delete_failed', '枠の削除に失敗しました。' );
	}

	return true;
}

/* -------------------------------------------------------------------------
 * 入力のパース
 * ---------------------------------------------------------------------- */

/**
 * 日付（Y-m-d）として妥当なら返す.
 *
 * @param string $raw 入力値。
 * @return string 妥当でなければ空文字。
 */
function ssb_parse_date( $raw ) {
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) ) {
		return '';
	}

	if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return '';
	}

	return $raw;
}

/**
 * 時刻（H:i）として妥当なら返す.
 *
 * @param string $raw 入力値。
 * @return string 妥当でなければ空文字。
 */
function ssb_parse_time( $raw ) {
	if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $raw, $m ) ) {
		return '';
	}

	if ( (int) $m[1] > 23 || (int) $m[2] > 59 ) {
		return '';
	}

	return $m[1] . ':' . $m[2];
}

/**
 * 曜日の一覧を返す.
 *
 * キーは date('w') と同じで 0 が日曜。
 *
 * @return array<int,string>
 */
function ssb_weekdays() {
	return array( 0 => '日', 1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土' );
}

/* -------------------------------------------------------------------------
 * 一括生成
 * ---------------------------------------------------------------------- */

/**
 * 予約枠を一括生成する.
 *
 * 1コマの長さは講座の duration_min。終了時刻をはみ出すコマは作らない。
 * 過去の枠と、同じ講座で同じ開始時刻の枠は作らずに読み飛ばす。
 *
 * @param object              $course 講座レコード。
 * @param array<string,mixed> $args   start_date / end_date / start_time / end_time / weekdays。
 * @return array<string,int>|WP_Error created / skipped。
 */
function ssb_generate_slots( $course, $args ) {
	$duration = (int) $course->duration_min;

	if ( $duration < 1 ) {
		return new WP_Error( 'ssb_bad_duration', '講座の所要時間が設定されていません。' );
	}

	$tz  = wp_timezone();
	$now = new DateTimeImmutable( current_time( 'mysql' ), $tz );

	$cursor = new DateTimeImmutable( $args['start_date'] . ' 00:00:00', $tz );
	$last   = new DateTimeImmutable( $args['end_date'] . ' 00:00:00', $tz );

	$created = 0;
	$skipped = 0;

	while ( $cursor <= $last ) {
		if ( in_array( (int) $cursor->format( 'w' ), $args['weekdays'], true ) ) {
			$day        = $cursor->format( 'Y-m-d' );
			$slot_start = new DateTimeImmutable( $day . ' ' . $args['start_time'] . ':00', $tz );
			$day_end    = new DateTimeImmutable( $day . ' ' . $args['end_time'] . ':00', $tz );

			while ( true ) {
				$slot_end = $slot_start->modify( '+' . $duration . ' minutes' );

				// 終了時刻をはみ出すコマは作らない。
				if ( $slot_end > $day_end ) {
					break;
				}

				if ( $created >= SSB_MAX_SLOTS_PER_RUN ) {
					return new WP_Error(
						'ssb_too_many_slots',
						sprintf(
							'一度に作れる枠は %1$d 件までです。期間を短く区切ってお試しください。（%2$d 件作成済み）',
							SSB_MAX_SLOTS_PER_RUN,
							$created
						)
					);
				}

				$start_str = $slot_start->format( 'Y-m-d H:i:s' );

				if ( $slot_start <= $now || ssb_slot_exists( $course->id, $start_str ) ) {
					$skipped++;
				} elseif ( ssb_insert_slot( $course->id, $start_str, $slot_end->format( 'Y-m-d H:i:s' ) ) ) {
					$created++;
				} else {
					$skipped++;
				}

				$slot_start = $slot_end;
			}
		}

		$cursor = $cursor->modify( '+1 day' );
	}

	return array(
		'created' => $created,
		'skipped' => $skipped,
	);
}

/* -------------------------------------------------------------------------
 * マイページからの操作
 * ---------------------------------------------------------------------- */

/**
 * 予約枠の一括生成の受け口.
 *
 * @return void
 */
function ssb_handle_generate_slots() {
	check_admin_referer( 'ssb_generate_slots', 'ssb_slots_nonce' );

	$instructor = ssb_current_instructor();

	if ( ! $instructor ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	$back      = array( 'tab' => 'slots' );
	$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
	$course    = $course_id ? ssb_get_own_course( $course_id, $instructor->id ) : null;

	if ( ! $course ) {
		ssb_mypage_done( 'course_forbidden', $back );
	}

	$raw_weekdays = isset( $_POST['weekdays'] ) ? (array) wp_unslash( $_POST['weekdays'] ) : array();
	$weekdays     = array();

	foreach ( $raw_weekdays as $value ) {
		$day = (int) $value;

		if ( $day >= 0 && $day <= 6 ) {
			$weekdays[] = $day;
		}
	}

	$input = array(
		'course_id'  => (string) $course_id,
		'start_date' => isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '',
		'end_date'   => isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '',
		'start_time' => isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '',
		'end_time'   => isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '',
		'weekdays'   => $weekdays,
	);

	$errors     = array();
	$start_date = ssb_parse_date( $input['start_date'] );
	$end_date   = ssb_parse_date( $input['end_date'] );
	$start_time = ssb_parse_time( $input['start_time'] );
	$end_time   = ssb_parse_time( $input['end_time'] );

	if ( '' === $start_date || '' === $end_date ) {
		$errors[] = '開始日と終了日を正しく入力してください。';
	} elseif ( $start_date > $end_date ) {
		$errors[] = '終了日は開始日以降の日付を指定してください。';
	} elseif ( ( ( strtotime( $end_date ) - strtotime( $start_date ) ) / DAY_IN_SECONDS ) + 1 > SSB_MAX_SLOT_RANGE_DAYS ) {
		$errors[] = sprintf( '期間は %d 日以内で指定してください。', SSB_MAX_SLOT_RANGE_DAYS );
	}

	if ( '' === $start_time || '' === $end_time ) {
		$errors[] = '開始時刻と終了時刻を正しく入力してください。';
	} elseif ( $start_time >= $end_time ) {
		$errors[] = '終了時刻は開始時刻より後の時刻を指定してください。';
	}

	if ( ! $weekdays ) {
		$errors[] = '曜日を1つ以上選んでください。';
	}

	if ( $errors ) {
		ssb_mypage_fail( $errors, $input, $back );
	}

	$result = ssb_generate_slots(
		$course,
		array(
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'start_time' => $start_time,
			'end_time'   => $end_time,
			'weekdays'   => $weekdays,
		)
	);

	if ( is_wp_error( $result ) ) {
		ssb_mypage_fail( array( $result->get_error_message() ), $input, $back );
	}

	ssb_mypage_done(
		'slots_generated',
		array(
			'tab'     => 'slots',
			'created' => (string) $result['created'],
			'skipped' => (string) $result['skipped'],
		)
	);
}
add_action( 'admin_post_ssb_generate_slots', 'ssb_handle_generate_slots' );

/**
 * 予約枠の削除の受け口.
 *
 * @return void
 */
function ssb_handle_delete_slot() {
	check_admin_referer( 'ssb_delete_slot', 'ssb_slot_nonce' );

	$instructor = ssb_current_instructor();

	if ( ! $instructor ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	$back    = array( 'tab' => 'slots' );
	$slot_id = isset( $_POST['slot_id'] ) ? absint( wp_unslash( $_POST['slot_id'] ) ) : 0;
	$slot    = $slot_id ? ssb_get_slot( $slot_id ) : null;

	// 自分の講座の枠かどうかを必ず確認する。
	if ( ! $slot || ! ssb_get_own_course( (int) $slot->course_id, $instructor->id ) ) {
		ssb_mypage_done( 'slot_forbidden', $back );
	}

	$result = ssb_delete_slot( $slot_id );

	if ( is_wp_error( $result ) ) {
		ssb_mypage_done( $result->get_error_code(), $back );
	}

	ssb_mypage_done( 'slot_deleted', $back );
}
add_action( 'admin_post_ssb_delete_slot', 'ssb_handle_delete_slot' );

/* -------------------------------------------------------------------------
 * 受講者に見せる空き枠
 * ---------------------------------------------------------------------- */

/**
 * 予約可能な枠を返す.
 *
 * SPEC 4.3 の引き算のうち、この段階では次まで。
 *   講師が登録した枠（open） － 仮押さえ中で期限内のもの
 * ssb_release_expired_holds() を先に呼ぶので、期限切れの held は open に戻ってから
 * 抽出される。Googleカレンダーによる除外は実装順序 11 で加わる。
 *
 * @param int $course_id 講座ID。
 * @return object[]
 */
function ssb_get_available_slots( $course_id ) {
	global $wpdb;

	ssb_release_expired_holds();

	$table = ssb_table( 'slots' );

	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, start_at, end_at FROM `{$table}`
			WHERE course_id = %d AND status = 'open' AND start_at > %s
			ORDER BY start_at ASC",
			(int) $course_id,
			current_time( 'mysql' )
		)
	);
}

/**
 * カレンダー用に整形した空き枠を返す.
 *
 * DATETIME はローカルの壁時計なので、日付と時刻はそのまま切り出す。
 *
 * @param int $course_id 講座ID。
 * @return array<int,array<string,mixed>>
 */
function ssb_get_calendar_slots( $course_id ) {
	$out = array();

	foreach ( ssb_get_available_slots( $course_id ) as $slot ) {
		$out[] = array(
			'id'    => (int) $slot->id,
			'date'  => substr( (string) $slot->start_at, 0, 10 ),
			'start' => substr( (string) $slot->start_at, 11, 5 ),
			'end'   => substr( (string) $slot->end_at, 11, 5 ),
		);
	}

	return $out;
}

/**
 * 講座詳細ページで予約カレンダーを読み込む.
 *
 * @return void
 */
function ssb_enqueue_calendar() {
	$course_id = (int) get_query_var( 'ssb_course_id' );

	if ( ! $course_id || ! ssb_get_published_course( $course_id ) ) {
		return;
	}

	wp_enqueue_script( 'skillshare-calendar', SSB_URL . '/assets/js/calendar.js', array(), SSB_VERSION, true );

	wp_add_inline_script(
		'skillshare-calendar',
		'window.ssbCalendarData = ' . wp_json_encode(
			array(
				'courseId' => $course_id,
				'today'    => current_time( 'Y-m-d' ),
				'slots'    => ssb_get_calendar_slots( $course_id ),
			)
		) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'ssb_enqueue_calendar' );
