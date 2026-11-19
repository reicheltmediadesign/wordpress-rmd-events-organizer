<?php
/**
 * Turns the rows of a CSV file into events. One row is one date; rows with
 * the same key are the dates of one event, described by the first of them.
 *
 * Rows that cannot be used are reported with their line and left out; values
 * that cannot be used are reported and the rest of the row is kept.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain\Csv;

use RMD\EventsOrganizer\Domain\Dates;
use RMD\EventsOrganizer\Domain\Kinds;
use RMD\EventsOrganizer\Domain\Occurrence;
use RMD\EventsOrganizer\Domain\Validator;

final class CsvPlanner {

	public const STATUS_PUBLISH = 'publish';
	public const STATUS_DRAFT   = 'draft';

	private const KINDS = [
		''              => Kinds::EVENT,
		'event'         => Kinds::EVENT,
		'veranstaltung' => Kinds::EVENT,
		'market'        => Kinds::MARKET,
		'markt'         => Kinds::MARKET,
		'markttermin'   => Kinds::MARKET,
	];

	private const STATUSES = [
		''                => self::STATUS_PUBLISH,
		'publish'         => self::STATUS_PUBLISH,
		'published'       => self::STATUS_PUBLISH,
		'veroeffentlicht' => self::STATUS_PUBLISH,
		'veröffentlicht'  => self::STATUS_PUBLISH,
		'draft'           => self::STATUS_DRAFT,
		'entwurf'         => self::STATUS_DRAFT,
	];

	private const YES = [ 'ja', 'yes', 'y', 'j', '1', 'x', 'true', 'wahr' ];

	/** Detail field => column. */
	private const DETAILS = [
		'organizer_name' => 'organizer',
		'organizer_url'  => 'organizer_url',
		'info_url'       => 'info_url',
		'price_text'     => 'price',
		'stall'          => 'stall',
		'stall_note'     => 'stall_note',
	];

	/**
	 * @param list<array{line: int, cells: array<string, string>}> $rows Rows of CsvReader::parse().
	 * @return array{units: list<array<string, mixed>>, issues: list<array{line: int, code: string, arg: string, skipped: bool}>}
	 */
	public static function plan( array $rows ): array {
		$groups = [];
		foreach ( $rows as $row ) {
			$key = (string) ( $row['cells']['key'] ?? '' );
			// The prefix keeps numeric keys from becoming array indexes.
			$groups[ '' === $key ? '#' . $row['line'] : 'k:' . $key ][] = $row;
		}

		$units  = [];
		$issues = [];
		foreach ( $groups as $group ) {
			$cells = $group[0]['cells'];
			$line  = (int) $group[0]['line'];
			$code  = self::head_error( $cells );
			if ( '' !== $code ) {
				foreach ( $group as $row ) {
					$issues[] = self::issue( (int) $row['line'], $code, self::head_arg( $cells, $code ), true );
				}
				continue;
			}

			$entries = [];
			$lines   = [];
			foreach ( $group as $row ) {
				$entry = self::entry( $row['cells'] );
				if ( is_string( $entry ) ) {
					$issues[] = self::issue( (int) $row['line'], $entry, '', true );
				} elseif ( isset( $entries[ $entry['id'] ] ) ) {
					$issues[] = self::issue( (int) $row['line'], 'duplicate_date', '', true );
				} elseif ( count( $entries ) >= Validator::MAX_ENTRIES ) {
					$issues[] = self::issue( (int) $row['line'], 'too_many', (string) Validator::MAX_ENTRIES, true );
				} else {
					$entries[ $entry['id'] ] = $entry;
					$lines[]                 = (int) $row['line'];
				}
			}
			if ( [] === $entries ) {
				continue;
			}

			$details = [];
			foreach ( self::DETAILS as $field => $column ) {
				$value = (string) ( $cells[ $column ] ?? '' );
				if ( '' !== $value && Kinds::FIELD_URL === ( Kinds::fields( Kinds::MARKET )[ $field ] ?? '' ) && '' === Validator::default_sanitize_url( $value ) ) {
					$issues[] = self::issue( $line, 'invalid_url', $column, false );
					$value    = '';
				}
				$details[ $field ] = $value;
			}

			$units[] = [
				'key'         => (string) ( $cells['key'] ?? '' ),
				'lines'       => $lines,
				'kind'        => self::KINDS[ self::lower( (string) ( $cells['kind'] ?? '' ) ) ],
				'status'      => self::STATUSES[ self::lower( (string) ( $cells['status'] ?? '' ) ) ],
				'title'       => (string) $cells['title'],
				'description' => (string) ( $cells['description'] ?? '' ),
				'entries'     => array_values( $entries ),
				'location'    => [
					'name'     => (string) ( $cells['location'] ?? '' ),
					'street'   => (string) ( $cells['street'] ?? '' ),
					'postcode' => (string) ( $cells['postcode'] ?? '' ),
					'city'     => (string) ( $cells['city'] ?? '' ),
					'country'  => (string) ( $cells['country'] ?? '' ),
				],
				'categories'  => self::names( (string) ( $cells['categories'] ?? '' ) ),
				'tags'        => self::names( (string) ( $cells['tags'] ?? '' ) ),
				'details'     => $details,
				'image'       => (string) ( $cells['image'] ?? '' ),
				'language'    => self::lower( (string) ( $cells['language'] ?? '' ) ),
			];
		}

		usort( $issues, static fn( array $a, array $b ): int => $a['line'] <=> $b['line'] );
		return [
			'units'  => $units,
			'issues' => $issues,
		];
	}

	/**
	 * "2026-12-05", "5.12.2026" or "05.12.26" as "2026-12-05"; '' when it is no date.
	 */
	public static function date( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m ) ) {
			$date = sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] );
		} elseif ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4}|\d{2})$/', $value, $m ) ) {
			$date = sprintf( '%04d-%02d-%02d', 2 === strlen( $m[3] ) ? 2000 + (int) $m[3] : $m[3], $m[2], $m[1] );
		} else {
			return '';
		}
		return Dates::is_valid( $date ) ? $date : '';
	}

	/**
	 * "9", "9:30", "09.30", "18:00:00" or "18 Uhr" as "HH:MM"; '' when empty,
	 * null when it is no time.
	 */
	public static function time( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/^(\d{1,2})(?:[:.](\d{2}))?(?::\d{2})?(?:\s*uhr)?$/i', $value, $m ) ) {
			return null;
		}
		$time = sprintf( '%02d:%02d', $m[1], $m[2] ?? 0 );
		return Dates::is_valid_time( $time ) ? $time : null;
	}

	/**
	 * What keeps the first row of an event from describing it, or ''.
	 *
	 * @param array<string, string> $cells
	 */
	private static function head_error( array $cells ): string {
		if ( '' === (string) ( $cells['title'] ?? '' ) ) {
			return 'no_title';
		}
		if ( ! isset( self::KINDS[ self::lower( (string) ( $cells['kind'] ?? '' ) ) ] ) ) {
			return 'invalid_kind';
		}
		if ( ! isset( self::STATUSES[ self::lower( (string) ( $cells['status'] ?? '' ) ) ] ) ) {
			return 'invalid_status';
		}
		return '';
	}

	/**
	 * @param array<string, string> $cells
	 */
	private static function head_arg( array $cells, string $code ): string {
		if ( 'invalid_kind' === $code ) {
			return (string) ( $cells['kind'] ?? '' );
		}
		return 'invalid_status' === $code ? (string) ( $cells['status'] ?? '' ) : '';
	}

	/**
	 * The date of a row as an entry of a schedule, or the code of what is wrong with it.
	 *
	 * @param array<string, string> $cells
	 * @return array<string, mixed>|string
	 */
	private static function entry( array $cells ) {
		if ( '' === (string) ( $cells['date'] ?? '' ) ) {
			return 'no_date';
		}
		$start_date = self::date( (string) $cells['date'] );
		if ( '' === $start_date ) {
			return 'invalid_date';
		}
		$end_date = $start_date;
		if ( '' !== (string) ( $cells['end_date'] ?? '' ) ) {
			$end_date = self::date( (string) $cells['end_date'] );
			if ( '' === $end_date ) {
				return 'invalid_end_date';
			}
			if ( $end_date < $start_date ) {
				return 'end_before_start';
			}
		}

		$start = self::time( (string) ( $cells['start'] ?? '' ) );
		$end   = self::time( (string) ( $cells['end'] ?? '' ) );
		if ( null === $start || null === $end ) {
			return 'invalid_time';
		}
		if ( '' === $start && '' !== $end ) {
			return 'end_without_start';
		}

		$daily = $end_date > $start_date && in_array( self::lower( (string) ( $cells['daily_hours'] ?? '' ) ), self::YES, true );
		if ( $daily && '' !== $start && ( '' === $end || $end <= $start ) ) {
			return 'daily_needs_end';
		}

		return [
			// The same date keeps its id when the file is imported again.
			'id'         => substr( md5( $start_date . '|' . $start ), 0, 8 ),
			'type'       => 'single',
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'all_day'    => '' === $start,
			'start_time' => $start,
			'end_time'   => $end,
			'time_mode'  => $daily && '' !== $start ? Occurrence::MODE_DAILY : Occurrence::MODE_CONTINUOUS,
		];
	}

	/**
	 * @return list<string> Names separated by commas, without duplicates.
	 */
	private static function names( string $value ): array {
		$names = [];
		foreach ( explode( ',', $value ) as $name ) {
			$name = trim( $name );
			if ( '' !== $name && ! isset( $names[ self::lower( $name ) ] ) ) {
				$names[ self::lower( $name ) ] = $name;
			}
		}
		return array_values( $names );
	}

	private static function lower( string $value ): string {
		$value = trim( $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * @return array{line: int, code: string, arg: string, skipped: bool}
	 */
	private static function issue( int $line, string $code, string $arg, bool $skipped ): array {
		return [
			'line'    => $line,
			'code'    => $code,
			'arg'     => $arg,
			'skipped' => $skipped,
		];
	}
}
