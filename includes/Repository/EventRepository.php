<?php
/**
 * Reads and writes the meta of an event in its canonical form.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Repository;

use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Domain\Validator;
use RMD\EventsOrganizer\Meta;
use RMD\EventsOrganizer\Modules;

defined( 'ABSPATH' ) || exit;

final class EventRepository {

	public static function kind( int $post_id ): string {
		$kind = Kinds::normalize( (string) get_post_meta( $post_id, Meta::KIND, true ) );
		return Kinds::MARKET === $kind && ! Modules::is_on( Modules::MARKETS ) ? Kinds::EVENT : $kind;
	}

	public static function schedule( int $post_id ): array {
		$stored = get_post_meta( $post_id, Meta::SCHEDULE, true );
		return is_array( $stored ) ? $stored + Validator::empty_schedule() : Validator::empty_schedule();
	}

	public static function details( int $post_id ): array {
		$stored = get_post_meta( $post_id, Meta::DETAILS, true );
		return ( is_array( $stored ) ? $stored : [] ) + Validator::empty_details( Kinds::MARKET );
	}

	public static function location_id( int $post_id ): int {
		return Modules::is_on( Modules::LOCATIONS ) ? (int) get_post_meta( $post_id, Meta::LOCATION, true ) : 0;
	}

	/**
	 * @param array{kind: string, schedule: array, details: array, location_id: int} $data Canonical data.
	 */
	public static function write( int $post_id, array $data ): void {
		update_post_meta( $post_id, Meta::KIND, $data['kind'] );
		update_post_meta( $post_id, Meta::SCHEDULE, wp_slash( $data['schedule'] ) );
		update_post_meta( $post_id, Meta::DETAILS, wp_slash( $data['details'] ) );
		update_post_meta( $post_id, Meta::LOCATION, (int) $data['location_id'] );
	}
}
