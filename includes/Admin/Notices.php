<?php
/**
 * Admin notices and translation of validator error codes into messages.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Admin;

use RMD\EventsOrganizer\PostTypes;

defined( 'ABSPATH' ) || exit;

final class Notices {

	private const TRANSIENT_PREFIX = 'rmd_eo_errors_';

	public static function init(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	/**
	 * Stores validation errors for the current user so they survive the
	 * redirect after saving a post.
	 *
	 * @param list<array{path: string, code: string, args: array}> $errors
	 */
	public static function remember( int $post_id, array $errors ): void {
		set_transient( self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $post_id, $errors, 5 * MINUTE_IN_SECONDS );
	}

	public static function render(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! in_array( $screen->post_type, [ PostTypes::EVENT, PostTypes::LOCATION ], true ) ) {
			return;
		}
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			return;
		}
		$key    = self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $post_id;
		$errors = get_transient( $key );
		if ( ! is_array( $errors ) || [] === $errors ) {
			return;
		}
		delete_transient( $key );

		echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'Some entries could not be saved as entered:', 'rmd-events-organizer' ) . '</strong></p><ul class="ul-disc">';
		foreach ( array_slice( $errors, 0, 15 ) as $error ) {
			echo '<li>' . esc_html( self::message( $error ) ) . '</li>';
		}
		if ( count( $errors ) > 15 ) {
			echo '<li>' . esc_html( sprintf( /* translators: %d: number of additional errors */ __( '… and %d more.', 'rmd-events-organizer' ), count( $errors ) - 15 ) ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * @param array{path: string, code: string, args: array} $error
	 */
	public static function message( array $error ): string {
		$args     = $error['args'] ?? [];
		$messages = [
			'invalid_date'     => __( 'Invalid date; the entry was left out.', 'rmd-events-organizer' ),
			'invalid_time'     => __( 'Invalid time. Use the format HH:MM.', 'rmd-events-organizer' ),
			'end_before_start' => __( 'The end is before the start; the end was ignored.', 'rmd-events-organizer' ),
			'daily_needs_end'  => __( '“Same hours every day” needs an end time later than the start time.', 'rmd-events-organizer' ),
			'invalid_choice'   => __( 'Unknown choice; the entry was left out.', 'rmd-events-organizer' ),
			'invalid_url'      => __( 'Invalid web address (only http and https are allowed).', 'rmd-events-organizer' ),
			'invalid_payload'  => __( 'The editor data could not be read; nothing was changed. Please reload the page and try again.', 'rmd-events-organizer' ),
			'location_failed'  => __( 'The new location could not be created.', 'rmd-events-organizer' ),
			/* translators: %d: maximum number of entries */
			'too_many'         => sprintf( __( 'At most %d entries are allowed; the rest was left out.', 'rmd-events-organizer' ), (int) ( $args[0] ?? 0 ) ),
		];

		$text = $messages[ $error['code'] ] ?? __( 'Invalid value.', 'rmd-events-organizer' );

		return self::describe_path( (string) $error['path'] ) . ': ' . $text;
	}

	/**
	 * Turns "entries.2.end_time" into a human readable location.
	 */
	private static function describe_path( string $path ): string {
		$parts  = explode( '.', $path );
		$names  = [
			'entries'       => __( 'Dates', 'rmd-events-organizer' ),
			'exclusions'    => __( 'Exceptions', 'rmd-events-organizer' ),
			'overrides'     => __( 'Changed dates', 'rmd-events-organizer' ),
			'details'       => __( 'Details', 'rmd-events-organizer' ),
			'address'       => __( 'Address', 'rmd-events-organizer' ),
			'location'      => __( 'Location', 'rmd-events-organizer' ),
			'schedule'      => __( 'Dates', 'rmd-events-organizer' ),
			'start_date'    => __( 'Start date', 'rmd-events-organizer' ),
			'end_date'      => __( 'End date', 'rmd-events-organizer' ),
			'start_time'    => __( 'Start time', 'rmd-events-organizer' ),
			'end_time'      => __( 'End time', 'rmd-events-organizer' ),
			'from'          => __( 'First date', 'rmd-events-organizer' ),
			'until'         => __( 'Last date', 'rmd-events-organizer' ),
			'freq'          => __( 'Repetition', 'rmd-events-organizer' ),
			'organizer_url' => __( 'Organiser website', 'rmd-events-organizer' ),
			'info_url'      => __( 'Link for more information', 'rmd-events-organizer' ),
			'url'           => __( 'Website', 'rmd-events-organizer' ),
		];
		$labels = [];

		foreach ( $parts as $index => $part ) {
			if ( ctype_digit( $part ) ) {
				continue;
			}
			$label = $names[ $part ] ?? ucfirst( str_replace( '_', ' ', $part ) );
			if ( isset( $parts[ $index + 1 ] ) && ctype_digit( $parts[ $index + 1 ] ) ) {
				$label .= ' ' . ( (int) $parts[ $index + 1 ] + 1 );
			}
			$labels[] = $label;
		}

		return implode( ' › ', $labels );
	}
}
