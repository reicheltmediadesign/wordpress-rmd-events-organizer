<?php
/**
 * Works together with Polylang on multilingual sites: events, categories and
 * tags have a language, lists and calendars show the events of the language
 * of the page. Locations are shared by all languages.
 *
 * Everything here does nothing when Polylang is not active.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

defined( 'ABSPATH' ) || exit;

final class Polylang {

	public static function init(): void {
		add_filter( 'pll_get_post_types', [ self::class, 'post_types' ] );
		add_filter( 'pll_get_taxonomies', [ self::class, 'taxonomies' ] );
	}

	/**
	 * @param array<string, string> $post_types
	 * @return array<string, string>
	 */
	public static function post_types( $post_types ): array {
		$post_types                     = (array) $post_types;
		$post_types[ PostTypes::EVENT ] = PostTypes::EVENT;
		return $post_types;
	}

	/**
	 * @param array<string, string> $taxonomies
	 * @return array<string, string>
	 */
	public static function taxonomies( $taxonomies ): array {
		$taxonomies                         = (array) $taxonomies;
		$taxonomies[ Taxonomies::CATEGORY ] = Taxonomies::CATEGORY;
		$taxonomies[ Taxonomies::TAG ]      = Taxonomies::TAG;
		return $taxonomies;
	}

	public static function is_active(): bool {
		return function_exists( 'PLL' ) && function_exists( 'pll_current_language' ) && function_exists( 'pll_languages_list' );
	}

	/**
	 * Code of a language that exists: the given one, otherwise the language
	 * of the current page. Empty without Polylang or when neither is known.
	 */
	public static function language( string $slug = '' ): string {
		if ( ! self::is_active() ) {
			return '';
		}
		if ( '' !== $slug ) {
			return in_array( $slug, (array) pll_languages_list(), true ) ? $slug : '';
		}
		$current = pll_current_language();
		return is_string( $current ) ? $current : '';
	}

	/**
	 * Id that connects posts with a language in the database, or 0.
	 */
	public static function term_taxonomy_id( string $slug ): int {
		if ( '' === $slug || ! self::is_active() ) {
			return 0;
		}
		$term = get_term_by( 'slug', $slug, 'language' );
		return $term instanceof \WP_Term ? (int) $term->term_taxonomy_id : 0;
	}

	/**
	 * Locale of a language, e.g. "de_DE", or ''.
	 */
	public static function locale( string $slug ): string {
		if ( '' === self::language( $slug ) ) {
			return '';
		}
		foreach ( (array) pll_languages_list( [ 'fields' => '' ] ) as $language ) {
			if ( is_object( $language ) && ( $language->slug ?? '' ) === $slug ) {
				return (string) ( $language->locale ?? '' );
			}
		}
		return '';
	}

	/**
	 * Gives a post the language of another post and connects it with the
	 * posts that are the translations of that one.
	 *
	 * @param callable $map Turns the id of a translation of the source into the id of its copy, or 0.
	 */
	public static function copy_post_language( int $source, int $target, callable $map ): void {
		if ( ! self::is_active() || ! function_exists( 'pll_get_post_language' ) ) {
			return;
		}
		$language = pll_get_post_language( $source );
		if ( ! is_string( $language ) || '' === $language ) {
			return;
		}
		pll_set_post_language( $target, $language );

		$group = [ $language => $target ];
		foreach ( (array) pll_get_post_translations( $source ) as $code => $translation ) {
			$copy = (int) $translation === $source ? $target : (int) $map( (int) $translation );
			if ( $copy > 0 ) {
				$group[ (string) $code ] = $copy;
			}
		}
		if ( count( $group ) > 1 ) {
			pll_save_post_translations( $group );
		}
	}

	/**
	 * The same for a category or tag.
	 *
	 * @param callable $map Turns the id of a translation of the source into the id of its copy, or 0.
	 */
	public static function copy_term_language( int $source, int $target, callable $map ): void {
		if ( ! self::is_active() || ! function_exists( 'pll_get_term_language' ) ) {
			return;
		}
		$language = pll_get_term_language( $source );
		if ( ! is_string( $language ) || '' === $language ) {
			return;
		}
		pll_set_term_language( $target, $language );

		$group = [ $language => $target ];
		foreach ( (array) pll_get_term_translations( $source ) as $code => $translation ) {
			$copy = (int) $translation === $source ? $target : (int) $map( (int) $translation );
			if ( $copy > 0 ) {
				$group[ (string) $code ] = $copy;
			}
		}
		if ( count( $group ) > 1 ) {
			pll_save_term_translations( $group );
		}
	}
}
