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
import Report from './report';
import Runs from './runs';
import Settings from './settings';
import Setup from './setup';
import Style from './style';
import Topics from './topics';
import './admin.scss';

const VIEWS = {
	dashboard: Dashboard,
	setup: Setup,
	profile: Profile,
	entities: Entities,
	keywords: Keywords,
	topics: Topics,
	runs: Runs,
	report: Report,
	style: Style,
	settings: Settings,
};

const root = document.getElementById( 'lsec-app' );
if ( root ) {
	const View = VIEWS[ root.dataset.view ] || Dashboard;
	createRoot( root ).render( <View /> );
}
