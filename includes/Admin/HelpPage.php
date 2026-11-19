<?php
/**
 * Events → Help: how to enter events and show them on the website.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Admin;

use RMD\EventsOrganizer\Capabilities;
use RMD\EventsOrganizer\Modules;
use RMD\EventsOrganizer\PostTypes;

defined( 'ABSPATH' ) || exit;

final class HelpPage {

	public const SLUG = 'rmd-eo-help';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 20 );
	}

	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostTypes::EVENT,
			__( 'Events Help', 'rmd-events-organizer' ),
			__( 'Help', 'rmd-events-organizer' ),
			Capabilities::CAP,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'rmd-events-organizer' ) );
		}
		$markets  = Modules::is_on( Modules::MARKETS );
		$calendar = Modules::is_on( Modules::CALENDAR );

		echo '<div class="wrap rmd-eo-help"><h1>' . esc_html__( 'Events Help', 'rmd-events-organizer' ) . '</h1>';

		echo '<h2>' . esc_html__( 'Entering an event', 'rmd-events-organizer' ) . '</h2><ol>';
		echo '<li>' . esc_html__( 'Choose Events → Add event and enter the name.', 'rmd-events-organizer' ) . '</li>';
		echo '<li>' . esc_html__( 'Under “When” enter the date. Leave the times empty for an all-day event. Use “Add date” for further dates of the same event.', 'rmd-events-organizer' ) . '</li>';
		echo '<li>' . esc_html__( 'For something that takes place regularly choose “Repeats” and set the rhythm, for example every week on Tuesday or every first Saturday of the month.', 'rmd-events-organizer' ) . '</li>';
		echo '<li>' . esc_html__( 'The preview lists the next dates. There you can cancel a single date or leave it out without changing the rule.', 'rmd-events-organizer' ) . '</li>';
		echo '<li>' . esc_html__( 'Choose a location or type a new one, add a description and an image if you like, then publish.', 'rmd-events-organizer' ) . '</li>';
		echo '</ol>';

		if ( $markets ) {
			echo '<h2>' . esc_html__( 'Market dates', 'rmd-events-organizer' ) . '</h2>';
			echo '<p>' . esc_html__( 'Use Events → Add market date for markets where you have a stall. Enter the first and last day and choose “Same hours every day” for the daily opening hours. You can also note where your stall is.', 'rmd-events-organizer' ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Showing events on the website', 'rmd-events-organizer' ) . '</h2>';
		echo '<p>' . esc_html__( 'In the block editor add one of these blocks. Each block has its options in the sidebar.', 'rmd-events-organizer' ) . '</p><ul class="ul-disc">';
		echo '<li><strong>' . esc_html__( 'Events', 'rmd-events-organizer' ) . '</strong> – ' . esc_html__( 'a list of dates.', 'rmd-events-organizer' ) . '</li>';
		if ( $markets ) {
			echo '<li><strong>' . esc_html__( 'Market Dates', 'rmd-events-organizer' ) . '</strong> – ' . esc_html__( 'the markets where visitors can find you.', 'rmd-events-organizer' ) . '</li>';
		}
		if ( $calendar ) {
			echo '<li><strong>' . esc_html__( 'Events Calendar', 'rmd-events-organizer' ) . '</strong> – ' . esc_html__( 'a month calendar, or a small one that only marks the days (for sidebars).', 'rmd-events-organizer' ) . '</li>';
		}
		echo '<li><strong>' . esc_html__( 'Event Details', 'rmd-events-organizer' ) . '</strong> – ' . esc_html__( 'dates and location of one event.', 'rmd-events-organizer' ) . '</li>';
		echo '</ul>';

		echo '<h2>' . esc_html__( 'Shortcodes', 'rmd-events-organizer' ) . '</h2>';
		echo '<table class="widefat striped rmd-eo-help__table"><thead><tr><th>' . esc_html__( 'Shortcode', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Shows', 'rmd-events-organizer' ) . '</th></tr></thead><tbody>';
		self::row( '[rmd_events]', __( 'The upcoming dates.', 'rmd-events-organizer' ) );
		self::row( '[rmd_events limit="5" category="kurse" group="month"]', __( 'Five dates of one category, with a heading per month.', 'rmd-events-organizer' ) );
		self::row( '[rmd_events scope="past" pagination="yes"]', __( 'Past dates, newest first, with paging.', 'rmd-events-organizer' ) );
		self::row( '[rmd_events unique="yes" show="date,time,location,excerpt,image"]', __( 'Each event once with its next date, plus excerpt and image.', 'rmd-events-organizer' ) );
		if ( $markets ) {
			self::row( '[rmd_markets]', __( 'The upcoming market dates.', 'rmd-events-organizer' ) );
		}
		if ( $calendar ) {
			self::row( '[rmd_events_calendar]', __( 'The month calendar.', 'rmd-events-organizer' ) );
			self::row( '[rmd_events_calendar size="mini"]', __( 'The small calendar.', 'rmd-events-organizer' ) );
		}
		self::row( '[rmd_event id="123"]', __( 'Dates and location of the event with this id.', 'rmd-events-organizer' ) );
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Attributes of [rmd_events]', 'rmd-events-organizer' ) . '</h3>';
		echo '<table class="widefat striped rmd-eo-help__table"><tbody>';
		self::row( 'scope', __( 'upcoming (default), past, all, today, tomorrow, month, next-month', 'rmd-events-organizer' ) );
		self::row( 'from, to', __( 'A fixed period, each as YYYY-MM-DD.', 'rmd-events-organizer' ) );
		self::row( 'limit', __( 'Number of dates.', 'rmd-events-organizer' ) );
		self::row( 'category, tag', __( 'Slugs, several separated by commas.', 'rmd-events-organizer' ) );
		self::row( 'exclude_category', __( 'Categories to leave out.', 'rmd-events-organizer' ) );
		self::row( 'location', __( 'Id of a location.', 'rmd-events-organizer' ) );
		self::row( 'kind', __( 'event or market.', 'rmd-events-organizer' ) );
		self::row( 'unique', __( 'yes: each event only once, with its next date.', 'rmd-events-organizer' ) );
		self::row( 'group', __( 'month or year: adds headings.', 'rmd-events-organizer' ) );
		self::row( 'show', __( 'Parts to show: date, time, location, stall, excerpt, image, category.', 'rmd-events-organizer' ) );
		self::row( 'pagination', __( 'yes: links to earlier and later dates.', 'rmd-events-organizer' ) );
		self::row( 'empty', __( 'Text shown when there are no dates.', 'rmd-events-organizer' ) );
		echo '</tbody></table></div>';
	}

	private static function row( string $code, string $text ): void {
		echo '<tr><td><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( $text ) . '</td></tr>';
	}
}
