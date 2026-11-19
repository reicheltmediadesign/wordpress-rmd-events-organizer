<?php
/**
 * Renders a month as a calendar: "full" lists the events in each day, "mini"
 * only marks the days that have events (for sidebars).
 *
 * The markup works without JavaScript: month navigation and marked days are
 * plain links. The front-end script swaps months in place and opens the day
 * list of the small calendar as a popover.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Render;

use RMD\EventsOrganizer\Assets;
use RMD\EventsOrganizer\Clock;
use RMD\EventsOrganizer\Domain\Dates;
use RMD\EventsOrganizer\Domain\MonthGrid;
use RMD\EventsOrganizer\Modules;
use RMD\EventsOrganizer\Polylang;
use RMD\EventsOrganizer\Query\OccurrenceQuery;
use RMD\EventsOrganizer\Rest\CalendarController;
use RMD\EventsOrganizer\Settings;
use RMD\EventsOrganizer\Taxonomies;

defined( 'ABSPATH' ) || exit;

final class CalendarRenderer {

	public const MONTH_VAR = 'eo_month';
	public const SIZES     = [ 'full', 'mini' ];

	/** Filters that travel with the month navigation. */
	public const FILTERS = [ 'kind', 'category', 'tag', 'location' ];

	/**
	 * @param array $args size, month ("YYYY-MM"), kind, category, tag, location, lang, class.
	 */
	public static function render( array $args ): string {
		if ( ! Modules::is_on( Modules::CALENDAR ) ) {
			return '';
		}
		Assets::enqueue_front();

		$size    = in_array( $args['size'] ?? '', self::SIZES, true ) ? (string) $args['size'] : 'full';
		$filters = [];
		foreach ( self::FILTERS as $filter ) {
			if ( ! empty( $args[ $filter ] ) ) {
				$filters[ $filter ] = is_array( $args[ $filter ] ) ? implode( ',', $args[ $filter ] ) : (string) $args[ $filter ];
			}
		}

		// The language travels with the request for another month, which has no page to take it from.
		$language = Polylang::language( (string) ( $args['lang'] ?? '' ) );
		if ( '' !== $language ) {
			$filters['lang'] = $language;
		}

		$bounds = OccurrenceQuery::month_bounds( (string) ( $filters['kind'] ?? '' ) );
		$today  = Clock::today();
		$month  = self::month( $args, $today, $bounds );
		$grid   = MonthGrid::build( $month, (int) get_option( 'start_of_week', 1 ) );
		$found  = OccurrenceQuery::find(
			[
				'from'  => $grid['from'],
				'to'    => $grid['to'],
				'order' => 'asc',
			] + $filters
		);
		$days   = MonthGrid::by_day( $found['items'], $grid['from'], $grid['to'] );

		$html  = self::navigation( $month, $bounds );
		$html .= '<table class="rmd-eo-calendar__grid"><thead><tr>';
		foreach ( $grid['weekdays'] as $weekday ) {
			$html .= sprintf(
				'<th scope="col" abbr="%s">%s</th>',
				esc_attr( self::weekday_name( $weekday, false ) ),
				esc_html( self::weekday_name( $weekday, true ) )
			);
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $grid['weeks'] as $week ) {
			$html .= '<tr>';
			foreach ( $week as $day ) {
				$html .= self::day( $day, $days[ $day['date'] ] ?? [], $size, $today );
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		return sprintf(
			'<div class="%s" data-rmd-eo-calendar data-endpoint="%s" data-query="%s" data-month="%s" data-today="%s">%s</div>',
			esc_attr( trim( 'rmd-eo rmd-eo-calendar rmd-eo-calendar--' . $size . ' ' . ( $args['class'] ?? '' ) ) ),
			esc_url( rest_url( CalendarController::NAMESPACE . '/calendar' ) ),
			esc_attr( (string) wp_json_encode( [ 'size' => $size ] + $filters ) ),
			esc_attr( $month ),
			esc_attr( $today ),
			$html
		);
	}

	/**
	 * The requested month, limited to the months that have events so that
	 * crawlers cannot walk through the centuries.
	 *
	 * @param array{0: string, 1: string}|null $bounds
	 */
	private static function month( array $args, string $today, ?array $bounds ): string {
		$current = substr( $today, 0, 7 );
		$month   = (string) ( $args['month'] ?? '' );
		if ( '' === $month && isset( $_GET[ self::MONTH_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
			$month = sanitize_text_field( wp_unslash( $_GET[ self::MONTH_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! Dates::is_valid_month( $month ) ) {
			return $current;
		}
		$first = $bounds ? min( $bounds[0], $current ) : $current;
		$last  = $bounds ? max( $bounds[1], $current ) : $current;
		return max( $first, min( $last, $month ) );
	}

	/**
	 * @param array{0: string, 1: string}|null $bounds
	 */
	private static function navigation( string $month, ?array $bounds ): string {
		$current  = substr( Clock::today(), 0, 7 );
		$first    = $bounds ? min( $bounds[0], $current ) : $current;
		$last     = $bounds ? max( $bounds[1], $current ) : $current;
		$previous = Dates::add_months( $month, -1 );
		$next     = Dates::add_months( $month, 1 );

		$html  = '<div class="rmd-eo-calendar__nav">';
		$html .= $previous >= $first
			? sprintf(
				'<a class="rmd-eo-calendar__prev" rel="nofollow" data-month="%s" href="%s" aria-label="%s">‹</a>',
				esc_attr( $previous ),
				esc_url( add_query_arg( self::MONTH_VAR, $previous ) ),
				esc_attr__( 'Previous month', 'rmd-events-organizer' )
			)
			: '<span class="rmd-eo-calendar__prev" aria-hidden="true"></span>';
		$html .= '<span class="rmd-eo-calendar__title" aria-live="polite">' . esc_html( Formatter::date( $month . '-01', 'F Y' ) ) . '</span>';
		$html .= $next <= $last
			? sprintf(
				'<a class="rmd-eo-calendar__next" rel="nofollow" data-month="%s" href="%s" aria-label="%s">›</a>',
				esc_attr( $next ),
				esc_url( add_query_arg( self::MONTH_VAR, $next ) ),
				esc_attr__( 'Next month', 'rmd-events-organizer' )
			)
			: '<span class="rmd-eo-calendar__next" aria-hidden="true"></span>';
		return $html . '</div>';
	}

	/**
	 * @param array{date: string, in_month: bool} $day
	 * @param list<array<string, mixed>>          $occurrences
	 */
	private static function day( array $day, array $occurrences, string $size, string $today ): string {
		$classes = [ 'rmd-eo-day' ];
		if ( ! $day['in_month'] ) {
			$classes[] = 'is-other-month';
		}
		if ( [] !== $occurrences ) {
			$classes[] = 'has-events';
		}
		if ( $day['date'] === $today ) {
			$classes[] = 'is-today';
		}

		$number = (string) (int) substr( $day['date'], 8, 2 );
		$html   = sprintf( '<td class="%s" data-date="%s">', esc_attr( implode( ' ', $classes ) ), esc_attr( $day['date'] ) );

		if ( [] === $occurrences ) {
			return $html . '<span class="rmd-eo-day__number">' . esc_html( $number ) . '</span></td>';
		}

		if ( 'mini' === $size ) {
			$label = sprintf(
				/* translators: 1: date, 2: number of events */
				_n( '%1$s: %2$d date', '%1$s: %2$d dates', count( $occurrences ), 'rmd-events-organizer' ),
				Formatter::date( $day['date'] ),
				count( $occurrences )
			);
			$url = self::day_url( $day['date'], $occurrences );
			if ( '' !== $url ) {
				$html .= sprintf( '<a class="rmd-eo-day__number rmd-eo-day__link" href="%s" aria-label="%s">%s</a>', esc_url( $url ), esc_attr( $label ), esc_html( $number ) );
			} else {
				$html .= sprintf( '<button type="button" class="rmd-eo-day__number rmd-eo-day__link" aria-label="%s">%s</button>', esc_attr( $label ), esc_html( $number ) );
			}
			$html .= '<div class="rmd-eo-day__popover" hidden><p class="rmd-eo-day__popover-title">' . esc_html( Formatter::date( $day['date'] ) ) . '</p>';
			$html .= self::events( $occurrences ) . '</div>';
			return $html . '</td>';
		}

		$html .= '<span class="rmd-eo-day__number">' . esc_html( $number ) . '</span>';
		return $html . self::events( $occurrences ) . '</td>';
	}

	/**
	 * @param list<array<string, mixed>> $occurrences
	 */
	private static function events( array $occurrences ): string {
		$html = '<ul class="rmd-eo-day__events">';
		foreach ( $occurrences as $occurrence ) {
			$color = '';
			$terms = get_the_terms( (int) $occurrence['event_id'], Taxonomies::CATEGORY );
			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				$color = Taxonomies::color( (int) $term->term_id );
				if ( '' !== $color ) {
					break;
				}
			}
			$time  = Formatter::times( [ 'end_time' => '' ] + $occurrence );
			$html .= sprintf(
				'<li class="rmd-eo-day__event rmd-eo-day__event--%s%s"%s><a href="%s">%s%s</a></li>',
				esc_attr( sanitize_html_class( (string) $occurrence['kind'] ) ),
				$occurrence['cancelled'] ? ' is-cancelled' : ( ! empty( $occurrence['withdrawn'] ) ? ' is-withdrawn' : '' ),
				'' !== $color ? ' style="--rmd-eo-event-color:' . esc_attr( $color ) . '"' : '',
				esc_url( (string) get_permalink( (int) $occurrence['event_id'] ) ),
				'' !== $time ? '<span class="rmd-eo-day__time">' . esc_html( $time ) . '</span> ' : '',
				esc_html( get_the_title( (int) $occurrence['event_id'] ) )
			);
		}
		return $html . '</ul>';
	}

	/**
	 * Where a marked day of the small calendar leads without JavaScript: the
	 * events page filtered to that day, or the event itself when the day has
	 * exactly one.
	 *
	 * @param list<array<string, mixed>> $occurrences
	 */
	private static function day_url( string $date, array $occurrences ): string {
		$page_id = (int) ( ( (array) Settings::get( 'pages', [] ) )['events'] ?? 0 );
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			return add_query_arg( ListRenderer::DAY_VAR, $date, (string) get_permalink( $page_id ) );
		}
		$event_ids = array_unique( array_column( $occurrences, 'event_id' ) );
		return 1 === count( $event_ids ) ? (string) get_permalink( (int) $event_ids[0] ) : '';
	}

	private static function weekday_name( string $weekday, bool $short ): string {
		global $wp_locale;
		$index = ( (int) array_search( $weekday, Dates::WEEKDAYS, true ) + 1 ) % 7;
		$name  = $wp_locale->get_weekday( $index );
		return $short ? $wp_locale->get_weekday_abbrev( $name ) : $name;
	}
}
