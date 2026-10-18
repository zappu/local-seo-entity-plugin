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
