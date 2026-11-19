<?php
/**
 * Colour field for event categories. The colour marks events in lists and
 * calendars.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Admin;

use RMD\EventsOrganizer\Capabilities;
use RMD\EventsOrganizer\Taxonomies;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class TermFields {

	public const FIELD        = 'rmd_eo_color';
	public const TOGGLE       = 'rmd_eo_color_on';
	public const NONCE_FIELD  = 'rmd_eo_term_nonce';
	public const NONCE_ACTION = 'rmd_eo_save_term';

	public static function init(): void {
		add_action( Taxonomies::CATEGORY . '_add_form_fields', [ self::class, 'add_field' ] );
		add_action( Taxonomies::CATEGORY . '_edit_form_fields', [ self::class, 'edit_field' ] );
		add_action( 'created_' . Taxonomies::CATEGORY, [ self::class, 'save' ] );
		add_action( 'edited_' . Taxonomies::CATEGORY, [ self::class, 'save' ] );
	}

	public static function add_field(): void {
		echo '<div class="form-field">';
		echo '<label for="rmd-eo-color">' . esc_html__( 'Colour', 'rmd-events-organizer' ) . '</label>';
		self::inputs( '' );
		echo '</div>';
	}

	public static function edit_field( WP_Term $term ): void {
		echo '<tr class="form-field"><th scope="row"><label for="rmd-eo-color">' . esc_html__( 'Colour', 'rmd-events-organizer' ) . '</label></th><td>';
		self::inputs( Taxonomies::color( (int) $term->term_id ) );
		echo '</td></tr>';
	}

	public static function save( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::CAP ) ) {
			return;
		}
		$color = empty( $_POST[ self::TOGGLE ] ) ? '' : (string) sanitize_hex_color( sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ?? '' ) ) );
		if ( '' === $color ) {
			delete_term_meta( $term_id, Taxonomies::COLOR );
		} else {
			update_term_meta( $term_id, Taxonomies::COLOR, $color );
		}
	}

	private static function inputs( string $color ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s> %s</label> ',
			esc_attr( self::TOGGLE ),
			checked( '' !== $color, true, false ),
			esc_html__( 'Use a colour', 'rmd-events-organizer' )
		);
		printf(
			'<input type="color" id="rmd-eo-color" name="%s" value="%s">',
			esc_attr( self::FIELD ),
			esc_attr( '' !== $color ? $color : '#3a7bd5' )
		);
		echo '<p class="description">' . esc_html__( 'Events of this category are marked with this colour in lists and calendars.', 'rmd-events-organizer' ) . '</p>';
	}
}
