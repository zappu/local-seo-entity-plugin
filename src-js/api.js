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
export const mergeEntities = ( target, sources ) =>
	apiFetch( { path: `${ base }/entities/merge`, method: 'POST', data: { target, sources } } );
export const getMergeSuggestions = () => apiFetch( { path: `${ base }/entities/merge-suggestions` } );
export const getIndex = () => apiFetch( { path: `${ base }/index` } );
export const runIndex = ( all = false ) =>
	apiFetch( { path: `${ base }/index/run`, method: 'POST', data: { all } } );
export const stopIndex = () => apiFetch( { path: `${ base }/index/stop`, method: 'POST' } );
export const analyzeProfile = ( urls ) =>
	apiFetch( { path: `${ base }/profile/analyze`, method: 'POST', data: { urls } } );
