/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Keywords: täglicher Abgleich (Search Console, Orte und Namen, Ausschlüsse, Textfocus-Volumina),
 * verwandte Keywords von Textfocus, Import mit Vorschau und Spaltenzuordnung, Tabelle mit Ausschlüssen.
 */
import { createElement, Fragment, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, CheckboxControl, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import {
	decideKeyword,
	decideKeywords,
	getKeywordOverview,
	getKeywordTable,
	previewImport,
	relatedKeywords,
	runImport,
	syncKeywords,
} from './api';
import { ago, dateTime, EXCLUSIONS, number, RUN_STATUS, sourceLabel } from './format';

const PAGE = 100;

const STATUS_OPTIONS = [
	{ label: 'aktiv', value: 'active' },
	{ label: 'ausgeschlossen', value: 'excluded' },
	{ label: 'alle', value: 'all' },
].concat( Object.entries( EXCLUSIONS ).map( ( [ value, label ] ) => ( { label: `– ${ label }`, value } ) ) );

const SORT_OPTIONS = [
	{ label: 'Suchvolumen', value: 'volume' },
	{ label: 'Impressionen', value: 'impressions' },
	{ label: 'Klicks', value: 'clicks' },
	{ label: 'Position', value: 'position' },
	{ label: 'Keyword', value: 'keyword' },
	{ label: 'neueste', value: 'new' },
];

/**
 * Kurzfassung eines Schritts des Abgleichs.
 *
 * @param {string} name Schritt.
 * @param {Object} step Status und Ausgabe.
 * @return {string} Text.
 */
function stepText( name, step ) {
	const out = step.output || {};
	if ( out.skipped ) {
		return `übersprungen (${ out.skipped })`;
	}
	if ( out.note ) {
		return out.note;
	}
	switch ( name ) {
		case 'gsc':
			return ( out.fetched || [] ).map( ( f ) => `${ f.days } Tage: ${ number( f.queries ) } Anfragen, ${ number( f.created ) } neu` ).join( ' · ' ) || RUN_STATUS[ step.status ] || step.status;
		case 'analyze':
			return `${ number( out.analyzed || 0 ) } geprüft, ${ number( out.found || 0 ) } mit Ort oder Namen`;
		case 'filter':
			return `${ number( out.checked || 0 ) } bewertet, ${ number( out.changed || 0 ) } geändert`;
		case 'volumes':
			return `${ number( out.saved || 0 ) } Volumina, ${ number( out.calls || 0 ) } Credits${ out.typos && Object.keys( out.typos ).length ? `, ${ Object.keys( out.typos ).length } mögliche Tippfehler übersprungen` : '' }`;
		default:
			return step.status;
	}
}

function SyncCard( { overview, onSync, busy } ) {
	const { gsc, sync } = overview;
	const running = sync && [ 'queued', 'running', 'waiting' ].includes( sync.status );
	const names = { gsc: 'Search Console', analyze: 'Orte und Namen', filter: 'Ausschlüsse', volumes: 'Textfocus-Volumina' };
	return (
		<Card>
			<CardHeader>
				<h2>Täglicher Abgleich</h2>
				<Button variant="secondary" size="small" onClick={ onSync } isBusy={ busy } disabled={ busy || running }>
					Jetzt abgleichen
				</Button>
			</CardHeader>
			<CardBody>
				<dl>
					<div className="lsec-row">
						<dt>Search Console</dt>
						<dd>{ gsc.property && gsc.credentials ? gsc.property : <em>nicht eingerichtet</em> }</dd>
					</div>
					<div className="lsec-row">
						<dt>Letzter Abruf</dt>
						<dd>{ gsc.last_import ? `${ ago( gsc.last_import.at ) } · ${ number( gsc.last_import.rows ) } Anfragen` : 'noch keiner' }</dd>
					</div>
					<div className="lsec-row">
						<dt>Letzter Abgleich</dt>
						<dd>
							{ sync ? `${ RUN_STATUS[ sync.status ] || sync.status }, ${ dateTime( sync.created_at ) }` : 'noch keiner' }
						</dd>
					</div>
				</dl>
				{ sync && sync.status === 'waiting' && <p className="lsec-muted">{ sync.error_message }</p> }
				{ sync && (
					<ul className="lsec-steps">
						{ Object.entries( sync.steps ).map( ( [ name, step ] ) => (
							<li key={ name }>
								<strong>{ names[ name ] || name }:</strong> { stepText( name, step ) }
							</li>
						) ) }
					</ul>
				) }
				<p className="lsec-muted">Läuft jeden Tag über den Cronjob: Search Console (28 Tage, wöchentlich 90 Tage), dann Orte und Namen in neuen Keywords, Ausschlüsse und fehlende Suchvolumina.</p>
			</CardBody>
		</Card>
	);
}

function RelatedCard( { overview, onDone, setNotice } ) {
	const [ keyword, setKeyword ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const tf = overview.textfocus;
	const left = Math.max( 0, tf.daily_budget - tf.used_today );

	const fetchRelated = ( ignoreTypos ) => {
		setBusy( true );
		relatedKeywords( keyword, ignoreTypos )
			.then( ( out ) => {
				if ( out.typo ) {
					// eslint-disable-next-line no-alert
					if ( window.confirm( `Mögliche Schreibvariante: „${ out.keyword }“ → meinten Sie „${ out.typo }“?\n\nOK fragt trotzdem „${ out.keyword }“ ab (1 Credit), Abbrechen lässt Sie das Keyword korrigieren.` ) ) {
						fetchRelated( true );
						return;
					}
					setBusy( false );
					return;
				}
				setNotice( {
					status: 'success',
					text: `${ number( out.saved ) } verwandte Keywords zu „${ out.keyword }“ gespeichert (${ out.cached ? 'aus dem Zwischenspeicher, kein Credit' : '1 Credit' }). ${ out.run_id ? 'Orte, Namen und Ausschlüsse prüft der Abgleich gleich im Hintergrund.' : 'Ausschlüsse folgen beim nächsten Abgleich.' }`,
				} );
				setKeyword( '' );
				setBusy( false );
				onDone();
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', text: e.message } );
				setBusy( false );
			} );
	};

	return (
		<Card>
			<CardHeader>
				<h2>Verwandte Keywords</h2>
			</CardHeader>
			<CardBody>
				{ ! tf.configured && <p className="lsec-muted">Textfocus ist nicht eingerichtet (Konstante LSEC_TEXTFOCUS_API_KEY in wp-config.php).</p> }
				<p className="lsec-muted">Textfocus liefert bis zu 100 verwandte Suchbegriffe mit Volumen. Ein Credit je Abfrage; heute noch { number( left ) } von { number( tf.daily_budget ) }.</p>
				<div className="lsec-inline">
					<TextControl
						label="Ausgangs-Keyword"
						value={ keyword }
						onChange={ setKeyword }
						placeholder="z. B. flächenreinigung"
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button variant="secondary" onClick={ () => fetchRelated( false ) } isBusy={ busy } disabled={ busy || ! keyword.trim() || ! tf.configured || left < 1 }>
						Abfragen
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

function ImportCard( { onDone, setNotice } ) {
	const [ preview, setPreview ] = useState( null );
	const [ mapping, setMapping ] = useState( {} );
	const [ source, setSource ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ inputKey, setInputKey ] = useState( 0 );

	const choose = ( event ) => {
		const file = event.target.files && event.target.files[ 0 ];
		if ( ! file ) {
			return;
		}
		setBusy( true );
		previewImport( file )
			.then( ( out ) => {
				setPreview( out );
				setMapping( out.mapping || {} );
				setSource( '' );
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( false ) );
	};

	const reset = () => {
		setPreview( null );
		setMapping( {} );
		setInputKey( ( k ) => k + 1 );
	};

	const submit = () => {
		setBusy( true );
		runImport( preview.token, mapping, source.trim() )
			.then( ( out ) => {
				const notes = ( out.notes || [] ).join( ' ' );
				setNotice( { status: 'success', text: `Import ${ out.import_id }: ${ number( out.created ) } neue, ${ number( out.updated ) } vorhandene Keywords (${ sourceLabel( out.source ) }). ${ notes }` } );
				reset();
				onDone();
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( false ) );
	};

	const columns = preview ? [ { label: '– nicht importieren –', value: '' } ].concat( preview.header.map( ( h ) => ( { label: h, value: h } ) ) ) : [];

	return (
		<Card className={ preview ? 'lsec-wide' : '' }>
			<CardHeader>
				<h2>Import</h2>
			</CardHeader>
			<CardBody>
				{ ! preview && (
					<>
						<p className="lsec-muted">CSV- oder Excel-Datei, z. B. von Keyword Revealer, Keyword Chef oder Textfocus. Nach dem Hochladen zeigt eine Vorschau, welche Spalte wohin gehört.</p>
						<input key={ inputKey } type="file" accept=".csv,.txt,.xlsx" onChange={ choose } disabled={ busy } aria-label="Datei wählen" />
						{ busy && <Spinner /> }
					</>
				) }
				{ preview && (
					<>
						<p>
							<strong>{ preview.name }</strong> · { number( preview.rows ) } Zeilen · erkannt als { preview.profile.label }
						</p>
						<div className="lsec-mapping">
							{ Object.entries( preview.fields ).map( ( [ field, label ] ) => (
								<SelectControl
									key={ field }
									label={ label }
									value={ mapping[ field ] || '' }
									options={ columns }
									onChange={ ( v ) => setMapping( ( m ) => ( { ...m, [ field ]: v } ) ) }
									__nextHasNoMarginBottom
									__next40pxDefaultSize
								/>
							) ) }
						</div>
						<div className="lsec-table-wrap">
							<table className="widefat striped lsec-table lsec-sample">
								<thead>
									<tr>
										{ preview.header.map( ( h ) => (
											<th key={ h }>
												{ h }
												{ Object.entries( mapping ).filter( ( [ , c ] ) => c === h ).map( ( [ f ] ) => (
													<span key={ f } className="lsec-tag">{ preview.fields[ f ] }</span>
												) ) }
											</th>
										) ) }
									</tr>
								</thead>
								<tbody>
									{ preview.sample.map( ( row, i ) => (
										<tr key={ i }>
											{ preview.header.map( ( h, j ) => <td key={ h }>{ row[ j ] }</td> ) }
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
						<TextControl
							label="Quelle (optional)"
							help={ `Standard: ${ sourceLabel( preview.profile.source ) } (${ preview.profile.source }). Eigene Kennung im Format art:name, z. B. csv:sistrix.` }
							value={ source }
							onChange={ setSource }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<div className="lsec-savebar lsec-savebar--inline">
							<Button variant="primary" onClick={ submit } isBusy={ busy } disabled={ busy || ! mapping.keyword }>
								Importieren
							</Button>
							<Button variant="tertiary" onClick={ reset } disabled={ busy }>Abbrechen</Button>
						</div>
					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * Pfad einer Adresse für die Anzeige.
 *
 * @param {string} url Adresse.
 * @return {string} Pfad.
 */
function path( url ) {
	try {
		return new URL( url ).pathname;
	} catch ( e ) {
		return url;
	}
}

function KeywordTable( { overview, onChanged, setNotice } ) {
	const [ filters, setFilters ] = useState( { search: '', source: '', status: 'active', sort: 'volume' } );
	const [ data, setData ] = useState( null );
	const [ limit, setLimit ] = useState( PAGE );
	const [ busyId, setBusyId ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ bulkBusy, setBulkBusy ] = useState( false );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const latest = useRef( 0 );

	// Nachtrag 24.10. (0.10.0): Nur die Antwort der neuesten Anfrage zählt; eine langsamere ältere überschrieb
	// sonst die Tabelle, und die Filter wirkten, als reagierten sie nicht. Fehler stehen direkt über der Tabelle.
	const load = useCallback( () => {
		const request = latest.current + 1;
		latest.current = request;
		setLoading( true );
		getKeywordTable( { search: filters.search, source: filters.source, status: filters.status, sort: filters.sort, limit } )
			.then( ( result ) => {
				if ( request === latest.current ) {
					setData( result );
					setError( '' );
				}
			} )
			.catch( ( e ) => {
				if ( request === latest.current ) {
					setError( e.message || 'Die Tabelle konnte nicht geladen werden.' );
				}
			} )
			.finally( () => {
				if ( request === latest.current ) {
					setLoading( false );
				}
			} );
	}, [ filters, limit ] );

	useEffect( () => {
		const timer = setTimeout( load, 250 );
		return () => clearTimeout( timer );
	}, [ load ] );

	const setFilter = ( key, value ) => {
		setLimit( PAGE );
		setSelected( [] );
		setFilters( ( f ) => ( { ...f, [ key ]: value } ) );
	};

	const decide = ( id, decision ) => {
		setBusyId( id );
		decideKeyword( id, decision )
			.then( () => {
				load();
				onChanged();
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusyId( null ) );
	};

	// Nachtrag 24.10. (0.10.0): mehrere Keywords auf einmal ausschließen oder aufnehmen.
	const decideSelected = ( decision ) => {
		setBulkBusy( true );
		decideKeywords( selected, decision )
			.then( ( out ) => {
				setNotice( { status: 'success', text: `${ number( out.changed ) } Keywords ${ decision === 'manual' ? 'ausgeschlossen' : 'aufgenommen' }.` } );
				setSelected( [] );
				load();
				onChanged();
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBulkBusy( false ) );
	};
	const toggle = ( id, on ) => setSelected( ( sel ) => ( on ? sel.concat( [ id ] ) : sel.filter( ( x ) => x !== id ) ) );
	const visible = data ? data.rows.map( ( r ) => r.id ) : [];
	const allSelected = visible.length > 0 && visible.every( ( id ) => selected.includes( id ) );
	const selectedRows = data ? data.rows.filter( ( r ) => selected.includes( r.id ) ) : [];

	const summary = overview.summary;
	const sources = [ { label: 'alle Quellen', value: '' } ].concat(
		[ 'gsc', 'textfocus', 'import' ].concat( summary.sources.filter( ( s ) => ! [ 'gsc', 'textfocus' ].includes( s ) ) ).map( ( s ) => ( { label: sourceLabel( s ), value: s } ) )
	);
	const excluded = Object.entries( summary.by_status ).filter( ( [ s ] ) => s !== 'active' );

	return (
		<Card className="lsec-wide">
			<CardHeader>
				<div className="lsec-filters">
					<TextControl label="Suche" value={ filters.search } onChange={ ( v ) => setFilter( 'search', v ) } __nextHasNoMarginBottom __next40pxDefaultSize />
					<SelectControl label="Quelle" value={ filters.source } options={ sources } onChange={ ( v ) => setFilter( 'source', v ) } __nextHasNoMarginBottom __next40pxDefaultSize />
					<SelectControl label="Status" value={ filters.status } options={ STATUS_OPTIONS } onChange={ ( v ) => setFilter( 'status', v ) } __nextHasNoMarginBottom __next40pxDefaultSize />
					<SelectControl label="Sortierung" value={ filters.sort } options={ SORT_OPTIONS } onChange={ ( v ) => setFilter( 'sort', v ) } __nextHasNoMarginBottom __next40pxDefaultSize />
				</div>
			</CardHeader>
			<CardBody>
				<p className="lsec-counts">
					<Button variant="link" onClick={ () => setFilter( 'status', 'active' ) }>{ number( summary.by_status.active || 0 ) } aktiv</Button>
					{ excluded.map( ( [ reason, count ] ) => (
						<Fragment key={ reason }>
							{ ' · ' }
							<Button variant="link" onClick={ () => setFilter( 'status', reason ) }>
								{ number( count ) } { EXCLUSIONS[ reason ] || reason }
							</Button>
						</Fragment>
					) ) }
					{ summary.unanalyzed > 0 && <span className="lsec-muted"> · { number( summary.unanalyzed ) } noch nicht auf Orte und Namen geprüft</span> }
				</p>
				{ selected.length > 0 && (
					<div className="lsec-bulk">
						<span>{ number( selected.length ) } ausgewählt</span>
						{ selectedRows.some( ( r ) => ! r.exclusion ) && (
							<Button variant="secondary" isDestructive disabled={ bulkBusy } onClick={ () => decideSelected( 'manual' ) }>Ausschließen</Button>
						) }
						{ selectedRows.some( ( r ) => r.exclusion ) && (
							<Button variant="secondary" disabled={ bulkBusy } onClick={ () => decideSelected( 'include' ) }>Aufnehmen</Button>
						) }
						<Button variant="tertiary" onClick={ () => setSelected( [] ) }>Auswahl aufheben</Button>
					</div>
				) }
				{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
				{ ( ! data || loading ) && <Spinner /> }
				{ data && data.rows.length === 0 && <p>Keine Keywords für diese Auswahl.</p> }
				{ data && data.rows.length > 0 && (
					<div className={ `lsec-table-wrap${ loading ? ' lsec-loading' : '' }` }>
						<table className="widefat striped lsec-table">
							<thead>
								<tr>
									<th className="lsec-check">
										<CheckboxControl
											label=""
											aria-label="Alle angezeigten auswählen"
											checked={ allSelected }
											onChange={ ( v ) => setSelected( v ? visible : [] ) }
											__nextHasNoMarginBottom
										/>
									</th>
									<th>Keyword</th>
									<th className="lsec-num">Volumen</th>
									<th className="lsec-num">Wettbewerb</th>
									<th className="lsec-num">CPC</th>
									<th className="lsec-num">Impr.</th>
									<th className="lsec-num">Klicks</th>
									<th className="lsec-num">Position</th>
									<th>Seite</th>
									<th>Ausschluss</th>
									<th><span className="screen-reader-text">Aktionen</span></th>
								</tr>
							</thead>
							<tbody>
								{ data.rows.map( ( row ) => (
									<tr key={ row.id }>
										<td className="lsec-check">
											<CheckboxControl
												label=""
												aria-label={ `${ row.keyword } auswählen` }
												checked={ selected.includes( row.id ) }
												onChange={ ( v ) => toggle( row.id, v ) }
												__nextHasNoMarginBottom
											/>
										</td>
										<td>{ row.keyword }</td>
										<td className="lsec-num">
											{ row.search_volume === null ? <span className="lsec-muted">unbekannt</span> : number( row.search_volume ) }
											{ row.volume_source && <span className="lsec-source">{ sourceLabel( row.volume_source ) }</span> }
										</td>
										<td className="lsec-num">{ row.competition === null ? '–' : number( row.competition ) }</td>
										<td className="lsec-num">{ row.cpc === null ? '–' : row.cpc.toLocaleString( 'de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) }</td>
										<td className="lsec-num">{ row.impressions === null ? '–' : number( row.impressions ) }</td>
										<td className="lsec-num">{ row.clicks === null ? '–' : number( row.clicks ) }</td>
										<td className="lsec-num">{ row.position === null ? '–' : row.position.toLocaleString( 'de-DE', { maximumFractionDigits: 1 } ) }</td>
										<td className="lsec-reason">{ row.page_url ? <a href={ row.page_url } target="_blank" rel="noreferrer">{ path( row.page_url ) }</a> : '' }</td>
										<td>
											{ row.exclusion && (
												<span className="lsec-badge lsec-badge--e-rejected">
													{ EXCLUSIONS[ row.exclusion ] || row.exclusion }
													{ row.exclusion_detail && `: ${ row.exclusion_detail }` }
												</span>
											) }
											{ row.exclusion_by && row.exclusion && row.exclusion !== 'manual' && <span className="lsec-muted"> (von Hand)</span> }
											{ row.exclusion_by && ! row.exclusion && <span className="lsec-muted">von Hand aufgenommen</span> }
										</td>
										<td className="lsec-actions">
											{ ! row.exclusion && (
												<Button variant="tertiary" size="small" isDestructive disabled={ busyId === row.id } onClick={ () => decide( row.id, 'manual' ) }>
													Ausschließen
												</Button>
											) }
											{ row.exclusion && (
												<Button variant="secondary" size="small" disabled={ busyId === row.id } onClick={ () => decide( row.id, 'include' ) }>
													Aufnehmen
												</Button>
											) }
											{ row.exclusion_by && (
												<Button variant="tertiary" size="small" disabled={ busyId === row.id } onClick={ () => decide( row.id, 'auto' ) }>
													Filter entscheiden lassen
												</Button>
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
				{ data && data.rows.length < data.total && (
					<p>
						<Button variant="secondary" onClick={ () => setLimit( ( l ) => l + PAGE ) }>
							Weitere laden ({ number( data.rows.length ) } von { number( data.total ) })
						</Button>
					</p>
				) }
			</CardBody>
		</Card>
	);
}

export default function Keywords() {
	const [ overview, setOverview ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ version, setVersion ] = useState( 0 );

	const loadOverview = useCallback( () => {
		getKeywordOverview()
			.then( setOverview )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	}, [] );

	useEffect( () => {
		loadOverview();
		const timer = setInterval( loadOverview, 60000 );
		return () => clearInterval( timer );
	}, [ loadOverview ] );

	const refresh = () => {
		loadOverview();
		setVersion( ( v ) => v + 1 );
	};

	const onSync = () => {
		setBusy( true );
		syncKeywords()
			.then( ( run ) => setNotice( { status: 'success', text: `Abgleich eingeplant (Ablauf ${ run.run_id }); der Cronjob arbeitet ihn in den nächsten Minuten ab.` } ) )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => {
				setBusy( false );
				loadOverview();
			} );
	};

	if ( ! overview ) {
		return notice ? <Notice status="error" isDismissible={ false }>{ notice.text }</Notice> : <Spinner />;
	}
	return (
		<>
			{ notice && <Notice status={ notice.status } onRemove={ () => setNotice( null ) }>{ notice.text }</Notice> }
			<div className="lsec-grid">
				<SyncCard overview={ overview } onSync={ onSync } busy={ busy } />
				<RelatedCard overview={ overview } onDone={ refresh } setNotice={ setNotice } />
				<ImportCard onDone={ refresh } setNotice={ setNotice } />
				<KeywordTable key={ version } overview={ overview } onChanged={ loadOverview } setNotice={ setNotice } />
			</div>
		</>
	);
}
