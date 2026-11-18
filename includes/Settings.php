<?php
/**
 * Global plugin settings stored in one option.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

use RMD\EventsOrganizer\Domain\Settings as SettingsData;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'rmd_eo_settings';
	public const GROUP  = 'rmd_eo_settings_group';

	private static ?array $cache = null;

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ] );
		add_action( 'update_option_' . self::OPTION, [ self::class, 'on_change' ], 5, 2 );
		add_action( 'add_option_' . self::OPTION, [ self::class, 'flush' ], 5 );
	}

	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			[
				'type'              => 'array',
				'default'           => SettingsData::defaults(),
				'show_in_rest'      => false,
				'sanitize_callback' => [ self::class, 'sanitize' ],
			]
		);
	}

	/**
	 * @param mixed $value Raw option value.
	 */
	public static function sanitize( $value ): array {
		$value  = is_array( $value ) ? $value : [];
		$stored = self::get();

		// The roles checklist posts nothing when every box is unchecked.
		$roles_posted = isset( $value['roles'] ) || ! empty( $value['roles_submitted'] );
		$roles        = is_array( $value['roles'] ?? null ) ? $value['roles'] : [];
		unset( $value['roles'], $value['roles_submitted'] );

		$merged = $stored;
		foreach ( array_intersect_key( $value, $stored ) as $key => $section ) {
			$merged[ $key ] = is_array( $section ) && is_array( $stored[ $key ] ) ? array_replace( $stored[ $key ], $section ) : $section;
		}

		// Only users who may manage roles can change who has access.
		if ( $roles_posted && current_user_can( 'promote_users' ) ) {
			$merged['roles'] = array_values( array_intersect( array_map( 'strval', $roles ), array_keys( wp_roles()->roles ) ) );
		}

		return SettingsData::parse( $merged );
	}

	/**
	 * @param string|null $key      Top-level key; null returns the whole array.
	 * @param mixed       $fallback Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( ?string $key = null, $fallback = null ) {
		if ( null === self::$cache ) {
			self::$cache = SettingsData::parse( get_option( self::OPTION, [] ) );
		}

		if ( null === $key ) {
			return self::$cache;
		}

		return array_key_exists( $key, self::$cache ) ? self::$cache[ $key ] : $fallback;
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $new_value
	 */
	public static function on_change( $old_value, $new_value ): void {
		self::flush();

		$old = SettingsData::parse( $old_value );
		$new = SettingsData::parse( $new_value );
		if ( $old['permalinks'] !== $new['permalinks'] || $old['modules'] !== $new['modules'] ) {
			PostTypes::request_flush();
		}
	}

	public static function flush(): void {
		self::$cache = null;
	}
}
