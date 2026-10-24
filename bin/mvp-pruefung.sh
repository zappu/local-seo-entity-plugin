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
#   Stufe D: D1 Faktenprüfung Eltze/Dollbergen, sinngemäße Zitate und Überschneidung (KI-Attrappe, Quelle aus dem Zwischenspeicher)
#            D2 Freigabe, Ausschluss off_topic, REST-Routen
#   Stufe E: E1 Entwurf zu einem freigegebenen Thema (KI-Attrappe): Blöcke, SEO-Angaben, Prüfbericht bestanden
#            E2 Entwurf mit unbelegter Zahl wird blocked, der Entwurf entsteht trotzdem · E3 Tageslimit des Cronjobs
#            E4 Style Mimic von Hand, REST-Routen · E5 doppelte Themen werden zusammengeführt (0.7.1)
#            E6 Ablehnen bricht laufende Entwürfe ab (0.7.8) · E7 vorhandenen Entwurf neu prüfen (0.7.9), Bericht mit Tatsachen (0.7.10)
#            E8 automatische Nachbesserung · E9 Ortsfakten werden recherchiert, einmal je Ort (0.7.13)
#   Stufe F: F1 Einrichtungsassistent: Stand der sechs Schritte, unbekannter Schritt, REST-Routen (0.8.0)
#            F2 Sammelauftrag mit Kostenschätzung, Seite „Abläufe“ (REST) (0.9.0)
#            F3 Bericht: Inhalte, Entitäten, Hinweise „bestehende Seite verbessern“ (Search Console Position 4–20), CSV (0.9.1)
#            F4 Sammelentscheidung über Entitäten (0.9.2)
#            F5 Beitragsbilder: Beschreibung, Wahl aus der Mediathek, KI-Bild speichern; Sammelausschluss Keywords (0.10.0)
#            F6 Kategorie für Ortsseiten (0.10.1)
#            F7 Empfehlung bei doppelten Themen (0.10.2)
#            F3 ergänzt: Hinweise je Seite, Marken- und Navigationssuchen (0.10.5)
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
	wpc db query "DELETE q FROM ${PREFIX}lsec_quality_reports q JOIN ${PREFIX}lsec_runs r ON r.id = q.run_id JOIN ${PREFIX}lsec_topics t ON t.id = r.subject_id AND r.subject_type = 'topic' WHERE t.title LIKE 'LSECTEST%'"
	wpc db query "DELETE b FROM ${PREFIX}lsec_briefs b JOIN ${PREFIX}lsec_topics t ON t.id = b.topic_id WHERE t.title LIKE 'LSECTEST%'"
	wpc db query "UPDATE ${PREFIX}lsec_runs r JOIN ${PREFIX}lsec_topics t ON t.id = r.subject_id AND r.subject_type = 'topic' SET r.status = 'cancelled' WHERE t.title LIKE 'LSECTEST%' AND r.status IN ('queued', 'running', 'waiting')"
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
check "Cronjob-Befehl" "1" "$(echo "$D" | json cron.args | grep -c 'lsec cron')"
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
# Eine allgemeine Seite rankt für das Keyword des Ortsthemas: nur teilweise Überschneidung (Nachtrag 22.10.).
wpc db query "INSERT INTO ${PREFIX}lsec_keyword_metrics (keyword_id, source, period_start, period_end, impressions, clicks, ctr, position, page_url, created_at) VALUES ($K_NEW, 'gsc', DATE_SUB(CURDATE(), INTERVAL 31 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 20, 0, 0, 12.0, '$HOME_URL/lsectest-pflasterreinigung/', UTC_TIMESTAMP())" >/dev/null
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
{"subject":"LSECTEST Eltze","subject_type":"place","property":"river","value":"Fuhse","source_url":"$SRC","quote":"LSECTEST Eltze liegt an dem Fluss Fuhse"},
{"subject":"LSECTEST Eltze","subject_type":"place","property":"population","value":"999","source_url":"$SRC","quote":"LSECTEST Eltze hat 999 Einwohner"},
{"subject":"LSECTEST Firma","subject_type":"organization","property":"address","value":"Teststraße 1","source_url":"$HOME_URL/lsectest-kontakt/","quote":"LSECTEST Firma, Teststraße 1"}],"insufficient_data":false},
{"title":"LSECTEST Terrassenreinigung","intent":"commercial","format":"article","region":"","primary_keyword_ids":[$K_RANK],"secondary_keyword_ids":[],"primary_entity_ids":[],"secondary_entity_ids":[],"rationale":"LSECTEST","facts":[],"insufficient_data":false}],
"excluded_keywords":[{"keyword_id":$K_OFF,"reason":"off_topic","note":"LSECTEST kein Bezug"}]}}
JSON
OUT=$(wpc lsec topics research --service=lsectest --stub="$TMP/recherche.json" --offline --now 2>&1)
contains "Recherche abgeschlossen" "succeeded" "$OUT"
contains "Tatsachen geprüft" "4 Tatsachen: 2 geprüft, 1 unklar, 1 verworfen" "$OUT"
contains "Tatsache von der eigenen Website nicht verwendet" "Quelle ist die eigene Website" "$OUT"
TOPICS=$(wpj lsec topics list --status=proposed --format=json | json .)
T1=$(echo "$TOPICS" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $r){ if($r["thema"]==="LSECTEST Pflasterreinigung in Eltze"){ echo $r["id"]; break; } }')
FACTS=$(wpj lsec topics show "$T1" --format=json | json facts)
fact() { echo "$FACTS" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $r){ if($r["entity"]===$argv[1] && $r["property"]===$argv[2]){ echo $r["status"]; break; } }' "$1" "$2"; }
check "Eltze 1226 geprüft" "verified" "$(fact 'LSECTEST Eltze' first_mentioned)"
check "Dollbergen 1226 nicht geprüft (Bezug unklar)" "disputed" "$(fact 'LSECTEST Dollbergen' first_mentioned)"
check "Zitat sinngemäß, Wert und Ort im Absatz: geprüft" "verified" "$(fact 'LSECTEST Eltze' river)"
check "Zitat und Wert nicht in der Quelle: verworfen" "rejected" "$(fact 'LSECTEST Eltze' population)"
check "neues Thema trotz allgemeiner Seite vorgeschlagen" "1" "$( [ -n "$T1" ] && echo 1 || echo 0 )"
check "allgemeine Seite nur teilweise Überschneidung" "partial" "$(wpj lsec topics show "$T1" --format=json | json overlap.level)"
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

# ===========================================================================
echo "E1 Entwurf zu einem freigegebenen Thema (KI-Attrappe)"
wpc lsec settings set draft_lengths '{"local_landing":[20,3000]}' >/dev/null
wpc lsec settings set production_auto false >/dev/null
TF=$(wpj lsec topics show "$T1" --format=json | json facts)
claim() { echo "$TF" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $r){ if($r["entity"]===$argv[1] && $r["property"]===$argv[2]){ echo $r["id"]; break; } }' "$1" "$2"; }
C_YEAR=$(claim 'LSECTEST Eltze' first_mentioned)
C_RIVER=$(claim 'LSECTEST Eltze' river)
draft_stub() {
	local extra="$1"
	cat <<JSON
{"brief":{"working_title":"LSECTEST Pflasterreinigung in Eltze","search_intent":"lokal","audience":"Eigentümer","outline":[{"heading":"Pflaster in Eltze","level":2,"purpose":"Einstieg","entity_ids":[]}],"required_facts":[{"claim_id":$C_YEAR,"use":"Ortsbezug"},{"claim_id":$C_RIVER,"use":"Lage"}],"facts_to_avoid":["Preise"],"internal_link_targets":[],"elements":["faq"],"length":{"min_words":20,"max_words":3000},"cta":"Rufen Sie uns an."},
"draft":{"title":"LSECTEST Pflasterreinigung in LSECTEST Eltze","content":[
{"type":"paragraph","level":0,"text":"LSECTEST Eltze wurde im Jahr 1226 erstmals urkundlich erwähnt und liegt an der Fuhse. Viele Höfe haben altes <strong>Pflaster</strong>.$extra","ordered":false,"items":[],"head":[],"rows":[],"cite":"","summary":""},
{"type":"heading","level":2,"text":"So reinigen wir Pflaster","ordered":false,"items":[],"head":[],"rows":[],"cite":"","summary":""},
{"type":"list","level":0,"text":"","ordered":false,"items":["Moos entfernen","Fugen säubern"],"head":[],"rows":[],"cite":"","summary":""},
{"type":"details","level":0,"text":"Ja, auch Naturstein.","ordered":false,"items":[],"head":[],"rows":[],"cite":"","summary":"Reinigen Sie auch Naturstein?"},
{"type":"paragraph","level":0,"text":"Nehmen Sie Kontakt mit uns auf.","ordered":false,"items":[],"head":[],"rows":[],"cite":"","summary":""}],
"excerpt":"Pflasterreinigung in LSECTEST Eltze.","used_entity_ids":[],"used_claim_ids":[$C_YEAR,$C_RIVER],"unsupported_statements":[],"internal_links":[],
"seo":{"title":"LSECTEST Pflasterreinigung in Eltze – sauber","description":"Pflasterreinigung in LSECTEST Eltze: Moos und Schmutz schonend entfernen. Wir reinigen Pflaster, Einfahrten und Höfe in Eltze und Umgebung.","focus_keyword":"lsectest pflasterreinigung eltze","secondary_keywords":[],"slug":""},
"taxonomy":{"category_ids":[],"new_category_suggestions":[],"tags":["LSECTEST"]}},
"review":{"scores":{"intent":0.9,"local_relevance":0.9,"entity_integration":0.8,"value":0.8,"readability":0.9,"style_match":0.8},"blocking_issues":[],"warnings":[]}}
JSON
}
draft_stub "" > "$TMP/e1.json"
OUT=$(wpc lsec production start "$T1" --stub="$TMP/e1.json" --now 2>&1)
contains "Prüfung bestanden" "Prüfung: bestanden" "$OUT"
R1=$(printf '%s' "$OUT" | sed -n 's/^Ablauf \([0-9]*\) .*/\1/p' | head -1)
REP=$(wpj lsec production report "$R1" --format=json)
check "Ablauf fertig" "succeeded" "$(printf '%s' "$REP" | json status)"
P1=$(printf '%s' "$REP" | json post_id)
check "Beitrag ist ein Entwurf" "draft" "$(wpc post get "$P1" --field=post_status)"
contains "Inhalt als Blöcke" "<!-- wp:details -->" "$(wpc post get "$P1" --field=post_content)"
TOPIC_E=$(wpj lsec topics show "$T1" --format=json)
check "Thema auf produced mit Beitrag" "produced $P1" "$(printf '%s' "$TOPIC_E" | json status) $(printf '%s' "$TOPIC_E" | json post_id)"
check "Prüfbericht am Beitrag" "1" "$(wpc post meta get "$P1" _lsec_quality_passed)"
# Mit Rank Math als rank_math_focus_keyword, ohne als Vorschlag in _lsec_seo_suggestion (9.2).
contains "Fokus-Keyword gespeichert (Rank Math oder Vorschlag)" "lsectest pflasterreinigung eltze" "$(wpj post meta get "$P1" rank_math_focus_keyword) $(wpj post meta get "$P1" _lsec_seo_suggestion)"

echo "E2 Entwurf mit unbelegter Zahl wird blocked"
draft_stub " Wir reinigen seit 25 Jahren Pflaster." > "$TMP/e2.json"
OUT=$(wpc lsec production start "$T1" --again --stub="$TMP/e2.json" --now 2>&1)
contains "Zahl ohne Beleg erkannt" "UNSUPPORTED_NUMBER – ohne Beleg: 25" "$OUT"
R2=$(printf '%s' "$OUT" | sed -n 's/^Ablauf \([0-9]*\) .*/\1/p' | head -1)
check "Ablauf blocked" "blocked" "$(wpj lsec production report "$R2" --format=json | json status)"
P2=$(wpj lsec production report "$R2" --format=json | json post_id)
check "Entwurf trotzdem angelegt, als nicht bestanden markiert" "draft 0" "$(echo "$(wpc post get "$P2" --field=post_status) $(wpc post meta get "$P2" _lsec_quality_passed)")"
check "neuer Entwurf, alter bleibt" "1" "$( [ -n "$P2" ] && [ "$P2" != "$P1" ] && echo 1 || echo 0 )"

echo "E3 Tageslimit des Cronjobs"
wpc lsec topics decide "$T1" approved >/dev/null
contains "ausgeschaltet: nichts gestartet" "Kein Entwurf gestartet" "$(wpc lsec production schedule)"
TODAY=$(wpc lsec production list | sed -n 's/^Heute gestartet: \([0-9]*\).*/\1/p')
if [ "${TODAY:-0}" -ge 20 ]; then
	# drafts_per_day erlaubt höchstens 20; auf Testsystemen mit vielen Läufen am selben Tag nicht prüfbar.
	skip "Tageslimit (heute schon $TODAY Entwürfe gestartet)"
else
	wpc lsec settings set drafts_per_day "$TODAY" >/dev/null
	wpc lsec settings set production_auto true >/dev/null
	contains "Tageslimit erreicht: nichts gestartet" "Kein Entwurf gestartet" "$(wpc lsec production schedule)"
	wpc lsec settings set drafts_per_day "$((TODAY + 1))" >/dev/null
	OUT=$(wpc lsec production schedule)
	contains "unter dem Limit: Entwurf gestartet" "Gestartet: Abläufe" "$OUT"
	printf '%s\n' "$OUT" | grep -q "Gestartet" || printf '%s\n' "$OUT" | sed 's/^/    | /'
	R3=$(printf '%s' "$OUT" | sed -n 's/^Gestartet: Abläufe \([0-9]*\).*/\1/p')
	[ -n "$R3" ] && wpc lsec runs cancel "$R3" >/dev/null 2>&1
	contains "danach Limit erreicht" "Kein Entwurf gestartet" "$(wpc lsec production schedule)"
fi

echo "E4 Style Mimic von Hand, REST-Routen"
printf '{"profile":{"tone":"LSECTEST sachlich","address":"Sie","rules":["Keine Preise nennen"]}}' > "$TMP/style.json"
contains "Profil gespeichert" "gespeichert und aktiv" "$(wpc lsec style set --file="$TMP/style.json" 2>&1)"
contains "Profil aktiv mit Regel" "Regel: Keine Preise nennen" "$(wpc lsec style show)"
OUT=$($WP "${WP_ARGS[@]}" lsec check rest 2>&1)
contains "alle Routen 403" "Routen geschützt" "$OUT"
contains "Route Entwurf starten" "/lsec/v1/topics/1/produce" "$OUT"
contains "Route Style Mimic" "/lsec/v1/style" "$OUT"
echo "E5 Doppelte Themen werden zusammengeführt (0.7.1)"
cat > "$TMP/dublette.json" <<JSON
{"research":"LSECTEST","sources":[],"result":{"topics":[
{"title":"LSECTEST Pflasterreinigung in LSECTEST Eltze: Firma in der Nähe","intent":"local","format":"local_landing","region":"LSECTEST Eltze","primary_keyword_ids":[$K_NEW],"secondary_keyword_ids":[],"primary_entity_ids":[],"secondary_entity_ids":[],"rationale":"LSECTEST","facts":[],"insufficient_data":false}],
"excluded_keywords":[]}}
JSON
OUT=$(wpc lsec topics research --service=lsectest2 --stub="$TMP/dublette.json" --offline --now 2>&1)
contains "Dublette mit dem freigegebenen Thema zusammengeführt" "zusammengeführt mit #$T1" "$OUT"
check "kein neues Thema angelegt" "0" "$(wpj lsec topics list --format=json | json . | php -r '$d=json_decode(stream_get_contents(STDIN),true); $n=0; foreach((array)$d as $r){ if(str_contains($r["thema"],"Firma in der Nähe") && str_starts_with($r["thema"],"LSECTEST")) $n++; } echo $n;')"
check "keine Dubletten unter den Testthemen" "0" "$(wpc lsec topics duplicates | grep -c LSECTEST)"

echo "E6 Ablehnen bricht laufende Entwürfe ab, Bericht über die Nummer des Entwurfs (0.7.8)"
contains "Bericht über die Nummer des Entwurfs" "Ablauf $R1 " "$(wpc lsec production report "$P1")"
wpc lsec topics decide "$T1" approved >/dev/null
OUT=$(wpc lsec production start "$T1" --again --allow-duplicate --stub="$TMP/e1.json" 2>&1)
R6=$(printf '%s' "$OUT" | sed -n 's/^Success: Ablauf \([0-9]*\) eingeplant.*/\1/p')
contains "Ablehnen bricht den Entwurf ab" "Laufende Entwürfe abgebrochen: Abläufe $R6" "$(wpc lsec topics decide "$T1" rejected)"
check "Ablauf abgebrochen" "cancelled" "$(wpj lsec production report "$R6" --format=json | json status)"

echo "E7 Vorhandenen Entwurf neu prüfen (0.7.9)"
OUT=$(wpc lsec production review "$P2" --stub="$TMP/e1.json" 2>&1)
contains "neu geprüft über die Nummer des Entwurfs" "Entwurf $P2 (Ablauf $R2) neu geprüft" "$OUT"
printf '%s\n' "$OUT" | grep -q "neu geprüft" || printf '%s\n' "$OUT" | head -8 | sed 's/^/    | /'
contains "bisheriges Ergebnis angezeigt" "Bisher: blockiert" "$OUT"
contains "Regelprüfung findet die Zahl wieder" "UNSUPPORTED_NUMBER – ohne Beleg: 25" "$OUT"
contains "bestandener Entwurf bleibt bestanden" "Prüfung: bestanden" "$(wpc lsec production review "$P1" --stub="$TMP/e1.json" 2>&1)"
contains "Bericht nennt Format und Tatsachen (0.7.10)" "Format local_landing · Ort LSECTEST Eltze · Geprüfte Tatsachen:" "$(wpc lsec production report "$P1")"
check "gespeichertes Prüfergebnis unverändert" "0 blocked" "$(wpc post meta get "$P2" _lsec_quality_passed) $(wpj lsec production report "$R2" --format=json | json status)"

echo "E8 Automatische Nachbesserung (0.7.13)"
contains "Ortsrecherche übersprungen, Tatsachen zum Ort vorhanden" "Tatsachen zu LSECTEST Eltze vorhanden" "$(wpc lsec production report "$P1")"
contains "ohne Nachbesserung im Prüfskript blockiert" "Nachbesserung: Prüfskript ohne Nachbesserung" "$(wpc lsec production report "$R2")"
php -r '$d=json_decode(file_get_contents($argv[1]),true); $d["revision"]=["changes"=>[["find"=>"Wir reinigen seit 25 Jahren Pflaster.","replace"=>""]]]; file_put_contents($argv[2], json_encode($d, JSON_UNESCAPED_UNICODE));' "$TMP/e2.json" "$TMP/e8.json"
wpc lsec topics decide "$T1" approved >/dev/null
OUT=$(wpc lsec production start "$T1" --again --allow-duplicate --stub="$TMP/e8.json" --now 2>&1)
contains "nachgebessert" "Nachgebessert (vorher blockiert: UNSUPPORTED_NUMBER), 1 Stellen" "$OUT"
contains "danach bestanden" "Prüfung: bestanden" "$OUT"
R8=$(printf '%s' "$OUT" | sed -n 's/^Ablauf \([0-9]*\) .*/\1/p' | head -1)
P8=$(wpj lsec production report "$R8" --format=json | json post_id)
check "Ablauf fertig" "succeeded" "$(wpj lsec production report "$R8" --format=json | json status)"
check "Zahl aus dem Beitrag entfernt" "0" "$(wpc post get "$P8" --field=post_content | grep -c '25 Jahren')"
check "Beitrag als bestanden markiert" "1" "$(wpc post meta get "$P8" _lsec_quality_passed)"

echo "E9 Ortsfakten werden recherchiert, einmal je Ort (0.7.13)"
SRC9="https://lsectest.invalid/neudorf"
TXT9="LSECTEST Neudorf liegt an der Autobahn A 2. LSECTEST Neudorf hat einen Bahnhof an der Strecke nach Hannover."
wpc db query "INSERT INTO ${PREFIX}lsec_sources (url, url_hash, title, source_type, fetched_at, content_hash, content, reliability, created_at) VALUES ('$SRC9', SHA2('$SRC9', 256), 'Neudorf', 'ai_search', UTC_TIMESTAMP(), SHA2('$TXT9', 256), '$TXT9', 'unknown', UTC_TIMESTAMP())" >/dev/null
wpc lsec entities add place "LSECTEST Neudorf" >/dev/null 2>&1
wpc eval '$o=(array)get_option("lsec_place_research",array()); unset($o["lsectest neudorf"]); update_option("lsec_place_research",$o,false);' >/dev/null
cat > "$TMP/neudorf.json" <<JSON
{"research":"LSECTEST","sources":[],"result":{"topics":[
{"title":"LSECTEST Fugenreinigung in LSECTEST Neudorf","intent":"local","format":"local_landing","region":"LSECTEST Neudorf","primary_keyword_ids":[$K_NEW],"secondary_keyword_ids":[],"primary_entity_ids":[],"secondary_entity_ids":[],"rationale":"LSECTEST","facts":[],"insufficient_data":false}],
"excluded_keywords":[]}}
JSON
wpc lsec topics research --service=lsectest3 --region="LSECTEST Neudorf" --stub="$TMP/neudorf.json" --offline --now --force >/dev/null 2>&1
T9=$(wpj lsec topics list --format=json | json . | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach((array)$d as $r){ if($r["thema"]==="LSECTEST Fugenreinigung in LSECTEST Neudorf"){ echo $r["id"]; break; } }')
if [ -z "$T9" ]; then
	skip "Thema für LSECTEST Neudorf nicht angelegt"
else
	wpc lsec topics decide "$T9" approved >/dev/null
	php -r '$d=json_decode(file_get_contents($argv[1]),true); $d["place"]=["research"=>"LSECTEST Neudorf liegt an der A 2 und hat einen Bahnhof.","sources"=>[["url"=>$argv[3],"title"=>"Neudorf"]],"result"=>["facts"=>[["property"=>"autobahn","value"=>"A 2","source_url"=>$argv[3],"quote"=>"LSECTEST Neudorf liegt an der Autobahn A 2."],["property"=>"bahnhof","value"=>"Bahnhof an der Strecke nach Hannover","source_url"=>$argv[3],"quote"=>"LSECTEST Neudorf hat einen Bahnhof an der Strecke nach Hannover."]]]]; file_put_contents($argv[2], json_encode($d, JSON_UNESCAPED_UNICODE));' "$TMP/e1.json" "$TMP/e9.json" "$SRC9"
	OUT=$(wpc lsec production start "$T9" --allow-duplicate --stub="$TMP/e9.json" --now 2>&1)
	contains "Ortsfakten recherchiert und geprüft" "Ortsfakten recherchiert: 2 geprüft" "$OUT"
	printf '%s\n' "$OUT" | grep -q "Ortsfakten recherchiert" || printf '%s\n' "$OUT" | sed 's/^/    | /'
	OUT=$(wpc lsec production start "$T9" --again --allow-duplicate --stub="$TMP/e9.json" --now 2>&1)
	contains "zweiter Entwurf nutzt die gespeicherten Ortsfakten" "Ortsrecherche: 2 Tatsachen zu LSECTEST Neudorf vorhanden" "$OUT"
fi

echo "F1 Einrichtungsassistent (0.8.0)"
OUT=$(wpc lsec setup 2>&1)
contains "Stand der Einrichtung" "Schritten erledigt" "$OUT"
contains "Schritt Technik" "1. Technik prüfen" "$OUT"
contains "Schritt Entscheidungen" "6. Entscheidungen" "$OUT"
contains "KI-Einstellungen vor Website lesen (0.8.1)" "2. KI-Einstellungen" "$OUT"
check "sechs Schritte als JSON" "6" "$(wpj lsec setup --format=json | json total)"
contains "unbekannter Schritt abgelehnt" "Unbekannter Schritt" "$($WP "${WP_ARGS[@]}" lsec setup unbekannt 2>&1)"
OUT=$($WP "${WP_ARGS[@]}" lsec check rest 2>&1)
contains "Route Einrichtung" "/lsec/v1/setup" "$OUT"

echo "F2 Sammelauftrag und Seite „Abläufe“ (0.9.0)"
wpc lsec topics decide "$T1" approved >/dev/null
OUT=$(wpc lsec production batch "$T1" 999999 2>&1)
contains "Schätzung ohne Start" "Starten mit --yes" "$OUT"
contains "freigegebenes Thema wird geschrieben" "+ Thema $T1" "$OUT"
contains "unbekanntes Thema übersprungen" "- Thema 999999" "$OUT"
OUT=$(wpc lsec production batch "$T1" --yes 2>&1)
contains "Sammelauftrag gestartet" "Der Cronjob arbeitet die Abläufe ab" "$OUT"
RB=$(printf '%s' "$OUT" | sed -n 's/.*(Ablauf \([0-9]*\)).*/\1/p' | head -1)
check "Ablauf eingeplant" "queued" "$(wpj lsec production report "$RB" --format=json | json status)"
[ -n "$RB" ] && wpc lsec runs cancel "$RB" >/dev/null 2>&1
OUT=$($WP "${WP_ARGS[@]}" lsec check rest 2>&1)
contains "Route Abläufe" "/lsec/v1/runs" "$OUT"
contains "Route Sammelauftrag" "/lsec/v1/production/batch" "$OUT"

echo "F3 Bericht (0.9.1)"
P3=$(wpc post list --post_type=page --post_status=publish --field=url --posts_per_page=1)
wpc db query "INSERT INTO ${PREFIX}lsec_keywords (keyword, normalized, locale, region, first_seen_at, updated_at) VALUES ('lsectest bericht keyword', 'lsectest bericht keyword', 'de_DE', '', UTC_TIMESTAMP(), UTC_TIMESTAMP())" >/dev/null
K3=$(wpc db query "SELECT id FROM ${PREFIX}lsec_keywords WHERE normalized = 'lsectest bericht keyword'" --skip-column-names | head -1)
wpc db query "INSERT INTO ${PREFIX}lsec_keyword_metrics (keyword_id, source, period_start, period_end, impressions, clicks, ctr, position, page_url, created_at) VALUES ($K3, 'gsc', DATE_SUB(CURDATE(), INTERVAL 28 DAY), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 120, 2, 0.0167, 8.4, '$P3', UTC_TIMESTAMP())" >/dev/null
OUT=$(wpc lsec report 2>&1)
contains "Inhalte im Bericht" "Entwürfe des Plugins:" "$OUT"
contains "Entitäten im Bericht" "Leistungen und Orte ohne eigenen Inhalt" "$OUT"
contains "Hinweis aus der Search Console" "lsectest bericht keyword" "$OUT"
contains "CSV für Excel" "Seite;Titel;Art;Elementor;Keyword;Position" "$(wpc lsec report --format=csv)"
contains "Hinweis je Seite als Inhalt" "Inhalt" "$(wpc lsec report --section=improve)"
wpc lsec settings set brand_terms lsectest >/dev/null
contains "Markenbegriff aus der Einstellung" "Markenbegriffe: lsectest" "$(wpc lsec report --section=improve)"
contains "Suche nach dem eigenen Namen erkannt" "Marke" "$(wpc lsec report --section=improve)"
wpc lsec settings set brand_terms "" >/dev/null
wpc db query "DELETE FROM ${PREFIX}lsec_keyword_metrics WHERE keyword_id = $K3" >/dev/null
OUT=$($WP "${WP_ARGS[@]}" lsec check rest 2>&1)
contains "Route Bericht" "/lsec/v1/report" "$OUT"

echo "F4 Sammelentscheidung über Entitäten (0.9.2)"
for n in A B; do
	wpc db query "INSERT INTO ${PREFIX}lsec_entities (uuid, type, canonical_name, normalized_name, status, origin, created_at, updated_at) VALUES (UUID(), 'place', 'LSECTEST Sammel $n', 'lsectest sammel $n', 'candidate', 'ai_extraction', UTC_TIMESTAMP(), UTC_TIMESTAMP())" >/dev/null
done
wpc db query "INSERT INTO ${PREFIX}lsec_entities (uuid, type, canonical_name, normalized_name, status, origin, created_at, updated_at) VALUES (UUID(), 'service', 'LSECTEST Sammel Profil', 'lsectest sammel profil', 'confirmed', 'business_profile', UTC_TIMESTAMP(), UTC_TIMESTAMP())" >/dev/null
EA=$(wpc db query "SELECT id FROM ${PREFIX}lsec_entities WHERE canonical_name = 'LSECTEST Sammel A'" --skip-column-names | last_number)
EB=$(wpc db query "SELECT id FROM ${PREFIX}lsec_entities WHERE canonical_name = 'LSECTEST Sammel B'" --skip-column-names | last_number)
EP=$(wpc db query "SELECT id FROM ${PREFIX}lsec_entities WHERE canonical_name = 'LSECTEST Sammel Profil'" --skip-column-names | last_number)
OUT=$(wpc lsec entities decide "$EA,$EB,$EP" rejected 2>&1)
contains "mehrere abgelehnt, Profil übersprungen" "übersprungen: $EP" "$OUT"
check "beide abgelehnt" "2" "$(wpc db query "SELECT COUNT(*) FROM ${PREFIX}lsec_entities WHERE id IN ($EA, $EB) AND status = 'rejected' AND decided_at IS NOT NULL" --skip-column-names | last_number)"
check "Profileintrag unverändert" "confirmed" "$(wpc db query "SELECT status FROM ${PREFIX}lsec_entities WHERE id = $EP" --skip-column-names | last_word)"
OUT=$(wpc lsec entities decide "$EA,$EB" rejected 2>&1)
contains "Wiederholung ist kein Fehler" "Success" "$OUT"
OUT=$($WP "${WP_ARGS[@]}" lsec check rest 2>&1)
contains "Route Sammelentscheidung" "/lsec/v1/entities/decision" "$OUT"

echo "F5 Beitragsbilder und Sammelausschluss (0.10.0)"
contains "Standard: Mediathek, sonst KI-Bild" "library_ai" "$(wpc lsec settings list --format=json)"
if php -r 'exit(function_exists("imagecreatetruecolor") ? 0 : 1);'; then
	php -r '$i=imagecreatetruecolor((int)$argv[2],(int)$argv[3]); imagefill($i,0,0,imagecolorallocate($i,90,120,150)); imagejpeg($i,$argv[1],85);' "$TMP/lsectest-bild.jpg" 1200 700
	php -r '$i=imagecreatetruecolor(200,200); imagejpeg($i,$argv[1]);' "$TMP/lsectest-klein.jpg"
	IA=$(wpc media import "$TMP/lsectest-bild.jpg" --title="LSECTEST Bild" --porcelain | last_number)
	IS=$(wpc media import "$TMP/lsectest-klein.jpg" --title="LSECTEST klein" --porcelain | last_number)
	check "neues Bild ist offen" "1" "$(wpc eval "echo PHP_EOL, in_array($IA, \\Lsec\\Media\\ImageLibrary::pending_ids(1000), true) ? 1 : 0, PHP_EOL;" | last_number)"
	contains "kleines Bild ohne KI als ungeeignet" "$IS small" "$(wpc lsec images describe "$IS" 2>&1)"
	# Beschreibung wie von der KI, ohne Kosten.
	wpc eval "\\Lsec\\Media\\ImageLibrary::store($IA, array('alt' => 'LSECTEST Pflaster wird gereinigt', 'motif' => 'Pflaster', 'keywords' => array('Pflasterreinigung'), 'kind' => 'photo', 'watermark' => false, 'text' => false, 'suitable' => true), 'test');" >/dev/null
	check "Alternativtext gesetzt" "LSECTEST Pflaster wird gereinigt" "$(wpc post meta get "$IA" _wp_attachment_image_alt)"
	contains "geeignet und frei" "$IA" "$(wpc lsec images list --free --format=csv)"
	php -r '$d=json_decode(file_get_contents($argv[1]),true); $d["image"]=["image_id"=>(int)$argv[2]]; echo json_encode($d, JSON_UNESCAPED_UNICODE);' "$TMP/e1.json" "$IA" > "$TMP/e5.json"
	OUT=$(wpc lsec production start "$T1" --again --allow-duplicate --stub="$TMP/e5.json" --now 2>&1)
	R5=$(printf '%s' "$OUT" | sed -n 's/^Ablauf \([0-9]*\) .*/\1/p' | head -1)
	P5=$(wpj lsec production report "$R5" --format=json | json post_id)
	check "Beitragsbild aus der Mediathek gesetzt" "$IA" "$(wpc post meta get "$P5" _thumbnail_id)"
	contains "Beitragsbild im Bericht des Entwurfs" "Beitragsbild: aus der Mediathek" "$(wpc lsec production report "$R5")"
	check "danach nicht mehr frei" "0" "$(wpc lsec images list --free --format=csv | grep -c "^$IA,")"
	GEN=$(wpc eval "echo PHP_EOL, \\Lsec\\Media\\ImageLibrary::save_generated(file_get_contents('$TMP/lsectest-bild.jpg'), 'image/jpeg', array('prompt' => 'test photo', 'alt' => 'LSECTEST KI-Bild', 'title' => 'LSECTEST KI-Bild', 'keywords' => array('Test'), 'filename' => 'lsectest-ki-bild'), $P5, 'test/model', 0.01), PHP_EOL;" | last_number)
	contains "KI-Bild mit deutschem Dateinamen" "lsectest-ki-bild" "$(wpc eval "echo PHP_EOL, basename(get_attached_file($GEN)), PHP_EOL;")"
	contains "als KI-Bild vermerkt" "test/model" "$(wpc post meta get "$GEN" _lsec_ai_generated)"
	check "Alternativtext des KI-Bilds" "LSECTEST KI-Bild" "$(wpc post meta get "$GEN" _wp_attachment_image_alt)"
	contains "Bilder im Bericht" "Bilder:" "$(wpc lsec report --section=images)"
	for id in $IA $IS $GEN; do wpc post delete "$id" --force >/dev/null 2>&1; done
else
	skip "Beitragsbilder (PHP ohne GD)"
fi
OUT=$($WP "${WP_ARGS[@]}" lsec check rest 2>&1)
contains "Route Sammelausschluss Keywords" "/lsec/v1/keywords/exclusion" "$OUT"

echo "F6 Kategorie für Ortsseiten (0.10.1)"
wpc lsec settings set local_category "LSECTEST Regional" >/dev/null
OUT=$(wpc lsec production start "$T1" --again --allow-duplicate --stub="$TMP/e1.json" --now 2>&1)
R6=$(printf '%s' "$OUT" | sed -n 's/^Ablauf \([0-9]*\) .*/\1/p' | head -1)
P6=$(wpj lsec production report "$R6" --format=json | json post_id)
contains "neue Ortsseite in der Kategorie" "LSECTEST Regional" "$(wpc post term list "$P6" category --field=name)"
check "früherer Entwurf desselben Themas im Papierkorb (1.1.3)" "trash" "$(wpc post get "$P1" --field=post_status)"
wpc post term set "$P6" category 1 >/dev/null
OUT=$(wpc lsec production local-category 2>&1)
contains "vorhandene Ortsseiten nachträglich" "Ortsseiten neu in „LSECTEST Regional“" "$OUT"
contains "Ortsseite ohne Kategorie nachträglich zugeordnet" "LSECTEST Regional" "$(wpc post term list "$P6" category --field=name)"
wpc lsec settings set local_category "" >/dev/null
for t in $(wpc term list category --name="LSECTEST Regional" --field=term_id); do wpc term delete category "$t" >/dev/null; done

echo "F7 Empfehlung bei doppelten Themen (0.10.2)"
rest() { wpc eval "wp_set_current_user(1); \$q = new WP_REST_Request('$1', '/lsec/v1/$2'); \$q->set_query_params(json_decode('$3', true)); echo PHP_EOL, wp_json_encode(rest_do_request(\$q)->get_data(), JSON_UNESCAPED_UNICODE), PHP_EOL;"; }
OUT=$(rest GET topics/compare "{\"a\":$T2,\"b\":$T1}")
check "Empfehlung: Thema mit Entwurf behalten" "$T1" "$(printf '%s' "$OUT" | json advice.keep)"
contains "mit Begründung" "wird schon gearbeitet" "$OUT"
OUT=$(rest POST topics/resolve "{\"keep\":$T1,\"reject\":$T2}")
check "Empfehlung übernommen" "$T2" "$(printf '%s' "$OUT" | json reject)"
check "anderes Thema abgelehnt" "rejected" "$(wpj lsec topics show "$T2" --format=json | json status)"
OUT=$(rest POST topics/resolve "{\"keep\":$T2,\"reject\":$T1}")
contains "Thema mit Entwurf wird nicht abgelehnt" "gibt es schon einen Entwurf" "$OUT"

echo "F8 Handbuch im Backend (0.10.7)"
OUT=$(wpc eval 'echo PHP_EOL, (string) \Lsec\Admin\Manual::html(), PHP_EOL;')
contains "Handbuch wird gelesen" 'id="3-installation"' "$OUT"
contains "Tabellen und Code werden umgesetzt" "<pre><code>" "$OUT"
OUT=$(wpc eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; wp_set_current_user(1); (new \Lsec\Admin\AppPage())->add_menu(); global $submenu; echo PHP_EOL, implode(",", array_column($submenu["lsec"] ?? array(), 2)), PHP_EOL;')
contains "Menüpunkt gleich nach dem Dashboard" "lsec,lsec-manual,lsec-topics" "$OUT"

echo "F9 Knöpfe statt Befehle (0.11.0)"
OUT=$(rest POST tools/checks "{}")
check "Sicherheit prüfen im Backend" "true" "$(printf '%s' "$OUT" | json ok)"
contains "Schnittstellen geprüft" "Schnittstellen geprüft: nur für Administratoren erreichbar" "$OUT"
check "Datenbank aktualisieren im Backend" "true" "$(rest POST tools/migrate "{}" | json ok)"
check "Hängengebliebene Abläufe im Backend" "true" "$(rest POST tools/watchdog "{}" | json ok)"
check "Dashboard-Hinweise ohne Shell-Befehle" "0" "$(rest GET dashboard "{}" | json hints | grep -c 'wp lsec')"
check "Einrichtung ohne Shell-Befehle" "0" "$(rest GET setup "{}" | grep -c 'wp lsec\|curl -\|chmod ')"
OUT=$(rest POST settings '{"values":{"local_category":"LSECTEST Regional"}}')
contains "Kategorie gilt beim Speichern auch für vorhandene Ortsseiten" "LSECTEST Regional" "$(printf '%s' "$OUT" | json notes)"
rest POST settings '{"values":{"local_category":""}}' >/dev/null
for t in $(wpc term list category --name="LSECTEST Regional" --field=term_id); do wpc term delete category "$t" >/dev/null; done
PX=$(wpc post create --post_type=post --post_status=publish --post_title="LSECTEST Liste" --porcelain)
OUT=$(wpc eval 'wp_set_current_user(1); $a = ( new \Lsec\Admin\PostActions( \Lsec\Core\Plugin::instance() ) )->row_actions( array(), get_post( '"$PX"' ) ); echo PHP_EOL, implode( " | ", array_map( "wp_strip_all_tags", $a ) ), PHP_EOL;')
contains "Beitragsliste: Beitragsbild suchen" "Beitragsbild suchen" "$OUT"
contains "Beitragsliste: Entitäten erkennen" "Entitäten erkennen" "$OUT"
wpc post delete "$PX" --force >/dev/null

echo "F10 Steckbrief, Schema und Schreibregeln (1.1.0)"
OUT=$(wpc lsec production report "$R6")
contains "Steckbrief im Bericht" "Steckbrief:" "$OUT"
E10=$(wpc lsec entities add service "LSECTEST Fugenpflege" --confirmed | sed -n 's/.*: \([0-9]*\)$/\1/p')
PX=$(wpc post create --post_type=post --post_status=publish --post_title="LSECTEST Fugen" --post_content="<p>Die LSECTEST Fugenpflege nach der Reinigung.</p>" --porcelain)
wpc post meta update "$PX" _lsec_topic_id 1 >/dev/null
wpc post meta update "$PX" _lsec_primary_entities "[$E10]" >/dev/null
OUT=$(wpc eval 'echo PHP_EOL, wp_json_encode( \Lsec\Content\EntitySchema::for_post( '"$PX"' ), JSON_UNESCAPED_UNICODE ), PHP_EOL;')
contains "Schema: Haupt-Entität als about" '"about":[{"@type":"Service","name":"LSECTEST Fugenpflege"}]' "$OUT"
OUT=$(wpc eval 'echo PHP_EOL, implode( "|", (array) apply_filters( "thoka_auto_tagger_priority_terms", array(), get_post( '"$PX"' ) ) ), PHP_EOL;')
contains "Entitäten als Schlagwörter für den Auto Tagger" "LSECTEST Fugenpflege" "$OUT"
wpc post delete "$PX" --force >/dev/null
OUT=$(wpc eval '$c = array( array( "type" => "paragraph", "text" => "Mehr dazu <a href=\"/x\">hier</a>, nur heute." ) ); echo PHP_EOL, implode( ",", \Lsec\Production\QualityChecker::generic_anchors( $c ) ), "|", implode( ",", \Lsec\Production\QualityChecker::urgency( "Nur heute!" ) ), PHP_EOL;')
contains "Hinweis bei Ankertext „hier“ und künstlicher Dringlichkeit" "hier|nur heute" "$OUT"
check "Einstellung Schema für Entitäten an" "1" "$(wpc eval 'echo PHP_EOL, (int) \Lsec\Core\Settings::get( "schema_entities" ), PHP_EOL;' | tail -1)"

STYLE_V=$(wpc lsec style list --format=json | json 0.version)
wpc db query "DELETE FROM ${PREFIX}lsec_style_profiles WHERE profile LIKE '%LSECTEST%'" >/dev/null
[ -n "$STYLE_V" ] && wpc db query "UPDATE ${PREFIX}lsec_style_profiles SET is_active = 1 WHERE version = (SELECT v FROM (SELECT MAX(version) AS v FROM ${PREFIX}lsec_style_profiles) x)" >/dev/null
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
