<?php
/**
 * Keeps the database table in place and runs one-time data changes after a
 * plugin update. The installed version is kept in an option; add steps here
 * guarded by a version comparison.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

defined( 'ABSPATH' ) || exit;

final class Upgrade {

	public const OPTION = 'rmd_eo_version';

	public static function run(): void {
		// Also covers sites added to a network after activation.
		Schema::ensure();

		$installed = (string) get_option( self::OPTION, '' );
		if ( '' !== $installed && version_compare( $installed, RMD_EO_VERSION, '>=' ) ) {
			return;
		}

		update_option( self::OPTION, RMD_EO_VERSION, true );
	}
}
