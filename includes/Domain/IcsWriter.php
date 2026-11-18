<?php
/**
 * Writes iCalendar (RFC 5545) text for single events.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain;

final class IcsWriter {

	/**
	 * @param array $event {
	 *     @type string $uid         Stable identifier.
	 *     @type string $summary     Title.
	 *     @type string $description Plain text.
	 *     @type string $location    Plain text.
	 *     @type string $url         Link to the event.
	 *     @type bool   $all_day
	 *     @type string $start_date  Y-m-d, used for all-day events.
	 *     @type string $end_date    Y-m-d (last day), used for all-day events.
	 *     @type int    $start_ts    Unix time, used for timed events.
	 *     @type int    $end_ts      Unix time, used for timed events.
	 *     @type bool   $cancelled
	 * }
	 * @param string $product Product identifier of the writing software.
	 * @param int    $stamp   Unix time the file is created.
	 */
	public static function calendar( array $event, string $product, int $stamp ): string {
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:' . self::escape( $product ),
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:' . self::escape( (string) ( $event['uid'] ?? '' ) ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z', $stamp ),
		];

		if ( ! empty( $event['all_day'] ) ) {
			$lines[] = 'DTSTART;VALUE=DATE:' . str_replace( '-', '', (string) $event['start_date'] );
			// The end of an all-day event is the day after its last day.
			$lines[] = 'DTEND;VALUE=DATE:' . str_replace( '-', '', Dates::add_days( (string) $event['end_date'], 1 ) );
		} else {
			$lines[] = 'DTSTART:' . gmdate( 'Ymd\THis\Z', (int) $event['start_ts'] );
			if ( (int) $event['end_ts'] > (int) $event['start_ts'] ) {
				$lines[] = 'DTEND:' . gmdate( 'Ymd\THis\Z', (int) $event['end_ts'] );
			}
		}

		$lines[] = 'SUMMARY:' . self::escape( (string) ( $event['summary'] ?? '' ) );
		foreach (
			[
				'DESCRIPTION' => 'description',
				'LOCATION'    => 'location',
			] as $property => $key
		) {
			if ( '' !== (string) ( $event[ $key ] ?? '' ) ) {
				$lines[] = $property . ':' . self::escape( (string) $event[ $key ] );
			}
		}
		if ( '' !== (string) ( $event['url'] ?? '' ) ) {
			$lines[] = 'URL:' . (string) preg_replace( '/[\r\n]+/', '', (string) $event['url'] );
		}
		$lines[] = 'STATUS:' . ( ! empty( $event['cancelled'] ) ? 'CANCELLED' : 'CONFIRMED' );
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( [ self::class, 'fold' ], $lines ) ) . "\r\n";
	}

	public static function escape( string $text ): string {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$text = str_replace( [ '\\', ';', ',' ], [ '\\\\', '\\;', '\\,' ], $text );
		return str_replace( "\n", '\\n', $text );
	}

	/**
	 * Folds a content line at 75 octets without splitting UTF-8 characters.
	 */
	public static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$parts   = [];
		$current = '';
		$limit   = 75;
		foreach ( preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $char ) {
			if ( strlen( $current ) + strlen( $char ) > $limit ) {
				$parts[] = $current;
				$current = '';
				// Continuation lines start with a space that counts towards the limit.
				$limit = 74;
			}
			$current .= $char;
		}
		$parts[] = $current;
		return implode( "\r\n ", $parts );
	}
}
