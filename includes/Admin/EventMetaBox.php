<?php
/**
 * The event editor: a React app directly below the title that keeps dates,
 * place and details in a hidden JSON field. The description stays in the
 * ordinary editor underneath.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Admin;

use RMD\EventsOrganizer\Assets;
use RMD\EventsOrganizer\Clock;
use RMD\EventsOrganizer\Domain\Dates;
use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Domain\Validator;
use RMD\EventsOrganizer\Modules;
use RMD\EventsOrganizer\PostTypes;
use RMD\EventsOrganizer\Repository\EventRepository;
use RMD\EventsOrganizer\Repository\LocationRepository;
use RMD\EventsOrganizer\Rest\CalendarController;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class EventMetaBox {

	public const HANDLE = 'rmd-eo-event-editor';

	public static function init(): void {
		add_action( 'edit_form_after_title', [ self::class, 'render_editor' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_filter( 'enter_title_here', [ self::class, 'title_placeholder' ], 10, 2 );
	}

	/**
	 * Shortcut for adding a market date.
	 */
	public static function menu(): void {
		if ( ! Modules::is_on( Modules::MARKETS ) ) {
			return;
		}
		add_submenu_page(
			'edit.php?post_type=' . PostTypes::EVENT,
			__( 'Add market date', 'rmd-events-organizer' ),
			__( 'Add market date', 'rmd-events-organizer' ),
			\RMD\EventsOrganizer\Capabilities::CAP,
			'post-new.php?post_type=' . PostTypes::EVENT . '&kind=' . Kinds::MARKET,
			'',
			2
		);
	}

	public static function title_placeholder( string $placeholder, WP_Post $post ): string {
		if ( PostTypes::EVENT !== $post->post_type ) {
			return $placeholder;
		}
		return Kinds::MARKET === self::kind( $post )
			? __( 'Name of the market, e.g. “Christmas market Freiberg”', 'rmd-events-organizer' )
			: __( 'Name of the event', 'rmd-events-organizer' );
	}

	public static function render_editor( WP_Post $post ): void {
		if ( PostTypes::EVENT !== $post->post_type ) {
			return;
		}

		$data = [
			'kind'     => self::kind( $post ),
			'schedule' => EventRepository::schedule( (int) $post->ID ),
			'details'  => EventRepository::details( (int) $post->ID ),
			'location' => [ 'id' => EventRepository::location_id( (int) $post->ID ) ],
		];

		wp_nonce_field( SaveHandler::NONCE_ACTION . $post->ID, SaveHandler::NONCE_FIELD );
		echo '<input type="hidden" id="rmd-eo-data" name="' . esc_attr( SaveHandler::FIELD ) . '" value="' . esc_attr( (string) wp_json_encode( $data ) ) . '">';
		echo '<div id="rmd-eo-editor-root" class="rmd-eo-editor">';
		echo '<noscript><p>' . esc_html__( 'The event editor needs JavaScript.', 'rmd-events-organizer' ) . '</p></noscript>';
		echo '<p class="rmd-eo-editor__loading">' . esc_html__( 'Loading editor …', 'rmd-events-organizer' ) . '</p>';
		echo '</div>';
	}

	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || PostTypes::EVENT !== $screen->post_type ) {
			return;
		}
		if ( ! is_readable( RMD_EO_DIR . 'build/admin/event-editor.js' ) ) {
			return;
		}

		$asset = Assets::build_asset( 'build/admin/event-editor.js' );
		wp_enqueue_script(
			self::HANDLE,
			RMD_EO_URL . 'build/admin/event-editor.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( self::HANDLE, 'rmd-events-organizer', RMD_EO_DIR . 'languages' );

		if ( is_readable( RMD_EO_DIR . 'build/admin/event-editor.css' ) ) {
			wp_enqueue_style( self::HANDLE, RMD_EO_URL . 'build/admin/event-editor.css', [ 'wp-components' ], $asset['version'] );
		}

		wp_add_inline_script( self::HANDLE, 'window.rmdEoEditorConfig = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Kind of the event being edited; a new post takes it from the URL.
	 */
	private static function kind( WP_Post $post ): string {
		if ( 'auto-draft' === $post->post_status && isset( $_GET['kind'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only preselects a form option.
			$kind = Kinds::normalize( sanitize_key( wp_unslash( $_GET['kind'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return Kinds::MARKET === $kind && ! Modules::is_on( Modules::MARKETS ) ? Kinds::EVENT : $kind;
		}
		return EventRepository::kind( (int) $post->ID );
	}

	/**
	 * Static configuration for the editor app.
	 *
	 * @return array<string, mixed>
	 */
	private static function config(): array {
		global $wp_locale;

		$weekdays = [];
		foreach ( Dates::WEEKDAYS as $index => $weekday ) {
			$name       = $wp_locale->get_weekday( ( $index + 1 ) % 7 );
			$weekdays[] = [
				'id'    => $weekday,
				'long'  => $name,
				'short' => $wp_locale->get_weekday_abbrev( $name ),
			];
		}

		$locations = [];
		if ( Modules::is_on( Modules::LOCATIONS ) ) {
			foreach ( LocationRepository::choices() as $id => $name ) {
				$locations[] = [
					'id'   => $id,
					'name' => html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ),
				];
			}
		}

		return [
			'fieldId'     => 'rmd-eo-data',
			'today'       => Clock::today(),
			'weekdays'    => $weekdays,
			'startOfWeek' => (int) get_option( 'start_of_week', 1 ),
			'modules'     => Modules::states(),
			'locations'   => $locations,
			'previewPath' => '/' . CalendarController::NAMESPACE . '/preview',
			'limits'      => [
				'entries'    => Validator::MAX_ENTRIES,
				'exclusions' => Validator::MAX_EXCLUSIONS,
				'interval'   => Validator::MAX_INTERVAL,
				'count'      => Validator::MAX_COUNT,
				'duration'   => Validator::MAX_DURATION,
				'text'       => Validator::MAX_TEXT,
				'note'       => Validator::MAX_NOTE,
			],
		];
	}
}
