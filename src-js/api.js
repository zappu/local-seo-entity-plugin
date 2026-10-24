/**
 * Zugriff auf den REST-Namensraum lsec/v1. apiFetch setzt die Nonce selbst.
 */
import apiFetch from '@wordpress/api-fetch';

const base = '/lsec/v1';

export const getDashboard = () => apiFetch( { path: `${ base }/dashboard` } );
export const getSettings = () => apiFetch( { path: `${ base }/settings` } );

/**
 * Speichert Werte. Bei Prüffehlern antwortet der Server mit 422 und den Gründen je Feld.
 *
 * @param {Object} values Schlüssel → Wert.
 * @return {Promise<Object>} saved, errors, settings.
 */
export async function saveSettings( values ) {
	try {
		return await apiFetch( {
			path: `${ base }/settings`,
			method: 'POST',
			data: { values },
		} );
	} catch ( error ) {
		if ( error && error.errors ) {
			return error;
		}
		throw error;
	}
}

export const runAction = ( id, action ) =>
	apiFetch( { path: `${ base }/runs/${ id }/${ action }`, method: 'POST' } );

export const refreshTextfocus = () =>
	apiFetch( { path: `${ base }/textfocus/account`, method: 'POST' } );

export const getProfile = () => apiFetch( { path: `${ base }/profile` } );
export const saveProfile = ( profile ) =>
	apiFetch( { path: `${ base }/profile`, method: 'POST', data: profile } );
export const searchWikidata = ( q ) =>
	apiFetch( { path: `${ base }/wikidata/search?q=${ encodeURIComponent( q ) }` } );
export const setServiceArea = ( data ) =>
	apiFetch( { path: `${ base }/service-area`, method: 'POST', data } );

/**
 * Entitäten mit Filtern.
 *
 * @param {Object} params type, status, search, min_confidence, limit.
 * @return {Promise<Array>} Liste.
 */
export function getEntities( params ) {
	const query = new URLSearchParams();
	Object.entries( params ).forEach( ( [ k, v ] ) => {
		if ( v !== '' && v !== null && v !== undefined ) {
			query.set( k, v );
		}
	} );
	return apiFetch( { path: `${ base }/entities?${ query.toString() }` } );
}
export const getEntity = ( id ) => apiFetch( { path: `${ base }/entities/${ id }` } );
export const decideEntity = ( id, decision ) =>
	apiFetch( { path: `${ base }/entities/${ id }/decision`, method: 'POST', data: { decision } } );
export const decideEntities = ( ids, decision ) =>
	apiFetch( { path: `${ base }/entities/decision`, method: 'POST', data: { ids, decision } } );
export const updateEntity = ( id, fields ) =>
	apiFetch( { path: `${ base }/entities/${ id }`, method: 'POST', data: fields } );
export const deleteEntity = ( id ) => apiFetch( { path: `${ base }/entities/${ id }`, method: 'DELETE' } );
export const mergeEntities = ( target, sources ) =>
	apiFetch( { path: `${ base }/entities/merge`, method: 'POST', data: { target, sources } } );
export const getMergeSuggestions = () => apiFetch( { path: `${ base }/entities/merge-suggestions` } );
export const getIndex = () => apiFetch( { path: `${ base }/index` } );
export const runIndex = ( all = false ) =>
	apiFetch( { path: `${ base }/index/run`, method: 'POST', data: { all } } );
export const stopIndex = () => apiFetch( { path: `${ base }/index/stop`, method: 'POST' } );
export const analyzeProfile = ( urls ) =>
	apiFetch( { path: `${ base }/profile/analyze`, method: 'POST', data: { urls } } );

/**
 * Abfrage mit Suchparametern.
 *
 * @param {string} path  Pfad unter lsec/v1.
 * @param {Object} query Parameter.
 * @return {Promise<*>} Antwort.
 */
function getWith( path, query ) {
	const params = new URLSearchParams();
	Object.entries( query ).forEach( ( [ k, v ] ) => {
		if ( v !== '' && v !== null && v !== undefined ) {
			params.set( k, v );
		}
	} );
	return apiFetch( { path: `${ base }${ path }?${ params.toString() }` } );
}

export const getKeywordTable = ( query ) => getWith( '/keywords/table', query );
export const getKeywordOverview = () => apiFetch( { path: `${ base }/keywords/overview` } );
export const decideKeyword = ( id, decision ) =>
	apiFetch( { path: `${ base }/keywords/${ id }/exclusion`, method: 'POST', data: { decision } } );
export const decideKeywords = ( ids, decision ) =>
	apiFetch( { path: `${ base }/keywords/exclusion`, method: 'POST', data: { ids, decision } } );
export const syncKeywords = () => apiFetch( { path: `${ base }/keywords/sync`, method: 'POST' } );
export const relatedKeywords = ( keyword, ignoreTypos = false ) =>
	apiFetch( { path: `${ base }/keywords/related`, method: 'POST', data: { keyword, ignore_typos: ignoreTypos } } );

/**
 * Lädt eine Datei für die Vorschau hoch.
 *
 * @param {File} file Datei.
 * @return {Promise<Object>} Vorschau.
 */
export function previewImport( file ) {
	const body = new window.FormData();
	body.append( 'file', file );
	return apiFetch( { path: `${ base }/keywords/import/preview`, method: 'POST', body } );
}

export const runImport = ( token, mapping, source ) =>
	apiFetch( { path: `${ base }/keywords/import`, method: 'POST', data: { token, mapping, source } } );

export const getTopics = ( status ) => getWith( '/topics', { status } );
export const getTopic = ( id ) => apiFetch( { path: `${ base }/topics/${ id }` } );
export const compareTopics = ( a, b ) => apiFetch( { path: `${ base }/topics/compare?a=${ a }&b=${ b }` } );
export const resolveTopics = ( keep, reject ) =>
	apiFetch( { path: `${ base }/topics/resolve`, method: 'POST', data: { keep, reject } } );
export const decideTopic = ( id, decision ) =>
	apiFetch( { path: `${ base }/topics/${ id }/decision`, method: 'POST', data: { decision } } );
export const startResearch = ( data ) => apiFetch( { path: `${ base }/topics/research`, method: 'POST', data } );
export const decideClaim = ( id, decision ) =>
	apiFetch( { path: `${ base }/claims/${ id }/decision`, method: 'POST', data: { decision } } );
export const produceTopic = ( id, again = false ) => apiFetch( { path: `${ base }/topics/${ id }/produce`, method: 'POST', data: { again } } );
export const getProduction = ( runId ) => apiFetch( { path: `${ base }/production/${ runId }` } );
export const getStyle = () => apiFetch( { path: `${ base }/style` } );
export const buildStyle = ( data ) => apiFetch( { path: `${ base }/style/build`, method: 'POST', data } );
export const saveStyle = ( profile ) => apiFetch( { path: `${ base }/style`, method: 'POST', data: { profile } } );
export const activateStyle = ( version ) => apiFetch( { path: `${ base }/style/${ version }/activate`, method: 'POST' } );

export const getSetup = () => apiFetch( { path: `${ base }/setup` } );
export const runSetup = ( step, data ) =>
	apiFetch( { path: `${ base }/setup/${ step }`, method: 'POST', data } );

export const getRuns = ( { status = '', type = '', page = 1 } = {} ) => {
	const query = new URLSearchParams( { status, type, page: String( page ) } );
	return apiFetch( { path: `${ base }/runs?${ query.toString() }` } );
};
export const getRun = ( id ) => apiFetch( { path: `${ base }/runs/${ id }` } );
export const batchProduce = ( topicIds, start ) =>
	apiFetch( { path: `${ base }/production/batch`, method: 'POST', data: { topic_ids: topicIds, start } } );
export const getReport = () => apiFetch( { path: `${ base }/report` } );
