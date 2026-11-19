<?php
/**
 * Creates events, locations, categories and tags from a plan. The source
 * data is only read; everything created here remembers where it came from,
 * so a second run updates instead of duplicating and the import can be
 * undone.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Migration;

use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Domain\Occurrence;
use RMD\EventsOrganizer\Domain\Validator;
use RMD\EventsOrganizer\Meta;
use RMD\EventsOrganizer\Modules;
use RMD\EventsOrganizer\Polylang;
use RMD\EventsOrganizer\PostTypes;
use RMD\EventsOrganizer\Repository\EventRepository;
use RMD\EventsOrganizer\Sync\OccurrenceSync;
use RMD\EventsOrganizer\Taxonomies;

defined( 'ABSPATH' ) || exit;

final class Importer {

	/** Post meta: id of the source row in the events table. */
	public const SOURCE_EVENT = '_rmd_eo_em_event_id';
	/** Post meta: id of the source post (used when the row is missing). */
	public const SOURCE_POST = '_rmd_eo_em_post_id';
	/** Post meta: id of the source row in the locations table. */
	public const SOURCE_LOCATION = '_rmd_eo_em_location_id';
	/** Term meta: id of the source term. */
	public const SOURCE_TERM = 'rmd_eo_em_term_id';

	private const TAXONOMIES = [
		EmReader::TAXONOMY_CATEGORY => Taxonomies::CATEGORY,
		EmReader::TAXONOMY_TAG      => Taxonomies::TAG,
	];

	/** Meta of SEO plugins that is copied along. */
	private const COPIED_META_PREFIXES = [ '_yoast_wpseo_', 'rank_math_', '_aioseo_', '_seopress_' ];

	/**
	 * Categories, tags and locations. Small in number, so they are done in
	 * one go before the events.
	 *
	 * @return array{terms: int, locations: int}
	 */
	public static function import_basics(): array {
		$terms = 0;
		foreach ( self::TAXONOMIES as $source => $target ) {
			$terms += self::import_terms( $source, $target );
		}
		return [
			'terms'     => $terms,
			'locations' => Modules::is_on( Modules::LOCATIONS ) ? self::import_locations() : 0,
		];
	}

	/**
	 * @param list<array<string, mixed>> $units Units of Planner::plan().
	 * @return array{created: int, updated: int}
	 */
	public static function import_units( array $units ): array {
		$created = 0;
		$updated = 0;
		foreach ( $units as $unit ) {
			if ( self::import_unit( $unit ) ) {
				++$created;
			} else {
				++$updated;
			}
		}
		return [
			'created' => $created,
			'updated' => $updated,
		];
	}

	/**
	 * Number of imported items that exist right now.
	 *
	 * @return array{events: int, locations: int, terms: int}
	 */
	public static function imported(): array {
		return [
			'events'    => count( self::imported_posts( PostTypes::EVENT ) ),
			'locations' => count( self::imported_posts( PostTypes::LOCATION ) ),
			'terms'     => count( self::imported_terms() ),
		];
	}

	/**
	 * Deletes everything an import created. The source data is untouched.
	 *
	 * Pages, widgets and menus changed afterwards are put back first: they
	 * refer to the events deleted here, and WordPress would delete menu items
	 * together with the event they lead to.
	 *
	 * @return array{events: int, locations: int, terms: int}
	 */
	public static function undo(): array {
		MenuRewriter::undo_all();
		ContentRewriter::undo_all();
		WidgetRewriter::undo_all();

		$counts = [
			'events'    => 0,
			'locations' => 0,
			'terms'     => 0,
		];
		foreach ( self::imported_posts( PostTypes::EVENT ) as $post_id ) {
			wp_delete_post( $post_id, true );
			++$counts['events'];
		}
		foreach ( self::imported_posts( PostTypes::LOCATION ) as $post_id ) {
			wp_delete_post( $post_id, true );
			++$counts['locations'];
		}
		foreach ( self::imported_terms() as $term ) {
			wp_delete_term( $term->term_id, $term->taxonomy );
			++$counts['terms'];
		}
		return $counts;
	}

	/* ------------------------------------------------------------------ */
	/* Terms and locations                                                 */
	/* ------------------------------------------------------------------ */

	private static function import_terms( string $source, string $target ): int {
		$colors = EmReader::category_colors();
		$map    = self::term_map( $target );
		$count  = 0;

		// Parents come first (the reader orders by parent), so children find theirs.
		foreach ( EmReader::terms( $source ) as $term ) {
			$args = [
				'slug'        => $term['slug'],
				'description' => $term['description'],
				'parent'      => $term['parent'] > 0 ? (int) ( $map[ $term['parent'] ] ?? 0 ) : 0,
			];

			if ( isset( $map[ $term['id'] ] ) ) {
				wp_update_term( $map[ $term['id'] ], $target, [ 'name' => $term['name'] ] + $args );
				$term_id = $map[ $term['id'] ];
			} else {
				$result = wp_insert_term( $term['name'], $target, $args );
				if ( is_wp_error( $result ) ) {
					// A term of that name exists already (entered by hand): use it.
					$existing = get_term_by( 'slug', $term['slug'], $target );
					if ( ! $existing ) {
						continue;
					}
					$term_id = (int) $existing->term_id;
				} else {
					$term_id = (int) $result['term_id'];
				}
				update_term_meta( $term_id, self::SOURCE_TERM, $term['id'] );
				$map[ $term['id'] ] = $term_id;
			}

			Polylang::copy_term_language( $term['id'], $term_id, static fn( int $source_id ): int => (int) ( $map[ $source_id ] ?? 0 ) );

			$color = (string) sanitize_hex_color( $colors[ $term['id'] ] ?? '' );
			if ( Taxonomies::CATEGORY === $target && '' !== $color ) {
				update_term_meta( $term_id, Taxonomies::COLOR, $color );
			}
			++$count;
		}
		return $count;
	}

	private static function import_locations(): int {
		$validator = Meta::validator();
		$count     = 0;

		foreach ( EmReader::locations() as $location ) {
			$post = $location['post_id'] > 0 ? get_post( $location['post_id'] ) : null;
			if ( $post && 'trash' === $post->post_status ) {
				continue;
			}
			$postarr = [
				'post_type'    => PostTypes::LOCATION,
				'post_title'   => $post ? $post->post_title : $location['name'],
				'post_content' => $post ? $post->post_content : $location['content'],
				'post_status'  => $post && in_array( $post->post_status, [ 'publish', 'private', 'draft', 'pending' ], true ) ? $post->post_status : ( 1 === $location['status'] ? 'publish' : 'draft' ),
				'post_name'    => $post ? $post->post_name : '',
			];

			$existing = self::find_post( PostTypes::LOCATION, self::SOURCE_LOCATION, $location['id'] );
			if ( $existing ) {
				$postarr['ID'] = $existing;
			}
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			$address = $validator->normalize_address(
				[
					'street'   => $location['street'],
					'postcode' => $location['postcode'],
					'city'     => $location['city'],
					'region'   => $location['region'],
					'country'  => $location['country'],
					// The source stores 0/0 for "no coordinates".
					'lat'      => 0.0 === $location['lat'] && 0.0 === $location['lng'] ? '' : $location['lat'],
					'lng'      => 0.0 === $location['lat'] && 0.0 === $location['lng'] ? '' : $location['lng'],
				]
			)['data'];
			update_post_meta( (int) $post_id, Meta::ADDRESS, wp_slash( $address ) );
			update_post_meta( (int) $post_id, self::SOURCE_LOCATION, $location['id'] );
			if ( $post ) {
				self::copy_extras( (int) $post->ID, (int) $post_id );
			}
			++$count;
		}
		return $count;
	}

	/* ------------------------------------------------------------------ */
	/* Events                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array<string, mixed> $unit
	 * @return bool True when a new event was created, false when one was updated.
	 */
	private static function import_unit( array $unit ): bool {
		$existing = $unit['source_id'] > 0
			? self::find_post( PostTypes::EVENT, self::SOURCE_EVENT, (int) $unit['source_id'] )
			: self::find_post( PostTypes::EVENT, self::SOURCE_POST, (int) $unit['source_post'] );

		$postarr = [
			'post_type'    => PostTypes::EVENT,
			'post_title'   => $unit['title'],
			'post_content' => $unit['content'],
			'post_excerpt' => $unit['excerpt'],
			'post_status'  => $unit['status'],
			'post_name'    => $unit['slug'],
		];
		if ( $unit['author'] > 0 && get_userdata( (int) $unit['author'] ) ) {
			$postarr['post_author'] = (int) $unit['author'];
		}
		if ( '' !== $unit['date'] ) {
			$postarr['post_date'] = $unit['date'];
		}
		if ( $existing ) {
			$postarr['ID'] = $existing;
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return false;
		}
		$post_id = (int) $post_id;

		$details           = Validator::empty_details( Kinds::EVENT );
		$details['status'] = $unit['cancelled'] ? Occurrence::STATUS_CANCELLED : Occurrence::STATUS_SCHEDULED;
		if ( $existing ) {
			// Keep what was added here after the first import.
			$details = [ 'status' => $details['status'] ] + EventRepository::details( $post_id );
			$details = Meta::validator()->normalize_details( $details, EventRepository::kind( $post_id ) )['data'];
		}

		EventRepository::write(
			$post_id,
			[
				'kind'        => $existing ? EventRepository::kind( $post_id ) : Kinds::EVENT,
				'schedule'    => $unit['schedule'],
				'details'     => $details,
				'location_id' => $unit['location'] > 0 ? self::find_post( PostTypes::LOCATION, self::SOURCE_LOCATION, (int) $unit['location'] ) : 0,
			]
		);

		if ( $unit['source_id'] > 0 ) {
			update_post_meta( $post_id, self::SOURCE_EVENT, (int) $unit['source_id'] );
		}
		if ( $unit['source_post'] > 0 ) {
			update_post_meta( $post_id, self::SOURCE_POST, (int) $unit['source_post'] );

			// Before the categories: Polylang only accepts terms in the language of the post.
			Polylang::copy_post_language( (int) $unit['source_post'], $post_id, [ Targets::class, 'event_by_post' ] );

			foreach ( self::TAXONOMIES as $source => $target ) {
				$map   = self::term_map( $target );
				$terms = [];
				foreach ( EmReader::post_terms( (int) $unit['source_post'], $source ) as $term_id ) {
					if ( isset( $map[ $term_id ] ) ) {
						$terms[] = $map[ $term_id ];
					}
				}
				wp_set_object_terms( $post_id, $terms, $target );
			}
			self::copy_extras( (int) $unit['source_post'], $post_id );
		}

		// Addresses of the single dates of a series lead to the series.
		$known = get_post_meta( $post_id, '_wp_old_slug', false );
		foreach ( array_unique( $unit['old_slugs'] ) as $slug ) {
			if ( ! in_array( $slug, $known, true ) && get_post_field( 'post_name', $post_id ) !== $slug ) {
				add_post_meta( $post_id, '_wp_old_slug', $slug );
			}
		}

		OccurrenceSync::sync( $post_id );
		return ! $existing;
	}

	/**
	 * Image and SEO fields of the source post.
	 */
	private static function copy_extras( int $source_post, int $target_post ): void {
		$thumbnail = (int) get_post_meta( $source_post, '_thumbnail_id', true );
		if ( $thumbnail > 0 && get_post( $thumbnail ) ) {
			set_post_thumbnail( $target_post, $thumbnail );
		}
		foreach ( (array) get_post_meta( $source_post ) as $key => $values ) {
			foreach ( self::COPIED_META_PREFIXES as $prefix ) {
				if ( 0 === strpos( (string) $key, $prefix ) ) {
					update_post_meta( $target_post, (string) $key, wp_slash( maybe_unserialize( $values[0] ) ) );
				}
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Lookups                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Id of the post created from a source id, or 0.
	 */
	public static function find_post( string $post_type, string $meta_key, int $source_id ): int {
		if ( $source_id <= 0 ) {
			return 0;
		}
		$found = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => [ 'publish', 'private', 'draft', 'pending', 'future', 'trash' ],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup per imported item.
				'meta_value'     => (string) $source_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// Polylang: in every language, whatever the language filter of the admin is set to.
				'lang'           => '',
			]
		);
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * @return array<int, int> Source term id => term id here.
	 */
	private static function term_map( string $taxonomy ): array {
		$map = [];
		foreach ( self::imported_terms( $taxonomy ) as $term ) {
			$map[ (int) get_term_meta( $term->term_id, self::SOURCE_TERM, true ) ] = (int) $term->term_id;
		}
		return $map;
	}

	/**
	 * @return list<\WP_Term>
	 */
	private static function imported_terms( string $taxonomy = '' ): array {
		$terms = get_terms(
			[
				'taxonomy'   => '' !== $taxonomy ? $taxonomy : array_values( self::TAXONOMIES ),
				'hide_empty' => false,
				'meta_key'   => self::SOURCE_TERM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				// Children before parents, so deleting does not re-parent on the way.
				'orderby'    => 'term_id',
				'order'      => 'DESC',
				'lang'       => '',
			]
		);
		return is_array( $terms ) ? array_values( $terms ) : [];
	}

	/**
	 * @return list<int>
	 */
	private static function imported_posts( string $post_type ): array {
		global $wpdb;
		$keys = PostTypes::LOCATION === $post_type ? [ self::SOURCE_LOCATION ] : [ self::SOURCE_EVENT, self::SOURCE_POST ];
		$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built above.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = %s AND m.meta_key IN ( {$in} )", array_merge( [ $post_type ], $keys ) ) ) );
	}
}
