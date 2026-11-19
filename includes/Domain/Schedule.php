<?php
/**
 * Expands the schedule of an event (single dates, rules, exclusions and
 * per-date overrides) into concrete occurrences.
 *
 * Every occurrence gets a key that survives edits: the entry id for single
 * dates, "<entry id>:<start date>" for rule instances. Changing a time keeps
 * the key, so rows that depend on an occurrence stay attached to it.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain;

final class Schedule {

	public const MAX_OCCURRENCES = 1000;

	/**
	 * @param array  $schedule Canonical schedule (see Validator::normalize_schedule()).
	 * @param string $horizon  Last start date to generate for open-ended rules.
	 * @param string $timezone Zone the wall-clock times are meant in.
	 * @param int    $max      Upper bound; the most recent occurrences win.
	 * @return list<Occurrence> Ordered by start.
	 */
	public static function expand( array $schedule, string $horizon, string $timezone, int $max = self::MAX_OCCURRENCES ): array {
		$exclusions  = (array) ( $schedule['exclusions'] ?? [] );
		$overrides   = (array) ( $schedule['overrides'] ?? [] );
		$occurrences = [];

		foreach ( (array) ( $schedule['entries'] ?? [] ) as $entry ) {
			if ( ! is_array( $entry ) || '' === (string) ( $entry['id'] ?? '' ) ) {
				continue;
			}
			$id = (string) $entry['id'];

			if ( 'rule' === ( $entry['type'] ?? '' ) ) {
				$duration = max( 1, (int) ( $entry['duration_days'] ?? 1 ) );
				foreach ( RecurrenceExpander::dates( $entry, $horizon ) as $date ) {
					if ( self::is_excluded( $date, $exclusions ) ) {
						continue;
					}
					$key           = self::rule_key( $id, $date );
					$occurrences[] = Occurrence::build( $key, $date, Dates::add_days( $date, $duration - 1 ), $entry, $timezone, (array) ( $overrides[ $key ] ?? [] ) );
				}
				continue;
			}

			$start = (string) ( $entry['start_date'] ?? '' );
			$end   = (string) ( $entry['end_date'] ?? '' );
			if ( ! Dates::is_valid( $start ) ) {
				continue;
			}
			if ( ! Dates::is_valid( $end ) || $end < $start ) {
				$end = $start;
			}
			$occurrences[] = Occurrence::build( $id, $start, $end, $entry, $timezone, (array) ( $overrides[ $id ] ?? [] ) );
		}

		usort(
			$occurrences,
			static function ( Occurrence $a, Occurrence $b ): int {
				return [ $a->start_utc, $a->end_utc, $a->key ] <=> [ $b->start_utc, $b->end_utc, $b->key ];
			}
		);

		if ( $max > 0 && count( $occurrences ) > $max ) {
			$occurrences = array_slice( $occurrences, -$max );
		}
		return $occurrences;
	}

	public static function rule_key( string $entry_id, string $date ): string {
		return $entry_id . ':' . $date;
	}

	/**
	 * Entry id an occurrence key belongs to.
	 */
	public static function entry_id( string $key ): string {
		$position = strpos( $key, ':' );
		return false === $position ? $key : substr( $key, 0, $position );
	}

	/**
	 * @param list<array{from: string, to: string}> $exclusions
	 */
	public static function is_excluded( string $date, array $exclusions ): bool {
		foreach ( $exclusions as $range ) {
			if ( $date >= (string) ( $range['from'] ?? '' ) && $date <= (string) ( $range['to'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when at least one rule has neither an end date nor a count, so the
	 * index needs to be extended as time passes.
	 */
	public static function is_open_ended( array $schedule ): bool {
		foreach ( (array) ( $schedule['entries'] ?? [] ) as $entry ) {
			if ( 'rule' === ( $entry['type'] ?? '' ) && '' === (string) ( $entry['until'] ?? '' ) && 0 === (int) ( $entry['count'] ?? 0 ) ) {
				return true;
			}
		}
		return false;
	}
}
