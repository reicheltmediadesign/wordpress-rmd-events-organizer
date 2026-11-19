<?php
/**
 * Third step of the import: removing what Events Manager leaves in the
 * database after it was deleted. Optional, and the only step that cannot be
 * undone.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Admin;

use RMD\EventsOrganizer\Migration\Cleanup;
use RMD\EventsOrganizer\Migration\ContentRewriter;
use RMD\EventsOrganizer\Migration\EmReader;
use RMD\EventsOrganizer\Migration\Importer;

defined( 'ABSPATH' ) || exit;

final class MigrationCleanupTab {

	public const ACTION = 'rmd_eo_em_cleanup';

	/** What has to be typed to confirm. */
	public const CONFIRMATION = 'events-manager';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	public static function render(): void {
		echo '<p>' . esc_html__( 'Events Manager does not remove its data when it is deleted. This step is optional: it deletes what that plugin left in the database. The events imported into this plugin are kept.', 'rmd-events-organizer' ) . '</p>';

		$inventory = Cleanup::inventory();
		if ( ! Cleanup::has_leftovers() ) {
			echo '<p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'Nothing of Events Manager is left in the database.', 'rmd-events-organizer' ) . '</p>';
			return;
		}

		echo '<h2>' . esc_html__( 'What would be removed', 'rmd-events-organizer' ) . '</h2><table class="widefat striped rmd-eo-import__table"><tbody>';
		self::row( __( 'Database tables', 'rmd-events-organizer' ), [] !== $inventory['tables'] ? implode( ', ', $inventory['tables'] ) : '0' );
		self::row( __( 'Events and locations of Events Manager (posts)', 'rmd-events-organizer' ), (string) $inventory['posts'] );
		self::row( __( 'Categories and tags of Events Manager', 'rmd-events-organizer' ), (string) $inventory['terms'] );
		self::row( __( 'Settings', 'rmd-events-organizer' ), (string) $inventory['options'] );
		self::row( __( 'Widgets', 'rmd-events-organizer' ), (string) $inventory['widgets'] );
		self::row( __( 'Also', 'rmd-events-organizer' ), __( 'its capabilities in the user roles and its scheduled tasks; bookings and tickets are in the tables above', 'rmd-events-organizer' ) );
		echo '</tbody></table>';

		if ( EmReader::is_plugin_active() ) {
			echo '<p><strong>' . esc_html__( 'Possible once Events Manager is deactivated and deleted.', 'rmd-events-organizer' ) . '</strong></p>';
			return;
		}

		echo '<h2>' . esc_html__( 'Remove', 'rmd-events-organizer' ) . '</h2>';
		if ( 0 === Importer::imported()['events'] && null !== EmReader::tables() && [] !== EmReader::events() ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'No event has been imported yet. Everything entered in Events Manager would be lost.', 'rmd-events-organizer' ) . '</strong></p></div>';
		}
		$open = count( array_filter( ContentRewriter::scan(), static fn( array $row ): bool => $row['open'] > 0 ) );
		if ( $open > 0 ) {
			/* translators: %d: number of pages */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( _n( '%d page still uses shortcodes or blocks of Events Manager that can be changed (step 2). After the cleanup, filters by category or location can no longer be carried over.', '%d pages still use shortcodes or blocks of Events Manager that can be changed (step 2). After the cleanup, filters by category or location can no longer be carried over.', $open, 'rmd-events-organizer' ), $open ) ) . '</p></div>';
		}

		echo '<ul class="ul-disc">';
		echo '<li>' . esc_html__( 'This cannot be undone. Make a backup of the database first.', 'rmd-events-organizer' ) . '</li>';
		echo '<li>' . esc_html__( 'Afterwards the import can no longer be repeated, compared or undone, and Events Manager would start empty if it were installed again.', 'rmd-events-organizer' ) . '</li>';
		echo '<li>' . esc_html__( 'Menu items that still lead to events, locations or categories of Events Manager are removed with them (step 2 changes those that have a counterpart here).', 'rmd-events-organizer' ) . '</li>';
		echo '<li>' . esc_html__( 'Images stay in the media library.', 'rmd-events-organizer' ) . '</li>';
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<p><label for="rmd-eo-cleanup-confirm">';
		/* translators: %s: the word to type */
		echo wp_kses( sprintf( __( 'To confirm, type %s:', 'rmd-events-organizer' ), '<code>' . self::CONFIRMATION . '</code>' ), [ 'code' => [] ] );
		echo '</label> <input type="text" id="rmd-eo-cleanup-confirm" name="confirm" value="" autocomplete="off" class="regular-text" required></p>';
		submit_button( __( 'Remove the data of Events Manager', 'rmd-events-organizer' ), 'delete' );
		echo '</form>';
	}

	public static function handle(): void {
		MigrationPage::guard( self::ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified in guard().
		$confirm = isset( $_REQUEST['confirm'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['confirm'] ) ) : '';
		// phpcs:enable
		if ( EmReader::is_plugin_active() || self::CONFIRMATION !== strtolower( trim( $confirm ) ) ) {
			self::redirect( 'cleanup_confirm' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Deleting a batch of posts may take longer than a page view.
		}

		$left = Cleanup::run_batch();
		if ( $left <= 0 ) {
			self::redirect( 'cleanup' );
		}
		MigrationPage::forward(
			wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&confirm=' . self::CONFIRMATION ), self::ACTION ),
			/* translators: %d: number of posts */
			sprintf( __( 'Removing … %d posts left.', 'rmd-events-organizer' ), $left )
		);
	}

	private static function redirect( string $done ): void {
		wp_safe_redirect(
			MigrationPage::url(
				[
					'tab'  => MigrationPage::TAB_CLEANUP,
					'done' => $done,
				]
			)
		);
		exit;
	}

	private static function row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
