<?php
/**
 * Removes all plugin data when the plugin is deleted, but only if the site
 * owner opted in under Events → Settings.
 *
 * @package RMD\EventsOrganizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes posts, terms, the occurrences table, options and capabilities of
 * the current site.
 */
function rmd_eo_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'rmd_eo_settings', [] );
	if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	$post_ids = get_posts(
		[
			'post_type'      => [ 'rmd_eo_event', 'rmd_eo_location' ],
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'lang'           => '', // Polylang: every language.
		]
	);
	foreach ( $post_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
	// "any" leaves out the trash.
	$trashed = get_posts(
		[
			'post_type'      => [ 'rmd_eo_event', 'rmd_eo_location' ],
			'post_status'    => 'trash',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'lang'           => '', // Polylang: every language.
		]
	);
	foreach ( $trashed as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}

	// The plugin is not loaded during uninstall; terms can only be deleted from registered taxonomies.
	foreach ( [ 'rmd_eo_category', 'rmd_eo_tag' ] as $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, 'rmd_eo_event', [ 'public' => false ] );
		}
		$term_ids = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'lang'       => '',
			]
		);
		if ( is_array( $term_ids ) ) {
			foreach ( $term_ids as $term_id ) {
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}
	}

	$table = $wpdb->prefix . 'rmd_eo_occurrences';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own table.

	foreach ( [ 'rmd_eo_settings', 'rmd_eo_db', 'rmd_eo_version', 'rmd_eo_caps_version', 'rmd_eo_flush_rewrite', 'external_updates-rmd-events-organizer' ] as $option ) {
		delete_option( $option );
	}
	wp_clear_scheduled_hook( 'rmd_eo_daily_purge' );
	wp_clear_scheduled_hook( 'rmd_eo_resync' );

	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_rmd\_eo\_%' OR option_name LIKE '\_transient\_timeout\_rmd\_eo\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role && $role->has_cap( 'rmd_eo_manage_events' ) ) {
			$role->remove_cap( 'rmd_eo_manage_events' );
		}
	}

	flush_rewrite_rules( false );
}

if ( is_multisite() ) {
	$rmd_eo_site_ids = get_sites( [ 'fields' => 'ids' ] );
	foreach ( $rmd_eo_site_ids as $rmd_eo_site_id ) {
		switch_to_blog( (int) $rmd_eo_site_id );
		rmd_eo_uninstall_site();
		restore_current_blog();
	}
} else {
	rmd_eo_uninstall_site();
}
