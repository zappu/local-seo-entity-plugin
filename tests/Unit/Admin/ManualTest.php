<?php
/**
 * Tests für das Handbuch im Backend.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Admin;

use Lsec\Admin\Manual;
use PHPUnit\Framework\TestCase;

/**
 * Markdown des Handbuchs wird zu HTML.
 */
final class ManualTest extends TestCase {

	public function test_titel_entfaellt_und_ueberschriften_haben_anker(): void {
		$html = Manual::to_html( "# Titel\n\n## 7. Der Prüfbericht eines Entwurfs\n\n### 3.2 Schlüssel in `wp-config.php`\n" );
		$this->assertStringNotContainsString( 'Titel', $html );
		$this->assertStringContainsString( '<h2 id="7-der-prüfbericht-eines-entwurfs">7. Der Prüfbericht eines Entwurfs</h2>', $html );
		$this->assertStringContainsString( '<h3 id="32-schlüssel-in-wp-configphp">3.2 Schlüssel in <code>wp-config.php</code></h3>', $html );
	}

	public function test_inhaltsverzeichnis_verlinkt_die_anker(): void {
		$html = Manual::to_html( "1. [Was das Plugin macht](#1-was-das-plugin-macht)\n9. [Befehle](#9-befehle-wp-cli)\n" );
		$this->assertStringContainsString( '<ol><li><a href="#1-was-das-plugin-macht">Was das Plugin macht</a></li><li><a href="#9-befehle-wp-cli">Befehle</a></li></ol>', $html );
	}

	public function test_verschachtelte_liste(): void {
		$html = Manual::to_html( "- **Themen** prüfen\n  - Doppelte markiert\n  - Tatsachen\n- Entwürfe\n" );
		$this->assertSame( '<ul><li><strong>Themen</strong> prüfen<ul><li>Doppelte markiert</li><li>Tatsachen</li></ul></li><li>Entwürfe</li></ul>', $html );
	}

	public function test_tabelle_und_code(): void {
		$html = Manual::to_html( "| Feld | Wert |\n|---|---|\n| Ausführen | `*/5 * * * *` |\n\n```php\ndefine( 'X', '<a>' );\n```\n" );
		$this->assertStringContainsString( '<thead><tr><th>Feld</th><th>Wert</th></tr></thead><tbody><tr><td>Ausführen</td><td><code>*/5 * * * *</code></td></tr></tbody>', $html );
		$this->assertStringContainsString( '<pre><code>define( &#039;X&#039;, &#039;&lt;a&gt;&#039; );</code></pre>', $html );
	}

	public function test_html_wird_geschuetzt_und_dateilinks_werden_text(): void {
		$html = Manual::to_html( "Nach `<?php` eintragen <b>x</b>, siehe [Spezifikation](docs/spez.md) und [Seite](https://example.org).\n" );
		$this->assertStringContainsString( '<code>&lt;?php</code>', $html );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'siehe Spezifikation und', $html );
		$this->assertStringContainsString( '<a href="https://example.org" target="_blank" rel="noopener noreferrer">Seite</a>', $html );
	}

	public function test_kursiv(): void {
		$this->assertSame( '<p>Preise <em>(weitere)</em> und <strong>fett</strong>, Cron <code>*/5 * * * *</code></p>', Manual::to_html( 'Preise *(weitere)* und **fett**, Cron `*/5 * * * *`' ) );
	}

	public function test_absatz_ueber_mehrere_zeilen_und_falsche_tabelle(): void {
		$this->assertSame( "<p>Erste Zeile zweite Zeile</p>\n<p>| keine Tabelle</p>", Manual::to_html( "Erste Zeile\nzweite Zeile\n\n| keine Tabelle\n" ) );
	}

	public function test_handbuch_hat_alle_abschnitte_des_inhalts(): void {
		$markdown = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/handbuch.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$html     = Manual::to_html( $markdown );
		preg_match_all( '/href="#([^"]+)"/', $html, $links );
		$this->assertGreaterThanOrEqual( 12, count( $links[1] ) );
		foreach ( $links[1] as $anchor ) {
			$this->assertStringContainsString( 'id="' . $anchor . '"', $html, 'Anker fehlt: ' . $anchor );
		}
	}
}
