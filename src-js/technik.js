/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * Seite Technik (0.11.0): Prüfungen und Reparaturen per Knopf statt per WP-CLI.
 */
import { createElement } from '@wordpress/element';
import { Card, CardBody, CardHeader } from '@wordpress/components';
import ToolAction from './tools';

const TOOLS = [
	{
		action: { tool: 'checks', label: 'Sicherheit prüfen', kind: 'run' },
		text: 'Sucht die API-Schlüssel im Klartext in Datenbank und Fehlerprotokollen und prüft, dass alle Schnittstellen des Plugins nur für Administratoren erreichbar sind. Dauert einige Sekunden.',
	},
	{
		action: { tool: 'migrate', label: 'Datenbank aktualisieren', kind: 'run' },
		text: 'Legt fehlende Tabellen an und bringt das Schema auf den Stand des Plugins. Passiert sonst automatisch beim Update.',
	},
	{
		action: { tool: 'watchdog', label: 'Hängengebliebene Abläufe aufnehmen', kind: 'run' },
		text: 'Plant Abläufe neu ein, die seit über einer Minute ohne Fortschritt sind. Macht der Cronjob sonst bei jedem Lauf.',
	},
];

export default function Technik() {
	return (
		<Card className="lsec-technik">
			<CardHeader>
				<h2>Prüfen und reparieren</h2>
			</CardHeader>
			<CardBody>
				{ TOOLS.map( ( tool ) => (
					<div key={ tool.action.tool } className="lsec-technik__tool">
						<p className="lsec-muted">{ tool.text }</p>
						<ToolAction action={ tool.action } variant="primary" />
					</div>
				) ) }
			</CardBody>
		</Card>
	);
}
