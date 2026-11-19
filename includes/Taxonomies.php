<?php
/**
 * Event categories and tags. They filter lists and calendars; they have no
 * public archive pages of their own.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

defined( 'ABSPATH' ) || exit;

final class Taxonomies {

	public const CATEGORY = 'rmd_eo_category';
	public const TAG      = 'rmd_eo_tag';

	/** Term meta: colour of a category as "#rrggbb". */
	public const COLOR = 'rmd_eo_color';

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ], 9 );
	}

	public static function register(): void {
		$cap  = Capabilities::CAP;
		$caps = [
			'manage_terms' => $cap,
			'edit_terms'   => $cap,
			'delete_terms' => $cap,
			'assign_terms' => $cap,
		];
		$base = [
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => false,
			'show_in_rest'      => false,
			'show_tagcloud'     => false,
			'rewrite'           => false,
			'query_var'         => false,
			'capabilities'      => $caps,
		];

		register_taxonomy(
			self::CATEGORY,
			PostTypes::EVENT,
			[
				'labels'       => [
					'name'          => __( 'Event categories', 'rmd-events-organizer' ),
					'singular_name' => __( 'Event category', 'rmd-events-organizer' ),
					'menu_name'     => __( 'Categories', 'rmd-events-organizer' ),
					'all_items'     => __( 'All categories', 'rmd-events-organizer' ),
					'edit_item'     => __( 'Edit category', 'rmd-events-organizer' ),
					'add_new_item'  => __( 'Add new category', 'rmd-events-organizer' ),
					'search_items'  => __( 'Search categories', 'rmd-events-organizer' ),
					'not_found'     => __( 'No categories found.', 'rmd-events-organizer' ),
				],
				'hierarchical' => true,
			] + $base
		);

		register_taxonomy(
			self::TAG,
			PostTypes::EVENT,
			[
				'labels'       => [
					'name'          => __( 'Event tags', 'rmd-events-organizer' ),
					'singular_name' => __( 'Event tag', 'rmd-events-organizer' ),
					'menu_name'     => __( 'Tags', 'rmd-events-organizer' ),
					'all_items'     => __( 'All tags', 'rmd-events-organizer' ),
					'edit_item'     => __( 'Edit tag', 'rmd-events-organizer' ),
					'add_new_item'  => __( 'Add new tag', 'rmd-events-organizer' ),
					'search_items'  => __( 'Search tags', 'rmd-events-organizer' ),
					'not_found'     => __( 'No tags found.', 'rmd-events-organizer' ),
				],
				'hierarchical' => false,
			] + $base
		);

		register_term_meta(
			self::CATEGORY,
			self::COLOR,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn( $value ): string => (string) sanitize_hex_color( (string) $value ),
			]
		);
	}

	public static function color( int $term_id ): string {
		return (string) sanitize_hex_color( (string) get_term_meta( $term_id, self::COLOR, true ) );
	}
}
