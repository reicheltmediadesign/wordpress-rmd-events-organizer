<?php
/**
 * How a location is named in one line.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain;

final class Place {

	/**
	 * "Name, City" — or the name alone when it already says the city
	 * ("Kreuzgang Meißen", or a location simply called "Meißen").
	 */
	public static function label( string $name, string $city ): string {
		$name = trim( $name );
		$city = trim( $city );
		if ( '' === $name || '' === $city ) {
			return $name . $city;
		}
		$lower    = static fn( string $text ): string => function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$contains = 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $lower( $city ), '/' ) . '(?![\p{L}\p{N}])/u', $lower( $name ) );

		return $contains ? $name : $name . ', ' . $city;
	}
}
