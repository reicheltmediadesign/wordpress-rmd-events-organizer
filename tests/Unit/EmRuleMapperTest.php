<?php
declare(strict_types=1);

namespace RMD\EventsOrganizer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\EventsOrganizer\Domain\Em\RuleMapper;
use RMD\EventsOrganizer\Domain\Schedule;
use RMD\EventsOrganizer\Domain\Validator;

final class EmRuleMapperTest extends TestCase {

	public function test_weekly_every_week_becomes_one_rule(): void {
		$result = RuleMapper::map(
			[
				'freq'       => 'weekly',
				'interval'   => '1',
				'byday'      => '0,3',
				'start_date' => '2026-01-07',
				'end_date'   => '2026-01-25',
				'start_time' => '18:30:00',
				'end_time'   => '20:00:00',
			]
		);

		self::assertCount( 1, $result['entries'] );
		self::assertSame( [ 'wed', 'sun' ], $result['entries'][0]['weekdays'] );
		self::assertSame( '18:30', $result['entries'][0]['start_time'] );
		self::assertSame( 1, $result['entries'][0]['duration_days'] );
	}

	public function test_weekly_interval_reproduces_per_weekday_anchoring(): void {
		// Start on a Wednesday, every second week on Monday and Friday: the
		// source plugin yields Fri 9 Jan, Mon 12 Jan and then every 14 days each.
		$dates = RuleMapper::dates(
			[
				'freq'       => 'weekly',
				'interval'   => 2,
				'byday'      => '1,5',
				'start_date' => '2026-01-07',
				'end_date'   => '2026-02-10',
			],
			'2030-01-01'
		);

		self::assertSame( [ '2026-01-09', '2026-01-12', '2026-01-23', '2026-01-26', '2026-02-06', '2026-02-09' ], $dates );
	}

	public function test_monthly_nth_and_last_weekday(): void {
		$second_tuesday = RuleMapper::dates(
			[
				'freq'       => 'monthly',
				'interval'   => 1,
				'byday'      => '2',
				'byweekno'   => 2,
				'start_date' => '2026-01-01',
				'end_date'   => '2026-03-31',
			],
			'2030-01-01'
		);
		$last_sunday    = RuleMapper::dates(
			[
				'freq'       => 'monthly',
				'interval'   => 1,
				'byday'      => '0',
				'byweekno'   => -1,
				'start_date' => '2026-01-01',
				'end_date'   => '2026-03-31',
			],
			'2030-01-01'
		);

		self::assertSame( [ '2026-01-13', '2026-02-10', '2026-03-10' ], $second_tuesday );
		self::assertSame( [ '2026-01-25', '2026-02-22', '2026-03-29' ], $last_sunday );
	}

	public function test_duration_counts_added_days(): void {
		$result = RuleMapper::map(
			[
				'freq'       => 'yearly',
				'interval'   => 1,
				'duration'   => 2,
				'start_date' => '2026-12-04',
				'end_date'   => '2028-12-31',
				'all_day'    => 1,
			]
		);

		$schedule    = ( new Validator() )->normalize_schedule( [ 'entries' => $result['entries'] ] )['data'];
		$occurrences = Schedule::expand( $schedule, '2030-01-01', 'Europe/Berlin' );

		self::assertCount( 3, $occurrences );
		self::assertSame( '2026-12-06', $occurrences[0]->end_date );
		self::assertTrue( $occurrences[0]->all_day );
	}

	public function test_fixed_dates_become_single_entries(): void {
		$result = RuleMapper::map(
			[
				'freq'       => 'on',
				'dates'      => '2026-05-09, 2026-05-02,nonsense',
				'start_time' => '10:00:00',
			]
		);

		self::assertSame( [ '2026-05-02', '2026-05-09' ], array_column( $result['entries'], 'start_date' ) );
		self::assertSame( [ 'single', 'single' ], array_column( $result['entries'], 'type' ) );
	}

	public function test_unusable_rules_are_reported(): void {
		self::assertSame( [ 'unsupported_rule' ], RuleMapper::map( [ 'freq' => 'hourly' ] )['warnings'] );
		self::assertSame(
			[ 'weekly_without_days' ],
			RuleMapper::map(
				[
					'freq'       => 'weekly',
					'start_date' => '2026-01-01',
				]
			)['warnings']
		);
	}
}
