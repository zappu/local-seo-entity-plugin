<?php
/**
 * Schwärzt Geheimnisse in Texten.
 *
 * @package Lsec
 */

namespace Lsec\Infrastructure\Logging;

/**
 * Entfernt bekannte Geheimnisse und typische Schlüsselmuster aus Protokollen,
 * Fehlermeldungen und Exporten.
 */
final class Redactor {

	public const MASK = '[GESCHWÄRZT]';

	/**
	 * Muster für typische API-Schlüssel und Zugangsdaten.
	 *
	 * @var list<string>
	 */
	private const PATTERNS = array(
		'/sk-ant-[A-Za-z0-9_\-]{10,}/',
		'/sk-or-v1-[A-Za-z0-9]{10,}/',
		'/sk-(?:proj-)?[A-Za-z0-9_\-]{20,}/',
		'/AKIA[0-9A-Z]{16}/',
		'/gh[pousr]_[A-Za-z0-9]{20,}/',
		'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
		'/((?:api[_-]?key|token|secret|password|passwort)["\']?\s*[:=]\s*["\']?)[^"\'\s&,}]{6,}/i',
		'/(Bearer\s+)[A-Za-z0-9._\-]{10,}/i',
		// Textfocus und andere Dienste übergeben den Schlüssel als URL-Parameter "key".
		'/([?&](?:key|apikey|api_key|access_token)=)[^&\s"\']{6,}/i',
		'/(X-API-Key["\']?\s*[:=]\s*["\']?)[^"\'\s,}]{6,}/i',
	);

	/**
	 * Bekannte Geheimnisse, die wörtlich ersetzt werden.
	 *
	 * @var list<string>
	 */
	private array $secrets = array();

	/**
	 * Meldet ein Geheimnis an, damit es überall geschwärzt wird.
	 *
	 * @param string $secret Geheimnis. Sehr kurze Werte werden ignoriert, um Fehltreffer zu vermeiden.
	 */
	public function add_secret( string $secret ): void {
		if ( strlen( $secret ) >= 8 && ! in_array( $secret, $this->secrets, true ) ) {
			$this->secrets[] = $secret;
		}
	}

	/**
	 * Schwärzt einen Text.
	 *
	 * @param string $text Text.
	 */
	public function redact( string $text ): string {
		foreach ( $this->secrets as $secret ) {
			$text = str_replace( $secret, self::MASK, $text );
		}
		foreach ( self::PATTERNS as $pattern ) {
			$text = (string) preg_replace_callback(
				$pattern,
				static fn( array $m ): string => ( isset( $m[1] ) && '' !== $m[1] && ! str_starts_with( $m[0], '-----' ) ) ? $m[1] . self::MASK : self::MASK,
				$text
			);
		}
		return $text;
	}

	/**
	 * Schwärzt alle Zeichenketten in einem Array, auch verschachtelt.
	 *
	 * @param array<mixed> $data Daten.
	 * @return array<mixed>
	 */
	public function redact_array( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) ) {
				$data[ $key ] = $this->redact( $value );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = $this->redact_array( $value );
			}
		}
		return $data;
	}
}
