<?php
/**
 * Public read-only endpoints.
 *
 *   GET /rmd-events-organizer/v1/calendar?month=YYYY-MM&size=full|mini&kind=&category=&tag=&location=
 *       Rendered calendar for the month navigation of the front-end script.
 *   GET /rmd-events-organizer/v1/choices
 *       Events, locations, categories and tags for the block settings (editors only).
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Rest;

use RMD\EventsOrganizer\Capabilities;
use RMD\EventsOrganizer\Domain\Dates;
use RMD\EventsOrganizer\Modules;
use RMD\EventsOrganizer\Polylang;
use RMD\EventsOrganizer\PostTypes;
use RMD\EventsOrganizer\Render\CalendarRenderer;
use RMD\EventsOrganizer\Repository\LocationRepository;
use RMD\EventsOrganizer\Taxonomies;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class CalendarController {

	public const NAMESPACE = 'rmd-events-organizer/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		$text = [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		];

		register_rest_route(
			self::NAMESPACE,
			'/calendar',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'calendar' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'month'    => [
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && Dates::is_valid_month( $value ),
					],
					'size'     => [
						'type'    => 'string',
						'default' => 'full',
						'enum'    => CalendarRenderer::SIZES,
					],
					'kind'     => $text,
					'category' => $text,
					'tag'      => $text,
					'lang'     => $text,
					'location' => [
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/choices',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'choices' ],
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ) || Capabilities::current_user_can(),
			]
		);
	}

	public static function calendar( WP_REST_Request $request ) {
		if ( ! Modules::is_on( Modules::CALENDAR ) ) {
			return new WP_Error( 'rmd_eo_calendar_off', __( 'The calendar is switched off.', 'rmd-events-organizer' ), [ 'status' => 404 ] );
		}

		// Month and weekday names in the language of the page the calendar is on.
		$locale   = Polylang::locale( sanitize_key( (string) $request['lang'] ) );
		$switched = '' !== $locale && switch_to_locale( $locale );

		$response = new WP_REST_Response(
			[
				'html' => CalendarRenderer::render(
					[
						'lang'     => sanitize_key( (string) $request['lang'] ),
						'month'    => (string) $request['month'],
						'size'     => (string) $request['size'],
						'kind'     => sanitize_key( (string) $request['kind'] ),
						'category' => (string) $request['category'],
						'tag'      => (string) $request['tag'],
						'location' => (int) $request['location'],
					]
				),
			]
		);
		if ( $switched ) {
			restore_previous_locale();
		}
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	public static function choices(): WP_REST_Response {
		$events = [];
		foreach (
			get_posts(
				[
					'post_type'      => PostTypes::EVENT,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				]
			) as $post
		) {
			$events[] = [
				'id'    => (int) $post->ID,
				'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			];
		}

		$locations = [];
		if ( Modules::is_on( Modules::LOCATIONS ) ) {
			foreach ( LocationRepository::choices() as $id => $name ) {
				$locations[] = [
					'id'    => $id,
					'title' => html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ),
				];
			}
		}

		return new WP_REST_Response(
			[
				'events'     => $events,
				'locations'  => $locations,
				'categories' => self::terms( Taxonomies::CATEGORY ),
				'tags'       => self::terms( Taxonomies::TAG ),
				'modules'    => Modules::states(),
			]
		);
	}

	/**
	 * @return list<array{slug: string, name: string}>
	 */
	private static function terms( string $taxonomy ): array {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			]
		);
		$list  = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$list[] = [
				'slug' => (string) $term->slug,
				'name' => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
			];
		}
		return $list;
	}
}
