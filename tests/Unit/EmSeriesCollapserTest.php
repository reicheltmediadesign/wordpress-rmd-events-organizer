<?php
declare(strict_types=1);

namespace RMD\EventsOrganizer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\EventsOrganizer\Domain\Em\EventMapper;
use RMD\EventsOrganizer\Domain\Em\SeriesCollapser;

final class EmSeriesCollapserTest extends TestCase {

	private const ZONE = 'Europe/Berlin';

	private static function child( int $id, string $date, array $overrides = [] ): array {
		return $overrides + [
			'id'         => $id,
			'start_date' => $date,
			'end_date'   => $date,
			'start_time' => '18:00:00',
			'end_time'   => '19:30:00',
			'all_day'    => 0,
			'cancelled'  => false,
			'standalone' => false,
		];
	}

	private static function weekly_rule(): array {
		return [
			'set_id'     => 7,
			'type'       => 'include',
			'freq'       => 'weekly',
			'interval'   => 1,
			'byday'      => '2',
			'start_date' => '2026-06-02',
			'end_date'   => '2026-06-30',
			'start_time' => '18:00:00',
			'end_time'   => '19:30:00',
		];
	}

	public function test_all_day_and_missing_end_are_recognised(): void {
		self::assertTrue( EventMapper::times( '00:00:00', '23:59:59', false )['all_day'] );
		self::assertTrue( EventMapper::times( '00:00:00', '00:00:00', false )['all_day'] );
		self::assertTrue( EventMapper::times( '10:00:00', '12:00:00', true )['all_day'] );
		self::assertSame( '', EventMapper::times( '18:00:00', '18:00:00', false )['end_time'] );
		self::assertNull( EventMapper::dates( [ 'start_date' => '0000-00-00' ] ) );
	}

	public function test_untouched_series_becomes_one_rule(): void {
		$children = array_map( fn ( string $date ) => self::child( (int) substr( $date, 8 ), $date ), [ '2026-06-02', '2026-06-09', '2026-06-16', '2026-06-23', '2026-06-30' ] );
		$result   = SeriesCollapser::collapse( [ self::weekly_rule() ], $children, self::ZONE );

		self::assertSame( [], $result['mismatches'] );
		self::assertCount( 1, $result['schedule']['entries'] );
		self::assertSame( 'ems7a', $result['schedule']['entries'][0]['id'] );
		self::assertSame( [], $result['schedule']['exclusions'] );
		self::assertSame( [], $result['schedule']['overrides'] );
	}

	public function test_deleted_changed_cancelled_extra_and_edited_dates(): void {
		$children = [
			self::child( 1, '2026-06-02' ),
			// 9 June was deleted.
			self::child( 3, '2026-06-16', [ 'start_time' => '17:00:00' ] ),
			self::child( 4, '2026-06-23', [ 'cancelled' => true ] ),
			self::child( 5, '2026-06-30', [ 'standalone' => true ] ),
			self::child( 6, '2026-07-04' ),
		];
		$result   = SeriesCollapser::collapse( [ self::weekly_rule() ], $children, self::ZONE );

		self::assertSame( [], $result['mismatches'] );
		self::assertSame( [ 5 ], $result['standalone'] );
		self::assertSame(
			[ '2026-06-09', '2026-06-30' ],
			array_column( $result['schedule']['exclusions'], 'from' )
		);
		self::assertSame( '17:00', $result['schedule']['overrides']['ems7a:2026-06-16']['start_time'] );
		self::assertSame( 'cancelled', $result['schedule']['overrides']['ems7a:2026-06-23']['status'] );
		self::assertSame( [ 'ems7a', 'emc6' ], array_column( $result['schedule']['entries'], 'id' ) );
	}

	public function test_exclude_rule_and_interval_split(): void {
		$rules    = [
			[
				'set_id'     => 3,
				'type'       => 'include',
				'freq'       => 'weekly',
				'interval'   => 2,
				'byday'      => '1,5',
				'start_date' => '2026-01-07',
				'end_date'   => '2026-02-10',
				'start_time' => '19:00:00',
				'end_time'   => '21:00:00',
			],
			[
				'set_id' => 4,
				'type'   => 'exclude',
				'freq'   => 'on',
				'dates'  => '2026-01-23',
			],
		];
		$children = [];
		foreach ( [ '2026-01-09', '2026-01-12', '2026-01-26', '2026-02-06', '2026-02-09' ] as $index => $date ) {
			$children[] = self::child(
				$index + 1,
				$date,
				[
					'start_time' => '19:00:00',
					'end_time'   => '21:00:00',
				]
			);
		}
		$result = SeriesCollapser::collapse( $rules, $children, self::ZONE );

		self::assertSame( [], $result['mismatches'] );
		self::assertSame( [ 'ems3a', 'ems3b' ], array_column( $result['schedule']['entries'], 'id' ) );
		self::assertSame( [ '2026-01-23' ], array_column( $result['schedule']['exclusions'], 'from' ) );
	}

	public function test_unusable_rule_keeps_every_date_as_it_is(): void {
		$children = [ self::child( 1, '2026-06-02' ), self::child( 2, '2026-06-11', [ 'cancelled' => true ] ) ];
		$result   = SeriesCollapser::collapse(
			[
				[
					'set_id' => 1,
					'type'   => 'include',
					'freq'   => 'hourly',
				],
			],
			$children,
			self::ZONE
		);

		self::assertSame( [], $result['mismatches'] );
		self::assertSame( [ 'single', 'single' ], array_column( $result['schedule']['entries'], 'type' ) );
		self::assertContains( 'unsupported_rule', $result['warnings'] );
		self::assertSame( 'cancelled', $result['schedule']['overrides']['emc2']['status'] );
	}

	public function test_all_day_series_over_two_days(): void {
		$rule     = [
			'set_id'     => 9,
			'type'       => 'include',
			'freq'       => 'yearly',
			'interval'   => 1,
			'duration'   => 1,
			'all_day'    => 1,
			'start_date' => '2025-06-20',
			'end_date'   => '2027-06-20',
		];
		$children = [];
		foreach ( [ 2025, 2026, 2027 ] as $year ) {
			$children[] = self::child(
				$year,
				"$year-06-20",
				[
					'end_date'   => "$year-06-21",
					'start_time' => '00:00:00',
					'end_time'   => '23:59:59',
					'all_day'    => 1,
				]
			);
		}
		$result = SeriesCollapser::collapse( [ $rule ], $children, self::ZONE );

		self::assertSame( [], $result['mismatches'] );
		self::assertCount( 1, $result['schedule']['entries'] );
		self::assertSame( 2, $result['schedule']['entries'][0]['duration_days'] );
	}

	public function test_differences_are_reported(): void {
		$schedule   = SeriesCollapser::collapse( [ self::weekly_rule() ], [ self::child( 1, '2026-06-02' ) ], self::ZONE )['schedule'];
		$mismatches = SeriesCollapser::compare( $schedule, [ self::child( 1, '2026-06-02' ), self::child( 2, '2026-06-09' ) ], [], self::ZONE );

		self::assertSame( [ 'missing: 2026-06-09 18:00 to 2026-06-09 19:30' ], $mismatches );
	}
}
