<?php
/**
 * Events → Import: brings events, locations, categories and tags over from
 * Events Manager. Shows a preview first; the import itself only reads the
 * old data, can be repeated and can be undone.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Admin;

use RMD\EventsOrganizer\Migration\EmReader;
use RMD\EventsOrganizer\Migration\Importer;
use RMD\EventsOrganizer\Migration\Planner;
use RMD\EventsOrganizer\Modules;
use RMD\EventsOrganizer\PostTypes;
use RMD\EventsOrganizer\Settings;

defined( 'ABSPATH' ) || exit;

final class MigrationPage {

	public const SLUG = 'rmd-eo-import';
	public const CAP  = 'manage_options';

	public const ACTION_IMPORT = 'rmd_eo_em_import';
	public const ACTION_UNDO   = 'rmd_eo_em_undo';
	public const ACTION_SLUG   = 'rmd_eo_em_adopt_slug';

	public const TAB_DATA    = 'events';
	public const TAB_CONTENT = 'content';
	public const TAB_CLEANUP = 'cleanup';

	/** Events handled per request, so large sites do not run into time limits. */
	private const BATCH = 25;

	private const PROGRESS = 'rmd_eo_em_progress_';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 15 );
		add_action( 'admin_post_' . self::ACTION_IMPORT, [ self::class, 'handle_import' ] );
		add_action( 'admin_post_' . self::ACTION_UNDO, [ self::class, 'handle_undo' ] );
		add_action( 'admin_post_' . self::ACTION_SLUG, [ self::class, 'handle_slug' ] );
		MigrationContentTab::init();
		MigrationCleanupTab::init();
	}

	public static function menu(): void {
		if ( ! Modules::is_on( Modules::MIGRATION ) ) {
			return;
		}
		add_submenu_page(
			'edit.php?post_type=' . PostTypes::EVENT,
			__( 'Import from Events Manager', 'rmd-events-organizer' ),
			__( 'Import', 'rmd-events-organizer' ),
			self::CAP,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . PostTypes::EVENT . '&page=' . self::SLUG ) );
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to import events.', 'rmd-events-organizer' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses what is shown.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$tabs = [
			self::TAB_DATA    => __( '1. Events', 'rmd-events-organizer' ),
			self::TAB_CONTENT => __( '2. Pages, widgets and menus', 'rmd-events-organizer' ),
			self::TAB_CLEANUP => __( '3. Clean up', 'rmd-events-organizer' ),
		];
		$tab  = isset( $tabs[ $tab ] ) ? $tab : self::TAB_DATA;

		echo '<div class="wrap rmd-eo-import"><h1>' . esc_html__( 'Import from Events Manager', 'rmd-events-organizer' ) . '</h1>';
		echo '<style>.rmd-eo-import__inline{display:inline-block;margin:0 6px 6px 0}.rmd-eo-import__actions{margin:0 0 8px}.rmd-eo-import__changes code{white-space:normal;word-break:break-word}.rmd-eo-import .nav-tab-wrapper{margin-bottom:12px}</style>';
		self::result_notice();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( [ 'tab' => $key ] ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		if ( self::TAB_CONTENT === $tab ) {
			MigrationContentTab::render();
		} elseif ( self::TAB_CLEANUP === $tab ) {
			MigrationCleanupTab::render();
		} else {
			self::render_data();
		}
		echo '</div>';
	}

	private static function render_data(): void {
		if ( null === EmReader::tables() ) {
			echo '<p>' . esc_html__( 'No data of Events Manager was found in the database of this website.', 'rmd-events-organizer' ) . '</p>';
			return;
		}

		$plan     = Planner::plan();
		$imported = Importer::imported();
		$active   = EmReader::is_plugin_active();

		echo '<p>' . esc_html__( 'The import copies events, locations, categories and tags. The data of Events Manager stays as it is, so you can compare, repeat the import or undo it.', 'rmd-events-organizer' ) . '</p>';

		echo '<h2>' . esc_html__( 'Found', 'rmd-events-organizer' ) . '</h2><table class="widefat striped rmd-eo-import__table"><tbody>';
		self::row( __( 'Events Manager', 'rmd-events-organizer' ), $active ? __( 'installed and active', 'rmd-events-organizer' ) : __( 'not active (its data is still in the database)', 'rmd-events-organizer' ) );
		self::row( __( 'Version of the data', 'rmd-events-organizer' ), '' !== EmReader::version() ? EmReader::version() : '–' );
		self::row( __( 'Single events', 'rmd-events-organizer' ), (string) ( $plan['counts']['single'] + $plan['counts']['standalone'] ) );
		/* translators: 1: number of series, 2: number of dates in them */
		self::row( __( 'Series', 'rmd-events-organizer' ), sprintf( __( '%1$d with %2$d dates, each becomes one event with all its dates', 'rmd-events-organizer' ), $plan['counts']['series'], $plan['counts']['dates'] ) );
		self::row( __( 'Locations', 'rmd-events-organizer' ), (string) count( EmReader::locations() ) );
		self::row( __( 'Categories and tags', 'rmd-events-organizer' ), (string) ( count( EmReader::terms( EmReader::TAXONOMY_CATEGORY ) ) + count( EmReader::terms( EmReader::TAXONOMY_TAG ) ) ) );
		echo '</tbody></table>';

		self::not_imported( $plan );
		self::series_table( $plan );
		self::warnings_table( $plan );

		echo '<h2>' . esc_html__( 'Import', 'rmd-events-organizer' ) . '</h2>';
		if ( $imported['events'] > 0 ) {
			/* translators: 1: number of events, 2: number of locations */
			echo '<p>' . esc_html( sprintf( __( 'Already imported: %1$d events and %2$d locations. Importing again updates them from Events Manager; dates, texts and locations changed here in the meantime are overwritten.', 'rmd-events-organizer' ), $imported['events'], $imported['locations'] ) ) . '</p>';
		}
		if ( $plan['counts']['mismatches'] > 0 ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Some series could not be reproduced exactly (see “Series” above). You can import anyway and correct those events afterwards.', 'rmd-events-organizer' ) . '</p></div>';
		}
		self::button( self::ACTION_IMPORT, $imported['events'] > 0 ? __( 'Import again', 'rmd-events-organizer' ) : __( 'Import now', 'rmd-events-organizer' ), 'primary' );

		if ( $imported['events'] + $imported['locations'] + $imported['terms'] > 0 ) {
			echo '<h2>' . esc_html__( 'After the import', 'rmd-events-organizer' ) . '</h2>';
			self::slug_section( $active );

			echo '<h3>' . esc_html__( 'Undo the import', 'rmd-events-organizer' ) . '</h3>';
			echo '<p>' . esc_html__( 'Deletes every imported event, location, category and tag, including changes made to them here, and puts back the pages, widgets and menus changed in step 2. Events entered by hand and the data of Events Manager are kept.', 'rmd-events-organizer' ) . '</p>';
			self::button( self::ACTION_UNDO, __( 'Undo the import', 'rmd-events-organizer' ), 'delete', __( 'Delete everything that was imported?', 'rmd-events-organizer' ) );
		}
	}

	private static function result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only chooses a message.
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		// phpcs:enable
		if ( '' === $done ) {
			return;
		}
		$progress = get_transient( self::PROGRESS . get_current_user_id() );
		$progress = is_array( $progress ) ? $progress : [];
		$message  = '';

		if ( 'import' === $done ) {
			$message = sprintf(
				/* translators: 1: events created, 2: events updated, 3: locations, 4: categories and tags */
				__( 'Import finished: %1$d events created, %2$d updated, %3$d locations, %4$d categories and tags.', 'rmd-events-organizer' ),
				(int) ( $progress['created'] ?? 0 ),
				(int) ( $progress['updated'] ?? 0 ),
				(int) ( $progress['locations'] ?? 0 ),
				(int) ( $progress['terms'] ?? 0 )
			);
		} elseif ( 'undo' === $done ) {
			$message = sprintf(
				/* translators: 1: events, 2: locations, 3: categories and tags */
				__( 'Import undone: %1$d events, %2$d locations and %3$d categories and tags deleted.', 'rmd-events-organizer' ),
				(int) ( $progress['events'] ?? 0 ),
				(int) ( $progress['locations'] ?? 0 ),
				(int) ( $progress['terms'] ?? 0 )
			);
		} elseif ( 'slug' === $done ) {
			$message = __( 'The events now use the address Events Manager used.', 'rmd-events-organizer' );
		} elseif ( '' !== MigrationContentTab::result_message( $done, $progress ) ) {
			$message = MigrationContentTab::result_message( $done, $progress );
		} elseif ( 'cleanup' === $done ) {
			$message = __( 'The data of Events Manager was removed.', 'rmd-events-organizer' );
		} elseif ( 'cleanup_confirm' === $done ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nothing was removed: the confirmation was not typed correctly.', 'rmd-events-organizer' ) . '</p></div>';
			return;
		} elseif ( 'slug_blocked' === $done ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Deactivate Events Manager first; two plugins cannot answer under the same address.', 'rmd-events-organizer' ) . '</p></div>';
			return;
		}
		if ( '' !== $message ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private static function not_imported( array $plan ): void {
		$lines = [];
		if ( $plan['counts']['trash'] > 0 ) {
			/* translators: %d: number of events */
			$lines[] = sprintf( _n( '%d event in the trash', '%d events in the trash', $plan['counts']['trash'], 'rmd-events-organizer' ), $plan['counts']['trash'] );
		}
		if ( $plan['counts']['timeslots'] > 0 ) {
			/* translators: %d: number of time slots */
			$lines[] = sprintf( _n( '%d time slot (events keep their overall start and end)', '%d time slots (events keep their overall start and end)', $plan['counts']['timeslots'], 'rmd-events-organizer' ), $plan['counts']['timeslots'] );
		}
		foreach ( $plan['notes'] as $note ) {
			/* translators: %s: name of an event */
			$lines[] = sprintf( __( '“%s” has no usable date', 'rmd-events-organizer' ), $note['label'] );
		}
		$lines[] = __( 'bookings, tickets and booking forms', 'rmd-events-organizer' );
		$lines[] = __( 'the list and page formats of Events Manager (the display follows this plugin)', 'rmd-events-organizer' );

		echo '<h2>' . esc_html__( 'Not imported', 'rmd-events-organizer' ) . '</h2><ul class="ul-disc">';
		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private static function series_table( array $plan ): void {
		$series = array_filter( $plan['units'], static fn( array $unit ): bool => ! empty( $unit['series'] ) );
		if ( [] === $series ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Series', 'rmd-events-organizer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Every series is checked: the dates it will have here are compared with the dates Events Manager created.', 'rmd-events-organizer' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Series', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Dates', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Becomes', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Check', 'rmd-events-organizer' ) . '</th></tr></thead><tbody>';
		foreach ( $series as $unit ) {
			$rules   = count( array_filter( $unit['schedule']['entries'], static fn( array $entry ): bool => 'rule' === $entry['type'] ) );
			$singles = count( $unit['schedule']['entries'] ) - $rules;
			$parts   = [];
			if ( $rules > 0 ) {
				/* translators: %d: number of rules */
				$parts[] = sprintf( _n( '%d rule', '%d rules', $rules, 'rmd-events-organizer' ), $rules );
			}
			if ( $singles > 0 ) {
				/* translators: %d: number of dates */
				$parts[] = sprintf( _n( '%d single date', '%d single dates', $singles, 'rmd-events-organizer' ), $singles );
			}
			if ( [] !== $unit['schedule']['exclusions'] ) {
				/* translators: %d: number of days */
				$parts[] = sprintf( _n( '%d day left out', '%d days left out', count( $unit['schedule']['exclusions'] ), 'rmd-events-organizer' ), count( $unit['schedule']['exclusions'] ) );
			}
			if ( [] !== $unit['schedule']['overrides'] ) {
				/* translators: %d: number of dates */
				$parts[] = sprintf( _n( '%d changed or cancelled date', '%d changed or cancelled dates', count( $unit['schedule']['overrides'] ), 'rmd-events-organizer' ), count( $unit['schedule']['overrides'] ) );
			}

			echo '<tr><td><strong>' . esc_html( $unit['title'] ) . '</strong></td><td>' . (int) $unit['dates'] . '</td><td>' . esc_html( implode( ', ', $parts ) ) . '</td><td>';
			if ( [] === $unit['mismatches'] ) {
				echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'all dates match', 'rmd-events-organizer' );
			} else {
				/* translators: %d: number of differences */
				echo '<strong>' . esc_html( sprintf( _n( '%d difference', '%d differences', count( $unit['mismatches'] ), 'rmd-events-organizer' ), count( $unit['mismatches'] ) ) ) . '</strong><br><code>' . esc_html( implode( ' · ', array_slice( $unit['mismatches'], 0, 5 ) ) ) . '</code>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private static function warnings_table( array $plan ): void {
		$labels = [
			'post_missing'        => __( 'The post of this event no longer exists; it is imported as a draft.', 'rmd-events-organizer' ),
			'row_missing'         => __( 'Restored from the post; the entry in the events table was missing.', 'rmd-events-organizer' ),
			'edited_date'         => __( 'This date of a series has its own title or text and becomes an event of its own.', 'rmd-events-organizer' ),
			'other_timezone'      => __( 'Was entered in another time zone; the times are taken as they are.', 'rmd-events-organizer' ),
			'unsupported_rule'    => __( 'The rule of this series is not supported; its dates are imported one by one.', 'rmd-events-organizer' ),
			'weekly_without_days' => __( 'The rule of this series has no weekdays; its dates are imported one by one.', 'rmd-events-organizer' ),
			'monthly_without_day' => __( 'The rule of this series has no weekday; its dates are imported one by one.', 'rmd-events-organizer' ),
			'count_with_interval' => __( 'The series ends after a number of dates; please check its last dates.', 'rmd-events-organizer' ),
			'too_many'            => __( 'The series has more entries than an event can hold; the rest was left out.', 'rmd-events-organizer' ),
		];
		$rows   = [];
		foreach ( $plan['units'] as $unit ) {
			foreach ( array_unique( $unit['warnings'] ) as $code ) {
				if ( isset( $labels[ $code ] ) ) {
					$rows[] = [ $unit['title'], $labels[ $code ] ];
				}
			}
		}
		if ( [] === $rows ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Please note', 'rmd-events-organizer' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( $rows as [ $title, $text ] ) {
			echo '<tr><td><strong>' . esc_html( $title ) . '</strong></td><td>' . esc_html( $text ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function slug_section( bool $active ): void {
		$old     = (string) get_option( 'dbem_cp_events_slug', 'events' );
		$current = (string) ( ( (array) Settings::get( 'permalinks', [] ) )['event_slug'] ?? '' );

		echo '<h3>' . esc_html__( 'Keep the old addresses', 'rmd-events-organizer' ) . '</h3>';
		if ( $old === $current ) {
			echo '<p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html( sprintf( /* translators: %s: URL path */ __( 'Events already use the address /%s/ as before.', 'rmd-events-organizer' ), $old ) ) . '</p>';
			return;
		}
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: old URL path, 2: current URL path */
				__( 'Events Manager showed events under /%1$s/; this plugin uses /%2$s/. Take over the old address so that existing links and search results keep working. The single dates of a series then lead to the series.', 'rmd-events-organizer' ),
				$old,
				$current
			)
		) . '</p>';
		if ( $active ) {
			echo '<p class="description">' . esc_html__( 'Possible once Events Manager is deactivated.', 'rmd-events-organizer' ) . '</p>';
			return;
		}
		self::button( self::ACTION_SLUG, __( 'Take over the old address', 'rmd-events-organizer' ), 'secondary' );
	}

	private static function row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * A form with one button that posts to an action of this page.
	 *
	 * @param array<string, string> $fields Hidden fields sent along.
	 * @param bool                  $inline True to place several buttons side by side.
	 */
	public static function button( string $action, string $label, string $type, string $confirm = '', array $fields = [], bool $inline = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $inline ? ' class="rmd-eo-import__inline"' : '' ) . ( '' !== $confirm ? ' onsubmit="return window.confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		wp_nonce_field( $action );
		submit_button( $label, $type, 'submit', ! $inline );
		echo '</form>';
	}

	/**
	 * Remembers numbers for the message shown after the redirect.
	 *
	 * @param array<string, int> $numbers
	 */
	public static function remember( array $numbers ): void {
		set_transient( self::PROGRESS . get_current_user_id(), $numbers, HOUR_IN_SECONDS );
	}

	/**
	 * Continues a long task in the next request. A page that forwards itself
	 * instead of a redirect: browsers give up after about twenty redirects in a row.
	 */
	public static function forward( string $url, string $message ): void {
		nocache_headers();
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . esc_url( $url ) . '"><title>' . esc_html( $message ) . '</title></head><body><p>' . esc_html( $message ) . '</p></body></html>';
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	public static function handle_import(): void {
		self::guard( self::ACTION_IMPORT );

		$offset   = isset( $_REQUEST['offset'] ) ? absint( $_REQUEST['offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in guard().
		$key      = self::PROGRESS . get_current_user_id();
		$progress = $offset > 0 ? get_transient( $key ) : [];
		$progress = is_array( $progress ) ? $progress : [];
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A batch of an import may take longer than a page view.
		}

		if ( 0 === $offset ) {
			$progress = Importer::import_basics() + [
				'created' => 0,
				'updated' => 0,
			];
		}

		$units  = Planner::plan()['units'];
		$result = Importer::import_units( array_slice( $units, $offset, self::BATCH ) );

		$progress['created'] = (int) ( $progress['created'] ?? 0 ) + $result['created'];
		$progress['updated'] = (int) ( $progress['updated'] ?? 0 ) + $result['updated'];
		set_transient( $key, $progress, HOUR_IN_SECONDS );

		$next = $offset + self::BATCH;
		if ( $next >= count( $units ) ) {
			wp_safe_redirect( self::url( [ 'done' => 'import' ] ) );
			exit;
		}

		self::forward(
			wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_IMPORT . '&offset=' . $next ), self::ACTION_IMPORT ),
			/* translators: 1: number of events done, 2: total number of events */
			sprintf( __( 'Importing … %1$d of %2$d events.', 'rmd-events-organizer' ), $next, count( $units ) )
		);
	}

	public static function handle_undo(): void {
		self::guard( self::ACTION_UNDO );
		set_transient( self::PROGRESS . get_current_user_id(), Importer::undo(), HOUR_IN_SECONDS );
		wp_safe_redirect( self::url( [ 'done' => 'undo' ] ) );
		exit;
	}

	public static function handle_slug(): void {
		self::guard( self::ACTION_SLUG );
		if ( EmReader::is_plugin_active() ) {
			wp_safe_redirect( self::url( [ 'done' => 'slug_blocked' ] ) );
			exit;
		}

		$settings                             = Settings::get();
		$settings['permalinks']['event_slug'] = (string) get_option( 'dbem_cp_events_slug', 'events' );
		update_option( Settings::OPTION, $settings );

		wp_safe_redirect( self::url( [ 'done' => 'slug' ] ) );
		exit;
	}

	public static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) || ! Modules::is_on( Modules::MIGRATION ) ) {
			wp_die( esc_html__( 'You are not allowed to import events.', 'rmd-events-organizer' ) );
		}
		check_admin_referer( $action );
	}
}
