<?php
/**
 * Finds what an item of Events Manager became here: the event for an old
 * event or post id, the location, the category or tag.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Migration;

use RMD\EventsOrganizer\PostTypes;
use RMD\EventsOrganizer\Taxonomies;

defined( 'ABSPATH' ) || exit;

final class Targets {

	/** @var array<int, array<string, mixed>>|null Source events by id. */
	private static ?array $events = null;

	/**
	 * Event here for an id of the old events table. A date of a series leads
	 * to the series.
	 */
	public static function event( int $source_id ): int {
		$found = Importer::find_post( PostTypes::EVENT, Importer::SOURCE_EVENT, $source_id );
		if ( $found > 0 ) {
			return $found;
		}
		$source = self::events()[ $source_id ] ?? null;
		if ( null === $source ) {
			return 0;
		}
		if ( 'recurrence' === $source['type'] && $source['parent_id'] > 0 && $source['parent_id'] !== $source_id ) {
			return Importer::find_post( PostTypes::EVENT, Importer::SOURCE_EVENT, (int) $source['parent_id'] );
		}
		return 0;
	}

	/**
	 * Event here for the id of an old event post.
	 */
	public static function event_by_post( int $source_post ): int {
		if ( $source_post <= 0 ) {
			return 0;
		}
		foreach ( self::events() as $source ) {
			if ( $source['post_id'] === $source_post ) {
				$found = self::event( (int) $source['id'] );
				if ( $found > 0 ) {
					return $found;
				}
			}
		}
		return Importer::find_post( PostTypes::EVENT, Importer::SOURCE_POST, $source_post );
	}

	/**
	 * Location here for an id of the old locations table.
	 */
	public static function location( int $source_id ): int {
		return Importer::find_post( PostTypes::LOCATION, Importer::SOURCE_LOCATION, $source_id );
	}

	/**
	 * Slug of the category or tag here for an old term id or slug, or ''.
	 *
	 * @param string $taxonomy Taxonomy of this plugin.
	 */
	public static function term_slug( string $taxonomy, string $id_or_slug ): string {
		$source_taxonomy = Taxonomies::CATEGORY === $taxonomy ? EmReader::TAXONOMY_CATEGORY : EmReader::TAXONOMY_TAG;
		$source_id       = 0;

		if ( ctype_digit( $id_or_slug ) ) {
			$source_id = (int) $id_or_slug;
		} else {
			foreach ( EmReader::terms( $source_taxonomy ) as $term ) {
				if ( $term['slug'] === $id_or_slug || 0 === strcasecmp( $term['name'], $id_or_slug ) ) {
					$source_id = $term['id'];
					break;
				}
			}
		}

		if ( $source_id > 0 ) {
			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 1,
					'lang'       => '',
					'meta_key'   => Importer::SOURCE_TERM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup per converted filter.
					'meta_value' => (string) $source_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			);
			if ( is_array( $terms ) && [] !== $terms ) {
				return (string) $terms[0]->slug;
			}
		}

		$term = ctype_digit( $id_or_slug ) ? null : get_term_by( 'slug', $id_or_slug, $taxonomy );
		return $term ? (string) $term->slug : '';
	}

	/**
	 * The lookups in the form the converter expects.
	 *
	 * @return array<string, callable>
	 */
	public static function resolvers(): array {
		return [
			'category'   => static fn( string $key ): string => self::term_slug( Taxonomies::CATEGORY, $key ),
			'tag'        => static fn( string $key ): string => self::term_slug( Taxonomies::TAG, $key ),
			'location'   => static fn( int $id ): int => self::location( $id ),
			'event'      => static fn( int $id ): int => self::event( $id ),
			'event_post' => static fn( int $id ): int => self::event_by_post( $id ),
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function events(): array {
		if ( null === self::$events ) {
			self::$events = array_column( EmReader::events(), null, 'id' );
		}
		return self::$events;
	}
}
