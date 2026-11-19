<?php
/**
 * Single source of truth for the shape of stored event data. Every write path
 * (meta box, meta sanitizers, migration) runs through here. Input is
 * untrusted; output is a canonical array plus a list of field errors.
 *
 * Errors are returned as ['path' => 'entries.2.end_date', 'code' =>
 * 'end_before_start', 'args' => []] and translated into messages by the
 * WordPress layer.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain;

final class Validator {

	public const SCHEDULE_VERSION = 1;

	public const MAX_ENTRIES    = 300;
	public const MAX_EXCLUSIONS = 500;
	public const MAX_OVERRIDES  = 500;
	public const MAX_INTERVAL   = 99;
	public const MAX_COUNT      = 500;
	public const MAX_DURATION   = 31;
	public const MAX_TEXT       = 200;
	public const MAX_NOTE       = 255;
	public const MAX_URL        = 500;

	public const NTH_VALUES = [ -1, 1, 2, 3, 4, 5 ];

	public const EVENT_STATUSES = [ Occurrence::STATUS_SCHEDULED, Occurrence::STATUS_CANCELLED, Occurrence::STATUS_WITHDRAWN ];

	public const ADDRESS_FIELDS = [ 'street', 'postcode', 'city', 'region', 'country' ];

	/** @var callable(string): string */
	private $sanitize_text;

	/** @var callable(string): string */
	private $sanitize_url;

	/** @var callable(): string */
	private $new_id;

	/** @var list<array{path: string, code: string, args: array}> */
	private array $errors = [];

	/**
	 * @param callable|null $sanitize_text Cleans a single-line string (default: strip tags, trim).
	 * @param callable|null $sanitize_url  Cleans a URL (default: http(s) only).
	 * @param callable|null $new_id        Returns a new entry id.
	 */
	public function __construct( ?callable $sanitize_text = null, ?callable $sanitize_url = null, ?callable $new_id = null ) {
		$this->sanitize_text = $sanitize_text ?? [ self::class, 'default_sanitize_text' ];
		$this->sanitize_url  = $sanitize_url ?? [ self::class, 'default_sanitize_url' ];
		$this->new_id        = $new_id ?? [ self::class, 'default_id' ];
	}

	public static function default_sanitize_text( string $value ): string {
		$value = strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- The domain layer must not depend on WordPress.
		$value = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
	}

	public static function default_sanitize_url( string $value ): string {
		$value = trim( $value );
		return preg_match( '#^https?://[^\s<>"\']+$#i', $value ) ? $value : '';
	}

	public static function default_id(): string {
		return bin2hex( random_bytes( 4 ) );
	}

	public static function is_valid_id( string $id ): bool {
		return (bool) preg_match( '/^[a-z0-9]{4,16}$/', $id );
	}

	/* ------------------------------------------------------------------ */
	/* Defaults                                                            */
	/* ------------------------------------------------------------------ */

	public static function empty_schedule(): array {
		return [
			'version'    => self::SCHEDULE_VERSION,
			'entries'    => [],
			'exclusions' => [],
			'overrides'  => [],
		];
	}

	public static function empty_details( string $kind ): array {
		$details = [ 'status' => Occurrence::STATUS_SCHEDULED ];
		foreach ( array_keys( Kinds::fields( $kind ) ) as $field ) {
			$details[ $field ] = '';
		}
		return $details;
	}

	public static function empty_address(): array {
		return array_fill_keys( self::ADDRESS_FIELDS, '' ) + [
			'lat' => '',
			'lng' => '',
			'url' => '',
		];
	}

	/* ------------------------------------------------------------------ */
	/* Schedule                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * @param mixed $raw
	 * @return array{data: array, errors: list<array{path: string, code: string, args: array}>}
	 */
	public function normalize_schedule( $raw ): array {
		$this->errors = [];
		$raw          = is_array( $raw ) ? $raw : [];
		$data         = self::empty_schedule();
		$ids          = [];

		$entries = array_values( (array) ( $raw['entries'] ?? [] ) );
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$this->error( 'entries', 'too_many', [ self::MAX_ENTRIES ] );
			$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
		}
		foreach ( $entries as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$path  = 'entries.' . $index;
			$clean = 'rule' === ( $entry['type'] ?? '' ) ? $this->rule( $entry, $path ) : $this->single( $entry, $path );
			if ( null === $clean ) {
				continue;
			}

			$id = (string) ( $entry['id'] ?? '' );
			while ( ! self::is_valid_id( $id ) || isset( $ids[ $id ] ) ) {
				$id = (string) call_user_func( $this->new_id );
			}
			$ids[ $id ]        = true;
			$data['entries'][] = [ 'id' => $id ] + $clean;
		}

		$data['exclusions'] = $this->exclusions( (array) ( $raw['exclusions'] ?? [] ) );
		$data['overrides']  = $this->overrides( (array) ( $raw['overrides'] ?? [] ), $ids );

		return [
			'data'   => $data,
			'errors' => $this->errors,
		];
	}

	private function single( array $entry, string $path ): ?array {
		$start = (string) ( $entry['start_date'] ?? '' );
		if ( ! Dates::is_valid( $start ) ) {
			$this->error( $path . '.start_date', 'invalid_date' );
			return null;
		}
		$end = (string) ( $entry['end_date'] ?? '' );
		if ( '' === $end ) {
			$end = $start;
		} elseif ( ! Dates::is_valid( $end ) ) {
			$this->error( $path . '.end_date', 'invalid_date' );
			$end = $start;
		} elseif ( $end < $start ) {
			$this->error( $path . '.end_date', 'end_before_start' );
			$end = $start;
		}

		return [
			'type'       => 'single',
			'start_date' => $start,
			'end_date'   => $end,
		] + $this->times( $entry, $path, $end > $start );
	}

	private function rule( array $entry, string $path ): ?array {
		$freq = (string) ( $entry['freq'] ?? '' );
		if ( ! in_array( $freq, RecurrenceExpander::FREQUENCIES, true ) ) {
			$this->error( $path . '.freq', 'invalid_choice' );
			return null;
		}
		$from = (string) ( $entry['from'] ?? '' );
		if ( ! Dates::is_valid( $from ) ) {
			$this->error( $path . '.from', 'invalid_date' );
			return null;
		}
		$until = (string) ( $entry['until'] ?? '' );
		if ( '' !== $until && ! Dates::is_valid( $until ) ) {
			$this->error( $path . '.until', 'invalid_date' );
			$until = '';
		} elseif ( '' !== $until && $until < $from ) {
			$this->error( $path . '.until', 'end_before_start' );
			$until = '';
		}

		$weekdays = array_values( array_intersect( Dates::WEEKDAYS, array_map( 'strval', (array) ( $entry['weekdays'] ?? [] ) ) ) );
		$nth      = (int) ( $entry['nth'] ?? 0 );
		$duration = $this->int_between( $entry['duration_days'] ?? 1, 1, self::MAX_DURATION );

		return [
			'type'          => 'rule',
			'freq'          => $freq,
			'interval'      => $this->int_between( $entry['interval'] ?? 1, 1, self::MAX_INTERVAL ),
			'weekdays'      => $weekdays,
			'month_mode'    => 'nth_weekday' === ( $entry['month_mode'] ?? '' ) ? 'nth_weekday' : 'day_of_month',
			'nth'           => in_array( $nth, self::NTH_VALUES, true ) ? $nth : 0,
			'from'          => $from,
			'until'         => $until,
			'count'         => $this->int_between( $entry['count'] ?? 0, 0, self::MAX_COUNT ),
			'duration_days' => $duration,
		] + $this->times( $entry, $path, $duration > 1 );
	}

	/**
	 * Time fields shared by both entry types.
	 */
	private function times( array $entry, string $path, bool $multi_day ): array {
		$start = (string) ( $entry['start_time'] ?? '' );
		$end   = (string) ( $entry['end_time'] ?? '' );

		if ( '' !== $start && ! Dates::is_valid_time( $start ) ) {
			$this->error( $path . '.start_time', 'invalid_time' );
			$start = '';
		}
		if ( '' !== $end && ! Dates::is_valid_time( $end ) ) {
			$this->error( $path . '.end_time', 'invalid_time' );
			$end = '';
		}

		// Without a start time the entry covers whole days.
		$all_day = ! empty( $entry['all_day'] ) || '' === $start;
		if ( $all_day ) {
			return [
				'all_day'    => true,
				'start_time' => '',
				'end_time'   => '',
				'time_mode'  => Occurrence::MODE_CONTINUOUS,
			];
		}

		$mode = Occurrence::MODE_DAILY === ( $entry['time_mode'] ?? '' ) ? Occurrence::MODE_DAILY : Occurrence::MODE_CONTINUOUS;
		if ( Occurrence::MODE_DAILY === $mode ) {
			if ( ! $multi_day ) {
				$mode = Occurrence::MODE_CONTINUOUS;
			} elseif ( '' === $end || $end <= $start ) {
				$this->error( $path . '.end_time', 'daily_needs_end' );
				$mode = Occurrence::MODE_CONTINUOUS;
			}
		}

		return [
			'all_day'    => false,
			'start_time' => $start,
			'end_time'   => $end,
			'time_mode'  => $mode,
		];
	}

	/**
	 * @return list<array{from: string, to: string}>
	 */
	private function exclusions( array $raw ): array {
		$clean = [];
		foreach ( array_values( $raw ) as $index => $item ) {
			if ( count( $clean ) >= self::MAX_EXCLUSIONS ) {
				$this->error( 'exclusions', 'too_many', [ self::MAX_EXCLUSIONS ] );
				break;
			}
			if ( is_string( $item ) ) {
				$item = [ 'date' => $item ];
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$from = (string) ( $item['from'] ?? $item['date'] ?? '' );
			$to   = (string) ( $item['to'] ?? $from );
			if ( '' === $to ) {
				$to = $from;
			}
			if ( ! Dates::is_valid( $from ) || ! Dates::is_valid( $to ) ) {
				$this->error( 'exclusions.' . $index, 'invalid_date' );
				continue;
			}
			if ( $to < $from ) {
				[ $from, $to ] = [ $to, $from ];
			}
			$clean[ $from . '|' . $to ] = [
				'from' => $from,
				'to'   => $to,
			];
		}
		ksort( $clean );
		return array_values( $clean );
	}

	/**
	 * @param array<string, bool> $ids Entry ids that exist.
	 * @return array<string, array{status: string, start_time: string, end_time: string, note: string}>
	 */
	private function overrides( array $raw, array $ids ): array {
		$clean = [];
		foreach ( $raw as $key => $item ) {
			$key = (string) $key;
			if ( ! is_array( $item ) || ! isset( $ids[ Schedule::entry_id( $key ) ] ) ) {
				continue;
			}
			if ( false !== strpos( $key, ':' ) && ! Dates::is_valid( substr( $key, strpos( $key, ':' ) + 1 ) ) ) {
				continue;
			}
			if ( count( $clean ) >= self::MAX_OVERRIDES ) {
				$this->error( 'overrides', 'too_many', [ self::MAX_OVERRIDES ] );
				break;
			}

			$start = (string) ( $item['start_time'] ?? '' );
			$end   = (string) ( $item['end_time'] ?? '' );
			$entry = [
				'status'     => in_array( $item['status'] ?? '', self::EVENT_STATUSES, true ) ? (string) $item['status'] : Occurrence::STATUS_SCHEDULED,
				'start_time' => Dates::is_valid_time( $start ) ? $start : '',
				'end_time'   => Dates::is_valid_time( $end ) ? $end : '',
				'note'       => $this->text( $item['note'] ?? '', self::MAX_NOTE ),
			];
			if ( Occurrence::STATUS_SCHEDULED === $entry['status'] && '' === $entry['start_time'] && '' === $entry['end_time'] && '' === $entry['note'] ) {
				continue;
			}
			$clean[ $key ] = $entry;
		}
		ksort( $clean );
		return $clean;
	}

	/* ------------------------------------------------------------------ */
	/* Details and address                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * @param mixed $raw
	 * @return array{data: array, errors: list<array{path: string, code: string, args: array}>}
	 */
	public function normalize_details( $raw, string $kind ): array {
		$this->errors = [];
		$raw          = is_array( $raw ) ? $raw : [];
		$data         = self::empty_details( $kind );

		if ( in_array( $raw['status'] ?? '', self::EVENT_STATUSES, true ) ) {
			$data['status'] = (string) $raw['status'];
		}
		// Only a market can take place without us.
		if ( Occurrence::STATUS_WITHDRAWN === $data['status'] && Kinds::MARKET !== Kinds::normalize( $kind ) ) {
			$data['status'] = Occurrence::STATUS_SCHEDULED;
		}
		foreach ( Kinds::fields( $kind ) as $field => $type ) {
			$data[ $field ] = Kinds::FIELD_URL === $type
				? $this->url( $raw[ $field ] ?? '', 'details.' . $field )
				: $this->text( $raw[ $field ] ?? '', self::MAX_TEXT );
		}

		return [
			'data'   => $data,
			'errors' => $this->errors,
		];
	}

	/**
	 * @param mixed $raw
	 * @return array{data: array, errors: list<array{path: string, code: string, args: array}>}
	 */
	public function normalize_address( $raw ): array {
		$this->errors = [];
		$raw          = is_array( $raw ) ? $raw : [];
		$data         = self::empty_address();

		foreach ( self::ADDRESS_FIELDS as $field ) {
			$data[ $field ] = $this->text( $raw[ $field ] ?? '', self::MAX_TEXT );
		}
		$data['country'] = strtoupper( substr( $data['country'], 0, 2 ) );
		$data['lat']     = $this->coordinate( $raw['lat'] ?? '', 90.0 );
		$data['lng']     = $this->coordinate( $raw['lng'] ?? '', 180.0 );
		if ( '' === $data['lat'] || '' === $data['lng'] ) {
			$data['lat'] = '';
			$data['lng'] = '';
		}
		$data['url'] = $this->url( $raw['url'] ?? '', 'address.url' );

		return [
			'data'   => $data,
			'errors' => $this->errors,
		];
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @param mixed $value
	 */
	private function text( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$clean = (string) call_user_func( $this->sanitize_text, (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $clean, 0, $max ) : substr( $clean, 0, $max );
	}

	/**
	 * @param mixed $value
	 */
	private function url( $value, string $path ): string {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return '';
		}
		$clean = (string) call_user_func( $this->sanitize_url, trim( (string) $value ) );
		if ( '' === $clean || strlen( $clean ) > self::MAX_URL ) {
			$this->error( $path, 'invalid_url' );
			return '';
		}
		return $clean;
	}

	/**
	 * @param mixed $value
	 */
	private function coordinate( $value, float $limit ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = str_replace( ',', '.', trim( (string) $value ) );
		if ( ! is_numeric( $value ) || abs( (float) $value ) > $limit ) {
			return '';
		}
		return rtrim( rtrim( number_format( (float) $value, 6, '.', '' ), '0' ), '.' );
	}

	/**
	 * @param mixed $value
	 */
	private function int_between( $value, int $min, int $max ): int {
		return max( $min, min( $max, is_numeric( $value ) ? (int) $value : $min ) );
	}

	private function error( string $path, string $code, array $args = [] ): void {
		$this->errors[] = [
			'path' => $path,
			'code' => $code,
			'args' => $args,
		];
	}
}
