<?php
/**
 * Keeps the occurrences index in step with the schedule stored on each event.
 *
 * Rows are matched by their key, so an occurrence keeps its id when its time
 * changes. A row that is no longer part of the schedule is deleted unless the
 * rmd_eo_can_delete_occurrence filter objects; then it is kept and marked as
 * removed.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Sync;

use RMD\EventsOrganizer\Cache;
use RMD\EventsOrganizer\Clock;
use RMD\EventsOrganizer\Domain\Occurrence;
use RMD\EventsOrganizer\Domain\OccurrenceDiff;
use RMD\EventsOrganizer\Domain\Schedule;
use RMD\EventsOrganizer\PostTypes;
use RMD\EventsOrganizer\Repository\EventRepository;
use RMD\EventsOrganizer\Repository\OccurrenceRepository;
use RMD\EventsOrganizer\Settings;

defined( 'ABSPATH' ) || exit;

final class OccurrenceSync {

	public const RESYNC_HOOK = 'rmd_eo_resync';

	private const SKIPPED_STATUSES = [ 'auto-draft', 'inherit' ];

	public static function init(): void {
		// After Admin\SaveHandler has written the meta.
		add_action( 'save_post_' . PostTypes::EVENT, [ self::class, 'sync' ], 20 );
		add_action( 'before_delete_post', [ self::class, 'on_delete' ] );

		// Open-ended rules move forward with time.
		add_action( Cache::DAILY_HOOK, [ self::class, 'sync_all' ], 5 );
		add_action( self::RESYNC_HOOK, [ self::class, 'sync_all' ] );

		// UTC values and the horizon depend on these settings.
		add_action( 'update_option_timezone_string', [ self::class, 'schedule_resync' ] );
		add_action( 'update_option_gmt_offset', [ self::class, 'schedule_resync' ] );
		add_action( 'update_option_' . Settings::OPTION, [ self::class, 'schedule_resync' ], 20 );
	}

	public static function sync( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || PostTypes::EVENT !== $post->post_type || in_array( $post->post_status, self::SKIPPED_STATUSES, true ) ) {
			return;
		}

		$details   = EventRepository::details( $post_id );
		$cancelled = Occurrence::STATUS_CANCELLED === $details['status'];
		$shared    = [
			'event_id'    => $post_id,
			'kind'        => EventRepository::kind( $post_id ),
			'post_status' => (string) $post->post_status,
			'location_id' => EventRepository::location_id( $post_id ),
		];

		$desired = [];
		foreach ( Schedule::expand( EventRepository::schedule( $post_id ), self::horizon(), wp_timezone_string() ) as $occurrence ) {
			$row = $occurrence->to_row() + $shared;
			if ( $cancelled ) {
				$row['status'] = Occurrence::STATUS_CANCELLED;
			}
			$desired[] = $row;
		}

		$diff = OccurrenceDiff::compute( $desired, OccurrenceRepository::for_event( $post_id ) );

		foreach ( $diff['insert'] as $row ) {
			OccurrenceRepository::insert( $row );
		}
		foreach ( $diff['update'] as $id => $changes ) {
			OccurrenceRepository::update( (int) $id, $changes );
		}
		foreach ( $diff['delete'] as $id ) {
			/**
			 * Filters whether an occurrence that left the schedule may be
			 * deleted. Return false when other data still refers to it.
			 *
			 * @param bool $can_delete    Default true.
			 * @param int  $occurrence_id Row id.
			 * @param int  $post_id       Event id.
			 */
			if ( apply_filters( 'rmd_eo_can_delete_occurrence', true, (int) $id, $post_id ) ) {
				OccurrenceRepository::delete( (int) $id );
			} else {
				OccurrenceRepository::update( (int) $id, [ 'status' => Occurrence::STATUS_REMOVED ] );
			}
		}

		/**
		 * Fires after the occurrences of an event were brought up to date.
		 *
		 * @param int   $post_id Event id.
		 * @param array $diff    What changed: insert, update, delete.
		 */
		do_action( 'rmd_eo_occurrences_synced', $post_id, $diff );
	}

	public static function on_delete( int $post_id ): void {
		if ( PostTypes::EVENT === get_post_type( $post_id ) ) {
			OccurrenceRepository::delete_for_event( $post_id );
		}
	}

	public static function sync_all(): void {
		$page = 1;
		do {
			$ids = get_posts(
				[
					'post_type'      => PostTypes::EVENT,
					'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private', 'trash' ],
					'posts_per_page' => 100,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
					// Polylang would otherwise limit this to one language.
					'lang'           => '',
				]
			);
			foreach ( $ids as $id ) {
				self::sync( (int) $id );
			}
			++$page;
			$found = count( $ids );
		} while ( 100 === $found );
	}

	public static function schedule_resync(): void {
		if ( ! wp_next_scheduled( self::RESYNC_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::RESYNC_HOOK );
		}
	}

	/**
	 * Last start date generated for rules without an end.
	 */
	public static function horizon(): string {
		$months = (int) ( ( (array) Settings::get( 'calendar', [] ) )['horizon_months'] ?? 24 );
		return Clock::now()->modify( sprintf( '+%d months', max( 1, $months ) ) )->format( 'Y-m-d' );
	}
}
