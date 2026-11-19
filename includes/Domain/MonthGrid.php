<?php
/**
 * Lays out the days of a month in complete weeks and assigns occurrences to
 * the days they cover.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain;

final class MonthGrid {

	/**
	 * @param string $month         "YYYY-MM".
	 * @param int    $start_of_week 0 (Sunday) to 6 (Saturday), as in WordPress.
	 * @return array{from: string, to: string, weeks: list<list<array{date: string, in_month: bool}>>, weekdays: list<string>}
	 */
	public static function build( string $month, int $start_of_week = 1 ): array {
		$first = $month . '-01';
		$last  = sprintf( '%s-%02d', $month, Dates::days_in_month( (int) substr( $month, 0, 4 ), (int) substr( $month, 5, 2 ) ) );

		// Position of a weekday in the Monday-first list, shifted to the site's first day.
		$first_index = ( $start_of_week + 6 ) % 7;
		$lead        = ( (int) array_search( Dates::weekday( $first ), Dates::WEEKDAYS, true ) - $first_index + 7 ) % 7;
		$from        = Dates::add_days( $first, -$lead );
		$trail       = 6 - ( ( (int) array_search( Dates::weekday( $last ), Dates::WEEKDAYS, true ) - $first_index + 7 ) % 7 );
		$to          = Dates::add_days( $last, $trail );

		$weeks = [];
		$week  = [];
		for ( $date = $from; $date <= $to; $date = Dates::add_days( $date, 1 ) ) {
			$week[] = [
				'date'     => $date,
				'in_month' => substr( $date, 0, 7 ) === $month,
			];
			if ( 7 === count( $week ) ) {
				$weeks[] = $week;
				$week    = [];
			}
		}

		$weekdays = [];
		for ( $i = 0; $i < 7; $i++ ) {
			$weekdays[] = Dates::WEEKDAYS[ ( $first_index + $i ) % 7 ];
		}

		return [
			'from'     => $from,
			'to'       => $to,
			'weeks'    => $weeks,
			'weekdays' => $weekdays,
		];
	}

	/**
	 * Groups occurrences by every day they cover within the given range.
	 *
	 * @param list<array<string, mixed>> $occurrences Rows with start_date and end_date.
	 * @return array<string, list<array<string, mixed>>> date => occurrences.
	 */
	public static function by_day( array $occurrences, string $from, string $to ): array {
		$days = [];
		foreach ( $occurrences as $occurrence ) {
			$start = max( (string) $occurrence['start_date'], $from );
			$end   = min( (string) $occurrence['end_date'], $to );
			for ( $date = $start; $date <= $end; $date = Dates::add_days( $date, 1 ) ) {
				$days[ $date ][] = $occurrence;
			}
		}
		return $days;
	}
}
