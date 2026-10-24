/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Content- und Entity-Bericht (Stufe F): Entwürfe des Plugins, Entitäten, Hinweise „bestehende Seite verbessern“
 * mit CSV-Export für Excel.
 */
import { createElement, Fragment, useEffect, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, Spinner } from '@wordpress/components';
import { getReport } from './api';
import { dateTime, ENTITY_STATUS, ENTITY_TYPES, number, usd } from './format';
import ToolAction from './tools';
import { Competing } from './entity-homes';

const config = window.lsecAdmin || {};

const POST_STATUS = { draft: 'Entwurf', publish: 'veröffentlicht', pending: 'zur Prüfung', future: 'geplant', private: 'privat' };

const KINDS = { content: 'Inhalt', brand: 'Markensuche', navigational: 'Navigationssuche' };

function csvDownload( items ) {
	const cell = ( v ) => `"${ String( null === v || undefined === v ? '' : v ).replace( /"/g, '""' ) }"`;
	const rows = [ [ 'Seite', 'Titel', 'Art', 'Elementor', 'Keyword', 'Position', 'Impressionen', 'Klicks', 'Hinweis' ] ].concat(
		items.map( ( i ) => [ i.page_url, i.title, KINDS[ i.kind ] || '', i.elementor ? 'ja' : '', i.keyword, null === i.position ? '' : String( i.position ).replace( '.', ',' ), i.impressions, i.clicks, i.reason ] )
	);
	const blob = new window.Blob( [ '﻿' + rows.map( ( r ) => r.map( cell ).join( ';' ) ).join( '\r\n' ) ], { type: 'text/csv;charset=utf-8' } );
	const link = document.createElement( 'a' );
	link.href = window.URL.createObjectURL( blob );
	link.download = 'bestehende-seiten-verbessern.csv';
	document.body.appendChild( link );
	link.click();
	link.remove();
}

function ContentCard( { content } ) {
	const d = content.drafts;
	return (
		<Card className="lsec-wide">
			<CardHeader>
				<h2>Inhalte</h2>
				<span className="lsec-muted">
					{ content.indexed } von { content.published } Inhalten gelesen
				</span>
			</CardHeader>
			<CardBody>
				<div className="lsec-kpis">
					<div>
						<strong>{ d.count }</strong>
						<span>Entwürfe des Plugins</span>
					</div>
					<div>
						<strong>{ d.by_status.publish || 0 }</strong>
						<span>davon veröffentlicht</span>
					</div>
					<div>
						<strong>{ d.passed }</strong>
						<span>Prüfung bestanden</span>
					</div>
					<div>
						<strong>{ null === d.average_score ? '–' : number( d.average_score ) }</strong>
						<span>Ø Bewertung</span>
					</div>
					<div>
						<strong>{ usd( d.cost ) }</strong>
						<span>Kosten gesamt{ null === d.cost_per ? '' : `, je Entwurf ${ usd( d.cost_per ) }` }</span>
					</div>
				</div>
				{ content.items.length > 0 && (
					<div className="lsec-table-wrap">
						<table className="widefat striped lsec-table">
							<thead>
								<tr>
									<th>Beitrag</th>
									<th>Status</th>
									<th>Prüfung</th>
									<th className="lsec-num">Bewertung</th>
									<th className="lsec-num">Kosten</th>
									<th>Beitragsbild</th>
									<th>Stand</th>
								</tr>
							</thead>
							<tbody>
								{ content.items.map( ( i ) => (
									<tr key={ i.post_id }>
										<td>
											<a href={ i.edit_url }>{ i.title || `#${ i.post_id }` }</a>
											{ i.view_url && (
												<>
													{ ' · ' }
													<a href={ i.view_url }>ansehen</a>
												</>
											) }
										</td>
										<td>{ POST_STATUS[ i.status ] || i.status }</td>
										<td>{ i.passed ? 'bestanden' : 'blockiert' }</td>
										<td className="lsec-num">{ null === i.score ? '–' : number( i.score ) }</td>
										<td className="lsec-num">{ usd( i.cost ) }</td>
										<td>{ i.image ? IMAGE_SOURCES[ i.image ] || i.image : <span className="lsec-muted">keins</span> }</td>
										<td>{ dateTime( i.date ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</CardBody>
		</Card>
	);
}

function EntitiesCard( { entities } ) {
	const types = Object.keys( entities.counts );
	return (
		<Card className="lsec-wide">
			<CardHeader>
				<h2>Entitäten</h2>
			</CardHeader>
			<CardBody>
				<div className="lsec-table-wrap">
					<table className="widefat striped lsec-table">
						<thead>
							<tr>
								<th>Typ</th>
								{ [ 'confirmed', 'probable', 'candidate', 'rejected' ].map( ( s ) => (
									<th key={ s } className="lsec-num">
										{ ENTITY_STATUS[ s ] }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ types.map( ( t ) => (
								<tr key={ t }>
									<td>{ ENTITY_TYPES[ t ] || t }</td>
									{ [ 'confirmed', 'probable', 'candidate', 'rejected' ].map( ( s ) => (
										<td key={ s } className="lsec-num">
											{ entities.counts[ t ][ s ] || '' }
										</td>
									) ) }
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
				<h3>Am häufigsten in eigenen Inhalten</h3>
				<p>{ entities.top.length ? entities.top.map( ( e ) => `${ e.name } (${ e.posts })` ).join( ' · ' ) : 'Noch keine Fundstellen.' }</p>
				<h3>Leistungen und Orte ohne eigenen Inhalt</h3>
				<p className="lsec-muted">Bestätigte Leistungen und Orte, die in keinem Inhalt vorkommen: Kandidaten für eine Themenrecherche.</p>
				<p>{ entities.gaps.length ? entities.gaps.map( ( e ) => `${ e.name } (${ ENTITY_TYPES[ e.type ] || e.type })` ).join( ' · ' ) : 'Keine.' }</p>
			</CardBody>
		</Card>
	);
}

// Nachtrag 24.10. (0.10.5): eine Zeile je Seite mit ihren Suchanfragen; Markensuchen und Navigationssuchen getrennt.
function PageRows( { pages } ) {
	return (
		<div className="lsec-table-wrap">
			<table className="widefat striped lsec-table">
				<thead>
					<tr>
						<th>Seite</th>
						<th>Suchanfragen (Position, Impressionen)</th>
						<th>Hinweis</th>
					</tr>
				</thead>
				<tbody>
					{ pages.map( ( p ) => (
						<tr key={ `${ p.kind }-${ p.page_url }` }>
							<td>
								<a href={ p.page_url }>{ p.title || p.page_url }</a>
								<br />
								<span className="lsec-muted">{ p.type }</span>
								{ p.elementor && <span className="lsec-tag">Elementor</span> }
							</td>
							<td>
								{ p.queries.length === 0 && <span className="lsec-muted">–</span> }
								{ p.queries.length > 0 && (
									<ul className="lsec-queries">
										{ p.queries.map( ( q ) => (
											<li key={ q.keyword }>
												{ q.keyword } <span className="lsec-muted">· { number( q.position ) } · { q.impressions }</span>
											</li>
										) ) }
									</ul>
								) }
							</td>
							<td>{ p.advice }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function ImproveCard( { items, pages, brandTerms } ) {
	const content = pages.filter( ( p ) => p.kind === 'content' );
	const other = pages.filter( ( p ) => p.kind !== 'content' );
	return (
		<Card className="lsec-wide">
			<CardHeader>
				<h2>Bestehende Seiten verbessern</h2>
				{ items.length > 0 && (
					<Button variant="secondary" size="small" onClick={ () => csvDownload( items ) }>
						Als CSV herunterladen
					</Button>
				) }
			</CardHeader>
			<CardBody>
				<p className="lsec-muted">
					Eigene Seiten auf Position 4 bis 20 in der Search Console (mindestens 10 Impressionen) und verworfene Themen, für die schon eine passende Seite rankt. Hier bringt der Ausbau der bestehenden Seite meist mehr als ein neuer Beitrag.
				</p>
				{ items.length === 0 && <p>Keine Hinweise. Ohne Search Console gibt es nur Hinweise aus verworfenen Themen.</p> }
				{ content.length > 0 && <PageRows pages={ content } /> }
				{ items.length > 0 && content.length === 0 && <p>Keine Seite, bei der mehr Inhalt hilft.</p> }
				{ other.length > 0 && (
					<>
						<h3>Markensuchen und Navigationssuchen</h3>
						<p className="lsec-muted">
							Suchen nach dem eigenen Namen oder nach Öffnungszeiten, Telefon und Adresse. Mehr Text hilft hier nicht; entscheidend sind Google-Unternehmensprofil, strukturierte Daten und gut sichtbare Angaben.
							{ brandTerms.length > 0 && ` Als Name erkannt: ${ brandTerms.join( ', ' ) } (Einstellungen → Datenquellen → Markenbegriffe).` }
						</p>
						<PageRows pages={ other } />
					</>
				) }
			</CardBody>
		</Card>
	);
}

const IMAGE_SOURCES = {
	library: 'Mediathek',
	generated: 'KI-Bild',
	kept: 'vorhanden',
	none: 'keins',
	off: 'keins',
};

const IMAGE_MODES = {
	library_ai: 'aus der Mediathek, sonst KI-Bild',
	library: 'nur aus der Mediathek',
	off: 'aus',
};

// Nachtrag 24.10. (0.10.0): Bilder der Mediathek.
function ImagesCard( { images } ) {
	return (
		<Card className="lsec-wide">
			<CardHeader>
				<h2>Bilder</h2>
				<span className="lsec-muted">Beitragsbilder: { IMAGE_MODES[ images.mode ] || images.mode }</span>
			</CardHeader>
			<CardBody>
				<div className="lsec-kpis">
					<div>
						<strong>{ number( images.images ) }</strong>
						<span>Bilder in der Mediathek</span>
					</div>
					<div>
						<strong>{ number( images.described ) }</strong>
						<span>beschrieben{ images.pending > 0 ? `, ${ number( images.pending ) } offen` : '' }</span>
					</div>
					<div>
						<strong>{ number( images.free ) }</strong>
						<span>frei als Beitragsbild</span>
					</div>
					<div>
						<strong>{ number( images.used ) }</strong>
						<span>schon Beitragsbild</span>
					</div>
					<div>
						<strong>{ number( images.generated ) }</strong>
						<span>KI-Bilder</span>
					</div>
				</div>
				<p className="lsec-muted">
					Neue Bilder beschreibt der Cronjob innerhalb von etwa 15 Minuten. Logos, Symbole, Grafiken mit Text, Bilder mit Wasserzeichen und Bilder unter 800 Pixel Breite kommen nicht als Beitragsbild infrage. Für einen vorhandenen Beitrag ohne Bild: in der Beitragsliste unter dem Titel „Beitragsbild suchen“.
					{ images.free < 5 && images.mode !== 'off' && ' Es sind nur noch wenige freie Bilder da; neue Fotos in die Mediathek laden spart KI-Bilder.' }
				</p>
				{ images.pending > 0 && <ToolAction action={ { tool: 'images-describe', label: 'Offene Bilder jetzt beschreiben', kind: 'run' } } /> }
			</CardBody>
		</Card>
	);
}

/**
 * Hauptseiten je Entität (1.2.0).
 *
 * @param {Object} props       Eigenschaften.
 * @param {Object} props.homes Ergebnis von EntityHome::overview().
 */
function HomesCard( { homes } ) {
	const c = homes.counts;
	if ( c.total === 0 ) {
		return null;
	}
	const missing = homes.items.filter( ( i ) => ! i.home );
	return (
		<Card className="lsec-wide">
			<CardHeader>
				<h2>Hauptseiten</h2>
				<a href={ `${ ( config.pages || {} ).entities || '' }` }>Auf der Seite „Entitäten“ festlegen</a>
			</CardHeader>
			<CardBody>
				<p>
					{ `${ c.set } von ${ c.total } Entitäten des Unternehmens haben eine Hauptseite.` }
					{ c.suggested > 0 && ` Für ${ c.suggested } gibt es einen Vorschlag.` }
				</p>
				{ missing.length > 0 && (
					<>
						<h3>Ohne Hauptseite</h3>
						<p>
							{ missing.map( ( i ) => `${ i.name } (${ ENTITY_TYPES[ i.type ] || i.type }${ i.suggestions.length === 0 ? ', keine passende Seite' : '' })` ).join( ' · ' ) }
						</p>
						<p className="lsec-muted">„Keine passende Seite“ heißt: Keine eigene Seite trägt den Namen im Titel oder in der Adresse. Eine Seite dazu wäre eine Lücke, die sich als Thema lohnt.</p>
					</>
				) }
				<Competing items={ homes.competing } />
			</CardBody>
		</Card>
	);
}

export default function Report() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	useEffect( () => {
		getReport()
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [] );
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! data ) {
		return <Spinner />;
	}
	return (
		<div className="lsec-grid">
			<ImproveCard items={ data.improve } pages={ data.pages || [] } brandTerms={ data.brand_terms || [] } />
			<ContentCard content={ data.content } />
			<EntitiesCard entities={ data.entities } />
			{ data.homes && <HomesCard homes={ data.homes } /> }
			{ data.images && <ImagesCard images={ data.images } /> }
		</div>
	);
}
