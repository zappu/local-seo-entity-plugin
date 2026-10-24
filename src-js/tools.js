/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Knopf zu einem Werkzeug (0.11.0). Ersetzt Befehle für die Shell in Hinweisen, Einrichtung, Einstellungen
 * und auf der Seite Technik.
 *
 * action.kind:
 * - run: ausführen und Ergebnis zeigen,
 * - choose: Liste laden, wählen, speichern (z. B. Property der Search Console).
 */
import { createElement, Fragment, useState } from '@wordpress/element';
import { Button, Notice, SelectControl } from '@wordpress/components';
import { getToolOptions, runTool, saveToolOption } from './api';

function Result( { result } ) {
	if ( ! result ) {
		return null;
	}
	return (
		<Notice status={ result.ok ? 'success' : 'warning' } isDismissible={ false } className="lsec-tool__result">
			<p>{ result.message }</p>
			{ result.details && result.details.length > 0 && (
				<ul className="lsec-tool__details">
					{ result.details.map( ( line, index ) => (
						<li key={ index }>{ line }</li>
					) ) }
				</ul>
			) }
		</Notice>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.action  tool, label, kind.
 * @param {Function} props.onDone  Nach Erfolg (z. B. Daten neu laden).
 * @param {string}   props.variant Stil des Knopfs.
 */
export default function ToolAction( { action, onDone, variant = 'secondary' } ) {
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ options, setOptions ] = useState( null );
	const [ value, setValue ] = useState( '' );

	const fail = ( error ) => setResult( { ok: false, message: ( error && error.message ) || 'Unbekannter Fehler.' } );
	const done = ( out ) => {
		setResult( out );
		if ( out.ok && onDone ) {
			// Kurz warten, damit die Meldung zu lesen ist, bevor die Seite neu lädt.
			window.setTimeout( () => onDone( out ), 2500 );
		}
	};

	const run = () => {
		setBusy( true );
		setResult( null );
		runTool( action.tool )
			.then( done )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	const load = () => {
		setBusy( true );
		setResult( null );
		getToolOptions( action.tool )
			.then( ( out ) => {
				const list = out.options || [];
				if ( ! list.length ) {
					setResult( out );
					return;
				}
				setOptions( list );
				setValue( out.current && list.some( ( o ) => o.value === out.current ) ? out.current : list[ 0 ].value );
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	const save = () => {
		setBusy( true );
		saveToolOption( action.tool, value )
			.then( ( out ) => {
				if ( out.ok ) {
					setOptions( null );
				}
				done( out );
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="lsec-tool">
			{ options === null ? (
				<Button variant={ variant } size="compact" isBusy={ busy } disabled={ busy } onClick={ action.kind === 'choose' ? load : run }>
					{ busy ? 'Bitte warten …' : action.label }
				</Button>
			) : (
				<div className="lsec-tool__choose">
					<SelectControl
						label={ action.label }
						hideLabelFromVision
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						value={ value }
						options={ options }
						onChange={ setValue }
					/>
					<Button variant="primary" isBusy={ busy } disabled={ busy || ! value } onClick={ save }>
						Übernehmen
					</Button>
					<Button variant="tertiary" disabled={ busy } onClick={ () => setOptions( null ) }>
						Abbrechen
					</Button>
				</div>
			) }
			<Result result={ result } />
		</div>
	);
}
