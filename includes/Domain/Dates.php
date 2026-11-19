<?php
/**
 * Small date/time string helpers. All dates are "YYYY-MM-DD", all times "HH:MM";
 * string comparison therefore equals chronological comparison.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain;

use DateTimeImmutable;
use DateTimeZone;

final class Dates {

	public const WEEKDAYS = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];

	public static function is_valid( string $ymd ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	public static function is_valid_time( string $time ): bool {
		return (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time );
	}

	public static function is_valid_month( string $ym ): bool {
		return (bool) preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $ym );
	}

	public static function date( string $ymd ): DateTimeImmutable {
		return new DateTimeImmutable( $ymd . ' 00:00:00', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * @return string One of self::WEEKDAYS.
	 */
	public static function weekday( string $ymd ): string {
		return self::WEEKDAYS[ (int) self::date( $ymd )->format( 'N' ) - 1 ];
	}

	public static function add_days( string $ymd, int $days ): string {
		return self::date( $ymd )->modify( sprintf( '%+d days', $days ) )->format( 'Y-m-d' );
	}

	/**
	 * Number of days from $from to $to (negative when $to is earlier).
	 */
	public static function diff_days( string $from, string $to ): int {
		$diff = self::date( $from )->diff( self::date( $to ) );
		return (int) $diff->format( '%r%a' );
	}

	/**
	 * Monday of the ISO week that contains $ymd.
	 */
	public static function week_start( string $ymd ): string {
		$offset = (int) self::date( $ymd )->format( 'N' ) - 1;
		return self::add_days( $ymd, -$offset );
	}

	public static function days_in_month( int $year, int $month ): int {
		return (int) self::date( sprintf( '%04d-%02d-01', $year, $month ) )->format( 't' );
	}

	/**
	 * "YYYY-MM" shifted by a number of months.
	 */
	public static function add_months( string $ym, int $months ): string {
		$index = (int) substr( $ym, 0, 4 ) * 12 + (int) substr( $ym, 5, 2 ) - 1 + $months;
		return sprintf( '%04d-%02d', intdiv( $index, 12 ), $index % 12 + 1 );
	}

	/**
	 * The nth (1..5) or last (-1) given weekday of a month; null when the
	 * month has no such day (a fifth Saturday, for example).
	 */
	public static function nth_weekday( int $year, int $month, string $weekday, int $nth ): ?string {
		$target = array_search( $weekday, self::WEEKDAYS, true );
		if ( false === $target || 0 === $nth ) {
			return null;
		}
		$days = self::days_in_month( $year, $month );

		if ( $nth < 0 ) {
			$last  = sprintf( '%04d-%02d-%02d', $year, $month, $days );
			$index = (int) array_search( self::weekday( $last ), self::WEEKDAYS, true );
			return self::add_days( $last, -( ( $index - $target + 7 ) % 7 ) );
		}

		$first = sprintf( '%04d-%02d-01', $year, $month );
		$index = (int) array_search( self::weekday( $first ), self::WEEKDAYS, true );
		$day   = 1 + ( ( $target - $index + 7 ) % 7 ) + ( $nth - 1 ) * 7;
		return $day > $days ? null : sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * Converts a wall-clock date and time in the given zone to a UTC
	 * "Y-m-d H:i:s" string.
	 */
	public static function to_utc( string $ymd, string $time, string $timezone ): string {
		$local = new DateTimeImmutable( $ymd . ' ' . ( '' === $time ? '00:00' : $time ) . ':00', self::zone( $timezone ) );
		return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Accepts IANA names and fixed offsets ("+02:00"); anything else is UTC.
	 */
	public static function zone( string $timezone ): DateTimeZone {
		try {
			return new DateTimeZone( '' === $timezone ? 'UTC' : $timezone );
		} catch ( \Exception $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}
}
