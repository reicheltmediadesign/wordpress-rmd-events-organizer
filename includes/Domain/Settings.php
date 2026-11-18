<?php
/**
 * The only place that defines the shape of the stored settings. Free of
 * WordPress so it can be tested with plain PHPUnit.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain;

final class Settings {

	public const MODULES = [ 'locations', 'markets', 'calendar' ];

	public const MIN_HORIZON = 6;
	public const MAX_HORIZON = 60;
	public const MAX_LIMIT   = 100;

	public static function defaults(): array {
		return [
			'modules'             => [
				'locations' => true,
				'markets'   => false,
				'calendar'  => true,
			],
			'roles'               => [],
			'permalinks'          => [
				'event_slug'      => 'veranstaltungen',
				'location_slug'   => 'veranstaltungsorte',
				'location_public' => false,
			],
			'pages'               => [
				'events'  => 0,
				'markets' => 0,
			],
			'display'             => [
				'list_limit'      => 10,
				'single_details'  => true,
				'show_end_time'   => true,
				'structured_data' => true,
				'ics'             => true,
			],
			'calendar'            => [
				'horizon_months' => 24,
			],
			'delete_on_uninstall' => false,
		];
	}

	/**
	 * Reads unknown data (stored option or form input). Invalid or missing
	 * values fall back to the given defaults; unknown keys are dropped.
	 *
	 * @param mixed      $raw      Raw value.
	 * @param array|null $defaults Defaults to fall back to (the WordPress layer passes translated slugs).
	 */
	public static function parse( $raw, ?array $defaults = null ): array {
		$defaults = $defaults ?? self::defaults();
		$raw      = is_array( $raw ) ? $raw : [];
		$result   = [];

		foreach ( $defaults as $key => $default ) {
			$value = $raw[ $key ] ?? null;
			if ( 'roles' === $key ) {
				$result[ $key ] = self::slugs( $value );
			} elseif ( is_array( $default ) ) {
				$value = is_array( $value ) ? $value : [];
				foreach ( $default as $sub_key => $sub_default ) {
					$result[ $key ][ $sub_key ] = self::scalar( $value[ $sub_key ] ?? null, $sub_default );
				}
			} else {
				$result[ $key ] = self::scalar( $value, $default );
			}
		}

		$result['display']['list_limit']      = max( 1, min( self::MAX_LIMIT, $result['display']['list_limit'] ) );
		$result['calendar']['horizon_months'] = max( self::MIN_HORIZON, min( self::MAX_HORIZON, $result['calendar']['horizon_months'] ) );
		foreach ( [ 'event_slug', 'location_slug' ] as $slug_key ) {
			$slug                              = self::slug( $result['permalinks'][ $slug_key ] );
			$result['permalinks'][ $slug_key ] = '' === $slug ? $defaults['permalinks'][ $slug_key ] : $slug;
		}
		if ( $result['permalinks']['event_slug'] === $result['permalinks']['location_slug'] ) {
			$result['permalinks']['location_slug'] .= '-2';
		}
		foreach ( $result['pages'] as $page => $id ) {
			$result['pages'][ $page ] = max( 0, $id );
		}

		return $result;
	}

	/**
	 * Lowercase path made of letters, digits and "-", with "/" between
	 * segments ("termine/alle").
	 */
	public static function slug( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = (string) preg_replace( '/[^a-z0-9\/-]+/', '-', $value );
		$value = (string) preg_replace( '#-*/+-*#', '/', $value );
		$value = (string) preg_replace( '/-{2,}/', '-', $value );
		return substr( trim( $value, '/-' ), 0, 100 );
	}

	/**
	 * @param mixed                 $value   Raw value.
	 * @param bool|int|string|array $fallback Default that also decides the type.
	 * @return bool|int|string
	 */
	private static function scalar( $value, $fallback ) {
		if ( is_bool( $fallback ) ) {
			if ( is_bool( $value ) ) {
				return $value;
			}
			if ( is_int( $value ) || ( is_string( $value ) && in_array( $value, [ '0', '1' ], true ) ) ) {
				return 1 === (int) $value;
			}
			return $fallback;
		}
		if ( is_int( $fallback ) ) {
			return is_numeric( $value ) ? (int) $value : $fallback;
		}
		return is_string( $value ) ? $value : (string) $fallback;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return list<string>
	 */
	private static function slugs( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$slugs = [];
		foreach ( $value as $item ) {
			if ( is_string( $item ) && 1 === preg_match( '/^[a-z0-9_-]{1,60}$/', $item ) ) {
				$slugs[] = $item;
			}
		}
		return array_values( array_unique( $slugs ) );
	}
}
