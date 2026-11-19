<?php
/**
 * The only reader of the occurrences index for lists and calendars.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Query;

use RMD\EventsOrganizer\Clock;
use RMD\EventsOrganizer\Domain\Dates;
use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Domain\Occurrence;
use RMD\EventsOrganizer\Modules;
use RMD\EventsOrganizer\Polylang;
use RMD\EventsOrganizer\Schema;
use RMD\EventsOrganizer\Taxonomies;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own table; every value is passed through $wpdb->prepare() below.

final class OccurrenceQuery {

	public const SCOPES = [ 'upcoming', 'past', 'all', 'today', 'tomorrow', 'month', 'next-month' ];

	/** Upper bound for one result set, whatever the caller asks for. */
	public const HARD_LIMIT = 1000;

	/**
	 * @param array $args {
	 *     @type string       $scope            One of self::SCOPES; ignored when from/to are given.
	 *     @type string       $from             First day (Y-m-d).
	 *     @type string       $to               Last day (Y-m-d).
	 *     @type string       $kind             "event", "market" or "" for both.
	 *     @type int          $event_id         Only this event.
	 *     @type int          $location         Only this location.
	 *     @type string|array $category         Category slugs or ids; children included.
	 *     @type string|array $exclude_category Category slugs or ids to leave out.
	 *     @type string|array $tag              Tag slugs or ids.
	 *     @type string       $lang             With Polylang: language code; default is the language of the page.
	 *                                         Not applied when one event is asked for.
	 *     @type bool         $unique           Only the first matching date of each event.
	 *     @type string       $order            "asc" or "desc"; default depends on the scope.
	 *     @type int          $limit            Maximum number of rows (0 = hard limit).
	 *     @type int          $offset           Rows to skip.
	 * }
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public static function find( array $args ): array {
		global $wpdb;

		$table  = Schema::occurrences();
		$where  = [ "o.post_status = 'publish'", $wpdb->prepare( 'o.status <> %s', Occurrence::STATUS_REMOVED ) ];
		$scope  = in_array( $args['scope'] ?? '', self::SCOPES, true ) ? (string) $args['scope'] : 'upcoming';
		$from   = (string) ( $args['from'] ?? '' );
		$to     = (string) ( $args['to'] ?? '' );
		$today  = Clock::today();
		$is_asc = 'past' !== $scope;

		if ( Dates::is_valid( $from ) || Dates::is_valid( $to ) ) {
			$scope = 'range';
		} else {
			switch ( $scope ) {
				case 'today':
					$from = $today;
					$to   = $today;
					break;
				case 'tomorrow':
					$from = Dates::add_days( $today, 1 );
					$to   = $from;
					break;
				case 'month':
				case 'next-month':
					$month = 'month' === $scope ? substr( $today, 0, 7 ) : Dates::add_months( substr( $today, 0, 7 ), 1 );
					$from  = $month . '-01';
					$to    = sprintf( '%s-%02d', $month, Dates::days_in_month( (int) substr( $month, 0, 4 ), (int) substr( $month, 5, 2 ) ) );
					break;
			}
		}

		if ( 'upcoming' === $scope ) {
			$where[] = $wpdb->prepare( 'o.end_utc >= %s', gmdate( 'Y-m-d H:i:s', Clock::timestamp() ) );
		} elseif ( 'past' === $scope ) {
			$where[] = $wpdb->prepare( 'o.end_utc < %s', gmdate( 'Y-m-d H:i:s', Clock::timestamp() ) );
		} else {
			if ( Dates::is_valid( $to ) ) {
				$where[] = $wpdb->prepare( 'o.start_date <= %s', $to );
			}
			if ( Dates::is_valid( $from ) ) {
				$where[] = $wpdb->prepare( 'o.end_date >= %s', $from );
			}
		}

		$kind = (string) ( $args['kind'] ?? '' );
		if ( ! Modules::is_on( Modules::MARKETS ) ) {
			if ( Kinds::MARKET === $kind ) {
				return self::nothing();
			}
			$kind = '';
		}
		if ( Kinds::is_valid( $kind ) ) {
			$where[] = $wpdb->prepare( 'o.kind = %s', $kind );
		}
		if ( ! empty( $args['event_id'] ) ) {
			$where[] = $wpdb->prepare( 'o.event_id = %d', (int) $args['event_id'] );
		}
		if ( ! empty( $args['location'] ) ) {
			$where[] = $wpdb->prepare( 'o.location_id = %d', (int) $args['location'] );
		}

		// On a multilingual site a list shows the events of its language. A single event is shown wherever it is placed.
		$language = empty( $args['event_id'] ) ? Polylang::term_taxonomy_id( Polylang::language( (string) ( $args['lang'] ?? '' ) ) ) : 0;
		if ( $language > 0 ) {
			$where[] = $wpdb->prepare( "o.event_id IN ( SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = %d )", $language );
		}

		foreach (
			[
				[ 'category', Taxonomies::CATEGORY, 'IN' ],
				[ 'tag', Taxonomies::TAG, 'IN' ],
				[ 'exclude_category', Taxonomies::CATEGORY, 'NOT IN' ],
			] as [ $key, $taxonomy, $operator ]
		) {
			if ( empty( $args[ $key ] ) ) {
				continue;
			}
			$ids = self::term_taxonomy_ids( $args[ $key ], $taxonomy );
			if ( [] === $ids ) {
				if ( 'IN' === $operator ) {
					return self::nothing();
				}
				continue;
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$where[]      = $wpdb->prepare(
				"o.event_id {$operator} ( SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id IN ( {$placeholders} ) )",
				$ids
			);
		}

		if ( isset( $args['order'] ) && in_array( strtolower( (string) $args['order'] ), [ 'asc', 'desc' ], true ) ) {
			$is_asc = 'asc' === strtolower( (string) $args['order'] );
		}
		$direction = $is_asc ? 'ASC' : 'DESC';
		$limit     = (int) ( $args['limit'] ?? 0 );
		$limit     = $limit > 0 ? min( $limit, self::HARD_LIMIT ) : self::HARD_LIMIT;
		$offset    = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$unique    = ! empty( $args['unique'] );

		$sql  = "SELECT o.* FROM {$table} o WHERE " . implode( ' AND ', $where ) . " ORDER BY o.start_utc {$direction}, o.end_utc {$direction}, o.id {$direction}";
		$rows = (array) $wpdb->get_results( $sql . $wpdb->prepare( ' LIMIT %d', self::HARD_LIMIT ), ARRAY_A );

		if ( $unique ) {
			$seen = [];
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( &$seen ): bool {
						if ( isset( $seen[ $row['event_id'] ] ) ) {
							return false;
						}
						$seen[ $row['event_id'] ] = true;
						return true;
					}
				)
			);
		}

		$total = count( $rows );
		$rows  = array_slice( $rows, $offset, $limit );
		$items = array_map( [ self::class, 'cast' ], $rows );

		if ( [] !== $items ) {
			// One query for all posts instead of one per item.
			_prime_post_caches( array_values( array_unique( array_merge( array_column( $items, 'event_id' ), array_filter( array_column( $items, 'location_id' ) ) ) ) ), true, true );
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * A single published occurrence by its row id.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::occurrences();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND post_status = 'publish' AND status <> %s", $id, Occurrence::STATUS_REMOVED ), ARRAY_A );
		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * First and last month ("Y-m") that has a published occurrence, or null
	 * when there is none. Calendars do not navigate beyond these.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public static function month_bounds( string $kind = '' ): ?array {
		global $wpdb;
		$table = Schema::occurrences();
		$where = $wpdb->prepare( "post_status = 'publish' AND status <> %s", Occurrence::STATUS_REMOVED );
		if ( Kinds::is_valid( $kind ) ) {
			$where .= $wpdb->prepare( ' AND kind = %s', $kind );
		}
		$row = $wpdb->get_row( "SELECT MIN(start_date) AS first, MAX(end_date) AS last FROM {$table} WHERE {$where}", ARRAY_A );
		if ( ! is_array( $row ) || empty( $row['first'] ) || empty( $row['last'] ) ) {
			return null;
		}
		return [ substr( (string) $row['first'], 0, 7 ), substr( (string) $row['last'], 0, 7 ) ];
	}

	/**
	 * @param array<string, string|null> $row
	 * @return array<string, mixed>
	 */
	private static function cast( array $row ): array {
		return [
			'id'          => (int) $row['id'],
			'event_id'    => (int) $row['event_id'],
			'key'         => (string) $row['occ_key'],
			'kind'        => (string) $row['kind'],
			'start_date'  => (string) $row['start_date'],
			'end_date'    => (string) $row['end_date'],
			'start_time'  => null === $row['start_time'] ? '' : substr( (string) $row['start_time'], 0, 5 ),
			'end_time'    => null === $row['end_time'] ? '' : substr( (string) $row['end_time'], 0, 5 ),
			'all_day'     => (bool) $row['all_day'],
			'time_mode'   => (string) $row['time_mode'],
			'start_ts'    => (int) strtotime( $row['start_utc'] . ' UTC' ),
			'end_ts'      => (int) strtotime( $row['end_utc'] . ' UTC' ),
			'cancelled'   => Occurrence::STATUS_CANCELLED === $row['status'],
			'withdrawn'   => Occurrence::STATUS_WITHDRAWN === $row['status'],
			'location_id' => (int) $row['location_id'],
			'note'        => (string) $row['note'],
		];
	}

	/**
	 * @param string|array $terms    Comma separated or list of slugs and ids.
	 * @param string       $taxonomy
	 * @return list<int> term_taxonomy_ids including children.
	 */
	private static function term_taxonomy_ids( $terms, string $taxonomy ): array {
		$terms = is_array( $terms ) ? $terms : explode( ',', (string) $terms );
		$ids   = [];
		foreach ( $terms as $term ) {
			$term = trim( (string) $term );
			if ( '' === $term ) {
				continue;
			}
			$found = ctype_digit( $term ) ? get_term( (int) $term, $taxonomy ) : get_term_by( 'slug', sanitize_title( $term ), $taxonomy );
			if ( ! $found instanceof \WP_Term ) {
				continue;
			}
			$ids[] = (int) $found->term_taxonomy_id;
			if ( is_taxonomy_hierarchical( $taxonomy ) ) {
				foreach ( (array) get_term_children( $found->term_id, $taxonomy ) as $child_id ) {
					$child = get_term( (int) $child_id, $taxonomy );
					if ( $child instanceof \WP_Term ) {
						$ids[] = (int) $child->term_taxonomy_id;
					}
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	private static function nothing(): array {
		return [
			'items' => [],
			'total' => 0,
		];
	}
}
