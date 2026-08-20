<?php
/**
 * 予約.
 *
 * 予約は Checkout セッションを作る直前に pending で1件作り、
 * 決済の確定は必ず Webhook 側で行う（SPEC 4.6）。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * ステータス
 * ---------------------------------------------------------------------- */

/**
 * 予約ステータスの一覧を返す.
 *
 * @return array<string,string> ステータス => 表示名。
 */
function ssb_booking_statuses() {
	return array(
		'pending'   => '未決済',
		'paid'      => '確定',
		'cancelled' => 'キャンセル',
	);
}

/**
 * 予約ステータスの表示名を返す.
 *
 * @param string $status ステータス。
 * @return string
 */
function ssb_booking_status_label( $status ) {
	$statuses = ssb_booking_statuses();

	return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
}

/* -------------------------------------------------------------------------
 * データアクセス
 * ---------------------------------------------------------------------- */

/**
 * ID から予約を取得する.
 *
 * @param int $id 予約ID。
 * @return object|null
 */
function ssb_get_booking( $id ) {
	global $wpdb;

	$table = ssb_table( 'bookings' );

	return $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", (int) $id )
	);
}

/**
 * Stripe のセッションIDから予約を取得する.
 *
 * @param string $session_id Checkout セッションID。
 * @return object|null
 */
function ssb_get_booking_by_session( $session_id ) {
	global $wpdb;

	if ( '' === (string) $session_id ) {
		return null;
	}

	$table = ssb_table( 'bookings' );

	return $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM `{$table}` WHERE stripe_session_id = %s LIMIT 1", $session_id )
	);
}

/**
 * 予約を pending で作成する.
 *
 * stripe_session_id はここでは入れない。UNIQUE インデックスがあるため
 * 空文字を入れると2件目の pending 作成で必ず失敗する（NULL の重複は許される）。
 *
 * @param array<string,mixed> $data slot_id / name / email / note / amount。
 * @return int 作成された予約ID。失敗時は 0。
 */
function ssb_insert_booking( $data ) {
	global $wpdb;

	$ok = $wpdb->insert(
		ssb_table( 'bookings' ),
		array(
			'slot_id'    => (int) $data['slot_id'],
			'name'       => $data['name'],
			'email'      => $data['email'],
			'note'       => $data['note'],
			'amount'     => (int) $data['amount'],
			'status'     => 'pending',
			'created_at' => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * 予約に Checkout セッションIDを記録する.
 *
 * @param int    $id         予約ID。
 * @param string $session_id セッションID。
 * @return bool
 */
function ssb_set_booking_session( $id, $session_id ) {
	global $wpdb;

	$result = $wpdb->update(
		ssb_table( 'bookings' ),
		array( 'stripe_session_id' => $session_id ),
		array( 'id' => (int) $id ),
		array( '%s' ),
		array( '%d' )
	);

	return false !== $result;
}

/**
 * 予約を確定（決済済み）にする.
 *
 * すでに paid の行は更新しないので、Webhook が二重に届いても影響がない。
 *
 * @param int    $id             予約ID。
 * @param string $payment_intent PaymentIntent ID。
 * @return bool 実際に確定へ変わったなら true。
 */
function ssb_mark_booking_paid( $id, $payment_intent ) {
	global $wpdb;

	$table = ssb_table( 'bookings' );

	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE `{$table}`
			SET status = 'paid', paid_at = %s, stripe_payment_intent = %s
			WHERE id = %d AND status <> 'paid'",
			current_time( 'mysql' ),
			$payment_intent,
			(int) $id
		)
	);

	return (bool) $updated;
}

/**
 * 予約をキャンセル扱いにする.
 *
 * @param int $id 予約ID。
 * @return bool
 */
function ssb_cancel_booking( $id ) {
	global $wpdb;

	$table = ssb_table( 'bookings' );

	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE `{$table}` SET status = 'cancelled' WHERE id = %d AND status = 'pending'",
			(int) $id
		)
	);

	return (bool) $updated;
}

/**
 * 予約に紐づく枠・講座・講師をまとめて取得する.
 *
 * メール送信や画面表示で毎回 JOIN を書かないためのヘルパー。
 *
 * @param int $booking_id 予約ID。
 * @return object|null
 */
function ssb_get_booking_context( $booking_id ) {
	global $wpdb;

	$bookings    = ssb_table( 'bookings' );
	$slots       = ssb_table( 'slots' );
	$courses     = ssb_table( 'courses' );
	$instructors = ssb_table( 'instructors' );

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT
				b.*,
				s.start_at, s.end_at, s.course_id, s.hold_token,
				c.title AS course_title, c.duration_min, c.price,
				i.id AS instructor_id, i.display_name AS instructor_name, i.email AS instructor_email
			FROM `{$bookings}` b
			INNER JOIN `{$slots}` s ON s.id = b.slot_id
			INNER JOIN `{$courses}` c ON c.id = s.course_id
			INNER JOIN `{$instructors}` i ON i.id = c.instructor_id
			WHERE b.id = %d
			LIMIT 1",
			(int) $booking_id
		)
	);
}

/* -------------------------------------------------------------------------
 * 管理画面向けの検索と集計
 * ---------------------------------------------------------------------- */

/**
 * 条件を指定して予約を検索する.
 *
 * 値はすべて prepare() で束縛する。テーブル名は ssb_table() のホワイトリスト由来。
 *
 * @param array<string,mixed> $args status / instructor_id / course_id / month / min_amount / max_amount。
 * @return object[] 講座・講師の情報を含む行。
 */
function ssb_query_bookings( $args = array() ) {
	global $wpdb;

	$args = array_merge(
		array(
			'status'        => '',
			'instructor_id' => 0,
			'course_id'     => 0,
			'month'         => '',
			'min_amount'    => '',
			'max_amount'    => '',
		),
		$args
	);

	$bookings    = ssb_table( 'bookings' );
	$slots       = ssb_table( 'slots' );
	$courses     = ssb_table( 'courses' );
	$instructors = ssb_table( 'instructors' );

	$where  = array();
	$params = array();

	if ( '' !== $args['status'] ) {
		$where[]  = 'b.status = %s';
		$params[] = $args['status'];
	}

	if ( $args['instructor_id'] ) {
		$where[]  = 'i.id = %d';
		$params[] = (int) $args['instructor_id'];
	}

	if ( $args['course_id'] ) {
		$where[]  = 'c.id = %d';
		$params[] = (int) $args['course_id'];
	}

	if ( '' !== $args['month'] ) {
		$range = ssb_month_range( $args['month'] );

		if ( $range ) {
			$where[]  = 'b.created_at >= %s';
			$params[] = $range[0];
			$where[]  = 'b.created_at < %s';
			$params[] = $range[1];
		}
	}

	if ( '' !== $args['min_amount'] ) {
		$where[]  = 'b.amount >= %d';
		$params[] = (int) $args['min_amount'];
	}

	if ( '' !== $args['max_amount'] ) {
		$where[]  = 'b.amount <= %d';
		$params[] = (int) $args['max_amount'];
	}

	$clause = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

	$sql = "SELECT
			b.*,
			s.start_at, s.end_at, s.course_id,
			c.title AS course_title, c.duration_min,
			i.id AS instructor_id, i.display_name AS instructor_name, i.email AS instructor_email
		FROM `{$bookings}` b
		INNER JOIN `{$slots}` s ON s.id = b.slot_id
		INNER JOIN `{$courses}` c ON c.id = s.course_id
		INNER JOIN `{$instructors}` i ON i.id = c.instructor_id
		{$clause}
		ORDER BY b.created_at DESC, b.id DESC";

	if ( ! $params ) {
		return $wpdb->get_results( $sql );
	}

	return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
}

/**
 * 「YYYY-MM」から、その月の開始と翌月の開始を返す.
 *
 * @param string $month 例: 2026-08。
 * @return array<int,string>|null [開始, 翌月開始]。不正なら null。
 */
function ssb_month_range( $month ) {
	if ( ! preg_match( '/^(\d{4})-(\d{2})$/', (string) $month, $m ) ) {
		return null;
	}

	if ( (int) $m[2] < 1 || (int) $m[2] > 12 ) {
		return null;
	}

	$start = new DateTimeImmutable( $month . '-01 00:00:00', wp_timezone() );

	return array(
		$start->format( 'Y-m-d H:i:s' ),
		$start->modify( '+1 month' )->format( 'Y-m-d H:i:s' ),
	);
}

/**
 * 講師別・月別の売上を集計する.
 *
 * 振込作業のための表なので、対象は決済済み（paid）のみ。決済日で月を分ける。
 *
 * @param int $instructor_id 講師で絞る場合はID。0 なら全講師。
 * @return array<int,array<string,mixed>> month / instructor_id / instructor_name / count / total。
 */
function ssb_booking_monthly_summary( $instructor_id = 0 ) {
	$rows    = ssb_query_bookings(
		array(
			'status'        => 'paid',
			'instructor_id' => $instructor_id,
		)
	);
	$grouped = array();

	foreach ( $rows as $row ) {
		if ( empty( $row->paid_at ) ) {
			continue;
		}

		$month = substr( (string) $row->paid_at, 0, 7 );
		$key   = $month . '#' . (int) $row->instructor_id;

		if ( ! isset( $grouped[ $key ] ) ) {
			$grouped[ $key ] = array(
				'month'           => $month,
				'instructor_id'   => (int) $row->instructor_id,
				'instructor_name' => $row->instructor_name,
				'count'           => 0,
				'total'           => 0,
			);
		}

		$grouped[ $key ]['count']++;
		$grouped[ $key ]['total'] += (int) $row->amount;
	}

	// 新しい月から、同じ月の中では金額の大きい講師から。
	uasort(
		$grouped,
		static function ( $a, $b ) {
			if ( $a['month'] === $b['month'] ) {
				return $b['total'] <=> $a['total'];
			}

			return strcmp( $b['month'], $a['month'] );
		}
	);

	return array_values( $grouped );
}
