/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Abläufe (Stufe F): alle Abläufe mit Status, Schritten, KI-Aufrufen, Kosten und Fehlern; Fortsetzen und Abbrechen.
 */
import { createElement, Fragment, useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl, Spinner } from '@wordpress/components';
import { getRun, getRuns, runAction } from './api';
import { dateTime, RUN_STATUS, usd } from './format';

const STATUS_FILTER = [ { label: 'alle', value: '' } ].concat(
	Object.keys( RUN_STATUS ).map( ( key ) => ( { label: RUN_STATUS[ key ], value: key } ) )
);

const STEP_STATUS = {
	pending: 'offen',
	running: 'läuft',
	done: 'fertig',
	succeeded: 'fertig',
	failed: 'fehlgeschlagen',
	waiting: 'wartet',
	skipped: 'übersprungen',
	blocked: 'blockiert',
	cancelled: 'abgebrochen',
};

function RunDetail( { id } ) {
	const [ detail, setDetail ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ openStep, setOpenStep ] = useState( '' );
	useEffect( () => {
		getRun( id )
			.then( setDetail )
			.catch( ( e ) => setError( e.message ) );
	}, [ id ] );
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! detail ) {
		return <Spinner />;
	}
	return (
		<div className="lsec-run-detail">
			{ detail.run.post_edit && (
				<p>
					<a href={ detail.run.post_edit }>Entwurf öffnen</a>
				</p>
			) }
			<h4>Schritte</h4>
			<table className="widefat striped lsec-table">
				<thead>
					<tr>
						<th>Schritt</th>
						<th>Status</th>
						<th className="lsec-num">Versuch</th>
						<th>Beginn</th>
						<th>Ende</th>
						<th>Fehler</th>
						<th>
							<span className="screen-reader-text">Ergebnis</span>
						</th>
					</tr>
				</thead>
				<tbody>
					{ detail.steps.map( ( step ) => (
						<Fragment key={ step.step }>
							<tr>
								<td>
									<code>{ step.step }</code>
								</td>
								<td>{ STEP_STATUS[ step.status ] || step.status }</td>
								<td className="lsec-num">{ step.attempt }</td>
								<td>{ step.started_at ? dateTime( step.started_at ) : '–' }</td>
								<td>{ step.finished_at ? dateTime( step.finished_at ) : '–' }</td>
								<td>{ step.error_code ? `${ step.error_code } ${ step.error_message }` : '' }</td>
								<td>
									{ step.output && (
										<Button variant="link" onClick={ () => setOpenStep( openStep === step.step ? '' : step.step ) }>
											{ openStep === step.step ? 'Ergebnis ausblenden' : 'Ergebnis' }
										</Button>
									) }
								</td>
							</tr>
							{ openStep === step.step && (
								<tr>
									<td colSpan="7">
										<pre className="lsec-run-output">{ step.output }</pre>
									</td>
								</tr>
							) }
						</Fragment>
					) ) }
				</tbody>
			</table>
			{ detail.calls.length > 0 && (
				<>
					<h4>KI-Aufrufe</h4>
					<table className="widefat striped lsec-table">
						<thead>
							<tr>
								<th>Aufgabe</th>
								<th>Modell</th>
								<th>Status</th>
								<th className="lsec-num">Token ein/aus</th>
								<th className="lsec-num">Dauer</th>
								<th className="lsec-num">Kosten</th>
							</tr>
						</thead>
						<tbody>
							{ detail.calls.map( ( call, index ) => (
								<tr key={ index }>
									<td>{ call.task }</td>
									<td>{ call.model }</td>
									<td>{ call.status }</td>
									<td className="lsec-num">
										{ call.in } / { call.out }
									</td>
									<td className="lsec-num">{ ( call.ms / 1000 ).toFixed( 1 ) } s</td>
									<td className="lsec-num">{ null === call.cost ? '–' : usd( call.cost ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</>
			) }
		</div>
	);
}

function RunRow( { run, labels, open, onToggle, onAction, busy } ) {
	return (
		<>
			<tr className={ open ? 'is-open' : '' }>
				<td>
					<Button variant="link" onClick={ onToggle } aria-expanded={ open }>
						{ run.id }
					</Button>
				</td>
				<td>{ labels[ run.type ] || run.type }</td>
				<td>{ run.subject }</td>
				<td>
					<span className={ `lsec-run-status lsec-run-status--${ run.status }` }>{ RUN_STATUS[ run.status ] || run.status }</span>
				</td>
				<td>
					<code>{ run.step }</code>
				</td>
				<td className="lsec-num">{ usd( run.cost ) }</td>
				<td>{ run.error_code ? `${ run.error_code } ${ run.error_message }` : '' }</td>
				<td>{ dateTime( run.updated_at ) }</td>
				<td className="lsec-actions">
					{ 'waiting' === run.status && (
						<Button variant="secondary" size="small" disabled={ busy } onClick={ () => onAction( run.id, 'resume' ) }>
							Fortsetzen
						</Button>
					) }
					{ ! run.terminal && (
						<Button variant="tertiary" size="small" isDestructive disabled={ busy } onClick={ () => onAction( run.id, 'cancel' ) }>
							Abbrechen
						</Button>
					) }
				</td>
			</tr>
			{ open && (
				<tr className="lsec-run-detail-row">
					<td colSpan="9">
						<RunDetail id={ run.id } />
					</td>
				</tr>
			) }
		</>
	);
}

export default function Runs() {
	const [ status, setStatus ] = useState( '' );
	const [ type, setType ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ open, setOpen ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( () => {
		getRuns( { status, type, page } )
			.then( setData )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	}, [ status, type, page ] );

	useEffect( () => {
		load();
		const timer = setInterval( load, 30000 );
		return () => clearInterval( timer );
	}, [ load ] );

	const onAction = ( id, action ) => {
		// eslint-disable-next-line no-alert
		if ( 'cancel' === action && ! window.confirm( `Ablauf ${ id } abbrechen?` ) ) {
			return;
		}
		setBusy( true );
		runAction( id, action )
			.then( ( r ) => {
				setNotice( { status: 'success', text: `Ablauf ${ id }: ${ RUN_STATUS[ r.status ] || r.status }` } );
				load();
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( false ) );
	};

	if ( ! data ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const labels = data.labels || {};
	const typeOptions = [ { label: 'alle', value: '' } ].concat(
		Object.keys( labels ).map( ( key ) => ( {
			label: `${ labels[ key ] }${ data.types[ key ] ? ` (${ data.types[ key ] })` : '' }`,
			value: key,
		} ) )
	);
	const pages = Math.max( 1, Math.ceil( data.total / data.per_page ) );
	return (
		<>
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }
			<Card>
				<CardHeader>
					<div className="lsec-filters">
						<SelectControl
							label="Typ"
							value={ type }
							options={ typeOptions }
							onChange={ ( v ) => {
								setType( v );
								setPage( 1 );
							} }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<SelectControl
							label="Status"
							value={ status }
							options={ STATUS_FILTER }
							onChange={ ( v ) => {
								setStatus( v );
								setPage( 1 );
							} }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</div>
					<span className="lsec-muted">{ data.total } Abläufe</span>
				</CardHeader>
				<CardBody>
					{ data.items.length === 0 && <p>Keine Abläufe für diese Auswahl.</p> }
					{ data.items.length > 0 && (
						<div className="lsec-table-wrap">
							<table className="widefat striped lsec-table lsec-runs">
								<thead>
									<tr>
										<th>ID</th>
										<th>Typ</th>
										<th>Gegenstand</th>
										<th>Status</th>
										<th>Schritt</th>
										<th className="lsec-num">Kosten</th>
										<th>Grund</th>
										<th>Stand</th>
										<th>
											<span className="screen-reader-text">Aktionen</span>
										</th>
									</tr>
								</thead>
								<tbody>
									{ data.items.map( ( run ) => (
										<RunRow
											key={ run.id }
											run={ run }
											labels={ labels }
											open={ open === run.id }
											onToggle={ () => setOpen( open === run.id ? 0 : run.id ) }
											onAction={ onAction }
											busy={ busy }
										/>
									) ) }
								</tbody>
							</table>
						</div>
					) }
					{ pages > 1 && (
						<p className="lsec-pager">
							<Button variant="secondary" size="small" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
								Zurück
							</Button>{ ' ' }
							Seite { page } von { pages }{ ' ' }
							<Button variant="secondary" size="small" disabled={ page >= pages } onClick={ () => setPage( page + 1 ) }>
								Weiter
							</Button>
						</p>
					) }
				</CardBody>
			</Card>
		</>
	);
}
