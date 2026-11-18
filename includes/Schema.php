<?php
/**
 * Creates and versions the plugin's database table.
 *
 * The occurrences table is an index derived from the schedule stored with
 * each event; it can be rebuilt at any time. Rows keep their id as long as
 * their key exists, so later features may reference them.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const OPTION  = 'rmd_eo_db';
	public const VERSION = '1';

	public static function occurrences(): string {
		global $wpdb;
		return $wpdb->prefix . 'rmd_eo_occurrences';
	}

	public static function ensure(): void {
		if ( get_option( self::OPTION ) === self::VERSION ) {
			return;
		}
		self::create();
		update_option( self::OPTION, self::VERSION, true );
	}

	private static function create(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::occurrences();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				occ_key varchar(40) NOT NULL,
				kind varchar(20) NOT NULL DEFAULT 'event',
				start_date date NOT NULL,
				end_date date NOT NULL,
				start_time time NULL,
				end_time time NULL,
				all_day tinyint(1) unsigned NOT NULL DEFAULT 0,
				time_mode varchar(12) NOT NULL DEFAULT 'continuous',
				start_utc datetime NOT NULL,
				end_utc datetime NOT NULL,
				timezone varchar(64) NOT NULL DEFAULT '',
				status varchar(12) NOT NULL DEFAULT 'scheduled',
				post_status varchar(20) NOT NULL DEFAULT 'publish',
				location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				note varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY event_key (event_id,occ_key),
				KEY range_local (post_status,start_date,end_date),
				KEY end_utc (post_status,end_utc),
				KEY location (location_id,start_date)
			) {$collate};"
		);
	}
}
