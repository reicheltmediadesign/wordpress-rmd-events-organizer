<?php
/**
 * Works out what an import from Events Manager would create, without writing
 * anything. The result drives both the preview ("dry run") and the import.
 *
 * One unit of the plan becomes one event here:
 * - a single event,
 * - a series with all its dates (rules, exclusions and changed dates),
 * - a date of a series that was edited on its own.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Migration;

use RMD\EventsOrganizer\Domain\Em\EventMapper;
use RMD\EventsOrganizer\Domain\Em\SeriesCollapser;
use RMD\EventsOrganizer\Domain\Validator;

defined( 'ABSPATH' ) || exit;

final class Planner {

	/**
	 * @return array{
	 *     units: list<array<string, mixed>>,
	 *     counts: array<string, int>,
	 *     notes: list<array{code: string, label: string}>
	 * }
	 */
	public static function plan(): array {
		$events    = EmReader::events();
		$by_id     = array_column( $events, null, 'id' );
		$timezone  = wp_timezone_string();
		$units     = [];
		$notes     = [];
		$counts    = [
			'single'     => 0,
			'series'     => 0,
			'dates'      => 0,
			'standalone' => 0,
			'trash'      => 0,
			'deleted'    => 0,
			'timeslots'  => 0,
			'recovered'  => 0,
			'mismatches' => 0,
		];
		$rules_for = [];
		foreach ( EmReader::rules() as $rule ) {
			$rules_for[ $rule['event_id'] ][] = $rule;
		}
		$children_of = [];
		foreach ( $events as $event ) {
			if ( 'recurrence' === $event['type'] ) {
				$children_of[ $event['parent_id'] ][] = $event;
			}
		}

		foreach ( $events as $event ) {
			if ( 'timeslot' === $event['type'] ) {
				++$counts['timeslots'];
				continue;
			}
			if ( 'recurrence' === $event['type'] ) {
				if ( ! isset( $by_id[ $event['parent_id'] ] ) ) {
					// The series is gone; keep the date as an event of its own.
					$unit = self::single( $event, $timezone );
					if ( null !== $unit ) {
						$units[] = $unit;
						++$counts['single'];
					}
				}
				continue;
			}

			$post = $event['post_id'] > 0 ? get_post( $event['post_id'] ) : null;
			if ( ( $post && 'trash' === $post->post_status ) || ( ! $post && -1 === $event['status'] ) ) {
				++$counts['trash'];
				continue;
			}
			if ( ! $post && $event['post_id'] > 0 ) {
				// The post was deleted for good; Events Manager leaves the row behind and does not show it either.
				++$counts['deleted'];
				continue;
			}

			if ( in_array( $event['type'], [ 'repeating', 'recurring' ], true ) ) {
				$children = [];
				foreach ( $children_of[ $event['id'] ] ?? [] as $child ) {
					$child_post = $child['post_id'] > 0 ? get_post( $child['post_id'] ) : null;
					if ( 1 !== $child['status'] || ( $child_post && 'trash' === $child_post->post_status ) || ( $child['post_id'] > 0 && ! $child_post ) ) {
						// Deleted or unpublished dates are left out of the series.
						continue;
					}
					$children[] = [
						'id'         => $child['id'],
						'start_date' => $child['start_date'],
						'end_date'   => $child['end_date'],
						'start_time' => $child['start_time'],
						'end_time'   => $child['end_time'],
						'all_day'    => $child['all_day'],
						'cancelled'  => ! $child['active'],
						'standalone' => $post && $child_post && self::was_edited( $child_post, $post ),
						'slug'       => $child_post ? (string) $child_post->post_name : '',
					];
				}

				$result = SeriesCollapser::collapse( $rules_for[ $event['id'] ] ?? [], $children, $timezone );
				$unit   = self::base( $event, $post );

				$unit['schedule']   = $result['schedule'];
				$unit['series']     = true;
				$unit['dates']      = count( $children ) - count( $result['standalone'] );
				$unit['mismatches'] = $result['mismatches'];
				$unit['warnings']   = array_merge( $unit['warnings'], $result['warnings'] );
				foreach ( $children as $child ) {
					if ( '' !== $child['slug'] && ! in_array( $child['id'], $result['standalone'], true ) ) {
						$unit['old_slugs'][] = $child['slug'];
					}
				}
				$units[] = $unit;
				++$counts['series'];
				$counts['dates']      += $unit['dates'];
				$counts['mismatches'] += count( $result['mismatches'] );

				foreach ( $result['standalone'] as $child_id ) {
					$standalone = self::single( $by_id[ $child_id ], $timezone );
					if ( null !== $standalone ) {
						$standalone['warnings'][] = 'edited_date';
						$units[]                  = $standalone;
						++$counts['standalone'];
					}
				}
				continue;
			}

			$unit = self::single( $event, $timezone );
			if ( null === $unit ) {
				$notes[] = [
					'code'  => 'no_date',
					'label' => $event['name'],
				];
				continue;
			}
			$units[] = $unit;
			++$counts['single'];
			if ( ! $post ) {
				++$counts['recovered'];
			}
		}

		// Posts whose table row is missing still carry their dates as post meta.
		foreach ( EmReader::posts_without_row() as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$event = [
				'id'          => 0,
				'post_id'     => $post_id,
				'type'        => 'single',
				'status'      => 1,
				'private'     => false,
				'active'      => '0' !== (string) get_post_meta( $post_id, '_event_active_status', true ),
				'name'        => $post->post_title,
				'slug'        => $post->post_name,
				'content'     => $post->post_content,
				'owner'       => (int) $post->post_author,
				'start_date'  => (string) get_post_meta( $post_id, '_event_start_date', true ),
				'end_date'    => (string) get_post_meta( $post_id, '_event_end_date', true ),
				'start_time'  => (string) get_post_meta( $post_id, '_event_start_time', true ),
				'end_time'    => (string) get_post_meta( $post_id, '_event_end_time', true ),
				'all_day'     => (bool) get_post_meta( $post_id, '_event_all_day', true ),
				'timezone'    => (string) get_post_meta( $post_id, '_event_timezone', true ),
				'location_id' => (int) get_post_meta( $post_id, '_location_id', true ),
			];
			$unit  = self::single( $event, $timezone );
			if ( null === $unit ) {
				$notes[] = [
					'code'  => 'no_date',
					'label' => $post->post_title,
				];
				continue;
			}
			$unit['warnings'][] = 'row_missing';
			$units[]            = $unit;
			++$counts['single'];
			++$counts['recovered'];
		}

		return [
			'units'  => $units,
			'counts' => $counts,
			'notes'  => $notes,
		];
	}

	/**
	 * @param array<string, mixed> $event Row from EmReader::events().
	 * @return array<string, mixed>|null Null when the event has no usable date.
	 */
	private static function single( array $event, string $timezone ): ?array {
		$dates = EventMapper::dates( $event );
		if ( null === $dates ) {
			return null;
		}
		$post = $event['post_id'] > 0 ? get_post( $event['post_id'] ) : null;
		$unit = self::base( $event, $post );

		$unit['schedule'] = ( new Validator() )->normalize_schedule(
			[
				'entries' => [
					[
						'id'   => $event['id'] > 0 ? 'emc' . $event['id'] : 'emp' . $event['post_id'],
						'type' => 'single',
					] + $dates,
				],
			]
		)['data'];
		$unit['dates']    = 1;

		if ( '' !== $event['timezone'] && $event['timezone'] !== $timezone ) {
			$unit['warnings'][] = 'other_timezone';
		}
		return $unit;
	}

	/**
	 * Fields every unit has; text comes from the post when it still exists.
	 *
	 * @param array<string, mixed> $event
	 * @return array<string, mixed>
	 */
	private static function base( array $event, ?\WP_Post $post ): array {
		$warnings = [];
		if ( $post ) {
			$status = in_array( $post->post_status, [ 'publish', 'private', 'draft', 'pending', 'future' ], true ) ? $post->post_status : 'draft';
		} else {
			// Without its post nobody has seen this event for a while; let a person decide.
			$status     = 'draft';
			$warnings[] = 'post_missing';
		}

		return [
			'source_id'   => (int) $event['id'],
			'source_post' => $post ? (int) $post->ID : 0,
			'title'       => $post ? $post->post_title : $event['name'],
			'content'     => $post ? $post->post_content : $event['content'],
			'excerpt'     => $post ? $post->post_excerpt : '',
			'slug'        => $post ? $post->post_name : $event['slug'],
			'status'      => $status,
			'author'      => $post ? (int) $post->post_author : (int) $event['owner'],
			'date'        => $post ? $post->post_date : '',
			'cancelled'   => ! $event['active'],
			'location'    => (int) $event['location_id'],
			'schedule'    => Validator::empty_schedule(),
			'series'      => false,
			'dates'       => 0,
			'old_slugs'   => [],
			'mismatches'  => [],
			'warnings'    => $warnings,
		];
	}

	/**
	 * A date counts as edited on its own when its title or text no longer
	 * match the series it came from.
	 */
	private static function was_edited( \WP_Post $child, \WP_Post $series ): bool {
		return trim( $child->post_title ) !== trim( $series->post_title ) || trim( $child->post_content ) !== trim( $series->post_content );
	}
}
