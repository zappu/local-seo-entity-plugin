/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Hauptseite je Entität (1.2.0): Übersicht mit Vorschlägen und Konkurrenz eigener Seiten, Auswahl im Detail.
 */
import { createElement, Fragment, useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, Spinner, TextControl } from '@wordpress/components';
import { getEntityHomes, getHomeCandidates, setEntityHome } from './api';
import { ENTITY_TYPES } from './format';

/**
 * Link auf eine Seite.
 *
 * @param {Object} props      Eigenschaften.
 * @param {Object} props.page Seite (title, url).
 */
function PageLink( { page } ) {
	return <a href={ page.url } target="_blank" rel="noreferrer">{ page.title || page.url }</a>;
}

/**
 * Vorschlag mit Gründen und Knopf.
 *
 * @param {Object}   props            Eigenschaften.
 * @param {Object}   props.suggestion Vorschlag.
 * @param {Function} props.onPick     Übernehmen.
 * @param {boolean}  props.busy       Speichert gerade.
 */
function Suggestion( { suggestion, onPick, busy } ) {
	return (
		<div className="lsec-home__suggestion">
			<PageLink page={ suggestion } />
			{ suggestion.reasons && suggestion.reasons.length > 0 && <span className="lsec-muted"> – { suggestion.reasons.join( ', ' ) }</span> }
			{ ' ' }
			<Button variant="secondary" size="small" disabled={ busy } onClick={ () => onPick( suggestion.post_id, 'suggestion' ) }>Als Hauptseite festlegen</Button>
		</div>
	);
}

/**
 * Abschnitt „Hauptseite“ im Detail einer Entität.
 *
 * @param {Object}   props           Eigenschaften.
 * @param {Object}   props.entity    Entität aus getEntity.
 * @param {Function} props.onChanged Nach dem Speichern (Text der Meldung).
 */
export function HomeSection( { entity, onChanged } ) {
	const [ home, setHome ] = useState( entity.home || null );
	const [ picking, setPicking ] = useState( false );
	const [ candidates, setCandidates ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const load = ( term ) => {
		setCandidates( ( c ) => ( c ? { ...c, loading: true } : null ) );
		getHomeCandidates( entity.id, term )
			.then( setCandidates )
			.catch( ( e ) => setError( e.message ) );
	};

	const open = () => {
		setPicking( true );
		load( '' );
	};

	const pick = ( postId, source ) => {
		setBusy( true );
		setError( null );
		setEntityHome( entity.id, postId, source )
			.then( ( out ) => {
				setHome( out.home );
				setPicking( false );
				onChanged( out.home ? `Hauptseite von „${ entity.canonical_name }“: ${ out.home.title }.` : `Hauptseite von „${ entity.canonical_name }“ entfernt.` );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	if ( entity.status !== 'confirmed' || entity.type === 'competitor' ) {
		return null;
	}
	return (
		<div className="lsec-home">
			<h3>Hauptseite</h3>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			<p className="lsec-muted">Die eine eigene Seite, die diese Entität maßgeblich beschreibt. Entwürfe verlinken sie, die strukturierten Daten nennen sie als Adresse der Entität.</p>
			{ entity.home_stale && ! home && <Notice status="warning" isDismissible={ false }>Die festgelegte Hauptseite ist nicht mehr veröffentlicht. Bitte eine neue wählen.</Notice> }
			<p>
				{ home ? <PageLink page={ home } /> : <span className="lsec-muted">Keine festgelegt.</span> }
				{ ' ' }
				{ ! picking && <Button variant="secondary" size="small" onClick={ open }>{ home ? 'Ändern' : 'Festlegen …' }</Button> }
				{ home && ! picking && <Button variant="tertiary" size="small" isDestructive disabled={ busy } onClick={ () => pick( 0, 'manual' ) }>Entfernen</Button> }
			</p>
			{ picking && (
				<div className="lsec-home__picker">
					{ ! candidates && <Spinner /> }
					{ candidates && (
						<>
							<p><strong>Vorschläge</strong></p>
							{ candidates.suggestions.length === 0 && <p className="lsec-muted">Keine Seite trägt den Namen im Titel oder in der Adresse. Seite unten suchen oder zuerst eine Seite zu dieser Entität anlegen.</p> }
							{ candidates.suggestions.map( ( s ) => <Suggestion key={ s.post_id } suggestion={ s } onPick={ pick } busy={ busy } /> ) }
							<div className="lsec-home__search">
								<TextControl label="Eigene Seite suchen (Titel)" value={ search } onChange={ setSearch } __nextHasNoMarginBottom __next40pxDefaultSize />
								<Button variant="secondary" disabled={ search.trim().length < 2 || candidates.loading } onClick={ () => load( search.trim() ) }>Suchen</Button>
							</div>
							{ candidates.results.map( ( r ) => (
								<div key={ r.post_id } className="lsec-home__suggestion">
									<PageLink page={ r } />
									<span className="lsec-muted"> ({ r.kind })</span>
									{ ' ' }
									<Button variant="secondary" size="small" disabled={ busy } onClick={ () => pick( r.post_id, 'manual' ) }>Als Hauptseite festlegen</Button>
								</div>
							) ) }
							<p><Button variant="tertiary" onClick={ () => setPicking( false ) }>Abbrechen</Button></p>
						</>
					) }
				</div>
			) }
		</div>
	);
}

/**
 * Seiten, die sich Konkurrenz machen.
 *
 * @param {Object} props       Eigenschaften.
 * @param {Array}  props.items Einträge aus overview().competing.
 */
export function Competing( { items } ) {
	if ( ! items || items.length === 0 ) {
		return null;
	}
	return (
		<>
			<h3>Seiten, die sich Konkurrenz machen</h3>
			<p className="lsec-muted">Suchanfragen aus der Search Console, für die mehrere eigene Seiten erscheinen. Google muss dann raten, welche gemeint ist; meist rankt keine so gut wie eine klare Hauptseite.</p>
			{ items.map( ( item ) => (
				<div key={ item.entity_id } className="lsec-home__competing">
					<p><strong>{ item.name }</strong> – { item.query_count } { item.query_count === 1 ? 'Suchanfrage' : 'Suchanfragen' }, { item.pages } Seiten. { item.advice }</p>
					<ul>
						{ item.queries.map( ( q ) => (
							<li key={ q.keyword }>
								„{ q.keyword }“:{ ' ' }
								{ q.pages.map( ( p, i ) => (
									<span key={ p.url }>
										{ i > 0 && ', ' }
										<a href={ p.url } target="_blank" rel="noreferrer">{ p.url.replace( /^https?:\/\/[^/]+/, '' ) || '/' }</a>
										{ ` (Pos. ${ p.position.toLocaleString( 'de-DE' ) }, ${ p.impressions } Impr.)` }
										{ p.home && <span className="lsec-badge lsec-badge--e-confirmed"> Hauptseite</span> }
									</span>
								) ) }
							</li>
						) ) }
					</ul>
				</div>
			) ) }
		</>
	);
}

/**
 * Karte „Hauptseiten“ auf der Seite Entitäten.
 *
 * @param {Object}   props        Eigenschaften.
 * @param {Function} props.onOpen Detail einer Entität öffnen.
 * @param {number}   props.reload Zähler; ändert er sich, wird neu geladen.
 */
export function HomesCard( { onOpen, reload } ) {
	const [ data, setData ] = useState( null );
	const [ open, setOpen ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback( () => {
		getEntityHomes().then( setData ).catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	}, [] );
	useEffect( load, [ load, reload ] );

	const pick = ( item, postId ) => {
		setBusy( true );
		setEntityHome( item.id, postId, 'suggestion' )
			.then( ( out ) => {
				setNotice( { status: 'success', text: `Hauptseite von „${ item.name }“: ${ out.home.title }.` } );
				load();
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( false ) );
	};

	if ( ! data ) {
		return null;
	}
	const c = data.counts;
	if ( c.total === 0 ) {
		return null;
	}
	const open_items = data.items.filter( ( i ) => ! i.home );
	return (
		<Card className="lsec-homes">
			<CardHeader>
				<div>
					<strong>Hauptseiten</strong>
					<span className="lsec-muted">
						{ ` ${ c.set } von ${ c.total } festgelegt` }
						{ c.suggested > 0 && ` · ${ c.suggested } mit Vorschlag` }
						{ c.none > 0 && ` · ${ c.none } ohne passende Seite` }
						{ c.stale > 0 && ` · ${ c.stale } nicht mehr veröffentlicht` }
						{ data.competing.length > 0 && ` · ${ data.competing.length } mit Konkurrenz eigener Seiten` }
					</span>
				</div>
				<Button variant="tertiary" onClick={ () => setOpen( ! open ) }>{ open ? 'Zuklappen' : 'Anzeigen' }</Button>
			</CardHeader>
			{ open && (
				<CardBody>
					{ notice && <Notice status={ notice.status } onRemove={ () => setNotice( null ) }>{ notice.text }</Notice> }
					<p className="lsec-muted">Je Leistung, Standort, Person und für das Unternehmen eine feste eigene Seite. Entwürfe verlinken sie, und die strukturierten Daten nennen sie als Adresse der Entität. Vorschläge kommen aus Titel und Adresse der Seiten, den Fundstellen und der Search Console; festgelegt wird nur von Hand.</p>
					{ open_items.length === 0 && <p>Alle Entitäten haben eine Hauptseite.</p> }
					{ open_items.length > 0 && (
						<table className="widefat striped lsec-table">
							<thead>
								<tr>
									<th>Entität</th>
									<th>Vorschlag</th>
									<th><span className="screen-reader-text">Aktionen</span></th>
								</tr>
							</thead>
							<tbody>
								{ open_items.map( ( item ) => {
									const best = item.suggestions[ 0 ];
									return (
										<tr key={ item.id }>
											<td>
												<Button variant="link" onClick={ () => onOpen( item.id ) }>{ item.name }</Button>
												<span className="lsec-muted"> { ENTITY_TYPES[ item.type ] || item.type }</span>
												{ item.stale && <span className="lsec-muted"> · Hauptseite nicht mehr veröffentlicht</span> }
											</td>
											<td>
												{ best ? (
													<>
														<PageLink page={ best } />
														<span className="lsec-muted"> – { best.reasons.join( ', ' ) }</span>
														{ item.suggestions.length > 1 && <span className="lsec-muted"> (+{ item.suggestions.length - 1 } weitere)</span> }
													</>
												) : (
													<span className="lsec-muted">Keine passende Seite – ein Thema zu dieser Entität wäre eine Lücke.</span>
												) }
											</td>
											<td className="lsec-actions">
												{ best && <Button variant="secondary" size="small" disabled={ busy } onClick={ () => pick( item, best.post_id ) }>Übernehmen</Button> }
												<Button variant="tertiary" size="small" onClick={ () => onOpen( item.id ) }>Andere wählen …</Button>
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					) }
					{ data.items.some( ( i ) => i.home ) && (
						<details className="lsec-home__set">
							<summary>Festgelegte Hauptseiten ({ c.set })</summary>
							<ul>
								{ data.items.filter( ( i ) => i.home ).map( ( i ) => (
									<li key={ i.id }>
										<Button variant="link" onClick={ () => onOpen( i.id ) }>{ i.name }</Button>
										{ ' → ' }
										<PageLink page={ i.home } />
									</li>
								) ) }
							</ul>
						</details>
					) }
					<Competing items={ data.competing } />
				</CardBody>
			) }
		</Card>
	);
}
