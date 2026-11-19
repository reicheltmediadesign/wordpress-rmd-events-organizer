<?php
/**
 * Creates events from the units CsvPlanner built out of a CSV file. An event
 * that has a key remembers it, so importing the file again updates the event
 * instead of creating it a second time.
 *
 * On an update the dates are replaced by those of the file; every other
 * value only when the file has one, so what was added by hand stays.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Import;

use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Meta;
use RMD\EventsOrganizer\Modules;
use RMD\EventsOrganizer\Polylang;
use RMD\EventsOrganizer\PostTypes;
use RMD\EventsOrganizer\Repository\EventRepository;
use RMD\EventsOrganizer\Repository\LocationRepository;
use RMD\EventsOrganizer\Sync\OccurrenceSync;
use RMD\EventsOrganizer\Taxonomies;

defined( 'ABSPATH' ) || exit;

final class CsvImporter {

	/** Post meta: the key the event has in the file. */
	public const SOURCE_KEY = '_rmd_eo_csv_key';

	public const NOTE_IMAGE    = 'image_not_found';
	public const NOTE_LANGUAGE = 'language_unknown';
	public const NOTE_FAILED   = 'not_saved';

	private const STATUSES = [ 'publish', 'private', 'draft', 'pending', 'future' ];

	/**
	 * @param list<array<string, mixed>> $units Units of CsvPlanner::plan().
	 * @return array{created: int, updated: int, locations: int, terms: int, notes: list<array{title: string, code: string, arg: string}>}
	 */
	public static function import( array $units ): array {
		$result = [
			'created'   => 0,
			'updated'   => 0,
			'locations' => 0,
			'terms'     => 0,
			'notes'     => [],
		];
		foreach ( $units as $unit ) {
			self::import_unit( $unit, $result );
		}
		return $result;
	}

	/**
	 * Id of the event imported with a key, or 0.
	 */
	public static function find_event( string $key ): int {
		if ( '' === $key ) {
			return 0;
		}
		$found = get_posts(
			[
				'post_type'      => PostTypes::EVENT,
				'post_status'    => self::STATUSES,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::SOURCE_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup per imported event.
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// Polylang: in every language.
				'lang'           => '',
			]
		);
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Id of the location with this name, or 0.
	 */
	public static function find_location( string $name ): int {
		if ( '' === $name || ! Modules::is_on( Modules::LOCATIONS ) ) {
			return 0;
		}
		$found = get_posts(
			[
				'post_type'      => PostTypes::LOCATION,
				'post_status'    => [ 'publish', 'private', 'draft', 'pending' ],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'title'          => $name,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'lang'           => '',
			]
		);
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * @param array<string, mixed> $unit
	 * @param array<string, mixed> $result Counted up.
	 */
	private static function import_unit( array $unit, array &$result ): void {
		$existing = self::find_event( (string) $unit['key'] );

		$postarr = [
			'post_type'   => PostTypes::EVENT,
			'post_title'  => (string) $unit['title'],
			'post_status' => (string) $unit['status'],
		];
		if ( '' !== $unit['description'] || ! $existing ) {
			$postarr['post_content'] = (string) $unit['description'];
		}
		if ( $existing ) {
			$postarr['ID'] = $existing;
		}
		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			$result['notes'][] = self::note( $unit, self::NOTE_FAILED, $post_id->get_error_message() );
			return;
		}
		$post_id = (int) $post_id;

		$kind    = Kinds::MARKET === $unit['kind'] && ! Modules::is_on( Modules::MARKETS ) ? Kinds::EVENT : (string) $unit['kind'];
		$details = array_filter( (array) $unit['details'], 'strlen' );
		$stored  = $existing ? EventRepository::schedule( $post_id ) : [];
		if ( $existing ) {
			$details += EventRepository::details( $post_id );
		}

		$location = (int) ( $existing ? get_post_meta( $post_id, Meta::LOCATION, true ) : 0 );
		if ( '' !== $unit['location']['name'] && Modules::is_on( Modules::LOCATIONS ) ) {
			$location = self::find_location( (string) $unit['location']['name'] );
			if ( 0 === $location ) {
				$location             = LocationRepository::create( (string) $unit['location']['name'], Meta::validator()->normalize_address( $unit['location'] )['data'] );
				$result['locations'] += $location > 0 ? 1 : 0;
			}
		}

		EventRepository::write(
			$post_id,
			[
				'kind'        => $kind,
				// Cancelled dates and notes entered by hand stay with the dates that are still there.
				'schedule'    => Meta::validator()->normalize_schedule(
					[
						'entries'   => $unit['entries'],
						'overrides' => (array) ( $stored['overrides'] ?? [] ),
					]
				)['data'],
				'details'     => Meta::validator()->normalize_details( $details, $kind )['data'],
				'location_id' => $location,
			]
		);
		if ( '' !== $unit['key'] ) {
			update_post_meta( $post_id, self::SOURCE_KEY, wp_slash( (string) $unit['key'] ) );
		}

		// Before the categories: Polylang only accepts terms in the language of the post.
		$language = self::language( $unit, $post_id, (bool) $existing, $result );

		foreach (
			[
				Taxonomies::CATEGORY => $unit['categories'],
				Taxonomies::TAG      => $unit['tags'],
			] as $taxonomy => $names
		) {
			if ( [] !== $names ) {
				wp_set_object_terms( $post_id, self::terms( (array) $names, $taxonomy, $language, $result ), $taxonomy );
			}
		}

		if ( '' !== $unit['image'] ) {
			$image = self::image( (string) $unit['image'] );
			if ( $image > 0 ) {
				set_post_thumbnail( $post_id, $image );
			} else {
				$result['notes'][] = self::note( $unit, self::NOTE_IMAGE, (string) $unit['image'] );
			}
		}

		OccurrenceSync::sync( $post_id );
		++$result[ $existing ? 'updated' : 'created' ];
	}

	/**
	 * Gives the event its language and returns the code, '' without Polylang.
	 *
	 * @param array<string, mixed> $unit
	 * @param array<string, mixed> $result
	 */
	private static function language( array $unit, int $post_id, bool $existing, array &$result ): string {
		if ( ! Polylang::is_active() || ! function_exists( 'pll_set_post_language' ) ) {
			return '';
		}
		$language = Polylang::language( (string) $unit['language'] );
		if ( '' !== $unit['language'] && '' === $language ) {
			$result['notes'][] = self::note( $unit, self::NOTE_LANGUAGE, (string) $unit['language'] );
		}
		if ( '' === $language && $existing && function_exists( 'pll_get_post_language' ) ) {
			$language = (string) pll_get_post_language( $post_id );
		}
		if ( '' === $language && function_exists( 'pll_default_language' ) ) {
			$language = (string) pll_default_language();
		}
		if ( '' !== $language ) {
			pll_set_post_language( $post_id, $language );
		}
		return $language;
	}

	/**
	 * Ids of the terms with these names; missing ones are created.
	 *
	 * @param list<string>         $names
	 * @param array<string, mixed> $result
	 * @return list<int>
	 */
	private static function terms( array $names, string $taxonomy, string $language, array &$result ): array {
		$ids = [];
		foreach ( $names as $name ) {
			$found = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'name'       => $name,
					'hide_empty' => false,
					'number'     => 1,
					'fields'     => 'ids',
					'lang'       => $language,
				]
			);
			if ( is_array( $found ) && [] !== $found ) {
				$ids[] = (int) $found[0];
				continue;
			}

			$created = wp_insert_term( $name, $taxonomy );
			if ( is_wp_error( $created ) ) {
				// The name is taken by a term this lookup did not see (another language): use that one.
				$taken = (int) $created->get_error_data( 'term_exists' );
				if ( $taken > 0 ) {
					$ids[] = $taken;
				}
				continue;
			}
			if ( '' !== $language && function_exists( 'pll_set_term_language' ) ) {
				pll_set_term_language( (int) $created['term_id'], $language );
			}
			$ids[] = (int) $created['term_id'];
			++$result['terms'];
		}
		return $ids;
	}

	/**
	 * Id of an image of the media library, given as id or address; 0 when there is none.
	 */
	private static function image( string $value ): int {
		$id = ctype_digit( $value ) ? (int) $value : (int) attachment_url_to_postid( $value );
		return $id > 0 && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/**
	 * @param array<string, mixed> $unit
	 * @return array{title: string, code: string, arg: string}
	 */
	private static function note( array $unit, string $code, string $arg ): array {
		return [
			'title' => (string) $unit['title'],
			'code'  => $code,
			'arg'   => $arg,
		];
	}
}
