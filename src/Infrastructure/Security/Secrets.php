<?php
/**
 * Zugriff auf API-Schlüssel und andere Geheimnisse.
 *
 * @package Lsec
 */

namespace Lsec\Infrastructure\Security;

use Lsec\Infrastructure\Logging\Redactor;

/**
 * Liest Geheimnisse in dieser Reihenfolge:
 * 1. Konstante in wp-config.php, z. B. LSEC_OPENROUTER_API_KEY (hat immer Vorrang).
 * 2. Verschlüsselte Option lsec_secret_<name>.
 *
 * Die Verschlüsselung (libsodium secretbox) nutzt einen aus den WordPress-Salts
 * abgeleiteten Schlüssel. Sie schützt bei einem reinen Datenbank-Leck, nicht bei
 * vollem Serverzugriff.
 */
final class Secrets {

	/**
	 * Erlaubte Namen.
	 *
	 * @var list<string>
	 */
	public const NAMES = array( 'openrouter_api_key', 'anthropic_api_key', 'openai_api_key', 'textfocus_api_key' );

	/**
	 * Konstruktor.
	 *
	 * @param Redactor $redactor Jedes gelesene Geheimnis wird hier zur Schwärzung angemeldet.
	 */
	public function __construct( private readonly Redactor $redactor ) {}

	/**
	 * Name der Konstante, z. B. LSEC_OPENROUTER_API_KEY.
	 *
	 * @param string $name Name aus NAMES.
	 */
	public static function constant_name( string $name ): string {
		return 'LSEC_' . strtoupper( $name );
	}

	/**
	 * Woher kommt der Wert?
	 *
	 * @param string $name Name aus NAMES.
	 * @return string "constant", "option" oder "none".
	 */
	public function source( string $name ): string {
		self::assert_name( $name );
		$constant = self::constant_name( $name );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return 'constant';
		}
		return '' !== (string) get_option( 'lsec_secret_' . $name, '' ) ? 'option' : 'none';
	}

	/**
	 * Liest ein Geheimnis.
	 *
	 * @param string $name Name aus NAMES.
	 */
	public function get( string $name ): ?string {
		self::assert_name( $name );
		$constant = self::constant_name( $name );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			$value = (string) constant( $constant );
			$this->redactor->add_secret( $value );
			return $value;
		}

		$stored = (string) get_option( 'lsec_secret_' . $name, '' );
		if ( '' === $stored ) {
			return null;
		}
		$value = self::decrypt( $stored, self::key() );
		if ( null !== $value ) {
			$this->redactor->add_secret( $value );
		}
		return $value;
	}

	/**
	 * Speichert ein Geheimnis verschlüsselt. Ein leerer Wert löscht es.
	 *
	 * @param string $name  Name aus NAMES.
	 * @param string $value Klartext.
	 */
	public function set( string $name, string $value ): void {
		self::assert_name( $name );
		if ( '' === $value ) {
			delete_option( 'lsec_secret_' . $name );
			return;
		}
		update_option( 'lsec_secret_' . $name, self::encrypt( $value, self::key() ), false );
		$this->redactor->add_secret( $value );
	}

	/**
	 * Maskierte Anzeige, z. B. "sk-or-…a1b2".
	 *
	 * @param string|null $value Wert.
	 */
	public static function mask( ?string $value ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( strlen( $value ) <= 8 ) {
			return str_repeat( '•', 8 );
		}
		return substr( $value, 0, 6 ) . '…' . substr( $value, -4 );
	}

	/**
	 * Verschlüsselt mit libsodium.
	 *
	 * @param string $plaintext Klartext.
	 * @param string $key       32-Byte-Schlüssel.
	 */
	public static function encrypt( string $plaintext, string $key ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		return 'v1:' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Entschlüsselt. Gibt null zurück, wenn der Wert beschädigt ist oder die Salts geändert wurden.
	 *
	 * @param string $stored Gespeicherter Wert.
	 * @param string $key    32-Byte-Schlüssel.
	 */
	public static function decrypt( string $stored, string $key ): ?string {
		if ( ! str_starts_with( $stored, 'v1:' ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		return false === $plain ? null : $plain;
	}

	/**
	 * Schlüssel aus den WordPress-Salts.
	 */
	private static function key(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|lsec-secrets', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Erlaubt nur bekannte Namen.
	 *
	 * @param string $name Name.
	 */
	private static function assert_name( string $name ): void {
		if ( ! in_array( $name, self::NAMES, true ) ) {
			throw new \InvalidArgumentException( 'Unbekanntes Geheimnis: ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}
}
