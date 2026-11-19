<?php
/**
 * Second step of the import: pages, widgets and menus that still use
 * shortcodes, blocks, widgets or links of Events Manager.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Admin;

use RMD\EventsOrganizer\Domain\Em\ContentConverter;
use RMD\EventsOrganizer\Domain\Em\ShortcodeMapper;
use RMD\EventsOrganizer\Migration\ContentRewriter;
use RMD\EventsOrganizer\Migration\EmReader;
use RMD\EventsOrganizer\Migration\Importer;
use RMD\EventsOrganizer\Migration\MenuRewriter;
use RMD\EventsOrganizer\Migration\WidgetRewriter;

defined( 'ABSPATH' ) || exit;

final class MigrationContentTab {

	public const ACTION_CONTENT = 'rmd_eo_em_content';
	public const ACTION_WIDGETS = 'rmd_eo_em_widgets';
	public const ACTION_MENUS   = 'rmd_eo_em_menus';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION_CONTENT, [ self::class, 'handle_content' ] );
		add_action( 'admin_post_' . self::ACTION_WIDGETS, [ self::class, 'handle_widgets' ] );
		add_action( 'admin_post_' . self::ACTION_MENUS, [ self::class, 'handle_menus' ] );
	}

	public static function render(): void {
		echo '<p>' . esc_html__( 'Pages, widgets and menus that still use shortcodes, blocks or links of Events Manager are listed here and can be changed to those of this plugin. The original is kept for every change, so each one can be put back.', 'rmd-events-organizer' ) . '</p>';
		if ( 0 === Importer::imported()['events'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Import the events first (step 1). Filters by category or location and single events can only be carried over once they exist here.', 'rmd-events-organizer' ) . '</p></div>';
		}

		if ( EmReader::is_plugin_active() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Events Manager is still active. It keeps filling its own pages and showing its widgets, so changes made here only become visible once it is deactivated.', 'rmd-events-organizer' ) . '</p></div>';
		}

		self::content_section();
		self::widget_section();
		self::menu_section();
	}

	/**
	 * Message for the notice after one of the actions of this tab, or ''.
	 *
	 * @param array<string, mixed> $numbers
	 */
	public static function result_message( string $done, array $numbers ): string {
		$count = (int) ( $numbers['count'] ?? 0 );
		switch ( $done ) {
			case 'content':
				/* translators: %d: number of pages */
				return sprintf( _n( '%d page or post changed.', '%d pages or posts changed.', $count, 'rmd-events-organizer' ), $count );
			case 'content_undo':
				/* translators: %d: number of pages */
				return sprintf( _n( '%d page or post put back.', '%d pages or posts put back.', $count, 'rmd-events-organizer' ), $count );
			case 'widgets':
				/* translators: %d: number of widgets */
				return sprintf( _n( '%d widget changed.', '%d widgets changed.', $count, 'rmd-events-organizer' ), $count );
			case 'widgets_undo':
				/* translators: %d: number of widgets */
				return sprintf( _n( '%d widget put back.', '%d widgets put back.', $count, 'rmd-events-organizer' ), $count );
			case 'menus':
				/* translators: %d: number of menu items */
				return sprintf( _n( '%d menu item changed.', '%d menu items changed.', $count, 'rmd-events-organizer' ), $count );
			case 'menus_undo':
				/* translators: %d: number of menu items */
				return sprintf( _n( '%d menu item put back.', '%d menu items put back.', $count, 'rmd-events-organizer' ), $count );
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Sections                                                            */
	/* ------------------------------------------------------------------ */

	private static function content_section(): void {
		$rows = ContentRewriter::scan();
		echo '<h2>' . esc_html__( 'Pages and posts', 'rmd-events-organizer' ) . '</h2>';
		if ( [] === $rows ) {
			echo '<p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'No page, post or template uses shortcodes or blocks of Events Manager.', 'rmd-events-organizer' ) . '</p>';
			return;
		}

		$open      = count( array_filter( $rows, static fn( array $row ): bool => $row['open'] > 0 ) );
		$converted = count( array_filter( $rows, static fn( array $row ): bool => $row['converted'] ) );
		self::bulk_buttons(
			self::ACTION_CONTENT,
			$open,
			$converted,
			[ 'post' => '0' ],
			__( 'Put back the original content of all changed pages? Changes made to them since are lost.', 'rmd-events-organizer' )
		);

		echo '<table class="widefat striped rmd-eo-import__changes"><thead><tr><th>' . esc_html__( 'Page', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Found', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Becomes', 'rmd-events-organizer' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$first = true;
			$span  = max( 1, count( $row['changes'] ) );
			foreach ( [] !== $row['changes'] ? $row['changes'] : [ null ] as $change ) {
				echo '<tr>';
				if ( $first ) {
					$link = get_edit_post_link( $row['id'] );
					echo '<td rowspan="' . (int) $span . '"><strong>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $row['title'] ) . '</a>' : esc_html( $row['title'] ) ) . '</strong><br>' . esc_html( $row['type'] );
					if ( $row['converted'] ) {
						echo '<br><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'changed', 'rmd-events-organizer' );
					}
					echo '</td>';
				}
				if ( null === $change ) {
					echo '<td colspan="2">' . esc_html__( 'Nothing of Events Manager is left on this page.', 'rmd-events-organizer' ) . '</td>';
				} else {
					self::change_cells( $change['from'], $change['to'], $change['notes'] );
				}
				if ( $first ) {
					echo '<td rowspan="' . (int) $span . '">';
					if ( $row['open'] > 0 ) {
						MigrationPage::button(
							self::ACTION_CONTENT,
							__( 'Change', 'rmd-events-organizer' ),
							'secondary small',
							'',
							[
								'do'   => 'apply',
								'post' => (string) $row['id'],
							],
							true
						);
					}
					if ( $row['converted'] ) {
						MigrationPage::button(
							self::ACTION_CONTENT,
							__( 'Put back', 'rmd-events-organizer' ),
							'secondary small',
							__( 'Put back the original content of this page? Changes made to it since are lost.', 'rmd-events-organizer' ),
							[
								'do'   => 'undo',
								'post' => (string) $row['id'],
							],
							true
						);
					}
					echo '</td>';
				}
				echo '</tr>';
				$first = false;
			}
		}
		echo '</tbody></table>';
	}

	private static function widget_section(): void {
		$rows = WidgetRewriter::scan();
		$undo = WidgetRewriter::has_backup();
		echo '<h2>' . esc_html__( 'Widgets', 'rmd-events-organizer' ) . '</h2>';
		if ( [] === $rows && ! $undo ) {
			echo '<p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'No widget of Events Manager is in use.', 'rmd-events-organizer' ) . '</p>';
			return;
		}

		self::bulk_buttons(
			self::ACTION_WIDGETS,
			count( array_filter( $rows, static fn( array $row ): bool => '' !== $row['to'] ) ),
			$undo ? 1 : 0,
			[],
			__( 'Put back the widgets as they were?', 'rmd-events-organizer' )
		);
		if ( [] === $rows ) {
			echo '<p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'The widgets of Events Manager were changed; none is left.', 'rmd-events-organizer' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped rmd-eo-import__changes"><thead><tr><th>' . esc_html__( 'Widget area', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Found', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Becomes', 'rmd-events-organizer' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['area'] ) . '</td>';
			$labels = [
				'em_widget'           => __( 'Widget “Events”', 'rmd-events-organizer' ),
				'em_calendar'         => __( 'Widget “Events Calendar”', 'rmd-events-organizer' ),
				'em_locations_widget' => __( 'Widget “Event Locations”', 'rmd-events-organizer' ),
			];
			$base   = (string) preg_replace( '/-\d+$/', '', $row['from'] );
			self::change_cells( $labels[ $base ] ?? $row['from'], $row['to'], $row['notes'], isset( $labels[ $base ] ) );
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function menu_section(): void {
		$rows = MenuRewriter::scan();
		echo '<h2>' . esc_html__( 'Menus', 'rmd-events-organizer' ) . '</h2>';
		if ( [] === $rows ) {
			echo '<p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'No menu item leads to an event of Events Manager.', 'rmd-events-organizer' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'Menu items whose target no longer exists disappear from the menu. Changed items lead to the imported event instead.', 'rmd-events-organizer' ) . '</p>';

		self::bulk_buttons(
			self::ACTION_MENUS,
			count( array_filter( $rows, static fn( array $row ): bool => '' !== $row['to'] && ! $row['converted'] ) ),
			count( array_filter( $rows, static fn( array $row ): bool => $row['converted'] ) ),
			[],
			__( 'Put back the menu items as they were?', 'rmd-events-organizer' )
		);

		$notes = [
			MenuRewriter::NOTE_LOCATION => __( 'Locations have no page of their own here; please remove the menu item or link another page.', 'rmd-events-organizer' ),
			MenuRewriter::NOTE_TERM     => __( 'Categories and tags have no page of their own here; please remove the menu item or link another page.', 'rmd-events-organizer' ),
			MenuRewriter::NOTE_ARCHIVE  => __( 'The page with the list of events was not found; please link the right page by hand.', 'rmd-events-organizer' ),
			MenuRewriter::NOTE_MISSING  => __( 'The event it leads to was not imported.', 'rmd-events-organizer' ),
		];
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Menu', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Menu item', 'rmd-events-organizer' ) . '</th><th>' . esc_html__( 'Leads to', 'rmd-events-organizer' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['menu'] ) . '</td><td><strong>' . esc_html( $row['title'] ) . '</strong></td><td>';
			if ( '' !== $row['to'] ) {
				echo esc_html( $row['to'] );
				if ( $row['converted'] ) {
					echo ' <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'changed', 'rmd-events-organizer' );
				}
			} else {
				echo esc_html( $notes[ $row['note'] ] ?? '' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @param array<string, string> $fields
	 */
	private static function bulk_buttons( string $action, int $open, int $converted, array $fields, string $confirm ): void {
		if ( 0 === $open + $converted ) {
			return;
		}
		echo '<div class="rmd-eo-import__actions">';
		if ( $open > 0 ) {
			MigrationPage::button( $action, __( 'Change all', 'rmd-events-organizer' ), 'primary', '', [ 'do' => 'apply' ] + $fields, true );
		}
		if ( $converted > 0 ) {
			MigrationPage::button( $action, __( 'Put all back', 'rmd-events-organizer' ), 'secondary', $confirm, [ 'do' => 'undo' ] + $fields, true );
		}
		echo '</div>';
	}

	/**
	 * @param list<array{0: string, 1: string}> $notes
	 */
	private static function change_cells( string $from, string $to, array $notes, bool $plain = false ): void {
		$short = static function ( string $text ): string {
			$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
			return mb_strlen( $text ) > 160 ? mb_substr( $text, 0, 160 ) . ' …' : $text;
		};

		echo '<td>';
		if ( '' === $from ) {
			echo '–';
		} elseif ( $plain ) {
			echo esc_html( $from );
		} else {
			echo '<code>' . esc_html( $short( $from ) ) . '</code>';
		}
		echo '</td><td>';
		if ( '' !== $to ) {
			echo '<code>' . esc_html( $short( $to ) ) . '</code>';
		} else {
			echo '<strong>' . esc_html__( 'stays as it is', 'rmd-events-organizer' ) . '</strong>';
		}
		foreach ( $notes as $note ) {
			$text = self::note( $note );
			if ( '' !== $text ) {
				echo '<br><span class="description">' . esc_html( $text ) . '</span>';
			}
		}
		echo '</td>';
	}

	/**
	 * @param array{0: string, 1: string} $note
	 */
	private static function note( array $note ): string {
		[ $code, $detail ] = $note;
		switch ( $code ) {
			case ShortcodeMapper::NOTE_NONE:
				return __( 'Has no counterpart in this plugin; please remove or replace it by hand.', 'rmd-events-organizer' );
			case ShortcodeMapper::NOTE_FORMAT:
				return __( 'Its own output format is not carried over; the display follows this plugin.', 'rmd-events-organizer' );
			case ShortcodeMapper::NOTE_SCOPE:
				/* translators: %s: name of a period, e.g. "3-months" */
				return sprintf( __( 'The period “%s” does not exist here; upcoming dates are shown.', 'rmd-events-organizer' ), $detail );
			case ShortcodeMapper::NOTE_FILTER:
				/* translators: %s: a shortcode attribute, e.g. category="5" */
				return sprintf( __( 'The filter %s could not be carried over and is left out.', 'rmd-events-organizer' ), $detail );
			case ShortcodeMapper::NOTE_ATTRIBUTE:
				/* translators: %s: name of a shortcode attribute */
				return sprintf( __( 'The attribute %s is not supported and is left out.', 'rmd-events-organizer' ), $detail );
			case ShortcodeMapper::NOTE_EVENT:
				return __( 'The event it shows was not imported.', 'rmd-events-organizer' );
			case ContentConverter::NOTE_PAGE_WHOLE:
				return __( 'Events Manager replaced the whole content of this page with its list; here the list is added below the existing content.', 'rmd-events-organizer' );
			case ContentConverter::NOTE_PAGE:
				$pages = [
					'locations'  => __( 'This page showed the list of locations of Events Manager. There is no replacement; please change or delete the page.', 'rmd-events-organizer' ),
					'categories' => __( 'This page showed the list of categories of Events Manager. There is no replacement; please change or delete the page.', 'rmd-events-organizer' ),
					'tags'       => __( 'This page showed the list of tags of Events Manager. There is no replacement; please change or delete the page.', 'rmd-events-organizer' ),
					'bookings'   => __( 'This page showed visitors their bookings in Events Manager. There is no replacement; please change or delete the page.', 'rmd-events-organizer' ),
				];
				return $pages[ $detail ] ?? '';
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	public static function handle_content(): void {
		MigrationPage::guard( self::ACTION_CONTENT );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$undo    = isset( $_POST['do'] ) && 'undo' === sanitize_key( wp_unslash( $_POST['do'] ) );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		// phpcs:enable

		if ( $post_id > 0 ) {
			$count = ( $undo ? ContentRewriter::undo( $post_id ) : ContentRewriter::apply( $post_id ) ) ? 1 : 0;
		} else {
			$count = $undo ? ContentRewriter::undo_all() : ContentRewriter::apply_all();
		}
		self::finish( $undo ? 'content_undo' : 'content', $count );
	}

	public static function handle_widgets(): void {
		MigrationPage::guard( self::ACTION_WIDGETS );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$undo = isset( $_POST['do'] ) && 'undo' === sanitize_key( wp_unslash( $_POST['do'] ) );
		self::finish( $undo ? 'widgets_undo' : 'widgets', $undo ? WidgetRewriter::undo_all() : WidgetRewriter::apply_all() );
	}

	public static function handle_menus(): void {
		MigrationPage::guard( self::ACTION_MENUS );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$undo = isset( $_POST['do'] ) && 'undo' === sanitize_key( wp_unslash( $_POST['do'] ) );
		self::finish( $undo ? 'menus_undo' : 'menus', $undo ? MenuRewriter::undo_all() : MenuRewriter::apply_all() );
	}

	private static function finish( string $done, int $count ): void {
		MigrationPage::remember( [ 'count' => $count ] );
		wp_safe_redirect(
			MigrationPage::url(
				[
					'tab'  => MigrationPage::TAB_CONTENT,
					'done' => $done,
				]
			)
		);
		exit;
	}
}
