<?php
/**
 * Persists the event editor's JSON payload on save_post after nonce,
 * capability and validation checks.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Admin;

use RMD\EventsOrganizer\Capabilities;
use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Meta;
use RMD\EventsOrganizer\Modules;
use RMD\EventsOrganizer\PostTypes;
use RMD\EventsOrganizer\Repository\EventRepository;
use RMD\EventsOrganizer\Repository\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class SaveHandler {

	public const FIELD        = 'rmd_eo_data';
	public const NONCE_FIELD  = 'rmd_eo_nonce';
	public const NONCE_ACTION = 'rmd_eo_save_event_';
	public const MAX_BYTES    = 524288; // 512 KiB.

	public static function init(): void {
		add_action( 'save_post_' . PostTypes::EVENT, [ self::class, 'save' ] );
	}

	public static function save( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST[ self::FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::CAP ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$json = wp_unslash( $_POST[ self::FIELD ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is validated below.
		$raw  = is_string( $json ) && strlen( $json ) <= self::MAX_BYTES ? json_decode( $json, true, 16 ) : null;
		if ( ! is_array( $raw ) ) {
			Notices::remember(
				$post_id,
				[
					[
						'path' => 'schedule',
						'code' => 'invalid_payload',
						'args' => [],
					],
				]
			);
			return;
		}

		$validator = Meta::validator();
		$kind      = Kinds::normalize( (string) ( $raw['kind'] ?? '' ) );
		if ( Kinds::MARKET === $kind && ! Modules::is_on( Modules::MARKETS ) ) {
			$kind = Kinds::EVENT;
		}

		$schedule = $validator->normalize_schedule( $raw['schedule'] ?? [] );
		$details  = $validator->normalize_details( $raw['details'] ?? [], $kind );
		$errors   = array_merge( $schedule['errors'], $details['errors'] );

		$location_id = EventRepository::location_id( $post_id );
		if ( Modules::is_on( Modules::LOCATIONS ) ) {
			$location    = is_array( $raw['location'] ?? null ) ? $raw['location'] : [];
			$location_id = absint( $location['id'] ?? 0 );
			if ( $location_id > 0 && PostTypes::LOCATION !== get_post_type( $location_id ) ) {
				$location_id = 0;
			}

			// A location typed into the event form is created along with the event.
			$new  = is_array( $location['new'] ?? null ) ? $location['new'] : [];
			$name = sanitize_text_field( (string) ( $new['name'] ?? '' ) );
			if ( '' !== $name ) {
				$address     = $validator->normalize_address( $new );
				$location_id = LocationRepository::create( $name, $address['data'] );
				if ( 0 === $location_id ) {
					$errors[] = [
						'path' => 'location',
						'code' => 'location_failed',
						'args' => [],
					];
				}
			}
		}

		EventRepository::write(
			$post_id,
			[
				'kind'        => $kind,
				'schedule'    => $schedule['data'],
				'details'     => $details['data'],
				'location_id' => $location_id,
			]
		);

		if ( [] !== $errors ) {
			Notices::remember( $post_id, $errors );
		}
	}
}
