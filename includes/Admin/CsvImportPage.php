<?php
/**
 * Events → CSV import: creates events and market dates from a CSV file. The
 * file is read and shown as a preview first; nothing is written before the
 * import is confirmed.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Admin;

use RMD\EventsOrganizer\Capabilities;
use RMD\EventsOrganizer\Domain\Csv\CsvPlanner;
use RMD\EventsOrganizer\Domain\Csv\CsvReader;
use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Domain\Occurrence;
use RMD\EventsOrganizer\Import\CsvImporter;
use RMD\EventsOrganizer\Modules;
use RMD\EventsOrganizer\Polylang;
use RMD\EventsOrganizer\PostTypes;

defined( 'ABSPATH' ) || exit;

final class CsvImportPage {

	public const SLUG = 'rmd-eo-csv';

	public const ACTION_UPLOAD   = 'rmd_eo_csv_upload';
	public const ACTION_IMPORT   = 'rmd_eo_csv_import';
	public const ACTION_DISCARD  = 'rmd_eo_csv_discard';
	public const ACTION_TEMPLATE = 'rmd_eo_csv_template';

	private const FIELD    = 'rmd_eo_csv_file';
	private const MAX_SIZE = 2 * MB_IN_BYTES;

	/** Events written per request. */
	private const BATCH = 25;

	/** Rows shown in the preview. */
	private const PREVIEW = 200;

	/** Transient per user: the file that was read, and afterwards what the import did. */
	private const PLAN   = 'rmd_eo_csv_plan_';
	private const RESULT = 'rmd_eo_csv_result_';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 14 );
		add_action( 'admin_post_' . self::ACTION_UPLOAD, [ self::class, 'handle_upload' ] );
		add_action( 'admin_post_' . self::ACTION_IMPORT, [ self::class, 'handle_import' ] );
		add_action( 'admin_post_' . self::ACTION_DISCARD, [ self::class, 'handle_discard' ] );
		add_action( 'admin_post_' . self::ACTION_TEMPLATE, [ self::class, 'handle_template' ] );
	}

	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostTypes::EVENT,
			__( 'Import events from a CSV file', 'rmd-events-organizer' ),
			__( 'CSV import', 'rmd-events-organizer' ),
			Capabilities::CAP,
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
		if ( ! Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'You are not allowed to import events.', 'rmd-events-organizer' ) );
		}
		echo '<div class="wrap rmd-eo-csv"><h1>' . esc_html__( 'Import events from a CSV file', 'rmd-events-organizer' ) . '</h1>';
		echo '<style>.rmd-eo-import__inline{display:inline-block;margin:0 6px 6px 0}.rmd-eo-csv__actions{margin-top:12px}.rmd-eo-csv table.widefat{max-width:1100px}.rmd-eo-csv__columns code{white-space:nowrap}</style>';

		self::notices();

		$plan = get_transient( self::PLAN . get_current_user_id() );
		if ( is_array( $plan ) ) {
			self::render_preview( $plan );
		} else {
			self::render_upload();
			self::render_columns();
		}
		echo '</div>';
	}

	private static function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses a message.
		$state  = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		$errors = [
			'upload'                => __( 'The file could not be uploaded. Please try again.', 'rmd-events-organizer' ),
			/* translators: %s: file size, e.g. "2 MB" */
			'size'                  => sprintf( __( 'The file is too large (at most %s).', 'rmd-events-organizer' ), size_format( self::MAX_SIZE ) ),
			CsvReader::ERROR_EMPTY  => __( 'The file is empty.', 'rmd-events-organizer' ),
			CsvReader::ERROR_HEADER => __( 'The first line of the file must name the columns, at least the title and the date (see the columns below).', 'rmd-events-organizer' ),
			'expired'               => __( 'The preview has expired. Please choose the file again.', 'rmd-events-organizer' ),
		];
		if ( isset( $errors[ $state ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $state ] ) . '</p></div>';
			return;
		}
		if ( 'done' !== $state ) {
			return;
		}

		$result = get_transient( self::RESULT . get_current_user_id() );
		if ( ! is_array( $result ) ) {
			return;
		}
		$message = sprintf(
			/* translators: 1: events created, 2: events updated, 3: locations created, 4: categories and tags created */
			__( 'Import finished: %1$d events created, %2$d updated, %3$d new locations, %4$d new categories and tags.', 'rmd-events-organizer' ),
			(int) ( $result['created'] ?? 0 ),
			(int) ( $result['updated'] ?? 0 ),
			(int) ( $result['locations'] ?? 0 ),
			(int) ( $result['terms'] ?? 0 )
		);
		echo '<div class="notice notice-success"><p>' . esc_html( $message ) . ' <a href="' . esc_url( admin_url( 'edit.php?post_type=' . PostTypes::EVENT ) ) . '">' . esc_html__( 'Show the events', 'rmd-events-organizer' ) . '</a></p></div>';

		$notes = (array) ( $result['notes'] ?? [] );
		if ( [] === $notes ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Please check:', 'rmd-events-organizer' ) . '</p><ul class="ul-disc">';
		foreach ( $notes as $note ) {
			echo '<li><strong>' . esc_html( (string) $note['title'] ) . '</strong>: ' . esc_html( self::note_text( (string) $note['code'], (string) $note['arg'] ) ) . '</li>';
		}
		echo '</ul></div>';
	}

	private static function render_upload(): void {
		echo '<p>' . esc_html__( 'Create many events or market dates at once from a table. Every line of the file is one date. Choose the file to see what would be imported; nothing is created before you confirm.', 'rmd-events-organizer' ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_UPLOAD ) . '">';
		wp_nonce_field( self::ACTION_UPLOAD );
		echo '<p><label for="' . esc_attr( self::FIELD ) . '" class="screen-reader-text">' . esc_html__( 'CSV file', 'rmd-events-organizer' ) . '</label>';
		echo '<input type="file" id="' . esc_attr( self::FIELD ) . '" name="' . esc_attr( self::FIELD ) . '" accept=".csv,text/csv,text/plain" required></p>';
		submit_button( __( 'Read the file', 'rmd-events-organizer' ), 'primary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_TEMPLATE ), self::ACTION_TEMPLATE ) ) . '">' . esc_html__( 'Download a template', 'rmd-events-organizer' ) . '</a>';
		echo '</form>';
	}

	private static function render_columns(): void {
		$german = self::is_german();
		$yes    = $german ? 'ja' : 'yes';
		/* translators: 1, 2: the two words that can be entered */
		$choice = __( '%1$s (default) or %2$s.', 'rmd-events-organizer' );
		$rows   = [
			'title'         => __( 'Name of the event. Required.', 'rmd-events-organizer' ),
			'date'          => __( 'Day, or first day: 12.12.2026 or 2026-12-12. Required.', 'rmd-events-organizer' ),
			'end_date'      => __( 'Last day, only for dates over several days.', 'rmd-events-organizer' ),
			'start'         => __( 'Start time, e.g. 10:00. Empty: all day.', 'rmd-events-organizer' ),
			'end'           => __( 'End time.', 'rmd-events-organizer' ),
			/* translators: %s: the word to enter, e.g. "yes" */
			'daily_hours'   => sprintf( __( '%s: over several days the times apply to every day (daily from 10:00 to 18:00). Empty: from the start on the first day to the end on the last.', 'rmd-events-organizer' ), $yes ),
			'key'           => __( 'Any short name of your own. Lines with the same key become one event with several dates, described by the first of these lines. Importing a file again updates the events with a key instead of creating them again.', 'rmd-events-organizer' ),
			'kind'          => sprintf( $choice, $german ? 'veranstaltung' : 'event', $german ? 'markt' : 'market' ),
			'description'   => __( 'Text of the event.', 'rmd-events-organizer' ),
			'location'      => __( 'Name of the location. An existing location of that name is used, otherwise one is created with the address columns.', 'rmd-events-organizer' ),
			'street'        => __( 'Address of a new location.', 'rmd-events-organizer' ),
			'postcode'      => '',
			'city'          => '',
			'country'       => __( 'Two letters, e.g. DE.', 'rmd-events-organizer' ),
			'categories'    => __( 'Names, several separated by commas. Missing ones are created.', 'rmd-events-organizer' ),
			'tags'          => __( 'Names, several separated by commas. Missing ones are created.', 'rmd-events-organizer' ),
			'organizer'     => __( 'Name of the organiser.', 'rmd-events-organizer' ),
			'organizer_url' => __( 'Complete address, starting with https://', 'rmd-events-organizer' ),
			'info_url'      => __( 'Address with further information, starting with https://', 'rmd-events-organizer' ),
			'price'         => __( 'Free text, e.g. “Free admission”.', 'rmd-events-organizer' ),
			'stall'         => __( 'Market dates: where your stall is.', 'rmd-events-organizer' ),
			'stall_note'    => __( 'Market dates: a note about the stall.', 'rmd-events-organizer' ),
			'status'        => sprintf( $choice, $german ? 'veroeffentlicht' : 'published', $german ? 'entwurf' : 'draft' ),
			'image'         => __( 'Id or address of an image that is already in the media library.', 'rmd-events-organizer' ),
			'language'      => __( 'Code of the language, e.g. de (only with Polylang).', 'rmd-events-organizer' ),
		];
		if ( ! Modules::is_on( Modules::MARKETS ) ) {
			unset( $rows['kind'], $rows['stall'], $rows['stall_note'] );
		}
		if ( ! Modules::is_on( Modules::LOCATIONS ) ) {
			unset( $rows['location'], $rows['street'], $rows['postcode'], $rows['city'], $rows['country'] );
		}
		if ( ! Polylang::is_active() ) {
			unset( $rows['language'] );
		}

		echo '<h2>' . esc_html__( 'Columns', 'rmd-events-organizer' ) . '</h2>';
		echo '<p>' . esc_html__( 'The first line names the columns. Only title and date are needed; leave out what you do not use, the order does not matter. Separate the columns with semicolons or commas and save the file as “CSV UTF-8”.', 'rmd-events-organizer' ) . '</p>';
		echo '<table class="widefat striped rmd-eo-csv__columns"><thead><tr><th>' . esc_html__( 'Column', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Content', 'rmd-events-organizer' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $column => $text ) {
			echo '<tr><td><code>' . esc_html( $german ? CsvReader::COLUMNS[ $column ][0] : $column ) . '</code></td><td>' . esc_html( $text ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Repeating dates (every Tuesday, every first Saturday) cannot be imported; enter them in the event itself.', 'rmd-events-organizer' ) . '</p>';
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private static function render_preview( array $plan ): void {
		$units  = (array) ( $plan['units'] ?? [] );
		$issues = (array) ( $plan['issues'] ?? [] );
		$kinds  = [
			Kinds::EVENT  => __( 'Event', 'rmd-events-organizer' ),
			Kinds::MARKET => __( 'Market date', 'rmd-events-organizer' ),
		];

		/* translators: %s: name of a file */
		echo '<h2>' . esc_html( sprintf( __( 'Preview of “%s”', 'rmd-events-organizer' ), (string) ( $plan['file'] ?? '' ) ) ) . '</h2>';

		if ( [] !== (array) ( $plan['unknown'] ?? [] ) ) {
			/* translators: %s: list of column names */
			echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'Columns that are not known and are ignored: %s', 'rmd-events-organizer' ), implode( ', ', (array) $plan['unknown'] ) ) ) . '</p></div>';
		}

		if ( [] !== $issues ) {
			$skipped = count( array_filter( $issues, static fn( array $issue ): bool => ! empty( $issue['skipped'] ) ) );
			echo '<h3>' . esc_html__( 'Problems in the file', 'rmd-events-organizer' ) . '</h3>';
			if ( $skipped > 0 ) {
				/* translators: %d: number of lines */
				echo '<p>' . esc_html( sprintf( _n( '%d line cannot be imported. Correct the file and read it again, or import the rest.', '%d lines cannot be imported. Correct the file and read it again, or import the rest.', $skipped, 'rmd-events-organizer' ), $skipped ) ) . '</p>';
			}
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Line', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Problem', 'rmd-events-organizer' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $issues, 0, self::PREVIEW ) as $issue ) {
				echo '<tr><td>' . (int) $issue['line'] . '</td><td>' . esc_html( self::issue_text( (string) $issue['code'], (string) $issue['arg'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			self::more( count( $issues ) );
		}

		if ( [] === $units ) {
			echo '<p><strong>' . esc_html__( 'The file contains nothing that can be imported.', 'rmd-events-organizer' ) . '</strong></p>';
			MigrationPage::button( self::ACTION_DISCARD, __( 'Choose another file', 'rmd-events-organizer' ), 'secondary' );
			return;
		}

		$dates = array_sum( array_map( static fn( array $unit ): int => count( $unit['entries'] ), $units ) );
		/* translators: 1: number of events, 2: number of dates */
		echo '<h3>' . esc_html( sprintf( __( '%1$d events with %2$d dates', 'rmd-events-organizer' ), count( $units ), $dates ) ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Line', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Title', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Kind', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Dates', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Location', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Categories and tags', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Becomes', 'rmd-events-organizer' ) . '</th></tr></thead><tbody>';
		$known = [];
		foreach ( array_slice( $units, 0, self::PREVIEW ) as $unit ) {
			$name = (string) $unit['location']['name'];
			if ( '' !== $name && ! isset( $known[ $name ] ) ) {
				$known[ $name ] = CsvImporter::find_location( $name ) > 0;
			}
			$location = $name;
			if ( '' !== $name && ! Modules::is_on( Modules::LOCATIONS ) ) {
				$location = '–';
			} elseif ( '' !== $name && ! $known[ $name ] ) {
				/* translators: %s: name of a location */
				$location = sprintf( __( '%s (new)', 'rmd-events-organizer' ), $name );
			}

			$state = CsvImporter::find_event( (string) $unit['key'] ) > 0 ? __( 'is updated', 'rmd-events-organizer' ) : __( 'new', 'rmd-events-organizer' );
			if ( CsvPlanner::STATUS_DRAFT === $unit['status'] ) {
				$state .= ', ' . __( 'draft', 'rmd-events-organizer' );
			}

			echo '<tr><td>' . esc_html( implode( ', ', array_slice( (array) $unit['lines'], 0, 3 ) ) . ( count( $unit['lines'] ) > 3 ? ' …' : '' ) ) . '</td>';
			echo '<td><strong>' . esc_html( (string) $unit['title'] ) . '</strong></td>';
			echo '<td>' . esc_html( $kinds[ $unit['kind'] ] ?? '' ) . '</td>';
			echo '<td>' . esc_html( self::dates_text( (array) $unit['entries'] ) ) . '</td>';
			echo '<td>' . esc_html( $location ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', array_merge( (array) $unit['categories'], (array) $unit['tags'] ) ) ) . '</td>';
			echo '<td>' . esc_html( $state ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::more( count( $units ) );

		$without_key = count( array_filter( $units, static fn( array $unit ): bool => '' === $unit['key'] ) );
		if ( $without_key > 0 ) {
			/* translators: %d: number of events */
			echo '<p class="description">' . esc_html( sprintf( _n( '%d event has no key: it is created again every time this file is imported.', '%d events have no key: they are created again every time this file is imported.', $without_key, 'rmd-events-organizer' ), $without_key ) ) . '</p>';
		}

		echo '<div class="rmd-eo-csv__actions">';
		/* translators: %d: number of events */
		MigrationPage::button( self::ACTION_IMPORT, sprintf( _n( 'Import %d event', 'Import %d events', count( $units ), 'rmd-events-organizer' ), count( $units ) ), 'primary', '', [], true );
		MigrationPage::button( self::ACTION_DISCARD, __( 'Choose another file', 'rmd-events-organizer' ), 'secondary', '', [], true );
		echo '</div>';
	}

	private static function more( int $total ): void {
		if ( $total > self::PREVIEW ) {
			/* translators: 1: number of rows shown, 2: number of all rows */
			echo '<p class="description">' . esc_html( sprintf( __( 'The first %1$d of %2$d are shown.', 'rmd-events-organizer' ), self::PREVIEW, $total ) ) . '</p>';
		}
	}

	/**
	 * @param list<array<string, mixed>> $entries
	 */
	private static function dates_text( array $entries ): string {
		$format = (string) get_option( 'date_format', 'Y-m-d' );
		$parts  = [];
		foreach ( array_slice( $entries, 0, 3 ) as $entry ) {
			$text = mysql2date( $format, (string) $entry['start_date'] );
			if ( $entry['end_date'] !== $entry['start_date'] ) {
				$text .= ' – ' . mysql2date( $format, (string) $entry['end_date'] );
			}
			if ( '' !== $entry['start_time'] ) {
				$text .= ', ' . $entry['start_time'] . ( '' !== $entry['end_time'] ? '–' . $entry['end_time'] : '' );
			}
			if ( Occurrence::MODE_DAILY === $entry['time_mode'] ) {
				$text .= ' ' . __( '(every day)', 'rmd-events-organizer' );
			}
			$parts[] = $text;
		}
		if ( count( $entries ) > 3 ) {
			/* translators: %d: number of dates */
			$parts[] = sprintf( __( 'and %d more', 'rmd-events-organizer' ), count( $entries ) - 3 );
		}
		return implode( ' · ', $parts );
	}

	private static function issue_text( string $code, string $arg ): string {
		switch ( $code ) {
			case 'no_title':
				return __( 'The title is missing.', 'rmd-events-organizer' );
			case 'no_date':
				return __( 'The date is missing.', 'rmd-events-organizer' );
			case 'invalid_date':
				return __( 'The date is not a valid date. Write it like 12.12.2026 or 2026-12-12.', 'rmd-events-organizer' );
			case 'invalid_end_date':
				return __( 'The last day is not a valid date.', 'rmd-events-organizer' );
			case 'end_before_start':
				return __( 'The last day is before the first day.', 'rmd-events-organizer' );
			case 'invalid_time':
				return __( 'A time is not valid. Write it like 10:00.', 'rmd-events-organizer' );
			case 'end_without_start':
				return __( 'There is an end time but no start time.', 'rmd-events-organizer' );
			case 'daily_needs_end':
				return __( 'Times that apply to every day need an end time after the start time.', 'rmd-events-organizer' );
			case 'invalid_kind':
				/* translators: %s: value found in the file */
				return sprintf( __( 'The kind “%s” is not known.', 'rmd-events-organizer' ), $arg );
			case 'invalid_status':
				/* translators: %s: value found in the file */
				return sprintf( __( 'The status “%s” is not known.', 'rmd-events-organizer' ), $arg );
			case 'duplicate_date':
				return __( 'This event already has a date on that day at that time.', 'rmd-events-organizer' );
			case 'too_many':
				/* translators: %s: number of dates */
				return sprintf( __( 'An event can have at most %s dates.', 'rmd-events-organizer' ), $arg );
			case 'invalid_url':
				/* translators: %s: name of a column */
				return sprintf( __( 'The address in the column “%s” is not complete (it must start with https://) and is left out; the line is imported.', 'rmd-events-organizer' ), self::is_german() ? CsvReader::COLUMNS[ $arg ][0] ?? $arg : $arg );
		}
		return $code;
	}

	private static function note_text( string $code, string $arg ): string {
		switch ( $code ) {
			case CsvImporter::NOTE_IMAGE:
				/* translators: %s: value found in the file */
				return sprintf( __( 'the image “%s” was not found in the media library.', 'rmd-events-organizer' ), $arg );
			case CsvImporter::NOTE_LANGUAGE:
				/* translators: %s: value found in the file */
				return sprintf( __( 'the language “%s” does not exist; the default language was used.', 'rmd-events-organizer' ), $arg );
			case CsvImporter::NOTE_FAILED:
				/* translators: %s: error message */
				return sprintf( __( 'could not be saved (%s).', 'rmd-events-organizer' ), $arg );
		}
		return $code;
	}

	private static function is_german(): bool {
		return 0 === strpos( (string) get_user_locale(), 'de' );
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	public static function handle_upload(): void {
		self::guard( self::ACTION_UPLOAD );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Verified in guard(); the upload is only read, never stored.
		$file = isset( $_FILES[ self::FIELD ] ) && is_array( $_FILES[ self::FIELD ] ) ? $_FILES[ self::FIELD ] : [];
		// phpcs:enable
		$path = (string) ( $file['tmp_name'] ?? '' );
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $path || ! is_uploaded_file( $path ) ) {
			self::back( [ 'state' => 'upload' ] );
		}
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_SIZE ) {
			self::back( [ 'state' => 'size' ] );
		}
		$text = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local temporary file.
		if ( false === $text ) {
			self::back( [ 'state' => 'upload' ] );
		}

		$parsed = CsvReader::parse( (string) $text );
		if ( '' !== $parsed['error'] ) {
			self::back( [ 'state' => $parsed['error'] ] );
		}
		$plan = CsvPlanner::plan( $parsed['rows'] );

		delete_transient( self::RESULT . get_current_user_id() );
		set_transient(
			self::PLAN . get_current_user_id(),
			[
				'file'    => sanitize_file_name( (string) ( $file['name'] ?? '' ) ),
				'unknown' => array_map( 'sanitize_text_field', $parsed['unknown'] ),
				'units'   => $plan['units'],
				'issues'  => $plan['issues'],
			],
			HOUR_IN_SECONDS
		);
		self::back();
	}

	public static function handle_import(): void {
		self::guard( self::ACTION_IMPORT );

		$user = get_current_user_id();
		$plan = get_transient( self::PLAN . $user );
		if ( ! is_array( $plan ) || empty( $plan['units'] ) ) {
			self::back( [ 'state' => 'expired' ] );
		}
		$units  = (array) $plan['units'];
		$offset = isset( $_REQUEST['offset'] ) ? absint( $_REQUEST['offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in guard().
		$total  = $offset > 0 ? get_transient( self::RESULT . $user ) : [];
		$total  = is_array( $total ) ? $total : [];
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A batch of an import may take longer than a page view.
		}

		$result = CsvImporter::import( array_slice( $units, $offset, self::BATCH ) );
		foreach ( [ 'created', 'updated', 'locations', 'terms' ] as $number ) {
			$total[ $number ] = (int) ( $total[ $number ] ?? 0 ) + $result[ $number ];
		}
		$total['notes'] = array_merge( (array) ( $total['notes'] ?? [] ), $result['notes'] );
		set_transient( self::RESULT . $user, $total, HOUR_IN_SECONDS );

		$next = $offset + self::BATCH;
		if ( $next >= count( $units ) ) {
			delete_transient( self::PLAN . $user );
			self::back( [ 'state' => 'done' ] );
		}
		MigrationPage::forward(
			wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_IMPORT . '&offset=' . $next ), self::ACTION_IMPORT ),
			/* translators: 1: number of events done, 2: total number of events */
			sprintf( __( 'Importing … %1$d of %2$d events.', 'rmd-events-organizer' ), $next, count( $units ) )
		);
	}

	public static function handle_discard(): void {
		self::guard( self::ACTION_DISCARD );
		delete_transient( self::PLAN . get_current_user_id() );
		self::back();
	}

	public static function handle_template(): void {
		self::guard( self::ACTION_TEMPLATE );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="events.csv"' );
		// The mark at the start makes spreadsheet programs read the umlauts correctly.
		echo "\xEF\xBB\xBF" . CsvReader::header( self::is_german() ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV file made of fixed column names.
		exit;
	}

	private static function guard( string $action ): void {
		if ( ! Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'You are not allowed to import events.', 'rmd-events-organizer' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * @param array<string, string> $args
	 * @return never
	 */
	private static function back( array $args = [] ): void {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}
}
