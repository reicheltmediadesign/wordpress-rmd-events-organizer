<?php
declare(strict_types=1);

namespace RMD\EventsOrganizer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\EventsOrganizer\Domain\Em\ContentConverter;
use RMD\EventsOrganizer\Domain\Em\ShortcodeMapper;

final class EmContentConverterTest extends TestCase {

	/**
	 * @return array<string, callable>
	 */
	private function resolve(): array {
		return [
			'category'   => static fn( string $key ): string => [
				'7'        => 'konzerte',
				'8'        => 'kurse',
				'konzerte' => 'konzerte',
			][ $key ] ?? '',
			'tag'        => static fn( string $key ): string => 'familie' === $key ? 'familie' : '',
			'location'   => static fn( int $id ): int => 3 === $id ? 30 : 0,
			'event'      => static fn( int $id ): int => 11 === $id ? 110 : 0,
			'event_post' => static fn( int $id ): int => 500 === $id ? 110 : 0,
		];
	}

	private function convert( string $content, string $role = '' ): array {
		return ContentConverter::convert( $content, $this->resolve(), $role );
	}

	public function test_list_with_scope_limit_category_and_pagination(): void {
		$result = $this->convert( 'Vorher [events_list scope="past" limit="5" category="7" pagination="1"] nachher' );

		self::assertSame( 'Vorher [rmd_events scope="past" limit="5" category="konzerte" pagination="yes"] nachher', $result['content'] );
		self::assertCount( 1, $result['changes'] );
		self::assertSame( [], $result['changes'][0]['notes'] );
	}

	public function test_default_scope_is_left_out(): void {
		self::assertSame( '[rmd_events limit="3"]', $this->convert( '[events_list scope="future" limit="3"]' )['content'] );
	}

	public function test_date_range_becomes_from_and_to(): void {
		self::assertSame( '[rmd_events from="2026-01-05" to="2026-03-31"]', $this->convert( '[events_list scope="2026-1-5,2026-03-31"]' )['content'] );
		self::assertSame( '[rmd_events from="2026-05-01" to="2026-05-01"]', $this->convert( '[events_list scope="2026-05-01"]' )['content'] );
	}

	public function test_unknown_scope_is_reported(): void {
		$result = $this->convert( '[events_list scope="3-months"]' );

		self::assertSame( '[rmd_events]', $result['content'] );
		self::assertSame( [ [ ShortcodeMapper::NOTE_SCOPE, '3-months' ] ], $result['changes'][0]['notes'] );
	}

	public function test_negative_category_is_excluded_and_unknown_one_reported(): void {
		$result = $this->convert( '[events_list category="7,-8,99"]' );

		self::assertSame( '[rmd_events category="konzerte" exclude_category="kurse"]', $result['content'] );
		self::assertSame( [ [ ShortcodeMapper::NOTE_FILTER, 'category="99"' ] ], $result['changes'][0]['notes'] );
	}

	public function test_enclosed_format_is_dropped_and_reported(): void {
		$result = $this->convert( '[events_list limit="3" order="DESC"]<li>#_EVENTLINK</li>[/events_list] Text' );

		self::assertSame( '[rmd_events limit="3" order="desc"] Text', $result['content'] );
		self::assertSame( [ [ ShortcodeMapper::NOTE_FORMAT, '' ] ], $result['changes'][0]['notes'] );
	}

	public function test_grouped_list(): void {
		self::assertSame( '[rmd_events group="year"]', $this->convert( '[events_list_grouped mode="yearly"]' )['content'] );
		self::assertSame( '[rmd_events group="month"]', $this->convert( '[events_list_grouped]' )['content'] );
	}

	public function test_calendar_small_by_default_and_full_on_request(): void {
		self::assertSame( '[rmd_events_calendar size="mini"]', $this->convert( '[events_calendar]' )['content'] );
		self::assertSame( '[rmd_events_calendar size="full" month="2026-12" category="konzerte"]', $this->convert( "[events_calendar full=1 long_events=1 month='12' year='2026' category='konzerte']" )['content'] );
	}

	public function test_single_event_by_id_and_by_post(): void {
		self::assertSame( '[rmd_event id="110"]', $this->convert( '[event event="11"]' )['content'] );
		self::assertSame( '[rmd_event id="110"]', $this->convert( '[event post_id="500"]#_EVENTNAME[/event]' )['content'] );
	}

	public function test_unknown_event_stays(): void {
		$result = $this->convert( '[event post_id="501"]' );

		self::assertSame( '[event post_id="501"]', $result['content'] );
		self::assertSame( '', $result['changes'][0]['to'] );
		self::assertSame( ShortcodeMapper::NOTE_EVENT, $result['changes'][0]['notes'][0][0] );
	}

	public function test_shortcodes_without_counterpart_stay_and_are_reported(): void {
		$content = "[locations_list limit=\"10\"]\n[locations_map width=\"100%\"]\n[event_form]\n[location post_id=\"4\"]";
		$result  = $this->convert( $content );

		self::assertSame( $content, $result['content'] );
		self::assertCount( 4, $result['changes'] );
		foreach ( $result['changes'] as $change ) {
			self::assertSame( '', $change['to'] );
			self::assertSame( ShortcodeMapper::NOTE_NONE, $change['notes'][0][0] );
		}
	}

	public function test_similar_names_and_escaped_shortcodes_are_left_alone(): void {
		$content = '[events_listing] [eventbrite id="1"] [[events_list]] [rmd_events]';
		$result  = $this->convert( $content );

		self::assertSame( $content, $result['content'] );
		self::assertSame( [], $result['changes'] );
	}

	public function test_converting_twice_changes_nothing_more(): void {
		$once = $this->convert( '[events_list limit="5"] [events_calendar full="1"]' )['content'];

		self::assertSame( $once, $this->convert( $once )['content'] );
		self::assertFalse( ContentConverter::concerns( $once ) );
	}

	public function test_events_block_becomes_our_block(): void {
		$result = $this->convert( '<!-- wp:events-manager/events {"scope":"past","limit":3,"category":"7","title":"Rückblick"} /-->' );

		self::assertSame(
			"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Rückblick</h2>\n<!-- /wp:heading -->\n\n" . '<!-- wp:rmd/events {"scope":"past","limit":3,"category":"konzerte"} /-->',
			$result['content']
		);
	}

	public function test_block_with_order_falls_back_to_a_shortcode_block(): void {
		$result = $this->convert( '<!-- wp:events-manager/events {"order":"DESC"} /-->' );

		self::assertSame( "<!-- wp:shortcode -->\n[rmd_events order=\"desc\"]\n<!-- /wp:shortcode -->", $result['content'] );
	}

	public function test_calendar_block_and_block_without_attributes(): void {
		self::assertSame( '<!-- wp:rmd/events-calendar {"size":"full"} /-->', $this->convert( '<!-- wp:events-manager/calendar /-->' )['content'] );
		self::assertSame( '<!-- wp:rmd/events-calendar {"size":"mini"} /-->', $this->convert( '<!-- wp:events-manager/calendar {"calendar_size":"small"} /-->' )['content'] );
	}

	public function test_locations_block_stays(): void {
		$content = '<!-- wp:events-manager/locations {"limit":5} /-->';
		$result  = $this->convert( $content );

		self::assertSame( $content, $result['content'] );
		self::assertSame( '', $result['changes'][0]['to'] );
	}

	public function test_events_page_placeholder_in_classic_and_block_content(): void {
		self::assertSame( 'Text [rmd_events pagination="yes"] Text', $this->convert( 'Text CONTENTS Text', 'events' )['content'] );
		self::assertSame(
			'<!-- wp:rmd/events {"pagination":true} /-->',
			$this->convert( "<!-- wp:paragraph -->\n<p>CONTENTS</p>\n<!-- /wp:paragraph -->", 'events' )['content']
		);
	}

	public function test_events_page_without_placeholder_gets_the_list_appended(): void {
		$result = $this->convert( '<p>Willkommen</p>', 'events' );

		self::assertSame( "<p>Willkommen</p>\n\n[rmd_events pagination=\"yes\"]", $result['content'] );
		self::assertSame( ContentConverter::NOTE_PAGE_WHOLE, $result['changes'][0]['notes'][0][0] );
		self::assertSame( $result['content'], $this->convert( $result['content'], 'events' )['content'] );
	}

	public function test_other_own_pages_are_only_reported(): void {
		$result = $this->convert( 'CONTENTS', 'locations' );

		self::assertSame( 'CONTENTS', $result['content'] );
		self::assertSame( [ [ ContentConverter::NOTE_PAGE, 'locations' ] ], $result['changes'][0]['notes'] );
	}

	public function test_typographic_quotes_in_attributes(): void {
		self::assertSame( '[rmd_events limit="4"]', $this->convert( '[events_list limit=“4”]' )['content'] );
	}
}
