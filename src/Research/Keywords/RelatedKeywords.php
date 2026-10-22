<?php
/**
 * Verwandte Keywords von Textfocus übernehmen.
 *
 * @package Lsec
 */

namespace Lsec\Research\Keywords;

use Lsec\Core\Plugin;
use Lsec\Integrations\Textfocus\ProviderCallRepository;
use Lsec\Integrations\Textfocus\TextfocusService;

/**
 * Textfocus tf_related liefert bis zu 100 verwandte Keywords mit Volumen. Sie werden als Quelle textfocus gespeichert;
 * danach prüft ein Keyword-Abgleich (ohne Search Console und ohne weitere Volumenabfragen) Orte, Namen und
 * Ausschlüsse (Spezifikation 6.4, Stufe C).
 */
final class RelatedKeywords {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Holt und speichert verwandte Keywords.
	 *
	 * @param string        $keyword      Ausgangs-Keyword.
	 * @param bool          $force        Zwischenspeicher umgehen.
	 * @param bool          $ignore_typos Auch bei möglicher Schreibvariante abfragen.
	 * @param \Closure|null $sleep        Wartefunktion für den Mindestabstand (WP-CLI); null = nicht warten.
	 * @return array{keyword: string, saved: int, cached: bool, run_id: int|null, typo: string|null}
	 * @throws \InvalidArgumentException Bei leerem Keyword.
	 */
	public function fetch( string $keyword, bool $force = false, bool $ignore_typos = false, ?\Closure $sleep = null ): array {
		$keyword = trim( (string) preg_replace( '/\s+/u', ' ', $keyword ) );
		if ( '' === $keyword ) {
			throw new \InvalidArgumentException( 'Bitte ein Keyword angeben.' );
		}
		$repo = new KeywordRepository();
		if ( ! $ignore_typos ) {
			$suggestion = ( new TypoChecker( $repo->known_terms() ) )->suggest( $keyword );
			if ( null !== $suggestion && KeywordNormalizer::normalize( $suggestion ) !== KeywordNormalizer::normalize( $keyword ) ) {
				return array(
					'keyword' => $keyword,
					'saved'   => 0,
					'cached'  => false,
					'run_id'  => null,
					'typo'    => $suggestion,
				);
			}
		}
		$tf      = TextfocusService::from_settings( $this->plugin->http, $this->plugin->secrets, new ProviderCallRepository(), $sleep );
		$lang    = TextfocusService::default_lang();
		$out     = $tf->query(
			'tf_related',
			array(
				'lang'    => $lang,
				'keyword' => $keyword,
			),
			$force,
			null !== $sleep
		);
		$entries = TextfocusService::parse_related_result( $out['result'] );
		$saved   = $repo->save_metrics( TextfocusService::PROVIDER, $entries, TextfocusService::locale_for( $lang ) );
		$run     = KeywordSyncWorkflow::start(
			$this->plugin,
			array(
				'gsc'     => false,
				'volumes' => false,
				'reason'  => 'related',
			)
		);
		return array(
			'keyword' => $keyword,
			'saved'   => $saved,
			'cached'  => $out['cached'],
			'run_id'  => null === $run ? null : $run['run_id'],
			'typo'    => null,
		);
	}
}
