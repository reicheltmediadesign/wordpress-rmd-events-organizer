<?php
/**
 * Reads and writes locations.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Repository;

use RMD\EventsOrganizer\Domain\Validator;
use RMD\EventsOrganizer\Meta;
use RMD\EventsOrganizer\PostTypes;

defined( 'ABSPATH' ) || exit;

final class LocationRepository {

	public static function address( int $post_id ): array {
		$stored = get_post_meta( $post_id, Meta::ADDRESS, true );
		return ( is_array( $stored ) ? $stored : [] ) + Validator::empty_address();
	}

	/**
	 * Name and address of a published location, or null.
	 *
	 * @return array{id: int, name: string, address: array, url: string}|null
	 */
	public static function find( int $post_id ): ?array {
		if ( $post_id <= 0 ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( ! $post || PostTypes::LOCATION !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		return [
			'id'      => (int) $post->ID,
			'name'    => (string) get_the_title( $post ),
			'address' => self::address( (int) $post->ID ),
			'url'     => is_post_type_viewable( PostTypes::LOCATION ) ? (string) get_permalink( $post ) : '',
		];
	}

	/**
	 * @return array<int, string> id => name, ordered by name.
	 */
	public static function choices(): array {
		$posts   = get_posts(
			[
				'post_type'      => PostTypes::LOCATION,
				'post_status'    => 'publish',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A select needs every location.
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);
		$choices = [];
		foreach ( $posts as $post ) {
			$choices[ (int) $post->ID ] = (string) get_the_title( $post );
		}
		return $choices;
	}

	/**
	 * Creates a published location and returns its id (0 on failure).
	 *
	 * @param array $address Canonical address.
	 */
	public static function create( string $name, array $address ): int {
		$post_id = wp_insert_post(
			[
				'post_type'   => PostTypes::LOCATION,
				'post_status' => 'publish',
				'post_title'  => $name,
			],
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}
		update_post_meta( (int) $post_id, Meta::ADDRESS, wp_slash( $address ) );
		return (int) $post_id;
	}

	/**
	 * One-line postal address ("Street, 12345 City").
	 */
	public static function one_line( array $address ): string {
		$city = trim( ( $address['postcode'] ?? '' ) . ' ' . ( $address['city'] ?? '' ) );
		return implode( ', ', array_filter( [ (string) ( $address['street'] ?? '' ), $city ], 'strlen' ) );
	}
}
