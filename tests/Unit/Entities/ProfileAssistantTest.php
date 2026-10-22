<?php
/**
 * Tests des Profil-Vorschlags aus Webseiten.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Entities;

use Lsec\Entities\ProfileAssistant;
use PHPUnit\Framework\TestCase;

/**
 * Adressen, Seitentext und Prüfung gegen den Text.
 */
final class ProfileAssistantTest extends TestCase {

	private const TEXT = "SEITE https://example.de/impressum:\nStaats Flächenreinigungs-Service & Wassertechnik\nInhaber Max Staats\nHauptstraße 12\n31311 Uetze\nTelefon: 05173 / 98 76 54\nE-Mail: info@example.de\nWir reinigen Terrassen, Pflaster und Fassaden. Terrassenreinigung und Fassadenreinigung in der Region Hannover.\nWir arbeiten mit Geräten von Mosmatic.";

	public function test_parse_urls(): void {
		$urls = ProfileAssistant::parse_urls( "/impressum\nhttps://example.de/kontakt, example.de/leistungen\n\n/impressum\nftp://x.de javascript:alert(1)", 'https://example.de/' );
		$this->assertSame( array( 'https://example.de/impressum', 'https://example.de/kontakt', 'https://example.de/leistungen' ), $urls );
		$many = ProfileAssistant::parse_urls( implode( "\n", array_map( static fn( int $i ): string => '/s' . $i, range( 1, 12 ) ) ), 'https://example.de' );
		$this->assertCount( ProfileAssistant::MAX_PAGES, $many );
	}

	public function test_html_text_keeps_footer_and_phone_links(): void {
		$html = '<html><head><style>p{}</style></head><body><nav>Menü</nav><main><h1>Leistungen</h1><p>Terrassenreinigung</p></main>'
			. '<footer>Hauptstraße 12 · <a href="tel:+495173987654">Anrufen</a><script>var x=1;</script></footer></body></html>';
		$text = ProfileAssistant::html_text( $html );
		$this->assertStringContainsString( 'Terrassenreinigung', $text );
		$this->assertStringContainsString( 'Hauptstraße 12', $text );
		$this->assertStringContainsString( '+495173987654', $text );
		$this->assertStringNotContainsString( 'var x', $text );
	}

	public function test_verify_keeps_what_is_on_the_page(): void {
		$data = array(
			'organization' => array(
				'name'        => 'Staats Flächenreinigungs-Service & Wassertechnik',
				'description' => 'Reinigung von Außenflächen.',
				'website'     => 'https://example.de/',
				'phone'       => '+49 5173 987654',
				'email'       => 'info@example.de',
			),
			'locations'    => array(
				array(
					'name'          => 'Hauptsitz Uetze',
					'street'        => 'Hauptstraße 12',
					'postal_code'   => '31311',
					'city'          => 'Uetze',
					'phone'         => '',
					'opening_hours' => '',
				),
			),
			'services'     => array(
				array( 'name' => 'Terrassenreinigung', 'description' => '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'name' => 'Dachreinigung', 'description' => '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'name' => 'terrassenreinigung', 'description' => '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			),
			'brands'       => array( array( 'name' => 'Mosmatic', 'website' => '', 'own' => false ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'competitors'  => array(),
			'service_area' => 'Region Hannover',
		);
		$out  = ProfileAssistant::verify( $data, self::TEXT );
		$p    = $out['proposal'];
		$this->assertSame( 'Staats Flächenreinigungs-Service & Wassertechnik', $p['organization']['name'] );
		$this->assertSame( '+49 5173 987654', $p['organization']['phone'], 'Telefon mit +49 statt 0 und anderen Leerzeichen.' );
		$this->assertSame( 'info@example.de', $p['organization']['email'] );
		$this->assertCount( 1, $p['locations'] );
		$this->assertSame( array( 'Terrassenreinigung' ), array_column( $p['services'], 'name' ), 'Dachreinigung steht nicht im Text, Dublette entfällt.' );
		$this->assertSame( 'Mosmatic', $p['brands'][0]['name'] );
		$this->assertSame( 'Region Hannover', $p['service_area'] );
		$this->assertContains( 'Dachreinigung (nicht auf den Seiten)', $out['dropped'] );
	}

	public function test_verify_drops_invented_contact_data(): void {
		$data = array(
			'organization' => array(
				'name'        => 'Staats GmbH',
				'description' => '',
				'website'     => '',
				'phone'       => '0511 123456',
				'email'       => 'kontakt@staats.de',
			),
			'locations'    => array(
				array(
					'name'          => 'Filiale Hannover',
					'street'        => 'Lister Meile 1',
					'postal_code'   => '30161',
					'city'          => 'Hannover',
					'phone'         => '',
					'opening_hours' => '',
				),
			),
		);
		$p    = ProfileAssistant::verify( $data, self::TEXT )['proposal'];
		$this->assertSame( '', $p['organization']['name'] );
		$this->assertSame( '', $p['organization']['phone'] );
		$this->assertSame( '', $p['organization']['email'] );
		$this->assertSame( array(), $p['locations'] );
	}
}
