/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Entitäten: Vorschläge zum Zusammenführen, Filter, Bestätigen, Ablehnen, Zusammenführen, Details mit Fundstellen,
 * Bearbeiten (Name, Typ, Status, Beschreibung, Schreibweisen) und Löschen.
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
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { decideEntity, deleteEntity, getEntities, getEntity, getMergeSuggestions, mergeEntities, updateEntity } from './api';
import { ENTITY_STATUS, ENTITY_TYPES, ORIGINS } from './format';

const config = window.lsecAdmin || {};
const MIN_CONFIDENCE = Number( config.minConfidence ?? 0.6 );
const EDITABLE_STATUS = [ 'candidate', 'probable', 'unresolved', 'confirmed', 'rejected' ];
const PROFILE = 'business_profile';

/**
 * Formular zum Bearbeiten. Einträge aus dem Unternehmensprofil: nur Beschreibung und Schreibweisen.
 *
 * @param {Object}   props          Eigenschaften.
 * @param {Object}   props.entity   Entität.
 * @param {Function} props.onSave   Speichern mit den Feldern.
 * @param {Function} props.onCancel Abbrechen.
 * @param {boolean}  props.busy     Speichert gerade.
 */
function EditForm( { entity, onSave, onCancel, busy } ) {
	const fromProfile = entity.origin === PROFILE;
	const [ form, setForm ] = useState( {
		name: entity.canonical_name,
		type: entity.type,
		status: entity.status,
		description: entity.description || '',
		aliases: entity.aliases.slice(),
	} );
	const [ alias, setAlias ] = useState( '' );
	const set = ( key, value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );
	const addAlias = () => {
		const value = alias.trim();
		if ( value && ! form.aliases.includes( value ) && value !== form.name ) {
			set( 'aliases', [ ...form.aliases, value ] );
		}
		setAlias( '' );
	};
	return (
		<div className="lsec-edit">
			{ fromProfile && (
				<Notice status="info" isDismissible={ false }>
					Dieser Eintrag stammt aus dem Unternehmensprofil. Name, Typ und Status bitte unter „Unternehmen“ ändern; hier lassen sich Beschreibung und Schreibweisen pflegen.
				</Notice>
			) }
			<TextControl label="Name" value={ form.name } disabled={ fromProfile } onChange={ ( v ) => set( 'name', v ) } __nextHasNoMarginBottom __next40pxDefaultSize />
			<div className="lsec-edit__row">
				<SelectControl
					label="Typ"
					value={ form.type }
					disabled={ fromProfile }
					options={ Object.entries( ENTITY_TYPES ).map( ( [ value, label ] ) => ( { label, value } ) ) }
					onChange={ ( v ) => set( 'type', v ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<SelectControl
					label="Status"
					value={ form.status }
					disabled={ fromProfile }
					options={ EDITABLE_STATUS.map( ( value ) => ( { label: ENTITY_STATUS[ value ], value } ) ) }
					onChange={ ( v ) => set( 'status', v ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>
			<TextareaControl label="Beschreibung" rows={ 2 } value={ form.description } onChange={ ( v ) => set( 'description', v ) } __nextHasNoMarginBottom />
			<fieldset className="lsec-aliases">
				<legend>Weitere Schreibweisen</legend>
				{ form.aliases.length === 0 && <p className="lsec-muted">Keine.</p> }
				<ul>
					{ form.aliases.map( ( a ) => (
						<li key={ a }>
							{ a }
							<Button variant="link" isDestructive onClick={ () => set( 'aliases', form.aliases.filter( ( x ) => x !== a ) ) } aria-label={ `${ a } entfernen` }>
								entfernen
							</Button>
						</li>
					) ) }
				</ul>
				<div className="lsec-aliases__add">
					<TextControl
						label="Schreibweise hinzufügen"
						value={ alias }
						onChange={ setAlias }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								e.preventDefault();
								addAlias();
							}
						} }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button variant="secondary" onClick={ addAlias } disabled={ ! alias.trim() }>Hinzufügen</Button>
				</div>
			</fieldset>
			{ form.name !== entity.canonical_name && (
				<p className="lsec-muted">Der bisherige Name „{ entity.canonical_name }“ bleibt als Schreibweise erhalten.</p>
			) }
			<div className="lsec-savebar lsec-savebar--inline">
				<Button variant="primary" isBusy={ busy } disabled={ busy || ! form.name.trim() } onClick={ () => onSave( form ) }>Speichern</Button>
				<Button variant="tertiary" disabled={ busy } onClick={ onCancel }>Abbrechen</Button>
			</div>
		</div>
	);
}

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
							<span className="lsec-muted">{ ` (${ ENTITY_TYPES[ group.target.type ] || group.target.type }${ group.reason ? `, ${ group.reason }` : '' })` }</span>
							{ ` – ${ group.candidates.length } weitere${ group.candidates.length === 1 ? 'r Eintrag' : ' Einträge' }:` }
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

function Detail( { id, edit, onClose, onChanged } ) {
	const [ entity, setEntity ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ editing, setEditing ] = useState( !! edit );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		getEntity( id ).then( setEntity ).catch( ( e ) => setError( e.message ) );
	}, [ id ] );

	const save = ( form ) => {
		const fields = { description: form.description, aliases: form.aliases };
		if ( entity.origin !== PROFILE ) {
			fields.name = form.name.trim();
			fields.type = form.type;
			fields.status = form.status;
		}
		setBusy( true );
		setError( null );
		updateEntity( id, fields )
			.then( ( updated ) => {
				setEntity( updated );
				setEditing( false );
				onChanged( `„${ updated.canonical_name }“ gespeichert.` );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	const remove = () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( `„${ entity.canonical_name }“ mit allen Schreibweisen und Fundstellen löschen?\n\nAnders als „Ablehnen“ merkt sich das Plugin den Namen dann nicht: Eine spätere Erkennung kann ihn wieder vorschlagen. Für Begriffe, die nicht wiederkommen sollen, ist „Ablehnen“ besser.` ) ) {
			return;
		}
		setBusy( true );
		deleteEntity( id )
			.then( () => {
				onChanged( `„${ entity.canonical_name }“ gelöscht.` );
				onClose();
			} )
			.catch( ( e ) => {
				setError( e.message );
				setBusy( false );
			} );
	};

	const wikidata = entity && entity.external_ids && entity.external_ids.wikidata;
	const candidates = ( entity && entity.properties && entity.properties.candidates ) || [];
	const posts = {};
	( ( entity && entity.mentions ) || [] ).forEach( ( m ) => {
		const key = `${ m.object_type }:${ m.object_id }`;
		posts[ key ] = posts[ key ] || { title: m.title, url: m.url, excerpts: [] };
		posts[ key ].excerpts.push( m.excerpt );
	} );

	return (
		<Modal title={ entity ? entity.canonical_name : 'Entität' } onRequestClose={ onClose } className="lsec-detail">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! entity && ! error && <Spinner /> }
			{ entity && editing && <EditForm entity={ entity } onSave={ save } onCancel={ () => setEditing( false ) } busy={ busy } /> }
			{ entity && ! editing && (
				<>
					{ entity.status !== 'merged' && (
						<div className="lsec-detail__actions">
							<Button variant="secondary" size="small" onClick={ () => setEditing( true ) }>Bearbeiten</Button>
							{ entity.origin !== PROFILE && (
								<Button variant="tertiary" size="small" isDestructive disabled={ busy } onClick={ remove }>Löschen</Button>
							) }
						</div>
					) }
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
								{ post.url ? <a href={ post.url } target="_blank" rel="noreferrer">{ post.title || postId }</a> : post.title || postId }
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
													<Button variant="link" onClick={ () => setDetail( { id, edit: false } ) }>{ row.canonical_name }</Button>
													{ Number( row.aliases ) > 0 && <span className="lsec-muted"> +{ row.aliases }</span> }
												</td>
												<td>{ ENTITY_TYPES[ row.type ] || row.type }</td>
												<td><span className={ `lsec-badge lsec-badge--e-${ row.status }` }>{ ENTITY_STATUS[ row.status ] || row.status }</span></td>
												<td className="lsec-num">{ row.confidence === null ? '–' : Number( row.confidence ).toLocaleString( 'de-DE', { maximumFractionDigits: 2 } ) }</td>
												<td className="lsec-num">{ row.posts }</td>
												<td>{ ORIGINS[ row.origin ] || row.origin }</td>
												<td className="lsec-actions">
													{ row.status !== 'merged' && row.origin !== PROFILE && row.status !== 'confirmed' && (
														<Button variant="secondary" size="small" disabled={ busy } onClick={ () => decide( id, 'confirmed' ) }>Bestätigen</Button>
													) }
													{ row.status !== 'merged' && row.origin !== PROFILE && row.status !== 'rejected' && (
														<Button variant="tertiary" size="small" isDestructive disabled={ busy } onClick={ () => decide( id, 'rejected' ) }>Ablehnen</Button>
													) }
													{ row.status !== 'merged' && (
														<Button variant="tertiary" size="small" disabled={ busy } onClick={ () => setDetail( { id, edit: true } ) }>Bearbeiten</Button>
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
			{ detail !== null && (
				<Detail
					key={ `${ detail.id }-${ detail.edit }` }
					id={ detail.id }
					edit={ detail.edit }
					onClose={ () => setDetail( null ) }
					onChanged={ ( text ) => {
						setNotice( { status: 'success', text } );
						load();
					} }
				/>
			) }
			{ merging && selectedRows.length > 1 && <MergeDialog entities={ selectedRows } onMerge={ merge } onClose={ () => setMerging( false ) } busy={ busy } /> }
		</div>
	);
}
