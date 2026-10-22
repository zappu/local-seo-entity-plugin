/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Themen: Recherche mit Websuche starten, Vorschläge mit Priorität, Begründung und geprüften Tatsachen,
 * Freigabe oder Ablehnung, Entscheidungen über einzelne Tatsachen, Überschneidungen mit bestehenden Seiten.
 */
import { createElement, Fragment, useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { decideClaim, decideTopic, getTopic, getTopics, startResearch } from './api';
import { CLAIM_STATUS, dateTime, number, RUN_STATUS, TOPIC_FORMATS, TOPIC_STATUS, usd } from './format';

const FILTERS = [
	{ label: 'offene Vorschläge', value: 'proposed' },
	{ label: 'freigegeben', value: 'approved' },
	{ label: 'abgelehnt', value: 'rejected' },
	{ label: 'bestehende Seite verbessern', value: 'discarded' },
	{ label: 'alle (ohne bestehende Seiten)', value: '' },
];

const CLAIM_BADGE = {
	verified: 'e-confirmed',
	confirmed: 'e-confirmed',
	disputed: 'e-probable',
	rejected: 'e-rejected',
	candidate: 'e-candidate',
};

function ResearchCard( { data, onStarted, setNotice } ) {
	const [ service, setService ] = useState( '' );
	const [ region, setRegion ] = useState( '' );
	const [ count, setCount ] = useState( String( data.settings.count ) );
	const [ busy, setBusy ] = useState( false );
	const latest = data.latest;
	const running = latest && [ 'queued', 'running', 'waiting' ].includes( latest.status );

	const start = ( force ) => {
		setBusy( true );
		startResearch( { service, region, count: Number( count ) || data.settings.count, force } )
			.then( ( run ) => {
				setNotice( { status: 'success', text: `Recherche eingeplant (Ablauf ${ run.run_id }). Sie läuft über den Cronjob und dauert einige Minuten; die Vorschläge erscheinen danach hier.` } );
				onStarted();
			} )
			.catch( ( e ) => {
				if ( e.code === 'lsec_recent' ) {
					// eslint-disable-next-line no-alert
					if ( window.confirm( `${ e.message }\n\nTrotzdem neu recherchieren?` ) ) {
						start( true );
						return;
					}
				} else {
					setNotice( { status: 'error', text: e.message } );
				}
			} )
			.finally( () => setBusy( false ) );
	};

	const onStart = () => {
		// eslint-disable-next-line no-alert
		if ( window.confirm( 'Recherche mit Websuche starten? Sie kostet etwa 0,05 bis 0,20 USD (im Rahmen des KI-Budgets).' ) ) {
			start( false );
		}
	};

	return (
		<Card className="lsec-wide">
			<CardHeader>
				<h2>Neue Recherche</h2>
			</CardHeader>
			<CardBody>
				<div className="lsec-filters">
					<SelectControl
						label="Leistung"
						value={ service }
						options={ [ { label: '– alle –', value: '' } ].concat( data.services.map( ( s ) => ( { label: s, value: s } ) ) ) }
						onChange={ setService }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl label="Ort" value={ region } placeholder={ data.area || 'z. B. Lehrte' } onChange={ setRegion } __nextHasNoMarginBottom __next40pxDefaultSize />
					<TextControl label="Anzahl Themen" type="number" min={ 1 } max={ 10 } value={ count } onChange={ setCount } __nextHasNoMarginBottom __next40pxDefaultSize />
					<Button variant="primary" onClick={ onStart } isBusy={ busy } disabled={ busy || running }>
						Recherche starten
					</Button>
				</div>
				<p className="lsec-muted">
					Websuche mit { data.settings.web_results } Treffern{ data.settings.research ? `, Recherchemodell ${ data.settings.research }` : '' }. Jede Tatsache wird danach auf ihrer Quelle gesucht; nur wörtlich belegte gelten als geprüft.
				</p>
				{ latest && (
					<p>
						Letzte Recherche: { RUN_STATUS[ latest.status ] || latest.status }
						{ running && latest.step && ` (Schritt ${ latest.step })` }, { dateTime( latest.created_at ) }
						{ latest.focus && ( latest.focus.service || latest.focus.region ) && ` · ${ [ latest.focus.service, latest.focus.region ].filter( Boolean ).join( ' in ' ) }` }
						{ ` · ${ usd( latest.cost ) }` }
						{ latest.error_message && <span className="lsec-muted"> · { latest.error_message }</span> }
					</p>
				) }
			</CardBody>
		</Card>
	);
}

function Breakdown( { breakdown, labels } ) {
	const parts = ( breakdown && breakdown.breakdown ) || {};
	return (
		<table className="widefat lsec-table lsec-breakdown">
			<tbody>
				{ Object.entries( parts ).map( ( [ name, part ] ) => (
					<tr key={ name }>
						<td>{ labels[ name ] || name }</td>
						<td className="lsec-num">{ part.value === null ? <span className="lsec-muted">fehlt</span> : `${ Math.round( part.value * 100 ) } %` }</td>
						<td className="lsec-num">{ part.points === null ? '–' : `${ part.points.toLocaleString( 'de-DE' ) } P.` }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

function TopicDetail( { id, labels, setNotice } ) {
	const [ topic, setTopic ] = useState( null );
	const load = useCallback( () => {
		getTopic( id ).then( setTopic ).catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	}, [ id, setNotice ] );
	useEffect( load, [ load ] );

	if ( ! topic ) {
		return <Spinner />;
	}
	const onFact = ( claimId, decision ) => {
		decideClaim( claimId, decision ).then( load ).catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	};
	const breakdown = topic.score_breakdown || {};
	return (
		<div className="lsec-topic__detail">
			<div className="lsec-topic__columns">
				<div>
					<h3>Priorität</h3>
					<Breakdown breakdown={ breakdown } labels={ labels } />
					{ breakdown.limited && <p className="lsec-muted">Eingeschränkte Datenbasis: Es fehlen { ( breakdown.missing || [] ).filter( ( m ) => m !== 'season' ).map( ( m ) => labels[ m ] || m ).join( ', ' ) }.</p> }
					{ breakdown.local_note && <p className="lsec-muted">Lokaler Bezug: { breakdown.local_note }.</p> }
					{ breakdown.insufficient_data && <p className="lsec-muted">Die Recherche hält dieses Thema für schwach gestützt.</p> }
				</div>
				<div>
					<h3>Keywords</h3>
					{ topic.keywords.length === 0 && <p className="lsec-muted">Keine.</p> }
					<ul>
						{ topic.keywords.map( ( k ) => (
							<li key={ k.id }>
								{ k.keyword }
								{ k.role === 'primary' && <span className="lsec-tag">Haupt</span> }
								<span className="lsec-muted">
									{ k.volume !== null && ` · ${ number( k.volume ) } Suchen` }
									{ k.impressions !== null && ` · ${ number( k.impressions ) } Impr.` }
								</span>
							</li>
						) ) }
					</ul>
					<h3>Entitäten</h3>
					<p>{ topic.entities.length ? topic.entities.map( ( e ) => e.name ).join( ', ' ) : <span className="lsec-muted">Keine.</span> }</p>
				</div>
			</div>
			<h3>Tatsachen</h3>
			{ topic.facts.length === 0 && <p className="lsec-muted">Keine Tatsachen aus der Recherche.</p> }
			<ul className="lsec-facts">
				{ topic.facts.map( ( f ) => (
					<li key={ f.id }>
						<span className={ `lsec-badge lsec-badge--${ CLAIM_BADGE[ f.status ] || 'e-candidate' }` }>{ CLAIM_STATUS[ f.status ] || f.status }</span>
						{ ' ' }
						<strong>{ f.entity }</strong>: { f.property } = { f.value }
						{ f.quote && <blockquote>„{ f.quote }“</blockquote> }
						<span className="lsec-muted">
							{ f.source_url && (
								<a href={ f.source_url } target="_blank" rel="noreferrer">{ f.source_title || f.source_url }</a>
							) }
							{ f.note && ` · ${ f.note }` }
						</span>
						{ ' ' }
						{ f.status !== 'confirmed' && <Button variant="link" onClick={ () => onFact( f.id, 'confirmed' ) }>bestätigen</Button> }
						{ f.status !== 'rejected' && (
							<Button variant="link" isDestructive onClick={ () => onFact( f.id, 'rejected' ) }>
								verwerfen
							</Button>
						) }
					</li>
				) ) }
			</ul>
			{ topic.overlap && topic.overlap.items && topic.overlap.items.length > 0 && (
				<>
					<h3>Überschneidung mit bestehenden Seiten</h3>
					<ul>
						{ topic.overlap.items.map( ( item, i ) => (
							<li key={ i }>
								<a href={ item.url } target="_blank" rel="noreferrer">{ item.url }</a> – { item.reason }
							</li>
						) ) }
					</ul>
				</>
			) }
		</div>
	);
}

function TopicRow( { topic, labels, onDecide, busy, setNotice } ) {
	const [ open, setOpen ] = useState( false );
	return (
		<div className={ `lsec-topic lsec-topic--${ topic.status }` }>
			<div className="lsec-topic__head">
				<div className="lsec-topic__score" title="Priorität (0–100)">
					{ topic.priority_score === null ? '–' : Math.round( topic.priority_score ) }
				</div>
				<div className="lsec-topic__main">
					<h3>
						<Button variant="link" onClick={ () => setOpen( ( o ) => ! o ) } aria-expanded={ open }>{ topic.title }</Button>
					</h3>
					<p className="lsec-muted">
						{ TOPIC_FORMATS[ topic.format ] || topic.format }
						{ topic.region && ` · ${ topic.region }` }
						{ topic.primary_keywords && ` · ${ topic.primary_keywords }` }
						{ ` · ${ TOPIC_STATUS[ topic.status ] || topic.status }` }
					</p>
					{ topic.rationale && <p>{ topic.rationale }</p> }
				</div>
				<div className="lsec-actions">
					{ topic.status !== 'approved' && topic.status !== 'discarded' && (
						<Button variant="primary" size="small" disabled={ busy } onClick={ () => onDecide( topic.id, 'approved' ) }>Freigeben</Button>
					) }
					{ topic.status !== 'rejected' && (
						<Button variant="tertiary" size="small" isDestructive disabled={ busy } onClick={ () => onDecide( topic.id, 'rejected' ) }>Ablehnen</Button>
					) }
					{ ( topic.status === 'approved' || topic.status === 'rejected' || topic.status === 'discarded' ) && (
						<Button variant="tertiary" size="small" disabled={ busy } onClick={ () => onDecide( topic.id, 'proposed' ) }>
							{ topic.status === 'discarded' ? 'doch vorschlagen' : 'zurücksetzen' }
						</Button>
					) }
				</div>
			</div>
			{ open && <TopicDetail id={ topic.id } labels={ labels } setNotice={ setNotice } /> }
		</div>
	);
}

export default function Topics() {
	const [ status, setStatus ] = useState( 'proposed' );
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( () => {
		getTopics( status )
			.then( setData )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	}, [ status ] );

	useEffect( () => {
		load();
		const timer = setInterval( load, 30000 );
		return () => clearInterval( timer );
	}, [ load ] );

	const onDecide = ( id, decision ) => {
		setBusy( true );
		decideTopic( id, decision )
			.then( load )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( false ) );
	};

	if ( ! data ) {
		return notice ? <Notice status="error" isDismissible={ false }>{ notice.text }</Notice> : <Spinner />;
	}
	const counts = data.counts || {};
	return (
		<>
			{ notice && <Notice status={ notice.status } onRemove={ () => setNotice( null ) }>{ notice.text }</Notice> }
			<div className="lsec-grid">
				<ResearchCard data={ data } onStarted={ load } setNotice={ setNotice } />
				<Card className="lsec-wide">
					<CardHeader>
						<div className="lsec-filters">
							<SelectControl
								label="Anzeigen"
								value={ status }
								options={ FILTERS.map( ( f ) => ( { label: `${ f.label }${ f.value && counts[ f.value ] ? ` (${ counts[ f.value ] })` : '' }`, value: f.value } ) ) }
								onChange={ setStatus }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						</div>
					</CardHeader>
					<CardBody>
						{ status === 'discarded' && (
							<p className="lsec-muted">Diese Themen deckt schon eine eigene Seite ab, die für das Haupt-Keyword rankt oder es im Titel trägt. Statt eines neuen Beitrags lohnt es sich, die bestehende Seite zu verbessern (Ausbau 2).</p>
						) }
						{ data.topics.length === 0 && <p>Keine Themen für diese Auswahl.</p> }
						{ data.topics.map( ( t ) => (
							<TopicRow key={ t.id } topic={ t } labels={ data.labels } onDecide={ onDecide } busy={ busy } setNotice={ setNotice } />
						) ) }
					</CardBody>
				</Card>
			</div>
		</>
	);
}
