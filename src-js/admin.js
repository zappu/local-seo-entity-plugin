/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * Einstieg der Backend-Oberfläche. Die Seite legt ein Element #lsec-app mit data-view an.
 *
 * JSX wird hier klassisch übersetzt (createElement aus @wordpress/element). Die automatische
 * Übersetzung verlangt das Skript react-jsx-runtime, das WordPress erst ab 6.6 mitbringt.
 */
import { createElement, createRoot } from '@wordpress/element';
import Dashboard from './dashboard';
import Settings from './settings';
import './admin.scss';

const VIEWS = {
	dashboard: Dashboard,
	settings: Settings,
};

const root = document.getElementById( 'lsec-app' );
if ( root ) {
	const View = VIEWS[ root.dataset.view ] || Dashboard;
	createRoot( root ).render( <View /> );
}
