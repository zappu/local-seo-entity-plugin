<?php
/**
 * Tests der Regelprüfung (Spezifikation 10.1) und der KI-Bewertung (10.2).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Production;

use Lsec\Production\BriefWriter;
use Lsec\Production\DraftGenerator;
use Lsec\Production\QualityChecker;
use Lsec\Production\QualityReviewer;
use PHPUnit\Framework\TestCase;

/**
 * Abnahme Stufe E: ein Entwurf mit unbelegter Zahl ist blockiert.
 */
final class QualityCheckerTest extends TestCase {

	/**
	 * Kontext wie aus gather_context.
	 *
	 * @return array<string, mixed>
	 */
	private static function context(): array {
		return array(
			'topic'        => array(
				'id'            => 1,
				'title'         => 'Pflasterreinigung in Uetze',
				'intent'        => 'local',
				'format'        => 'local_landing',
				'region'        => 'Uetze',
				'rationale'     => '',
				'overlap_level' => 'partial',
			),
			'company'      => array(
				'name'      => 'Staats Flächenreinigung',
				'phone'     => '05173 92 41 30',
				'email'     => '',
				'website'   => '',
				'services'  => array( 'Pflasterreinigung' ),
				'locations' => array(
					array(
						'name'        => 'Büro',
						'street'      => 'Westerkampstr. 15',
						'postal_code' => '31311',
						'city'        => 'Uetze',
						'phone'       => '',
					),
				),
			),
			'area'         => 'Uetze und 60 km Umkreis',
			'entities'     => array(
				array(
					'id'     => 5,
					'name'   => 'Pflasterreinigung',
					'type'   => 'service',
					'status' => 'confirmed',
					'role'   => 'primary',
				),
			),
			'claims'       => array(
				array(
					'id'          => 10,
					'entity'      => 'Uetze',
					'entity_type' => 'place',
					'property'    => 'population',
					'value'       => '20.723',
					'status'      => 'verified',
					'quote'       => 'Einwohner: 20.723 (Stand: 10/2022)',
					'source_url'  => 'https://www.uetze.de/',
				),
				array(
					'id'          => 11,
					'entity'      => 'Uetze',
					'entity_type' => 'place',
					'property'    => 'first_mentioned',
					'value'       => '1021',
					'status'      => 'verified',
					'quote'       => 'Bereits 1021 wird Uetze erstmals urkundlich genannt.',
					'source_url'  => 'https://www.uetze.de/',
				),
				array(
					'id'          => 12,
					'entity'      => 'Uetze',
					'entity_type' => 'place',
					'property'    => 'area',
					'value'       => '139',
					'status'      => 'disputed',
					'quote'       => '',
					'source_url'  => '',
				),
			),
			'keywords'     => array(
				array(
					'keyword' => 'pflasterreinigung uetze',
					'role'    => 'primary',
					'volume'  => 20,
				),
			),
			'link_targets' => array(
				array(
					'post_id' => 7,
					'title'   => 'Pflasterreinigung',
					'url'     => 'https://example.de/pflasterreinigung/',
				),
			),
			'categories'   => array(),
			'style'        => '',
			'length'       => array(
				'min' => 20,
				'max' => 2000,
			),
		);
	}

	/**
	 * Entwurf mit Text.
	 *
	 * @param string $text Absatz.
	 * @param int[]  $used Verwendete Aussagen.
	 * @return array<string, mixed>
	 */
	private static function draft( string $text, array $used = array( 10, 11 ) ): array {
		return DraftGenerator::normalize(
			array(
				'title'                  => 'Pflasterreinigung in Uetze: sauberes Pflaster',
				'content'                => array(
					array(
						'type' => 'paragraph',
						'text' => $text,
					),
					array(
						'type' => 'paragraph',
						'text' => 'Rufen Sie uns an: 05173 92 41 30. Unser Büro: Westerkampstr. 15, 31311 Uetze. Wir reinigen in 3 Schritten <a href="https://example.de/pflasterreinigung/">Pflaster</a>.',
					),
				),
				'excerpt'                => 'Pflasterreinigung in Uetze.',
				'used_entity_ids'        => array( 5 ),
				'used_claim_ids'         => $used,
				'unsupported_statements' => array(),
				'internal_links'         => array(
					array(
						'post_id' => 7,
						'anchor'  => 'Pflaster',
					),
				),
				'seo'                    => array(
					'title'              => 'Pflasterreinigung in Uetze – sauber und schonend',
					'description'        => 'Pflasterreinigung in Uetze: Moos und Schmutz schonend entfernen. Wir reinigen Pflaster, Einfahrten und Höfe in Uetze und Umgebung.',
					'focus_keyword'      => 'pflasterreinigung uetze',
					'secondary_keywords' => array(),
					'slug'               => '',
				),
				'taxonomy'               => array(
					'category_ids'             => array(),
					'new_category_suggestions' => array(),
					'tags'                     => array(),
				),
			),
			self::context()
		)['draft'];
	}

	/**
	 * Daten der Website.
	 *
	 * @return array<string, mixed>
	 */
	private static function site(): array {
		return array(
			'titles'         => array( 'Pflasterreinigung', 'Kontakt' ),
			'local_texts'    => array(),
			'foreign_places' => array( 'Hamburg' ),
			'focus_keywords' => array( 'pflasterreinigung' ),
			'blocks_valid'   => true,
			'home_host'      => 'example.de',
		);
	}

	/**
	 * Codes der blockierenden Mängel.
	 *
	 * @param array<string, mixed> $result Ergebnis.
	 * @return string[]
	 */
	private static function codes( array $result ): array {
		return array_column( $result['blocking'], 'code' );
	}

	public function test_supported_draft_passes(): void {
		$result = QualityChecker::check( self::draft( 'Uetze hat 20.723 Einwohner und wurde 1021 erstmals erwähnt.' ), self::context(), self::site() );
		$this->assertSame( array(), self::codes( $result ), (string) json_encode( $result['blocking'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_unsupported_number_blocks(): void {
		$result = QualityChecker::check( self::draft( 'Uetze hat 20.723 Einwohner. Wir arbeiten seit 25 Jahren und reinigen ab 3 € pro m².' ), self::context(), self::site() );
		$this->assertContains( 'UNSUPPORTED_NUMBER', self::codes( $result ) );
		$detail = $result['blocking'][ array_search( 'UNSUPPORTED_NUMBER', self::codes( $result ), true ) ]['detail'];
		$this->assertStringContainsString( '25', $detail );
		$this->assertStringContainsString( '3', $detail );
	}

	public function test_number_from_unlisted_claim_is_added(): void {
		$result = QualityChecker::check( self::draft( 'Uetze hat 20.723 Einwohner und wurde 1021 erstmals erwähnt.', array( 10 ) ), self::context(), self::site() );
		$this->assertNotContains( 'UNSUPPORTED_NUMBER', self::codes( $result ) );
		$this->assertContains( 11, $result['used_claim_ids'] );
	}

	public function test_disputed_claim_blocks(): void {
		$result = QualityChecker::check( self::draft( 'Uetze hat 20.723 Einwohner und wurde 1021 erstmals erwähnt.', array( 10, 11, 12 ) ), self::context(), self::site() );
		$this->assertContains( 'UNSUPPORTED_FACT', self::codes( $result ) );
	}

	public function test_local_rules(): void {
		$one = QualityChecker::check( self::draft( 'Uetze hat 20.723 Einwohner.', array( 10 ) ), self::context(), self::site() );
		$this->assertContains( 'LOCAL_FACTS', self::codes( $one ) );
		$this->assertStringContainsString( '1 Tatsachen zum Ort verwendet', (string) json_encode( $one['blocking'], JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		// Ratgeber mit Ort: Warnung statt Blockade (0.7.10, Spezifikation 10.1 „lokale Beiträge“).
		$guide                    = self::context();
		$guide['topic']['format'] = 'guide';
		$warn                     = QualityChecker::check( self::draft( 'Uetze hat 20.723 Einwohner.', array( 10 ) ), $guide, self::site() );
		$this->assertNotContains( 'LOCAL_FACTS', self::codes( $warn ) );
		$this->assertContains( 'LOCAL_FACTS', array_column( $warn['warnings'], 'code' ) );

		// Lokale Seite, Ortsrecherche fand zu wenig (kleiner Ort): Warnung (0.7.14).
		$small                   = self::context();
		$small['claims']         = array();
		$small['place_searched'] = true;
		$few                     = QualityChecker::check( self::draft( 'Uetze ist schön.', array() ), $small, self::site() );
		$this->assertNotContains( 'LOCAL_FACTS', self::codes( $few ) );
		$small['place_searched'] = false;
		$this->assertContains( 'LOCAL_FACTS', self::codes( QualityChecker::check( self::draft( 'Uetze ist schön.', array() ), $small, self::site() ) ) );

		$site                = self::site();
		$site['local_texts'] = array( 'Uetze hat 20.723 Einwohner und wurde 1021 erstmals erwähnt. Rufen Sie uns an: 05173 92 41 30. Unser Büro: Westerkampstr. 15, 31311 Uetze. Wir reinigen in 3 Schritten Pflaster.' );
		$this->assertContains( 'LOCAL_DUPLICATE', self::codes( QualityChecker::check( self::draft( 'Uetze hat 20.723 Einwohner und wurde 1021 erstmals erwähnt.' ), self::context(), $site ) ) );

		$this->assertContains( 'FOREIGN_PLACE', self::codes( QualityChecker::check( self::draft( 'Uetze hat 20.723 Einwohner und wurde 1021 erstmals erwähnt. Auch in Hamburg sind wir für Sie da.' ), self::context(), self::site() ) ) );
	}

	public function test_prompt_leaks(): void {
		$text = 'Bei einer PZR werden Beläge entfernt. Die oben beschriebenen Abläufe einer PZR sind allgemeines Fachwissen. Laut Profil bieten wir Beratung an.';
		$this->assertSame( array( 'Die oben beschriebenen Abläufe einer PZR sind allgemeines Fachwissen.', 'Laut Profil bieten wir Beratung an.' ), QualityChecker::prompt_leaks( $text ) );
		$this->assertSame( array(), QualityChecker::prompt_leaks( 'Fachwissen und Erfahrung zählen. Unser Team berät Sie gern.' ) );
	}

	public function test_placeholders_titles_and_warnings(): void {
		$this->assertSame( array( '[Ort]', 'Lorem ipsum' ), QualityChecker::placeholders( 'Pflaster in [Ort]. Lorem ipsum.' ) );
		$this->assertNotNull( QualityChecker::similar_title( 'Pflasterreinigung in Uetze', array( 'Pflasterreinigung in Uetze!' ) ) );
		$this->assertNull( QualityChecker::similar_title( 'Pflasterreinigung in Uetze', array( 'Terrassenreinigung in Lehrte' ) ) );
		$this->assertSame( array( '20723', '1,20', '2022' ), QualityChecker::numbers( 'Einwohner: 20.723, Breite 1,20 m, Stand 2022, in 3 Schritten', true ) );
	}

	public function test_brief_drops_unknown_references(): void {
		$valid = BriefWriter::validate(
			array(
				'outline'               => array(
					array(
						'heading'    => 'A',
						'level'      => 4,
						'purpose'    => '',
						'entity_ids' => array( 5, 99 ),
					),
				),
				'required_facts'        => array(
					array(
						'claim_id' => 10,
						'use'      => 'x',
					),
					array(
						'claim_id' => 77,
						'use'      => 'y',
					),
				),
				'internal_link_targets' => array(
					array(
						'post_id'     => 8,
						'anchor_hint' => 'z',
					),
				),
				'length'                => array(
					'min_words' => 5,
					'max_words' => 9000,
				),
			),
			self::context()
		);
		$this->assertSame( array( 5 ), $valid['brief']['outline'][0]['entity_ids'] );
		$this->assertSame( 2, $valid['brief']['outline'][0]['level'] );
		$this->assertSame( array( 10 ), array_column( $valid['brief']['required_facts'], 'claim_id' ) );
		$this->assertSame( array(), $valid['brief']['internal_link_targets'] );
		$this->assertSame( array( 20, 2000 ), array_values( $valid['brief']['length'] ) );
		$this->assertCount( 3, $valid['dropped'] );
	}

	public function test_review_thresholds(): void {
		$scores = array(
			'intent'             => 0.9,
			'local_relevance'    => 0.5,
			'entity_integration' => 0.8,
			'value'              => 0.8,
			'readability'        => 0.9,
			'style_match'        => 0.8,
		);
		$low    = QualityReviewer::evaluate( array( 'scores' => $scores ), 0.6, 0.75 );
		$this->assertSame( array( 'QUALITY_SCORE' ), array_column( $low['blocking'], 'code' ) );
		$scores['local_relevance'] = 0.7;
		$ok                        = QualityReviewer::evaluate(
			array(
				'scores'          => $scores,
				'blocking_issues' => array(
					array(
						'code'     => 'UNSUPPORTED_FACT',
						'detail'   => 'seit 1990',
						'location' => 'Einleitung',
					),
				),
			),
			0.6,
			0.75
		);
		$this->assertSame( array( 'AI_UNSUPPORTED_FACT' ), array_column( $ok['blocking'], 'code' ) );
	}

	public function test_regrouped_phone_and_area_are_supported(): void {
		$context                                     = self::context();
		$context['company']['locations'][0]['phone'] = '0151 89221017 2';
		$result                                      = QualityChecker::check( self::draft( 'Uetze hat 20.723 Einwohner und wurde 1021 erstmals erwähnt. Wir sind im Umkreis von 60 km für Sie da, mobil unter +49 151 89 22 101 72.' ), $context, self::site() );
		$this->assertNotContains( 'UNSUPPORTED_NUMBER', self::codes( $result ) );
		$this->assertSame( '0151892210172', QualityChecker::phone_digits( '+49 151 89 22 101 72' ) );
	}

	public function test_phone_with_protected_spaces_and_service_numbers(): void {
		$context                                     = self::context();
		$context['company']['locations'][0]['phone'] = '05147 892210172';
		$context['company']['service_details']       = array(
			array(
				'name'        => 'Pflasterreinigung',
				'description' => 'Heißwasser mit bis zu 150 Grad',
			),
		);
		$result                                      = QualityChecker::check( self::draft( "Uetze hat 20.723 Einwohner und wurde 1021 erstmals erwähnt. Wir reinigen mit Heißwasser bis 150 Grad. Mobil: 05147\u{00A0}89\u{00A0}22\u{00A0}101\u{00A0}72." ), $context, self::site() );
		$this->assertNotContains( 'UNSUPPORTED_NUMBER', self::codes( $result ) );
	}

	public function test_digits_in_mail_and_web_addresses_are_ignored(): void {
		$result = QualityChecker::check( self::draft( 'Uetze hat 20.723 Einwohner und wurde 1021 erstmals erwähnt. Schreiben Sie an anfrage@heuristic-bhabha.89-22-101-72.plesk.page oder besuchen Sie https://heuristic-bhabha.89-22-101-72.plesk.page/kontakt/.' ), self::context(), self::site() );
		$this->assertNotContains( 'UNSUPPORTED_NUMBER', self::codes( $result ) );
	}
}
