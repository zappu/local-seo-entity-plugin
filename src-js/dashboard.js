/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Dashboard: Hinweise, KI-Kosten und Budget, Textfocus-Credits, Cronjob, Search Console, letzte Abläufe.
 */
import { createElement, Fragment, useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, Spinner } from '@wordpress/components';
import { getDashboard, refreshTextfocus, runAction } from './api';
import { ago, dateTime, number, RUN_STATUS, usd } from './format';

const REFRESH_MS = 60000;

/**
 * Balken für Verbrauch gegen Grenze.
 *
 * @param {Object} props       Eigenschaften.
 * @param {number} props.value Verbrauch.
 * @param {number} props.limit Grenze; 0 = ohne Grenze.
 */
function Meter( { value, limit } ) {
	if ( ! limit ) {
		return <p className="lsec-muted">Ohne Grenze</p>;
	}
	const share = Math.min( 1, value / limit );
	let tone = 'ok';
	if ( share >= 1 ) {
		tone = 'over';
	} else if ( share >= 0.8 ) {
		tone = 'high';
	}
	return (
		<div
			className={ `lsec-meter lsec-meter--${ tone }` }
			role="meter"
			aria-valuemin={ 0 }
			aria-valuemax={ limit }
			aria-valuenow={ value }
		>
			<span style={ { width: `${ Math.round( share * 100 ) }%` } } />
		</div>
	);
}

/**
 * Zeile mit Bezeichnung und Wert.
 *
 * @param {Object} props          Eigenschaften.
 * @param {string} props.label    Bezeichnung.
 * @param {*}      props.children Wert.
 */
function Row( { label, children } ) {
	return (
		<div className="lsec-row">
			<dt>{ label }</dt>
			<dd>{ children }</dd>
		</div>
	);
}

/**
 * Ja/Nein mit Farbe.
 *
 * @param {Object}  props       Eigenschaften.
 * @param {boolean} props.ok    Zustand.
 * @param {string}  props.yes   Text für ja.
 * @param {string}  props.no    Text für nein.
 */
function State( { ok, yes = 'ja', no = 'nein' } ) {
	return <span className={ ok ? 'lsec-state lsec-state--ok' : 'lsec-state lsec-state--no' }>{ ok ? yes : no }</span>;
}

function Hints( { hints } ) {
	if ( ! hints.length ) {
		return null;
	}
	return (
		<div className="lsec-hints">
			{ hints.map( ( hint, index ) => (
				<Notice key={ index } status={ hint.level } isDismissible={ false }>
					<p>{ hint.text }</p>
					{ hint.command && <code className="lsec-command">{ hint.command }</code> }
				</Notice>
			) ) }
		</div>
	);
}

function AiCard( { ai } ) {
	const b = ai.budget;
	return (
		<Card>
			<CardHeader>
				<h2>KI-Kosten</h2>
			</CardHeader>
			<CardBody>
				<p className="lsec-figure">
					{ usd( b.month_spent ) }
					<span className="lsec-figure__limit">{ b.month_limit ? ` von ${ usd( b.month_limit ) }` : '' }</span>
				</p>
				<p className="lsec-muted">Seit { dateTime( b.month_start ) }</p>
				<Meter value={ b.month_spent } limit={ b.month_limit } />
				<dl>
					<Row label="Budget je Ablauf">{ b.run_limit ? usd( b.run_limit ) : 'ohne Grenze' }</Row>
					<Row label="Letzte 30 Tage">
						{ number( ai.last_30_days.calls ) } Aufrufe · { usd( ai.last_30_days.cost ) }
					</Row>
					<Row label="Token (30 Tage)">
						{ number( ai.last_30_days.input_tokens ) } ein · { number( ai.last_30_days.output_tokens ) } aus
					</Row>
					<Row label="Anbieter">{ ai.provider }</Row>
					<Row label="Hauptmodell">{ ai.model || <em>nicht eingestellt</em> }</Row>
					<Row label="Recherchemodell">{ ai.research_model || 'wie Hauptmodell' }</Row>
					<Row label="Treffer der Websuche">{ ai.web_results }</Row>
				</dl>
			</CardBody>
		</Card>
	);
}

function TextfocusCard( { tf, onRefresh, busy } ) {
	const account = tf.account;
	const configured = tf.key_source !== 'none';
	return (
		<Card>
			<CardHeader>
				<h2>Textfocus</h2>
				{ configured && (
					<Button variant="secondary" size="small" onClick={ onRefresh } isBusy={ busy } disabled={ busy }>
						Guthaben abfragen
					</Button>
				) }
			</CardHeader>
			<CardBody>
				{ ! configured && <p>Kein Schlüssel eingetragen (Konstante LSEC_TEXTFOCUS_API_KEY).</p> }
				{ configured && (
					<>
						<p className="lsec-figure">
							{ account && account.credits_remaining !== null ? number( account.credits_remaining ) : '–' }
							<span className="lsec-figure__limit"> Credits</span>
						</p>
						<p className="lsec-muted">
							{ account ? `Stand ${ dateTime( account.checked_at ) }` : 'Noch nicht abgefragt' }
						</p>
						<dl>
							<Row label="Heute verbraucht">
								{ number( tf.used_today ) } von { number( tf.daily_budget ) }
							</Row>
						</dl>
						<Meter value={ tf.used_today } limit={ tf.daily_budget } />
						<dl>
							{ account && <Row label="Tarif">{ account.pack || '–' }</Row> }
							<Row label="Sprachcode">{ tf.lang || <em>nicht gespeichert</em> }</Row>
						</dl>
					</>
				) }
			</CardBody>
		</Card>
	);
}

function CronCard( { cron } ) {
	return (
		<Card>
			<CardHeader>
				<h2>Cronjob</h2>
				<State ok={ cron.ok } yes="läuft" no="prüfen" />
			</CardHeader>
			<CardBody>
				<dl>
					<Row label="Letzter Lauf">
						{ cron.last_run ? `${ ago( cron.last_run ) } (${ dateTime( cron.last_run ) })` : 'noch nie' }
					</Row>
				</dl>
				<p className="lsec-muted">Alle 5 Minuten als Benutzer des Abonnements:</p>
				<code className="lsec-command">{ cron.command }</code>
			</CardBody>
		</Card>
	);
}

function SiteCard( { data } ) {
	const { gsc, integrations, plugin } = data;
	return (
		<Card>
			<CardHeader>
				<h2>Website</h2>
			</CardHeader>
			<CardBody>
				<dl>
					<Row label="Search Console">{ gsc.property || <em>keine Property</em> }</Row>
					<Row label="Letzter Abruf">
						{ gsc.last_import ? `${ dateTime( gsc.last_import.at ) } · ${ number( gsc.last_import.rows ) } Suchanfragen` : 'noch keiner' }
					</Row>
					<Row label="Schlüsseldatei"><State ok={ gsc.credentials } yes="vorhanden" no="fehlt" /></Row>
					<Row label="Rank Math">
						<State ok={ integrations.rank_math } yes="aktiv" no="nicht aktiv" />
						{ integrations.rank_math && ! integrations.rank_math_editor && ' · Editor noch nicht freigeschaltet' }
					</Row>
					<Row label="Elementor"><State ok={ integrations.elementor } yes="aktiv" no="nicht aktiv" /></Row>
					<Row label="Plugin">{ plugin.version } · WordPress { plugin.wp } · PHP { plugin.php }</Row>
					<Row label="Datenbank"><State ok={ plugin.schema_ok } yes="aktuell" no="Migration nötig" /></Row>
				</dl>
			</CardBody>
		</Card>
	);
}

function RunsCard( { runs, onAction, busyId } ) {
	const terminal = [ 'succeeded', 'failed', 'cancelled', 'blocked' ];
	const counts = Object.entries( runs.by_status );
	return (
		<Card className="lsec-wide">
			<CardHeader>
				<h2>Letzte Abläufe</h2>
				<span className="lsec-muted">
					{ counts.length ? counts.map( ( [ s, n ] ) => `${ RUN_STATUS[ s ] || s }: ${ n }` ).join( ' · ' ) : 'Noch keine' }
				</span>
			</CardHeader>
			<CardBody>
				{ runs.recent.length === 0 && <p>Noch keine Abläufe.</p> }
				{ runs.recent.length > 0 && (
					<div className="lsec-table-wrap">
						<table className="widefat striped lsec-table">
							<thead>
								<tr>
									<th>ID</th>
									<th>Typ</th>
									<th>Status</th>
									<th>Schritt</th>
									<th className="lsec-num">Kosten</th>
									<th>Grund</th>
									<th>Stand</th>
									<th><span className="screen-reader-text">Aktionen</span></th>
								</tr>
							</thead>
							<tbody>
								{ runs.recent.map( ( run ) => (
									<tr key={ run.id }>
										<td>{ run.id }</td>
										<td>{ run.type }</td>
										<td><span className={ `lsec-badge lsec-badge--${ run.status }` }>{ RUN_STATUS[ run.status ] || run.status }</span></td>
										<td>{ run.step || '–' }</td>
										<td className="lsec-num">{ usd( run.cost ) }</td>
										<td className="lsec-reason" title={ run.error_message }>{ run.error_message || run.error_code || '' }</td>
										<td title={ dateTime( run.updated_at ) }>{ ago( run.updated_at ) }</td>
										<td className="lsec-actions">
											{ run.status === 'waiting' && (
												<Button variant="secondary" size="small" isBusy={ busyId === run.id } disabled={ busyId !== null } onClick={ () => onAction( run.id, 'resume' ) }>
													Fortsetzen
												</Button>
											) }
											{ ! terminal.includes( run.status ) && (
												<Button variant="tertiary" size="small" isDestructive disabled={ busyId !== null } onClick={ () => onAction( run.id, 'cancel' ) }>
													Abbrechen
												</Button>
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</CardBody>
		</Card>
	);
}

export default function Dashboard() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ message, setMessage ] = useState( null );
	const [ busyId, setBusyId ] = useState( null );
	const [ tfBusy, setTfBusy ] = useState( false );

	const load = useCallback( () => {
		getDashboard()
			.then( ( result ) => {
				setData( result );
				setError( null );
			} )
			.catch( ( e ) => setError( e.message || 'Dashboard konnte nicht geladen werden.' ) );
	}, [] );

	useEffect( () => {
		load();
		const timer = setInterval( load, REFRESH_MS );
		return () => clearInterval( timer );
	}, [ load ] );

	const onAction = ( id, action ) => {
		// Abbrechen lässt sich nicht rückgängig machen; ein Klick daneben soll keinen Ablauf beenden.
		// eslint-disable-next-line no-alert
		if ( action === 'cancel' && ! window.confirm( `Ablauf ${ id } abbrechen?` ) ) {
			return;
		}
		setBusyId( id );
		runAction( id, action )
			.then( () => setMessage( { status: 'success', text: action === 'resume' ? `Ablauf ${ id } ist eingeplant; der nächste Cronjob führt ihn aus.` : `Ablauf ${ id } abgebrochen.` } ) )
			.catch( ( e ) => setMessage( { status: 'error', text: e.message } ) )
			.finally( () => {
				setBusyId( null );
				load();
			} );
	};

	const onRefreshTf = () => {
		setTfBusy( true );
		refreshTextfocus()
			.then( () => setMessage( { status: 'success', text: 'Textfocus-Guthaben aktualisiert.' } ) )
			.catch( ( e ) => setMessage( { status: 'error', text: e.message } ) )
			.finally( () => {
				setTfBusy( false );
				load();
			} );
	};

	if ( error && ! data ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}
	return (
		<>
			{ message && (
				<Notice status={ message.status } onRemove={ () => setMessage( null ) }>{ message.text }</Notice>
			) }
			<Hints hints={ data.hints } />
			<div className="lsec-grid">
				<AiCard ai={ data.ai } />
				<TextfocusCard tf={ data.textfocus } onRefresh={ onRefreshTf } busy={ tfBusy } />
				<CronCard cron={ data.cron } />
				<SiteCard data={ data } />
				<RunsCard runs={ data.runs } onAction={ onAction } busyId={ busyId } />
			</div>
		</>
	);
}
