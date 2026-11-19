<?php
/**
 * Removes what Events Manager leaves behind after it was deleted: its tables,
 * settings, posts, categories, tags, widgets, capabilities and scheduled
 * tasks. That plugin has no uninstall routine of its own.
 *
 * This is the one place of the plugin that deletes foreign data. It only runs
 * on request, never while that plugin is active, and cannot be undone.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Migration;

defined( 'ABSPATH' ) || exit;

final class Cleanup {

	/** Posts deleted per request. */
	public const BATCH = 100;

	/** Tables of that plugin and of its paid add-on, without prefix. */
	private const TABLES = [
		'em_events',
		'em_event_recurrences',
		'em_event_timeslots',
		'em_timeranges',
		'em_locations',
		'em_bookings',
		'em_bookings_meta',
		'em_tickets',
		'em_tickets_bookings',
		'em_tickets_bookings_meta',
		'em_meta',
		'em_online_sessions',
		'em_migrate_map',
		'em_coupons',
		'em_transactions',
		'em_email_queue',
		'em_bookings_relationships',
		'em_automation',
		'em_automation_logs',
	];

	private const POST_TYPES = [ EmReader::POST_TYPE_EVENT, EmReader::POST_TYPE_RECURRING, EmReader::POST_TYPE_LOCATION ];

	private const TAXONOMIES = [ EmReader::TAXONOMY_CATEGORY, EmReader::TAXONOMY_TAG ];

	private const CAPABILITIES = [
		'publish_events',
		'delete_others_events',
		'edit_others_events',
		'manage_others_bookings',
		'publish_recurring_events',
		'delete_others_recurring_events',
		'edit_others_recurring_events',
		'publish_locations',
		'delete_others_locations',
		'delete_locations',
		'edit_others_locations',
		'delete_event_categories',
		'edit_event_categories',
		'manage_bookings',
		'upload_event_images',
		'delete_events',
		'edit_events',
		'read_private_events',
		'delete_recurring_events',
		'edit_recurring_events',
		'edit_locations',
		'read_private_locations',
		'read_others_locations',
	];

	private const CRON_HOOKS = [
		'em_booking_intents_expire',
		'em_online_venues_queue',
		'em_online_venues_sync',
		'em_uploads_api_cleanup',
		'emp_cron_hook',
	];

	/**
	 * What is there to remove.
	 *
	 * @return array{tables: list<string>, options: int, posts: int, terms: int, widgets: int}
	 */
	public static function inventory(): array {
		global $wpdb;
		$types = "'" . implode( "', '", self::POST_TYPES ) . "'";
		$taxes = "'" . implode( "', '", self::TAXONOMIES ) . "'";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counts with constant lists.
		$inventory = [
			'tables'  => self::tables(),
			'options' => count( self::options() ),
			'posts'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( {$types} )" ),
			'terms'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ( {$taxes} )" ),
			'widgets' => 0,
		];
		// phpcs:enable
		foreach ( (array) get_option( 'sidebars_widgets', [] ) as $widgets ) {
			foreach ( is_array( $widgets ) ? $widgets : [] as $widget_id ) {
				$inventory['widgets'] += preg_match( '/^(em_widget|em_calendar|em_locations_widget)-\d+$/', (string) $widget_id ) ? 1 : 0;
			}
		}
		return $inventory;
	}

	public static function has_leftovers(): bool {
		$inventory = self::inventory();
		return [] !== $inventory['tables'] || $inventory['options'] + $inventory['posts'] + $inventory['terms'] + $inventory['widgets'] > 0;
	}

	/**
	 * Deletes one batch of posts; once none are left, everything else.
	 *
	 * @return int Posts still to delete; 0 means the cleanup is complete.
	 */
	public static function run_batch(): int {
		global $wpdb;
		if ( EmReader::is_plugin_active() ) {
			return 0;
		}

		// Registered for a moment so WordPress removes relations and terms properly.
		foreach ( self::TAXONOMIES as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, self::POST_TYPES, [ 'public' => false ] );
			}
		}

		$types = "'" . implode( "', '", self::POST_TYPES ) . "'";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Constant list of post types.
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( {$types} ) ORDER BY ID ASC LIMIT %d", self::BATCH ) );
		foreach ( $ids as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( {$types} )" );
		// phpcs:enable
		if ( $left > 0 ) {
			// Posts that cannot be deleted must not keep the cleanup running in circles.
			return [] === $ids ? 0 : $left;
		}

		foreach ( self::TAXONOMIES as $taxonomy ) {
			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
					'lang'       => '',
				]
			);
			foreach ( is_array( $terms ) ? $terms : [] as $term_id ) {
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}

		foreach ( self::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Name from a constant list, checked against the database.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		foreach ( self::options() as $option ) {
			delete_option( $option );
		}
		WidgetRewriter::remove_legacy();

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::CAPABILITIES as $capability ) {
				if ( $role->has_cap( $capability ) ) {
					$role->remove_cap( $capability );
				}
			}
		}
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		return 0;
	}

	/**
	 * Tables of that plugin that exist. On a network with shared tables they
	 * are left alone: other sites may still need them.
	 *
	 * @return list<string>
	 */
	private static function tables(): array {
		global $wpdb;
		$tables = [];
		foreach ( self::TABLES as $name ) {
			$table = $wpdb->prefix . $name;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Checks whether a table exists.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
				$tables[] = $table;
			}
		}
		return $tables;
	}

	/**
	 * @return list<string> Names of the settings of that plugin.
	 */
	private static function options(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lists foreign settings by their prefix.
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'dbem_' ) . '%' ) ) );
	}
}
