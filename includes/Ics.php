<?php
/**
 * Calendar file download for a single date: ?rmd_eo_ics=<occurrence id>.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

use RMD\EventsOrganizer\Domain\IcsWriter;
use RMD\EventsOrganizer\Query\OccurrenceQuery;
use RMD\EventsOrganizer\Repository\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class Ics {

	public const QUERY_VAR = 'rmd_eo_ics';

	public static function init(): void {
		add_action( 'template_redirect', [ self::class, 'maybe_send' ], 1 );
	}

	public static function url( int $occurrence_id ): string {
		return add_query_arg( self::QUERY_VAR, $occurrence_id, home_url( '/' ) );
	}

	public static function maybe_send(): void {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only download.
			return;
		}
		if ( empty( ( (array) Settings::get( 'display', [] ) )['ics'] ) ) {
			return;
		}
		$occurrence = OccurrenceQuery::get( absint( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $occurrence ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$event_id = (int) $occurrence['event_id'];
		$location = LocationRepository::find( (int) $occurrence['location_id'] );
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$body = IcsWriter::calendar(
			[
				'uid'         => 'rmd-eo-' . $occurrence['id'] . '@' . $host,
				'summary'     => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ),
				'description' => html_entity_decode( wp_strip_all_tags( get_the_excerpt( $event_id ) ), ENT_QUOTES, 'UTF-8' ),
				'location'    => $location ? implode( ', ', array_filter( [ $location['name'], LocationRepository::one_line( $location['address'] ) ], 'strlen' ) ) : '',
				'url'         => (string) get_permalink( $event_id ),
				'all_day'     => $occurrence['all_day'],
				'start_date'  => $occurrence['start_date'],
				'end_date'    => $occurrence['end_date'],
				'start_ts'    => $occurrence['start_ts'],
				'end_ts'      => $occurrence['end_ts'],
				'cancelled'   => $occurrence['cancelled'],
			],
			'-//reichelt media.design//RMD Events Organizer ' . RMD_EO_VERSION . '//EN',
			time()
		);

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( get_post_field( 'post_name', $event_id ) . '-' . $occurrence['start_date'] . '.ics' ) . '"' );
		header( 'X-Robots-Tag: noindex' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, escaped by IcsWriter.
		exit;
	}
}
