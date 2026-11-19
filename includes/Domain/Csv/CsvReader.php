<?php
/**
 * Reads a CSV file with events into rows keyed by column. Knows the columns
 * and the names they may have in the header line; what the values mean is up
 * to CsvPlanner.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain\Csv;

final class CsvReader {

	public const ERROR_EMPTY  = 'empty';
	public const ERROR_HEADER = 'no_header';

	/**
	 * Column => names accepted in the header line besides the column itself.
	 * The first name is the German one used for templates.
	 */
	public const COLUMNS = [
		'key'           => [ 'schluessel', 'schlüssel' ],
		'kind'          => [ 'art' ],
		'title'         => [ 'titel' ],
		'description'   => [ 'beschreibung' ],
		'date'          => [ 'datum' ],
		'end_date'      => [ 'enddatum' ],
		'start'         => [ 'beginn' ],
		'end'           => [ 'ende' ],
		'daily_hours'   => [ 'zeiten_taeglich', 'zeiten_täglich' ],
		'location'      => [ 'ort' ],
		'street'        => [ 'strasse', 'straße' ],
		'postcode'      => [ 'plz' ],
		'city'          => [ 'stadt' ],
		'country'       => [ 'land' ],
		'categories'    => [ 'kategorien' ],
		'tags'          => [ 'schlagwoerter', 'schlagwörter' ],
		'organizer'     => [ 'veranstalter' ],
		'organizer_url' => [ 'veranstalter_url' ],
		'info_url'      => [ 'info_url' ],
		'price'         => [ 'preis' ],
		'stall'         => [ 'stand' ],
		'stall_note'    => [ 'stand_hinweis' ],
		'status'        => [ 'status' ],
		'image'         => [ 'bild' ],
		'language'      => [ 'sprache' ],
	];

	private const DELIMITERS = [ ';', ',', "\t" ];

	/**
	 * @return array{error: string, columns: list<string>, unknown: list<string>, rows: list<array{line: int, cells: array<string, string>}>}
	 */
	public static function parse( string $text ): array {
		$result = [
			'error'   => '',
			'columns' => [],
			'unknown' => [],
			'rows'    => [],
		];

		$text = self::to_utf8( $text );
		if ( '' === trim( $text ) ) {
			$result['error'] = self::ERROR_EMPTY;
			return $result;
		}

		$records = self::records( $text, self::delimiter( $text ) );
		$header  = array_shift( $records );
		$names   = self::names();
		$map     = [];
		foreach ( $header['cells'] as $index => $label ) {
			$name = self::normalize( $label );
			if ( '' === $name ) {
				continue;
			}
			if ( ! isset( $names[ $name ] ) ) {
				$result['unknown'][] = trim( $label );
			} elseif ( ! in_array( $names[ $name ], $map, true ) ) {
				$map[ $index ] = $names[ $name ];
			}
		}
		$result['columns'] = array_values( $map );
		if ( ! in_array( 'title', $map, true ) || ! in_array( 'date', $map, true ) ) {
			$result['error'] = self::ERROR_HEADER;
			return $result;
		}

		foreach ( $records as $record ) {
			$cells = [];
			foreach ( $map as $index => $column ) {
				$cells[ $column ] = trim( (string) ( $record['cells'][ $index ] ?? '' ) );
			}
			if ( '' !== implode( '', $cells ) ) {
				$result['rows'][] = [
					'line'  => $record['line'],
					'cells' => $cells,
				];
			}
		}
		return $result;
	}

	/**
	 * Header line for a template.
	 *
	 * @param bool $german True for the German column names.
	 */
	public static function header( bool $german, string $delimiter = ';' ): string {
		$labels = [];
		foreach ( self::COLUMNS as $column => $aliases ) {
			$labels[] = $german ? $aliases[0] : $column;
		}
		return implode( $delimiter, $labels );
	}

	/**
	 * @return array<string, string> Name in a header line => column.
	 */
	private static function names(): array {
		$names = [];
		foreach ( self::COLUMNS as $column => $aliases ) {
			$names[ $column ] = $column;
			foreach ( $aliases as $alias ) {
				$names[ $alias ] = $column;
			}
		}
		return $names;
	}

	private static function normalize( string $label ): string {
		$label = trim( $label );
		$label = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
		return (string) preg_replace( '/[\s\-]+/', '_', $label );
	}

	/**
	 * Files saved by spreadsheet programs are often not UTF-8.
	 */
	private static function to_utf8( string $text ): string {
		if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}
		if ( 1 === preg_match( '//u', $text ) ) {
			return $text;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		return function_exists( 'iconv' ) ? (string) iconv( 'Windows-1252', 'UTF-8//IGNORE', $text ) : $text;
	}

	/**
	 * The separator that occurs most often in the header line.
	 */
	private static function delimiter( string $text ): string {
		$line = (string) strtok( ltrim( $text, "\r\n" ), "\r\n" );
		$best = ';';
		$most = 0;
		foreach ( self::DELIMITERS as $candidate ) {
			$count = substr_count( $line, $candidate );
			if ( $count > $most ) {
				$most = $count;
				$best = $candidate;
			}
		}
		return $best;
	}

	/**
	 * Splits the text into records. Quoted cells may contain the separator,
	 * line breaks and doubled quotes.
	 *
	 * @return list<array{line: int, cells: list<string>}> Line = line of the file the record starts on.
	 */
	private static function records( string $text, string $delimiter ): array {
		$records = [];
		$cells   = [];
		$cell    = '';
		$quoted  = false;
		$line    = 1;
		$start   = 1;
		$length  = strlen( $text );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $text[ $i ];

			if ( $quoted ) {
				if ( '"' === $char && '"' === ( $text[ $i + 1 ] ?? '' ) ) {
					$cell .= '"';
					++$i;
				} elseif ( '"' === $char ) {
					$quoted = false;
				} else {
					$cell .= $char;
					$line += "\n" === $char ? 1 : 0;
				}
				continue;
			}

			if ( '"' === $char && '' === trim( $cell ) ) {
				$quoted = true;
				$cell   = '';
			} elseif ( $delimiter === $char ) {
				$cells[] = $cell;
				$cell    = '';
			} elseif ( "\n" === $char || "\r" === $char ) {
				if ( "\r" === $char && "\n" === ( $text[ $i + 1 ] ?? '' ) ) {
					++$i;
				}
				$cells[]   = $cell;
				$records[] = [
					'line'  => $start,
					'cells' => $cells,
				];
				$cells     = [];
				$cell      = '';
				++$line;
				$start = $line;
			} else {
				$cell .= $char;
			}
		}
		if ( '' !== $cell || [] !== $cells ) {
			$cells[]   = $cell;
			$records[] = [
				'line'  => $start,
				'cells' => $cells,
			];
		}

		// Leading empty lines are not the header.
		while ( [] !== $records && '' === trim( implode( '', $records[0]['cells'] ) ) ) {
			array_shift( $records );
		}
		return [] === $records ? [
			[
				'line'  => 1,
				'cells' => [],
			],
		] : $records;
	}
}
