<?php
/**
 * Schema.org JSON-LD for the page of a single event: the next upcoming date,
 * or the most recent one when the event is over.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

use RMD\EventsOrganizer\Query\OccurrenceQuery;
use RMD\EventsOrganizer\Repository\EventRepository;
use RMD\EventsOrganizer\Repository\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class StructuredData {

	public static function init(): void {
		add_action( 'wp_footer', [ self::class, 'output' ], 20 );
	}

	public static function output(): void {
		if ( ! is_singular( PostTypes::EVENT ) || empty( ( (array) Settings::get( 'display', [] ) )['structured_data'] ) ) {
			return;
		}
		$event_id = (int) get_queried_object_id();
		$graph    = self::graph( $event_id );

		/**
		 * Filters the JSON-LD node emitted for an event. Return an empty
		 * array to suppress the output.
		 *
		 * @param array $graph    The schema.org node.
		 * @param int   $event_id The event.
		 */
		$graph = apply_filters( 'rmd_eo_jsonld', $graph, $event_id );
		if ( ! is_array( $graph ) || [] === $graph ) {
			return;
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function graph( int $event_id ): array {
		$found = OccurrenceQuery::find(
			[
				'event_id' => $event_id,
				'scope'    => 'upcoming',
				'limit'    => 1,
			]
		)['items'];
		if ( [] === $found ) {
			$found = OccurrenceQuery::find(
				[
					'event_id' => $event_id,
					'scope'    => 'past',
					'limit'    => 1,
				]
			)['items'];
		}
		if ( [] === $found ) {
			return [];
		}

		$occurrence = $found[0];
		$details    = EventRepository::details( $event_id );
		$zone       = Clock::timezone();
		$url        = (string) get_permalink( $event_id );

		$graph = [
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'@id'                 => $url . '#event',
			'name'                => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ),
			'url'                 => $url,
			'eventStatus'         => 'https://schema.org/' . ( $occurrence['cancelled'] ? 'EventCancelled' : 'EventScheduled' ),
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
		];

		if ( $occurrence['all_day'] ) {
			$graph['startDate'] = $occurrence['start_date'];
			$graph['endDate']   = $occurrence['end_date'];
		} else {
			$graph['startDate'] = wp_date( 'c', (int) $occurrence['start_ts'], $zone );
			if ( $occurrence['end_ts'] > $occurrence['start_ts'] ) {
				$graph['endDate'] = wp_date( 'c', (int) $occurrence['end_ts'], $zone );
			}
		}

		$description = wp_strip_all_tags( get_the_excerpt( $event_id ) );
		if ( '' !== $description ) {
			$graph['description'] = html_entity_decode( $description, ENT_QUOTES, 'UTF-8' );
		}
		$image = get_the_post_thumbnail_url( $event_id, 'large' );
		if ( $image ) {
			$graph['image'] = $image;
		}

		$location = LocationRepository::find( (int) $occurrence['location_id'] );
		if ( $location ) {
			$address           = array_filter(
				[
					'@type'           => 'PostalAddress',
					'streetAddress'   => $location['address']['street'],
					'postalCode'      => $location['address']['postcode'],
					'addressLocality' => $location['address']['city'],
					'addressRegion'   => $location['address']['region'],
					'addressCountry'  => $location['address']['country'],
				],
				'strlen'
			);
			$graph['location'] = [
				'@type' => 'Place',
				'name'  => $location['name'],
			];
			if ( count( $address ) > 1 ) {
				$graph['location']['address'] = $address;
			}
			if ( '' !== $location['address']['lat'] ) {
				$graph['location']['geo'] = [
					'@type'     => 'GeoCoordinates',
					'latitude'  => (float) $location['address']['lat'],
					'longitude' => (float) $location['address']['lng'],
				];
			}
		}

		if ( '' !== $details['organizer_name'] ) {
			$graph['organizer'] = array_filter(
				[
					'@type' => 'Organization',
					'name'  => $details['organizer_name'],
					'url'   => $details['organizer_url'],
				],
				'strlen'
			);
		}

		return $graph;
	}
}
