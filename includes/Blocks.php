<?php
/**
 * Registers the blocks from the built metadata collection and wires the
 * render callbacks to the renderers.
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

final class Blocks {

	/** Folder name => module the block belongs to ('' = always available). */
	public const NAMES = [
		'events'          => '',
		'event-details'   => '',
		'events-calendar' => Modules::CALENDAR,
		'market-dates'    => Modules::MARKETS,
	];

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ], 20 );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'editor_translations' ] );
	}

	public static function register(): void {
		$path     = RMD_EO_DIR . 'build/blocks';
		$manifest = RMD_EO_DIR . 'build/blocks-manifest.php';

		if ( ! is_readable( $manifest ) ) {
			// Assets not built (development checkout without `npm run build`).
			add_action( 'admin_notices', [ self::class, 'missing_build_notice' ] );
			return;
		}

		wp_register_block_types_from_metadata_collection( $path, $manifest );

		foreach ( self::NAMES as $name => $module ) {
			if ( '' !== $module && ! Modules::is_on( $module ) ) {
				unregister_block_type( 'rmd/' . $name );
			}
		}
	}

	/**
	 * block.json's "textdomain" makes core call wp_set_script_translations()
	 * without a path, which only finds translations in WP_LANG_DIR. Point the
	 * editor scripts at the bundled languages/ folder instead.
	 */
	public static function editor_translations(): void {
		foreach ( array_keys( self::NAMES ) as $name ) {
			$handle = 'rmd-' . $name . '-editor-script';
			if ( wp_script_is( $handle, 'registered' ) ) {
				wp_set_script_translations( $handle, 'rmd-events-organizer', RMD_EO_DIR . 'languages' );
			}
		}
	}

	public static function missing_build_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'RMD Events Organizer: the built block files are missing. You probably installed the “Source code” download from GitHub. Please install the file rmd-events-organizer.zip from the release page instead (or run “npm install && npm run build” in a development checkout).', 'rmd-events-organizer' ) . '</p></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Render callbacks (called from build/blocks/<name>/render.php)       */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render_events( array $attributes, string $kind = '' ): string {
		$html = ListRenderer::render(
			[
				'scope'            => sanitize_key( (string) ( $attributes['scope'] ?? 'upcoming' ) ),
				'limit'            => absint( $attributes['limit'] ?? 0 ),
				'kind'             => '' !== $kind ? $kind : sanitize_key( (string) ( $attributes['kind'] ?? '' ) ),
				'category'         => sanitize_text_field( (string) ( $attributes['category'] ?? '' ) ),
				'exclude_category' => sanitize_text_field( (string) ( $attributes['excludeCategory'] ?? '' ) ),
				'tag'              => sanitize_text_field( (string) ( $attributes['tag'] ?? '' ) ),
				'location'         => absint( $attributes['location'] ?? 0 ),
				'unique'           => ! empty( $attributes['unique'] ),
				'group'            => sanitize_key( (string) ( $attributes['group'] ?? '' ) ),
				'show'             => array_map( 'sanitize_key', (array) ( $attributes['show'] ?? [] ) ),
				'pagination'       => ! empty( $attributes['pagination'] ),
				'empty_text'       => sanitize_text_field( (string) ( $attributes['emptyText'] ?? '' ) ),
				'follow_day'       => empty( $attributes['isPreview'] ),
			]
		);
		return self::wrap( $html );
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render_market_dates( array $attributes ): string {
		return self::render_events( $attributes, Kinds::MARKET );
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render_events_calendar( array $attributes ): string {
		$month = (string) ( $attributes['month'] ?? '' );
		$html  = CalendarRenderer::render(
			[
				'size'     => sanitize_key( (string) ( $attributes['size'] ?? 'full' ) ),
				'month'    => Dates::is_valid_month( $month ) ? $month : '',
				'kind'     => sanitize_key( (string) ( $attributes['kind'] ?? '' ) ),
				'category' => sanitize_text_field( (string) ( $attributes['category'] ?? '' ) ),
				'tag'      => sanitize_text_field( (string) ( $attributes['tag'] ?? '' ) ),
				'location' => absint( $attributes['location'] ?? 0 ),
			]
		);
		return self::wrap( $html );
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render_event_details( array $attributes ): string {
		$event_id = absint( $attributes['eventId'] ?? 0 );
		if ( 0 === $event_id && is_singular( PostTypes::EVENT ) ) {
			$event_id = (int) get_queried_object_id();
		}
		$html = EventRenderer::render( $event_id, [ 'title' => ! empty( $attributes['showTitle'] ) ] );

		if ( '' === $html && ! empty( $attributes['isPreview'] ) ) {
			$html = '<p class="rmd-eo-editor-hint">' . esc_html__( 'Choose a published event in the block settings.', 'rmd-events-organizer' ) . '</p>';
		}
		return self::wrap( $html );
	}

	/**
	 * Adds the block wrapper (alignment, colours, spacing, anchor).
	 */
	private static function wrap( string $html ): string {
		if ( '' === $html ) {
			return '';
		}
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}
}
