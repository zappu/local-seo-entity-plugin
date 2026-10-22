/**
 * Formatierung für die Anzeige (deutsch).
 */

const usdFormat = new Intl.NumberFormat( 'de-DE', {
	style: 'currency',
	currency: 'USD',
	minimumFractionDigits: 2,
	maximumFractionDigits: 2,
} );

const smallUsdFormat = new Intl.NumberFormat( 'de-DE', {
	style: 'currency',
	currency: 'USD',
	minimumFractionDigits: 4,
	maximumFractionDigits: 4,
} );

const numberFormat = new Intl.NumberFormat( 'de-DE' );

/**
 * Betrag in US-Dollar; kleine Beträge mit vier Nachkommastellen.
 *
 * @param {number} value Betrag.
 * @return {string} Text.
 */
export function usd( value ) {
	const n = Number( value ) || 0;
	return n > 0 && n < 0.01 ? smallUsdFormat.format( n ) : usdFormat.format( n );
}

/**
 * Ganze Zahl mit Tausenderpunkt.
 *
 * @param {number} value Zahl.
 * @return {string} Text.
 */
export function number( value ) {
	return numberFormat.format( Number( value ) || 0 );
}

/**
 * Zeitpunkt in Ortszeit, z. B. „18.10.2026, 20:45“.
 *
 * @param {string|null} iso ISO-Zeit.
 * @return {string} Text.
 */
export function dateTime( iso ) {
	if ( ! iso ) {
		return '–';
	}
	const date = new Date( iso );
	if ( Number.isNaN( date.getTime() ) ) {
		return '–';
	}
	return date.toLocaleString( 'de-DE', {
		day: '2-digit',
		month: '2-digit',
		year: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	} );
}

/**
 * Abstand zu jetzt, z. B. „vor 3 Min.“.
 *
 * @param {string|null} iso ISO-Zeit.
 * @return {string} Text.
 */
export function ago( iso ) {
	if ( ! iso ) {
		return 'nie';
	}
	const minutes = Math.round( ( Date.now() - new Date( iso ).getTime() ) / 60000 );
	if ( minutes < 1 ) {
		return 'gerade eben';
	}
	if ( minutes < 60 ) {
		return `vor ${ minutes } Min.`;
	}
	const hours = Math.round( minutes / 60 );
	if ( hours < 48 ) {
		return `vor ${ hours } Std.`;
	}
	return `vor ${ Math.round( hours / 24 ) } Tagen`;
}

export const RUN_STATUS = {
	queued: 'eingeplant',
	running: 'läuft',
	waiting: 'wartet',
	blocked: 'blockiert',
	succeeded: 'fertig',
	failed: 'fehlgeschlagen',
	cancelled: 'abgebrochen',
};

export const ENTITY_TYPES = {
	organization: 'Unternehmen',
	brand: 'Marke',
	place: 'Ort',
	service_area: 'Servicegebiet',
	service: 'Leistung',
	product: 'Produkt',
	person: 'Person',
	competitor: 'Wettbewerber',
	concept: 'Begriff',
};

export const ENTITY_STATUS = {
	candidate: 'Kandidat',
	probable: 'wahrscheinlich',
	confirmed: 'bestätigt',
	unresolved: 'ungeklärt',
	rejected: 'abgelehnt',
	merged: 'zusammengeführt',
};

export const ORIGINS = {
	business_profile: 'Profil',
	ai_extraction: 'KI',
	manual: 'von Hand',
	import: 'Import',
};

export const EXCLUSIONS = {
	competitor: 'Wettbewerber',
	brand: 'fremde Firma oder Marke',
	foreign_place: 'Ort außerhalb',
	off_topic: 'ohne Bezug',
	manual: 'von Hand',
};

const SOURCES = {
	gsc: 'Search Console',
	textfocus: 'Textfocus',
	'xlsx:textfocus': 'Textfocus (Excel)',
	'csv:keyword_revealer': 'Keyword Revealer',
	'csv:keyword_chef': 'Keyword Chef',
	import: 'alle Importe',
};

/**
 * Bezeichnung einer Quelle; unbekannte bleiben, wie sie sind.
 *
 * @param {string} source Quelle.
 * @return {string} Text.
 */
export function sourceLabel( source ) {
	return SOURCES[ source ] || source;
}
