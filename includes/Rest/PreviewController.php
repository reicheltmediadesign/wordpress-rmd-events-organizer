<?php
/**
 * Computes the dates a schedule produces, for the preview in the event
 * editor. Nothing is stored; the recurrence logic stays in PHP only.
 *
 *   POST /rmd-events-organizer/v1/preview   { schedule: {...} }
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Rest;

use RMD\EventsOrganizer\Capabilities;
use RMD\EventsOrganizer\Clock;
use RMD\EventsOrganizer\Domain\Occurrence;
use RMD\EventsOrganizer\Domain\Schedule;
use RMD\EventsOrganizer\Meta;
use RMD\EventsOrganizer\Render\Formatter;
use RMD\EventsOrganizer\Sync\OccurrenceSync;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class PreviewController {

	public const MAX_ITEMS = 12;

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			CalendarController::NAMESPACE,
			'/preview',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'preview' ],
				'permission_callback' => static fn(): bool => Capabilities::current_user_can(),
				'args'                => [
					'schedule' => [
						'type'     => 'object',
						'required' => true,
					],
				],
			]
		);
	}

	public static function preview( WP_REST_Request $request ): WP_REST_Response {
		$result      = Meta::validator()->normalize_schedule( $request['schedule'] );
		$occurrences = Schedule::expand( $result['data'], OccurrenceSync::horizon(), wp_timezone_string() );
		$now         = gmdate( 'Y-m-d H:i:s', Clock::timestamp() );

		$upcoming = array_values( array_filter( $occurrences, static fn( Occurrence $o ): bool => $o->end_utc >= $now ) );
		$items    = [];
		foreach ( array_slice( $upcoming, 0, self::MAX_ITEMS ) as $occurrence ) {
			$items[] = [
				'key'       => $occurrence->key,
				'label'     => Formatter::when(
					[
						'start_date' => $occurrence->start_date,
						'end_date'   => $occurrence->end_date,
						'start_time' => $occurrence->start_time,
						'end_time'   => $occurrence->end_time,
						'all_day'    => $occurrence->all_day,
						'time_mode'  => $occurrence->time_mode,
					]
				),
				'isRule'    => false !== strpos( $occurrence->key, ':' ),
				'cancelled' => Occurrence::STATUS_CANCELLED === $occurrence->status,
				'withdrawn' => Occurrence::STATUS_WITHDRAWN === $occurrence->status,
			];
		}

		return new WP_REST_Response(
			[
				'items'     => $items,
				'upcoming'  => count( $upcoming ),
				'total'     => count( $occurrences ),
				'openEnded' => Schedule::is_open_ended( $result['data'] ),
				'errors'    => array_map( [ \RMD\EventsOrganizer\Admin\Notices::class, 'message' ], $result['errors'] ),
			]
		);
	}
}
