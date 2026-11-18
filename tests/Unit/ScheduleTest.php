<?php
declare(strict_types=1);

namespace RMD\EventsOrganizer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\EventsOrganizer\Domain\Occurrence;
use RMD\EventsOrganizer\Domain\OccurrenceDiff;
use RMD\EventsOrganizer\Domain\Schedule;
use RMD\EventsOrganizer\Domain\Validator;

final class ScheduleTest extends TestCase {

	private const ZONE = 'Europe/Berlin';

	private static function schedule( array $raw ): array {
		return ( new Validator() )->normalize_schedule( $raw )['data'];
	}

	/**
	 * @param list<Occurrence> $occurrences
	 * @return list<string>
	 */
	private static function keys( array $occurrences ): array {
		return array_map( static fn ( Occurrence $o ): string => $o->key, $occurrences );
	}

	public function test_single_timed_date(): void {
		$occurrences = Schedule::expand(
			self::schedule(
				[
					'entries' => [
						[
							'id'         => 'aaaa',
							'type'       => 'single',
							'start_date' => '2026-07-04',
							'start_time' => '19:30',
							'end_time'   => '22:00',
						],
					],
				]
			),
			'2028-01-01',
			self::ZONE
		);

		self::assertCount( 1, $occurrences );
		self::assertSame( 'aaaa', $occurrences[0]->key );
		self::assertSame( '2026-07-04 17:30:00', $occurrences[0]->start_utc );
		self::assertSame( '2026-07-04 20:00:00', $occurrences[0]->end_utc );
		self::assertFalse( $occurrences[0]->all_day );
	}

	public function test_entry_without_time_is_all_day_with_exclusive_end(): void {
		$occurrences = Schedule::expand(
			self::schedule(
				[
					'entries' => [
						[
							'id'         => 'aaaa',
							'type'       => 'single',
							'start_date' => '2026-12-12',
							'end_date'   => '2026-12-14',
						],
					],
				]
			),
			'2028-01-01',
			self::ZONE
		);

		self::assertTrue( $occurrences[0]->all_day );
		self::assertSame( '2026-12-11 23:00:00', $occurrences[0]->start_utc );
		self::assertSame( '2026-12-14 23:00:00', $occurrences[0]->end_utc );
		self::assertNull( $occurrences[0]->to_row()['start_time'] );
	}

	public function test_end_before_start_on_one_day_runs_past_midnight(): void {
		$occurrences = Schedule::expand(
			self::schedule(
				[
					'entries' => [
						[
							'id'         => 'aaaa',
							'type'       => 'single',
							'start_date' => '2026-07-04',
							'start_time' => '20:00',
							'end_time'   => '01:00',
						],
					],
				]
			),
			'2028-01-01',
			self::ZONE
		);

		self::assertSame( '2026-07-05', $occurrences[0]->end_date );
		self::assertSame( '2026-07-04 23:00:00', $occurrences[0]->end_utc );
	}

	public function test_market_with_daily_hours(): void {
		$occurrences = Schedule::expand(
			self::schedule(
				[
					'entries' => [
						[
							'id'         => 'aaaa',
							'type'       => 'single',
							'start_date' => '2026-12-12',
							'end_date'   => '2026-12-14',
							'start_time' => '10:00',
							'end_time'   => '18:00',
							'time_mode'  => 'daily',
						],
					],
				]
			),
			'2028-01-01',
			self::ZONE
		);

		self::assertSame( 'daily', $occurrences[0]->time_mode );
		self::assertSame( '2026-12-12 09:00:00', $occurrences[0]->start_utc );
		self::assertSame( '2026-12-14 17:00:00', $occurrences[0]->end_utc );
	}

	public function test_rule_keeps_wall_time_across_dst(): void {
		// Clocks go forward on 2026-03-29 in Europe/Berlin.
		$occurrences = Schedule::expand(
			self::schedule(
				[
					'entries' => [
						[
							'id'         => 'rrrr',
							'type'       => 'rule',
							'freq'       => 'weekly',
							'from'       => '2026-03-22',
							'count'      => 2,
							'start_time' => '10:00',
						],
					],
				]
			),
			'2028-01-01',
			self::ZONE
		);

		self::assertSame( [ 'rrrr:2026-03-22', 'rrrr:2026-03-29' ], self::keys( $occurrences ) );
		self::assertSame( '2026-03-22 09:00:00', $occurrences[0]->start_utc );
		self::assertSame( '2026-03-29 08:00:00', $occurrences[1]->start_utc );
		self::assertSame( '10:00', $occurrences[1]->start_time );
	}

	public function test_exclusions_and_overrides(): void {
		$occurrences = Schedule::expand(
			self::schedule(
				[
					'entries'    => [
						[
							'id'         => 'rrrr',
							'type'       => 'rule',
							'freq'       => 'weekly',
							'from'       => '2026-06-06',
							'until'      => '2026-06-27',
							'start_time' => '09:00',
							'end_time'   => '13:00',
						],
					],
					'exclusions' => [ '2026-06-13' ],
					'overrides'  => [
						'rrrr:2026-06-20' => [
							'status' => 'cancelled',
							'note'   => 'Rain',
						],
						'rrrr:2026-06-27' => [ 'start_time' => '11:00' ],
						'gone:2026-06-27' => [ 'status' => 'cancelled' ],
					],
				]
			),
			'2028-01-01',
			self::ZONE
		);

		self::assertSame( [ 'rrrr:2026-06-06', 'rrrr:2026-06-20', 'rrrr:2026-06-27' ], self::keys( $occurrences ) );
		self::assertSame( Occurrence::STATUS_CANCELLED, $occurrences[1]->status );
		self::assertSame( 'Rain', $occurrences[1]->note );
		self::assertSame( '11:00', $occurrences[2]->start_time );
		self::assertSame( '13:00', $occurrences[2]->end_time );
	}

	public function test_rule_duration_spans_days(): void {
		$occurrences = Schedule::expand(
			self::schedule(
				[
					'entries' => [
						[
							'id'            => 'rrrr',
							'type'          => 'rule',
							'freq'          => 'yearly',
							'from'          => '2026-12-04',
							'count'         => 2,
							'duration_days' => 3,
						],
					],
				]
			),
			'2030-01-01',
			self::ZONE
		);

		self::assertSame( '2026-12-06', $occurrences[0]->end_date );
		self::assertSame( '2027-12-06', $occurrences[1]->end_date );
	}

	public function test_occurrences_are_sorted_and_capped_keeping_the_latest(): void {
		$occurrences = Schedule::expand(
			self::schedule(
				[
					'entries' => [
						[
							'id'         => 'late',
							'type'       => 'single',
							'start_date' => '2026-01-10',
						],
						[
							'id'   => 'rrrr',
							'type' => 'rule',
							'freq' => 'daily',
							'from' => '2026-01-01',
						],
					],
				]
			),
			'2026-01-20',
			self::ZONE,
			5
		);

		self::assertCount( 5, $occurrences );
		self::assertSame( 'rrrr:2026-01-16', $occurrences[0]->key );
		self::assertSame( 'rrrr:2026-01-20', $occurrences[4]->key );
	}

	public function test_open_ended_detection(): void {
		self::assertTrue(
			Schedule::is_open_ended(
				self::schedule(
					[
						'entries' => [
							[
								'type' => 'rule',
								'freq' => 'daily',
								'from' => '2026-01-01',
							],
						],
					]
				)
			)
		);
		self::assertFalse(
			Schedule::is_open_ended(
				self::schedule(
					[
						'entries' => [
							[
								'type'  => 'rule',
								'freq'  => 'daily',
								'from'  => '2026-01-01',
								'count' => 3,
							],
						],
					]
				)
			)
		);
	}

	public function test_diff_keeps_ids_when_only_the_time_changes(): void {
		$raw = [
			'entries' => [
				[
					'id'         => 'rrrr',
					'type'       => 'rule',
					'freq'       => 'weekly',
					'from'       => '2026-06-06',
					'count'      => 3,
					'start_time' => '09:00',
				],
			],
		];

		$stored = [];
		foreach ( Schedule::expand( self::schedule( $raw ), '2028-01-01', self::ZONE ) as $index => $occurrence ) {
			$stored[ $occurrence->key ] = [ 'id' => (string) ( $index + 1 ) ] + array_map(
				static fn ( $value ) => null === $value ? null : (string) $value,
				$occurrence->to_row()
			);
		}

		$raw['entries'][0]['start_time'] = '10:00';
		$raw['entries'][0]['count']      = 2;
		$raw['entries'][]                = [
			'id'         => 'ssss',
			'type'       => 'single',
			'start_date' => '2026-08-01',
		];
		$desired                         = array_map(
			static fn ( Occurrence $o ): array => $o->to_row(),
			Schedule::expand( self::schedule( $raw ), '2028-01-01', self::ZONE )
		);

		$diff = OccurrenceDiff::compute( $desired, $stored );

		self::assertSame( [ 'ssss' ], array_column( $diff['insert'], 'occ_key' ) );
		self::assertSame( [ 1, 2 ], array_keys( $diff['update'] ) );
		self::assertSame( [ 'start_time', 'start_utc', 'end_utc' ], array_keys( $diff['update'][1] ) );
		self::assertSame( [ 3 ], $diff['delete'] );
	}

	public function test_diff_is_empty_when_nothing_changed(): void {
		$occurrences = Schedule::expand(
			self::schedule(
				[
					'entries' => [
						[
							'id'         => 'aaaa',
							'type'       => 'single',
							'start_date' => '2026-12-12',
						],
					],
				]
			),
			'2028-01-01',
			self::ZONE
		);
		$row         = $occurrences[0]->to_row();
		$stored      = [
			'aaaa' => [ 'id' => '7' ] + array_map( static fn ( $value ) => null === $value ? null : (string) $value, $row ),
		];

		self::assertSame(
			[
				'insert' => [],
				'update' => [],
				'delete' => [],
			],
			OccurrenceDiff::compute( [ $row ], $stored )
		);
	}
}
