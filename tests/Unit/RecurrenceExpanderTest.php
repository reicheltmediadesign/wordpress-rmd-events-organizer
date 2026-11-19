<?php
declare(strict_types=1);

namespace RMD\EventsOrganizer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\EventsOrganizer\Domain\RecurrenceExpander;

final class RecurrenceExpanderTest extends TestCase {

	private static function rule( array $overrides ): array {
		return $overrides + [
			'freq'       => 'weekly',
			'interval'   => 1,
			'weekdays'   => [],
			'month_mode' => 'day_of_month',
			'nth'        => 0,
			'from'       => '2026-01-01',
			'until'      => '',
			'count'      => 0,
		];
	}

	public function test_daily_with_interval_and_until(): void {
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'freq'     => 'daily',
					'interval' => 3,
					'from'     => '2026-02-26',
					'until'    => '2026-03-08',
				]
			),
			'2030-01-01'
		);

		self::assertSame( [ '2026-02-26', '2026-03-01', '2026-03-04', '2026-03-07' ], $dates );
	}

	public function test_horizon_limits_open_ended_rules(): void {
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'freq' => 'daily',
					'from' => '2026-01-01',
				]
			),
			'2026-01-05'
		);

		self::assertCount( 5, $dates );
		self::assertSame( '2026-01-05', end( $dates ) );
	}

	public function test_count_wins_over_horizon(): void {
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'freq'  => 'daily',
					'count' => 3,
				]
			),
			'2030-01-01'
		);

		self::assertSame( [ '2026-01-01', '2026-01-02', '2026-01-03' ], $dates );
	}

	public function test_weekly_uses_weekday_of_start_when_none_given(): void {
		// 2026-01-01 is a Thursday.
		$dates = RecurrenceExpander::dates( self::rule( [ 'count' => 3 ] ), '2030-01-01' );

		self::assertSame( [ '2026-01-01', '2026-01-08', '2026-01-15' ], $dates );
	}

	public function test_weekly_skips_days_before_the_start(): void {
		// Starts on a Thursday; the Tuesday of that week is already over.
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'weekdays' => [ 'tue', 'sat' ],
					'count'    => 4,
				]
			),
			'2030-01-01'
		);

		self::assertSame( [ '2026-01-03', '2026-01-06', '2026-01-10', '2026-01-13' ], $dates );
	}

	public function test_weekly_interval_is_anchored_to_the_start_week(): void {
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'interval' => 2,
					'weekdays' => [ 'mon', 'fri' ],
					'from'     => '2026-01-07', // Wednesday.
					'count'    => 4,
				]
			),
			'2030-01-01'
		);

		// Week of 5 Jan (only Friday left), then week of 19 Jan, then 2 Feb.
		self::assertSame( [ '2026-01-09', '2026-01-19', '2026-01-23', '2026-02-02' ], $dates );
	}

	public function test_monthly_day_of_month_skips_short_months(): void {
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'freq'  => 'monthly',
					'from'  => '2026-01-31',
					'until' => '2026-06-30',
				]
			),
			'2030-01-01'
		);

		self::assertSame( [ '2026-01-31', '2026-03-31', '2026-05-31' ], $dates );
	}

	public function test_monthly_first_saturday(): void {
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'freq'       => 'monthly',
					'month_mode' => 'nth_weekday',
					'weekdays'   => [ 'sat' ],
					'nth'        => 1,
					'from'       => '2026-01-01',
					'count'      => 4,
				]
			),
			'2030-01-01'
		);

		self::assertSame( [ '2026-01-03', '2026-02-07', '2026-03-07', '2026-04-04' ], $dates );
	}

	public function test_monthly_fifth_weekday_is_skipped_when_missing(): void {
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'freq'       => 'monthly',
					'month_mode' => 'nth_weekday',
					'weekdays'   => [ 'sat' ],
					'nth'        => 5,
					'from'       => '2026-01-01',
					'until'      => '2026-08-31',
				]
			),
			'2030-01-01'
		);

		self::assertSame( [ '2026-01-31', '2026-05-30', '2026-08-29' ], $dates );
	}

	public function test_monthly_last_weekday(): void {
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'freq'       => 'monthly',
					'month_mode' => 'nth_weekday',
					'weekdays'   => [ 'sun' ],
					'nth'        => -1,
					'from'       => '2026-02-01',
					'count'      => 3,
				]
			),
			'2030-01-01'
		);

		self::assertSame( [ '2026-02-22', '2026-03-29', '2026-04-26' ], $dates );
	}

	public function test_nth_weekday_is_derived_from_the_start_date(): void {
		// 2026-01-17 is the third Saturday of January.
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'freq'       => 'monthly',
					'month_mode' => 'nth_weekday',
					'from'       => '2026-01-17',
					'count'      => 3,
				]
			),
			'2030-01-01'
		);

		self::assertSame( [ '2026-01-17', '2026-02-21', '2026-03-21' ], $dates );
	}

	public function test_yearly_leap_day_only_in_leap_years(): void {
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'freq'  => 'yearly',
					'from'  => '2024-02-29',
					'until' => '2032-12-31',
				]
			),
			'2040-01-01'
		);

		self::assertSame( [ '2024-02-29', '2028-02-29', '2032-02-29' ], $dates );
	}

	public function test_yearly_nth_weekday(): void {
		// First Saturday in December.
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'freq'       => 'yearly',
					'month_mode' => 'nth_weekday',
					'weekdays'   => [ 'sat' ],
					'nth'        => 1,
					'from'       => '2026-12-01',
					'count'      => 3,
				]
			),
			'2040-01-01'
		);

		self::assertSame( [ '2026-12-05', '2027-12-04', '2028-12-02' ], $dates );
	}

	public function test_invalid_rule_yields_nothing(): void {
		self::assertSame( [], RecurrenceExpander::dates( self::rule( [ 'freq' => 'hourly' ] ), '2030-01-01' ) );
		self::assertSame( [], RecurrenceExpander::dates( self::rule( [ 'from' => 'soon' ] ), '2030-01-01' ) );
	}

	public function test_hard_cap(): void {
		$dates = RecurrenceExpander::dates(
			self::rule(
				[
					'freq' => 'daily',
					'from' => '1990-01-01',
				]
			),
			'2090-01-01'
		);

		self::assertCount( RecurrenceExpander::MAX_DATES, $dates );
	}
}
