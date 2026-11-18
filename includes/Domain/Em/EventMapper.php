<?php
/**
 * Translates the date fields of one Events Manager event into the time
 * fields of a schedule entry.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain\Em;

use RMD\EventsOrganizer\Domain\Dates;

final class EventMapper {

	/**
	 * @param array $row Fields: start_date, end_date, start_time, end_time, all_day.
	 * @return array{start_date: string, end_date: string, all_day: bool, start_time: string, end_time: string}|null
	 *         Null when the row has no usable start date.
	 */
	public static function dates( array $row ): ?array {
		$start = substr( (string) ( $row['start_date'] ?? '' ), 0, 10 );
		$end   = substr( (string) ( $row['end_date'] ?? '' ), 0, 10 );
		if ( ! Dates::is_valid( $start ) ) {
			return null;
		}
		if ( ! Dates::is_valid( $end ) || $end < $start ) {
			$end = $start;
		}

		return [
			'start_date' => $start,
			'end_date'   => $end,
		] + self::times( $row['start_time'] ?? '', $row['end_time'] ?? '', ! empty( $row['all_day'] ) );
	}

	/**
	 * All-day events are stored there as 00:00:00 to 23:59:59 (or, by older
	 * versions, 00:00:00 to 00:00:00); an end equal to the start means that
	 * no end was entered.
	 *
	 * @param mixed $start_time
	 * @param mixed $end_time
	 * @return array{all_day: bool, start_time: string, end_time: string}
	 */
	public static function times( $start_time, $end_time, bool $all_day ): array {
		$raw_end = substr( trim( (string) $end_time ), 0, 8 );
		$start   = RuleMapper::time( $start_time );
		$end     = RuleMapper::time( $end_time );

		if ( $all_day || '' === $start || ( '00:00' === $start && ( '23:59:59' === $raw_end || '00:00' === $end ) ) ) {
			return [
				'all_day'    => true,
				'start_time' => '',
				'end_time'   => '',
			];
		}

		return [
			'all_day'    => false,
			'start_time' => $start,
			'end_time'   => $end === $start ? '' : $end,
		];
	}
}
