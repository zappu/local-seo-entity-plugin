<?php
/**
 * Prüft Daten gegen ein JSON-Schema (Teilmenge).
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

/**
 * Unterstützt die Teilmenge, die alle drei Anbieter für strukturierte Ausgaben kennen:
 * type (auch als Liste, z. B. ["string","null"]), properties, required,
 * additionalProperties false, items, enum.
 *
 * Eine strukturell gültige Antwort ist damit nicht automatisch sachlich richtig;
 * dafür gibt es die fachlichen Prüfungen (Spezifikation 8.2).
 */
final class JsonSchemaValidator {

	/**
	 * Prüft Daten.
	 *
	 * @param mixed                $data   Daten.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   Pfad für Fehlermeldungen.
	 * @return list<string> Fehler, leer wenn gültig.
	 */
	public static function validate( mixed $data, array $schema, string $path = '$' ): array {
		$errors = array();

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $data, $schema['enum'], true ) ) {
			$errors[] = $path . ': Wert nicht erlaubt';
			return $errors;
		}

		if ( isset( $schema['type'] ) ) {
			$types = (array) $schema['type'];
			if ( ! self::matches_any( $data, $types ) ) {
				$errors[] = $path . ': erwartet ' . implode( '|', $types ) . ', erhalten ' . self::type_of( $data );
				return $errors;
			}
		}

		if ( is_array( $data ) && self::is_assoc_or_empty_object( $data, $schema ) ) {
			$properties = (array) ( $schema['properties'] ?? array() );
			foreach ( (array) ( $schema['required'] ?? array() ) as $key ) {
				if ( ! array_key_exists( $key, $data ) ) {
					$errors[] = $path . '.' . $key . ': fehlt';
				}
			}
			foreach ( $data as $key => $value ) {
				if ( isset( $properties[ $key ] ) && is_array( $properties[ $key ] ) ) {
					$errors = array_merge( $errors, self::validate( $value, $properties[ $key ], $path . '.' . $key ) );
				} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
					$errors[] = $path . '.' . $key . ': nicht erlaubt';
				}
			}
		} elseif ( is_array( $data ) && isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			foreach ( $data as $index => $item ) {
				$errors = array_merge( $errors, self::validate( $item, $schema['items'], $path . '[' . $index . ']' ) );
			}
		}

		return $errors;
	}

	/**
	 * Passt der Wert zu einem der Typen?
	 *
	 * @param mixed        $data  Wert.
	 * @param array<mixed> $types Typen.
	 */
	private static function matches_any( mixed $data, array $types ): bool {
		foreach ( $types as $type ) {
			$ok = match ( $type ) {
				'object'  => is_array( $data ) && ( array() === $data || ! array_is_list( $data ) ),
				'array'   => is_array( $data ) && array_is_list( $data ),
				'string'  => is_string( $data ),
				'integer' => is_int( $data ),
				'number'  => is_int( $data ) || is_float( $data ),
				'boolean' => is_bool( $data ),
				'null'    => null === $data,
				default   => false,
			};
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Wird das Array als Objekt behandelt?
	 *
	 * @param array<mixed>         $data   Daten.
	 * @param array<string, mixed> $schema Schema.
	 */
	private static function is_assoc_or_empty_object( array $data, array $schema ): bool {
		$types = (array) ( $schema['type'] ?? array() );
		if ( in_array( 'object', $types, true ) && ( array() === $data || ! array_is_list( $data ) ) ) {
			return true;
		}
		return array() !== $data && ! array_is_list( $data );
	}

	/**
	 * Typname für Fehlermeldungen.
	 *
	 * @param mixed $data Wert.
	 */
	private static function type_of( mixed $data ): string {
		if ( is_array( $data ) ) {
			return array_is_list( $data ) && array() !== $data ? 'array' : 'object';
		}
		return get_debug_type( $data );
	}
}
