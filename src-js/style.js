/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Style Mimic (Spezifikation 8.7): Profil aus eigenen Beiträgen erzeugen, von Hand bearbeiten, Versionen.
 * Jede Änderung speichert eine neue Version; genau eine ist aktiv.
 */
import { createElement, Fragment, useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, CheckboxControl, Notice, SelectControl, Spinner, TextareaControl, TextControl } from '@wordpress/components';
import { activateStyle, buildStyle, getStyle, saveStyle } from './api';
import { dateTime, usd } from './format';

const lines = ( text ) =>
	String( text || '' )
		.split( '\n' )
		.map( ( l ) => l.trim() )
		.filter( Boolean );

function toForm( profile ) {
	const p = profile || {};
	const vocab = p.vocabulary || {};
	return {
		tone: p.tone || '',
		address: p.address || 'Sie',
		sentence_length: p.sentence_length || '',
		preferred: ( vocab.preferred || [] ).join( '\n' ),
		avoid: ( vocab.avoid || [] ).join( '\n' ),
		structure_habits: ( p.structure_habits || [] ).join( '\n' ),
		rules: ( p.rules || [] ).join( '\n' ),
		examples: ( p.examples || [] ).map( ( e ) => `${ e.do } || ${ e.dont }` ).join( '\n' ),
	};
}

function fromForm( form ) {
	return {
		tone: form.tone,
		address: form.address,
		sentence_length: form.sentence_length,
		vocabulary: { preferred: lines( form.preferred ), avoid: lines( form.avoid ) },
		structure_habits: lines( form.structure_habits ),
		rules: lines( form.rules ),
		examples: lines( form.examples ).map( ( l ) => {
			const [ good, bad ] = l.split( '||' );
			return { do: ( good || '' ).trim(), dont: ( bad || '' ).trim() };
		} ),
	};
}

function ProfileCard( { data, onSaved, setNotice } ) {
	const [ form, setForm ] = useState( () => toForm( data.active && data.active.profile ) );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => setForm( toForm( data.active && data.active.profile ) ), [ data.active ] );
	const set = ( key ) => ( value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );
	const save = () => {
		setBusy( true );
		saveStyle( fromForm( form ) )
			.then( ( r ) => {
				setNotice( { status: 'success', text: `Version ${ r.version } gespeichert und aktiv.` } );
				onSaved();
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Card className="lsec-wide">
			<CardHeader>
				<h2>Profil{ data.active ? ` (Version ${ data.active.version })` : '' }</h2>
			</CardHeader>
			<CardBody>
				{ ! data.active && <p className="lsec-muted">Noch kein Profil. Ohne Profil schreiben Entwürfe sachlich und freundlich mit „Sie“. Unten aus eigenen Beiträgen erzeugen oder hier von Hand eintragen.</p> }
				<div className="lsec-form-grid">
					<TextControl label="Ton" value={ form.tone } onChange={ set( 'tone' ) } __nextHasNoMarginBottom __next40pxDefaultSize />
					<SelectControl
						label="Anrede"
						value={ form.address }
						options={ [ 'Sie', 'du', 'ihr', 'neutral' ].map( ( a ) => ( { label: a, value: a } ) ) }
						onChange={ set( 'address' ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl label="Satzlänge" value={ form.sentence_length } onChange={ set( 'sentence_length' ) } __nextHasNoMarginBottom __next40pxDefaultSize />
				</div>
				<div className="lsec-form-grid">
					<TextareaControl label="Bevorzugte Wörter (eins pro Zeile)" rows={ 5 } value={ form.preferred } onChange={ set( 'preferred' ) } __nextHasNoMarginBottom />
					<TextareaControl label="Vermeiden (eins pro Zeile)" rows={ 5 } value={ form.avoid } onChange={ set( 'avoid' ) } __nextHasNoMarginBottom />
				</div>
				<TextareaControl label="Gewohnheiten im Aufbau (eine pro Zeile)" rows={ 4 } value={ form.structure_habits } onChange={ set( 'structure_habits' ) } __nextHasNoMarginBottom />
				<TextareaControl label="Feste Regeln (eine pro Zeile)" rows={ 4 } value={ form.rules } onChange={ set( 'rules' ) } __nextHasNoMarginBottom />
				<TextareaControl
					label="Beispiele (eine Zeile: so || nicht so)"
					rows={ 4 }
					value={ form.examples }
					onChange={ set( 'examples' ) }
					help="Links ein Satz im gewünschten Stil, rechts derselbe Inhalt, wie er nicht klingen soll."
					__nextHasNoMarginBottom
				/>
				<p>
					<Button variant="primary" onClick={ save } isBusy={ busy } disabled={ busy }>
						Als neue Version speichern
					</Button>
				</p>
			</CardBody>
		</Card>
	);
}

function BuildCard( { data, onBuilt, setNotice } ) {
	const [ selected, setSelected ] = useState( () => ( data.active && data.active.inputs && data.active.inputs.post_ids ) || [] );
	const [ samples, setSamples ] = useState( '' );
	const [ rules, setRules ] = useState( '' );
	const [ filter, setFilter ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const toggle = ( id, on ) => setSelected( ( s ) => ( on ? s.concat( [ id ] ).slice( 0, 10 ) : s.filter( ( x ) => x !== id ) ) );
	const posts = data.posts.filter( ( p ) => ! filter || p.title.toLowerCase().includes( filter.toLowerCase() ) );
	const build = () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Profil mit der KI erzeugen? Ein Aufruf, wenige Cent. Die festen Regeln bleiben wörtlich.' ) ) {
			return;
		}
		setBusy( true );
		buildStyle( { post_ids: selected, samples: samples.split( /^\s*---\s*$/m ).filter( ( s ) => s.trim() ), rules: lines( rules ) } )
			.then( ( r ) => {
				setNotice( { status: 'success', text: `Version ${ r.version } erzeugt und aktiv (${ usd( r.cost ) }).` } );
				onBuilt();
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Card className="lsec-wide">
			<CardHeader>
				<h2>Aus eigenen Texten erzeugen</h2>
			</CardHeader>
			<CardBody>
				<p className="lsec-muted">Wählen Sie bis zu zehn Beiträge oder Seiten, deren Stil neue Entwürfe treffen sollen. { selected.length } ausgewählt.</p>
				<TextControl label="Suchen" value={ filter } onChange={ setFilter } __nextHasNoMarginBottom __next40pxDefaultSize />
				<div className="lsec-checklist">
					{ posts.slice( 0, 80 ).map( ( p ) => (
						<CheckboxControl
							key={ p.id }
							__nextHasNoMarginBottom
							label={ `${ p.title } (${ p.kind === 'page' ? 'Seite' : 'Beitrag' })` }
							checked={ selected.includes( p.id ) }
							onChange={ ( on ) => toggle( p.id, on ) }
						/>
					) ) }
				</div>
				<TextareaControl label="Beispieltexte (mehrere durch eine Zeile mit --- trennen)" rows={ 4 } value={ samples } onChange={ setSamples } __nextHasNoMarginBottom />
				<TextareaControl label="Feste Regeln (eine pro Zeile)" rows={ 3 } value={ rules } onChange={ setRules } help="z. B. „Keine Preise nennen“ oder „Firmenname immer mit Ort“." __nextHasNoMarginBottom />
				<p>
					<Button variant="secondary" onClick={ build } isBusy={ busy } disabled={ busy || ( selected.length === 0 && ! samples.trim() && ! rules.trim() ) }>
						Profil erzeugen
					</Button>
				</p>
			</CardBody>
		</Card>
	);
}

export default function Style() {
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const load = useCallback( () => {
		getStyle()
			.then( setData )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	}, [] );
	useEffect( load, [ load ] );

	if ( ! data ) {
		return notice ? <Notice status="error" isDismissible={ false }>{ notice.text }</Notice> : <Spinner />;
	}
	const activate = ( version ) => {
		activateStyle( version )
			.then( () => {
				setNotice( { status: 'success', text: `Version ${ version } ist aktiv.` } );
				load();
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	};
	return (
		<>
			{ notice && <Notice status={ notice.status } onRemove={ () => setNotice( null ) }>{ notice.text }</Notice> }
			<div className="lsec-grid">
				<ProfileCard data={ data } onSaved={ load } setNotice={ setNotice } />
				<BuildCard data={ data } onBuilt={ load } setNotice={ setNotice } />
				<Card className="lsec-wide">
					<CardHeader>
						<h2>Versionen</h2>
					</CardHeader>
					<CardBody>
						{ data.versions.length === 0 && <p className="lsec-muted">Keine.</p> }
						{ data.versions.length > 0 && (
							<table className="widefat striped lsec-table">
								<thead>
									<tr>
										<th>Version</th>
										<th>Herkunft</th>
										<th>Beiträge</th>
										<th>Erstellt</th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									{ data.versions.map( ( v ) => (
										<tr key={ v.version }>
											<td>{ v.version }{ v.is_active && <span className="lsec-tag">aktiv</span> }</td>
											<td>{ v.origin === 'ai' ? 'KI aus Texten' : 'von Hand' }</td>
											<td>{ v.post_ids.length }</td>
											<td>{ dateTime( v.created_at ) }</td>
											<td>{ ! v.is_active && <Button variant="link" onClick={ () => activate( v.version ) }>aktivieren</Button> }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) }
					</CardBody>
				</Card>
			</div>
		</>
	);
}
