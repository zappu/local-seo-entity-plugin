/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Einrichtungsassistent (Stufe F): sechs Schritte, jeder mit Stand, Hinweisen zum Einfügen und einer Aktion.
 * Der Stand wird jedes Mal aus der Website gelesen; fehlt später etwas, ist der Schritt wieder offen.
 */
import { createElement, Fragment, useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, CheckboxControl, Notice, Spinner, TextareaControl, TextControl } from '@wordpress/components';
import { getSetup, runSetup } from './api';
import ToolAction from './tools';

const pages = ( window.lsecAdmin && window.lsecAdmin.pages ) || {};

const LINKS = {
	technik: [ [ 'Einstellungen', 'settings' ] ],
	website: [ [ 'Unternehmen', 'profile' ] ],
	ki: [ [ 'Einstellungen', 'settings' ] ],
	stil: [ [ 'Style Mimic', 'style' ] ],
	start: [ [ 'Keywords', 'keywords' ] ],
	entscheidungen: [
		[ 'Entitäten', 'entities' ],
		[ 'Themen', 'topics' ],
	],
};

function Paste( { text } ) {
	const [ copied, setCopied ] = useState( false );
	const copy = () => {
		if ( window.navigator && window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( text ).then( () => {
				setCopied( true );
				window.setTimeout( () => setCopied( false ), 1500 );
			} );
		}
	};
	return (
		<div className="lsec-paste">
			<code className="lsec-command">{ text }</code>
			<Button variant="tertiary" size="small" onClick={ copy }>
				{ copied ? 'Kopiert' : 'Kopieren' }
			</Button>
		</div>
	);
}

function Item( { item, onDone } ) {
	let mark = '○';
	let cls = 'is-open';
	if ( item.ok ) {
		mark = '✓';
		cls = 'is-ok';
	} else if ( item.optional ) {
		mark = '–';
		cls = 'is-optional';
	}
	return (
		<li className={ `lsec-setup-item ${ cls }` }>
			<span className="lsec-setup-mark" aria-hidden="true">
				{ mark }
			</span>
			<div>
				<strong>{ item.label }</strong>: { item.detail }
				{ ( Array.isArray( item.paste ) ? item.paste : [ item.paste ] )
					.filter( Boolean )
					.map( ( text, index ) => (
						<Paste key={ index } text={ text } />
					) ) }
				{ item.action && <ToolAction action={ item.action } onDone={ onDone } /> }
			</div>
		</li>
	);
}

function Action( { step, busy, onRun } ) {
	const [ radius, setRadius ] = useState( '25' );
	const [ text, setText ] = useState( '' );
	const [ force, setForce ] = useState( false );
	const action = step.action;
	if ( ! action ) {
		return null;
	}
	const run = () => {
		const data = {};
		if ( 'website' === step.key ) {
			data.radius = parseFloat( radius ) || 25;
		}
		if ( 'stil' === step.key ) {
			data.text = text;
		}
		if ( 'ki' === step.key ) {
			data.force = force;
		}
		onRun( step.key, data );
	};
	return (
		<div className="lsec-setup-action">
			{ 'website' === step.key && (
				<TextControl
					label="Umkreis des Servicegebiets um den Firmensitz (km)"
					type="number"
					value={ radius }
					onChange={ setRadius }
					__nextHasNoMarginBottom
				/>
			) }
			{ 'stil' === step.key && (
				<TextareaControl
					label="Stilvorgabe (optional, z. B. ein Prompt aus einem anderen Schreibwerkzeug)"
					help="Leer lassen, um den Stil aus den eigenen Seiten abzuleiten."
					value={ text }
					onChange={ setText }
					rows={ 5 }
					__nextHasNoMarginBottom
				/>
			) }
			{ 'ki' === step.key && (
				<CheckboxControl
					label="Vorhandene Werte durch die erprobten ersetzen"
					checked={ force }
					onChange={ setForce }
					__nextHasNoMarginBottom
				/>
			) }
			<p>
				<Button variant={ step.done ? 'secondary' : 'primary' } onClick={ run } isBusy={ busy } disabled={ busy }>
					{ 'stil' === step.key && text.trim() ? 'Stil aus der Vorgabe übertragen' : action.label }
				</Button>{ ' ' }
				<span className="lsec-muted">{ action.cost }</span>
			</p>
		</div>
	);
}

function Step( { step, number, busy, onRun, onReload } ) {
	const links = LINKS[ step.key ] || [];
	return (
		<Card className={ `lsec-setup-step ${ step.done ? 'is-done' : 'is-open' }` }>
			<CardHeader>
				<h2>
					{ number }. { step.title }
				</h2>
				<span className={ `lsec-badge ${ step.done ? 'is-ok' : 'is-open' }` }>{ step.done ? 'erledigt' : 'offen' }</span>
			</CardHeader>
			<CardBody>
				<ul className="lsec-setup-items">
					{ step.items.map( ( item, index ) => (
						<Item key={ item.label } item={ item } onDone={ onReload } />
					) ) }
				</ul>
				{ step.note && <p className="lsec-muted">{ step.note }</p> }
				<Action step={ step } busy={ busy } onRun={ onRun } />
				{ links.length > 0 && (
					<p className="lsec-setup-links">
						{ links.map( ( [ label, key ] ) =>
							pages[ key ] ? (
								<a key={ key } href={ pages[ key ] }>
									{ label } öffnen
								</a>
							) : null
						) }
					</p>
				) }
			</CardBody>
		</Card>
	);
}

export default function Setup() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ result, setResult ] = useState( null );

	const load = useCallback( () => {
		getSetup()
			.then( setData )
			.catch( ( e ) => setError( e.message || 'Der Stand der Einrichtung ließ sich nicht laden.' ) );
	}, [] );
	useEffect( load, [ load ] );

	const onRun = ( key, payload ) => {
		setBusy( key );
		setResult( null );
		runSetup( key, payload )
			.then( ( r ) => {
				setResult( { status: 'success', key, messages: r.messages, cost: r.cost } );
				setData( r.status );
			} )
			.catch( ( e ) => setResult( { status: 'error', key, messages: [ e.message || 'Der Schritt ist fehlgeschlagen.' ] } ) )
			.finally( () => setBusy( '' ) );
	};

	if ( error && ! data ) {
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
		<>
			<p className="lsec-setup-progress">
				<strong>
					{ data.done } von { data.total } Schritten erledigt.
				</strong>{ ' ' }
				Von Hand bleiben nur der API-Schlüssel in wp-config.php, der Cronjob in Plesk und die Freigabe in der Search Console.
			</p>
			{ result && (
				<Notice status={ result.status } onRemove={ () => setResult( null ) }>
					<ul className="lsec-setup-messages">
						{ result.messages.map( ( m, i ) => (
							<li key={ i }>{ m }</li>
						) ) }
					</ul>
				</Notice>
			) }
			<div className="lsec-setup">
				{ data.steps.map( ( step, index ) => (
					<Step key={ step.key } step={ step } number={ index + 1 } busy={ busy === step.key } onRun={ onRun } onReload={ load } />
				) ) }
			</div>
		</>
	);
}
