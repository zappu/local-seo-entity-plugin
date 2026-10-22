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
import Entities from './entities';
import Keywords from './keywords';
import Profile from './profile';
import Settings from './settings';
import Style from './style';
import Topics from './topics';
import './admin.scss';

const VIEWS = {
	dashboard: Dashboard,
	profile: Profile,
	entities: Entities,
	keywords: Keywords,
	topics: Topics,
	style: Style,
	settings: Settings,
};

const root = document.getElementById( 'lsec-app' );
if ( root ) {
	const View = VIEWS[ root.dataset.view ] || Dashboard;
	createRoot( root ).render( <View /> );
}
