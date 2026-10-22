<?php
/**
 * Fälle aus der echten Recherche 118 (Gemeinde Uetze), Nachtrag 22.10. (0.6.4).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Topics;

use Lsec\Topics\FactChecker;
use PHPUnit\Framework\TestCase;

/**
 * Die KI gibt Werte als eigenen Satz wieder und Gegenstände als Beschreibung; die Zitate stehen aber auf der Seite.
 */
final class FactCheckerRealTest extends TestCase {

	private const GEMEINDE = "Unsere Gemeinde\nDie heutige Gemeinde Uetze ist noch jung.\nSie entstand 1974 im Zuge der Gebietsreform aus neun selbstständigen Gemeinden.\nBereits 1021 wird Uetze erstmals urkundlich genannt.\nEinwohner: 20.723 (Stand: 10/2022)";

	private const GEMEINDE_REF = 'Unsere Gemeinde https://www.uetze.de/portal/seiten/unsere-gemeinde-917000361-21300.html';

	private const REINIGUNG = "Straßenreinigung und Winterdienst – Teil 2\nDie Sommerreinigung beinhaltet die Beseitigung von Schmutz, Laub, Papier, sonstigem Unrat und Wildkraut.\nHier hat die Reinigung in einer Breite von 1,20 m ab begehbarem Fahrbahnrand zu erfolgen.";

	private const REINIGUNG_REF = 'Straßenreinigung https://www.uetze.de/portal/pressemitteilungen/strassenreinigung-und-winterdienst-teil-2-917000539-21300.html';

	private const PLACES = array( 'Uetze', 'Eltze', 'Dollbergen', 'Hänigsen' );

	/**
	 * Prüft eine Tatsache.
	 *
	 * @param string $subject Gegenstand.
	 * @param string $value   Wert.
	 * @param string $quote   Zitat.
	 * @param string $page    Seite.
	 * @param string $ref     Titel und Adresse.
	 * @return array{status: string, quality: string|null, note: string, excerpt: string}
	 */
	private static function check( string $subject, string $value, string $quote, string $page, string $ref ): array {
		return FactChecker::check(
			array(
				'quote' => $quote,
				'value' => $value,
			),
			array( $subject ),
			$page,
			null,
			$ref,
			self::PLACES
		);
	}

	public function test_value_as_sentence_with_numbers(): void {
		$first = self::check( 'Uetze', 'Uetze wurde erstmals 1021 urkundlich genannt.', 'Bereits 1021 wird Uetze erstmals urkundlich genannt.', self::GEMEINDE, self::GEMEINDE_REF );
		$this->assertSame( array( 'verified', 'strong' ), array( $first['status'], $first['quality'] ) );

		$population = self::check( 'Uetze', '20.723 Einwohner (Stand Oktober 2022)', 'Einwohner: 20.723 (Stand: 10/2022)', self::GEMEINDE, self::GEMEINDE_REF );
		$this->assertSame( 'verified', $population['status'] );
		$this->assertStringContainsString( 'Adresse der Seite', $population['note'] );
	}

	public function test_value_in_next_paragraph(): void {
		$result = self::check( 'Gemeinde Uetze', 'Die Gemeinde entstand 1974 im Zuge der Gebietsreform.', 'Die heutige Gemeinde Uetze ist noch jung.', self::GEMEINDE, self::GEMEINDE_REF );
		$this->assertSame( array( 'verified', 'medium' ), array( $result['status'], $result['quality'] ) );
		$this->assertStringContainsString( 'Umfeld', $result['note'] );
	}

	public function test_rule_paraphrased_with_subject_description(): void {
		$summer = self::check( 'Sommerreinigung in Uetze', 'Die Sommerreinigung umfasst Schmutz, Laub, Papier, sonstigen Unrat und Wildkraut.', 'Die Sommerreinigung beinhaltet die Beseitigung von Schmutz, Laub, Papier, sonstigem Unrat und Wildkraut.', self::REINIGUNG, self::REINIGUNG_REF );
		$this->assertSame( array( 'verified', 'medium' ), array( $summer['status'], $summer['quality'] ) );

		$width = self::check( 'Gehwegreinigung in Uetze', 'Die Reinigung hat in einer Breite von 1,20 m ab begehbarem Fahrbahnrand zu erfolgen.', 'Hier hat die Reinigung in einer Breite von 1,20 m ab begehbarem Fahrbahnrand zu erfolgen.', self::REINIGUNG, self::REINIGUNG_REF );
		$this->assertSame( 'verified', $width['status'] );
	}

	public function test_page_title_does_not_cover_another_place(): void {
		$page = "Ortsteile der Gemeinde Uetze\nEltze wurde im Jahr 1226 erstmals urkundlich erwähnt.\nDollbergen ist für seine Raffinerie bekannt.";
		$ref  = 'Ortsteile https://www.uetze.de/ortsteile';
		$this->assertSame( 'disputed', self::check( 'Uetze', '1226', 'Eltze wurde im Jahr 1226 erstmals urkundlich erwähnt', $page, $ref )['status'] );
		$this->assertSame( 'disputed', self::check( 'Dollbergen', '1226', 'Eltze wurde im Jahr 1226 erstmals urkundlich erwähnt', $page, $ref )['status'] );
		$this->assertSame( 'verified', self::check( 'Eltze', '1226', 'Eltze wurde im Jahr 1226 erstmals urkundlich erwähnt', $page, $ref )['status'] );
	}

	public function test_wrong_value_is_rejected(): void {
		$this->assertSame( 'rejected', self::check( 'Uetze', 'Uetze hat 31.000 Einwohner', 'Einwohner: 20.723 (Stand: 10/2022)', self::GEMEINDE, self::GEMEINDE_REF )['status'] );
		$this->assertSame( 'rejected', self::check( 'Sommerreinigung in Uetze', 'Die Sommerreinigung erfolgt monatlich durch den Bauhof.', 'Die Sommerreinigung beinhaltet die Beseitigung von Schmutz, Laub, Papier, sonstigem Unrat und Wildkraut.', self::REINIGUNG, self::REINIGUNG_REF )['status'] );
	}

	public function test_value_match(): void {
		$this->assertSame( 'exact', FactChecker::value_match( 'einwohner: 20.723', '20.723' ) );
		$this->assertSame( 'numbers', FactChecker::value_match( 'einwohner: 20.723 (stand: 10/2022)', '20.723 Einwohner (Stand Oktober 2022)' ) );
		$this->assertSame( '', FactChecker::value_match( 'einwohner: 20.723', '20.724 Einwohner' ) );
		$this->assertSame( 'words', FactChecker::value_match( 'bei geringerer nutzung können wir auch individuelle reinigungsintervalle festlegen', 'Individuelle Reinigungsintervalle bei geringerer Nutzung' ) );
	}

	public function test_variants(): void {
		$this->assertSame( array( 'buero uetze', 'uetze' ), FactChecker::variants( array( 'Büro Uetze' ), self::PLACES ) );
		$this->assertContains( 'uetze', FactChecker::variants( array( 'Supermarktparkplätze in Uetze' ), self::PLACES ) );
	}
}
