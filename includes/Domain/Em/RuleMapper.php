<?php
/**
 * Translates one Events Manager recurrence rule into schedule entries.
 *
 * Works for both generations of that plugin: the rule columns on the events
 * table (up to 6.x) and the rows of the separate recurrences table (7.x). The
 * caller passes the fields under the names used here.
 *
 * Differences that the mapping accounts for:
 * - Weekly rules there start one series per weekday at its first date on or
 *   after the start and step by the interval from that date. With an interval
 *   above one that differs from week-anchored repetition, so each weekday
 *   becomes its own rule here.
 * - The duration counts the days added to the start; here it counts days.
 * - Weekdays are numbered from Sunday = 0.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain\Em;

use RMD\EventsOrganizer\Domain\Dates;
use RMD\EventsOrganizer\Domain\RecurrenceExpander;

final class RuleMapper {

	private const WEEKDAYS = [ 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ];

	/**
	 * @param array $rule Fields: freq, interval, byday, byweekno, duration,
	 *                    dates, start_date, end_date, end_after, start_time,
	 *                    end_time, all_day.
	 * @return array{entries: list<array>, warnings: list<string>} Raw entries without ids.
	 */
	public static function map( array $rule ): array {
		$freq     = strtolower( trim( (string) ( $rule['freq'] ?? '' ) ) );
		$from     = substr( (string) ( $rule['start_date'] ?? '' ), 0, 10 );
		$until    = substr( (string) ( $rule['end_date'] ?? '' ), 0, 10 );
		$warnings = [];

		$times = [
			'all_day'    => ! empty( $rule['all_day'] ),
			'start_time' => self::time( $rule['start_time'] ?? '' ),
			'end_time'   => self::time( $rule['end_time'] ?? '' ),
		];
		$days  = max( 0, (int) ( $rule['duration'] ?? 0 ) ) + 1;

		if ( 'on' === $freq ) {
			$entries = [];
			foreach ( self::date_list( $rule['dates'] ?? '' ) as $date ) {
				$entries[] = [
					'type'       => 'single',
					'start_date' => $date,
					'end_date'   => Dates::add_days( $date, $days - 1 ),
				] + $times;
			}
			return [
				'entries'  => $entries,
				'warnings' => $warnings,
			];
		}

		if ( ! in_array( $freq, RecurrenceExpander::FREQUENCIES, true ) || ! Dates::is_valid( $from ) ) {
			return [
				'entries'  => [],
				'warnings' => [ 'unsupported_rule' ],
			];
		}
		if ( ! Dates::is_valid( $until ) ) {
			$until = '';
		}

		$base = [
			'type'          => 'rule',
			'freq'          => $freq,
			'interval'      => max( 1, (int) ( $rule['interval'] ?? 1 ) ),
			'weekdays'      => [],
			'month_mode'    => 'day_of_month',
			'nth'           => 0,
			'from'          => $from,
			'until'         => $until,
			'count'         => max( 0, (int) ( $rule['end_after'] ?? 0 ) ),
			'duration_days' => $days,
		] + $times;

		$weekdays = self::weekdays( $rule['byday'] ?? '' );

		if ( 'weekly' === $freq ) {
			if ( [] === $weekdays ) {
				return [
					'entries'  => [],
					'warnings' => [ 'weekly_without_days' ],
				];
			}
			if ( 1 === $base['interval'] ) {
				return [
					'entries'  => [ [ 'weekdays' => $weekdays ] + $base ],
					'warnings' => $warnings,
				];
			}
			if ( $base['count'] > 0 ) {
				// A count across separately anchored weekday series cannot be expressed per rule.
				$warnings[] = 'count_with_interval';
			}
			$entries = [];
			foreach ( $weekdays as $weekday ) {
				$entries[] = [
					'weekdays' => [ $weekday ],
					'from'     => self::first_weekday_from( $from, $weekday ),
				] + $base;
			}
			return [
				'entries'  => $entries,
				'warnings' => $warnings,
			];
		}

		if ( 'monthly' === $freq ) {
			if ( [] === $weekdays ) {
				return [
					'entries'  => [],
					'warnings' => [ 'monthly_without_day' ],
				];
			}
			$week               = (int) ( $rule['byweekno'] ?? 0 );
			$base['month_mode'] = 'nth_weekday';
			$base['weekdays']   = [ $weekdays[0] ];
			$base['nth']        = $week > 0 ? min( 5, $week ) : -1;
		}

		return [
			'entries'  => [ $base ],
			'warnings' => $warnings,
		];
	}

	/**
	 * Start dates a rule produces, used for "exclude" rules and for comparing
	 * the mapping with the dates the source plugin materialised.
	 *
	 * @return list<string>
	 */
	public static function dates( array $rule, string $horizon ): array {
		$dates = [];
		foreach ( self::map( $rule )['entries'] as $entry ) {
			if ( 'single' === $entry['type'] ) {
				$dates[] = $entry['start_date'];
				continue;
			}
			$dates = array_merge( $dates, RecurrenceExpander::dates( $entry, $horizon ) );
		}
		$dates = array_values( array_unique( $dates ) );
		sort( $dates );
		return $dates;
	}

	/**
	 * "HH:MM:SS" or "HH:MM" to "HH:MM"; anything else is empty.
	 *
	 * @param mixed $value
	 */
	public static function time( $value ): string {
		$time = substr( trim( (string) $value ), 0, 5 );
		return Dates::is_valid_time( $time ) ? $time : '';
	}

	/**
	 * @param mixed $value Comma separated numbers, Sunday = 0.
	 * @return list<string> In Monday-first order.
	 */
	private static function weekdays( $value ): array {
		$found = [];
		foreach ( explode( ',', (string) $value ) as $number ) {
			$number = trim( $number );
			if ( '' !== $number && ctype_digit( $number ) && isset( self::WEEKDAYS[ (int) $number ] ) ) {
				$found[] = self::WEEKDAYS[ (int) $number ];
			}
		}
		return array_values( array_intersect( Dates::WEEKDAYS, $found ) );
	}

	/**
	 * @param mixed $value Comma separated dates or an array of them.
	 * @return list<string>
	 */
	private static function date_list( $value ): array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$dates = [];
		foreach ( $items as $item ) {
			$item = trim( (string) $item );
			if ( Dates::is_valid( $item ) ) {
				$dates[] = $item;
			}
		}
		$dates = array_values( array_unique( $dates ) );
		sort( $dates );
		return $dates;
	}

	private static function first_weekday_from( string $from, string $weekday ): string {
		for ( $offset = 0; $offset < 7; $offset++ ) {
			$date = Dates::add_days( $from, $offset );
			if ( Dates::weekday( $date ) === $weekday ) {
				return $date;
			}
		}
		return $from;
	}
}
