<?php
/**
 * Date/time helpers. Attendance is stored as company-local wall-clock values:
 * dates as "YYYY-MM-DD" and times as "HH:MM", in the configured timezone.
 */

if ( ! defined( 'LOOMA_ATT_CORE' ) ) {
	exit;
}

class Looma_Time {

	public static function is_time( $v ) {
		return is_string( $v ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v );
	}

	public static function is_date( $v ) {
		if ( ! is_string( $v ) || ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $v, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	public static function is_month( $v ) {
		return is_string( $v ) && preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $v );
	}

	public static function to_minutes( $hhmm ) {
		$p = explode( ':', $hhmm );
		return (int) $p[0] * 60 + (int) $p[1];
	}

	public static function days_in_month( $year, $month ) {
		return (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
	}

	public static function month_dates( $month ) {
		list( $y, $m ) = array_map( 'intval', explode( '-', $month ) );
		$out = array();
		$n   = self::days_in_month( $y, $m );
		for ( $d = 1; $d <= $n; $d++ ) {
			$out[] = sprintf( '%s-%02d', $month, $d );
		}
		return $out;
	}

	/** 0 = Sunday … 6 = Saturday */
	public static function weekday( $date ) {
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $date ) );
		return (int) gmdate( 'w', gmmktime( 0, 0, 0, $m, $d, $y ) );
	}

	public static function add_days( $date, $n ) {
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $date ) );
		return gmdate( 'Y-m-d', gmmktime( 0, 0, 0, $m, $d + $n, $y ) );
	}

	public static function is_valid_timezone( $tz ) {
		if ( ! is_string( $tz ) || '' === $tz ) {
			return false;
		}
		try {
			new DateTimeZone( $tz );
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/** Current date and time in the company timezone. */
	public static function now_parts( $tz ) {
		try {
			$zone = new DateTimeZone( $tz );
		} catch ( Exception $e ) {
			$zone = new DateTimeZone( 'UTC' );
		}
		$now = new DateTime( 'now', $zone );
		return array(
			'date' => $now->format( 'Y-m-d' ),
			'time' => $now->format( 'H:i' ),
		);
	}

	/** ISO 8601 UTC timestamp, same shape as JavaScript's toISOString(). */
	public static function iso_now() {
		$t = microtime( true );
		return gmdate( 'Y-m-d\TH:i:s', (int) $t ) . sprintf( '.%03dZ', (int) ( ( $t - floor( $t ) ) * 1000 ) );
	}
}
