<?php
/**
 * Renders a list of occurrences. Used by the events block, the market dates
 * block and their shortcodes.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Render;

use RMD\EventsOrganizer\Assets;
use RMD\EventsOrganizer\Domain\Dates;
use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Query\OccurrenceQuery;
use RMD\EventsOrganizer\Repository\EventRepository;
use RMD\EventsOrganizer\Repository\LocationRepository;
use RMD\EventsOrganizer\Settings;
use RMD\EventsOrganizer\Taxonomies;

defined( 'ABSPATH' ) || exit;

final class ListRenderer {

	public const PARTS         = [ 'date', 'time', 'location', 'stall', 'excerpt', 'image', 'category' ];
	public const DEFAULT_PARTS = [ 'date', 'time', 'location' ];
	public const GROUPS        = [ 'month', 'year' ];

	public const DAY_VAR  = 'eo_day';
	public const PAGE_VAR = 'eo_page';

	/**
	 * @param array $args Query arguments of OccurrenceQuery::find() plus:
	 *                    show (list of self::PARTS), group, pagination (bool),
	 *                    empty_text, class, heading_level (2-6).
	 */
	public static function render( array $args ): string {
		Assets::enqueue_front();

		$show    = self::parts( $args['show'] ?? null, $args['kind'] ?? '' );
		$group   = in_array( $args['group'] ?? '', self::GROUPS, true ) ? (string) $args['group'] : '';
		$level   = max( 2, min( 6, (int) ( $args['heading_level'] ?? 3 ) ) );
		$limit   = (int) ( $args['limit'] ?? 0 );
		$limit   = $limit > 0 ? $limit : (int) ( ( (array) Settings::get( 'display', [] ) )['list_limit'] ?? 10 );
		$paged   = ! empty( $args['pagination'] );
		$page    = $paged ? max( 1, absint( $_GET[ self::PAGE_VAR ] ?? 1 ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging.
		$day     = self::requested_day( $args );
		$heading = '';

		$query = [
			'limit'  => $limit,
			'offset' => ( $page - 1 ) * $limit,
		] + $args;

		if ( '' !== $day ) {
			$query['from']   = $day;
			$query['to']     = $day;
			$query['limit']  = 0;
			$query['offset'] = 0;
			$query['unique'] = false;
			$paged           = false;
			$heading         = sprintf(
				'<p class="rmd-eo-list__day"><strong>%s</strong> <a href="%s">%s</a></p>',
				esc_html( Formatter::date( $day ) ),
				esc_url( remove_query_arg( self::DAY_VAR ) ),
				esc_html__( 'Show all dates', 'rmd-events-organizer' )
			);
		}

		$result = OccurrenceQuery::find( $query );
		$class  = trim( 'rmd-eo rmd-eo-list ' . ( $args['class'] ?? '' ) );

		if ( [] === $result['items'] ) {
			$text = '' !== (string) ( $args['empty_text'] ?? '' ) ? (string) $args['empty_text'] : __( 'There are no dates at the moment.', 'rmd-events-organizer' );
			return sprintf( '<div class="%s">%s<p class="rmd-eo-list__empty">%s</p></div>', esc_attr( $class ), $heading, esc_html( $text ) );
		}

		$html    = '';
		$current = null;
		$open    = false;
		foreach ( $result['items'] as $occurrence ) {
			$label = self::group_label( $occurrence, $group );
			if ( $label !== $current ) {
				if ( $open ) {
					$html .= '</ul>';
				}
				if ( '' !== $label ) {
					$html .= sprintf( '<h%1$d class="rmd-eo-list__group">%2$s</h%1$d>', $level, esc_html( $label ) );
				}
				$html   .= '<ul class="rmd-eo-list__items">';
				$open    = true;
				$current = $label;
			}
			$html .= self::item( $occurrence, $show, '' !== $label ? $level + 1 : $level );
		}
		$html .= '</ul>';

		if ( $paged ) {
			$html .= self::pagination( $page, $limit, $result['total'] );
		}

		// Cached pages keep showing dates that are over; the script hides them.
		$upcoming = '' === $day && empty( $args['from'] ) && empty( $args['to'] ) && in_array( $args['scope'] ?? 'upcoming', [ '', 'upcoming' ], true );

		return sprintf( '<div class="%s"%s>%s%s</div>', esc_attr( $class ), $upcoming ? ' data-rmd-eo-upcoming' : '', $heading, $html );
	}

	/**
	 * @param array<string, mixed> $occurrence
	 * @param list<string>         $show
	 */
	public static function item( array $occurrence, array $show, int $level = 3 ): string {
		$event_id = (int) $occurrence['event_id'];
		$classes  = [ 'rmd-eo-item', 'rmd-eo-item--' . sanitize_html_class( (string) $occurrence['kind'] ) ];
		if ( $occurrence['cancelled'] ) {
			$classes[] = 'is-cancelled';
		}

		$color = '';
		$terms = get_the_terms( $event_id, Taxonomies::CATEGORY );
		$terms = is_array( $terms ) ? $terms : [];
		foreach ( $terms as $term ) {
			$color = Taxonomies::color( (int) $term->term_id );
			if ( '' !== $color ) {
				break;
			}
		}

		$html = sprintf(
			'<li class="%s" data-end="%d"%s>',
			esc_attr( implode( ' ', $classes ) ),
			(int) $occurrence['end_ts'],
			'' !== $color ? ' style="--rmd-eo-event-color:' . esc_attr( $color ) . '"' : ''
		);

		if ( in_array( 'date', $show, true ) ) {
			$html .= sprintf(
				'<div class="rmd-eo-item__badge" aria-hidden="true"><span class="rmd-eo-item__badge-day">%s</span><span class="rmd-eo-item__badge-month">%s</span></div>',
				esc_html( Formatter::date( (string) $occurrence['start_date'], 'j' ) ),
				esc_html( Formatter::date( (string) $occurrence['start_date'], 'M' ) )
			);
		}

		$html .= '<div class="rmd-eo-item__body">';
		$html .= sprintf(
			'<h%1$d class="rmd-eo-item__title"><a href="%2$s">%3$s</a></h%1$d>',
			$level,
			esc_url( (string) get_permalink( $event_id ) ),
			esc_html( get_the_title( $event_id ) )
		);

		if ( $occurrence['cancelled'] ) {
			$html .= '<p class="rmd-eo-item__status">' . esc_html__( 'Cancelled', 'rmd-events-organizer' ) . '</p>';
		}

		$when = in_array( 'time', $show, true ) ? Formatter::when( $occurrence ) : Formatter::dates( $occurrence );
		if ( in_array( 'date', $show, true ) || in_array( 'time', $show, true ) ) {
			$html .= '<p class="rmd-eo-item__when">' . esc_html( $when ) . '</p>';
		}
		if ( '' !== $occurrence['note'] ) {
			$html .= '<p class="rmd-eo-item__note">' . esc_html( (string) $occurrence['note'] ) . '</p>';
		}

		$where = [];
		if ( in_array( 'location', $show, true ) ) {
			$location = LocationRepository::find( (int) $occurrence['location_id'] );
			if ( $location ) {
				$where[] = implode( ', ', array_filter( [ $location['name'], (string) $location['address']['city'] ], 'strlen' ) );
			}
		}
		if ( in_array( 'stall', $show, true ) && Kinds::MARKET === $occurrence['kind'] ) {
			$stall = (string) EventRepository::details( $event_id )['stall'];
			if ( '' !== $stall ) {
				$where[] = $stall;
			}
		}
		if ( [] !== $where ) {
			$html .= '<p class="rmd-eo-item__where">' . esc_html( implode( ' · ', $where ) ) . '</p>';
		}

		if ( in_array( 'category', $show, true ) && [] !== $terms ) {
			$html .= '<p class="rmd-eo-item__categories">' . esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) . '</p>';
		}
		if ( in_array( 'excerpt', $show, true ) ) {
			$excerpt = get_the_excerpt( $event_id );
			if ( '' !== $excerpt ) {
				$html .= '<p class="rmd-eo-item__excerpt">' . esc_html( $excerpt ) . '</p>';
			}
		}
		$html .= '</div>';

		if ( in_array( 'image', $show, true ) && has_post_thumbnail( $event_id ) ) {
			$html .= '<div class="rmd-eo-item__image">' . get_the_post_thumbnail( $event_id, 'medium' ) . '</div>';
		}

		return $html . '</li>';
	}

	/**
	 * @param mixed  $value Comma separated string or list.
	 * @param string $kind
	 * @return list<string>
	 */
	public static function parts( $value, string $kind = '' ): array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$items = array_values( array_intersect( self::PARTS, array_map( 'trim', array_map( 'strval', $items ) ) ) );
		if ( [] !== $items ) {
			return $items;
		}
		return Kinds::MARKET === $kind ? array_merge( self::DEFAULT_PARTS, [ 'stall' ] ) : self::DEFAULT_PARTS;
	}

	/**
	 * A day picked in the small calendar narrows lists that are not already
	 * fixed to a date range.
	 */
	private static function requested_day( array $args ): string {
		if ( ! empty( $args['from'] ) || ! empty( $args['to'] ) || ! empty( $args['event_id'] ) || ( isset( $args['follow_day'] ) && ! $args['follow_day'] ) ) {
			return '';
		}
		$day = isset( $_GET[ self::DAY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::DAY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		return Dates::is_valid( $day ) ? $day : '';
	}

	/**
	 * @param array<string, mixed> $occurrence
	 */
	private static function group_label( array $occurrence, string $group ): string {
		if ( 'month' === $group ) {
			return Formatter::date( (string) $occurrence['start_date'], 'F Y' );
		}
		if ( 'year' === $group ) {
			return Formatter::date( (string) $occurrence['start_date'], 'Y' );
		}
		return '';
	}

	private static function pagination( int $page, int $limit, int $total ): string {
		$pages = (int) ceil( $total / max( 1, $limit ) );
		if ( $pages <= 1 ) {
			return '';
		}
		$html = '<nav class="rmd-eo-list__pagination" aria-label="' . esc_attr__( 'Event pages', 'rmd-events-organizer' ) . '">';
		if ( $page > 1 ) {
			$html .= sprintf( '<a rel="nofollow" class="rmd-eo-list__prev" href="%s">%s</a>', esc_url( add_query_arg( self::PAGE_VAR, $page - 1 ) ), esc_html__( 'Earlier dates', 'rmd-events-organizer' ) );
		}
		if ( $page < $pages ) {
			$html .= sprintf( '<a rel="nofollow" class="rmd-eo-list__next" href="%s">%s</a>', esc_url( add_query_arg( self::PAGE_VAR, $page + 1 ) ), esc_html__( 'Later dates', 'rmd-events-organizer' ) );
		}
		return $html . '</nav>';
	}
}
