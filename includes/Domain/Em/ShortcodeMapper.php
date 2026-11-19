<?php
/**
 * Translates one shortcode of Events Manager into a shortcode of this plugin.
 * Only the meaning is carried over (which dates, how many, which filter); the
 * output formats of that plugin have no counterpart here.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain\Em;

final class ShortcodeMapper {

	/** Every shortcode of that plugin, with or without a counterpart here. */
	public const TAGS = [
		'events_list_grouped',
		'events_list',
		'events_calendar',
		'events_page',
		'events_map',
		'events_gcal',
		'events_link',
		'events_url',
		'events_rss_link',
		'events_rss_url',
		'events_search',
		'event_search_form',
		'event_category',
		'event_form',
		'event_tag',
		'event',
		'locations_list',
		'locations_map',
		'locations-map',
		'locations_search',
		'location_search_form',
		'location',
		'categories_list',
		'tags_list',
		'my_bookings',
		'em_withdrawal_form',
	];

	public const NOTE_NONE      = 'none';
	public const NOTE_FORMAT    = 'format';
	public const NOTE_SCOPE     = 'scope';
	public const NOTE_FILTER    = 'filter';
	public const NOTE_ATTRIBUTE = 'attribute';
	public const NOTE_EVENT     = 'event';

	/** Attributes that only concern the look or the technique of the old output. */
	private const IGNORED = [
		'orderby',
		'full',
		'long_events',
		'ajax',
		'view',
		'id',
		'title',
		'format',
		'format_header',
		'format_footer',
		'header_format',
		'date_format',
		'nolistwrap',
		'page',
		'page_queryvar',
		'calendar_size',
		'calendar_event_style',
		'event_archetype',
		'recurring',
		'recurrences',
		'has_search',
		'no_results_msg',
		'all_events',
		'all_events_text',
		'v6',
	];

	private const SCOPES = [
		'future'     => 'upcoming',
		'past'       => 'past',
		'all'        => 'all',
		'today'      => 'today',
		'tomorrow'   => 'tomorrow',
		'month'      => 'month',
		'this-month' => 'month',
		'next-month' => 'next-month',
	];

	/**
	 * @param array<string, string>   $atts       Attributes as written, keys in lower case.
	 * @param bool                    $has_format True when the shortcode encloses an output format of its own.
	 * @param array<string, callable> $resolve    Lookups from old to new: "category" and "tag"
	 *                                            (id or slug => slug or ''), "location",
	 *                                            "event" and "event_post" (id => id or 0).
	 * @return array{tag: string|null, atts: array<string, string>, notes: list<array{0: string, 1: string}>}
	 */
	public static function map( string $tag, array $atts, bool $has_format, array $resolve ): array {
		$notes = [];
		$out   = [];

		if ( $has_format || '' !== trim( (string) ( $atts['format'] ?? '' ) ) ) {
			$notes[] = [ self::NOTE_FORMAT, '' ];
		}

		switch ( $tag ) {
			case 'events_list':
			case 'events_list_grouped':
			case 'events_page':
				$target = 'rmd_events';
				$out    = self::scope( $atts, $notes );
				if ( (int) ( $atts['limit'] ?? 0 ) > 0 ) {
					$out['limit'] = (string) (int) $atts['limit'];
				}
				$out += self::filters( $atts, $resolve, $notes );
				if ( in_array( strtolower( (string) ( $atts['order'] ?? '' ) ), [ 'asc', 'desc' ], true ) ) {
					$out['order'] = strtolower( $atts['order'] );
					// The order this plugin uses anyway needs no attribute.
					if ( ( 'past' === ( $out['scope'] ?? '' ) ? 'desc' : 'asc' ) === $out['order'] ) {
						unset( $out['order'] );
					}
				}
				if ( 'events_list_grouped' === $tag ) {
					$mode         = strtolower( (string) ( $atts['mode'] ?? 'monthly' ) );
					$out['group'] = 'yearly' === $mode ? 'year' : 'month';
					if ( ! in_array( $mode, [ 'yearly', 'monthly' ], true ) ) {
						$notes[] = [ self::NOTE_ATTRIBUTE, 'mode="' . $mode . '"' ];
					}
				}
				if ( 'events_page' === $tag || self::truthy( (string) ( $atts['pagination'] ?? '' ) ) ) {
					$out['pagination'] = 'yes';
				}
				if ( '' !== trim( (string) ( $atts['no_events_text'] ?? '' ) ) ) {
					$out['empty'] = trim( strip_tags( $atts['no_events_text'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This class works without WordPress.
				}
				$handled = [ 'scope', 'limit', 'category', 'tag', 'location', 'order', 'mode', 'pagination', 'no_events_text', 'class' ];
				break;

			case 'events_calendar':
				$target      = 'rmd_events_calendar';
				$out['size'] = self::truthy( (string) ( $atts['full'] ?? '' ) ) ? 'full' : 'mini';
				$month       = (int) ( $atts['month'] ?? 0 );
				$year        = (int) ( $atts['year'] ?? 0 );
				if ( $month >= 1 && $month <= 12 && $year >= 1970 && $year <= 2200 ) {
					$out['month'] = sprintf( '%04d-%02d', $year, $month );
				}
				$out    += self::filters( $atts, $resolve, $notes );
				$handled = [ 'month', 'year', 'category', 'tag', 'location', 'class', 'scope', 'limit' ];
				break;

			case 'event':
				$target = 'rmd_event';
				$id     = 0;
				if ( is_numeric( $atts['event'] ?? '' ) ) {
					$id = (int) ( $resolve['event'] )( (int) $atts['event'] );
				} elseif ( is_numeric( $atts['post_id'] ?? '' ) ) {
					$id = (int) ( $resolve['event_post'] )( (int) $atts['post_id'] );
				}
				if ( $id <= 0 ) {
					return [
						'tag'   => null,
						'atts'  => [],
						'notes' => [ [ self::NOTE_EVENT, '' ] ],
					];
				}
				$out['id'] = (string) $id;
				$handled   = [ 'event', 'post_id', 'class' ];
				break;

			default:
				return [
					'tag'   => null,
					'atts'  => [],
					'notes' => [ [ self::NOTE_NONE, '' ] ],
				];
		}

		if ( '' !== trim( (string) ( $atts['class'] ?? '' ) ) ) {
			$out['class'] = trim( $atts['class'] );
		}
		foreach ( array_keys( $atts ) as $name ) {
			if ( ! is_string( $name ) || in_array( $name, $handled, true ) || in_array( $name, self::IGNORED, true ) ) {
				continue;
			}
			$notes[] = [ self::NOTE_ATTRIBUTE, $name ];
		}

		return [
			'tag'   => $target,
			'atts'  => $out,
			'notes' => $notes,
		];
	}

	/**
	 * @param array<string, string> $atts
	 */
	public static function shortcode( string $tag, array $atts ): string {
		$text = '[' . $tag;
		foreach ( $atts as $name => $value ) {
			// Brackets and quotes would end the shortcode early.
			$text .= ' ' . $name . '="' . str_replace( [ '"', '[', ']' ], [ '', '', '' ], $value ) . '"';
		}
		return $text . ']';
	}

	/**
	 * @param array<string, string>               $atts
	 * @param list<array{0: string, 1: string}> $notes
	 * @return array<string, string>
	 */
	private static function scope( array $atts, array &$notes ): array {
		$scope = strtolower( trim( (string) ( $atts['scope'] ?? '' ) ) );
		if ( '' === $scope || 'future' === $scope ) {
			return [];
		}
		if ( isset( self::SCOPES[ $scope ] ) ) {
			return [ 'scope' => self::SCOPES[ $scope ] ];
		}
		if ( preg_match( '/^(\d{4}-\d{1,2}-\d{1,2})?,(\d{4}-\d{1,2}-\d{1,2})?$/', $scope, $m ) && ( '' !== ( $m[1] ?? '' ) || '' !== ( $m[2] ?? '' ) ) ) {
			return array_filter(
				[
					'from' => self::date( $m[1] ?? '' ),
					'to'   => self::date( $m[2] ?? '' ),
				],
				'strlen'
			);
		}
		if ( preg_match( '/^\d{4}-\d{1,2}-\d{1,2}$/', $scope ) ) {
			return [
				'from' => self::date( $scope ),
				'to'   => self::date( $scope ),
			];
		}
		$notes[] = [ self::NOTE_SCOPE, $scope ];
		return [];
	}

	/**
	 * @param array<string, string>               $atts
	 * @param array<string, callable>             $resolve
	 * @param list<array{0: string, 1: string}> $notes
	 * @return array<string, string>
	 */
	private static function filters( array $atts, array $resolve, array &$notes ): array {
		$out = [];
		foreach ( [ 'category', 'tag' ] as $name ) {
			$include = [];
			$exclude = [];
			foreach ( preg_split( '/[,&]/', (string) ( $atts[ $name ] ?? '' ) ) ?: [] as $item ) {
				$item = trim( $item );
				if ( '' === $item || '0' === $item ) {
					continue;
				}
				$negative = '-' === $item[0];
				$slug     = (string) ( $resolve[ $name ] )( ltrim( $item, '-' ) );
				if ( '' === $slug || ( $negative && 'tag' === $name ) ) {
					$notes[] = [ self::NOTE_FILTER, $name . '="' . $item . '"' ];
				} elseif ( $negative ) {
					$exclude[] = $slug;
				} else {
					$include[] = $slug;
				}
			}
			if ( [] !== $include ) {
				$out[ $name ] = implode( ',', array_unique( $include ) );
			}
			if ( [] !== $exclude ) {
				$out['exclude_category'] = implode( ',', array_unique( $exclude ) );
			}
		}

		$location = trim( (string) ( $atts['location'] ?? '' ) );
		if ( '' !== $location && '0' !== $location ) {
			$id = is_numeric( $location ) ? (int) ( $resolve['location'] )( (int) $location ) : 0;
			if ( $id > 0 ) {
				$out['location'] = (string) $id;
			} else {
				// A list of several locations cannot be expressed; better all dates than none.
				$notes[] = [ self::NOTE_FILTER, 'location="' . $location . '"' ];
			}
		}
		return $out;
	}

	private static function date( string $value ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
	}

	private static function truthy( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), [ '1', 'yes', 'true', 'on' ], true );
	}
}
