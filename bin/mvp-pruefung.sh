#!/usr/bin/env bash
#
# MVP 1: Prüfungen je Stufe auf einer echten WordPress-Installation, ohne Kosten.
#   Stufe A: A1 Schemaversion 3 · A2 KI-Budget hält einen Ablauf an und gibt ihn nach Erhöhung frei
#            A3 REST-Routen der Oberfläche geschützt · A4 Dashboard-Daten · A5 Geheimnisse (M8) · A6 Oberfläche gebaut
#   Stufe B: B1 Inhaltsindex · B2 Entity-Erkennung als Ablauf (KI-Attrappe) · B3 Zusammenführen mit Vorschlägen
#            B4 Unternehmensprofil · B5 REST-Routen · B6 Ortsabgleich Hemmingen (nur mit --online, fragt Wikidata)
#            B7 Kategorieseiten im Index, Auswahl der Erkennung, Abbrechen und Wiederaufnehmen (0.4.2)
#            B8 Entitäten bearbeiten und löschen, weitere Vorschläge zum Zusammenführen (0.4.3)
#   Stufe C: C1 Ausschlüsse (KI-Attrappe, ohne Wikidata) · C2 Entscheidungen von Hand · C3 REST-Routen
#            C4 täglicher Abgleich (nur mit --online, liest den letzten Ablauf)
#   Stufe D: D1 Faktenprüfung Eltze/Dollbergen und Überschneidung (KI-Attrappe, Quelle aus dem Zwischenspeicher)
#            D2 Freigabe, Ausschluss off_topic, REST-Routen
#
# Aufruf (Plugin aktiviert, als Benutzer des Abonnements):
#   bash bin/mvp-pruefung.sh --path=/var/www/vhosts/example.de/httpdocs [--online]
# Optional: WP="php /pfad/wp-cli.phar" bash bin/mvp-pruefung.sh ...
#
# Der Test ändert Einstellungen nur vorübergehend und stellt sie am Ende wieder her.
# KI-Aufrufe laufen über eine Attrappe ohne Netz; ihre Kosten werden beim Aufräumen gelöscht.

set -u

WP="${WP:-wp}"
WP_ARGS=()
ONLINE=0
for arg in "$@"; do
	case "$arg" in
		--online) ONLINE=1 ;;
		*) WP_ARGS+=("$arg") ;;
	esac
done
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
	cleanup_b >/dev/null 2>&1
	rm -rf "$TMP"
}
# Testdaten aus Stufe B: Entitäten und Beiträge mit dem Namensanfang LSECTEST.
cleanup_b() {
	[ -z "${PREFIX:-}" ] && return 0
	local e="${PREFIX}lsec_entities"
	wpc db query "DELETE v FROM ${PREFIX}lsec_evidence v JOIN ${PREFIX}lsec_claims c ON c.id = v.target_id AND v.target_type = 'claim' JOIN $e e ON e.id = c.subject_id WHERE e.canonical_name LIKE 'LSECTEST%'"
	wpc db query "DELETE c FROM ${PREFIX}lsec_claims c JOIN $e e ON e.id = c.subject_id WHERE c.subject_type = 'entity' AND e.canonical_name LIKE 'LSECTEST%'"
	wpc db query "DELETE a FROM ${PREFIX}lsec_entity_aliases a JOIN $e e ON e.id = a.entity_id WHERE e.canonical_name LIKE 'LSECTEST%'"
	wpc db query "DELETE m FROM ${PREFIX}lsec_mentions m JOIN $e e ON e.id = m.entity_id WHERE e.canonical_name LIKE 'LSECTEST%'"
	wpc db query "DELETE r FROM ${PREFIX}lsec_relations r JOIN $e e ON e.id = r.source_entity_id OR e.id = r.target_entity_id WHERE e.canonical_name LIKE 'LSECTEST%'"
	wpc db query "DELETE FROM $e WHERE canonical_name LIKE 'LSECTEST%'"
	for id in $(wpc post list --post_type=post --post_status=any --s=LSECTEST --field=ID); do wpc post delete "$id" --force; done
	for id in $(wpc term list category --search=LSECTEST --field=term_id); do wpc term delete category "$id"; done
	wpc db query "DELETE m FROM ${PREFIX}lsec_keyword_metrics m JOIN ${PREFIX}lsec_keywords k ON k.id = m.keyword_id WHERE k.normalized LIKE '%lsectest%'"
	wpc db query "DELETE FROM ${PREFIX}lsec_keywords WHERE normalized LIKE '%lsectest%'"
	wpc db query "DELETE FROM ${PREFIX}lsec_imports WHERE source = 'csv:lsec_c'"
	wpc db query "DELETE tk FROM ${PREFIX}lsec_topic_keywords tk JOIN ${PREFIX}lsec_topics t ON t.id = tk.topic_id WHERE t.title LIKE 'LSECTEST%'"
	wpc db query "DELETE te FROM ${PREFIX}lsec_topic_entities te JOIN ${PREFIX}lsec_topics t ON t.id = te.topic_id WHERE t.title LIKE 'LSECTEST%'"
	wpc db query "DELETE FROM ${PREFIX}lsec_topics WHERE title LIKE 'LSECTEST%'"
	wpc db query "DELETE FROM ${PREFIX}lsec_sources WHERE url LIKE 'https://lsectest.invalid/%'"
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
json() { php -r '$in=stream_get_contents(STDIN); $l=explode("\n",$in); $d=null; for($i=0;$i<count($l)&&$d===null;$i++){ if(!preg_match("/^\\s*[\\[{]/",$l[$i])) continue; for($j=count($l)-1;$j>=$i&&$d===null;$j--){ $s=implode("\n",array_slice($l,$i,$j-$i+1)); $t=json_decode($s,true); if(is_array($t)){ $d=$t; break; } /* Text hinter dem JSON in derselben Zeile (z. B. Elementor-Protokoll) abschneiden. */ for($k=strlen($s)-1;$k>0;$k--){ if($s[$k]!=="]"&&$s[$k]!=="}") continue; $t=json_decode(substr($s,0,$k+1),true); if(is_array($t)){ $d=$t; break; } } } } if($argv[1]==="."){ echo json_encode($d,JSON_UNESCAPED_UNICODE); exit; } $p=explode(".",$argv[1]); foreach($p as $k){ if($k==="count"){ $d=is_array($d)?count($d):0; continue; } $d=is_array($d)?($d[$k]??null):null; } echo is_bool($d)?($d?"true":"false"):(is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):$d);' "$1"; }
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

echo "MVP 1, Stufen A und B – $(date -u '+%Y-%m-%d %H:%M UTC')"
if [ -z "$(wpj lsec status --format=json | json plugin_version)" ]; then
	echo "Abbruch: 'wp lsec' nicht verfügbar. Ist das Plugin aktiviert?"
	exit 2
fi
PREFIX=$(wpc db prefix | last_word)
SAVED=$(wpc option get lsec_settings --format=json)
wpc lsec selftest cleanup >/dev/null 2>&1

# ---------------------------------------------------------------------------
echo "A1 Schemaversion (3 seit Stufe A, 4 seit Stufe C, 5 seit Stufe D)"
STATUS=$(wpj lsec status --format=json)
check "Schemaversion installiert" "5" "$(echo "$STATUS" | json schema_version_installed)"
for col in "keyword_metrics competition" "keywords exclusion" "entities merged_into" "keywords exclusion_detail" "keywords exclusion_by" "keywords analyzed_at"; do
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

# ===========================================================================
echo "B1 Inhaltsindex"
wpc lsec settings set entity_auto_extract false >/dev/null
P1=$(wpc post create --post_type=post --post_status=publish --post_title="LSECTEST Pflasterreinigung in Hemmingen" --post_content="Die LSECTEST Musterbetrieb Flächenreinigung reinigt Pflaster und Terrassen in Hemmingen. Regelmäßige Wartung gehört dazu. Wir arbeiten mit dem Hochdruckreiniger LSECTEST Turbo." --porcelain | last_number)
P2=$(wpc post create --post_type=post --post_status=publish --post_title="LSECTEST Zweiter Beitrag" --post_content="Kurzer Text." --porcelain | last_number)
# Nur die Testbeiträge: Ein vollständiger Lauf ohne Erkennung würde die Inhalte der Website als erledigt markieren.
OUT=$(wpc lsec index run --now --ids="$P1,$P2" 2>&1)
contains "beide Testbeiträge gelesen" "Gelesen 2, neu oder geändert 2" "$OUT"
check "Beitrag im Index" "1" "$(wpc lsec index show "$P1" | grep -c '"hash"')"
OUT=$(wpc lsec index run --now --ids="$P1,$P2" 2>&1)
contains "unverändert erkannt" "Gelesen 2, neu oder geändert 0" "$OUT"
wpc post update "$P2" --post_content="Kurzer, geänderter Text." >/dev/null
check "Änderung über save_post vorgemerkt" "1" "$(wpc post meta get "$P2" _lsec_index_dirty | last_number)"
OUT=$(wpc lsec index run --now --ids="$P1,$P2" 2>&1)
contains "nur der geänderte Beitrag als geändert" "Gelesen 2, neu oder geändert 1" "$OUT"

# ---------------------------------------------------------------------------
echo "B2 Entity-Erkennung als Ablauf (KI-Attrappe, ohne Kosten)"
cat > "$TMP/antwort.json" <<'JSON'
{"entities":[
{"type":"organization","name":"LSECTEST Musterbetrieb Flächenreinigung","aliases":[],"description":"Die Firma","confidence":0.95,"evidence":[{"excerpt":"Die LSECTEST Musterbetrieb Flächenreinigung reinigt Pflaster und Terrassen in Hemmingen","location":"body"}]},
{"type":"product","name":"LSECTEST Turbo","aliases":[],"description":"Gerät","confidence":0.8,"evidence":[{"excerpt":"mit dem Hochdruckreiniger LSECTEST Turbo","location":"body"}]},
{"type":"concept","name":"LSECTEST Erfunden","aliases":[],"description":"steht nicht im Text","confidence":0.5,"evidence":[{"excerpt":"Dieser Satz steht nirgends","location":"body"}]}
],"ambiguities":[]}
JSON
R=$(wpj lsec selftest extraction "$P1" --response="$TMP/antwort.json")
check "Ablauf abgeschlossen" "succeeded" "$(echo "$R" | json status)"
check "zwei Entitäten mit Fundstelle" "2" "$(echo "$R" | json steps.extract.output.entities.count)"
check "erfundene Entität verworfen" "1" "$(echo "$R" | json steps.save.output.rejected)"
check "Kandidat gespeichert" "1" "$(wpj lsec entities list --search='LSECTEST Musterbetrieb' --min-confidence=0 --format=json | json count)"

# ---------------------------------------------------------------------------
echo "B3 Zusammenführen"
wpc lsec entities add organization "LSECTEST Musterbetrieb Flächenreinigungs-Service & Wassertechnik" --confirmed >/dev/null
wpc lsec entities add organization "LSECTEST Musterbetrieb" >/dev/null
wpc lsec entities add brand "LSECTEST Musterbetrieb Flächenreinigung & Wassertechnik" >/dev/null
# json . liest das JSON auch, wenn Elementor unter PHP 8.4 Text dahinter schreibt.
SUG=$(wpj lsec entities suggest-merges --format=json | json .)
TARGET=$(echo "$SUG" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $g){ if(str_starts_with($g["target"]["name"],"LSECTEST")){ echo $g["target"]["id"]; break; } }')
SOURCES=$(echo "$SUG" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $g){ if(str_starts_with($g["target"]["name"],"LSECTEST")){ echo implode(" ", array_column($g["candidates"],"id")); break; } }')
check "drei Schreibweisen vorgeschlagen" "3" "$(echo "$SOURCES" | wc -w | tr -d ' ')"
wpc lsec entities merge "$TARGET" $SOURCES >/dev/null
D=$(wpj lsec entities show "$TARGET" --format=json)
check "drei Schreibweisen als Alias" "3" "$(echo "$D" | json aliases.count)"
check "Fundstelle übernommen" "1" "$(echo "$D" | json mentions.count)"
wpj lsec selftest extraction "$P1" --response="$TMP/antwort.json" >/dev/null
check "erneute Erkennung findet das Ziel" "1" "$(wpj lsec entities show "$TARGET" --format=json | json mentions.count)"
check "kein neuer Kandidat für die Schreibweise" "0" "$(wpj lsec entities list --search='LSECTEST Musterbetrieb Flächenreinigung' --min-confidence=0 --status=candidate --format=json | json count)"

# ---------------------------------------------------------------------------
echo "B4 Unternehmensprofil"
wpj lsec profile show --format=json > "$TMP/profil.json"
if [ -z "$(json organization.name < "$TMP/profil.json")" ]; then
	skip "noch kein Profil angelegt (Local SEO → Unternehmen)"
else
	OUT=$(wpc lsec profile save "$TMP/profil.json" 2>&1)
	contains "unverändert gespeichert, nichts entfernt" "0 entfernt" "$OUT"
	check "Unternehmen bleibt bestätigt" "confirmed" "$(wpj lsec entities show "$(json organization.id < "$TMP/profil.json")" --format=json | json status)"
fi

# ---------------------------------------------------------------------------
echo "B5 REST-Routen der Stufe B geschützt"
OUT=$($WP "${WP_ARGS[@]}" lsec check rest 2>&1)
contains "alle Routen 403" "Routen geschützt" "$OUT"
contains "Route profile" "/lsec/v1/profile" "$OUT"
contains "Route merge" "/lsec/v1/entities/merge" "$OUT"

# ---------------------------------------------------------------------------
echo "B6 Ortsabgleich Hemmingen (Wikidata)"
if [ "$ONLINE" -eq 0 ]; then
	skip "nur mit --online"
else
	wpc lsec entities service-area --id=Q5963 >/dev/null
	OUT=$($WP "${WP_ARGS[@]}" lsec entities resolve-place Hemmingen 2>&1)
	if printf '%s' "$OUT" | grep -q "zu viele Anfragen"; then
		skip "Wikidata meldet zu viele Anfragen, später wiederholen"
	else
		contains "mit Region Hannover probable" "Status: probable" "$OUT"
		contains "Hemmingen in der Region Hannover" "Q534259" "$OUT"
	fi
fi

# ---------------------------------------------------------------------------
echo "B7 Kategorieseiten, Auswahl der Erkennung, Abbrechen"
LONG="Die LSECTEST Musterbetrieb Flächenreinigung reinigt Pflaster, Terrassen und Dächer in Hemmingen, Pattensen und Laatzen. Wir beraten vor Ort, prüfen den Untergrund, wählen das passende Verfahren und versiegeln die Flächen auf Wunsch. Für Hausverwaltungen übernehmen wir die regelmäßige Pflege von Wegen, Parkplätzen und Innenhöfen. Alle Arbeiten erledigen eigene Mitarbeiter mit eigenen Maschinen."
wpc lsec settings set entity_auto_extract true >/dev/null
wpc lsec settings set entity_extract_types category >/dev/null
P3=$(wpc post create --post_type=post --post_status=publish --post_title="LSECTEST Langer Beitrag" --post_content="$LONG" --porcelain | last_number)
T1=$(wpc term create category "LSECTEST Kategorie" --description="$LONG" --porcelain | last_number)
contains "Kategorien im Index" "Beitragskategorien (category)" "$(wpc lsec index status)"
OUT=$(wpc lsec index run --now --ids="$P3,term:$T1" 2>&1)
contains "Erkennung nur für die Kategorie, nicht für den Blogbeitrag" "Gelesen 2, neu oder geändert 2, Entity-Erkennungen gestartet 1" "$OUT"
check "Kategorie im Index" "1" "$(wpc lsec index show "term:$T1" | grep -c '"kind": "category"')"
# Feld eines Ablaufs aus der Liste: run_by <Suchfeld> <Wert> <Ausgabefeld>
run_by() { wpj lsec runs list --limit=50 --format=json | json . | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $r){ if((string)($r[$argv[1]]??"")===$argv[2]){ echo $r[$argv[3]]; break; } }' "$1" "$2" "$3"; }
RUN=$(run_by objekt "term:$T1" id)
wpc lsec runs cancel "$RUN" >/dev/null
OUT=$(wpc lsec index run --now --ids="term:$T1" 2>&1)
contains "abgebrochene Erkennung wieder aufgenommen" "Entity-Erkennungen gestartet 1" "$OUT"
check "derselbe Ablauf wieder eingeplant" "queued" "$(run_by id "$RUN" status)"
wpc lsec runs cancel "$RUN" >/dev/null
check "Erkennung abgebrochen" "cancelled" "$(run_by id "$RUN" status)"
contains "unbekannte Art abgelehnt" "Unbekannt: quatsch" "$($WP "${WP_ARGS[@]}" lsec settings set entity_extract_types page,quatsch 2>&1)"

# ---------------------------------------------------------------------------
echo "B8 Entitäten bearbeiten, löschen, weitere Vorschläge"
# Nur die ID aus „Angelegt: 12“; PHP-Hinweise anderer Plugins enthalten auch Zahlen.
E1=$(wpc lsec entities add concept "LSECTEST Alter Name" | grep -oE '(Angelegt|vorhanden): [0-9]+' | grep -oE '[0-9]+$' | head -1)
OUT=$(wpc lsec entities update "$E1" --name="LSECTEST Neuer Name" --type=service --status=confirmed --add-alias="LSECTEST Dritte Schreibweise" 2>&1)
contains "geändert" "geändert" "$OUT"
D=$(wpj lsec entities show "$E1" --format=json)
check "neuer Name" "LSECTEST Neuer Name" "$(echo "$D" | json canonical_name)"
check "Typ geändert" "service" "$(echo "$D" | json type)"
check "Status bestätigt" "confirmed" "$(echo "$D" | json status)"
check "alter Name und neue Schreibweise als Alias" "2" "$(echo "$D" | json aliases.count)"
wpc lsec entities update "$E1" --status=candidate --remove-alias="LSECTEST Dritte Schreibweise" >/dev/null
D=$(wpj lsec entities show "$E1" --format=json)
check "Status zurück auf Kandidat" "candidate" "$(echo "$D" | json status)"
check "Alias entfernt" "1" "$(echo "$D" | json aliases.count)"
wpc lsec entities add concept "LSECTEST Doppelt" >/dev/null
wpc lsec entities add service "LSECTEST Doppelt" >/dev/null
wpc lsec entities add place "LSECTEST Dorf" >/dev/null
wpc lsec entities add service_area "Region LSECTEST Dorf" >/dev/null
SUG=$(wpj lsec entities suggest-merges --format=json | json .)
contains "gleicher Name, anderer Typ vorgeschlagen" "gleicher Name, anderer Typ" "$SUG"
contains "Gebietsangabe dem Ort zugeordnet" "Gebietsangabe zum Ort" "$SUG"
OUT=$($WP "${WP_ARGS[@]}" lsec entities update "$E1" --name="LSECTEST Doppelt" --type=concept 2>&1)
contains "Namenskollision verweist aufs Zusammenführen" "zusammenführen" "$OUT"
OUT=$(wpc lsec entities delete "$E1" --yes 2>&1)
contains "gelöscht" "Gelöscht: 1 Entität" "$OUT"
contains "nach dem Löschen nicht mehr vorhanden" "nicht gefunden" "$($WP "${WP_ARGS[@]}" lsec entities show "$E1" 2>&1)"
ORG=$(json organization.id < "$TMP/profil.json")
if [ -n "$ORG" ]; then
	contains "Profil-Eintrag nur im Profil umbenennen" "Unternehmensprofil" "$($WP "${WP_ARGS[@]}" lsec entities update "$ORG" --name="LSECTEST Umbenannt" 2>&1)"
	contains "Profil-Eintrag nicht löschbar" "Unternehmensprofil" "$($WP "${WP_ARGS[@]}" lsec entities delete "$ORG" --yes 2>&1)"
fi
OUT=$($WP "${WP_ARGS[@]}" lsec check rest 2>&1)
contains "neue Routen geschützt" "Routen geschützt" "$OUT"

cleanup_b >/dev/null 2>&1

# ===========================================================================
echo "C1 Ausschlüsse (KI-Attrappe, ohne Wikidata)"
printf 'Keyword;Volume\nlsectest konkurrenz flächenreinigung;30\nflächenreinigung lsectest fremdort;20\nlsectest fremdmarke hochdruckreiniger;10\nlsectest pflasterreinigung;40\n' > "$TMP/c.csv"
wpc lsec keywords import "$TMP/c.csv" --source=csv:lsec_c >/dev/null
wpc lsec entities add competitor "LSECTEST Konkurrenz" >/dev/null
ORT=$(wpc lsec entities add place "LSECTEST Fremdort" | grep -oE '(Angelegt|vorhanden): [0-9]+' | grep -oE '[0-9]+$' | head -1)
wpc lsec entities update "$ORT" --status=rejected >/dev/null
cat > "$TMP/analyse.json" <<'JSON'
{"keywords":[
{"keyword":"flächenreinigung lsectest fremdort","places":["lsectest fremdort"],"names":[]},
{"keyword":"lsectest fremdmarke hochdruckreiniger","places":[],"names":["lsectest fremdmarke"]},
{"keyword":"lsectest pflasterreinigung","places":["erfundener ort"],"names":[]}
]}
JSON
# Feld eines Keywords aus der Tabelle: kw <Keyword> <Feld>
kw() { wpj lsec keywords table --status=all --search=lsectest --limit=50 --format=json | json . | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $r){ if($r["keyword"]===$argv[1]){ echo $r[$argv[2]]; break; } }' "$1" "$2"; }
IDS=$(wpj lsec keywords table --status=all --search=lsectest --limit=50 --format=json | json . | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo implode(",", array_column((array)$d,"id"));')
OUT=$(wpc lsec keywords sync --now --no-gsc --no-volumes --offline --ids="$IDS" --stub-analysis="$TMP/analyse.json" 2>&1)
contains "Abgleich abgeschlossen" "succeeded" "$OUT"
contains "Ort ohne wörtliche Fundstelle verworfen" '"found":2' "$OUT"
check "Wettbewerber ausgeschlossen" "competitor: LSECTEST Konkurrenz" "$(kw 'lsectest konkurrenz flächenreinigung' ausschluss)"
check "abgelehnter Ort als fremder Ort" "foreign_place: lsectest fremdort" "$(kw 'flächenreinigung lsectest fremdort' ausschluss)"
check "fremde Marke ausgeschlossen" "brand: lsectest fremdmarke" "$(kw 'lsectest fremdmarke hochdruckreiniger' ausschluss)"
check "eigenes Thema bleibt" "" "$(kw 'lsectest pflasterreinigung' ausschluss)"

# ---------------------------------------------------------------------------
echo "C2 Entscheidungen von Hand"
K1=$(kw 'lsectest pflasterreinigung' id)
K2=$(kw 'lsectest konkurrenz flächenreinigung' id)
wpc lsec keywords decide manual "$K1" >/dev/null
wpc lsec keywords decide include "$K2" >/dev/null
wpc lsec keywords sync --now --no-gsc --no-volumes --offline --ids="$IDS" --stub-analysis="$TMP/analyse.json" >/dev/null 2>&1
check "von Hand ausgeschlossen" "manual (von Hand)" "$(kw 'lsectest pflasterreinigung' ausschluss)"
check "von Hand aufgenommen bleibt nach dem Abgleich" "" "$(kw 'lsectest konkurrenz flächenreinigung' ausschluss)"
wpc lsec keywords decide auto "$K2" >/dev/null
wpc lsec keywords sync --now --no-gsc --no-volumes --offline --ids="$IDS" --stub-analysis="$TMP/analyse.json" >/dev/null 2>&1
check "zurück an den Filter" "competitor: LSECTEST Konkurrenz" "$(kw 'lsectest konkurrenz flächenreinigung' ausschluss)"

# ---------------------------------------------------------------------------
echo "C3 REST-Routen der Stufe C geschützt"
OUT=$($WP "${WP_ARGS[@]}" lsec check rest 2>&1)
contains "alle Routen 403" "Routen geschützt" "$OUT"
contains "Route Import" "/lsec/v1/keywords/import" "$OUT"
contains "Route Ausschluss" "/lsec/v1/keywords/1/exclusion" "$OUT"

# ---------------------------------------------------------------------------
echo "C4 Täglicher Abgleich"
if [ "$ONLINE" -eq 0 ]; then
	skip "nur mit --online"
else
	LAST=$(wpj lsec runs list --limit=100 --format=json | json . | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $r){ if($r["typ"]==="keyword_sync" && $r["status"]==="succeeded" && strtotime($r["stand"]." UTC") > time()-2*86400){ echo $r["id"]; break; } }')
	if [ -z "$LAST" ]; then
		skip "noch kein erfolgreicher Abgleich in den letzten zwei Tagen (läuft einmal am Tag über wp lsec cron)"
	else
		check "Abgleich der letzten zwei Tage erfolgreich" "1" "$( [ -n "$LAST" ] && echo 1 || echo 0 )"
		contains "Search Console abgerufen" "fetched" "$(wpj lsec runs show "$LAST" 2>&1)"
	fi
fi
cleanup_b >/dev/null 2>&1

# ===========================================================================
echo "D1 Faktenprüfung (Eltze/Dollbergen) und Überschneidung, KI-Attrappe"
printf 'Keyword;Volume\nlsectest pflasterreinigung eltze;30\nlsectest terrassenreinigung;50\nlsectest kochrezepte;10\n' > "$TMP/d.csv"
wpc lsec keywords import "$TMP/d.csv" --source=csv:lsec_c >/dev/null
DKW=$(wpj lsec keywords table --status=all --search=lsectest --limit=50 --format=json | json .)
did() { echo "$DKW" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $r){ if($r["keyword"]===$argv[1]){ echo $r["id"]; break; } }' "$1"; }
K_NEW=$(did 'lsectest pflasterreinigung eltze')
K_RANK=$(did 'lsectest terrassenreinigung')
K_OFF=$(did 'lsectest kochrezepte')
HOME_URL=$(wpc option get home | tail -1)
# Eigene Seite rankt für „lsectest terrassenreinigung“ (Search Console).
wpc db query "INSERT INTO ${PREFIX}lsec_keyword_metrics (keyword_id, source, period_start, period_end, impressions, clicks, ctr, position, page_url, created_at) VALUES ($K_RANK, 'gsc', DATE_SUB(CURDATE(), INTERVAL 31 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 40, 1, 0.025, 6.5, '$HOME_URL/lsectest-terrassen/', UTC_TIMESTAMP())" >/dev/null
# Quelle liegt schon im Zwischenspeicher (kein Abruf im Prüfskript).
SRC="https://lsectest.invalid/eltze"
TXT="Ortsteile der Gemeinde Uetze
LSECTEST Eltze wurde im Jahr 1226 erstmals urkundlich erwähnt. Der Ort liegt an der Fuhse.
LSECTEST Dollbergen ist für seine Raffinerie bekannt."
wpc db query "INSERT INTO ${PREFIX}lsec_sources (url, url_hash, title, source_type, fetched_at, content_hash, content, reliability, created_at) VALUES ('$SRC', SHA2('$SRC', 256), 'Eltze', 'ai_search', UTC_TIMESTAMP(), SHA2('$TXT', 256), '$TXT', 'unknown', UTC_TIMESTAMP())" >/dev/null
cat > "$TMP/recherche.json" <<JSON
{"research":"LSECTEST Eltze wurde im Jahr 1226 erstmals urkundlich erwähnt.","sources":[{"url":"$SRC","title":"Eltze"}],
"result":{"topics":[
{"title":"LSECTEST Pflasterreinigung in Eltze","intent":"local","format":"local_landing","region":"LSECTEST Eltze","primary_keyword_ids":[$K_NEW],"secondary_keyword_ids":[],"primary_entity_ids":[],"secondary_entity_ids":[],"rationale":"LSECTEST","facts":[
{"subject":"LSECTEST Eltze","subject_type":"place","property":"first_mentioned","value":"1226","source_url":"$SRC","quote":"LSECTEST Eltze wurde im Jahr 1226 erstmals urkundlich erwähnt"},
{"subject":"LSECTEST Dollbergen","subject_type":"place","property":"first_mentioned","value":"1226","source_url":"$SRC","quote":"LSECTEST Eltze wurde im Jahr 1226 erstmals urkundlich erwähnt"},
{"subject":"LSECTEST Eltze","subject_type":"place","property":"population","value":"999","source_url":"$SRC","quote":"LSECTEST Eltze hat 999 Einwohner"}],"insufficient_data":false},
{"title":"LSECTEST Terrassenreinigung","intent":"commercial","format":"article","region":"","primary_keyword_ids":[$K_RANK],"secondary_keyword_ids":[],"primary_entity_ids":[],"secondary_entity_ids":[],"rationale":"LSECTEST","facts":[],"insufficient_data":false}],
"excluded_keywords":[{"keyword_id":$K_OFF,"reason":"off_topic","note":"LSECTEST kein Bezug"}]}}
JSON
OUT=$(wpc lsec topics research --service=lsectest --stub="$TMP/recherche.json" --offline --now 2>&1)
contains "Recherche abgeschlossen" "succeeded" "$OUT"
contains "Tatsachen geprüft" "3 Tatsachen: 1 geprüft, 1 unklar, 1 verworfen" "$OUT"
TOPICS=$(wpj lsec topics list --status=proposed --format=json | json .)
T1=$(echo "$TOPICS" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $r){ if($r["thema"]==="LSECTEST Pflasterreinigung in Eltze"){ echo $r["id"]; break; } }')
FACTS=$(wpj lsec topics show "$T1" --format=json | json facts)
fact() { echo "$FACTS" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $r){ if($r["entity"]===$argv[1] && $r["property"]===$argv[2]){ echo $r["status"]; break; } }' "$1" "$2"; }
check "Eltze 1226 geprüft" "verified" "$(fact 'LSECTEST Eltze' first_mentioned)"
check "Dollbergen 1226 nicht geprüft (Bezug unklar)" "disputed" "$(fact 'LSECTEST Dollbergen' first_mentioned)"
check "Zitat nicht in der Quelle verworfen" "rejected" "$(fact 'LSECTEST Eltze' population)"
check "neues Thema vorgeschlagen" "1" "$( [ -n "$T1" ] && echo 1 || echo 0 )"
D=$(wpj lsec topics list --status=discarded --format=json | json .)
contains "rankende Seite als Überschneidung (bestehende Seite verbessern)" "LSECTEST Terrassenreinigung" "$D"
T2=$(echo "$D" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $r){ if($r["thema"]==="LSECTEST Terrassenreinigung"){ echo $r["id"]; break; } }')
contains "Überschneidung nennt die Seite" "lsectest-terrassen" "$(wpj lsec topics show "$T2" 2>&1)"

# ---------------------------------------------------------------------------
echo "D2 Freigabe, off_topic, REST-Routen"
wpc lsec topics decide "$T1" approved >/dev/null
check "Thema freigegeben" "approved" "$(wpj lsec topics show "$T1" --format=json | json status)"
check "Keyword ohne Bezug ausgeschlossen" "off_topic: LSECTEST kein Bezug" "$(kw 'lsectest kochrezepte' ausschluss)"
IDS=$(wpj lsec keywords table --status=all --search=lsectest --limit=50 --format=json | json . | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo implode(",", array_column((array)$d,"id"));')
cat > "$TMP/leer.json" <<'JSON'
{"keywords":[]}
JSON
wpc lsec keywords sync --now --no-gsc --no-volumes --offline --ids="$IDS" --stub-analysis="$TMP/leer.json" >/dev/null 2>&1
check "off_topic bleibt nach dem Keyword-Abgleich" "off_topic: LSECTEST kein Bezug" "$(kw 'lsectest kochrezepte' ausschluss)"
OUT=$($WP "${WP_ARGS[@]}" lsec check rest 2>&1)
contains "alle Routen 403" "Routen geschützt" "$OUT"
contains "Route Themen" "/lsec/v1/topics" "$OUT"
cleanup_b >/dev/null 2>&1

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen, $SKIP übersprungen"
cat <<'EOF'

Von Hand im Backend:
  - Dashboard: KI-Kosten, Textfocus-Credits, Cronjob, Hinweise, Karte „Inhalte und Entitäten“
  - Unternehmen: Profil und Servicegebiet eintragen, speichern
  - Entitäten: Vorschläge zum Zusammenführen, Bestätigen, Ablehnen, Details mit Fundstellen
  - Mit Kosten: Index starten (Dashboard oder wp lsec index run); die Entity-Erkennung läuft danach über den Cronjob
EOF
[ "$FAIL" -eq 0 ]
