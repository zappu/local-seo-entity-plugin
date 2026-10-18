#!/usr/bin/env bash
#
# MVP 1: Prüfungen je Stufe auf einer echten WordPress-Installation, ohne Kosten.
#   Stufe A: A1 Schemaversion 3 · A2 KI-Budget hält einen Ablauf an und gibt ihn nach Erhöhung frei
#            A3 REST-Routen der Oberfläche geschützt · A4 Dashboard-Daten · A5 Geheimnisse (M8) · A6 Oberfläche gebaut
#
# Aufruf (Plugin aktiviert, als Benutzer des Abonnements):
#   bash bin/mvp-pruefung.sh --path=/var/www/vhosts/example.de/httpdocs
# Optional: WP="php /pfad/wp-cli.phar" bash bin/mvp-pruefung.sh ...
#
# Der Test ändert Einstellungen nur vorübergehend und stellt sie am Ende wieder her.
# KI-Aufrufe laufen über eine Attrappe ohne Netz; ihre Kosten werden beim Aufräumen gelöscht.

set -u

WP="${WP:-wp}"
WP_ARGS=("$@")
PASS=0
FAIL=0
SKIP=0
STALE=10
TMP=$(mktemp -d)
SAVED=""
cleanup() {
	if [ -n "$SAVED" ]; then
		echo "$SAVED" | wpc option update lsec_settings --format=json >/dev/null
	fi
	wpc lsec selftest cleanup >/dev/null 2>&1
	rm -rf "$TMP"
}
trap cleanup EXIT

# Ausgabe von WP-CLI ohne PHP-Hinweise anderer Plugins (siehe machbarkeitstest.sh).
wpc() {
	$WP "${WP_ARGS[@]}" "$@" 2>/dev/null | awk '
		/^PHP: [0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] / { skip = 1 }
		skip { next }
		/^(PHP )?(Deprecated|Notice|Warning|Strict Standards|Fatal error): / { next }
		/^[[:space:]]*$/ { next }
		{ print }'
	return "${PIPESTATUS[0]}"
}
json() { php -r '$in=stream_get_contents(STDIN); $l=explode("\n",$in); $d=null; for($i=0;$i<count($l)&&$d===null;$i++){ if(!preg_match("/^\\s*[\\[{]/",$l[$i])) continue; for($j=count($l)-1;$j>=$i&&$d===null;$j--){ $s=implode("\n",array_slice($l,$i,$j-$i+1)); $t=json_decode($s,true); if(is_array($t)){ $d=$t; break; } /* Text hinter dem JSON in derselben Zeile (z. B. Elementor-Protokoll) abschneiden. */ for($k=strlen($s)-1;$k>0;$k--){ if($s[$k]!=="]"&&$s[$k]!=="}") continue; $t=json_decode(substr($s,0,$k+1),true); if(is_array($t)){ $d=$t; break; } } } } $p=explode(".",$argv[1]); foreach($p as $k){ if($k==="count"){ $d=is_array($d)?count($d):0; continue; } $d=is_array($d)?($d[$k]??null):null; } echo is_bool($d)?($d?"true":"false"):(is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):$d);' "$1"; }
# Ungefilterte Ausgabe für JSON: json() überspringt fremde Zeilen selbst. Der Filter in wpc() würde nach einem
# mehrzeiligen Elementor-Protokollblock alles Weitere verwerfen, auch das JSON.
wpj() { $WP "${WP_ARGS[@]}" "$@" 2>&1; }
# Zeigt die Rohausgabe, wenn darin kein JSON gefunden wurde.
diagnose() {
	local label="$1" raw="$2"
	if [ -z "$(printf '%s' "$raw" | json 0 2>/dev/null)$(printf '%s' "$raw" | json status 2>/dev/null)$(printf '%s' "$raw" | json source 2>/dev/null)" ]; then
		echo "  Rohausgabe ($label):"
		printf '%s\n' "$raw" | head -15 | cut -c1-300 | sed 's/^/    | /'
	fi
}
last_number() { grep -E '^[0-9]+$' | tail -1; }
last_word() { grep -E '^[A-Za-z0-9_]+$' | tail -1; }
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
contains() {
	local name="$1" needle="$2" haystack="$3"
	if printf '%s' "$haystack" | grep -qF -- "$needle"; then
		echo "  OK    $name"
		PASS=$((PASS + 1))
	else
		echo "  FEHLER $name: „$needle“ nicht gefunden"
		FAIL=$((FAIL + 1))
	fi
}
skip() { echo "  --    $1"; SKIP=$((SKIP + 1)); }
drain() {
	local id="$1" i status
	for i in $(seq 1 15); do
		wpc lsec cron --batch-size=5 --stale-after="$STALE" >/dev/null 2>&1
		status=$(wpj lsec selftest report "$id" | json status)
		case "$status" in succeeded|failed|blocked|waiting|cancelled) return 0 ;; esac
	done
}

echo "MVP 1, Stufe A – $(date -u '+%Y-%m-%d %H:%M UTC')"
if [ -z "$(wpj lsec status --format=json | json plugin_version)" ]; then
	echo "Abbruch: 'wp lsec' nicht verfügbar. Ist das Plugin aktiviert?"
	exit 2
fi
PREFIX=$(wpc db prefix | last_word)
SAVED=$(wpc option get lsec_settings --format=json)
wpc lsec selftest cleanup >/dev/null 2>&1

# ---------------------------------------------------------------------------
echo "A1 Schemaversion 3"
STATUS=$(wpj lsec status --format=json)
check "Schemaversion installiert" "3" "$(echo "$STATUS" | json schema_version_installed)"
for col in "keyword_metrics competition" "keywords exclusion" "entities merged_into"; do
	set -- $col
	check "Spalte $1.$2" "1" "$(wpc db query "SHOW COLUMNS FROM ${PREFIX}lsec_$1 LIKE '$2'" --skip-column-names | grep -c "^$2")"
done
printf 'Keyword;Volume;Competition\nlsec mvp pruefung;100;45\n' > "$TMP/wettbewerb.csv"
wpc lsec keywords import "$TMP/wettbewerb.csv" --source=csv:lsec_mvp >/dev/null
check "Wettbewerb aus dem Import gespeichert" "45.00" "$(wpc db query "SELECT competition FROM ${PREFIX}lsec_keyword_metrics WHERE source='csv:lsec_mvp'" --skip-column-names | tail -1)"
# Update 2 → 3: Spalte entfernen, Version zurücksetzen, migrieren; der Datensatz muss bleiben.
wpc db query "ALTER TABLE ${PREFIX}lsec_keyword_metrics DROP COLUMN competition" >/dev/null
wpc option update lsec_db_version 2 >/dev/null
wpc lsec migrate >/dev/null
check "Migration 2 → 3 ergänzt die Spalte" "1" "$(wpc db query "SHOW COLUMNS FROM ${PREFIX}lsec_keyword_metrics LIKE 'competition'" --skip-column-names | grep -c '^competition')"
check "Datensatz nach Migration erhalten" "1" "$(wpc db query "SELECT COUNT(*) FROM ${PREFIX}lsec_keyword_metrics WHERE source='csv:lsec_mvp'" --skip-column-names | last_number)"
wpc db query "DELETE FROM ${PREFIX}lsec_keyword_metrics WHERE source = 'csv:lsec_mvp'" >/dev/null
wpc db query "DELETE FROM ${PREFIX}lsec_imports WHERE source = 'csv:lsec_mvp'" >/dev/null
wpc db query "DELETE k FROM ${PREFIX}lsec_keywords k LEFT JOIN ${PREFIX}lsec_keyword_metrics m ON m.keyword_id = k.id WHERE m.id IS NULL" >/dev/null

# ---------------------------------------------------------------------------
echo "A2 KI-Budget je Ablauf"
wpc lsec settings set ai_run_budget 0.5 >/dev/null
wpc lsec settings set ai_monthly_budget 0 >/dev/null
ID=$(wpj lsec selftest start --key="mvp-a2-$RANDOM$RANDOM" --ai-calls=3 --ai-cost=0.4 | json run_id)
drain "$ID"
R=$(wpj lsec selftest report "$ID")
check "Ablauf wartet" "waiting" "$(echo "$R" | json status)"
check "Grund KI-Budget" "ai_budget" "$(echo "$R" | json error_code)"
check "Warten zählt nicht als Versuch" "0" "$(echo "$R" | json steps.compute.attempt)"
wpc lsec cron --batch-size=5 --stale-after="$STALE" >/dev/null 2>&1
check "ohne Erhöhung wartet er weiter" "waiting" "$(wpj lsec selftest report "$ID" | json status)"
wpc lsec settings set ai_run_budget 5 >/dev/null
drain "$ID"
drain "$ID"
R=$(wpj lsec selftest report "$ID")
check "nach Erhöhung abgeschlossen" "succeeded" "$(echo "$R" | json status)"
check "compute zweimal ausgeführt" "2" "$(echo "$R" | json steps.compute.executions)"
check "genau ein Entwurf" "1" "$(echo "$R" | json posts)"
B=$(wpj lsec ai budget --run="$ID" --format=json)
check "Kosten des Ablaufs" "2" "$(echo "$B" | json run_spent)"

# ---------------------------------------------------------------------------
echo "A3 REST-Routen geschützt"
OUT=$($WP "${WP_ARGS[@]}" lsec check rest 2>&1)
contains "alle Routen 403 für Besucher und Abonnenten" "Routen geschützt" "$OUT"
contains "Route dashboard" "/lsec/v1/dashboard" "$OUT"
contains "Route settings (POST)" "$(printf 'POST\t/lsec/v1/settings')" "$OUT"

# ---------------------------------------------------------------------------
echo "A4 Dashboard-Daten"
ADMIN=$(wpc user list --role=administrator --field=ID --number=1 | last_number)
D=$(wpj --user="$ADMIN" eval '$r = rest_do_request( "/lsec/v1/dashboard" ); echo wp_json_encode( $r->get_data() ), "\n";')
check "Budget je Ablauf im Dashboard" "5" "$(echo "$D" | json ai.budget.run_limit)"
check "Cronjob-Befehl" "1" "$(echo "$D" | json cron.command | grep -c 'lsec cron')"
check "keine Schlüssel in den Daten" "0" "$(echo "$D" | grep -c 'sk-')"
S=$(wpj --user="$ADMIN" eval '$q = new WP_REST_Request( "POST", "/lsec/v1/settings" ); $q->set_param( "values", array( "ai_web_max_results" => 11 ) ); $r = rest_do_request( $q ); echo wp_json_encode( array( "status" => $r->get_status(), "data" => $r->get_data() ) ), "\n";')
check "ungültiger Wert wird abgelehnt (422)" "422" "$(echo "$S" | json status)"

# ---------------------------------------------------------------------------
echo "A5 Geheimnisse im Klartext (M8)"
OUT=$($WP "${WP_ARGS[@]}" lsec check secrets 2>&1)
if printf '%s' "$OUT" | grep -q "Kein Geheimnis eingestellt"; then
	skip "kein Schlüssel eingestellt"
else
	contains "kein Schlüssel in Datenbank oder Protokoll" "Kein Schlüssel im Klartext gefunden" "$OUT"
fi

# ---------------------------------------------------------------------------
echo "A6 Oberfläche"
DIR=$(wpc plugin path local-seo-entity-plugin --dir | tail -1)
check "assets/dist/admin.js vorhanden" "1" "$( [ -f "$DIR/assets/dist/admin.js" ] && echo 1 || echo 0 )"
check "ohne react-jsx-runtime (WordPress 6.4)" "0" "$(grep -c 'react-jsx-runtime' "$DIR/assets/dist/admin.asset.php" 2>/dev/null | head -1)"

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen, $SKIP übersprungen"
cat <<'EOF'

Von Hand im Backend (Local SEO → Dashboard und Einstellungen):
  - Dashboard zeigt KI-Kosten, Textfocus-Credits (Knopf „Guthaben abfragen“), Cronjob und Hinweise
  - Einstellungen: einen Wert ändern, speichern, Seite neu laden
EOF
[ "$FAIL" -eq 0 ]
