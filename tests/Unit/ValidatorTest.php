<?php
declare(strict_types=1);

namespace RMD\EventsOrganizer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Domain\Settings;
use RMD\EventsOrganizer\Domain\Validator;

final class ValidatorTest extends TestCase {

	public function test_only_a_market_can_take_place_without_us(): void {
		$validator = new Validator();

		self::assertSame( 'withdrawn', $validator->normalize_details( [ 'status' => 'withdrawn' ], 'market' )['data']['status'] );
		self::assertSame( 'scheduled', $validator->normalize_details( [ 'status' => 'withdrawn' ], 'event' )['data']['status'] );
		self::assertSame( 'cancelled', $validator->normalize_details( [ 'status' => 'cancelled' ], 'event' )['data']['status'] );
	}

	public function test_a_single_date_can_be_marked_as_without_us(): void {
		$schedule = ( new Validator() )->normalize_schedule(
			[
				'entries'   => [
					[
						'id'         => 'aaaa',
						'type'       => 'single',
						'start_date' => '2026-12-12',
					],
				],
				'overrides' => [ 'aaaa' => [ 'status' => 'withdrawn' ] ],
			]
		)['data'];

		self::assertSame( 'withdrawn', $schedule['overrides']['aaaa']['status'] );
		self::assertSame( 'withdrawn', \RMD\EventsOrganizer\Domain\Schedule::expand( $schedule, '2027-12-31', 'Europe/Berlin' )[0]->status );
	}

	private static function validator(): Validator {
		$next = 0;
		return new Validator(
			null,
			null,
			static function () use ( &$next ): string {
				++$next;
				return sprintf( 'id%02d', $next );
			}
		);
	}

	/**
	 * @return list<string>
	 */
	private static function codes( array $result ): array {
		return array_map( static fn ( array $error ): string => $error['path'] . ':' . $error['code'], $result['errors'] );
	}

	public function test_garbage_becomes_an_empty_schedule(): void {
		$result = self::validator()->normalize_schedule( 'nope' );

		self::assertSame( Validator::empty_schedule(), $result['data'] );
		self::assertSame( [], $result['errors'] );
	}

	public function test_ids_are_kept_generated_and_made_unique(): void {
		$result = self::validator()->normalize_schedule(
			[
				'entries' => [
					[
						'id'         => 'keep',
						'start_date' => '2026-05-01',
					],
					[
						'id'         => 'keep',
						'start_date' => '2026-05-02',
					],
					[
						'id'         => 'Bad Id!',
						'start_date' => '2026-05-03',
					],
				],
			]
		);

		self::assertSame( [ 'keep', 'id01', 'id02' ], array_column( $result['data']['entries'], 'id' ) );
	}

	public function test_single_entry_errors(): void {
		$result = self::validator()->normalize_schedule(
			[
				'entries' => [
					[ 'start_date' => '2026-02-30' ],
					[
						'start_date' => '2026-05-10',
						'end_date'   => '2026-05-01',
						'start_time' => '25:00',
					],
				],
			]
		);

		self::assertSame(
			[ 'entries.0.start_date:invalid_date', 'entries.1.end_date:end_before_start', 'entries.1.start_time:invalid_time' ],
			self::codes( $result )
		);
		self::assertCount( 1, $result['data']['entries'] );
		self::assertSame( '2026-05-10', $result['data']['entries'][0]['end_date'] );
		self::assertTrue( $result['data']['entries'][0]['all_day'] );
	}

	public function test_daily_mode_needs_several_days_and_an_end_time(): void {
		$result = self::validator()->normalize_schedule(
			[
				'entries' => [
					[
						'start_date' => '2026-05-01',
						'start_time' => '10:00',
						'end_time'   => '18:00',
						'time_mode'  => 'daily',
					],
					[
						'start_date' => '2026-05-01',
						'end_date'   => '2026-05-03',
						'start_time' => '10:00',
						'time_mode'  => 'daily',
					],
					[
						'start_date' => '2026-05-01',
						'end_date'   => '2026-05-03',
						'start_time' => '10:00',
						'end_time'   => '18:00',
						'time_mode'  => 'daily',
					],
				],
			]
		);

		self::assertSame( [ 'continuous', 'continuous', 'daily' ], array_column( $result['data']['entries'], 'time_mode' ) );
		self::assertSame( [ 'entries.1.end_time:daily_needs_end' ], self::codes( $result ) );
	}

	public function test_rule_is_normalized(): void {
		$result = self::validator()->normalize_schedule(
			[
				'entries' => [
					[
						'type'          => 'rule',
						'freq'          => 'monthly',
						'interval'      => 500,
						'weekdays'      => [ 'sun', 'xyz', 'mon' ],
						'month_mode'    => 'nth_weekday',
						'nth'           => 9,
						'from'          => '2026-01-10',
						'until'         => '2025-01-01',
						'count'         => -4,
						'duration_days' => 0,
					],
					[
						'type' => 'rule',
						'freq' => 'sometimes',
						'from' => '2026-01-10',
					],
				],
			]
		);

		$rule = $result['data']['entries'][0];
		self::assertSame( Validator::MAX_INTERVAL, $rule['interval'] );
		self::assertSame( [ 'mon', 'sun' ], $rule['weekdays'] );
		self::assertSame( 0, $rule['nth'] );
		self::assertSame( '', $rule['until'] );
		self::assertSame( 0, $rule['count'] );
		self::assertSame( 1, $rule['duration_days'] );
		self::assertSame( [ 'entries.0.until:end_before_start', 'entries.1.freq:invalid_choice' ], self::codes( $result ) );
	}

	public function test_exclusions_are_sorted_and_deduplicated(): void {
		$result = self::validator()->normalize_schedule(
			[
				'exclusions' => [
					'2026-08-01',
					[
						'from' => '2026-07-10',
						'to'   => '2026-07-01',
					],
					[ 'date' => '2026-08-01' ],
					'tomorrow',
				],
			]
		);

		self::assertSame(
			[
				[
					'from' => '2026-07-01',
					'to'   => '2026-07-10',
				],
				[
					'from' => '2026-08-01',
					'to'   => '2026-08-01',
				],
			],
			$result['data']['exclusions']
		);
		self::assertSame( [ 'exclusions.3:invalid_date' ], self::codes( $result ) );
	}

	public function test_overrides_of_unknown_entries_and_empty_overrides_are_dropped(): void {
		$result = self::validator()->normalize_schedule(
			[
				'entries'   => [
					[
						'id'         => 'aaaa',
						'start_date' => '2026-05-01',
					],
				],
				'overrides' => [
					'aaaa'            => [ 'note' => ' <b>Moved</b> indoors ' ],
					'aaaa:2026-05-08' => [ 'status' => 'scheduled' ],
					'aaaa:someday'    => [ 'status' => 'cancelled' ],
					'zzzz'            => [ 'status' => 'cancelled' ],
				],
			]
		);

		self::assertSame( [ 'aaaa' ], array_keys( $result['data']['overrides'] ) );
		self::assertSame( 'Moved indoors', $result['data']['overrides']['aaaa']['note'] );
	}

	public function test_details_depend_on_the_kind(): void {
		$validator = self::validator();
		$raw       = [
			'status'        => 'cancelled',
			'organizer_url' => 'javascript:alert(1)',
			'info_url'      => 'https://example.org/markt',
			'stall'         => 'Stand 12',
			'unknown'       => 'x',
		];

		$event  = $validator->normalize_details( $raw, Kinds::EVENT );
		$market = $validator->normalize_details( $raw, Kinds::MARKET );

		self::assertArrayNotHasKey( 'stall', $event['data'] );
		self::assertArrayNotHasKey( 'unknown', $event['data'] );
		self::assertSame( 'cancelled', $event['data']['status'] );
		self::assertSame( '', $event['data']['organizer_url'] );
		self::assertSame( [ 'details.organizer_url:invalid_url' ], self::codes( $event ) );
		self::assertSame( 'Stand 12', $market['data']['stall'] );
		self::assertSame( 'https://example.org/markt', $market['data']['info_url'] );
	}

	public function test_address(): void {
		$result = self::validator()->normalize_address(
			[
				'street'  => " Obermarkt 1\n",
				'city'    => 'Freiberg',
				'country' => 'de',
				'lat'     => '50,9167',
				'lng'     => '13.3417000',
			]
		);

		self::assertSame( 'Obermarkt 1', $result['data']['street'] );
		self::assertSame( 'DE', $result['data']['country'] );
		self::assertSame( '50.9167', $result['data']['lat'] );
		self::assertSame( '13.3417', $result['data']['lng'] );

		$half = self::validator()->normalize_address( [ 'lat' => '95' ] + $result['data'] );
		self::assertSame( '', $half['data']['lat'] );
		self::assertSame( '', $half['data']['lng'] );
	}

	public function test_settings_fall_back_to_defaults(): void {
		$parsed = Settings::parse(
			[
				'modules'    => [
					'markets' => '1',
					'unknown' => true,
				],
				'roles'      => [ 'editor', 'Not A Role', 'editor' ],
				'permalinks' => [
					'event_slug'    => ' Termine / Alle ',
					'location_slug' => 'termine/alle',
				],
				'display'    => [ 'list_limit' => 5000 ],
				'calendar'   => [ 'horizon_months' => 'many' ],
				'pages'      => [ 'events' => '-3' ],
			]
		);

		self::assertTrue( $parsed['modules']['markets'] );
		self::assertTrue( $parsed['modules']['calendar'] );
		self::assertArrayNotHasKey( 'unknown', $parsed['modules'] );
		self::assertSame( [ 'editor' ], $parsed['roles'] );
		self::assertSame( 'termine/alle', $parsed['permalinks']['event_slug'] );
		self::assertSame( 'termine/alle-2', $parsed['permalinks']['location_slug'] );
		self::assertSame( Settings::MAX_LIMIT, $parsed['display']['list_limit'] );
		self::assertSame( 24, $parsed['calendar']['horizon_months'] );
		self::assertSame( 0, $parsed['pages']['events'] );
		self::assertSame( Settings::defaults(), Settings::parse( null ) );
	}
}
