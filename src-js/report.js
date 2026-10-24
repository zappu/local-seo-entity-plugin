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

const POST_STATUS = { draft: 'Entwurf', publish: 'veröffentlicht', pending: 'zur Prüfung', future: 'geplant', private: 'privat' };

function csvDownload( items ) {
	const cell = ( v ) => `"${ String( null === v || undefined === v ? '' : v ).replace( /"/g, '""' ) }"`;
	const rows = [ [ 'Seite', 'Titel', 'Keyword', 'Position', 'Impressionen', 'Klicks', 'Hinweis' ] ].concat(
		items.map( ( i ) => [ i.page_url, i.title, i.keyword, null === i.position ? '' : String( i.position ).replace( '.', ',' ), i.impressions, i.clicks, i.reason ] )
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

function ImproveCard( { items } ) {
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
				{ items.length > 0 && (
					<div className="lsec-table-wrap">
						<table className="widefat striped lsec-table">
							<thead>
								<tr>
									<th>Seite</th>
									<th>Keyword</th>
									<th className="lsec-num">Position</th>
									<th className="lsec-num">Impressionen</th>
									<th>Hinweis</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( i, index ) => (
									<tr key={ index }>
										<td>
											<a href={ i.page_url }>{ i.title || i.page_url }</a>
										</td>
										<td>{ i.keyword }</td>
										<td className="lsec-num">{ null === i.position ? '–' : number( i.position ) }</td>
										<td className="lsec-num">{ null === i.impressions ? '–' : i.impressions }</td>
										<td>{ i.reason }</td>
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
					Neue Bilder beschreibt der Cronjob innerhalb von etwa 15 Minuten. Logos, Symbole, Grafiken mit Text, Bilder mit Wasserzeichen und Bilder unter 800 Pixel Breite kommen nicht als Beitragsbild infrage.
					{ images.free < 5 && images.mode !== 'off' && ' Es sind nur noch wenige freie Bilder da; neue Fotos in die Mediathek laden spart KI-Bilder.' }
				</p>
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
			<ImproveCard items={ data.improve } />
			<ContentCard content={ data.content } />
			<EntitiesCard entities={ data.entities } />
			{ data.images && <ImagesCard images={ data.images } /> }
		</div>
	);
}
