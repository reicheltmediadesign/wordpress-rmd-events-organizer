<?php
/**
 * Replaces the widgets of Events Manager with block widgets showing the list
 * or the small calendar of this plugin, and rewrites its shortcodes inside
 * block and text widgets. Everything changed is remembered and can be put back.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Migration;

use RMD\EventsOrganizer\Domain\Em\ContentConverter;
use RMD\EventsOrganizer\Domain\Em\ShortcodeMapper;

defined( 'ABSPATH' ) || exit;

final class WidgetRewriter {

	/** Option: what was replaced, for putting it back. */
	public const BACKUP = 'rmd_eo_em_widget_backup';

	/** Widget types of that plugin => shortcode they correspond to. */
	private const LEGACY = [
		'em_widget'           => 'events_list',
		'em_calendar'         => 'events_calendar',
		'em_locations_widget' => 'locations_list',
	];

	/** Widget types with free content => key of the content in their settings. */
	private const TEXT = [
		'block' => 'content',
		'text'  => 'text',
	];

	/**
	 * @return list<array{id: string, area: string, from: string, to: string, notes: list<array{0: string, 1: string}>}>
	 */
	public static function scan(): array {
		$rows = [];
		foreach ( self::placed() as $widget_id => $area ) {
			[ $base, $number ] = self::split( $widget_id );

			if ( isset( self::LEGACY[ $base ] ) ) {
				$mapped = self::map_legacy( $base, self::instance( $base, $number ) );
				$rows[] = [
					'id'    => $widget_id,
					'area'  => $area,
					'from'  => $widget_id,
					'to'    => null === $mapped['tag'] ? '' : ShortcodeMapper::shortcode( $mapped['tag'], $mapped['atts'] ),
					'notes' => $mapped['notes'],
				];
			} elseif ( isset( self::TEXT[ $base ] ) ) {
				$text = (string) ( self::instance( $base, $number )[ self::TEXT[ $base ] ] ?? '' );
				foreach ( ContentConverter::convert( $text, Targets::resolvers() )['changes'] as $change ) {
					$rows[] = [
						'id'    => $widget_id,
						'area'  => $area,
						'from'  => $change['from'],
						'to'    => $change['to'],
						'notes' => $change['notes'],
					];
				}
			}
		}
		return $rows;
	}

	/**
	 * @return int Number of widgets changed.
	 */
	public static function apply_all(): int {
		$backup   = self::backup();
		$sidebars = self::sidebars();
		$count    = 0;

		foreach ( self::placed() as $widget_id => $area ) {
			[ $base, $number ] = self::split( $widget_id );

			if ( isset( self::LEGACY[ $base ] ) ) {
				$instance = self::instance( $base, $number );
				$mapped   = self::map_legacy( $base, $instance );
				if ( null === $mapped['tag'] ) {
					continue;
				}
				$blocks = get_option( 'widget_block', [] );
				$blocks = is_array( $blocks ) ? $blocks : [];
				$next   = max( array_merge( [ 1 ], array_filter( array_keys( $blocks ), 'is_int' ) ) ) + 1;

				$blocks[ $next ]        = [ 'content' => ContentConverter::block( $mapped['tag'], $mapped['atts'], (string) ( $instance['title'] ?? '' ) ) ];
				$blocks['_multiwidget'] = 1;
				update_option( 'widget_block', $blocks );

				foreach ( $sidebars as $sidebar => $widgets ) {
					if ( is_array( $widgets ) ) {
						$sidebars[ $sidebar ] = array_map( static fn( $id ) => $id === $widget_id ? 'block-' . $next : $id, $widgets );
					}
				}
				$backup['replaced'][ 'block-' . $next ] = $widget_id;
				++$count;
			} elseif ( isset( self::TEXT[ $base ] ) ) {
				$key       = self::TEXT[ $base ];
				$instances = get_option( 'widget_' . $base, [] );
				$text      = (string) ( $instances[ $number ][ $key ] ?? '' );
				$new       = ContentConverter::convert( $text, Targets::resolvers() )['content'];
				if ( $new === $text ) {
					continue;
				}
				if ( ! isset( $backup['contents'][ $widget_id ] ) ) {
					$backup['contents'][ $widget_id ] = $text;
				}
				$instances[ $number ][ $key ] = $new;
				update_option( 'widget_' . $base, $instances );
				++$count;
			}
		}

		if ( $count > 0 ) {
			update_option( 'sidebars_widgets', $sidebars );
			update_option( self::BACKUP, $backup, false );
		}
		return $count;
	}

	/**
	 * @return int Number of widgets put back.
	 */
	public static function undo_all(): int {
		$backup   = self::backup();
		$sidebars = self::sidebars();
		$count    = 0;

		foreach ( $backup['replaced'] as $new_id => $old_id ) {
			foreach ( $sidebars as $sidebar => $widgets ) {
				if ( is_array( $widgets ) ) {
					$sidebars[ $sidebar ] = array_map( static fn( $id ) => $id === $new_id ? $old_id : $id, $widgets );
				}
			}
			$blocks = get_option( 'widget_block', [] );
			unset( $blocks[ self::split( $new_id )[1] ] );
			update_option( 'widget_block', $blocks );
			++$count;
		}
		foreach ( $backup['contents'] as $widget_id => $text ) {
			[ $base, $number ] = self::split( $widget_id );
			$instances         = get_option( 'widget_' . $base, [] );
			if ( isset( self::TEXT[ $base ], $instances[ $number ] ) ) {
				$instances[ $number ][ self::TEXT[ $base ] ] = $text;
				update_option( 'widget_' . $base, $instances );
				++$count;
			}
		}

		update_option( 'sidebars_widgets', $sidebars );
		delete_option( self::BACKUP );
		return $count;
	}

	public static function has_backup(): bool {
		$backup = self::backup();
		return [] !== $backup['replaced'] || [] !== $backup['contents'];
	}

	/**
	 * Removes the widgets of that plugin from all areas (used by the cleanup).
	 */
	public static function remove_legacy(): void {
		$sidebars = self::sidebars();
		foreach ( $sidebars as $sidebar => $widgets ) {
			if ( is_array( $widgets ) ) {
				$sidebars[ $sidebar ] = array_values( array_filter( $widgets, static fn( $id ): bool => ! isset( self::LEGACY[ self::split( (string) $id )[0] ] ) ) );
			}
		}
		update_option( 'sidebars_widgets', $sidebars );
		foreach ( array_keys( self::LEGACY ) as $base ) {
			delete_option( 'widget_' . $base );
		}
		delete_option( self::BACKUP );
	}

	/**
	 * @param array<string, mixed> $instance
	 * @return array{tag: string|null, atts: array<string, string>, notes: list<array{0: string, 1: string}>}
	 */
	private static function map_legacy( string $base, array $instance ): array {
		$atts = [];
		foreach ( $instance as $name => $value ) {
			if ( is_scalar( $value ) ) {
				$atts[ (string) $name ] = (string) $value;
			}
		}
		// The widget always carries a format; only one the user wrote would be worth a note, and that cannot be told apart.
		unset( $atts['format'], $atts['format_header'], $atts['format_footer'], $atts['no_events_text'] );
		// A widget area is narrow: always the small calendar.
		unset( $atts['full'] );

		return ShortcodeMapper::map( self::LEGACY[ $base ], $atts, false, Targets::resolvers() );
	}

	/**
	 * @return array<string, string> Widget id => name of its area.
	 */
	private static function placed(): array {
		global $wp_registered_sidebars;
		$placed = [];
		foreach ( self::sidebars() as $sidebar => $widgets ) {
			if ( ! is_array( $widgets ) ) {
				continue;
			}
			$name = 'wp_inactive_widgets' === $sidebar ? __( 'Inactive widgets', 'rmd-events-organizer' ) : (string) ( $wp_registered_sidebars[ $sidebar ]['name'] ?? $sidebar );
			foreach ( $widgets as $widget_id ) {
				$placed[ (string) $widget_id ] = $name;
			}
		}
		return $placed;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function sidebars(): array {
		$sidebars = get_option( 'sidebars_widgets', [] );
		return is_array( $sidebars ) ? $sidebars : [];
	}

	/**
	 * @return array{0: string, 1: int} Type and number of a widget id like "text-3".
	 */
	private static function split( string $widget_id ): array {
		if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
			return [ $widget_id, 0 ];
		}
		return [ $m[1], (int) $m[2] ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function instance( string $base, int $number ): array {
		$instances = get_option( 'widget_' . $base, [] );
		return is_array( $instances ) && is_array( $instances[ $number ] ?? null ) ? $instances[ $number ] : [];
	}

	/**
	 * @return array{replaced: array<string, string>, contents: array<string, string>}
	 */
	private static function backup(): array {
		$backup = get_option( self::BACKUP, [] );
		$backup = is_array( $backup ) ? $backup : [];
		return [
			'replaced' => is_array( $backup['replaced'] ?? null ) ? $backup['replaced'] : [],
			'contents' => is_array( $backup['contents'] ?? null ) ? $backup['contents'] : [],
		];
	}
}
