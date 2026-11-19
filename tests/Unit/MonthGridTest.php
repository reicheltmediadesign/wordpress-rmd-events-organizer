<?php
declare(strict_types=1);

namespace RMD\EventsOrganizer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\EventsOrganizer\Domain\IcsWriter;
use RMD\EventsOrganizer\Domain\MonthGrid;

final class MonthGridTest extends TestCase {

	public function test_month_starting_on_monday_week(): void {
		// December 2026 starts on a Tuesday and ends on a Thursday.
		$grid = MonthGrid::build( '2026-12', 1 );

		self::assertSame( '2026-11-30', $grid['from'] );
		self::assertSame( '2027-01-03', $grid['to'] );
		self::assertCount( 5, $grid['weeks'] );
		self::assertSame( [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ], $grid['weekdays'] );
		self::assertFalse( $grid['weeks'][0][0]['in_month'] );
		self::assertTrue( $grid['weeks'][0][1]['in_month'] );
	}

	public function test_week_starting_on_sunday(): void {
		$grid = MonthGrid::build( '2026-12', 0 );

		self::assertSame( '2026-11-29', $grid['from'] );
		self::assertSame( '2027-01-02', $grid['to'] );
		self::assertSame( 'sun', $grid['weekdays'][0] );
	}

	public function test_month_that_fills_whole_weeks_has_no_padding(): void {
		// February 2027 starts on a Monday and has 28 days.
		$grid = MonthGrid::build( '2027-02', 1 );

		self::assertSame( '2027-02-01', $grid['from'] );
		self::assertSame( '2027-02-28', $grid['to'] );
		self::assertCount( 4, $grid['weeks'] );
	}

	public function test_occurrences_are_assigned_to_every_day_they_cover(): void {
		$days = MonthGrid::by_day(
			[
				[
					'id'         => 1,
					'start_date' => '2026-11-28',
					'end_date'   => '2026-12-01',
				],
				[
					'id'         => 2,
					'start_date' => '2026-12-01',
					'end_date'   => '2026-12-01',
				],
			],
			'2026-11-30',
			'2027-01-03'
		);

		self::assertSame( [ '2026-11-30', '2026-12-01' ], array_keys( $days ) );
		self::assertSame( [ 1, 2 ], array_column( $days['2026-12-01'], 'id' ) );
	}

	public function test_ics_for_a_timed_event(): void {
		$ics = IcsWriter::calendar(
			[
				'uid'         => 'rmd-eo-7@example.org',
				'summary'     => 'Töpfermarkt; Frühjahr, 2027',
				'description' => "Line one\nLine two",
				'location'    => 'Obermarkt, Freiberg',
				'url'         => 'https://example.org/events/markt/',
				'all_day'     => false,
				'start_ts'    => gmmktime( 8, 0, 0, 5, 1, 2027 ),
				'end_ts'      => gmmktime( 16, 0, 0, 5, 1, 2027 ),
				'cancelled'   => false,
			],
			'-//Test//EN',
			gmmktime( 12, 0, 0, 1, 2, 2027 )
		);

		self::assertStringContainsString( "DTSTART:20270501T080000Z\r\n", $ics );
		self::assertStringContainsString( "DTEND:20270501T160000Z\r\n", $ics );
		self::assertStringContainsString( 'SUMMARY:Töpfermarkt\; Frühjahr\, 2027', $ics );
		self::assertStringContainsString( 'DESCRIPTION:Line one\nLine two', $ics );
		self::assertStringContainsString( "STATUS:CONFIRMED\r\n", $ics );
		self::assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
	}

	public function test_ics_all_day_end_is_exclusive(): void {
		$ics = IcsWriter::calendar(
			[
				'uid'        => 'x',
				'summary'    => 'Market',
				'all_day'    => true,
				'start_date' => '2026-12-12',
				'end_date'   => '2026-12-14',
				'cancelled'  => true,
			],
			'-//Test//EN',
			0
		);

		self::assertStringContainsString( 'DTSTART;VALUE=DATE:20261212', $ics );
		self::assertStringContainsString( 'DTEND;VALUE=DATE:20261215', $ics );
		self::assertStringContainsString( 'STATUS:CANCELLED', $ics );
	}

	public function test_ics_lines_are_folded_at_75_octets_without_breaking_characters(): void {
		$folded = IcsWriter::fold( 'SUMMARY:' . str_repeat( 'ä', 60 ) );
		$lines  = explode( "\r\n", $folded );

		self::assertGreaterThan( 1, count( $lines ) );
		foreach ( $lines as $line ) {
			self::assertLessThanOrEqual( 75, strlen( $line ) );
			self::assertSame( 1, preg_match( '//u', $line ) );
		}
		self::assertSame( 'SUMMARY:' . str_repeat( 'ä', 60 ), str_replace( "\r\n ", '', $folded ) );
	}
}
