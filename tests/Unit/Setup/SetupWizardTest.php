<?php
/**
 * Einrichtungsassistent: Zusammenführen von Profil und Vorschlag (0.8.0).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Setup;

use Lsec\Setup\SetupWizard;
use PHPUnit\Framework\TestCase;

/**
 * Vorhandene Angaben bleiben, leere Felder und neue Einträge kommen dazu.
 */
final class SetupWizardTest extends TestCase {

	public function test_ai_settings_come_before_reading_the_website(): void {
		$this->assertSame( array( 'technik', 'ki', 'website', 'stil', 'start', 'entscheidungen' ), array_keys( SetupWizard::STEPS ) );
	}

	public function test_service_stems(): void {
		$this->assertSame( array( 'implan' ), SetupWizard::service_stems( 'Implantologie' ) );
		$this->assertSame( array( 'zahnae', 'zahnä' ), SetupWizard::service_stems( 'Zahnästhetik' ) );
		$this->assertSame( array( 'scalin' ), SetupWizard::service_stems( 'Deep Scaling' ) );
	}

	public function test_gsc_key_file_is_picked_only_when_unique(): void {
		$key   = '{"type":"service_account","client_email":"lsec@projekt.iam.gserviceaccount.com","private_key":"x"}';
		$other = '{"name":"keine Schlüsseldatei"}';
		$this->assertSame(
			'/p/lsec-search-console-6bd2a9baf7a6.json',
			\Lsec\Cli\GscCommand::pick_credentials(
				array(
					'/p/lsec-search-console-6bd2a9baf7a6.json' => $key,
					'/p/sonst.json' => $other,
				)
			)
		);
		$this->assertNull(
			\Lsec\Cli\GscCommand::pick_credentials(
				array(
					'/p/a.json' => $key,
					'/p/b.json' => $key,
				)
			),
			'zwei Schlüsseldateien sind nicht eindeutig'
		);
		$this->assertNull( \Lsec\Cli\GscCommand::pick_credentials( array( '/p/sonst.json' => $other ) ) );
	}

	public function test_merge_keeps_existing_and_adds_new(): void {
		$profile  = array(
			'organization' => array(
				'id'          => 5,
				'name'        => 'Musterreinigung GmbH',
				'description' => '',
				'phone'       => '05173 1234',
				'email'       => '',
			),
			'locations'    => array(
				array(
					'id'     => 7,
					'name'   => 'Büro Uetze',
					'street' => 'Hauptstraße 1',
					'city'   => 'Uetze',
				),
			),
			'services'     => array(
				array(
					'id'   => 8,
					'name' => 'Terrassenreinigung',
				),
			),
			'brands'       => array(),
			'competitors'  => array(),
		);
		$proposal = array(
			'organization' => array(
				'name'        => 'Andere Firma',
				'description' => 'Reinigung von Außenflächen.',
				'phone'       => '0000',
				'email'       => 'info@example.de',
			),
			'locations'    => array(
				array(
					'name'   => 'Hauptsitz',
					'street' => 'Hauptstraße 1',
					'city'   => 'Uetze',
				),
			),
			'services'     => array(
				array( 'name' => 'terrassenreinigung' ),
				array(
					'name'        => 'Fassadenreinigung',
					'description' => 'x',
				),
			),
		);
		$out      = SetupWizard::merge( $profile, $proposal );
		$this->assertSame( 'Musterreinigung GmbH', $out['profile']['organization']['name'] );
		$this->assertSame( '05173 1234', $out['profile']['organization']['phone'] );
		$this->assertSame( 'Reinigung von Außenflächen.', $out['profile']['organization']['description'] );
		$this->assertSame( 'info@example.de', $out['profile']['organization']['email'] );
		$this->assertCount( 1, $out['profile']['locations'], 'gleiche Adresse nicht doppelt' );
		$this->assertCount( 2, $out['profile']['services'] );
		$this->assertNull( $out['profile']['services'][1]['id'] );
		$this->assertContains( 'Leistung: Fassadenreinigung', $out['added'] );
		$this->assertCount( 3, $out['added'] );
	}
}
