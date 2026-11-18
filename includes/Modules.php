<?php
/**
 * Decides which parts of the plugin exist on this site. Capabilities control
 * who may use a feature; modules control whether the feature is there at all.
 *
 * Order of precedence: default, saved setting, the RMD_EO_MODULES constant
 * (an array like [ 'markets' => true ] in wp-config.php, which also locks the
 * switch on the settings page), and finally the rmd_eo_modules filter.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

use RMD\EventsOrganizer\Domain\Settings as SettingsData;

defined( 'ABSPATH' ) || exit;

final class Modules {

	public const LOCATIONS = 'locations';
	public const MARKETS   = 'markets';
	public const CALENDAR  = 'calendar';

	/**
	 * @return array<string, bool>
	 */
	public static function states(): array {
		$states = array_replace( (array) Settings::get( 'modules', [] ), self::forced() );

		/**
		 * Filters which modules are active.
		 *
		 * @param array<string, bool> $states Module name => active.
		 */
		$filtered = apply_filters( 'rmd_eo_modules', $states );

		$result = [];
		foreach ( SettingsData::MODULES as $name ) {
			$result[ $name ] = is_array( $filtered ) && array_key_exists( $name, $filtered ) ? (bool) $filtered[ $name ] : (bool) ( $states[ $name ] ?? false );
		}
		return $result;
	}

	public static function is_on( string $name ): bool {
		return ! empty( self::states()[ $name ] );
	}

	/**
	 * True when wp-config.php decides the state, so the settings page shows
	 * the switch as fixed.
	 */
	public static function is_locked( string $name ): bool {
		return array_key_exists( $name, self::forced() );
	}

	/**
	 * @return array<string, bool>
	 */
	private static function forced(): array {
		if ( ! defined( 'RMD_EO_MODULES' ) || ! is_array( RMD_EO_MODULES ) ) {
			return [];
		}
		$forced = [];
		foreach ( SettingsData::MODULES as $name ) {
			if ( array_key_exists( $name, RMD_EO_MODULES ) ) {
				$forced[ $name ] = (bool) RMD_EO_MODULES[ $name ];
			}
		}
		return $forced;
	}
}
