<?php
/**
 * Shortcodes mirroring the blocks.
 *
 * [rmd_events scope="upcoming|past|all|today|tomorrow|month|next-month" from="" to="" limit="10" kind="event|market" category="" exclude_category="" tag="" location="" unique="yes|no" order="asc|desc" group="month|year" show="date,time,location,stall,excerpt,image,category" pagination="yes|no" empty="" class=""]
 * [rmd_markets …]                      Same attributes; lists market dates only.
 * [rmd_events_calendar size="full|mini" month="YYYY-MM" kind="" category="" tag="" location="" class=""]
 * [rmd_event id="123" title="yes|no" class=""]
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

use RMD\EventsOrganizer\Domain\Dates;
use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Render\CalendarRenderer;
use RMD\EventsOrganizer\Render\EventRenderer;
use RMD\EventsOrganizer\Render\ListRenderer;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		add_shortcode( 'rmd_events', [ self::class, 'events' ] );
		add_shortcode( 'rmd_markets', [ self::class, 'markets' ] );
		add_shortcode( 'rmd_events_calendar', [ self::class, 'calendar' ] );
		add_shortcode( 'rmd_event', [ self::class, 'event' ] );
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function events( $atts, ?string $content = null, string $tag = 'rmd_events' ): string {
		$atts = shortcode_atts(
			[
				'scope'            => 'upcoming',
				'from'             => '',
				'to'               => '',
				'limit'            => '',
				'kind'             => '',
				'category'         => '',
				'exclude_category' => '',
				'tag'              => '',
				'location'         => '',
				'unique'           => 'no',
				'order'            => '',
				'group'            => '',
				'show'             => '',
				'pagination'       => 'no',
				'empty'            => '',
				'class'            => '',
			],
			is_array( $atts ) ? $atts : [],
			$tag
		);

		return ListRenderer::render( self::list_args( $atts ) );
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function markets( $atts ): string {
		if ( ! Modules::is_on( Modules::MARKETS ) ) {
			return self::notice( __( 'RMD Events Organizer: market dates are switched off in the settings. This message is only visible to editors.', 'rmd-events-organizer' ) );
		}
		$atts         = is_array( $atts ) ? $atts : [];
		$atts['kind'] = Kinds::MARKET;
		return self::events( $atts, null, 'rmd_markets' );
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function calendar( $atts ): string {
		if ( ! Modules::is_on( Modules::CALENDAR ) ) {
			return self::notice( __( 'RMD Events Organizer: the calendar is switched off in the settings. This message is only visible to editors.', 'rmd-events-organizer' ) );
		}
		$atts = shortcode_atts(
			[
				'size'     => 'full',
				'month'    => '',
				'kind'     => '',
				'category' => '',
				'tag'      => '',
				'location' => '',
				'class'    => '',
			],
			is_array( $atts ) ? $atts : [],
			'rmd_events_calendar'
		);

		return CalendarRenderer::render(
			[
				'size'     => sanitize_key( $atts['size'] ),
				'month'    => Dates::is_valid_month( $atts['month'] ) ? $atts['month'] : '',
				'kind'     => sanitize_key( $atts['kind'] ),
				'category' => sanitize_text_field( $atts['category'] ),
				'tag'      => sanitize_text_field( $atts['tag'] ),
				'location' => absint( $atts['location'] ),
				'class'    => self::css_class( $atts['class'] ),
			]
		);
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function event( $atts ): string {
		$atts = shortcode_atts(
			[
				'id'    => '',
				'title' => 'yes',
				'class' => '',
			],
			is_array( $atts ) ? $atts : [],
			'rmd_event'
		);

		$html = EventRenderer::render(
			absint( $atts['id'] ),
			[
				'title' => self::to_bool( $atts['title'] ),
				'class' => self::css_class( $atts['class'] ),
			]
		);
		return '' !== $html ? $html : self::notice( __( 'RMD Events Organizer: no published event with this id. This message is only visible to editors.', 'rmd-events-organizer' ) );
	}

	/**
	 * Turns shortcode attributes into arguments for the list renderer.
	 *
	 * @param array<string, string> $atts
	 * @return array<string, mixed>
	 */
	public static function list_args( array $atts ): array {
		return [
			'scope'            => sanitize_key( $atts['scope'] ?? '' ),
			'from'             => Dates::is_valid( (string) ( $atts['from'] ?? '' ) ) ? (string) $atts['from'] : '',
			'to'               => Dates::is_valid( (string) ( $atts['to'] ?? '' ) ) ? (string) $atts['to'] : '',
			'limit'            => absint( $atts['limit'] ?? 0 ),
			'kind'             => sanitize_key( $atts['kind'] ?? '' ),
			'category'         => sanitize_text_field( (string) ( $atts['category'] ?? '' ) ),
			'exclude_category' => sanitize_text_field( (string) ( $atts['exclude_category'] ?? '' ) ),
			'tag'              => sanitize_text_field( (string) ( $atts['tag'] ?? '' ) ),
			'location'         => absint( $atts['location'] ?? 0 ),
			'unique'           => self::to_bool( (string) ( $atts['unique'] ?? '' ) ),
			'order'            => sanitize_key( $atts['order'] ?? '' ),
			'group'            => sanitize_key( $atts['group'] ?? '' ),
			'show'             => sanitize_text_field( (string) ( $atts['show'] ?? '' ) ),
			'pagination'       => self::to_bool( (string) ( $atts['pagination'] ?? '' ) ),
			'empty_text'       => sanitize_text_field( (string) ( $atts['empty'] ?? '' ) ),
			'class'            => self::css_class( (string) ( $atts['class'] ?? '' ) ),
		];
	}

	public static function to_bool( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), [ '1', 'yes', 'true', 'on' ], true );
	}

	public static function css_class( string $value ): string {
		return implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $value ) ) ?: [] ) ) );
	}

	/**
	 * A hint that only people who can fix the problem see.
	 */
	public static function notice( string $message ): string {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			return '';
		}
		return '<p class="rmd-eo rmd-eo--missing">' . esc_html( $message ) . '</p>';
	}
}
