<?php
/**
 * The kinds of event the plugin knows and the detail fields each one has.
 * Kinds are defined in code, not by site owners.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain;

final class Kinds {

	public const EVENT  = 'event';
	public const MARKET = 'market';

	public const FIELD_TEXT = 'text';
	public const FIELD_URL  = 'url';

	/**
	 * Detail fields per kind: key => type.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function all(): array {
		$common = [
			'organizer_name' => self::FIELD_TEXT,
			'organizer_url'  => self::FIELD_URL,
			'info_url'       => self::FIELD_URL,
			'price_text'     => self::FIELD_TEXT,
		];

		return [
			self::EVENT  => $common,
			self::MARKET => $common + [
				'stall'      => self::FIELD_TEXT,
				'stall_note' => self::FIELD_TEXT,
			],
		];
	}

	public static function is_valid( string $kind ): bool {
		return isset( self::all()[ $kind ] );
	}

	public static function normalize( string $kind ): string {
		return self::is_valid( $kind ) ? $kind : self::EVENT;
	}

	/**
	 * @return array<string, string> key => type.
	 */
	public static function fields( string $kind ): array {
		return self::all()[ self::normalize( $kind ) ];
	}
}
