<?php
/**
 * Events → Settings (Settings API). Only administrators see this page; it
 * decides which parts of the plugin exist on the site.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Admin;

use RMD\EventsOrganizer\Capabilities;
use RMD\EventsOrganizer\Domain\Settings as SettingsData;
use RMD\EventsOrganizer\Modules;
use RMD\EventsOrganizer\PostTypes;
use RMD\EventsOrganizer\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const SLUG = 'rmd-eo-settings';
	public const CAP  = 'manage_options';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_init', [ self::class, 'fields' ] );
		add_filter( 'plugin_action_links_' . RMD_EO_BASENAME, [ self::class, 'action_links' ] );
	}

	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostTypes::EVENT,
			__( 'Events Settings', 'rmd-events-organizer' ),
			__( 'Settings', 'rmd-events-organizer' ),
			self::CAP,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * @param array<int|string, string> $links
	 * @return array<int|string, string>
	 */
	public static function action_links( array $links ): array {
		if ( current_user_can( self::CAP ) ) {
			array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'rmd-events-organizer' ) ) );
		}
		return $links;
	}

	public static function url(): string {
		return admin_url( 'edit.php?post_type=' . PostTypes::EVENT . '&page=' . self::SLUG );
	}

	public static function fields(): void {
		$page = self::SLUG;

		add_settings_section( 'modules', __( 'Components', 'rmd-events-organizer' ), [ self::class, 'modules_intro' ], $page );
		self::field( 'modules.locations', __( 'Locations', 'rmd-events-organizer' ), 'modules', 'module', [ 'checkbox_label' => __( 'Manage locations with an address and assign them to events', 'rmd-events-organizer' ) ] );
		self::field( 'modules.markets', __( 'Market dates', 'rmd-events-organizer' ), 'modules', 'module', [ 'checkbox_label' => __( 'Enter markets where the business has a stall and list them separately', 'rmd-events-organizer' ) ] );
		self::field( 'modules.calendar', __( 'Calendar', 'rmd-events-organizer' ), 'modules', 'module', [ 'checkbox_label' => __( 'Month calendar and small calendar as block and shortcode', 'rmd-events-organizer' ) ] );

		add_settings_section( 'display', __( 'Display', 'rmd-events-organizer' ), '__return_empty_string', $page );
		self::field(
			'display.list_limit',
			__( 'Dates per list', 'rmd-events-organizer' ),
			'display',
			'number',
			[
				'min'         => 1,
				'max'         => SettingsData::MAX_LIMIT,
				'description' => __( 'Used when a block or shortcode does not set its own limit.', 'rmd-events-organizer' ),
			]
		);
		self::field( 'display.show_end_time', __( 'End time', 'rmd-events-organizer' ), 'display', 'checkbox', [ 'checkbox_label' => __( 'Show the end time of events', 'rmd-events-organizer' ) ] );
		self::field( 'display.single_details', __( 'Event page', 'rmd-events-organizer' ), 'display', 'checkbox', [ 'checkbox_label' => __( 'Show dates, location and details above the description automatically', 'rmd-events-organizer' ) ] );
		self::field( 'display.ics', __( 'Calendar file', 'rmd-events-organizer' ), 'display', 'checkbox', [ 'checkbox_label' => __( 'Offer “Add to calendar” (.ics) for upcoming dates', 'rmd-events-organizer' ) ] );
		self::field( 'display.structured_data', __( 'Structured data', 'rmd-events-organizer' ), 'display', 'checkbox', [ 'checkbox_label' => __( 'Describe events for search engines (schema.org JSON-LD)', 'rmd-events-organizer' ) ] );
		self::field(
			'calendar.horizon_months',
			__( 'Repeat ahead (months)', 'rmd-events-organizer' ),
			'display',
			'number',
			[
				'min'         => SettingsData::MIN_HORIZON,
				'max'         => SettingsData::MAX_HORIZON,
				'description' => __( 'How far into the future dates of repeating events without an end are created.', 'rmd-events-organizer' ),
			]
		);

		add_settings_section( 'pages', __( 'Pages and addresses', 'rmd-events-organizer' ), [ self::class, 'pages_intro' ], $page );
		self::field( 'pages.events', __( 'Events page', 'rmd-events-organizer' ), 'pages', 'page', [ 'description' => __( 'The page that shows the events list. Days in the small calendar link to it.', 'rmd-events-organizer' ) ] );
		self::field( 'permalinks.event_slug', __( 'Address of events', 'rmd-events-organizer' ), 'pages', 'slug' );
		self::field( 'permalinks.location_public', __( 'Location pages', 'rmd-events-organizer' ), 'pages', 'checkbox', [ 'checkbox_label' => __( 'Locations have their own public page', 'rmd-events-organizer' ) ] );
		self::field( 'permalinks.location_slug', __( 'Address of locations', 'rmd-events-organizer' ), 'pages', 'slug' );

		add_settings_section( 'access', __( 'Access', 'rmd-events-organizer' ), [ self::class, 'access_intro' ], $page );
		self::field( 'roles', __( 'Roles that may manage events', 'rmd-events-organizer' ), 'access', 'roles' );

		add_settings_section( 'danger', __( 'Uninstall', 'rmd-events-organizer' ), '__return_empty_string', $page );
		self::field( 'delete_on_uninstall', __( 'Delete data', 'rmd-events-organizer' ), 'danger', 'checkbox', [ 'checkbox_label' => __( 'Delete all events, locations, categories and settings when the plugin is deleted', 'rmd-events-organizer' ) ] );
	}

	public static function modules_intro(): void {
		echo '<p>' . esc_html__( 'Switch off what this website does not need. A component that is off adds no menu items, blocks or pages.', 'rmd-events-organizer' ) . '</p>';
	}

	public static function pages_intro(): void {
		echo '<p>' . esc_html__( 'Changing an address changes the links of all events. Visitors and search engines that know the old links will no longer find them.', 'rmd-events-organizer' ) . '</p>';
	}

	public static function access_intro(): void {
		echo '<p>' . esc_html__( 'Administrators always have access. Tick the roles that should also see the Events menu and edit events, locations and categories. These settings stay reserved for administrators.', 'rmd-events-organizer' ) . '</p>';
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'rmd-events-organizer' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Events Settings', 'rmd-events-organizer' ) . '</h1>';
		settings_errors( Settings::OPTION );
		echo '<form method="post" action="options.php">';
		settings_fields( Settings::GROUP );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form></div>';
	}

	private static function field( string $key, string $label, string $section, string $type, array $args = [] ): void {
		add_settings_field(
			$key,
			$label,
			[ self::class, 'render_field' ],
			self::SLUG,
			$section,
			[
				'key'       => $key,
				'type'      => $type,
				'label_for' => in_array( $type, [ 'checkbox', 'module', 'roles' ], true ) ? '' : 'rmd-eo-' . str_replace( '.', '-', $key ),
			] + $args
		);
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public static function render_field( array $args ): void {
		$path  = explode( '.', (string) $args['key'] );
		$name  = Settings::OPTION . '[' . implode( '][', $path ) . ']';
		$id    = 'rmd-eo-' . implode( '-', $path );
		$value = Settings::get( $path[0] );
		if ( isset( $path[1] ) ) {
			$value = is_array( $value ) ? ( $value[ $path[1] ] ?? null ) : null;
		}

		switch ( $args['type'] ) {
			case 'number':
				printf(
					'<input type="number" id="%s" name="%s" value="%d" min="%d" max="%d" class="small-text">',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) $value,
					(int) ( $args['min'] ?? 0 ),
					(int) ( $args['max'] ?? 999 )
				);
				break;

			case 'slug':
				printf(
					'<code>%s</code><input type="text" id="%s" name="%s" value="%s" class="regular-text code"><code>/…</code>',
					esc_html( trailingslashit( home_url() ) ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;

			case 'page':
				wp_dropdown_pages(
					[
						'name'              => esc_attr( $name ),
						'id'                => esc_attr( $id ),
						'selected'          => (int) $value,
						'show_option_none'  => esc_html__( '— None —', 'rmd-events-organizer' ),
						'option_none_value' => '0',
					]
				);
				break;

			case 'module':
				$module = (string) ( $path[1] ?? '' );
				if ( Modules::is_locked( $module ) ) {
					printf(
						'<label><input type="checkbox" %s disabled> %s</label><p class="description">%s</p>',
						checked( Modules::is_on( $module ), true, false ),
						esc_html( (string) ( $args['checkbox_label'] ?? '' ) ),
						esc_html__( 'Fixed by your web developer for this website.', 'rmd-events-organizer' )
					);
					return;
				}
				self::checkbox( $id, $name, (bool) $value, (string) ( $args['checkbox_label'] ?? '' ) );
				break;

			case 'checkbox':
				self::checkbox( $id, $name, (bool) $value, (string) ( $args['checkbox_label'] ?? __( 'Enabled', 'rmd-events-organizer' ) ) );
				break;

			case 'roles':
				$can_edit = current_user_can( 'promote_users' );
				$selected = (array) $value;
				echo '<fieldset>';
				printf(
					'<label><input type="checkbox" checked disabled> %s <span class="description">(%s)</span></label><br>',
					esc_html( translate_user_role( 'Administrator' ) ),
					esc_html__( 'always', 'rmd-events-organizer' )
				);
				if ( $can_edit ) {
					printf( '<input type="hidden" name="%s" value="1">', esc_attr( Settings::OPTION . '[roles_submitted]' ) );
				}
				foreach ( Capabilities::selectable_roles() as $slug => $label ) {
					printf(
						'<label><input type="checkbox" name="%s" value="%s" %s %s> %s</label><br>',
						esc_attr( $name . '[]' ),
						esc_attr( $slug ),
						checked( in_array( $slug, $selected, true ), true, false ),
						$can_edit ? '' : 'disabled',
						esc_html( $label )
					);
				}
				echo '</fieldset>';
				break;
		}

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( (string) $args['description'] ) . '</p>';
		}
	}

	private static function checkbox( string $id, string $name, bool $checked, string $label ): void {
		printf( '<input type="hidden" name="%s" value="0">', esc_attr( $name ) );
		printf(
			'<label><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>',
			esc_attr( $id ),
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}
}
