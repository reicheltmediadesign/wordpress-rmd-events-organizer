<?php
declare(strict_types=1);

namespace RMD\EventsOrganizer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\EventsOrganizer\Domain\Csv\CsvPlanner;
use RMD\EventsOrganizer\Domain\Csv\CsvReader;
use RMD\EventsOrganizer\Domain\Validator;

final class CsvImportTest extends TestCase {

	private function plan( string $csv ): array {
		$parsed = CsvReader::parse( $csv );
		self::assertSame( '', $parsed['error'] );
		return CsvPlanner::plan( $parsed['rows'] );
	}

	public function test_german_header_with_semicolons_and_bom(): void {
		$parsed = CsvReader::parse( "\xEF\xBB\xBFTitel;Datum;Straße;Schlagwörter;Notiz\r\nKonzert;14.03.2027;Markt 1;a, b;egal\r\n" );

		self::assertSame( '', $parsed['error'] );
		self::assertSame( [ 'title', 'date', 'street', 'tags' ], $parsed['columns'] );
		self::assertSame( [ 'Notiz' ], $parsed['unknown'] );
		self::assertSame( 2, $parsed['rows'][0]['line'] );
		self::assertSame( 'Markt 1', $parsed['rows'][0]['cells']['street'] );
	}

	public function test_english_header_with_commas_and_quoted_cells(): void {
		$parsed = CsvReader::parse( "title,date,description\n\"Fest, gross\",2027-05-01,\"Zeile 1\nZeile 2 \"\"mit\"\" Zitat\"\n\nZweites,2027-05-02,\n" );

		self::assertCount( 2, $parsed['rows'] );
		self::assertSame( 'Fest, gross', $parsed['rows'][0]['cells']['title'] );
		self::assertSame( "Zeile 1\nZeile 2 \"mit\" Zitat", $parsed['rows'][0]['cells']['description'] );
		self::assertSame( 5, $parsed['rows'][1]['line'] );
	}

	public function test_windows_encoding_is_converted(): void {
		$parsed = CsvReader::parse( "titel;datum\nFr\xFChlingsfest;1.5.2027\n" );

		self::assertSame( 'Frühlingsfest', $parsed['rows'][0]['cells']['title'] );
	}

	public function test_file_without_the_needed_columns_is_refused(): void {
		self::assertSame( CsvReader::ERROR_EMPTY, CsvReader::parse( " \n" )['error'] );
		self::assertSame( CsvReader::ERROR_HEADER, CsvReader::parse( "name;tag\nA;B\n" )['error'] );
	}

	public function test_template_header_is_read_back_completely(): void {
		foreach ( [ true, false ] as $german ) {
			$parsed = CsvReader::parse( CsvReader::header( $german ) . "\nA;2027-01-01\n" );
			self::assertSame( array_keys( CsvReader::COLUMNS ), $parsed['columns'] );
			self::assertSame( [], $parsed['unknown'] );
		}
	}

	public function test_dates_and_times_in_usual_spellings(): void {
		self::assertSame( '2026-12-05', CsvPlanner::date( '5.12.2026' ) );
		self::assertSame( '2026-12-05', CsvPlanner::date( '05.12.26' ) );
		self::assertSame( '2026-12-05', CsvPlanner::date( '2026-12-5' ) );
		self::assertSame( '', CsvPlanner::date( '31.02.2026' ) );
		self::assertSame( '', CsvPlanner::date( '12/05/2026' ) );

		self::assertSame( '09:00', CsvPlanner::time( '9' ) );
		self::assertSame( '09:30', CsvPlanner::time( '9.30' ) );
		self::assertSame( '18:00', CsvPlanner::time( '18:00:00' ) );
		self::assertSame( '18:00', CsvPlanner::time( '18 Uhr' ) );
		self::assertSame( '', CsvPlanner::time( '' ) );
		self::assertNull( CsvPlanner::time( '25:00' ) );
		self::assertNull( CsvPlanner::time( 'abends' ) );
	}

	public function test_market_over_several_days_with_daily_hours(): void {
		$plan = $this->plan( "art;titel;datum;enddatum;beginn;ende;zeiten_taeglich;ort;stadt;kategorien;stand\nMarkt;Weihnachtsmarkt;12.12.2026;14.12.2026;10:00;18:00;ja;Obermarkt;Freiberg;Märkte, Advent, märkte;Stand 12\n" );

		self::assertSame( [], $plan['issues'] );
		$unit = $plan['units'][0];
		self::assertSame( 'market', $unit['kind'] );
		self::assertSame( 'publish', $unit['status'] );
		self::assertSame( [ 'Märkte', 'Advent' ], $unit['categories'] );
		self::assertSame( 'Obermarkt', $unit['location']['name'] );
		self::assertSame( 'Stand 12', $unit['details']['stall'] );
		self::assertSame( 'daily', $unit['entries'][0]['time_mode'] );
		self::assertSame( '2026-12-14', $unit['entries'][0]['end_date'] );

		// What the planner produces is what the validator accepts.
		$schedule = ( new Validator() )->normalize_schedule( [ 'entries' => $unit['entries'] ] );
		self::assertSame( [], $schedule['errors'] );
		self::assertSame( $unit['entries'], $schedule['data']['entries'] );
	}

	public function test_rows_with_the_same_key_are_dates_of_one_event(): void {
		$plan = $this->plan( "schluessel;titel;datum;beginn;status\nk1;Konzert;14.03.2027;19:30;Entwurf\n7;Lesung;01.04.2027;;\nk1;;21.03.2027;17:00;\n7;;02.04.2027;;\n" );

		self::assertSame( [], $plan['issues'] );
		self::assertCount( 2, $plan['units'] );
		self::assertSame( 'Konzert', $plan['units'][0]['title'] );
		self::assertSame( 'draft', $plan['units'][0]['status'] );
		self::assertSame( [ 2, 4 ], $plan['units'][0]['lines'] );
		self::assertSame( [ '2027-03-14', '2027-03-21' ], array_column( $plan['units'][0]['entries'], 'start_date' ) );
		self::assertSame( '7', $plan['units'][1]['key'] );
		self::assertTrue( $plan['units'][1]['entries'][0]['all_day'] );
	}

	public function test_dates_keep_their_id_between_imports(): void {
		$first  = $this->plan( "titel;datum;beginn\nA;1.5.2027;10:00\n" )['units'][0]['entries'][0]['id'];
		$second = $this->plan( "datum,title,start,end\n2027-05-01,A neu,10,12\n" )['units'][0]['entries'][0]['id'];

		self::assertSame( $first, $second );
		self::assertTrue( Validator::is_valid_id( $first ) );
	}

	public function test_rows_that_cannot_be_used_are_reported_and_left_out(): void {
		$plan = $this->plan(
			"schluessel;titel;datum;enddatum;beginn;ende;zeiten_taeglich;art;status\n"
			. ";;1.5.2027;;;;;;\n"                 // 2: no title.
			. ";A;;;;;;;\n"                        // 3: no date.
			. ";B;morgen;;;;;;\n"                  // 4: no valid date.
			. ";C;2.5.2027;1.5.2027;;;;;\n"        // 5: end before start.
			. ";D;2.5.2027;;abends;;;;\n"          // 6: no valid time.
			. ";E;2.5.2027;;;18:00;;;\n"           // 7: end without start.
			. ";F;2.5.2027;3.5.2027;10:00;;ja;;\n" // 8: daily hours without end.
			. ";G;2.5.2027;;;;;Messe;\n"           // 9: unknown kind.
			. ";H;2.5.2027;;;;;;geheim\n"          // 10: unknown status.
			. "k;I;2.5.2027;;10:00;;;;\n"          // 11: fine.
			. "k;;2.5.2027;;10:00;;;;\n"           // 12: same date again.
		);

		self::assertSame( [ 'I' ], array_column( $plan['units'], 'title' ) );
		self::assertSame(
			[
				2  => 'no_title',
				3  => 'no_date',
				4  => 'invalid_date',
				5  => 'end_before_start',
				6  => 'invalid_time',
				7  => 'end_without_start',
				8  => 'daily_needs_end',
				9  => 'invalid_kind',
				10 => 'invalid_status',
				12 => 'duplicate_date',
			],
			array_column( $plan['issues'], 'code', 'line' )
		);
		self::assertSame( 'Messe', $plan['issues'][7]['arg'] );
	}

	public function test_invalid_address_is_reported_but_the_event_kept(): void {
		$plan = $this->plan( "titel;datum;info_url;veranstalter_url\nA;1.5.2027;www.example.org;https://example.org/a\n" );

		self::assertCount( 1, $plan['units'] );
		self::assertSame( '', $plan['units'][0]['details']['info_url'] );
		self::assertSame( 'https://example.org/a', $plan['units'][0]['details']['organizer_url'] );
		self::assertSame( 'invalid_url', $plan['issues'][0]['code'] );
		self::assertSame( 'info_url', $plan['issues'][0]['arg'] );
		self::assertFalse( $plan['issues'][0]['skipped'] );
	}
}
