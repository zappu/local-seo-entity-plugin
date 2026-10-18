#!/usr/bin/env bash
#
# Machbarkeitstest, Etappe 1: Prüfpunkte M2 bis M5 auf einer echten WordPress-Installation.
#
# Aufruf auf dem Server (Plugin muss aktiviert sein):
#   bash bin/machbarkeitstest.sh --path=/var/www/vhosts/example.de/httpdocs
# Optional: WP="php /pfad/wp-cli.phar" bash bin/machbarkeitstest.sh ...
#
# Der Test legt Selbsttest-Abläufe und Entwürfe an und räumt sie am Ende wieder auf.
# Er verändert kurzzeitig die Tabelle lsec_topics (Spalte rationale wird entfernt und
# per Migration wiederhergestellt). Nicht auf einer Live-Website mit echten Daten ausführen.

set -u

WP="${WP:-wp}"
WP_ARGS=("$@")
PASS=0
FAIL=0
# Nach einem simulierten Absturz gelten Aufträge nach 10 Sekunden als verwaist (Produktion: 600).
STALE=10

# WP-CLI zeigt bei WP_DEBUG PHP-Hinweise anderer Plugins in der normalen Ausgabe an,
# z. B. "Deprecated: ..." von Elementor unter PHP 8.4, und Elementors Protokoll hängt beim
# Beenden einen mehrzeiligen Block an ("PHP: <Datum> [notice ...]"). Beides wird
# herausgefiltert, damit die Prüfungen nur die Ausgabe der Befehle auswerten.
wpc() {
	$WP "${WP_ARGS[@]}" "$@" 2>/dev/null | awk '
		/^PHP: [0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] / { skip = 1 }
		skip { next }
		/^(PHP )?(Deprecated|Notice|Warning|Strict Standards|Fatal error): / { next }
		/^[[:space:]]*$/ { next }
		{ print }'
	return "${PIPESTATUS[0]}"
}
json() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=explode(".",$argv[1]); foreach($p as $k){ $d=$d[$k]??null; } echo is_bool($d)?($d?"true":"false"):(is_array($d)?json_encode($d):$d);' "$1"; }
check() {
	local name="$1" expected="$2" actual="$3"
	if [ "$expected" = "$actual" ]; then
		echo "  OK    $name ($actual)"
		PASS=$((PASS + 1))
	else
		echo "  FEHLER $name: erwartet $expected, erhalten $actual"
		FAIL=$((FAIL + 1))
	fi
}
drain() {
	# Arbeitet die Warteschlange ab, bis der Ablauf fertig ist oder nichts mehr passiert.
	local id="$1" i status
	for i in $(seq 1 15); do
		wpc lsec cron --batch-size=5 --stale-after="$STALE" >/dev/null 2>&1
		status=$(wpc lsec selftest report "$id" | json status)
		case "$status" in succeeded|failed|blocked) return 0 ;; esac
	done
}
run_id() { json run_id; }

echo "Machbarkeitstest Etappe 1 – $(date -u '+%Y-%m-%d %H:%M UTC')"
wpc lsec selftest cleanup >/dev/null 2>&1

echo "M1/M2 Aktivierung und Datenbank"
STATUS=$(wpc lsec status --format=json)
check "keine fehlenden Tabellen" "[]" "$(echo "$STATUS" | json missing_tables)"
check "Schemaversion installiert = Code" "$(echo "$STATUS" | json schema_version_code)" "$(echo "$STATUS" | json schema_version_installed)"

PREFIX=$(wpc db prefix)
TOPICS="${PREFIX}lsec_topics"
wpc db query "INSERT INTO ${TOPICS} (title, format, status, rationale, created_at, updated_at) VALUES ('M2-Testthema', 'article', 'proposed', 'bleibt', UTC_TIMESTAMP(), UTC_TIMESTAMP())" >/dev/null
wpc db query "ALTER TABLE ${TOPICS} DROP COLUMN rationale" >/dev/null
wpc option update lsec_db_version 0 >/dev/null
wpc lsec migrate >/dev/null
check "Spalte per Migration wiederhergestellt" "1" "$(wpc db query "SHOW COLUMNS FROM ${TOPICS} LIKE 'rationale'" --skip-column-names | grep -c '^rationale')"
check "Datensatz erhalten" "1" "$(wpc db query "SELECT COUNT(*) FROM ${TOPICS} WHERE title='M2-Testthema'" --skip-column-names | tr -d ' ')"
wpc db query "DELETE FROM ${TOPICS} WHERE title='M2-Testthema'" >/dev/null

echo "M3 Cronjob über WP-CLI"
wpc lsec cron --stale-after="$STALE" >/dev/null 2>&1
check "Cronjob-Lauf registriert" "true" "$(wpc lsec status --format=json | json cron_ok)"
check "Action-Scheduler-Migration abgeschlossen" "true" "$(wpc lsec status --format=json | json as_migration_complete)"

echo "M4a Wiederaufnahme nach Abbruch in Schritt 2 (compute)"
ID=$(wpc lsec selftest start --key="m4a-$RANDOM$RANDOM" --crash-at=compute | run_id)
wpc lsec cron --batch-size=5 --stale-after="$STALE" >/dev/null 2>&1   # prepare
wpc lsec cron --batch-size=5 --stale-after="$STALE" >/dev/null 2>&1   # compute → Abbruch
R=$(wpc lsec selftest report "$ID")
check "compute nach Abbruch auf running" "running" "$(echo "$R" | json steps.compute.status)"
check "Sperre nach Abbruch noch gesetzt" "true" "$(echo "$R" | json locked)"
sleep 11   # Sperrdauer des Selbsttests: 10 Sekunden
drain "$ID"
R=$(wpc lsec selftest report "$ID")
check "Ablauf abgeschlossen" "succeeded" "$(echo "$R" | json status)"
check "prepare nicht wiederholt" "1" "$(echo "$R" | json steps.prepare.executions)"
check "compute genau einmal wiederholt" "2" "$(echo "$R" | json steps.compute.executions)"
check "create_post einmal" "1" "$(echo "$R" | json steps.create_post.executions)"
check "genau ein Entwurf" "1" "$(echo "$R" | json posts)"

echo "M4b Abbruch nach dem Anlegen des Beitrags (create_post)"
ID=$(wpc lsec selftest start --key="m4b-$RANDOM$RANDOM" --crash-at=create_post | run_id)
for i in 1 2 3; do wpc lsec cron --batch-size=5 --stale-after="$STALE" >/dev/null 2>&1; done
sleep 11
drain "$ID"
R=$(wpc lsec selftest report "$ID")
check "Ablauf abgeschlossen" "succeeded" "$(echo "$R" | json status)"
check "create_post zweimal ausgeführt" "2" "$(echo "$R" | json steps.create_post.executions)"
check "trotzdem genau ein Entwurf" "1" "$(echo "$R" | json posts)"

echo "M4c Vorübergehender Fehler wird verzögert wiederholt"
ID=$(wpc lsec selftest start --key="m4c-$RANDOM$RANDOM" --retry-once | run_id)
wpc lsec cron --batch-size=5 --stale-after="$STALE" >/dev/null 2>&1
wpc lsec cron --batch-size=5 --stale-after="$STALE" >/dev/null 2>&1
R=$(wpc lsec selftest report "$ID")
check "compute wartet auf Wiederholung" "pending" "$(echo "$R" | json steps.compute.status)"
check "Fehlercode gespeichert" "selftest_retry" "$(echo "$R" | json steps.compute.error_code)"
check "Ablauf wartet" "queued" "$(echo "$R" | json status)"

echo "M5a Doppelter Start mit gleichem Schlüssel"
KEY="m5a-$RANDOM$RANDOM"
for i in 1 2 3 4 5; do wpc lsec selftest start --key="$KEY" >/dev/null 2>&1 & done
wait
check "genau ein Ablauf" "1" "$(wpc lsec selftest count "$KEY")"

echo "M5b Gleichzeitige Bearbeitung desselben Ablaufs"
ID=$(wpc lsec selftest start --key="m5b-$RANDOM$RANDOM" | run_id)
for round in 1 2 3 4 5 6; do
	for i in 1 2 3 4; do
		wpc eval "\Lsec\Core\Plugin::instance()->engine->process( ${ID} );" >/dev/null 2>&1 &
	done
	wait
done
drain "$ID"
R=$(wpc lsec selftest report "$ID")
check "Ablauf abgeschlossen" "succeeded" "$(echo "$R" | json status)"
for step in prepare compute create_post finalize; do
	check "$step genau einmal" "1" "$(echo "$R" | json steps.$step.executions)"
done
check "genau ein Entwurf" "1" "$(echo "$R" | json posts)"

wpc lsec selftest cleanup >/dev/null 2>&1

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" -eq 0 ]
