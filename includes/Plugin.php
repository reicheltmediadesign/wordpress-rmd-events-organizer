<?php
/**
 * Plugin bootstrap: wires all services to WordPress hooks.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', [ self::class, 'load_textdomain' ], 1 );
		add_action( 'init', [ Upgrade::class, 'run' ], 5 );

		Capabilities::init();
		Settings::init();
		PostTypes::init();
		Taxonomies::init();
		Meta::init();
		Sync\OccurrenceSync::init();
		Assets::init();
		Blocks::init();
		Shortcodes::init();
		SingleView::init();
		StructuredData::init();
		Ics::init();
		Rest\CalendarController::init();
		Rest\PreviewController::init();
		Cache::init();
		Updater::init();

		if ( is_admin() ) {
			Admin\EventMetaBox::init();
			Admin\LocationMetaBox::init();
			Admin\SaveHandler::init();
			Admin\ListTable::init();
			Admin\TermFields::init();
			Admin\SettingsPage::init();
			Admin\HelpPage::init();
			Admin\Notices::init();
		}
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'rmd-events-organizer', false, dirname( RMD_EO_BASENAME ) . '/languages' );
	}

	public static function activate(): void {
		Schema::ensure();
		Capabilities::grant();
		Cache::schedule_daily();
		PostTypes::request_flush();
	}

	public static function deactivate(): void {
		Cache::unschedule_daily();
		flush_rewrite_rules();
	}
}
