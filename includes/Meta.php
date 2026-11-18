<?php
/**
 * Registers the post meta that stores an event and a location. The keys are
 * private (leading underscore), never exposed via REST, and every write runs
 * through the Validator.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Domain\Validator;

defined( 'ABSPATH' ) || exit;

final class Meta {

	public const KIND     = '_rmd_eo_kind';
	public const SCHEDULE = '_rmd_eo_schedule';
	public const LOCATION = '_rmd_eo_location_id';
	public const DETAILS  = '_rmd_eo_details';
	public const ADDRESS  = '_rmd_eo_address';

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ] );
	}

	/**
	 * Validator wired to WordPress sanitizers.
	 */
	public static function validator(): Validator {
		return new Validator(
			'sanitize_text_field',
			static function ( string $url ): string {
				// esc_url_raw() would turn "example" into "http://example"; ask for a complete address instead.
				if ( ! preg_match( '#^https?://[^\s/]+\.[^\s/]+#i', $url ) ) {
					return '';
				}
				return (string) esc_url_raw( $url, [ 'http', 'https' ] );
			}
		);
	}

	public static function register(): void {
		$auth = static fn(): bool => Capabilities::current_user_can();

		register_post_meta(
			PostTypes::EVENT,
			self::KIND,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => $auth,
				'sanitize_callback' => static fn( $value ): string => Kinds::normalize( is_string( $value ) ? $value : '' ),
			]
		);
		register_post_meta(
			PostTypes::EVENT,
			self::LOCATION,
			[
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => $auth,
				'sanitize_callback' => 'absint',
			]
		);
		register_post_meta(
			PostTypes::EVENT,
			self::SCHEDULE,
			[
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => $auth,
				'sanitize_callback' => static fn( $value ): array => self::validator()->normalize_schedule( $value )['data'],
			]
		);
		register_post_meta(
			PostTypes::EVENT,
			self::DETAILS,
			[
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => $auth,
				'sanitize_callback' => static function ( $value ): array {
					// The kind is stored separately; market details are recognised by their own field.
					$kind = is_array( $value ) && array_key_exists( 'stall', $value ) ? Kinds::MARKET : Kinds::EVENT;
					return self::validator()->normalize_details( $value, $kind )['data'];
				},
			]
		);
		register_post_meta(
			PostTypes::LOCATION,
			self::ADDRESS,
			[
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => $auth,
				'sanitize_callback' => static fn( $value ): array => self::validator()->normalize_address( $value )['data'],
			]
		);
	}
}
