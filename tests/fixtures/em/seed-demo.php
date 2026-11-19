<?php
/**
 * Seeds a test site with demo data in the shape Events Manager 7.x leaves
 * behind (its tables and posts stay when the plugin is deleted). Used to
 * develop and test the importer; never run it against a customer site.
 *
 * Usage: php tests/fixtures/em/seed-demo.php <path to WordPress>
 *
 * Everything it creates carries "[em-demo]" in its name and is replaced on
 * the next run. The dates of the series are computed here the way Events
 * Manager computes them (one series per weekday, stepping by the interval),
 * independently of this plugin's own recurrence code.
 *
 * @package RMD\EventsOrganizer
 */

// phpcs:ignoreFile -- Development fixture, not part of the plugin.

if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) {
	exit( "Usage: php seed-demo.php <path to WordPress>\n" );
}
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';

global $wpdb;
$events_table      = $wpdb->prefix . 'em_events';
$locations_table   = $wpdb->prefix . 'em_locations';
$recurrences_table = $wpdb->prefix . 'em_event_recurrences';
$meta_table        = $wpdb->prefix . 'em_meta';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events_table ) ) !== $events_table ) {
	exit( "No Events Manager tables found ({$events_table}). Install and activate Events Manager once, then delete it.\n" );
}
if ( ! in_array( 'event_type', $wpdb->get_col( "SHOW COLUMNS FROM {$events_table}" ), true ) ) {
	exit( "This seeder writes the 7.x table layout; the tables here are older.\n" );
}

// Events Manager is not installed, so its taxonomies have to exist for a moment.
foreach ( [ 'event-categories' => true, 'event-tags' => false ] as $taxonomy => $hierarchical ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		register_taxonomy( $taxonomy, 'event', [ 'hierarchical' => $hierarchical, 'public' => false ] );
	}
}

$zone  = wp_timezone();
$owner = (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 1 );
$today = new DateTimeImmutable( 'today', $zone );
$day   = fn( string $modifier ): string => $today->modify( $modifier )->format( 'Y-m-d' );
$utc   = fn( string $date, string $time ): string => ( new DateTimeImmutable( "$date $time", $zone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

/* ---------------------------------------------------------------------- */
/* Remove the data of earlier runs                                         */
/* ---------------------------------------------------------------------- */

$old_event_ids = $wpdb->get_col( "SELECT event_id FROM {$events_table} WHERE event_name LIKE '[em-demo]%'" );
if ( $old_event_ids ) {
	$in = implode( ',', array_map( 'intval', $old_event_ids ) );
	$wpdb->query( "DELETE FROM {$recurrences_table} WHERE event_id IN ($in)" );
	$wpdb->query( "DELETE FROM {$events_table} WHERE event_id IN ($in)" );
}
$wpdb->query( "DELETE FROM {$locations_table} WHERE location_name LIKE '[em-demo]%'" );
foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE '[em-demo]%'" ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}
foreach ( [ 'event-categories', 'event-tags' ] as $taxonomy ) {
	foreach ( get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] ) as $term ) {
		if ( 0 === strpos( $term->name, '[em-demo]' ) ) {
			$wpdb->delete( $meta_table, [ 'object_id' => $term->term_id ] );
			wp_delete_term( $term->term_id, $taxonomy );
		}
	}
}

// Widgets and conversions of earlier runs.
$demo_widget = 91;
$sidebars    = (array) get_option( 'sidebars_widgets', [] );
$blocks      = (array) get_option( 'widget_block', [] );
foreach ( $blocks as $number => $block ) {
	if ( is_array( $block ) && false !== strpos( (string) ( $block['content'] ?? '' ), '[em-demo]' ) ) {
		unset( $blocks[ $number ] );
		foreach ( $sidebars as $sidebar => $widgets ) {
			if ( is_array( $widgets ) ) {
				$sidebars[ $sidebar ] = array_values( array_diff( $widgets, [ 'block-' . $number ] ) );
			}
		}
	}
}
update_option( 'widget_block', $blocks );
foreach ( $sidebars as $sidebar => $widgets ) {
	if ( is_array( $widgets ) ) {
		$sidebars[ $sidebar ] = array_values( array_filter( $widgets, fn( $id ) => ! preg_match( '/-' . $demo_widget . '$/', (string) $id ) ) );
	}
}
update_option( 'sidebars_widgets', $sidebars );
delete_option( 'rmd_eo_em_widget_backup' );
foreach ( wp_get_nav_menus() as $menu ) {
	if ( 0 === strpos( $menu->name, '[em-demo]' ) ) {
		wp_delete_nav_menu( $menu->term_id );
	}
}

/* ---------------------------------------------------------------------- */
/* Terms                                                                   */
/* ---------------------------------------------------------------------- */

$term = function ( string $name, string $taxonomy, int $parent = 0, string $color = '' ) use ( $wpdb, $meta_table ): int {
	$created = wp_insert_term( "[em-demo] $name", $taxonomy, [ 'parent' => $parent ] );
	$term_id = (int) $created['term_id'];
	if ( '' !== $color ) {
		$wpdb->insert( $meta_table, [ 'object_id' => $term_id, 'meta_key' => 'category-bgcolor', 'meta_value' => $color ] );
	}
	return $term_id;
};
$cat_concerts = $term( 'Konzerte', 'event-categories', 0, '#cc3300' );
$cat_courses  = $term( 'Kurse', 'event-categories', 0, '#2a7de1' );
$cat_yoga     = $term( 'Yoga', 'event-categories', $cat_courses );
$cat_markets  = $term( 'Märkte', 'event-categories', 0, '#3c8d2f' );
$tag_family   = $term( 'familienfreundlich', 'event-tags' );
$tag_outdoor  = $term( 'draußen', 'event-tags' );

/* ---------------------------------------------------------------------- */
/* Locations                                                               */
/* ---------------------------------------------------------------------- */

$location = function ( string $name, array $fields, string $status = 'publish' ) use ( $wpdb, $locations_table, $owner ): int {
	$post_id = wp_insert_post(
		[
			'post_type'    => 'location',
			'post_status'  => $status,
			'post_title'   => "[em-demo] $name",
			'post_content' => "Beschreibung des Ortes $name.",
			'post_author'  => $owner,
		]
	);
	$row = [
		'post_id'              => $post_id,
		'blog_id'              => 0,
		'location_slug'        => get_post_field( 'post_name', $post_id ),
		'location_name'        => "[em-demo] $name",
		'location_owner'       => $owner,
		'post_content'         => "Beschreibung des Ortes $name.",
		'location_status'      => 'publish' === $status ? 1 : null,
		'location_private'     => 0,
		'location_language'    => 'de_DE',
		'location_translation' => 0,
	] + $fields;
	$wpdb->insert( $locations_table, $row );
	$location_id = (int) $wpdb->insert_id;

	update_post_meta( $post_id, '_location_id', $location_id );
	foreach ( $fields as $key => $value ) {
		update_post_meta( $post_id, '_' . $key, $value );
	}
	return $location_id;
};
$loc_hall   = $location( 'Stadthalle', [ 'location_address' => 'Schloßplatz 6', 'location_town' => 'Freiberg', 'location_state' => 'Sachsen', 'location_postcode' => '09599', 'location_region' => 'Mittelsachsen', 'location_country' => 'DE', 'location_latitude' => 50.920000, 'location_longitude' => 13.340000 ] );
$loc_market = $location( 'Obermarkt', [ 'location_address' => 'Obermarkt', 'location_town' => 'Freiberg', 'location_state' => 'Sachsen', 'location_postcode' => '09599', 'location_region' => '', 'location_country' => 'DE', 'location_latitude' => 50.917800, 'location_longitude' => 13.343100 ] );
$loc_studio = $location( 'Yogastudio', [ 'location_address' => 'Burgstraße 12', 'location_town' => 'Freiberg', 'location_state' => '', 'location_postcode' => '09599', 'location_region' => '', 'location_country' => 'DE', 'location_latitude' => 0, 'location_longitude' => 0 ] );

/* ---------------------------------------------------------------------- */
/* Events                                                                  */
/* ---------------------------------------------------------------------- */

/**
 * Inserts one row into the events table and, unless $with_post is false, its
 * post with the mirrored meta. Returns [ event_id, post_id ].
 */
$event = function ( string $name, array $args ) use ( $wpdb, $events_table, $owner, $utc, $zone ): array {
	$args += [
		'type'              => 'single',
		'post_type'         => 'event',
		'post_status'       => 'publish',
		'with_post'         => true,
		'start_date'        => '',
		'end_date'          => '',
		'start_time'        => '00:00:00',
		'end_time'          => '00:00:00',
		'all_day'           => 0,
		'location_id'       => 0,
		'active'            => 1,
		'content'           => "Beschreibung zu $name.",
		'recurrence_set_id' => null,
		'slug'              => '',
		'terms'             => [],
	];
	if ( '' === $args['end_date'] ) {
		$args['end_date'] = $args['start_date'];
	}
	if ( $args['all_day'] ) {
		$args['start_time'] = '00:00:00';
		$args['end_time']   = '23:59:59';
	}
	$status_map = [ 'publish' => 1, 'private' => 1, 'pending' => 0, 'trash' => -1, 'draft' => null ];

	$post_id = null;
	if ( $args['with_post'] ) {
		$post_id = wp_insert_post(
			[
				'post_type'    => $args['post_type'],
				'post_status'  => $args['post_status'],
				'post_title'   => "[em-demo] $name",
				'post_name'    => $args['slug'],
				'post_content' => $args['content'],
				'post_excerpt' => 'recurrence' === $args['type'] ? '' : "Kurztext zu $name.",
				'post_author'  => $owner,
			]
		);
		foreach ( $args['terms'] as $taxonomy => $term_ids ) {
			wp_set_object_terms( $post_id, array_map( 'intval', $term_ids ), $taxonomy );
		}
	}

	$row = [
		'post_id'             => $post_id,
		'event_archetype'     => 'event',
		'event_type'          => $args['type'],
		'event_slug'          => $post_id ? get_post_field( 'post_name', $post_id ) : sanitize_title( "em-demo $name" ),
		'event_owner'         => $owner,
		'event_status'        => $status_map[ $args['post_status'] ],
		'event_active_status' => $args['active'],
		'event_name'          => "[em-demo] $name",
		'event_start_date'    => $args['start_date'],
		'event_end_date'      => $args['end_date'],
		'event_start_time'    => $args['start_time'],
		'event_end_time'      => $args['end_time'],
		'event_all_day'       => $args['all_day'],
		'event_start'         => $utc( $args['start_date'], $args['start_time'] ),
		'event_end'           => $utc( $args['end_date'], $args['end_time'] ),
		'event_timezone'      => $zone->getName(),
		'post_content'        => $args['content'],
		'event_rsvp'          => 0,
		'event_private'       => 'private' === $args['post_status'] ? 1 : 0,
		'location_id'         => $args['location_id'],
		'recurrence_set_id'   => $args['recurrence_set_id'],
		'event_date_created'  => current_time( 'mysql' ),
		'blog_id'             => 0,
		'group_id'            => 0,
		'event_language'      => 'de_DE',
		'event_translation'   => 0,
	];
	$wpdb->insert( $events_table, $row );
	$event_id = (int) $wpdb->insert_id;

	if ( $post_id ) {
		$meta = [
			'_event_id'            => $event_id,
			'_event_archetype'     => 'event',
			'_event_type'          => $args['type'],
			'_event_timezone'      => $zone->getName(),
			'_event_start_time'    => $args['start_time'],
			'_event_end_time'      => $args['end_time'],
			'_event_start'         => $row['event_start'],
			'_event_end'           => $row['event_end'],
			'_event_all_day'       => $args['all_day'],
			'_event_start_date'    => $args['start_date'],
			'_event_end_date'      => $args['end_date'],
			'_location_id'         => $args['location_id'],
			'_recurrence_set_id'   => (string) $args['recurrence_set_id'],
			'_event_active_status' => $args['active'],
			'_event_start_local'   => $args['start_date'] . ' ' . $args['start_time'],
			'_event_end_local'     => $args['end_date'] . ' ' . $args['end_time'],
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}
	return [ $event_id, (int) $post_id ];
};

$rule = function ( int $event_id, array $fields ) use ( $wpdb, $recurrences_table, $zone ): int {
	$wpdb->insert(
		$recurrences_table,
		$fields + [
			'event_id'            => $event_id,
			'recurrence_order'    => 1,
			'recurrence_type'     => 'include',
			'recurrence_interval' => 1,
			'recurrence_duration' => 0,
			'recurrence_all_day'  => 0,
			'recurrence_timezone' => $zone->getName(),
			'recurrence_status'   => 1,
		]
	);
	return (int) $wpdb->insert_id;
};

/** Weekly dates the way Events Manager produces them: one series per weekday. */
$weekly = function ( string $from, string $until, array $weekdays, int $interval ): array {
	$dates = [];
	$start = new DateTimeImmutable( $from );
	for ( $i = 0; $i < 7; $i++ ) {
		$first = $start->modify( "+$i days" );
		if ( ! in_array( (int) $first->format( 'w' ), $weekdays, true ) ) {
			continue;
		}
		for ( $date = $first; $date->format( 'Y-m-d' ) <= $until; $date = $date->modify( '+' . ( 7 * $interval ) . ' days' ) ) {
			$dates[] = $date->format( 'Y-m-d' );
		}
	}
	sort( $dates );
	return $dates;
};

/** Monthly dates: the nth (or last, when $week <= 0) given weekday of each month. */
$monthly = function ( string $from, string $until, int $weekday, int $week ): array {
	$dates = [];
	for ( $month = new DateTimeImmutable( substr( $from, 0, 7 ) . '-01' ); $month->format( 'Y-m-d' ) <= $until; $month = $month->modify( '+1 month' ) ) {
		$matches = [];
		for ( $d = 1; $d <= (int) $month->format( 't' ); $d++ ) {
			$candidate = $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'm' ), $d );
			if ( (int) $candidate->format( 'w' ) === $weekday ) {
				$matches[] = $candidate->format( 'Y-m-d' );
			}
		}
		$date = $week > 0 ? ( $matches[ $week - 1 ] ?? null ) : end( $matches );
		if ( $date && $date >= $from && $date <= $until ) {
			$dates[] = $date;
		}
	}
	return $dates;
};

$count = [];

// 1. Upcoming single events.
$event( 'Jazzabend', [ 'start_date' => $day( '+9 days' ), 'start_time' => '19:30:00', 'end_time' => '22:00:00', 'location_id' => $loc_hall, 'terms' => [ 'event-categories' => [ $cat_concerts ], 'event-tags' => [ $tag_family ] ] ] );
$event( 'Stadtfest', [ 'start_date' => $day( '+30 days' ), 'end_date' => $day( '+32 days' ), 'all_day' => 1, 'location_id' => $loc_market, 'terms' => [ 'event-tags' => [ $tag_family, $tag_outdoor ] ] ] );
$event( 'Nachtkonzert', [ 'start_date' => $day( '+14 days' ), 'end_date' => $day( '+15 days' ), 'start_time' => '21:00:00', 'end_time' => '01:30:00', 'location_id' => $loc_hall, 'terms' => [ 'event-categories' => [ $cat_concerts ] ] ] );
$event( 'Vortrag ohne Ort', [ 'start_date' => $day( '+3 days' ), 'start_time' => '18:00:00', 'end_time' => '18:00:00' ] );
$event( 'Abgesagtes Konzert', [ 'start_date' => $day( '+20 days' ), 'start_time' => '20:00:00', 'end_time' => '22:00:00', 'location_id' => $loc_hall, 'active' => 0, 'terms' => [ 'event-categories' => [ $cat_concerts ] ] ] );
$count['single upcoming'] = 5;

// 2. Archive: past events.
$event( 'Sommerkonzert (Archiv)', [ 'start_date' => $day( '-150 days' ), 'start_time' => '19:00:00', 'end_time' => '21:30:00', 'location_id' => $loc_hall, 'terms' => [ 'event-categories' => [ $cat_concerts ] ] ] );
$event( 'Weihnachtsmarkt 2024 (Archiv)', [ 'start_date' => '2024-11-29', 'end_date' => '2024-12-22', 'all_day' => 1, 'location_id' => $loc_market, 'terms' => [ 'event-categories' => [ $cat_markets ], 'event-tags' => [ $tag_outdoor ] ] ] );
$event( 'Lesung (Archiv)', [ 'start_date' => $day( '-21 days' ), 'start_time' => '17:00:00', 'end_time' => '18:30:00', 'location_id' => $loc_studio ] );
$event( 'Ausgefallener Workshop (Archiv)', [ 'start_date' => $day( '-60 days' ), 'start_time' => '10:00:00', 'end_time' => '15:00:00', 'active' => 0, 'terms' => [ 'event-categories' => [ $cat_courses ] ] ] );
$count['single past'] = 4;

// 3. Other publication states.
$event( 'Entwurf', [ 'post_status' => 'draft', 'start_date' => $day( '+40 days' ), 'start_time' => '10:00:00', 'end_time' => '12:00:00' ] );
$event( 'Zur Prüfung', [ 'post_status' => 'pending', 'start_date' => $day( '+41 days' ), 'start_time' => '10:00:00', 'end_time' => '12:00:00' ] );
$event( 'Privat', [ 'post_status' => 'private', 'start_date' => $day( '+42 days' ), 'start_time' => '10:00:00', 'end_time' => '12:00:00' ] );
$event( 'Im Papierkorb', [ 'post_status' => 'trash', 'start_date' => $day( '+43 days' ), 'start_time' => '10:00:00', 'end_time' => '12:00:00' ] );
$count['draft, pending, private, trash'] = 4;

// 4. "Recurring" (7.x): one post, rule rows, occurrences only in the table.
[ $yoga_id ] = $event( 'Yoga am Abend', [ 'type' => 'recurring', 'start_date' => $day( '-60 days' ), 'end_date' => $day( '+90 days' ), 'start_time' => '18:00:00', 'end_time' => '19:30:00', 'location_id' => $loc_studio, 'terms' => [ 'event-categories' => [ $cat_yoga ] ] ] );
$yoga_dates   = $weekly( $day( '-60 days' ), $day( '+90 days' ), [ 2, 4 ], 1 );
$yoga_skipped = [ $yoga_dates[ count( $yoga_dates ) - 4 ], $yoga_dates[ count( $yoga_dates ) - 9 ] ];
$yoga_set     = $rule( $yoga_id, [ 'recurrence_freq' => 'weekly', 'recurrence_byday' => '2,4', 'recurrence_start_date' => $day( '-60 days' ), 'recurrence_end_date' => $day( '+90 days' ), 'recurrence_start_time' => '18:00:00', 'recurrence_end_time' => '19:30:00' ] );
$rule( $yoga_id, [ 'recurrence_order' => 2, 'recurrence_type' => 'exclude', 'recurrence_freq' => 'on', 'recurrence_dates' => implode( ',', $yoga_skipped ) ] );
$cancelled_yoga = $yoga_dates[ count( $yoga_dates ) - 6 ];
foreach ( array_diff( $yoga_dates, $yoga_skipped ) as $date ) {
	$event( 'Yoga am Abend', [ 'type' => 'recurrence', 'with_post' => false, 'start_date' => $date, 'start_time' => '18:00:00', 'end_time' => '19:30:00', 'location_id' => $loc_studio, 'recurrence_set_id' => $yoga_set, 'active' => $date === $cancelled_yoga ? 0 : 1 ] );
}
$count['recurring: Yoga occurrences without posts'] = count( $yoga_dates ) - 2;

// 5. "Repeating" (the model of 5.x/6.x): a template post, one post per date.
$series = function ( string $name, array $parent, array $rule_fields, array $dates, array $options = [] ) use ( $event, $rule, $wpdb, $events_table ): int {
	[ $parent_id, $parent_post ] = $event( $name, [ 'type' => 'repeating', 'post_type' => 'event-recurring' ] + $parent );
	$set_id                      = $rule( $parent_id, $rule_fields );
	$wpdb->update( $events_table, [ 'recurrence_set_id' => $set_id ], [ 'event_id' => $parent_id ] );
	update_post_meta( $parent_post, '_recurrence_set_id', $set_id );

	$duration = (int) ( $rule_fields['recurrence_duration'] ?? 0 );
	$created  = 0;
	foreach ( $dates as $index => $date ) {
		if ( in_array( $index, $options['deleted'] ?? [], true ) ) {
			continue;
		}
		$child = [
			'type'              => 'recurrence',
			'start_date'        => $date,
			'end_date'          => ( new DateTimeImmutable( $date ) )->modify( "+$duration days" )->format( 'Y-m-d' ),
			'start_time'        => $parent['start_time'] ?? '00:00:00',
			'end_time'          => $parent['end_time'] ?? '00:00:00',
			'all_day'           => $parent['all_day'] ?? 0,
			'location_id'       => $parent['location_id'] ?? 0,
			'terms'             => $parent['terms'] ?? [],
			'recurrence_set_id' => $set_id,
			'slug'              => sanitize_title( "em-demo $name" ) . '-' . $date,
		];
		$child_name = $name;
		if ( isset( $options['retimed'][ $index ] ) ) {
			[ $child['start_time'], $child['end_time'] ] = $options['retimed'][ $index ];
		}
		if ( isset( $options['renamed'][ $index ] ) ) {
			$child_name       = $options['renamed'][ $index ];
			$child['content'] = 'Dieser Termin wurde einzeln bearbeitet und hat einen eigenen Text.';
		}
		if ( in_array( $index, $options['cancelled'] ?? [], true ) ) {
			$child['active'] = 0;
		}
		[ $child_id ] = $event( $child_name, $child );
		$wpdb->update( $events_table, [ 'recurrence_id' => $parent_id ], [ 'event_id' => $child_id ] );
		++$created;
	}
	return $created;
};

$market_dates = $monthly( $day( '-120 days' ), $day( '+180 days' ), 6, 1 );
$last         = count( $market_dates ) - 1;
$count['repeating: Bauernmarkt, first Saturday'] = $series(
	'Bauernmarkt',
	[ 'start_date' => $day( '-120 days' ), 'end_date' => $day( '+180 days' ), 'start_time' => '08:00:00', 'end_time' => '13:00:00', 'location_id' => $loc_market, 'terms' => [ 'event-categories' => [ $cat_markets ], 'event-tags' => [ $tag_outdoor ] ] ],
	[ 'recurrence_freq' => 'monthly', 'recurrence_byday' => '6', 'recurrence_byweekno' => 1, 'recurrence_start_date' => $day( '-120 days' ), 'recurrence_end_date' => $day( '+180 days' ), 'recurrence_start_time' => '08:00:00', 'recurrence_end_time' => '13:00:00' ],
	$market_dates,
	[
		'deleted'   => [ $last - 1 ],
		'retimed'   => [ $last - 2 => [ '10:00:00', '16:00:00' ] ],
		'renamed'   => [ $last - 3 => 'Bauernmarkt mit Erntedankfest' ],
		'cancelled' => [ $last ],
	]
);

$chor_dates = $weekly( $day( '-30 days' ), $day( '+75 days' ), [ 1, 5 ], 2 );
$count['repeating: Chorprobe, every 2nd week Mon + Fri'] = $series(
	'Chorprobe',
	[ 'start_date' => $day( '-30 days' ), 'end_date' => $day( '+75 days' ), 'start_time' => '19:00:00', 'end_time' => '21:00:00', 'location_id' => $loc_hall ],
	[ 'recurrence_freq' => 'weekly', 'recurrence_interval' => 2, 'recurrence_byday' => '1,5', 'recurrence_start_date' => $day( '-30 days' ), 'recurrence_end_date' => $day( '+75 days' ), 'recurrence_start_time' => '19:00:00', 'recurrence_end_time' => '21:00:00' ],
	$chor_dates
);

$year       = (int) $today->format( 'Y' );
$fest_dates = [];
for ( $y = $year - 2; $y <= $year + 2; $y++ ) {
	$fest_dates[] = "$y-06-20";
}
$count['repeating: Sommerfest, yearly, two days'] = $series(
	'Sommerfest',
	[ 'start_date' => $fest_dates[0], 'end_date' => end( $fest_dates ), 'all_day' => 1, 'location_id' => $loc_market, 'terms' => [ 'event-tags' => [ $tag_family, $tag_outdoor ] ] ],
	[ 'recurrence_freq' => 'yearly', 'recurrence_duration' => 1, 'recurrence_all_day' => 1, 'recurrence_start_date' => $fest_dates[0], 'recurrence_end_date' => end( $fest_dates ) ],
	$fest_dates
);

$daily_dates = [];
for ( $i = 0; $i < 5; $i++ ) {
	$daily_dates[] = $day( '+' . ( 50 + $i ) . ' days' );
}
$count['repeating: Ferienprogramm, daily'] = $series(
	'Ferienprogramm',
	[ 'start_date' => $daily_dates[0], 'end_date' => end( $daily_dates ), 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'location_id' => $loc_studio, 'terms' => [ 'event-tags' => [ $tag_family ] ] ],
	[ 'recurrence_freq' => 'daily', 'recurrence_start_date' => $daily_dates[0], 'recurrence_end_date' => end( $daily_dates ), 'recurrence_start_time' => '09:00:00', 'recurrence_end_time' => '12:00:00' ],
	$daily_dates
);

// 6. Damaged data.
[ , $orphan_post ] = $event( 'Zeile ohne Beitrag', [ 'start_date' => $day( '+25 days' ), 'start_time' => '15:00:00', 'end_time' => '16:00:00' ] );
$wpdb->delete( $wpdb->posts, [ 'ID' => $orphan_post ] );
$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $orphan_post ] );
[ $rowless_id ] = $event( 'Beitrag ohne Zeile', [ 'start_date' => $day( '+26 days' ), 'start_time' => '15:00:00', 'end_time' => '16:00:00', 'location_id' => $loc_hall ] );
$wpdb->delete( $events_table, [ 'event_id' => $rowless_id ] );
$event( 'Zeitfenster', [ 'type' => 'timeslot', 'with_post' => false, 'start_date' => $day( '+27 days' ), 'start_time' => '09:00:00', 'end_time' => '09:30:00' ] );
$count['damaged: row without post, post without row, timeslot row'] = 3;

/* ---------------------------------------------------------------------- */
/* A page with shortcodes of Events Manager                                */
/* ---------------------------------------------------------------------- */

$jazz_post = (int) $wpdb->get_var( "SELECT post_id FROM {$events_table} WHERE event_name = '[em-demo] Jazzabend'" );
wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => '[em-demo] Seite mit Shortcodes',
		'post_content' => implode(
			"\n\n",
			[
				'<p>Unsere nächsten Konzerte:</p>',
				// The one with a closing tag comes first: WordPress would otherwise read everything up to that closing tag as the content of the first.
				'[events_list scope="past" limit="3" orderby="event_start_date" order="DESC"]<li>#_EVENTLINK am #_EVENTDATES</li>[/events_list]',
				'[events_list scope="future" limit="5" category="' . $cat_concerts . '" pagination="1"]',
				'[events_calendar full="1" long_events="1"]',
				'[events_calendar]',
				'[event post_id="' . $jazz_post . '"]#_EVENTNAME – #_EVENTDATES[/event]',
				'[locations_list limit="10"]',
				'[locations_map width="100%" height="300"]',
				'[event_form]',
			]
		),
	]
);

/* ---------------------------------------------------------------------- */
/* Pages Events Manager fills itself, its blocks, widgets and menu items   */
/* ---------------------------------------------------------------------- */

$page = fn( string $title, string $content ): int => (int) wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => "[em-demo] $title",
		'post_content' => $content,
	]
);
$events_page = $page( 'Veranstaltungen', "<p>Alle Termine im Überblick:</p>\n\nCONTENTS" );
update_option( 'dbem_events_page', $events_page );
update_option( 'dbem_locations_page', $page( 'Veranstaltungsorte', "<!-- wp:paragraph -->\n<p>CONTENTS</p>\n<!-- /wp:paragraph -->" ) );
$page(
	'Seite mit Blöcken',
	implode(
		"\n\n",
		[
			"<!-- wp:paragraph -->\n<p>Rückblick und Kalender als Blöcke:</p>\n<!-- /wp:paragraph -->",
			'<!-- wp:events-manager/events {"title":"Rückblick","scope":"past","limit":3,"category":"' . $cat_concerts . '"} /-->',
			'<!-- wp:events-manager/calendar {"category":"' . $cat_markets . '"} /-->',
			'<!-- wp:events-manager/locations {"limit":5} /-->',
		]
	)
);
$count['pages: events page, locations page, page with blocks'] = 3;

$widgets = [
	'em_widget'           => [ 'title' => '[em-demo] Nächste Termine', 'scope' => 'future', 'order' => 'ASC', 'limit' => 4, 'category' => $cat_concerts, 'format' => '<li>#_EVENTLINK</li>', 'orderby' => 'event_start_date,event_start_time,event_name' ],
	'em_calendar'         => [ 'title' => '[em-demo] Kalender', 'long_events' => 0, 'category' => 0 ],
	'em_locations_widget' => [ 'title' => '[em-demo] Orte', 'limit' => 5 ],
	'text'                => [ 'title' => '[em-demo] Text', 'text' => 'Heute: [events_list scope="today" limit="3"]', 'filter' => true, 'visual' => true ],
];
$sidebars = (array) get_option( 'sidebars_widgets', [] );
foreach ( $widgets as $base => $instance ) {
	$instances                 = (array) get_option( 'widget_' . $base, [] );
	$instances[ $demo_widget ] = $instance;
	$instances['_multiwidget'] = 1;
	update_option( 'widget_' . $base, $instances );
	$sidebars['wp_inactive_widgets'][] = $base . '-' . $demo_widget;
}
update_option( 'sidebars_widgets', $sidebars );
$count['widgets (kept under "inactive widgets")'] = count( $widgets );

$menu_id    = (int) wp_create_nav_menu( '[em-demo] Menü' );
$child_post = (int) $wpdb->get_var( "SELECT post_id FROM {$events_table} WHERE event_name = '[em-demo] Bauernmarkt' AND event_type = 'recurrence' AND post_id > 0 ORDER BY event_id ASC LIMIT 1" );
$hall_post  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$locations_table} WHERE location_id = %d", $loc_hall ) );
$menu_items = [
	[ 'Jazzabend', 'post_type', 'event', $jazz_post ],
	[ 'Ein Markttag', 'post_type', 'event', $child_post ],
	[ 'Stadthalle', 'post_type', 'location', $hall_post ],
	[ 'Konzerte', 'taxonomy', 'event-categories', $cat_concerts ],
	[ 'Alle Termine', 'post_type_archive', 'event', 0 ],
];
foreach ( $menu_items as [ $title, $type, $object, $object_id ] ) {
	$item_id = (int) wp_update_nav_menu_item(
		$menu_id,
		0,
		[
			'menu-item-title'     => "[em-demo] $title",
			'menu-item-type'      => $type,
			'menu-item-object'    => $object,
			'menu-item-object-id' => $object_id,
			'menu-item-status'    => 'publish',
		]
	);
	// WordPress may refuse or alter targets of types it does not know; the database of a site that had the plugin has them like this.
	update_post_meta( $item_id, '_menu_item_type', $type );
	update_post_meta( $item_id, '_menu_item_object', $object );
	update_post_meta( $item_id, '_menu_item_object_id', (string) $object_id );
}
$count['menu items'] = count( $menu_items );

echo "Seeded Events Manager demo data:\n";
foreach ( $count as $label => $number ) {
	echo str_pad( (string) $number, 5, ' ', STR_PAD_LEFT ), "  $label\n";
}
echo str_pad( (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table} WHERE event_name LIKE '[em-demo]%'" ), 5, ' ', STR_PAD_LEFT ), "  rows in {$events_table}\n";
echo str_pad( (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE '[em-demo]%' AND post_type IN ('event','event-recurring')" ), 5, ' ', STR_PAD_LEFT ), "  event posts\n";
echo '' === $wpdb->last_error ? '' : "Database error: {$wpdb->last_error}\n";
