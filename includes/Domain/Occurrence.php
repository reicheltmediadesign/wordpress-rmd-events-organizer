<?php
/**
 * One concrete date of an event, as stored in the occurrences index.
 *
 * Local date and time are the truth; the UTC values are derived for ordering,
 * "is it over" checks and calendar files.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain;

final class Occurrence {

	public const STATUS_SCHEDULED = 'scheduled';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_REMOVED   = 'removed';

	public const MODE_CONTINUOUS = 'continuous';
	public const MODE_DAILY      = 'daily';

	public function __construct(
		public readonly string $key,
		public readonly string $start_date,
		public readonly string $end_date,
		public readonly string $start_time,
		public readonly string $end_time,
		public readonly bool $all_day,
		public readonly string $time_mode,
		public readonly string $status,
		public readonly string $note,
		public readonly string $start_utc,
		public readonly string $end_utc,
		public readonly string $timezone
	) {
	}

	/**
	 * Builds an occurrence that starts on $start_date from the time fields of
	 * a schedule entry.
	 *
	 * @param array $entry    Canonical entry; only the time fields are read.
	 * @param array $override Optional per-date changes (status, times, note).
	 */
	public static function build( string $key, string $start_date, string $end_date, array $entry, string $timezone, array $override = [] ): self {
		$all_day    = ! empty( $entry['all_day'] );
		$time_mode  = self::MODE_DAILY === ( $entry['time_mode'] ?? '' ) ? self::MODE_DAILY : self::MODE_CONTINUOUS;
		$start_time = $all_day ? '' : (string) ( $entry['start_time'] ?? '' );
		$end_time   = $all_day ? '' : (string) ( $entry['end_time'] ?? '' );

		if ( ! $all_day ) {
			if ( '' !== ( $override['start_time'] ?? '' ) ) {
				$start_time = (string) $override['start_time'];
			}
			if ( '' !== ( $override['end_time'] ?? '' ) ) {
				$end_time = (string) $override['end_time'];
			}
		}

		// "20:00 – 01:00" on a single day ends on the following morning.
		if ( ! $all_day && self::MODE_CONTINUOUS === $time_mode && $end_date === $start_date && '' !== $end_time && $end_time < $start_time ) {
			$end_date = Dates::add_days( $end_date, 1 );
		}
		if ( $start_date === $end_date ) {
			$time_mode = self::MODE_CONTINUOUS;
		}

		if ( $all_day ) {
			$start_utc = Dates::to_utc( $start_date, '', $timezone );
			$end_utc   = Dates::to_utc( Dates::add_days( $end_date, 1 ), '', $timezone );
		} else {
			$start_utc = Dates::to_utc( $start_date, $start_time, $timezone );
			$end_utc   = Dates::to_utc( $end_date, '' === $end_time ? $start_time : $end_time, $timezone );
			if ( $end_utc < $start_utc ) {
				$end_utc = $start_utc;
			}
		}

		$status = (string) ( $override['status'] ?? '' );

		return new self(
			$key,
			$start_date,
			$end_date,
			$start_time,
			$end_time,
			$all_day,
			$time_mode,
			self::STATUS_CANCELLED === $status ? self::STATUS_CANCELLED : self::STATUS_SCHEDULED,
			(string) ( $override['note'] ?? '' ),
			$start_utc,
			$end_utc,
			$timezone
		);
	}

	/**
	 * Column values of the occurrences table (without the event columns).
	 *
	 * @return array<string, string|int|null>
	 */
	public function to_row(): array {
		return [
			'occ_key'    => $this->key,
			'start_date' => $this->start_date,
			'end_date'   => $this->end_date,
			'start_time' => '' === $this->start_time ? null : $this->start_time . ':00',
			'end_time'   => '' === $this->end_time ? null : $this->end_time . ':00',
			'all_day'    => $this->all_day ? 1 : 0,
			'time_mode'  => $this->time_mode,
			'start_utc'  => $this->start_utc,
			'end_utc'    => $this->end_utc,
			'timezone'   => $this->timezone,
			'status'     => $this->status,
			'note'       => $this->note,
		];
	}
}
