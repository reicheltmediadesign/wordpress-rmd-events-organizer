<?php
/**
 * The events overview: next date, place and kind as columns, upcoming events
 * first, a filter for the kind and a "Duplicate" action.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Admin;

use RMD\EventsOrganizer\Capabilities;
use RMD\EventsOrganizer\Clock;
use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Meta;
use RMD\EventsOrganizer\Modules;
use RMD\EventsOrganizer\PostTypes;
use RMD\EventsOrganizer\Query\OccurrenceQuery;
use RMD\EventsOrganizer\Render\Formatter;
use RMD\EventsOrganizer\Repository\EventRepository;
use RMD\EventsOrganizer\Repository\LocationRepository;
use RMD\EventsOrganizer\Schema;
use RMD\EventsOrganizer\Taxonomies;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

final class ListTable {

	public const DUPLICATE_ACTION = 'rmd_eo_duplicate';
	public const KIND_FILTER      = 'rmd_eo_kind';
	public const WHEN_FILTER      = 'rmd_eo_when';
	public const CATEGORY_FILTER  = 'rmd_eo_cat';
	public const LOCATION_FILTER  = 'rmd_eo_loc';

	public const WHEN_UPCOMING = 'upcoming';
	public const WHEN_PAST     = 'past';
	public const WHEN_OFF      = 'off';
	public const WHEN_UNDATED  = 'undated';

	public static function init(): void {
		add_filter( 'manage_' . PostTypes::EVENT . '_posts_columns', [ self::class, 'columns' ] );
		add_action( 'manage_' . PostTypes::EVENT . '_posts_custom_column', [ self::class, 'column' ], 10, 2 );
		add_filter( 'manage_' . PostTypes::LOCATION . '_posts_columns', [ self::class, 'location_columns' ] );
		add_action( 'manage_' . PostTypes::LOCATION . '_posts_custom_column', [ self::class, 'location_column' ], 10, 2 );
		add_filter( 'post_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		add_filter( 'post_class', [ self::class, 'row_class' ], 10, 3 );
		add_action( 'admin_head-edit.php', [ self::class, 'styles' ] );
		add_action( 'restrict_manage_posts', [ self::class, 'filters' ] );
		add_filter( 'months_dropdown_results', [ self::class, 'months' ], 10, 2 );
		add_action( 'pre_get_posts', [ self::class, 'query' ] );
		add_action( 'admin_post_' . self::DUPLICATE_ACTION, [ self::class, 'duplicate' ] );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$date = $columns['date'] ?? '';
		unset( $columns['date'] );

		$columns['rmd_eo_next'] = __( 'Next date', 'rmd-events-organizer' );
		if ( Modules::is_on( Modules::LOCATIONS ) ) {
			$columns['rmd_eo_location'] = __( 'Location', 'rmd-events-organizer' );
		}
		if ( Modules::is_on( Modules::MARKETS ) ) {
			$columns['rmd_eo_kind'] = __( 'Kind', 'rmd-events-organizer' );
		}
		if ( '' !== $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	public static function column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'rmd_eo_next':
				$rows = self::dates( $post_id );
				if ( null === $rows ) {
					echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No date entered', 'rmd-events-organizer' ) . '</span>';
					break;
				}
				echo esc_html( Formatter::when( $rows['item'] ) );
				if ( $rows['past'] ) {
					echo '<br><span class="description">' . esc_html__( 'over', 'rmd-events-organizer' ) . '</span>';
				} elseif ( $rows['more'] > 0 ) {
					/* translators: %d: number of further dates */
					echo '<br><span class="description">' . esc_html( sprintf( _n( '+ %d more date', '+ %d more dates', $rows['more'], 'rmd-events-organizer' ), $rows['more'] ) ) . '</span>';
				}
				if ( $rows['item']['cancelled'] ) {
					echo '<br><strong>' . esc_html__( 'Cancelled', 'rmd-events-organizer' ) . '</strong>';
				} elseif ( $rows['item']['withdrawn'] ) {
					echo '<br><strong>' . esc_html__( 'We are not taking part', 'rmd-events-organizer' ) . '</strong>';
				}
				break;

			case 'rmd_eo_location':
				$location = LocationRepository::find( EventRepository::location_id( $post_id ) );
				echo $location ? esc_html( implode( ', ', array_filter( [ $location['name'], (string) $location['address']['city'] ], 'strlen' ) ) ) : '<span aria-hidden="true">—</span>';
				break;

			case 'rmd_eo_kind':
				echo esc_html( self::kind_labels()[ EventRepository::kind( $post_id ) ] ?? '' );
				break;
		}
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function location_columns( array $columns ): array {
		unset( $columns['date'] );
		$columns['rmd_eo_address'] = __( 'Address', 'rmd-events-organizer' );
		return $columns;
	}

	public static function location_column( string $column, int $post_id ): void {
		if ( 'rmd_eo_address' === $column ) {
			echo esc_html( LocationRepository::one_line( LocationRepository::address( $post_id ) ) );
		}
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( PostTypes::EVENT !== $post->post_type || ! current_user_can( Capabilities::CAP ) || 'trash' === $post->post_status ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'] );

		$url                         = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DUPLICATE_ACTION . '&post=' . (int) $post->ID ),
			self::DUPLICATE_ACTION . '_' . (int) $post->ID
		);
		$actions['rmd_eo_duplicate'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Duplicate', 'rmd-events-organizer' ) );
		return $actions;
	}

	/**
	 * The month an event was written in says nothing about when it takes
	 * place: that filter of WordPress is left out.
	 *
	 * @param object[] $months
	 * @param string   $post_type
	 * @return object[]
	 */
	public static function months( $months, $post_type ): array {
		return PostTypes::EVENT === $post_type ? [] : (array) $months;
	}

	/**
	 * The filters above the list: when, kind, category, location.
	 */
	public static function filters( string $post_type ): void {
		if ( PostTypes::EVENT !== $post_type ) {
			return;
		}

		$when = [
			self::WHEN_UPCOMING => __( 'Upcoming', 'rmd-events-organizer' ),
			self::WHEN_PAST     => __( 'Past', 'rmd-events-organizer' ),
			self::WHEN_OFF      => Modules::is_on( Modules::MARKETS ) ? __( 'Cancelled or not taking part', 'rmd-events-organizer' ) : __( 'Cancelled', 'rmd-events-organizer' ),
			self::WHEN_UNDATED  => __( 'Without a date', 'rmd-events-organizer' ),
		];
		self::select( self::WHEN_FILTER, __( 'Filter by time', 'rmd-events-organizer' ), __( 'All dates', 'rmd-events-organizer' ), $when );

		if ( Modules::is_on( Modules::MARKETS ) ) {
			self::select( self::KIND_FILTER, __( 'Filter by kind', 'rmd-events-organizer' ), __( 'Events and market dates', 'rmd-events-organizer' ), self::kind_labels() );
		}

		$categories = get_terms(
			[
				'taxonomy'   => Taxonomies::CATEGORY,
				'hide_empty' => false,
				'fields'     => 'id=>name',
			]
		);
		if ( is_array( $categories ) && [] !== $categories ) {
			self::select( self::CATEGORY_FILTER, __( 'Filter by category', 'rmd-events-organizer' ), __( 'All categories', 'rmd-events-organizer' ), $categories );
		}

		if ( Modules::is_on( Modules::LOCATIONS ) ) {
			$locations = LocationRepository::choices();
			if ( [] !== $locations ) {
				self::select( self::LOCATION_FILTER, __( 'Filter by location', 'rmd-events-organizer' ), __( 'All locations', 'rmd-events-organizer' ), $locations );
			}
		}
	}

	/**
	 * @param array<int|string, string> $options Value => label.
	 */
	private static function select( string $name, string $label, string $all, array $options ): void {
		$current = self::chosen( $name );
		echo '<label class="screen-reader-text" for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html( $all ) . '</option>';
		foreach ( $options as $value => $text ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( (string) $value ), selected( $current, (string) $value, false ), esc_html( $text ) );
		}
		echo '</select>';
	}

	/**
	 * What was chosen in one of the filters, or ''.
	 */
	private static function chosen( string $name ): string {
		return isset( $_GET[ $name ] ) ? sanitize_key( wp_unslash( $_GET[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
	}

	/**
	 * Applies the filters and, unless another order was chosen, lists
	 * upcoming events first (soonest on top), then the rest.
	 */
	public static function query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || PostTypes::EVENT !== $query->get( 'post_type' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . PostTypes::EVENT !== $screen->id ) {
			return;
		}

		$meta = [];
		$kind = self::chosen( self::KIND_FILTER );
		if ( Kinds::is_valid( $kind ) ) {
			$clause = [
				'key'   => Meta::KIND,
				'value' => $kind,
			];
			if ( Kinds::EVENT === $kind ) {
				// Events saved before a kind existed have no meta at all.
				$clause = [
					'relation' => 'OR',
					$clause,
					[
						'key'     => Meta::KIND,
						'compare' => 'NOT EXISTS',
					],
				];
			}
			$meta[] = $clause;
		}
		$location = absint( self::chosen( self::LOCATION_FILTER ) );
		if ( $location > 0 && Modules::is_on( Modules::LOCATIONS ) ) {
			$meta[] = [
				'key'   => Meta::LOCATION,
				'value' => $location,
			];
		}
		if ( [] !== $meta ) {
			$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin list filter.
		}

		$category = absint( self::chosen( self::CATEGORY_FILTER ) );
		if ( $category > 0 ) {
			$query->set(
				'tax_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Admin list filter.
				[
					[
						'taxonomy' => Taxonomies::CATEGORY,
						'terms'    => [ $category ],
					],
				]
			);
		}

		$when  = self::chosen( self::WHEN_FILTER );
		$dated = self::ids_by_time( $when );
		if ( self::WHEN_UNDATED === $when ) {
			$query->set( 'post__not_in', $dated );
			return;
		}
		if ( null !== $dated ) {
			// An id that no post has: an empty list would mean "no restriction".
			$query->set( 'post__in', [] === $dated ? [ 0 ] : $dated );
		}

		if ( '' !== (string) $query->get( 'orderby' ) || '' !== (string) $query->get( 's' ) ) {
			return;
		}
		$ordered = null === $dated ? self::ids_by_next_date() : $dated;
		if ( [] !== $ordered ) {
			$query->set( 'post__in', $ordered );
			$query->set( 'orderby', 'post__in' );
		}
	}

	/**
	 * Ids of the events a time filter lets through, in the order they are
	 * listed in: upcoming ones soonest first, past ones latest first. For
	 * "without a date" the ids of all events that have dates; null without
	 * a filter.
	 *
	 * @return list<int>|null
	 */
	private static function ids_by_time( string $when ): ?array {
		global $wpdb;
		$table = Schema::occurrences();
		$now   = gmdate( 'Y-m-d H:i:s', Clock::timestamp() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		switch ( $when ) {
			case self::WHEN_UPCOMING:
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM {$table} WHERE status <> 'removed' AND end_utc >= %s GROUP BY event_id ORDER BY MIN(start_utc) ASC LIMIT 5000", $now ) );
				break;
			case self::WHEN_OFF:
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM {$table} WHERE status IN ('cancelled','withdrawn') AND end_utc >= %s GROUP BY event_id ORDER BY MIN(start_utc) ASC LIMIT 5000", $now ) );
				break;
			case self::WHEN_PAST:
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM {$table} WHERE status <> 'removed' GROUP BY event_id HAVING MAX(end_utc) < %s ORDER BY MAX(end_utc) DESC LIMIT 5000", $now ) );
				break;
			case self::WHEN_UNDATED:
				$ids = $wpdb->get_col( "SELECT DISTINCT event_id FROM {$table} WHERE status <> 'removed'" );
				break;
			default:
				return null;
		}
		// phpcs:enable

		return array_map( 'intval', (array) $ids );
	}

	public static function duplicate(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( self::DUPLICATE_ACTION . '_' . $post_id );
		$post = get_post( $post_id );
		if ( ! $post || PostTypes::EVENT !== $post->post_type || ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to duplicate this event.', 'rmd-events-organizer' ) );
		}

		$new_id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => PostTypes::EVENT,
					'post_status'  => 'draft',
					/* translators: %s: title of the copied event */
					'post_title'   => sprintf( __( '%s (copy)', 'rmd-events-organizer' ), $post->post_title ),
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
				]
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		EventRepository::write(
			(int) $new_id,
			[
				'kind'        => EventRepository::kind( $post_id ),
				'schedule'    => EventRepository::schedule( $post_id ),
				'details'     => EventRepository::details( $post_id ),
				'location_id' => EventRepository::location_id( $post_id ),
			]
		);
		foreach ( get_object_taxonomies( PostTypes::EVENT ) as $taxonomy ) {
			wp_set_object_terms( (int) $new_id, wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] ), $taxonomy );
		}
		$thumbnail = get_post_thumbnail_id( $post_id );
		if ( $thumbnail ) {
			set_post_thumbnail( (int) $new_id, (int) $thumbnail );
		}
		\RMD\EventsOrganizer\Sync\OccurrenceSync::sync( (int) $new_id );

		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . (int) $new_id ) );
		exit;
	}

	/**
	 * @return array<string, string>
	 */
	private static function kind_labels(): array {
		return [
			Kinds::EVENT  => __( 'Event', 'rmd-events-organizer' ),
			Kinds::MARKET => __( 'Market date', 'rmd-events-organizer' ),
		];
	}

	/**
	 * Marks the rows of the list: over, still to come, or still to come but
	 * cancelled or without us.
	 *
	 * @param string[] $classes
	 * @param string[] $extra
	 * @param int      $post_id
	 * @return string[]
	 */
	public static function row_class( $classes, $extra, $post_id ): array {
		$classes = (array) $classes;
		if ( ! is_admin() || PostTypes::EVENT !== get_post_type( (int) $post_id ) ) {
			return $classes;
		}
		$rows = self::dates( (int) $post_id );
		if ( null === $rows ) {
			return $classes;
		}
		if ( $rows['past'] ) {
			$classes[] = 'rmd-eo-is-past';
		} else {
			$classes[] = $rows['item']['cancelled'] || $rows['item']['withdrawn'] ? 'rmd-eo-is-off' : 'rmd-eo-is-upcoming';
		}
		return $classes;
	}

	public static function styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || PostTypes::EVENT !== $screen->post_type ) {
			return;
		}
		echo '<style>.wp-list-table tr>:first-child{border-left:4px solid transparent}.wp-list-table tr.rmd-eo-is-past>:first-child{border-left-color:#a7aaad}.wp-list-table tr.rmd-eo-is-upcoming>:first-child{border-left-color:#00a32a}.wp-list-table tr.rmd-eo-is-off>:first-child{border-left-color:#d63638}</style>';
	}

	/**
	 * Next upcoming date of an event (any post status), or its last one.
	 *
	 * @return array{item: array<string, mixed>, more: int, past: bool}|null
	 */
	private static function dates( int $post_id ): ?array {
		global $wpdb;
		$table = Schema::occurrences();
		$now   = gmdate( 'Y-m-d H:i:s', Clock::timestamp() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND status <> 'removed' AND end_utc >= %s ORDER BY start_utc ASC LIMIT 1", $post_id, $now ), ARRAY_A );
		$more = 0;
		$past = false;
		if ( is_array( $row ) ) {
			$more = max( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status <> 'removed' AND end_utc >= %s", $post_id, $now ) ) - 1 );
		} else {
			$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND status <> 'removed' ORDER BY start_utc DESC LIMIT 1", $post_id ), ARRAY_A );
			$past = true;
		}
		// phpcs:enable

		if ( ! is_array( $row ) ) {
			return null;
		}
		return [
			'item' => [
				'start_date' => (string) $row['start_date'],
				'end_date'   => (string) $row['end_date'],
				'start_time' => null === $row['start_time'] ? '' : substr( (string) $row['start_time'], 0, 5 ),
				'end_time'   => null === $row['end_time'] ? '' : substr( (string) $row['end_time'], 0, 5 ),
				'all_day'    => (bool) $row['all_day'],
				'time_mode'  => (string) $row['time_mode'],
				'cancelled'  => 'cancelled' === $row['status'],
				'withdrawn'  => 'withdrawn' === $row['status'],
			],
			'more' => $more,
			'past' => $past,
		];
	}

	/**
	 * All event ids: upcoming ones by their next date, then the others,
	 * newest first.
	 *
	 * @return list<int>
	 */
	private static function ids_by_next_date(): array {
		global $wpdb;
		$table = Schema::occurrences();
		$now   = gmdate( 'Y-m-d H:i:s', Clock::timestamp() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$upcoming = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM {$table} WHERE status <> 'removed' AND end_utc >= %s GROUP BY event_id ORDER BY MIN(start_utc) ASC LIMIT 2000", $now ) ) );
		$others   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY post_date DESC LIMIT 5000", PostTypes::EVENT ) ) );
		// phpcs:enable

		return array_values( array_unique( array_merge( $upcoming, $others ) ) );
	}
}
