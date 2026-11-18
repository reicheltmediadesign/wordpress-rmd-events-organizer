<?php
/**
 * Turns a series of Events Manager (its rules plus the dates that plugin
 * created from them) into one schedule.
 *
 * The rules are mapped first. Then every date that really exists is compared
 * with what the mapped rules produce:
 * - a date the rules produce but that does not exist becomes an exclusion,
 * - a date with other times or a cancellation becomes an override,
 * - a date the rules do not produce becomes a single entry,
 * - a date that was edited on its own is left out; the caller imports it as
 *   a separate event.
 *
 * The result is checked once more against the existing dates; anything that
 * still differs is reported in "mismatches".
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain\Em;

use RMD\EventsOrganizer\Domain\Dates;
use RMD\EventsOrganizer\Domain\Occurrence;
use RMD\EventsOrganizer\Domain\Schedule;
use RMD\EventsOrganizer\Domain\Validator;

final class SeriesCollapser {

	private const HORIZON = '2100-12-31';

	/**
	 * @param list<array> $rules    Rule fields as RuleMapper::map() expects them, plus
	 *                              'set_id' (int) and 'type' ("include" or "exclude").
	 * @param list<array> $children Existing dates: id, start_date, end_date, start_time,
	 *                              end_time, all_day, cancelled (bool), standalone (bool).
	 * @param string      $timezone Zone the wall-clock times are meant in.
	 * @return array{schedule: array, standalone: list<int>, warnings: list<string>, mismatches: list<string>}
	 */
	public static function collapse( array $rules, array $children, string $timezone ): array {
		$validator  = new Validator();
		$warnings   = [];
		$entries    = [];
		$exclusions = [];
		$overrides  = [];
		$standalone = [];
		$usable     = true;

		$dated = [];
		foreach ( $children as $child ) {
			$dates = EventMapper::dates( $child );
			if ( null === $dates ) {
				$warnings[] = 'child_without_date';
				continue;
			}
			$dated[] = $dates + [
				'id'         => (int) ( $child['id'] ?? 0 ),
				'cancelled'  => ! empty( $child['cancelled'] ),
				'standalone' => ! empty( $child['standalone'] ),
			];
		}

		foreach ( $rules as $rule ) {
			if ( 'exclude' === ( $rule['type'] ?? 'include' ) ) {
				continue;
			}
			$mapped = RuleMapper::map( $rule );
			if ( [] === $mapped['entries'] ) {
				$usable = false;
			}
			$warnings = array_merge( $warnings, $mapped['warnings'] );
			foreach ( $mapped['entries'] as $index => $entry ) {
				$times     = EventMapper::times( $entry['start_time'], $entry['end_time'], ! empty( $entry['all_day'] ) );
				$entries[] = [ 'id' => self::id( 'ems' . (int) ( $rule['set_id'] ?? 0 ), $index ) ] + $times + $entry;
			}
		}
		foreach ( $rules as $rule ) {
			if ( 'exclude' === ( $rule['type'] ?? 'include' ) ) {
				foreach ( RuleMapper::dates( $rule, self::HORIZON ) as $date ) {
					$exclusions[] = $date;
				}
			}
		}

		if ( ! $usable || [] === $entries ) {
			// The rules cannot be expressed: keep the dates exactly as they are.
			$entries    = [];
			$exclusions = [];
		} else {
			$expected = [];
			$schedule = $validator->normalize_schedule(
				[
					'entries'    => $entries,
					'exclusions' => $exclusions,
				]
			)['data'];
			foreach ( Schedule::expand( $schedule, self::HORIZON, $timezone, 0 ) as $occurrence ) {
				$expected[ $occurrence->start_date ][] = $occurrence;
			}
			$by_id = array_column( $schedule['entries'], null, 'id' );

			foreach ( $dated as $index => $child ) {
				$match = isset( $expected[ $child['start_date'] ] ) ? array_shift( $expected[ $child['start_date'] ] ) : null;
				if ( null === $match ) {
					continue;
				}
				if ( $child['standalone'] ) {
					$exclusions[]    = $child['start_date'];
					$dated[ $index ] = null;
					$standalone[]    = $child['id'];
					continue;
				}

				$override = [];
				if ( self::signature( $child ) !== self::signature_of( $match ) ) {
					$override = [
						'start_time' => $child['start_time'],
						'end_time'   => $child['end_time'],
					];
					$entry    = $by_id[ Schedule::entry_id( $match->key ) ];
					$last_day = isset( $entry['duration_days'] ) ? Dates::add_days( $match->start_date, max( 1, (int) $entry['duration_days'] ) - 1 ) : (string) $entry['end_date'];
					$changed  = Occurrence::build( $match->key, $match->start_date, $last_day, $entry, $timezone, $override );
					if ( self::signature( $child ) !== self::signature_of( $changed ) ) {
						// Not expressible as a change of times: take the date out of the rule and keep it as it is.
						$exclusions[] = $child['start_date'];
						continue;
					}
				}
				if ( $child['cancelled'] ) {
					$override['status'] = Occurrence::STATUS_CANCELLED;
				}
				if ( [] !== $override ) {
					$overrides[ $match->key ] = $override;
				}
				$dated[ $index ] = null;
			}

			// What the rules produce but does not exist was deleted over there.
			foreach ( $expected as $date => $left ) {
				if ( [] !== $left ) {
					$exclusions[] = $date;
				}
			}
		}

		// Everything not explained by a rule becomes a date of its own.
		foreach ( array_filter( $dated ) as $child ) {
			if ( $child['standalone'] ) {
				$standalone[] = $child['id'];
				continue;
			}
			$id        = 'emc' . $child['id'];
			$entries[] = [
				'id'         => $id,
				'type'       => 'single',
				'start_date' => $child['start_date'],
				'end_date'   => $child['end_date'],
				'all_day'    => $child['all_day'],
				'start_time' => $child['start_time'],
				'end_time'   => $child['end_time'],
			];
			if ( $child['cancelled'] ) {
				$overrides[ $id ] = [ 'status' => Occurrence::STATUS_CANCELLED ];
			}
		}

		$result = $validator->normalize_schedule(
			[
				'entries'    => $entries,
				'exclusions' => array_values( array_unique( $exclusions ) ),
				'overrides'  => $overrides,
			]
		);
		foreach ( $result['errors'] as $error ) {
			$warnings[] = $error['code'];
		}

		return [
			'schedule'   => $result['data'],
			'standalone' => array_values( array_unique( $standalone ) ),
			'warnings'   => array_values( array_unique( $warnings ) ),
			'mismatches' => self::compare( $result['data'], $children, $standalone, $timezone ),
		];
	}

	/**
	 * Lists every difference between the dates a schedule produces and the
	 * dates that exist in the source.
	 *
	 * @param list<array> $children   As in collapse().
	 * @param list<int>   $standalone Ids of children that are imported separately.
	 * @return list<string>
	 */
	public static function compare( array $schedule, array $children, array $standalone, string $timezone ): array {
		$have = [];
		foreach ( Schedule::expand( $schedule, self::HORIZON, $timezone, 0 ) as $occurrence ) {
			$key          = self::signature_of( $occurrence ) . ( Occurrence::STATUS_CANCELLED === $occurrence->status ? ' cancelled' : '' );
			$have[ $key ] = ( $have[ $key ] ?? 0 ) + 1;
		}
		$want = [];
		foreach ( $children as $child ) {
			$dates = EventMapper::dates( $child );
			if ( null === $dates || in_array( (int) ( $child['id'] ?? 0 ), $standalone, true ) ) {
				continue;
			}
			$key          = self::signature( $dates ) . ( ! empty( $child['cancelled'] ) ? ' cancelled' : '' );
			$want[ $key ] = ( $want[ $key ] ?? 0 ) + 1;
		}

		$mismatches = [];
		foreach ( $want as $key => $number ) {
			if ( ( $have[ $key ] ?? 0 ) < $number ) {
				$mismatches[] = 'missing: ' . $key;
			}
		}
		foreach ( $have as $key => $number ) {
			if ( ( $want[ $key ] ?? 0 ) < $number ) {
				$mismatches[] = 'extra: ' . $key;
			}
		}
		sort( $mismatches );
		return $mismatches;
	}

	/**
	 * @param array $dates Result of EventMapper::dates().
	 */
	private static function signature( array $dates ): string {
		if ( $dates['all_day'] ) {
			return $dates['start_date'] . ' to ' . $dates['end_date'] . ' all day';
		}
		// An end before the start on the same day runs past midnight; the schedule stores that as the next day.
		$end_date = $dates['end_date'];
		if ( $end_date === $dates['start_date'] && '' !== $dates['end_time'] && $dates['end_time'] < $dates['start_time'] ) {
			$end_date = Dates::add_days( $end_date, 1 );
		}
		return $dates['start_date'] . ' ' . $dates['start_time'] . ' to ' . $end_date . ' ' . $dates['end_time'];
	}

	private static function signature_of( Occurrence $occurrence ): string {
		if ( $occurrence->all_day ) {
			return $occurrence->start_date . ' to ' . $occurrence->end_date . ' all day';
		}
		return $occurrence->start_date . ' ' . $occurrence->start_time . ' to ' . $occurrence->end_date . ' ' . $occurrence->end_time;
	}

	/**
	 * Entry id from a prefix and a running number: "ems12a", "ems12b".
	 */
	private static function id( string $prefix, int $index ): string {
		return $prefix . chr( 97 + ( $index % 26 ) ) . ( $index >= 26 ? (string) intdiv( $index, 26 ) : '' );
	}
}
