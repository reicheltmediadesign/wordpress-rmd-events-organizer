<?php
/**
 * Points menu items that led to events of Events Manager to the events here.
 * Without this they disappear from the menus once that plugin is gone, since
 * WordPress hides items whose target no longer exists.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Migration;

use RMD\EventsOrganizer\PostTypes;

defined( 'ABSPATH' ) || exit;

final class MenuRewriter {

	/** Post meta of a menu item: its target before the change. */
	public const BACKUP = '_rmd_eo_em_menu_backup';

	public const NOTE_LOCATION = 'menu_location';
	public const NOTE_TERM     = 'menu_term';
	public const NOTE_ARCHIVE  = 'menu_archive';
	public const NOTE_MISSING  = 'menu_missing';

	private const OBJECTS = [
		EmReader::POST_TYPE_EVENT,
		EmReader::POST_TYPE_RECURRING,
		EmReader::POST_TYPE_LOCATION,
		EmReader::TAXONOMY_CATEGORY,
		EmReader::TAXONOMY_TAG,
	];

	/**
	 * @return list<array{id: int, title: string, menu: string, to: string, note: string, converted: bool}>
	 *         "to" is the title of the new target, empty when there is none.
	 */
	public static function scan(): array {
		$rows = [];
		foreach ( self::items() as $item_id ) {
			$backup = get_post_meta( $item_id, self::BACKUP, true );
			// A changed item shows where it leads now; its old target may be gone by now.
			$target = is_array( $backup )
				? [
					'object_id' => self::current( $item_id )['object_id'],
					'note'      => '',
				]
				: self::target( $item_id );
			$menus  = wp_get_object_terms( $item_id, 'nav_menu', [ 'fields' => 'names' ] );
			$title  = (string) get_post_field( 'post_title', $item_id );
			if ( '' === $title ) {
				$source = is_array( $backup ) ? $backup : self::current( $item_id );
				$title  = 'taxonomy' === $source['type']
					? (string) ( EmReader::terms( $source['object'] )[ (int) $source['object_id'] ]['name'] ?? '' )
					: (string) get_post_field( 'post_title', (int) $source['object_id'] );
				if ( '' === $title && $target['object_id'] > 0 ) {
					$title = (string) get_post_field( 'post_title', $target['object_id'] );
				}
			}

			$rows[] = [
				'id'        => $item_id,
				'title'     => '' !== $title ? $title : '#' . $item_id,
				'menu'      => is_array( $menus ) ? implode( ', ', $menus ) : '',
				'to'        => $target['object_id'] > 0 ? (string) get_the_title( $target['object_id'] ) : '',
				'note'      => $target['note'],
				'converted' => is_array( $backup ),
			];
		}
		return $rows;
	}

	/**
	 * @return int Number of menu items changed.
	 */
	public static function apply_all(): int {
		$count = 0;
		foreach ( self::items() as $item_id ) {
			if ( metadata_exists( 'post', $item_id, self::BACKUP ) ) {
				continue;
			}
			$target = self::target( $item_id );
			if ( $target['object_id'] <= 0 ) {
				continue;
			}
			add_post_meta( $item_id, self::BACKUP, self::current( $item_id ), true );
			update_post_meta( $item_id, '_menu_item_type', 'post_type' );
			update_post_meta( $item_id, '_menu_item_object', $target['object'] );
			update_post_meta( $item_id, '_menu_item_object_id', (string) $target['object_id'] );
			++$count;
		}
		return $count;
	}

	/**
	 * @return int Number of menu items put back.
	 */
	public static function undo_all(): int {
		$count = 0;
		foreach ( self::items() as $item_id ) {
			$backup = get_post_meta( $item_id, self::BACKUP, true );
			if ( ! is_array( $backup ) ) {
				continue;
			}
			update_post_meta( $item_id, '_menu_item_type', (string) $backup['type'] );
			update_post_meta( $item_id, '_menu_item_object', (string) $backup['object'] );
			update_post_meta( $item_id, '_menu_item_object_id', (string) $backup['object_id'] );
			delete_post_meta( $item_id, self::BACKUP );
			++$count;
		}
		return $count;
	}

	/**
	 * Where a menu item should lead.
	 *
	 * @return array{object: string, object_id: int, note: string}
	 */
	private static function target( int $item_id ): array {
		$source = self::current( $item_id );
		$none   = [
			'object'    => '',
			'object_id' => 0,
			'note'      => '',
		];

		if ( 'post_type_archive' === $source['type'] ) {
			$page = ContentRewriter::events_page();
			return $page > 0 ? [
				'object'    => 'page',
				'object_id' => $page,
				'note'      => '',
			] : [ 'note' => self::NOTE_ARCHIVE ] + $none;
		}
		if ( 'taxonomy' === $source['type'] ) {
			return [ 'note' => self::NOTE_TERM ] + $none;
		}
		if ( EmReader::POST_TYPE_LOCATION === $source['object'] ) {
			return [ 'note' => self::NOTE_LOCATION ] + $none;
		}

		$event = Targets::event_by_post( (int) $source['object_id'] );
		return $event > 0 ? [
			'object'    => PostTypes::EVENT,
			'object_id' => $event,
			'note'      => '',
		] : [ 'note' => self::NOTE_MISSING ] + $none;
	}

	/**
	 * @return array{type: string, object: string, object_id: int}
	 */
	private static function current( int $item_id ): array {
		return [
			'type'      => (string) get_post_meta( $item_id, '_menu_item_type', true ),
			'object'    => (string) get_post_meta( $item_id, '_menu_item_object', true ),
			'object_id' => (int) get_post_meta( $item_id, '_menu_item_object_id', true ),
		];
	}

	/**
	 * Menu items that lead, or led, to something of that plugin.
	 *
	 * @return list<int>
	 */
	private static function items(): array {
		global $wpdb;
		$in = implode( ',', array_fill( 0, count( self::OBJECTS ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built above.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'nav_menu_item' AND ( ( m.meta_key = '_menu_item_object' AND m.meta_value IN ( {$in} ) ) OR m.meta_key = %s ) ORDER BY p.ID ASC", array_merge( self::OBJECTS, [ self::BACKUP ] ) ) ) );
	}
}
