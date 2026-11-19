<?php
/**
 * Plugin Name:       RMD Events Organizer
 * Plugin URI:        https://github.com/reicheltmediadesign/wordpress-rmd-events-organizer
 * Description:       Events and market dates with repeating schedules, shown as lists and calendars via blocks and shortcodes.
 * Version:           0.3.2
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Philipp Reichelt, reichelt media.design
 * Author URI:        https://reicheltmedia.design
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rmd-events-organizer
 * Domain Path:       /languages
 * Update URI:        https://github.com/reicheltmediadesign/wordpress-rmd-events-organizer
 *
 * @package RMD\EventsOrganizer
 */

// This file must stay parseable by PHP 7.x so the version notice below can be shown instead of a fatal error.

defined( 'ABSPATH' ) || exit;

define( 'RMD_EO_VERSION', '0.3.2' );
define( 'RMD_EO_FILE', __FILE__ );
define( 'RMD_EO_DIR', plugin_dir_path( __FILE__ ) );
define( 'RMD_EO_URL', plugin_dir_url( __FILE__ ) );
define( 'RMD_EO_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'RMD Events Organizer requires PHP %1$s or newer. This site runs PHP %2$s, so the plugin stays inactive.', 'rmd-events-organizer' ),
					'8.1',
					PHP_VERSION
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'RMD\\EventsOrganizer\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = RMD_EO_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

if ( is_readable( RMD_EO_DIR . 'vendor/autoload.php' ) ) {
	require RMD_EO_DIR . 'vendor/autoload.php';
}

register_activation_hook( __FILE__, [ 'RMD\\EventsOrganizer\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'RMD\\EventsOrganizer\\Plugin', 'deactivate' ] );

RMD\EventsOrganizer\Plugin::boot();
