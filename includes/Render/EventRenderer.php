<?php
/**
 * Renders the facts of one event: dates, place and details. Shown on the
 * single event page and by the event details block and shortcode.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Render;

use RMD\EventsOrganizer\Assets;
use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Domain\Occurrence;
use RMD\EventsOrganizer\Ics;
use RMD\EventsOrganizer\PostTypes;
use RMD\EventsOrganizer\Query\OccurrenceQuery;
use RMD\EventsOrganizer\Repository\EventRepository;
use RMD\EventsOrganizer\Repository\LocationRepository;
use RMD\EventsOrganizer\Settings;

defined( 'ABSPATH' ) || exit;

final class EventRenderer {

	public const MAX_DATES = 10;

	/**
	 * @param array $args class, title (bool): also print the event title as a link.
	 */
	public static function render( int $event_id, array $args = [] ): string {
		$post = get_post( $event_id );
		if ( ! $post || PostTypes::EVENT !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}
		Assets::enqueue_front();

		$kind    = EventRepository::kind( $event_id );
		$details = EventRepository::details( $event_id );
		$html    = '';

		if ( ! empty( $args['title'] ) ) {
			$html .= sprintf( '<p class="rmd-eo-event__title"><a href="%s">%s</a></p>', esc_url( (string) get_permalink( $post ) ), esc_html( get_the_title( $post ) ) );
		}
		if ( Occurrence::STATUS_CANCELLED === $details['status'] ) {
			$html .= '<p class="rmd-eo-event__status">' . esc_html__( 'This event has been cancelled.', 'rmd-events-organizer' ) . '</p>';
		} elseif ( Occurrence::STATUS_WITHDRAWN === $details['status'] && Kinds::MARKET === $kind ) {
			$html .= '<p class="rmd-eo-event__status">' . esc_html__( 'We are not taking part in this market.', 'rmd-events-organizer' ) . '</p>';
		}

		$html .= self::dates( $event_id );
		$html .= self::location( EventRepository::location_id( $event_id ), Kinds::MARKET === $kind ? $details : [] );
		$html .= self::facts( $details );

		if ( '' === $html ) {
			return '';
		}
		return sprintf(
			'<div class="%s">%s</div>',
			esc_attr( trim( 'rmd-eo rmd-eo-event rmd-eo-event--' . $kind . ' ' . ( $args['class'] ?? '' ) ) ),
			$html
		);
	}

	private static function dates( int $event_id ): string {
		$upcoming = OccurrenceQuery::find(
			[
				'event_id' => $event_id,
				'scope'    => 'upcoming',
				'limit'    => self::MAX_DATES,
			]
		);
		$items    = $upcoming['items'];
		$ended    = false;

		if ( [] === $items ) {
			$items = OccurrenceQuery::find(
				[
					'event_id' => $event_id,
					'scope'    => 'past',
					'limit'    => 1,
				]
			)['items'];
			$ended = true;
		}
		if ( [] === $items ) {
			return '';
		}

		$with_ics = ! $ended && ! empty( ( (array) Settings::get( 'display', [] ) )['ics'] );
		$html     = '<div class="rmd-eo-event__section rmd-eo-event__dates">';
		$html    .= '<h2 class="rmd-eo-event__heading">' . esc_html__( 'Dates', 'rmd-events-organizer' ) . '</h2>';
		$html    .= '<ul class="rmd-eo-event__date-list"' . ( $ended ? '' : ' data-rmd-eo-upcoming' ) . '>';
		foreach ( $items as $occurrence ) {
			$html .= sprintf( '<li class="rmd-eo-event__date%s" data-end="%d">', $occurrence['cancelled'] ? ' is-cancelled' : ( $occurrence['withdrawn'] ? ' is-withdrawn' : '' ), (int) $occurrence['end_ts'] );
			$html .= '<span class="rmd-eo-event__when">' . esc_html( Formatter::when( $occurrence ) ) . '</span>';
			if ( $occurrence['cancelled'] ) {
				$html .= ' <span class="rmd-eo-event__cancelled">' . esc_html__( 'Cancelled', 'rmd-events-organizer' ) . '</span>';
			} elseif ( $occurrence['withdrawn'] ) {
				$html .= ' <span class="rmd-eo-event__cancelled">' . esc_html__( 'We are not taking part', 'rmd-events-organizer' ) . '</span>';
			}
			if ( '' !== $occurrence['note'] ) {
				$html .= ' <span class="rmd-eo-event__note">' . esc_html( (string) $occurrence['note'] ) . '</span>';
			}
			if ( $with_ics && ! $occurrence['cancelled'] && ! $occurrence['withdrawn'] ) {
				$html .= sprintf(
					' <a class="rmd-eo-event__ics" rel="nofollow" href="%s">%s</a>',
					esc_url( Ics::url( (int) $occurrence['id'] ) ),
					esc_html__( 'Add to calendar', 'rmd-events-organizer' )
				);
			}
			$html .= '</li>';
		}
		$html .= '</ul>';

		if ( $ended ) {
			$html .= '<p class="rmd-eo-event__ended">' . esc_html__( 'This date is in the past.', 'rmd-events-organizer' ) . '</p>';
		} elseif ( $upcoming['total'] > count( $items ) ) {
			$html .= '<p class="rmd-eo-event__more">' . esc_html(
				sprintf(
					/* translators: %d: number of further dates */
					_n( '… and %d more date.', '… and %d more dates.', $upcoming['total'] - count( $items ), 'rmd-events-organizer' ),
					$upcoming['total'] - count( $items )
				)
			) . '</p>';
		}

		return $html . '</div>';
	}

	/**
	 * @param array $market_details Details of a market date (stall), empty otherwise.
	 */
	private static function location( int $location_id, array $market_details ): string {
		$location = LocationRepository::find( $location_id );
		$stall    = (string) ( $market_details['stall'] ?? '' );
		$note     = (string) ( $market_details['stall_note'] ?? '' );
		if ( ! $location && '' === $stall && '' === $note ) {
			return '';
		}

		$html  = '<div class="rmd-eo-event__section rmd-eo-event__location">';
		$html .= '<h2 class="rmd-eo-event__heading">' . esc_html__( 'Location', 'rmd-events-organizer' ) . '</h2>';
		if ( $location ) {
			$name  = '' !== $location['url']
				? sprintf( '<a href="%s">%s</a>', esc_url( $location['url'] ), esc_html( $location['name'] ) )
				: esc_html( $location['name'] );
			$html .= '<p class="rmd-eo-event__place"><strong>' . $name . '</strong>';
			$line  = LocationRepository::one_line( $location['address'] );
			if ( '' !== $line ) {
				$html .= '<br>' . esc_html( $line );
			}
			if ( '' !== $location['address']['url'] ) {
				$html .= sprintf( '<br><a href="%s" rel="noopener">%s</a>', esc_url( $location['address']['url'] ), esc_html__( 'Website of the location', 'rmd-events-organizer' ) );
			}
			$html .= '</p>';
		}
		if ( '' !== $stall ) {
			/* translators: %s: position of the stall, e.g. "Stand 12" */
			$html .= '<p class="rmd-eo-event__stall">' . esc_html( sprintf( __( 'Our stall: %s', 'rmd-events-organizer' ), $stall ) ) . '</p>';
		}
		if ( '' !== $note ) {
			$html .= '<p class="rmd-eo-event__stall-note">' . esc_html( $note ) . '</p>';
		}
		return $html . '</div>';
	}

	private static function facts( array $details ): string {
		$rows = [];
		if ( '' !== $details['organizer_name'] || '' !== $details['organizer_url'] ) {
			$name   = '' !== $details['organizer_name'] ? $details['organizer_name'] : $details['organizer_url'];
			$rows[] = [
				__( 'Organiser', 'rmd-events-organizer' ),
				'' !== $details['organizer_url']
					? sprintf( '<a href="%s" rel="noopener">%s</a>', esc_url( $details['organizer_url'] ), esc_html( $name ) )
					: esc_html( $name ),
			];
		}
		if ( '' !== $details['price_text'] ) {
			$rows[] = [ __( 'Admission', 'rmd-events-organizer' ), esc_html( $details['price_text'] ) ];
		}
		if ( '' !== $details['info_url'] ) {
			$rows[] = [
				__( 'More information', 'rmd-events-organizer' ),
				sprintf( '<a href="%s" rel="noopener">%s</a>', esc_url( $details['info_url'] ), esc_html( (string) wp_parse_url( $details['info_url'], PHP_URL_HOST ) ) ),
			];
		}
		if ( [] === $rows ) {
			return '';
		}

		$html = '<dl class="rmd-eo-event__section rmd-eo-event__facts">';
		foreach ( $rows as [ $label, $value ] ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
		}
		return $html . '</dl>';
	}
}
