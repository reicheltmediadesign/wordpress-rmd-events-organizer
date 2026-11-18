<?php
/**
 * Turns one recurrence rule into the list of dates it produces. Knows nothing
 * about events, times or exclusions, so appointment slots can reuse it.
 *
 * Rules work on wall-clock dates. Weekly rules are anchored to the ISO week
 * of the start date; monthly and yearly rules skip periods that lack the
 * requested day instead of moving it.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain;

final class RecurrenceExpander {

	public const FREQUENCIES = [ 'daily', 'weekly', 'monthly', 'yearly' ];
	public const MONTH_MODES = [ 'day_of_month', 'nth_weekday' ];

	/** Hard stop for a single rule, whatever its range. */
	public const MAX_DATES = 10000;

	/**
	 * @param array  $rule    Canonical rule entry (see Validator::normalize_schedule()).
	 * @param string $horizon Last date to generate for rules without an end.
	 * @return list<string> Ascending start dates.
	 */
	public static function dates( array $rule, string $horizon ): array {
		$from  = (string) ( $rule['from'] ?? '' );
		$until = (string) ( $rule['until'] ?? '' );
		if ( ! Dates::is_valid( $from ) || ! Dates::is_valid( $horizon ) ) {
			return [];
		}
		$last  = ( Dates::is_valid( $until ) && $until < $horizon ) ? $until : $horizon;
		$count = (int) ( $rule['count'] ?? 0 );
		$limit = $count > 0 ? min( $count, self::MAX_DATES ) : self::MAX_DATES;
		$step  = max( 1, (int) ( $rule['interval'] ?? 1 ) );

		switch ( (string) ( $rule['freq'] ?? '' ) ) {
			case 'daily':
				return self::daily( $from, $last, $step, $limit );
			case 'weekly':
				return self::weekly( $rule, $from, $last, $step, $limit );
			case 'monthly':
				return self::by_month( $rule, $from, $last, $step, $limit );
			case 'yearly':
				return self::by_month( $rule, $from, $last, $step * 12, $limit );
		}
		return [];
	}

	/**
	 * @return list<string>
	 */
	private static function daily( string $from, string $last, int $step, int $limit ): array {
		$dates = [];
		$date  = $from;
		$total = 0;
		while ( $date <= $last && $total < $limit ) {
			$dates[] = $date;
			$date    = Dates::add_days( $date, $step );
			++$total;
		}
		return $dates;
	}

	/**
	 * @return list<string>
	 */
	private static function weekly( array $rule, string $from, string $last, int $step, int $limit ): array {
		$offsets = [];
		foreach ( (array) ( $rule['weekdays'] ?? [] ) as $weekday ) {
			$index = array_search( $weekday, Dates::WEEKDAYS, true );
			if ( false !== $index ) {
				$offsets[] = (int) $index;
			}
		}
		if ( [] === $offsets ) {
			$offsets[] = (int) array_search( Dates::weekday( $from ), Dates::WEEKDAYS, true );
		}
		$offsets = array_values( array_unique( $offsets ) );
		sort( $offsets );

		$dates = [];
		for ( $week = Dates::week_start( $from ); $week <= $last; $week = Dates::add_days( $week, 7 * $step ) ) {
			foreach ( $offsets as $offset ) {
				$date = Dates::add_days( $week, $offset );
				if ( $date < $from ) {
					continue;
				}
				if ( $date > $last || count( $dates ) >= $limit ) {
					return $dates;
				}
				$dates[] = $date;
			}
		}
		return $dates;
	}

	/**
	 * Monthly and yearly rules: one candidate per visited month.
	 *
	 * @return list<string>
	 */
	private static function by_month( array $rule, string $from, string $last, int $step, int $limit ): array {
		$nth_mode = 'nth_weekday' === ( $rule['month_mode'] ?? '' );
		$day      = (int) substr( $from, 8, 2 );
		$weekday  = (string) ( ( (array) ( $rule['weekdays'] ?? [] ) )[0] ?? '' );
		if ( ! in_array( $weekday, Dates::WEEKDAYS, true ) ) {
			$weekday = Dates::weekday( $from );
		}
		$nth = (int) ( $rule['nth'] ?? 0 );
		if ( 0 === $nth ) {
			// Derive "third Saturday" from the start date itself.
			$nth = intdiv( $day - 1, 7 ) + 1;
		}

		$dates      = [];
		$last_month = substr( $last, 0, 7 );
		for ( $month = substr( $from, 0, 7 ); $month <= $last_month; $month = Dates::add_months( $month, $step ) ) {
			$year_number  = (int) substr( $month, 0, 4 );
			$month_number = (int) substr( $month, 5, 2 );

			if ( $nth_mode ) {
				$date = Dates::nth_weekday( $year_number, $month_number, $weekday, $nth );
			} else {
				$date = $day <= Dates::days_in_month( $year_number, $month_number ) ? sprintf( '%s-%02d', $month, $day ) : null;
			}

			if ( null === $date || $date < $from ) {
				continue;
			}
			if ( $date > $last || count( $dates ) >= $limit ) {
				break;
			}
			$dates[] = $date;
		}
		return $dates;
	}
}
