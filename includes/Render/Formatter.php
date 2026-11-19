<?php
/**
 * Formats dates and times of occurrences using the site's date and time
 * settings.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Render;

use RMD\EventsOrganizer\Clock;
use RMD\EventsOrganizer\Domain\Occurrence;
use RMD\EventsOrganizer\Settings;

defined( 'ABSPATH' ) || exit;

final class Formatter {

	/**
	 * Formats a wall-clock date with a PHP date format, translated.
	 */
	public static function date( string $ymd, ?string $format = null ): string {
		$date = date_create_immutable( $ymd . ' 12:00:00', Clock::timezone() );
		if ( ! $date ) {
			return $ymd;
		}
		return (string) wp_date( $format ?? self::date_format(), $date->getTimestamp(), Clock::timezone() );
	}

	public static function time( string $time ): string {
		$date = date_create_immutable( '2000-01-01 ' . $time . ':00', Clock::timezone() );
		if ( ! $date ) {
			return $time;
		}
		return (string) wp_date( (string) get_option( 'time_format', 'H:i' ), $date->getTimestamp(), Clock::timezone() );
	}

	/**
	 * Date format with the weekday in front ("Sat, 12 December 2026").
	 */
	public static function date_format(): string {
		/**
		 * Filters the PHP date format used for event dates.
		 *
		 * @param string $format Default: short weekday plus the site's date format.
		 */
		return (string) apply_filters( 'rmd_eo_date_format', 'D, ' . get_option( 'date_format', 'j. F Y' ) );
	}

	/**
	 * "Sat, 12 December 2026, 10:00 – 18:00" and its multi-day variants.
	 *
	 * @param array<string, mixed> $occurrence Row from OccurrenceQuery.
	 */
	public static function when( array $occurrence ): string {
		$dates = self::dates( $occurrence );
		$times = self::times( $occurrence );

		if ( '' === $times ) {
			return $dates;
		}
		if ( $occurrence['start_date'] === $occurrence['end_date'] ) {
			/* translators: 1: date, 2: time or time range */
			return sprintf( _x( '%1$s, %2$s', 'date and time', 'rmd-events-organizer' ), $dates, $times );
		}
		if ( Occurrence::MODE_DAILY === $occurrence['time_mode'] ) {
			/* translators: 1: date range, 2: time range that applies to every day */
			return sprintf( __( '%1$s, daily %2$s', 'rmd-events-organizer' ), $dates, $times );
		}

		$start = sprintf(
			/* translators: 1: date, 2: time */
			_x( '%1$s, %2$s', 'date and time', 'rmd-events-organizer' ),
			self::date( (string) $occurrence['start_date'] ),
			self::time( (string) $occurrence['start_time'] )
		);
		if ( '' === $occurrence['end_time'] || ! self::show_end() ) {
			/* translators: 1: start date and time, 2: end date */
			return sprintf( _x( '%1$s – %2$s', 'date range', 'rmd-events-organizer' ), $start, self::date( (string) $occurrence['end_date'] ) );
		}
		$end = sprintf(
			/* translators: 1: date, 2: time */
			_x( '%1$s, %2$s', 'date and time', 'rmd-events-organizer' ),
			self::date( (string) $occurrence['end_date'] ),
			self::time( (string) $occurrence['end_time'] )
		);
		/* translators: 1: start date and time, 2: end date and time */
		return sprintf( _x( '%1$s – %2$s', 'date range', 'rmd-events-organizer' ), $start, $end );
	}

	/**
	 * Date or date range without times.
	 *
	 * @param array<string, mixed> $occurrence
	 */
	public static function dates( array $occurrence ): string {
		$start = (string) $occurrence['start_date'];
		$end   = (string) $occurrence['end_date'];
		if ( $start === $end ) {
			return self::date( $start );
		}
		/* translators: 1: start date, 2: end date */
		return sprintf( _x( '%1$s – %2$s', 'date range', 'rmd-events-organizer' ), self::date( $start ), self::date( $end ) );
	}

	/**
	 * Time or time range; empty for all-day occurrences.
	 *
	 * @param array<string, mixed> $occurrence
	 */
	public static function times( array $occurrence ): string {
		if ( ! empty( $occurrence['all_day'] ) || '' === $occurrence['start_time'] ) {
			return '';
		}
		$start = self::time( (string) $occurrence['start_time'] );
		if ( '' === $occurrence['end_time'] || ! self::show_end() ) {
			return $start;
		}
		/* translators: 1: start time, 2: end time */
		return sprintf( _x( '%1$s – %2$s', 'time range', 'rmd-events-organizer' ), $start, self::time( (string) $occurrence['end_time'] ) );
	}

	private static function show_end(): bool {
		return ! empty( ( (array) Settings::get( 'display', [] ) )['show_end_time'] );
	}
}
