/** @jsxRuntime classic */
/** @jsx createElement */
/** @jsxFrag Fragment */
/**
 * Unternehmensprofil: Unternehmen, Servicegebiet, Standorte, Leistungen, Marken, Wettbewerber.
 * Alles wird als bestätigte Entität gespeichert (Spezifikation 4.2).
 */
import { createElement, Fragment, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { getProfile, saveProfile, searchWikidata, setServiceArea } from './api';

/**
 * Listen des Profils mit ihren Feldern.
 */
const LISTS = [
	{
		key: 'locations',
		title: 'Standorte',
		add: 'Standort hinzufügen',
		help: 'Adresse und Öffnungszeiten werden als bestätigte Angaben gespeichert und später in Beiträgen verwendet.',
		fields: [
			{ key: 'name', label: 'Bezeichnung', wide: true },
			{ key: 'street', label: 'Straße und Hausnummer' },
			{ key: 'postal_code', label: 'PLZ' },
			{ key: 'city', label: 'Ort' },
			{ key: 'phone', label: 'Telefon' },
			{ key: 'opening_hours', label: 'Öffnungszeiten', wide: true },
		],
		empty: { name: '', street: '', postal_code: '', city: '', phone: '', opening_hours: '' },
	},
	{
		key: 'services',
		title: 'Leistungen',
		add: 'Leistung hinzufügen',
		help: 'Grundlage für Geschäftsrelevanz und Themenvorschläge.',
		fields: [
			{ key: 'name', label: 'Leistung' },
			{ key: 'description', label: 'Kurzbeschreibung' },
		],
		empty: { name: '', description: '' },
	},
	{
		key: 'brands',
		title: 'Marken',
		add: 'Marke hinzufügen',
		help: 'Eigene Marken und Marken, die Sie verkaufen oder einsetzen. Sie werden bei der Keyword-Recherche nicht als fremde Marke ausgefiltert.',
		fields: [
			{ key: 'name', label: 'Marke' },
			{ key: 'website', label: 'Website' },
			{ key: 'own', label: 'Eigene Marke', toggle: true },
		],
		empty: { name: '', website: '', own: false },
	},
	{
		key: 'competitors',
		title: 'Wettbewerber',
		add: 'Wettbewerber hinzufügen',
		help: 'Keywords mit diesen Namen werden später nicht als Thema vorgeschlagen.',
		fields: [
			{ key: 'name', label: 'Name' },
			{ key: 'website', label: 'Website' },
		],
		empty: { name: '', website: '' },
	},
];

function ListEditor( { list, items, onChange } ) {
	const update = ( index, key, value ) =>
		onChange( items.map( ( item, i ) => ( i === index ? { ...item, [ key ]: value } : item ) ) );
	return (
		<Card className="lsec-profile__group">
			<CardHeader>
				<h2>{ list.title }</h2>
				<span className="lsec-muted">{ items.length || 'keine' }</span>
			</CardHeader>
			<CardBody>
				<p className="lsec-muted">{ list.help }</p>
				{ items.map( ( item, index ) => (
					<div className="lsec-item" key={ item.id || `neu-${ index }` }>
						<div className="lsec-item__fields">
							{ list.fields.map( ( field ) =>
								field.toggle ? (
									<ToggleControl
										key={ field.key }
										label={ field.label }
										checked={ !! item[ field.key ] }
										onChange={ ( v ) => update( index, field.key, v ) }
										__nextHasNoMarginBottom
									/>
								) : (
									<div key={ field.key } className={ field.wide ? 'lsec-item__wide' : '' }>
										<TextControl
											label={ field.label }
											value={ item[ field.key ] || '' }
											onChange={ ( v ) => update( index, field.key, v ) }
											__nextHasNoMarginBottom
											__next40pxDefaultSize
										/>
									</div>
								)
							) }
						</div>
						<Button
							variant="tertiary"
							isDestructive
							size="small"
							onClick={ () => onChange( items.filter( ( _, i ) => i !== index ) ) }
						>
							Entfernen
						</Button>
					</div>
				) ) }
				<Button variant="secondary" onClick={ () => onChange( [ ...items, { ...list.empty } ] ) }>
					{ list.add }
				</Button>
			</CardBody>
		</Card>
	);
}

function ServiceAreaCard( { area, onSaved } ) {
	const [ query, setQuery ] = useState( '' );
	const [ hits, setHits ] = useState( [] );
	const [ radius, setRadius ] = useState( String( ( area && area.radius_km ) || 0 ) );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const timer = useRef( null );

	useEffect( () => {
		clearTimeout( timer.current );
		if ( query.trim().length < 2 ) {
			setHits( [] );
			return undefined;
		}
		timer.current = setTimeout( () => {
			searchWikidata( query )
				.then( setHits )
				.catch( ( e ) => setError( e.message ) );
		}, 400 );
		return () => clearTimeout( timer.current );
	}, [ query ] );

	const choose = ( id ) => {
		setBusy( true );
		setError( null );
		setServiceArea( { id, radius_km: Number( radius ) || 0 } )
			.then( ( result ) => {
				setQuery( '' );
				setHits( [] );
				onSaved( result.service_area );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	const current = area && area.label ? area : null;
	return (
		<Card className="lsec-profile__group">
			<CardHeader>
				<h2>Servicegebiet</h2>
				{ busy && <Spinner /> }
			</CardHeader>
			<CardBody>
				{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
				<p>
					{ current ? (
						<>
							<strong>{ current.label }</strong>
							{ current.wikidata && current.wikidata[ 0 ] && (
								<>
									{ ' · ' }
									<a href={ `https://www.wikidata.org/wiki/${ current.wikidata[ 0 ] }` } target="_blank" rel="noreferrer">
										{ current.wikidata[ 0 ] }
									</a>
								</>
							) }
							{ current.radius_km > 0 && ` · zusätzlich ${ current.radius_km } km um den Mittelpunkt` }
						</>
					) : (
						'Noch kein Servicegebiet. Ohne Servicegebiet bleiben gleichnamige Orte ungeklärt.'
					) }
				</p>
				<div className="lsec-area">
					<TextControl
						label="Landkreis, Region oder Stadt suchen"
						value={ query }
						onChange={ setQuery }
						placeholder="z. B. Region Hannover"
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label="Umkreis (km)"
						type="number"
						min={ 0 }
						value={ radius }
						onChange={ setRadius }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
				{ hits.length > 0 && (
					<ul className="lsec-hits">
						{ hits.map( ( hit ) => (
							<li key={ hit.id }>
								<Button variant="link" onClick={ () => choose( hit.id ) } disabled={ busy }>
									{ hit.label }
								</Button>
								<span className="lsec-muted"> { hit.description } ({ hit.id })</span>
							</li>
						) ) }
					</ul>
				) }
				{ current && (
					<Button variant="secondary" disabled={ busy } onClick={ () => choose( current.wikidata[ 0 ] ) }>
						Umkreis übernehmen
					</Button>
				) }
			</CardBody>
		</Card>
	);
}

export default function Profile() {
	const [ profile, setProfile ] = useState( null );
	const [ saved, setSaved ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	const apply = ( p ) => {
		setProfile( p );
		setSaved( JSON.stringify( p ) );
	};

	useEffect( () => {
		getProfile()
			.then( apply )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	}, [] );

	if ( ! profile ) {
		return notice ? <Notice status="error" isDismissible={ false }>{ notice.text }</Notice> : <Spinner />;
	}

	const org = profile.organization;
	const setOrg = ( key, value ) => setProfile( { ...profile, organization: { ...org, [ key ]: value } } );
	const dirty = JSON.stringify( profile ) !== saved;

	const onSave = () => {
		if ( ! org.name.trim() ) {
			setNotice( { status: 'error', text: 'Bitte den Namen des Unternehmens eintragen.' } );
			return;
		}
		setSaving( true );
		saveProfile( profile )
			.then( ( result ) => {
				apply( result.profile );
				setNotice( { status: 'success', text: `Profil gespeichert (${ result.result.saved } Einträge${ result.result.removed ? `, ${ result.result.removed } entfernt` : '' }).` } );
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setSaving( false ) );
	};

	return (
		<div className="lsec-profile">
			{ notice && <Notice status={ notice.status } onRemove={ () => setNotice( null ) }>{ notice.text }</Notice> }
			<p className="lsec-muted">
				Alle Angaben werden als bestätigte Entitäten gespeichert. Die Entity-Erkennung ordnet Schreibweisen aus Ihren Beiträgen diesen Einträgen zu.
			</p>
			<Card className="lsec-profile__group">
				<CardHeader>
					<h2>Unternehmen</h2>
				</CardHeader>
				<CardBody>
					<div className="lsec-item__fields">
						<div className="lsec-item__wide">
							<TextControl label="Name" value={ org.name } onChange={ ( v ) => setOrg( 'name', v ) } __nextHasNoMarginBottom __next40pxDefaultSize />
						</div>
						<TextControl label="Website" value={ org.website } onChange={ ( v ) => setOrg( 'website', v ) } __nextHasNoMarginBottom __next40pxDefaultSize />
						<TextControl label="Telefon" value={ org.phone } onChange={ ( v ) => setOrg( 'phone', v ) } __nextHasNoMarginBottom __next40pxDefaultSize />
						<TextControl label="E-Mail" type="email" value={ org.email } onChange={ ( v ) => setOrg( 'email', v ) } __nextHasNoMarginBottom __next40pxDefaultSize />
						<div className="lsec-item__wide">
							<TextareaControl label="Kurzbeschreibung" rows={ 3 } value={ org.description } onChange={ ( v ) => setOrg( 'description', v ) } __nextHasNoMarginBottom />
						</div>
					</div>
				</CardBody>
			</Card>
			<ServiceAreaCard
				area={ profile.service_area }
				onSaved={ ( area ) => {
					const next = { ...profile, service_area: area };
					setProfile( next );
					setSaved( JSON.stringify( { ...JSON.parse( saved ), service_area: area } ) );
					setNotice( { status: 'success', text: `Servicegebiet: ${ area.label }` } );
				} }
			/>
			{ LISTS.map( ( list ) => (
				<ListEditor
					key={ list.key }
					list={ list }
					items={ profile[ list.key ] || [] }
					onChange={ ( items ) => setProfile( { ...profile, [ list.key ]: items } ) }
				/>
			) ) }
			<div className="lsec-savebar">
				<Button variant="primary" onClick={ onSave } isBusy={ saving } disabled={ saving || ! dirty }>
					Profil speichern
				</Button>
				{ dirty && (
					<Button variant="tertiary" disabled={ saving } onClick={ () => setProfile( JSON.parse( saved ) ) }>
						Verwerfen
					</Button>
				) }
			</div>
		</div>
	);
}
