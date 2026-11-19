<?php
/**
 * Rewrites the shortcodes and blocks of Events Manager inside a text (the
 * content of a page, a template or a widget) to those of this plugin. What
 * has no counterpart stays as it is and is reported.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain\Em;

final class ContentConverter {

	/** The word that plugin replaces with its output on its own pages. */
	public const PLACEHOLDER = 'CONTENTS';

	public const ROLE_EVENTS = 'events';

	public const NOTE_PAGE       = 'page';
	public const NOTE_PAGE_WHOLE = 'page_whole';

	private const BLOCKS = [
		'events-manager/events'    => 'events_list',
		'events-manager/calendar'  => 'events_calendar',
		'events-manager/locations' => 'locations_list',
	];

	/**
	 * @param array<string, callable> $resolve   See ShortcodeMapper::map().
	 * @param string                  $page_role For the pages that plugin filled itself: "events",
	 *                                           "locations", "categories", "tags" or "bookings".
	 * @return array{content: string, changes: list<array{from: string, to: string, notes: list<array{0: string, 1: string}>}>}
	 *         "to" is empty for what could not be converted.
	 */
	public static function convert( string $content, array $resolve, string $page_role = '' ): array {
		$changes = [];

		$content = (string) preg_replace_callback(
			'#<!--\s+wp:(' . implode( '|', array_map( 'preg_quote', array_keys( self::BLOCKS ) ) ) . ')\s+(\{(?:(?!-->).)*\}\s+)?/-->#s',
			static function ( array $m ) use ( $resolve, &$changes ): string {
				$attrs = json_decode( trim( $m[2] ?? '' ), true );
				$attrs = is_array( $attrs ) ? $attrs : [];
				$atts  = [];
				foreach ( $attrs as $name => $value ) {
					if ( is_bool( $value ) ) {
						$atts[ (string) $name ] = $value ? '1' : '';
					} elseif ( is_scalar( $value ) ) {
						$atts[ (string) $name ] = (string) $value;
					}
				}
				$tag = self::BLOCKS[ $m[1] ];
				if ( 'events_calendar' === $tag ) {
					$atts['full'] = 'small' === ( $atts['calendar_size'] ?? '' ) ? '' : '1';
				}
				$has_format = '' !== trim( ( $atts['format'] ?? '' ) . ( $atts['format_header'] ?? '' ) . ( $atts['format_footer'] ?? '' ) );
				unset( $atts['format'] );

				$mapped = ShortcodeMapper::map( $tag, $atts, $has_format, $resolve );
				$to     = null === $mapped['tag'] ? '' : self::block( $mapped['tag'], $mapped['atts'], (string) ( $atts['title'] ?? '' ) );

				$changes[] = [
					'from'  => $m[0],
					'to'    => $to,
					'notes' => $mapped['notes'],
				];
				return '' === $to ? $m[0] : $to;
			},
			$content
		);

		$content = (string) preg_replace_callback(
			self::shortcode_regex(),
			static function ( array $m ) use ( $resolve, &$changes ): string {
				if ( '[' === $m[1] && ']' === ( $m[6] ?? '' ) ) {
					// [[tag]] is an escaped shortcode, shown as text.
					return $m[0];
				}
				$mapped = ShortcodeMapper::map( $m[2], self::attributes( $m[3] ), '' !== trim( $m[5] ?? '' ), $resolve );
				$to     = null === $mapped['tag'] ? '' : ShortcodeMapper::shortcode( $mapped['tag'], $mapped['atts'] );

				$changes[] = [
					'from'  => $m[0],
					'to'    => $to,
					'notes' => $mapped['notes'],
				];
				return '' === $to ? $m[0] : $m[1] . $to . ( $m[6] ?? '' );
			},
			$content
		);

		if ( '' !== $page_role ) {
			$content = self::own_page( $content, $page_role, $changes );
		}

		return [
			'content' => $content,
			'changes' => $changes,
		];
	}

	/**
	 * Block markup for one of our shortcodes: the matching block where its
	 * attributes can express everything, otherwise a shortcode block.
	 *
	 * @param array<string, string> $atts
	 */
	public static function block( string $tag, array $atts, string $title = '' ): string {
		$names  = [
			'rmd_events'          => 'rmd/events',
			'rmd_events_calendar' => 'rmd/events-calendar',
			'rmd_event'           => 'rmd/event-details',
		];
		$fields = [
			'rmd_events'          => [
				'scope'            => 'scope',
				'limit'            => 'limit',
				'category'         => 'category',
				'exclude_category' => 'excludeCategory',
				'tag'              => 'tag',
				'location'         => 'location',
				'group'            => 'group',
				'pagination'       => 'pagination',
				'empty'            => 'emptyText',
			],
			'rmd_events_calendar' => [
				'size'     => 'size',
				'month'    => 'month',
				'category' => 'category',
				'tag'      => 'tag',
				'location' => 'location',
			],
			'rmd_event'           => [
				'id' => 'eventId',
			],
		];

		$markup = '';
		if ( '' !== trim( $title ) ) {
			$markup = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . htmlspecialchars( trim( $title ), ENT_QUOTES, 'UTF-8' ) . "</h2>\n<!-- /wp:heading -->\n\n";
		}

		if ( ! isset( $fields[ $tag ] ) || [] !== array_diff_key( $atts, $fields[ $tag ] ) ) {
			return $markup . "<!-- wp:shortcode -->\n" . ShortcodeMapper::shortcode( $tag, $atts ) . "\n<!-- /wp:shortcode -->";
		}

		$attrs = [];
		foreach ( $atts as $name => $value ) {
			if ( in_array( $name, [ 'limit', 'location', 'id' ], true ) ) {
				$attrs[ $fields[ $tag ][ $name ] ] = (int) $value;
			} elseif ( 'pagination' === $name ) {
				$attrs['pagination'] = 'yes' === $value;
			} else {
				$attrs[ $fields[ $tag ][ $name ] ] = $value;
			}
		}
		$json = '';
		if ( [] !== $attrs ) {
			// The same escaping the block editor uses inside the comment.
			$json = str_replace(
				[ '--', '<', '>', '&' ],
				[ '--', '<', '>', '&' ],
				(string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This class works without WordPress.
			) . ' ';
		}
		return $markup . '<!-- wp:' . $names[ $tag ] . ' ' . $json . '/-->';
	}

	/**
	 * True when a text may contain something to convert (cheap pre-check).
	 */
	public static function concerns( string $content ): bool {
		return false !== strpos( $content, 'wp:events-manager/' ) || 1 === preg_match( self::shortcode_regex(), $content );
	}

	/**
	 * The pages that plugin created for itself: their content is the
	 * placeholder, which only that plugin replaces.
	 *
	 * @param list<array{from: string, to: string, notes: list<array{0: string, 1: string}>}> $changes
	 */
	private static function own_page( string $content, string $role, array &$changes ): string {
		$has_placeholder = 1 === preg_match( '/\b' . self::PLACEHOLDER . '\b/', $content );

		if ( self::ROLE_EVENTS !== $role ) {
			if ( $has_placeholder || '' === trim( $content ) ) {
				$changes[] = [
					'from'  => self::PLACEHOLDER,
					'to'    => '',
					'notes' => [ [ self::NOTE_PAGE, $role ] ],
				];
			}
			return $content;
		}

		// Converted already: our list is on the page.
		if ( false !== strpos( $content, '[rmd_events' ) || false !== strpos( $content, 'wp:rmd/events ' ) ) {
			return $content;
		}

		$atts      = [ 'pagination' => 'yes' ];
		$is_blocks = false !== strpos( $content, '<!-- wp:' );
		$block     = self::block( 'rmd_events', $atts );
		$shortcode = ShortcodeMapper::shortcode( 'rmd_events', $atts );

		if ( $has_placeholder ) {
			$new = (string) preg_replace( '#<!-- wp:paragraph[^>]*-->\s*<p[^>]*>\s*' . self::PLACEHOLDER . '\s*</p>\s*<!-- /wp:paragraph -->#', $block, $content, 1, $count );
			if ( 0 === $count ) {
				$new = (string) preg_replace( '/\b' . self::PLACEHOLDER . '\b/', $shortcode, $content, 1 );
			}
			$changes[] = [
				'from'  => self::PLACEHOLDER,
				'to'    => $count > 0 ? $block : $shortcode,
				'notes' => [],
			];
			return $new;
		}

		// Without the placeholder that plugin replaced the whole page.
		$to        = $is_blocks ? $block : $shortcode;
		$changes[] = [
			'from'  => '',
			'to'    => $to,
			'notes' => '' === trim( $content ) ? [] : [ [ self::NOTE_PAGE_WHOLE, '' ] ],
		];
		return '' === trim( $content ) ? $to : rtrim( $content ) . "\n\n" . $to;
	}

	/**
	 * Matches a shortcode with its attributes and, if it has a closing tag,
	 * the enclosed text. Groups: 1 and 6 extra brackets, 2 tag, 3 attributes,
	 * 5 enclosed text.
	 */
	private static function shortcode_regex(): string {
		$tags = ShortcodeMapper::TAGS;
		usort( $tags, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$tags = implode( '|', array_map( 'preg_quote', $tags ) );

		return '/\[(\[?)(' . $tags . ')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)/';
	}

	/**
	 * @return array<string, string> Attribute names in lower case.
	 */
	private static function attributes( string $text ): array {
		$atts = [];
		// Editors turn quotes into typographic ones now and then.
		$text = str_replace( [ '“', '”', '„', '″' ], '"', $text );
		if ( preg_match_all( '/([\w-]+)\s*=\s*"([^"]*)"|([\w-]+)\s*=\s*\'([^\']*)\'|([\w-]+)\s*=\s*([^\s\'"]+)/', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				if ( '' !== $m[1] ) {
					$atts[ strtolower( $m[1] ) ] = $m[2];
				} elseif ( '' !== ( $m[3] ?? '' ) ) {
					$atts[ strtolower( $m[3] ) ] = $m[4];
				} elseif ( '' !== ( $m[5] ?? '' ) ) {
					$atts[ strtolower( $m[5] ) ] = $m[6];
				}
			}
		}
		return $atts;
	}
}
