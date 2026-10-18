/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Entitäten: Vorschläge zum Zusammenführen, Filter, Bestätigen, Ablehnen, Zusammenführen, Details mit Fundstellen.
 */
import { createElement, Fragment, useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { decideEntity, getEntities, getEntity, getMergeSuggestions, mergeEntities } from './api';
import { ENTITY_STATUS, ENTITY_TYPES, ORIGINS } from './format';

const config = window.lsecAdmin || {};
const MIN_CONFIDENCE = Number( config.minConfidence ?? 0.6 );
const DECIDABLE = [ 'candidate', 'probable', 'unresolved' ];

function Suggestions( { groups, onMerge, busy } ) {
	if ( ! groups.length ) {
		return null;
	}
	return (
		<Card className="lsec-suggestions">
			<CardHeader>
				<h2>Vorschläge zum Zusammenführen</h2>
			</CardHeader>
			<CardBody>
				{ groups.map( ( group ) => (
					<div key={ group.target.id } className="lsec-suggestion">
						<p>
							<strong>{ group.target.name }</strong>
							{ ` – ${ group.candidates.length } weitere Schreibweise${ group.candidates.length === 1 ? '' : 'n' }:` }
						</p>
						<ul>
							{ group.candidates.map( ( c ) => (
								<li key={ c.id }>
									{ c.name } <span className="lsec-muted">({ ENTITY_TYPES[ c.type ] || c.type }, { c.mentions } Fundstellen)</span>
								</li>
							) ) }
						</ul>
						<Button variant="primary" isBusy={ busy } disabled={ busy } onClick={ () => onMerge( group.target.id, group.candidates.map( ( c ) => c.id ) ) }>
							Alle zu „{ group.target.name }“ zusammenführen
						</Button>
					</div>
				) ) }
			</CardBody>
		</Card>
	);
}

function Detail( { id, onClose } ) {
	const [ entity, setEntity ] = useState( null );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		getEntity( id ).then( setEntity ).catch( ( e ) => setError( e.message ) );
	}, [ id ] );

	const wikidata = entity && entity.external_ids && entity.external_ids.wikidata;
	const candidates = ( entity && entity.properties && entity.properties.candidates ) || [];
	const posts = {};
	( ( entity && entity.mentions ) || [] ).forEach( ( m ) => {
		const key = m.object_id;
		posts[ key ] = posts[ key ] || { title: m.title, url: m.url, excerpts: [] };
		posts[ key ].excerpts.push( m.excerpt );
	} );

	return (
		<Modal title={ entity ? entity.canonical_name : 'Entität' } onRequestClose={ onClose } className="lsec-detail">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! entity && ! error && <Spinner /> }
			{ entity && (
				<>
					<p>
						{ ENTITY_TYPES[ entity.type ] || entity.type } · <span className={ `lsec-badge lsec-badge--e-${ entity.status }` }>{ ENTITY_STATUS[ entity.status ] || entity.status }</span>
						{ ' · ' }
						{ ORIGINS[ entity.origin ] || entity.origin }
						{ entity.confidence !== null && ` · Sicherheit ${ Number( entity.confidence ).toLocaleString( 'de-DE' ) }` }
						{ wikidata && (
							<>
								{ ' · ' }
								<a href={ `https://www.wikidata.org/wiki/${ wikidata }` } target="_blank" rel="noreferrer">{ wikidata }</a>
							</>
						) }
					</p>
					{ entity.description && <p>{ entity.description }</p> }
					{ entity.aliases.length > 0 && (
						<p><strong>Weitere Schreibweisen:</strong> { entity.aliases.join( ', ' ) }</p>
					) }
					{ entity.merged_from.length > 0 && (
						<p><strong>Zusammengeführt aus:</strong> { entity.merged_from.map( ( m ) => m.canonical_name ).join( ', ' ) }</p>
					) }
					{ candidates.length > 0 && (
						<>
							<h3>Ortsabgleich</h3>
							{ entity.properties.reason && <p className="lsec-muted">{ entity.properties.reason }</p> }
							<ul>
								{ candidates.map( ( c ) => (
									<li key={ c.wikidata }>
										<a href={ `https://www.wikidata.org/wiki/${ c.wikidata }` } target="_blank" rel="noreferrer">{ c.wikidata }</a>
										{ ` ${ c.description || c.label || '' }` }
										{ c.distance_km !== null && c.distance_km !== undefined && ` · ${ c.distance_km } km` }
										{ c.in_area === true && ' · im Servicegebiet' }
										{ c.in_area === false && ' · außerhalb' }
									</li>
								) ) }
							</ul>
						</>
					) }
					{ entity.relations.length > 0 && (
						<>
							<h3>Beziehungen</h3>
							<ul>
								{ entity.relations.map( ( r ) => (
									<li key={ r.id }>{ r.source_name } → { r.relation_type } → { r.target_name }</li>
								) ) }
							</ul>
						</>
					) }
					<h3>Fundstellen</h3>
					{ Object.keys( posts ).length === 0 && <p className="lsec-muted">Keine.</p> }
					{ Object.entries( posts ).map( ( [ postId, post ] ) => (
						<div key={ postId } className="lsec-mentions">
							<p>
								{ post.url ? <a href={ post.url } target="_blank" rel="noreferrer">{ post.title || `Beitrag ${ postId }` }</a> : `Beitrag ${ postId }` }
							</p>
							<ul>
								{ post.excerpts.slice( 0, 5 ).map( ( text, i ) => <li key={ i }>„{ text }“</li> ) }
							</ul>
						</div>
					) ) }
				</>
			) }
		</Modal>
	);
}

function MergeDialog( { entities, onMerge, onClose, busy } ) {
	const [ target, setTarget ] = useState( entities[ 0 ].id );
	return (
		<Modal title="Zusammenführen" onRequestClose={ onClose }>
			<p>Welcher Name bleibt? Die anderen werden weitere Schreibweisen dieses Eintrags; Fundstellen und Beziehungen wandern mit.</p>
			{ entities.map( ( e ) => (
				<label key={ e.id } className="lsec-radio">
					<input type="radio" name="lsec-target" checked={ target === e.id } onChange={ () => setTarget( e.id ) } />
					{ ` ${ e.canonical_name } ` }
					<span className="lsec-muted">({ ENTITY_TYPES[ e.type ] || e.type }, { ENTITY_STATUS[ e.status ] || e.status })</span>
				</label>
			) ) }
			<div className="lsec-savebar">
				<Button variant="primary" isBusy={ busy } disabled={ busy } onClick={ () => onMerge( target, entities.filter( ( e ) => e.id !== target ).map( ( e ) => e.id ) ) }>
					Zusammenführen
				</Button>
				<Button variant="tertiary" onClick={ onClose }>Abbrechen</Button>
			</div>
		</Modal>
	);
}

export default function Entities() {
	const [ filters, setFilters ] = useState( { type: '', status: '', search: '', weak: false } );
	const [ rows, setRows ] = useState( null );
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ selected, setSelected ] = useState( [] );
	const [ detail, setDetail ] = useState( null );
	const [ merging, setMerging ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback( () => {
		getEntities( {
			type: filters.type,
			status: filters.status,
			search: filters.search,
			min_confidence: filters.weak ? 0 : MIN_CONFIDENCE,
			limit: 200,
		} )
			.then( ( result ) => {
				setRows( result );
				setSelected( ( sel ) => sel.filter( ( id ) => result.some( ( r ) => Number( r.id ) === id ) ) );
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
		getMergeSuggestions().then( setSuggestions ).catch( () => setSuggestions( [] ) );
	}, [ filters ] );

	useEffect( () => {
		const timer = setTimeout( load, 250 );
		return () => clearTimeout( timer );
	}, [ load ] );

	const decide = ( id, decision ) => {
		setBusy( true );
		decideEntity( id, decision )
			.then( load )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( false ) );
	};

	const merge = ( target, sources ) => {
		setBusy( true );
		mergeEntities( target, sources )
			.then( ( out ) => {
				setNotice( { status: 'success', text: `${ out.merged.length } zusammengeführt, ${ out.aliases } neue Schreibweisen, ${ out.mentions } Fundstellen übernommen.` } );
				setMerging( false );
				setSelected( [] );
				load();
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( false ) );
	};

	const setFilter = ( key, value ) => setFilters( ( f ) => ( { ...f, [ key ]: value } ) );
	const toggle = ( id, on ) => setSelected( ( sel ) => ( on ? [ ...sel, id ] : sel.filter( ( x ) => x !== id ) ) );
	const selectedRows = ( rows || [] ).filter( ( r ) => selected.includes( Number( r.id ) ) ).map( ( r ) => ( { ...r, id: Number( r.id ) } ) );

	return (
		<div className="lsec-entities">
			{ notice && <Notice status={ notice.status } onRemove={ () => setNotice( null ) }>{ notice.text }</Notice> }
			<Suggestions groups={ suggestions } onMerge={ merge } busy={ busy } />
			<Card>
				<CardHeader>
					<div className="lsec-filters">
						<SelectControl
							label="Typ"
							value={ filters.type }
							options={ [ { label: 'alle', value: '' }, ...Object.entries( ENTITY_TYPES ).map( ( [ value, label ] ) => ( { label, value } ) ) ] }
							onChange={ ( v ) => setFilter( 'type', v ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<SelectControl
							label="Status"
							value={ filters.status }
							options={ [ { label: 'alle', value: '' }, ...Object.entries( ENTITY_STATUS ).map( ( [ value, label ] ) => ( { label, value } ) ) ] }
							onChange={ ( v ) => setFilter( 'status', v ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label="Suche"
							value={ filters.search }
							onChange={ ( v ) => setFilter( 'search', v ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<ToggleControl
							label={ `Auch unsichere (unter ${ MIN_CONFIDENCE.toLocaleString( 'de-DE' ) })` }
							checked={ filters.weak }
							onChange={ ( v ) => setFilter( 'weak', v ) }
							__nextHasNoMarginBottom
						/>
					</div>
				</CardHeader>
				<CardBody>
					{ selected.length > 1 && (
						<div className="lsec-bulk">
							<span>{ selected.length } ausgewählt</span>
							<Button variant="secondary" onClick={ () => setMerging( true ) }>Zusammenführen …</Button>
							<Button variant="tertiary" onClick={ () => setSelected( [] ) }>Auswahl aufheben</Button>
						</div>
					) }
					{ ! rows && <Spinner /> }
					{ rows && rows.length === 0 && <p>Keine Entitäten für diese Auswahl.</p> }
					{ rows && rows.length > 0 && (
						<div className="lsec-table-wrap">
							<table className="widefat striped lsec-table">
								<thead>
									<tr>
										<th className="lsec-check"><span className="screen-reader-text">Auswahl</span></th>
										<th>Name</th>
										<th>Typ</th>
										<th>Status</th>
										<th className="lsec-num">Sicherheit</th>
										<th className="lsec-num">Beiträge</th>
										<th>Herkunft</th>
										<th><span className="screen-reader-text">Aktionen</span></th>
									</tr>
								</thead>
								<tbody>
									{ rows.map( ( row ) => {
										const id = Number( row.id );
										return (
											<tr key={ id }>
												<td className="lsec-check">
													<CheckboxControl
														label=""
														aria-label={ `${ row.canonical_name } auswählen` }
														checked={ selected.includes( id ) }
														disabled={ row.status === 'merged' }
														onChange={ ( v ) => toggle( id, v ) }
														__nextHasNoMarginBottom
													/>
												</td>
												<td>
													<Button variant="link" onClick={ () => setDetail( id ) }>{ row.canonical_name }</Button>
													{ Number( row.aliases ) > 0 && <span className="lsec-muted"> +{ row.aliases }</span> }
												</td>
												<td>{ ENTITY_TYPES[ row.type ] || row.type }</td>
												<td><span className={ `lsec-badge lsec-badge--e-${ row.status }` }>{ ENTITY_STATUS[ row.status ] || row.status }</span></td>
												<td className="lsec-num">{ row.confidence === null ? '–' : Number( row.confidence ).toLocaleString( 'de-DE', { maximumFractionDigits: 2 } ) }</td>
												<td className="lsec-num">{ row.posts }</td>
												<td>{ ORIGINS[ row.origin ] || row.origin }</td>
												<td className="lsec-actions">
													{ DECIDABLE.includes( row.status ) && (
														<>
															<Button variant="secondary" size="small" disabled={ busy } onClick={ () => decide( id, 'confirmed' ) }>Bestätigen</Button>
															<Button variant="tertiary" size="small" isDestructive disabled={ busy } onClick={ () => decide( id, 'rejected' ) }>Ablehnen</Button>
														</>
													) }
												</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>
					) }
				</CardBody>
			</Card>
			{ detail !== null && <Detail id={ detail } onClose={ () => setDetail( null ) } /> }
			{ merging && selectedRows.length > 1 && <MergeDialog entities={ selectedRows } onMerge={ merge } onClose={ () => setMerging( false ) } busy={ busy } /> }
		</div>
	);
}
