<?php
/**
 * Writes the occurrences index. Reading for lists and calendars happens in
 * Query\OccurrenceQuery.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Repository;

use RMD\EventsOrganizer\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; its name comes from Schema::occurrences().

final class OccurrenceRepository {

	/**
	 * @return array<string, array<string, string|null>> Rows by occ_key.
	 */
	public static function for_event( int $event_id ): array {
		global $wpdb;
		$table = Schema::occurrences();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d", $event_id ), ARRAY_A );

		$by_key = [];
		foreach ( (array) $rows as $row ) {
			foreach ( [ 'start_time', 'end_time' ] as $column ) {
				// The index stores HH:MM:SS; compare in the same form.
				$row[ $column ] = null === $row[ $column ] ? null : (string) $row[ $column ];
			}
			$by_key[ (string) $row['occ_key'] ] = $row;
		}
		return $by_key;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function insert( array $row ): void {
		global $wpdb;
		$wpdb->insert( Schema::occurrences(), $row );
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public static function update( int $id, array $changes ): void {
		global $wpdb;
		$wpdb->update( Schema::occurrences(), $changes, [ 'id' => $id ] );
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::occurrences(), [ 'id' => $id ], [ '%d' ] );
	}

	public static function delete_for_event( int $event_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::occurrences(), [ 'event_id' => $event_id ], [ '%d' ] );
	}
}
