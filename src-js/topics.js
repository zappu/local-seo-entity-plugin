/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Themen: Recherche mit Websuche starten, Vorschläge mit Priorität, Begründung und geprüften Tatsachen,
 * Freigabe oder Ablehnung, Entscheidungen über einzelne Tatsachen, Überschneidungen mit bestehenden Seiten.
 * Ab Stufe E: Beitrag schreiben, Entwurf mit Prüfbericht (Kosten, Bewertung, Mängel).
 */
import { createElement, Fragment, useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, CheckboxControl, Modal, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { batchProduce, decideClaim, decideTopic, getTopic, getTopics, produceTopic, startResearch } from './api';
import { CLAIM_STATUS, dateTime, number, RUN_STATUS, TOPIC_FORMATS, TOPIC_STATUS, usd } from './format';

const FILTERS = [
	{ label: 'offene Vorschläge', value: 'proposed' },
	{ label: 'freigegeben', value: 'approved' },
	{ label: 'in Arbeit', value: 'in_production' },
	{ label: 'Entwurf erstellt', value: 'produced' },
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

const SCORE_LABELS = {
	intent: 'Suchintention',
	local_relevance: 'Lokale Relevanz',
	entity_integration: 'Entity-Integration',
	value: 'Mehrwert',
	readability: 'Lesbarkeit',
	style_match: 'Stil',
};

const editUrl = ( postId ) => `post.php?post=${ postId }&action=edit`;

function Production( { report } ) {
	const running = [ 'queued', 'running', 'waiting' ].includes( report.status );
	return (
		<div className="lsec-production">
			<h3>Beitrag</h3>
			<p>
				Ablauf { report.run } · { RUN_STATUS[ report.status ] || report.status }
				{ running && report.step && ` (Schritt ${ report.step })` }
				{ report.model && ` · ${ report.model }` }
				{ ` · ${ usd( report.cost_usd ) }` }
				{ report.error && <span className="lsec-muted"> · { report.error }</span> }
			</p>
			{ report.post_id > 0 && (
				<p>
					<a href={ editUrl( report.post_id ) }>Entwurf „{ report.title }“ bearbeiten</a>
					{ report.preview && (
						<>
							{ ' · ' }
							<a href={ report.preview } target="_blank" rel="noreferrer">Vorschau</a>
						</>
					) }
					{ report.words !== null && <span className="lsec-muted"> · { number( report.words ) } Wörter</span> }
				</p>
			) }
			{ report.score !== null && (
				<>
					<p>
						<span className={ `lsec-badge lsec-badge--${ report.passed ? 'e-confirmed' : 'e-rejected' }` }>{ report.passed ? 'Prüfung bestanden' : 'blockiert' }</span>
						{ ` Bewertung ${ Math.round( report.score ) }/100` }
					</p>
					{ report.scores && (
						<table className="widefat lsec-table lsec-breakdown">
							<tbody>
								{ Object.entries( report.scores ).map( ( [ key, value ] ) => (
									<tr key={ key }>
										<td>{ SCORE_LABELS[ key ] || key }</td>
										<td className="lsec-num">{ Math.round( value * 100 ) } %</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</>
			) }
			{ report.blocking && report.blocking.length > 0 && (
				<>
					<h4>Mängel, die eine Veröffentlichung verhindern</h4>
					<ul className="lsec-issues">
						{ report.blocking.map( ( issue, i ) => (
							<li key={ i }>
								<code>{ issue.code }</code> { issue.detail }
							</li>
						) ) }
					</ul>
				</>
			) }
			{ report.warnings && report.warnings.length > 0 && (
				<>
					<h4>Hinweise</h4>
					<ul className="lsec-issues lsec-muted">
						{ report.warnings.map( ( issue, i ) => (
							<li key={ i }>
								<code>{ issue.code }</code> { issue.detail }
							</li>
						) ) }
					</ul>
				</>
			) }
		</div>
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
			{ topic.production && <Production report={ topic.production } /> }
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

// Nachtrag 24.10. (0.9.2): Doppelte Themen nebeneinander vergleichen und eines davon ablehnen.
function CompareColumn( { topic, label, onDecide, busy } ) {
	const fixed = topic.status === 'in_production' || topic.status === 'produced' || topic.status === 'rejected';
	const primary = topic.keywords.filter( ( k ) => k.role === 'primary' );
	const other = topic.keywords.filter( ( k ) => k.role !== 'primary' );
	const kw = ( k ) => (
		<li key={ k.id }>
			{ k.keyword }
			<span className="lsec-muted">
				{ k.volume !== null && ` · ${ number( k.volume ) } Suchen` }
				{ k.impressions !== null && ` · ${ number( k.impressions ) } Impr.` }
			</span>
		</li>
	);
	return (
		<div className="lsec-compare__col">
			<p className="lsec-compare__label">{ label }</p>
			<h3>Thema #{ topic.id }: { topic.title }</h3>
			<p className="lsec-muted">
				{ TOPIC_FORMATS[ topic.format ] || topic.format }
				{ topic.region && ` · ${ topic.region }` }
				{ ` · ${ TOPIC_STATUS[ topic.status ] || topic.status }` }
				{ ` · Priorität ${ topic.priority_score === null ? '–' : Math.round( topic.priority_score ) }` }
			</p>
			<h4>Haupt-Keywords</h4>
			{ primary.length === 0 ? <p className="lsec-muted">Keine.</p> : <ul>{ primary.map( kw ) }</ul> }
			{ other.length > 0 && (
				<>
					<h4>Weitere Keywords</h4>
					<ul>{ other.map( kw ) }</ul>
				</>
			) }
			<h4>Begründung</h4>
			<p>{ topic.rationale || <span className="lsec-muted">Keine.</span> }</p>
			{ topic.entities.length > 0 && (
				<>
					<h4>Entitäten</h4>
					<p>{ topic.entities.map( ( e ) => e.name ).join( ', ' ) }</p>
				</>
			) }
			<div className="lsec-compare__actions">
				{ Number( topic.post_id ) > 0 && (
					<Button variant="secondary" size="small" href={ editUrl( topic.post_id ) }>Entwurf öffnen</Button>
				) }
				{ ! fixed && (
					<Button variant="secondary" size="small" isDestructive disabled={ busy } onClick={ () => onDecide( topic.id, 'rejected' ) }>
						Thema #{ topic.id } ablehnen
					</Button>
				) }
			</div>
		</div>
	);
}

function CompareModal( { ids, onDecide, onClose, busy } ) {
	const [ topics, setTopics ] = useState( null );
	const [ error, setError ] = useState( '' );
	useEffect( () => {
		Promise.all( ids.map( ( id ) => getTopic( id ) ) )
			.then( setTopics )
			.catch( ( e ) => setError( e.message ) );
	}, [ ids ] );
	return (
		<Modal title={ `Doppelte Themen vergleichen: #${ ids[ 0 ] } und #${ ids[ 1 ] }` } onRequestClose={ onClose } className="lsec-compare-modal">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! topics && ! error && <Spinner /> }
			{ topics && (
				<>
					<p className="lsec-muted">Zwei Seiten zum selben Thema würden in Google gegeneinander ranken. Behalte das stärkere Thema und lehne das andere ab; brauchbare Keywords oder Gedanken des abgelehnten fließen beim Schreiben nicht automatisch ein.</p>
					<div className="lsec-compare">
						<CompareColumn topic={ topics[ 0 ] } label="dieses Thema" onDecide={ onDecide } busy={ busy } />
						<CompareColumn topic={ topics[ 1 ] } label="doppelt zu" onDecide={ onDecide } busy={ busy } />
					</div>
				</>
			) }
		</Modal>
	);
}

function TopicRow( { topic, labels, onDecide, onProduce, onCompare, busy, setNotice, selected, onSelect } ) {
	const [ open, setOpen ] = useState( false );
	const producing = topic.status === 'in_production' || topic.status === 'produced';
	return (
		<div className={ `lsec-topic lsec-topic--${ topic.status }` }>
			<div className={ `lsec-topic__head${ onSelect ? ' lsec-topic__head--select' : '' }` }>
				{ onSelect && (
					<CheckboxControl
						className="lsec-topic__select"
						label={ <span className="screen-reader-text">Für den Sammelauftrag auswählen</span> }
						checked={ selected }
						onChange={ () => onSelect( topic.id ) }
						__nextHasNoMarginBottom
					/>
				) }
				<div className="lsec-topic__score" title="Priorität (0–100)">
					{ topic.priority_score === null ? '–' : Math.round( topic.priority_score ) }
				</div>
				<div className="lsec-topic__main">
					<h3>
						<Button variant="link" onClick={ () => setOpen( ( o ) => ! o ) } aria-expanded={ open }>{ topic.title }</Button>
					</h3>
					<p className="lsec-muted">
						<strong className="lsec-topic__id">#{ topic.id }</strong>
						{ ` · ${ TOPIC_FORMATS[ topic.format ] || topic.format }` }
						{ topic.region && ` · ${ topic.region }` }
						{ topic.primary_keywords && ` · ${ topic.primary_keywords }` }
						{ ` · ${ TOPIC_STATUS[ topic.status ] || topic.status }` }
						{ topic.quality && ` · ${ topic.quality.passed ? 'Prüfung bestanden' : 'blockiert' }${ topic.quality.score !== null ? ` (${ Math.round( topic.quality.score ) }/100)` : '' }` }
					</p>
					{ topic.duplicate_of && (
						<p className="lsec-duplicate">
							Doppelt zu #{ topic.duplicate_of.id } „{ topic.duplicate_of.title }“ ({ TOPIC_STATUS[ topic.duplicate_of.status ] || topic.duplicate_of.status }). Zwei Seiten zum selben Thema würden gegeneinander ranken; eines davon ablehnen.
							{ ' ' }
							<Button variant="link" onClick={ () => onCompare( [ topic.id, topic.duplicate_of.id ] ) }>Vergleichen</Button>
						</p>
					) }
					{ topic.duplicates && topic.duplicates.map( ( twin ) => (
						<p key={ twin.id } className="lsec-duplicate">
							Doppelt zu #{ twin.id } „{ twin.title }“ ({ TOPIC_STATUS[ twin.status ] || twin.status }).
							{ ' ' }
							<Button variant="link" onClick={ () => onCompare( [ topic.id, twin.id ] ) }>Vergleichen</Button>
						</p>
					) ) }
					{ topic.rationale && <p>{ topic.rationale }</p> }
				</div>
				<div className="lsec-actions">
					{ topic.status === 'approved' && (
						<Button variant="primary" size="small" disabled={ busy } onClick={ () => onProduce( topic.id, false ) }>Beitrag schreiben</Button>
					) }
					{ topic.post_id > 0 && (
						<Button variant="secondary" size="small" href={ editUrl( topic.post_id ) }>Entwurf</Button>
					) }
					{ topic.status === 'produced' && (
						<Button variant="tertiary" size="small" disabled={ busy } onClick={ () => onProduce( topic.id, true ) }>neu schreiben</Button>
					) }
					{ ! producing && topic.status !== 'approved' && topic.status !== 'discarded' && (
						<Button variant="primary" size="small" disabled={ busy } onClick={ () => onDecide( topic.id, 'approved' ) }>Freigeben</Button>
					) }
					{ ! producing && topic.status !== 'rejected' && (
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
	const [ selected, setSelected ] = useState( [] );
	const [ compare, setCompare ] = useState( null );
	useEffect( () => setSelected( [] ), [ status ] );

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

	const onProduce = ( id, again ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( `${ again ? 'Neuen Entwurf' : 'Entwurf' } schreiben? Briefing, Text und Prüfung kosten zusammen etwa 0,05 bis 0,30 USD (im Rahmen des KI-Budgets).` ) ) {
			return;
		}
		setBusy( true );
		produceTopic( id, again )
			.then( ( run ) => {
				setNotice( { status: 'success', text: `Entwurf eingeplant (Ablauf ${ run.run_id }). Er entsteht über den Cronjob in einigen Minuten.` } );
				load();
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( false ) );
	};

	const onSelect = ( id ) =>
		setSelected( ( list ) => ( list.includes( id ) ? list.filter( ( x ) => x !== id ) : list.concat( [ id ] ) ) );

	// Sammelauftrag (Stufe F): erst die Schätzung, nach Bestätigung starten.
	const onBatch = () => {
		setBusy( true );
		batchProduce( selected, false )
			.then( ( estimate ) => {
				const budget = null === estimate.budget_left ? '' : ` Im Monatsbudget sind noch ${ usd( estimate.budget_left ) } frei.`;
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( `${ estimate.count } Entwürfe schreiben? Geschätzte Kosten etwa ${ usd( estimate.estimate ) } (je Entwurf etwa ${ usd( estimate.per_draft ) }).${ budget } Die Abläufe arbeitet der Cronjob nacheinander ab.` ) ) {
					return null;
				}
				return batchProduce( selected, true ).then( ( result ) => {
					setNotice( { status: 'success', text: `${ result.started.length } Entwürfe eingeplant (Abläufe ${ result.started.join( ', ' ) }). Stand auf der Seite „Abläufe“.` } );
					setSelected( [] );
					load();
				} );
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( false ) );
	};

	const onDecide = ( id, decision ) => {
		setBusy( true );
		decideTopic( id, decision )
			.then( () => {
				setCompare( null );
				load();
			} )
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
						{ status === 'approved' && data.production && (
							<p className="lsec-muted">
								{ data.production.auto ? `Der Cronjob schreibt freigegebene Themen automatisch, höchstens ${ data.production.per_day } am Tag (heute gestartet: ${ data.production.today }).` : 'Automatisches Schreiben ist ausgeschaltet (Einstellungen → Beiträge); „Beitrag schreiben“ startet einen Entwurf von Hand.' }
							</p>
						) }
						{ data.topics.length === 0 && <p>Keine Themen für diese Auswahl.</p> }
						{ status === 'approved' && data.topics.length > 1 && (
							<div className="lsec-batch">
								<CheckboxControl
									label="Alle auswählen"
									checked={ selected.length > 0 && selected.length === data.topics.filter( ( t ) => t.status === 'approved' ).length }
									onChange={ ( all ) => setSelected( all ? data.topics.filter( ( t ) => t.status === 'approved' ).map( ( t ) => t.id ).slice( 0, 20 ) : [] ) }
									__nextHasNoMarginBottom
								/>
								<Button variant="primary" disabled={ busy || selected.length === 0 } onClick={ onBatch }>
									Ausgewählte schreiben ({ selected.length })
								</Button>
								<span className="lsec-muted">höchstens 20 auf einmal; vor dem Start erscheint die Kostenschätzung</span>
							</div>
						) }
						{ data.topics.map( ( t ) => (
							<TopicRow
								key={ t.id }
								topic={ t }
								labels={ data.labels }
								onDecide={ onDecide }
								onProduce={ onProduce }
								onCompare={ setCompare }
								busy={ busy }
								setNotice={ setNotice }
								selected={ selected.includes( t.id ) }
								onSelect={ status === 'approved' && t.status === 'approved' ? onSelect : null }
							/>
						) ) }
					</CardBody>
				</Card>
			</div>
			{ compare && <CompareModal ids={ compare } onDecide={ onDecide } onClose={ () => setCompare( null ) } busy={ busy } /> }
		</>
	);
}
