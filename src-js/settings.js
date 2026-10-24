/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Einstellungen in Gruppen. Gespeichert werden nur geänderte Werte; der Server prüft jeden einzeln.
 */
import { createElement, Fragment, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CheckboxControl,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { getModels, getSettings, saveSettings } from './api';
import ToolAction from './tools';

// Modelllisten je Art (text, image), einmal je Seitenaufruf geladen.
const modelLists = {};

/**
 * Lädt die Modellliste für die Vorschläge beim Tippen (0.11.0).
 *
 * @param {string}   kind     text oder image.
 * @param {Function} setList  Setzt die Liste.
 * @param {Function} setNote  Setzt einen Hinweis, wenn es keine Liste gibt.
 */
function loadModels( kind, setList, setNote ) {
	if ( ! modelLists[ kind ] ) {
		modelLists[ kind ] = getModels( kind );
	}
	modelLists[ kind ]
		.then( ( out ) => {
			setList( out.models || [] );
			setNote( out.ok ? '' : out.message );
		} )
		.catch( () => setNote( 'Die Modellliste ließ sich nicht laden.' ) );
}

function ModelInput( { field, label, help, value, onChange } ) {
	const [ list, setList ] = useState( [] );
	const [ note, setNote ] = useState( '' );
	const id = `lsec-models-${ field.key }`;
	return (
		<>
			<TextControl
				label={ label }
				help={ note ? <>{ help } { note }</> : help }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				value={ value || '' }
				list={ id }
				autoComplete="off"
				onFocus={ () => ! list.length && loadModels( field.suggest, setList, setNote ) }
				onChange={ onChange }
			/>
			<datalist id={ id }>
				{ list.map( ( model ) => (
					<option key={ model } value={ model } />
				) ) }
			</datalist>
		</>
	);
}

/**
 * Wert für das Eingabefeld.
 *
 * @param {Object} field Feld.
 * @return {*} Anzeigewert.
 */
function initial( field ) {
	if ( field.type === 'json' ) {
		const value = field.value;
		return value && ( Array.isArray( value ) ? value.length : Object.keys( value ).length )
			? JSON.stringify( value, null, 2 )
			: '';
	}
	if ( field.type === 'number' ) {
		return String( field.value ?? '' );
	}
	if ( field.type === 'checkboxes' ) {
		return [ ...( field.value || [] ) ].sort();
	}
	return field.value;
}

/**
 * Gleichheit für den Vergleich mit dem geladenen Stand (Listen nach Inhalt).
 *
 * @param {*} a Wert.
 * @param {*} b Wert.
 * @return {boolean} Gleich.
 */
function same( a, b ) {
	return Array.isArray( a ) || Array.isArray( b ) ? JSON.stringify( a ) === JSON.stringify( b ) : a === b;
}

/**
 * Prüft vor dem Speichern, was sich im Browser prüfen lässt.
 *
 * @param {Object} field Feld.
 * @param {*}      value Eingabe.
 * @return {string|null} Fehler.
 */
function validate( field, value ) {
	if ( field.type === 'json' && value !== '' ) {
		try {
			JSON.parse( value );
		} catch ( e ) {
			return 'Kein gültiges JSON.';
		}
	}
	if ( field.type === 'number' ) {
		const n = Number( String( value ).replace( ',', '.' ) );
		if ( Number.isNaN( n ) ) {
			return 'Bitte eine Zahl eingeben.';
		}
		if ( n < field.min || ( field.max !== null && n > field.max ) ) {
			return field.max !== null ? `Erlaubt: ${ field.min } bis ${ field.max }.` : `Mindestens ${ field.min }.`;
		}
	}
	return null;
}

/**
 * Wert für den Server.
 *
 * @param {Object} field Feld.
 * @param {*}      value Eingabe.
 * @return {*} Wert.
 */
function outgoing( field, value ) {
	if ( field.type === 'json' ) {
		return value === '' ? {} : JSON.parse( value );
	}
	if ( field.type === 'number' ) {
		return Number( String( value ).replace( ',', '.' ) );
	}
	return value;
}

function Field( { field, value, error, onChange, onReload } ) {
	// Ohne JSX-Spread: Der würde bei klassischer Übersetzung @babel/runtime verlangen.
	const label = field.label;
	const help = field.help || field.unit ? (
		<>
			{ field.help }
			{ field.unit && <span className="lsec-unit"> Einheit: { field.unit }.</span> }
		</>
	) : undefined;
	let control;
	switch ( field.type ) {
		case 'select':
			control = (
				<SelectControl
					label={ label }
					help={ help }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					value={ value }
					options={ ( field.choices || [] ).map( ( c ) => ( { label: ( field.choice_labels && field.choice_labels[ c ] ) || c, value: c } ) ) }
					onChange={ onChange }
				/>
			);
			break;
		case 'checkboxes':
			control = (
				<fieldset className="lsec-checkboxes">
					<legend>{ label }</legend>
					{ ( field.choices || [] ).map( ( choice ) => (
						<CheckboxControl
							key={ choice.value }
							__nextHasNoMarginBottom
							label={ choice.label }
							checked={ ( value || [] ).includes( choice.value ) }
							onChange={ ( on ) => {
								const next = ( value || [] ).filter( ( v ) => v !== choice.value );
								if ( on ) {
									next.push( choice.value );
								}
								onChange( next.sort() );
							} }
						/>
					) ) }
					{ help && <p className="components-base-control__help">{ help }</p> }
				</fieldset>
			);
			break;
		case 'toggle':
			control = <ToggleControl label={ label } help={ help } __nextHasNoMarginBottom checked={ !! value } onChange={ onChange } />;
			break;
		case 'json':
			control = (
				<TextareaControl label={ label } help={ help } __nextHasNoMarginBottom rows={ 4 } value={ value } onChange={ onChange } className="lsec-mono" />
			);
			break;
		case 'readonly':
			control = (
				<TextControl label={ label } help={ help } __nextHasNoMarginBottom __next40pxDefaultSize value={ value || '' } placeholder="nicht gespeichert" readOnly onChange={ () => {} } />
			);
			break;
		case 'number':
			control = (
				<TextControl
					label={ label }
					help={ help }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					step={ field.step || 1 }
					min={ field.min }
					max={ field.max ?? undefined }
					value={ value }
					onChange={ onChange }
				/>
			);
			break;
		default:
			control = field.suggest ? (
				<ModelInput field={ field } label={ label } help={ help } value={ value } onChange={ onChange } />
			) : (
				<TextControl label={ label } help={ help } __nextHasNoMarginBottom __next40pxDefaultSize value={ value || '' } onChange={ onChange } />
			);
	}
	return (
		<div className={ error ? 'lsec-field lsec-field--error' : 'lsec-field' }>
			{ control }
			{ field.action && <ToolAction action={ field.action } onDone={ onReload } /> }
			{ error && <p className="lsec-field__error" role="alert">{ error }</p> }
		</div>
	);
}

/**
 * Alle Felder eines Reiters (Abschnitte und weitere Einstellungen).
 *
 * @param {Object} tab Reiter.
 * @return {Array} Felder.
 */
function tabFields( tab ) {
	return tab.sections.reduce( ( list, section ) => list.concat( section.fields ), [] ).concat( tab.advanced );
}

/**
 * Reiter aus der Adresse (#ki, #beitraege …), sonst der erste.
 *
 * @param {Array} tabs Reiter.
 * @return {string} Kennung.
 */
function tabFromHash( tabs ) {
	const hash = ( window.location.hash || '' ).replace( '#', '' );
	return tabs.some( ( t ) => t.id === hash ) ? hash : tabs[ 0 ].id;
}

export default function Settings() {
	const [ tabs, setTabs ] = useState( null );
	const [ active, setActive ] = useState( '' );
	const [ open, setOpen ] = useState( {} );
	const [ values, setValues ] = useState( {} );
	const [ original, setOriginal ] = useState( {} );
	const [ errors, setErrors ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	const apply = ( described ) => {
		const v = {};
		described.forEach( ( t ) => tabFields( t ).forEach( ( f ) => ( v[ f.key ] = initial( f ) ) ) );
		setTabs( described );
		setValues( v );
		setOriginal( v );
		setActive( ( current ) => current || tabFromHash( described ) );
	};

	const reload = () =>
		getSettings()
			.then( apply )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	useEffect( () => {
		reload();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const fields = {};
	( tabs || [] ).forEach( ( t ) => tabFields( t ).forEach( ( f ) => ( fields[ f.key ] = f ) ) );
	const changed = Object.keys( values ).filter( ( k ) => fields[ k ] && ! same( values[ k ], original[ k ] ) && fields[ k ].type !== 'readonly' );

	// Nicht gespeicherte Änderungen: beim Verlassen der Seite nachfragen.
	useEffect( () => {
		if ( ! changed.length ) {
			return undefined;
		}
		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ changed.length ] );

	if ( ! tabs ) {
		return notice ? <Notice status="error" isDismissible={ false }>{ notice.text }</Notice> : <Spinner />;
	}

	const select = ( id ) => {
		setActive( id );
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', `#${ id }` );
		}
	};

	const onSave = () => {
		const local = {};
		changed.forEach( ( k ) => {
			const problem = validate( fields[ k ], values[ k ] );
			if ( problem ) {
				local[ k ] = problem;
			}
		} );
		setErrors( local );
		if ( Object.keys( local ).length ) {
			setNotice( { status: 'error', text: 'Bitte die markierten Felder prüfen (Punkt am Reiter).' } );
			return;
		}
		const payload = {};
		changed.forEach( ( k ) => ( payload[ k ] = outgoing( fields[ k ], values[ k ] ) ) );
		setSaving( true );
		saveSettings( payload )
			.then( ( result ) => {
				const serverErrors = result.errors || {};
				const kept = {};
				Object.keys( serverErrors ).forEach( ( k ) => ( kept[ k ] = values[ k ] ) );
				apply( result.settings );
				// Abgelehnte Eingaben bleiben stehen, damit man sie korrigieren kann.
				setValues( ( v ) => ( { ...v, ...kept } ) );
				setErrors( serverErrors );
				const n = ( result.saved || [] ).length;
				const notes = ( result.notes || [] ).length ? ` ${ result.notes.join( ' ' ) }` : '';
				setNotice(
					Object.keys( serverErrors ).length
						? { status: 'warning', text: `${ n } gespeichert, ${ Object.keys( serverErrors ).length } abgelehnt.${ notes }` }
						: { status: 'success', text: ( n === 1 ? 'Einstellung gespeichert.' : `${ n } Einstellungen gespeichert.` ) + notes }
				);
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setSaving( false ) );
	};

	const renderField = ( field ) => (
		<Field
			key={ field.key }
			field={ field }
			value={ values[ field.key ] }
			error={ errors[ field.key ] }
			onReload={ reload }
			onChange={ ( v ) => setValues( ( prev ) => ( { ...prev, [ field.key ]: v } ) ) }
		/>
	);

	const tab = tabs.find( ( t ) => t.id === active ) || tabs[ 0 ];
	const marked = ( t ) => tabFields( t ).some( ( f ) => changed.includes( f.key ) || errors[ f.key ] );
	const advancedOpen = !! open[ tab.id ] || tab.advanced.some( ( f ) => errors[ f.key ] );

	return (
		<div className="lsec-settings">
			{ notice && <Notice status={ notice.status } onRemove={ () => setNotice( null ) }>{ notice.text }</Notice> }
			<div className="lsec-tabs" role="tablist" aria-label="Bereiche der Einstellungen">
				{ tabs.map( ( t ) => (
					<button
						key={ t.id }
						type="button"
						role="tab"
						aria-selected={ t.id === tab.id }
						className={ `lsec-tabs__tab${ t.id === tab.id ? ' is-active' : '' }` }
						onClick={ () => select( t.id ) }
					>
						{ t.label }
						{ marked( t ) && <span className="lsec-tabs__dot" aria-label="geändert" /> }
					</button>
				) ) }
			</div>
			<div role="tabpanel" className="lsec-settings__panel">
				<p className="lsec-muted lsec-settings__intro">{ tab.intro }</p>
				{ tab.sections.map( ( section, index ) => (
					<Card key={ index } className="lsec-settings__group">
						{ section.label && (
							<CardHeader>
								<h2>{ section.label }</h2>
							</CardHeader>
						) }
						<CardBody>{ section.fields.map( renderField ) }</CardBody>
					</Card>
				) ) }
				{ tab.advanced.length > 0 && (
					<Card className="lsec-settings__group lsec-settings__advanced">
						<CardHeader>
							<Button
								variant="link"
								aria-expanded={ advancedOpen }
								onClick={ () => setOpen( ( o ) => ( { ...o, [ tab.id ]: ! advancedOpen } ) ) }
							>
								{ advancedOpen ? '▾' : '▸' } Weitere Einstellungen ({ tab.advanced.length })
							</Button>
							<span className="lsec-muted">selten nötig</span>
						</CardHeader>
						{ advancedOpen && <CardBody>{ tab.advanced.map( renderField ) }</CardBody> }
					</Card>
				) }
			</div>
			<div className="lsec-savebar">
				<Button variant="primary" onClick={ onSave } isBusy={ saving } disabled={ saving || changed.length === 0 }>
					{ changed.length > 1 ? `${ changed.length } Änderungen speichern` : 'Speichern' }
				</Button>
				{ changed.length > 0 && (
					<Button
						variant="tertiary"
						disabled={ saving }
						onClick={ () => {
							setValues( original );
							setErrors( {} );
						} }
					>
						Verwerfen
					</Button>
				) }
				<span className="lsec-muted">
					{ changed.length ? `${ changed.length === 1 ? 'Eine Änderung' : `${ changed.length } Änderungen` } noch nicht gespeichert` : 'Alles gespeichert. Änderungen werden im Prüfprotokoll vermerkt.' }
				</span>
			</div>
		</div>
	);
}
