<?php
/**
 * Jalali (Shamsi) calendar conversion and monthly planning periods.
 *
 * A planning period is a Jalali month key such as "1405-08" (آبان ۱۴۰۵).
 * Dates are stored in Gregorian Y-m-d and displayed in Jalali.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

const SAP_JMONTHS = array( 1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );

/**
 * Gregorian → Jalali.
 */
function sap_g2j( $gy, $gm, $gd ) {
	$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
	$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
	$days  = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];
	$jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
	$days %= 12053;
	$jy   += 4 * intdiv( $days, 1461 );
	$days %= 1461;
	if ( $days > 365 ) {
		$jy  += intdiv( $days - 1, 365 );
		$days = ( $days - 1 ) % 365;
	}
	if ( $days < 186 ) {
		$jm = 1 + intdiv( $days, 31 );
		$jd = 1 + ( $days % 31 );
	} else {
		$jm = 7 + intdiv( $days - 186, 30 );
		$jd = 1 + ( ( $days - 186 ) % 30 );
	}
	return array( $jy, $jm, $jd );
}

/**
 * Jalali → Gregorian.
 */
function sap_j2g( $jy, $jm, $jd ) {
	$jy  += 1595;
	$days = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 ) + $jd + ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );
	$gy   = 400 * intdiv( $days, 146097 );
	$days %= 146097;
	if ( $days > 36524 ) {
		$gy  += 100 * intdiv( --$days, 36524 );
		$days %= 36524;
		if ( $days >= 365 ) {
			$days++;
		}
	}
	$gy  += 4 * intdiv( $days, 1461 );
	$days %= 1461;
	if ( $days > 365 ) {
		$gy  += intdiv( $days - 1, 365 );
		$days = ( $days - 1 ) % 365;
	}
	$gd    = $days + 1;
	$leap  = ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400;
	$sal_a = array( 0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
	for ( $gm = 0; $gm < 13 && $gd > $sal_a[ $gm ]; $gm++ ) {
		$gd -= $sal_a[ $gm ];
	}
	return array( $gy, $gm, $gd );
}

/**
 * Format a Gregorian date (Y-m-d or datetime) as Jalali text.
 *
 * @param string $format long (۲ آبان ۱۴۰۵) | short (۱۴۰۵/۰۸/۰۲) | day (۲ آبان) | month (آبان ۱۴۰۵).
 */
function sap_jdate( $ymd, $format = 'long' ) {
	$ts = strtotime( (string) $ymd );
	if ( ! $ymd || ! $ts || 0 === strpos( (string) $ymd, '0000' ) ) {
		return '';
	}
	list( $jy, $jm, $jd ) = sap_g2j( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ) );
	switch ( $format ) {
		case 'short':
			return sap_fa( sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ) );
		case 'day':
			return sap_fa( $jd ) . ' ' . SAP_JMONTHS[ $jm ];
		case 'month':
			return SAP_JMONTHS[ $jm ] . ' ' . sap_fa( $jy );
		default:
			return sap_fa( $jd ) . ' ' . SAP_JMONTHS[ $jm ] . ' ' . sap_fa( $jy );
	}
}

/**
 * Number of days in a Jalali month.
 */
function sap_jmonth_days( $jy, $jm ) {
	if ( $jm <= 6 ) {
		return 31;
	}
	if ( $jm <= 11 ) {
		return 30;
	}
	list( $gy, $gm, $gd ) = sap_j2g( $jy + 1, 1, 1 );
	$next                  = strtotime( sprintf( '%04d-%02d-%02d', $gy, $gm, $gd ) );
	list( $gy, $gm, $gd ) = sap_j2g( $jy, 12, 1 );
	$start                 = strtotime( sprintf( '%04d-%02d-%02d', $gy, $gm, $gd ) );
	return (int) round( ( $next - $start ) / DAY_IN_SECONDS );
}

/**
 * Current Jalali [year, month, day] in the site timezone.
 */
function sap_jnow() {
	$ts = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	return sap_g2j( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ) );
}

/**
 * Current planning period key, e.g. "1405-08".
 */
function sap_period_current() {
	list( $jy, $jm ) = sap_jnow();
	return sprintf( '%04d-%02d', $jy, $jm );
}

/**
 * Validate / normalize a period key.
 */
function sap_period_sanitize( $key ) {
	$key = sap_norm_text( (string) $key );
	if ( preg_match( '/^(\d{4})-(\d{1,2})$/', $key, $m ) && (int) $m[2] >= 1 && (int) $m[2] <= 12 ) {
		return sprintf( '%04d-%02d', (int) $m[1], (int) $m[2] );
	}
	return sap_period_current();
}

/**
 * Shift a period by N months.
 */
function sap_period_shift( $key, $n ) {
	list( $jy, $jm ) = array_map( 'intval', explode( '-', sap_period_sanitize( $key ) ) );
	$idx             = $jy * 12 + ( $jm - 1 ) + (int) $n;
	return sprintf( '%04d-%02d', intdiv( $idx, 12 ), ( $idx % 12 ) + 1 );
}

/**
 * Human label of a period: «آبان ۱۴۰۵».
 */
function sap_period_label( $key ) {
	list( $jy, $jm ) = array_map( 'intval', explode( '-', sap_period_sanitize( $key ) ) );
	return SAP_JMONTHS[ $jm ] . ' ' . sap_fa( $jy );
}

/**
 * Gregorian [start, end] (Y-m-d) of a period.
 */
function sap_period_range( $key ) {
	list( $jy, $jm )      = array_map( 'intval', explode( '-', sap_period_sanitize( $key ) ) );
	list( $gy, $gm, $gd ) = sap_j2g( $jy, $jm, 1 );
	$start                = sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
	$end                  = gmdate( 'Y-m-d', strtotime( $start ) + ( sap_jmonth_days( $jy, $jm ) - 1 ) * DAY_IN_SECONDS );
	return array( $start, $end );
}

/**
 * Period key of a Gregorian date.
 */
function sap_period_of( $ymd ) {
	$ts = strtotime( (string) $ymd );
	if ( ! $ts ) {
		return sap_period_current();
	}
	list( $jy, $jm ) = sap_g2j( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ) );
	return sprintf( '%04d-%02d', $jy, $jm );
}

/**
 * Parse a user-entered date into Gregorian Y-m-d.
 *
 * Accepts Jalali (۱۴۰۵/۰۸/۱۵, 1405-8-15) and Gregorian (2026-11-06) dates.
 * Returns '' when the text is not a date.
 */
function sap_parse_date( $text ) {
	$t = sap_norm_text( (string) $text );
	if ( ! preg_match( '/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/', $t, $m ) ) {
		return '';
	}
	$y = (int) $m[1];
	$mo = (int) $m[2];
	$d = (int) $m[3];
	if ( $mo < 1 || $mo > 12 || $d < 1 || $d > 31 ) {
		return '';
	}
	if ( $y < 1700 ) {
		list( $y, $mo, $d ) = sap_j2g( $y, $mo, $d );
	}
	return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
}

/**
 * Options for a period <select>: N months back and ahead.
 */
function sap_period_options( $back = 6, $ahead = 3 ) {
	$cur = sap_period_current();
	$out = array();
	for ( $i = $ahead; $i >= -$back; $i-- ) {
		$k         = sap_period_shift( $cur, $i );
		$out[ $k ] = sap_period_label( $k );
	}
	return $out;
}
