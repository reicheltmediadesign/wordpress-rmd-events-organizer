<?php
/**
 * Address fields of a location.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Admin;

use RMD\EventsOrganizer\Capabilities;
use RMD\EventsOrganizer\Meta;
use RMD\EventsOrganizer\PostTypes;
use RMD\EventsOrganizer\Repository\LocationRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class LocationMetaBox {

	public const FIELD        = 'rmd_eo_address';
	public const NONCE_FIELD  = 'rmd_eo_location_nonce';
	public const NONCE_ACTION = 'rmd_eo_save_location_';

	public static function init(): void {
		add_action( 'add_meta_boxes_' . PostTypes::LOCATION, [ self::class, 'add' ] );
		add_action( 'save_post_' . PostTypes::LOCATION, [ self::class, 'save' ] );
	}

	public static function add(): void {
		add_meta_box( 'rmd-eo-address', __( 'Address', 'rmd-events-organizer' ), [ self::class, 'render' ], PostTypes::LOCATION, 'normal', 'high' );
	}

	public static function render( WP_Post $post ): void {
		$address = LocationRepository::address( (int) $post->ID );
		$fields  = [
			'street'   => [ __( 'Street and number', 'rmd-events-organizer' ), 'regular-text' ],
			'postcode' => [ __( 'Postcode', 'rmd-events-organizer' ), 'small-text' ],
			'city'     => [ __( 'Town', 'rmd-events-organizer' ), 'regular-text' ],
			'region'   => [ __( 'Region or state', 'rmd-events-organizer' ), 'regular-text' ],
			'country'  => [ __( 'Country code (e.g. DE)', 'rmd-events-organizer' ), 'small-text' ],
			'url'      => [ __( 'Website', 'rmd-events-organizer' ), 'regular-text' ],
			'lat'      => [ __( 'Latitude', 'rmd-events-organizer' ), 'regular-text' ],
			'lng'      => [ __( 'Longitude', 'rmd-events-organizer' ), 'regular-text' ],
		];

		wp_nonce_field( self::NONCE_ACTION . $post->ID, self::NONCE_FIELD );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $key => [ $label, $class ] ) {
			printf(
				'<tr><th scope="row"><label for="rmd-eo-address-%1$s">%2$s</label></th><td><input type="%5$s" id="rmd-eo-address-%1$s" name="%3$s[%1$s]" value="%4$s" class="%6$s"></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( self::FIELD ),
				esc_attr( (string) $address[ $key ] ),
				'url' === $key ? 'url' : 'text',
				esc_attr( $class )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Latitude and longitude are optional; search engines use them to place the location on a map.', 'rmd-events-organizer' ) . '</p>';
	}

	public static function save( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! isset( $_POST[ self::NONCE_FIELD ], $_POST[ self::FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::CAP ) ) {
			return;
		}

		$raw    = wp_unslash( $_POST[ self::FIELD ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by the Validator.
		$result = Meta::validator()->normalize_address( is_array( $raw ) ? $raw : [] );

		update_post_meta( $post_id, Meta::ADDRESS, wp_slash( $result['data'] ) );
		if ( [] !== $result['errors'] ) {
			Notices::remember( $post_id, $result['errors'] );
		}
	}
}
