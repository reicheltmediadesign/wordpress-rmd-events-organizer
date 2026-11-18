<?php
/**
 * Registers the event and location post types. Dates, place and details are
 * stored as post meta; title, description and image are ordinary post fields.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

defined( 'ABSPATH' ) || exit;

final class PostTypes {

	public const EVENT    = 'rmd_eo_event';
	public const LOCATION = 'rmd_eo_location';

	private const FLUSH_OPTION = 'rmd_eo_flush_rewrite';

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ] );
		add_action( 'init', [ self::class, 'maybe_flush' ], 99 );
		add_filter( 'post_updated_messages', [ self::class, 'messages' ] );
	}

	/**
	 * Messages of the classic edit screen, which otherwise speak of "posts".
	 *
	 * @param array<string, array<int, string>> $messages
	 * @return array<string, array<int, string>>
	 */
	public static function messages( array $messages ): array {
		$view = sprintf( ' <a href="%s">%s</a>', esc_url( (string) get_permalink() ), esc_html__( 'View event', 'rmd-events-organizer' ) );

		$messages[ self::EVENT ]    = [
			1  => esc_html__( 'Event updated.', 'rmd-events-organizer' ) . $view,
			4  => esc_html__( 'Event updated.', 'rmd-events-organizer' ),
			6  => esc_html__( 'Event published.', 'rmd-events-organizer' ) . $view,
			7  => esc_html__( 'Event saved.', 'rmd-events-organizer' ),
			8  => esc_html__( 'Event submitted for review.', 'rmd-events-organizer' ),
			9  => esc_html__( 'Event scheduled.', 'rmd-events-organizer' ),
			10 => esc_html__( 'Event draft updated.', 'rmd-events-organizer' ),
		];
		$messages[ self::LOCATION ] = [
			1  => esc_html__( 'Location updated.', 'rmd-events-organizer' ),
			4  => esc_html__( 'Location updated.', 'rmd-events-organizer' ),
			6  => esc_html__( 'Location saved.', 'rmd-events-organizer' ),
			7  => esc_html__( 'Location saved.', 'rmd-events-organizer' ),
			10 => esc_html__( 'Location saved.', 'rmd-events-organizer' ),
		];
		return $messages;
	}

	public static function register(): void {
		$permalinks = (array) Settings::get( 'permalinks', [] );

		register_post_type(
			self::EVENT,
			[
				'labels'              => [
					'name'                   => __( 'Events', 'rmd-events-organizer' ),
					'singular_name'          => __( 'Event', 'rmd-events-organizer' ),
					'menu_name'              => __( 'Events', 'rmd-events-organizer' ),
					'all_items'              => __( 'All events', 'rmd-events-organizer' ),
					'add_new'                => __( 'Add event', 'rmd-events-organizer' ),
					'add_new_item'           => __( 'Add new event', 'rmd-events-organizer' ),
					'edit_item'              => __( 'Edit event', 'rmd-events-organizer' ),
					'new_item'               => __( 'New event', 'rmd-events-organizer' ),
					'view_item'              => __( 'View event', 'rmd-events-organizer' ),
					'search_items'           => __( 'Search events', 'rmd-events-organizer' ),
					'not_found'              => __( 'No events found.', 'rmd-events-organizer' ),
					'not_found_in_trash'     => __( 'No events in the trash.', 'rmd-events-organizer' ),
					'featured_image'         => __( 'Event image', 'rmd-events-organizer' ),
					'set_featured_image'     => __( 'Set event image', 'rmd-events-organizer' ),
					'remove_featured_image'  => __( 'Remove event image', 'rmd-events-organizer' ),
					'use_featured_image'     => __( 'Use as event image', 'rmd-events-organizer' ),
					'item_published'         => __( 'Event published.', 'rmd-events-organizer' ),
					'item_updated'           => __( 'Event updated.', 'rmd-events-organizer' ),
					'item_trashed'           => __( 'Event moved to the trash.', 'rmd-events-organizer' ),
					'item_scheduled'         => __( 'Event scheduled.', 'rmd-events-organizer' ),
					'item_reverted_to_draft' => __( 'Event reverted to draft.', 'rmd-events-organizer' ),
				],
				'description'         => __( 'Events and market dates managed by RMD Events Organizer.', 'rmd-events-organizer' ),
				'public'              => true,
				'show_in_rest'        => false,
				'exclude_from_search' => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-calendar-alt',
				'supports'            => [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ],
				'rewrite'             => [
					'slug'       => (string) ( $permalinks['event_slug'] ?? 'events' ),
					'with_front' => false,
					'feeds'      => false,
				],
				'map_meta_cap'        => false,
				'capabilities'        => self::capabilities(),
			]
		);

		if ( ! Modules::is_on( Modules::LOCATIONS ) ) {
			return;
		}

		$public = ! empty( $permalinks['location_public'] );
		register_post_type(
			self::LOCATION,
			[
				'labels'              => [
					'name'               => __( 'Locations', 'rmd-events-organizer' ),
					'singular_name'      => __( 'Location', 'rmd-events-organizer' ),
					'all_items'          => __( 'Locations', 'rmd-events-organizer' ),
					'add_new'            => __( 'Add location', 'rmd-events-organizer' ),
					'add_new_item'       => __( 'Add new location', 'rmd-events-organizer' ),
					'edit_item'          => __( 'Edit location', 'rmd-events-organizer' ),
					'new_item'           => __( 'New location', 'rmd-events-organizer' ),
					'view_item'          => __( 'View location', 'rmd-events-organizer' ),
					'search_items'       => __( 'Search locations', 'rmd-events-organizer' ),
					'not_found'          => __( 'No locations found.', 'rmd-events-organizer' ),
					'not_found_in_trash' => __( 'No locations in the trash.', 'rmd-events-organizer' ),
					'item_published'     => __( 'Location saved.', 'rmd-events-organizer' ),
					'item_updated'       => __( 'Location updated.', 'rmd-events-organizer' ),
				],
				'public'              => $public,
				'publicly_queryable'  => $public,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . self::EVENT,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => $public,
				'show_in_rest'        => false,
				'exclude_from_search' => ! $public,
				'has_archive'         => false,
				'hierarchical'        => false,
				'supports'            => [ 'title', 'editor', 'thumbnail' ],
				'rewrite'             => $public ? [
					'slug'       => (string) ( $permalinks['location_slug'] ?? 'locations' ),
					'with_front' => false,
					'feeds'      => false,
				] : false,
				'query_var'           => $public,
				'map_meta_cap'        => false,
				'capabilities'        => self::capabilities(),
			]
		);
	}

	/**
	 * Rewrite rules depend on the slugs and on which post types exist, so
	 * they are rebuilt once after those change.
	 */
	public static function request_flush(): void {
		update_option( self::FLUSH_OPTION, '1', true );
	}

	public static function maybe_flush(): void {
		if ( '1' !== get_option( self::FLUSH_OPTION ) ) {
			return;
		}
		delete_option( self::FLUSH_OPTION );
		flush_rewrite_rules( false );
	}

	/**
	 * Every editing capability maps to the plugin's single capability.
	 *
	 * @return array<string, string>
	 */
	private static function capabilities(): array {
		$cap = Capabilities::CAP;

		return [
			'edit_post'              => $cap,
			'read_post'              => 'read',
			'delete_post'            => $cap,
			'edit_posts'             => $cap,
			'edit_others_posts'      => $cap,
			'publish_posts'          => $cap,
			'read_private_posts'     => $cap,
			'read'                   => 'read',
			'delete_posts'           => $cap,
			'delete_private_posts'   => $cap,
			'delete_published_posts' => $cap,
			'delete_others_posts'    => $cap,
			'edit_private_posts'     => $cap,
			'edit_published_posts'   => $cap,
			'create_posts'           => $cap,
		];
	}
}
