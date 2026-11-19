<?php
/**
 * Compares the occurrences a schedule should have with the rows that are
 * stored and says what to insert, update and delete. Rows are matched by
 * their key, so ids stay stable when only times change.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Domain;

final class OccurrenceDiff {

	/**
	 * @param list<array<string, mixed>>         $desired Rows as they should be; each has 'occ_key'.
	 * @param array<string, array<string, mixed>> $stored  Current rows by occ_key; each has 'id'.
	 * @return array{insert: list<array<string, mixed>>, update: array<int, array<string, mixed>>, delete: list<int>}
	 *         'update' maps row id to the changed columns only.
	 */
	public static function compute( array $desired, array $stored ): array {
		$insert = [];
		$update = [];
		$seen   = [];

		foreach ( $desired as $row ) {
			$key = (string) ( $row['occ_key'] ?? '' );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			if ( ! isset( $stored[ $key ] ) ) {
				$insert[] = $row;
				continue;
			}

			$changes = [];
			foreach ( $row as $column => $value ) {
				if ( ! array_key_exists( $column, $stored[ $key ] ) || ! self::same( $value, $stored[ $key ][ $column ] ) ) {
					$changes[ $column ] = $value;
				}
			}
			if ( [] !== $changes ) {
				$update[ (int) $stored[ $key ]['id'] ] = $changes;
			}
		}

		$delete = [];
		foreach ( $stored as $key => $row ) {
			if ( ! isset( $seen[ $key ] ) ) {
				$delete[] = (int) $row['id'];
			}
		}

		return [
			'insert' => $insert,
			'update' => $update,
			'delete' => $delete,
		];
	}

	/**
	 * Database values come back as strings; compare without caring about
	 * that, but keep NULL distinct from an empty string.
	 *
	 * @param mixed $a
	 * @param mixed $b
	 */
	private static function same( $a, $b ): bool {
		if ( null === $a || null === $b ) {
			return $a === $b;
		}
		return (string) $a === (string) $b;
	}
}
