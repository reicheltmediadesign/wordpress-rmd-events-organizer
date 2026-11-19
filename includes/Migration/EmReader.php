<?php
/**
 * The only class that reads the tables Events Manager leaves in the database.
 * It never writes to them and works whether that plugin is active, inactive
 * or deleted.
 *
 * Two table layouts exist: up to 6.x the rule of a series lives in columns of
 * the events table; from 7.0 on rules have their own table and events carry a
 * type. Both are returned in the same shape.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Migration;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Reads foreign tables whose names are built from the database prefix.

final class EmReader {

	public const POST_TYPE_EVENT     = 'event';
	public const POST_TYPE_RECURRING = 'event-recurring';
	public const POST_TYPE_LOCATION  = 'location';
	public const TAXONOMY_CATEGORY   = 'event-categories';
	public const TAXONOMY_TAG        = 'event-tags';

	/** @var array{events: string, locations: string, recurrences: string, meta: string, global: bool}|null|false */
	private static $tables = false;

	/**
	 * Names of the source tables, or null when there are none. On a network
	 * that plugin can keep one set of tables for all sites.
	 *
	 * @return array{events: string, locations: string, recurrences: string, meta: string, global: bool}|null
	 */
	public static function tables(): ?array {
		global $wpdb;
		if ( false !== self::$tables ) {
			return self::$tables;
		}

		self::$tables = null;
		foreach ( array_unique( [ $wpdb->prefix, $wpdb->base_prefix ] ) as $prefix ) {
			if ( ! self::exists( $prefix . 'em_events' ) ) {
				continue;
			}
			self::$tables = [
				'events'      => $prefix . 'em_events',
				'locations'   => self::exists( $prefix . 'em_locations' ) ? $prefix . 'em_locations' : '',
				'recurrences' => self::exists( $prefix . 'em_event_recurrences' ) ? $prefix . 'em_event_recurrences' : '',
				'meta'        => self::exists( $prefix . 'em_meta' ) ? $prefix . 'em_meta' : '',
				'global'      => $prefix !== $wpdb->prefix,
			];
			break;
		}
		return self::$tables;
	}

	public static function is_plugin_active(): bool {
		return defined( 'EM_VERSION' ) || class_exists( 'EM_Event', false );
	}

	public static function version(): string {
		return (string) get_option( 'dbem_version', '' );
	}

	/**
	 * True for the layout of 7.0 and later.
	 */
	public static function has_types(): bool {
		return in_array( 'event_type', self::columns( 'events' ), true );
	}

	/**
	 * All events of this site in one shape.
	 *
	 * @return list<array<string, mixed>> Keys: id, post_id, type (single, repeating,
	 *         recurring, recurrence, timeslot), parent_id, set_id, status (1, 0, -1 or
	 *         null), private, active, name, slug, content, owner, start_date, end_date,
	 *         start_time, end_time, all_day, timezone, location_id.
	 */
	public static function events(): array {
		global $wpdb;
		$tables = self::tables();
		if ( null === $tables ) {
			return [];
		}
		$columns = self::columns( 'events' );
		$typed   = in_array( 'event_type', $columns, true );
		$rows    = (array) $wpdb->get_results( "SELECT * FROM {$tables['events']}" . self::blog_clause( $tables ) . ' ORDER BY event_id ASC', ARRAY_A );
		$rules   = self::rules();

		$events = [];
		foreach ( $rows as $row ) {
			if ( $typed ) {
				$type = (string) ( $row['event_type'] ?? 'single' );
				$type = '' === $type ? 'single' : $type;
			} elseif ( ! empty( $row['recurrence'] ) ) {
				$type = 'repeating';
			} elseif ( ! empty( $row['recurrence_id'] ) ) {
				$type = 'recurrence';
			} else {
				$type = 'single';
			}

			$set_id    = (int) ( $row['recurrence_set_id'] ?? 0 );
			$parent_id = (int) ( $row['recurrence_id'] ?? 0 );
			if ( 'recurrence' === $type && 0 === $parent_id && isset( $rules[ $set_id ] ) ) {
				$parent_id = (int) $rules[ $set_id ]['event_id'];
			}

			$events[] = [
				'id'          => (int) $row['event_id'],
				'post_id'     => (int) ( $row['post_id'] ?? 0 ),
				'type'        => $type,
				'parent_id'   => $parent_id,
				'set_id'      => $set_id,
				'status'      => null === $row['event_status'] ? null : (int) $row['event_status'],
				'private'     => ! empty( $row['event_private'] ),
				'active'      => ! array_key_exists( 'event_active_status', $row ) || null === $row['event_active_status'] || 0 !== (int) $row['event_active_status'],
				'name'        => (string) ( $row['event_name'] ?? '' ),
				'slug'        => (string) ( $row['event_slug'] ?? '' ),
				'content'     => (string) ( $row['post_content'] ?? '' ),
				'owner'       => (int) ( $row['event_owner'] ?? 0 ),
				'start_date'  => (string) ( $row['event_start_date'] ?? '' ),
				'end_date'    => (string) ( $row['event_end_date'] ?? '' ),
				'start_time'  => (string) ( $row['event_start_time'] ?? '' ),
				'end_time'    => (string) ( $row['event_end_time'] ?? '' ),
				'all_day'     => ! empty( $row['event_all_day'] ),
				'timezone'    => (string) ( $row['event_timezone'] ?? '' ),
				'location_id' => (int) ( $row['location_id'] ?? 0 ),
			];
		}
		return $events;
	}

	/**
	 * Rules of all series: from the rules table (7.x) or from the columns of
	 * the series' own row (older), keyed by an id that the dates refer to.
	 *
	 * @return array<int, array<string, mixed>> Key: set id (7.x) or the id of the series (older).
	 */
	public static function rules(): array {
		global $wpdb;
		$tables = self::tables();
		if ( null === $tables ) {
			return [];
		}

		$rules = [];
		if ( '' !== $tables['recurrences'] ) {
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$tables['recurrences']} ORDER BY event_id ASC, recurrence_order ASC, recurrence_set_id ASC", ARRAY_A ) as $row ) {
				$rules[ (int) $row['recurrence_set_id'] ] = [
					'set_id'     => (int) $row['recurrence_set_id'],
					'event_id'   => (int) $row['event_id'],
					'type'       => 'exclude' === ( $row['recurrence_type'] ?? '' ) ? 'exclude' : 'include',
					'freq'       => (string) ( $row['recurrence_freq'] ?? '' ),
					'interval'   => (int) ( $row['recurrence_interval'] ?? 1 ),
					'byday'      => (string) ( $row['recurrence_byday'] ?? '' ),
					'byweekno'   => (int) ( $row['recurrence_byweekno'] ?? 0 ),
					'duration'   => (int) ( $row['recurrence_duration'] ?? 0 ),
					'dates'      => (string) ( $row['recurrence_dates'] ?? '' ),
					'start_date' => (string) ( $row['recurrence_start_date'] ?? '' ),
					'end_date'   => (string) ( $row['recurrence_end_date'] ?? '' ),
					'end_after'  => (int) ( $row['recurrence_end_after'] ?? 0 ),
					'start_time' => (string) ( $row['recurrence_start_time'] ?? '' ),
					'end_time'   => (string) ( $row['recurrence_end_time'] ?? '' ),
					'all_day'    => ! empty( $row['recurrence_all_day'] ),
				];
			}
		}

		if ( in_array( 'recurrence_freq', self::columns( 'events' ), true ) ) {
			$has_flag = in_array( 'recurrence', self::columns( 'events' ), true );
			$where    = $has_flag ? 'recurrence = 1' : "recurrence_freq IS NOT NULL AND recurrence_freq <> ''";
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$tables['events']} WHERE {$where}", ARRAY_A ) as $row ) {
				$id = (int) $row['event_id'];
				foreach ( $rules as $rule ) {
					if ( $rule['event_id'] === $id ) {
						// Already converted to the rules table by a 7.x upgrade.
						continue 2;
					}
				}
				// Negative keys cannot collide with set ids of the rules table.
				$rules[ -$id ] = [
					'set_id'     => $id,
					'event_id'   => $id,
					'type'       => 'include',
					'freq'       => (string) ( $row['recurrence_freq'] ?? '' ),
					'interval'   => (int) ( $row['recurrence_interval'] ?? 1 ),
					'byday'      => (string) ( $row['recurrence_byday'] ?? '' ),
					'byweekno'   => (int) ( $row['recurrence_byweekno'] ?? 0 ),
					'duration'   => (int) ( $row['recurrence_days'] ?? 0 ),
					'dates'      => '',
					'start_date' => (string) ( $row['event_start_date'] ?? '' ),
					'end_date'   => (string) ( $row['event_end_date'] ?? '' ),
					'end_after'  => 0,
					'start_time' => (string) ( $row['event_start_time'] ?? '' ),
					'end_time'   => (string) ( $row['event_end_time'] ?? '' ),
					'all_day'    => ! empty( $row['event_all_day'] ),
				];
			}
		}
		return $rules;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function locations(): array {
		global $wpdb;
		$tables = self::tables();
		if ( null === $tables || '' === $tables['locations'] ) {
			return [];
		}
		$rows      = (array) $wpdb->get_results( "SELECT * FROM {$tables['locations']}" . self::blog_clause( $tables ) . ' ORDER BY location_id ASC', ARRAY_A );
		$locations = [];
		foreach ( $rows as $row ) {
			$locations[] = [
				'id'       => (int) $row['location_id'],
				'post_id'  => (int) ( $row['post_id'] ?? 0 ),
				'name'     => (string) ( $row['location_name'] ?? '' ),
				'content'  => (string) ( $row['post_content'] ?? '' ),
				'status'   => null === $row['location_status'] ? null : (int) $row['location_status'],
				'street'   => (string) ( $row['location_address'] ?? '' ),
				'postcode' => (string) ( $row['location_postcode'] ?? '' ),
				'city'     => (string) ( $row['location_town'] ?? '' ),
				'region'   => implode( ', ', array_filter( [ (string) ( $row['location_state'] ?? '' ), (string) ( $row['location_region'] ?? '' ) ], 'strlen' ) ),
				'country'  => (string) ( $row['location_country'] ?? '' ),
				'lat'      => (float) ( $row['location_latitude'] ?? 0 ),
				'lng'      => (float) ( $row['location_longitude'] ?? 0 ),
			];
		}
		return $locations;
	}

	/**
	 * Terms of a taxonomy, read without the taxonomy being registered.
	 *
	 * @return array<int, array{id: int, name: string, slug: string, parent: int, description: string}> By term id.
	 */
	public static function terms( string $taxonomy ): array {
		global $wpdb;
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, tt.parent, tt.description FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s ORDER BY tt.parent ASC, t.term_id ASC",
				$taxonomy
			),
			ARRAY_A
		);
		$terms = [];
		foreach ( $rows as $row ) {
			$terms[ (int) $row['term_id'] ] = [
				'id'          => (int) $row['term_id'],
				'name'        => (string) $row['name'],
				'slug'        => (string) $row['slug'],
				'parent'      => (int) $row['parent'],
				'description' => (string) $row['description'],
			];
		}
		return $terms;
	}

	/**
	 * @return list<int> Term ids of a post in a taxonomy.
	 */
	public static function post_terms( int $post_id, string $taxonomy ): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT tt.term_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s",
					$post_id,
					$taxonomy
				)
			)
		);
	}

	/**
	 * @return array<int, string> Category colour by term id.
	 */
	public static function category_colors(): array {
		global $wpdb;
		$tables = self::tables();
		if ( null === $tables || '' === $tables['meta'] ) {
			return [];
		}
		$colors = [];
		foreach ( (array) $wpdb->get_results( "SELECT object_id, meta_value FROM {$tables['meta']} WHERE meta_key = 'category-bgcolor'", ARRAY_A ) as $row ) {
			$colors[ (int) $row['object_id'] ] = (string) $row['meta_value'];
		}
		return $colors;
	}

	/**
	 * Event posts that have no row in the events table.
	 *
	 * @return list<int> Post ids.
	 */
	public static function posts_without_row(): array {
		global $wpdb;
		$tables = self::tables();
		if ( null === $tables ) {
			return [];
		}
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$tables['events']} e ON e.post_id = p.ID WHERE p.post_type = %s AND p.post_status NOT IN ( 'auto-draft', 'trash', 'inherit' ) AND e.event_id IS NULL ORDER BY p.ID ASC",
					self::POST_TYPE_EVENT
				)
			)
		);
	}

	/**
	 * What a later cleanup would remove; only counted here.
	 *
	 * @return array{tables: list<string>, options: int, posts: int}
	 */
	public static function leftovers(): array {
		global $wpdb;
		$tables = [];
		foreach ( array_unique( [ $wpdb->prefix, $wpdb->base_prefix ] ) as $prefix ) {
			$tables = array_merge( $tables, (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . 'em_' ) . '%' ) ) );
		}
		return [
			'tables'  => array_values( array_unique( $tables ) ),
			'options' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'dbem\_%'" ),
			'posts'   => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( %s, %s, %s )",
					self::POST_TYPE_EVENT,
					self::POST_TYPE_RECURRING,
					self::POST_TYPE_LOCATION
				)
			),
		];
	}

	/**
	 * @return list<string>
	 */
	private static function columns( string $table ): array {
		global $wpdb;
		static $cache = [];
		$tables       = self::tables();
		if ( null === $tables || '' === ( $tables[ $table ] ?? '' ) ) {
			return [];
		}
		if ( ! isset( $cache[ $table ] ) ) {
			$cache[ $table ] = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$tables[ $table ]}" ) );
		}
		return $cache[ $table ];
	}

	/**
	 * With shared tables on a network only the rows of this site count.
	 *
	 * @param array{global: bool} $tables
	 */
	private static function blog_clause( array $tables ): string {
		global $wpdb;
		if ( ! $tables['global'] ) {
			return '';
		}
		$blog_id = get_current_blog_id();
		return is_main_site() ? $wpdb->prepare( ' WHERE ( blog_id = %d OR blog_id IS NULL OR blog_id = 0 )', $blog_id ) : $wpdb->prepare( ' WHERE blog_id = %d', $blog_id );
	}

	private static function exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}
}
