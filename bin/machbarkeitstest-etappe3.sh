#!/usr/bin/env bash
#
# Machbarkeitstest, Etappe 3: Prüfpunkte ohne Kosten auf einer echten WordPress-Installation.
#   M10 CSV-/Excel-Import · M12/M13 Entwurf und Rank Math · M14 Elementor · M15 REST · M17 Tippfehler
#   M18 Ortsabgleich nur mit --online (fragt Wikidata ab)
#
# Aufruf (Plugin aktiviert):
#   bash bin/machbarkeitstest-etappe3.sh --path=/var/www/vhosts/example.de/httpdocs
#   bash bin/machbarkeitstest-etappe3.sh --path=… --online       # mit Wikidata (M18)
#   bash bin/machbarkeitstest-etappe3.sh --path=… --keep-draft   # Testentwurf zur Sichtprüfung behalten
# Optional: WP="php /pfad/wp-cli.phar" bash bin/machbarkeitstest-etappe3.sh ...
#
# Prüfpunkte mit Kosten (KI, Textfocus) stehen am Ende als Befehle zum Ausführen von Hand.
# Der Test legt Testdaten an (Quelle csv:lsec_test, Entwurf, Elementor-Seite) und räumt sie wieder auf.

set -u

WP="${WP:-wp}"
WP_ARGS=()
ONLINE=0
KEEP=0
for arg in "$@"; do
	case "$arg" in
		--online) ONLINE=1 ;;
		--keep-draft) KEEP=1 ;;
		*) WP_ARGS+=("$arg") ;;
	esac
done
PASS=0
FAIL=0
SKIP=0
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

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
json() { php -r '$in=stream_get_contents(STDIN); $l=explode("\n",$in); $d=null; for($i=0;$i<count($l)&&$d===null;$i++){ if(!preg_match("/^\\s*[\\[{]/",$l[$i])) continue; for($j=count($l)-1;$j>=$i;$j--){ $t=json_decode(implode("\n",array_slice($l,$i,$j-$i+1)),true); if(is_array($t)){ $d=$t; break; } } } $p=explode(".",$argv[1]); foreach($p as $k){ if($k==="count"){ $d=is_array($d)?count($d):0; continue; } $d=is_array($d)?($d[$k]??null):null; } echo is_bool($d)?($d?"true":"false"):(is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):$d);' "$1"; }
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

echo "Machbarkeitstest Etappe 3 – $(date -u '+%Y-%m-%d %H:%M UTC')"
if ! command -v php >/dev/null 2>&1; then
	echo "Abbruch: 'php' ist nicht im PATH. Beispiel: PATH=/opt/plesk/php/8.4/bin:\$PATH"
	exit 2
fi
STATUS=$(wpc lsec status --format=json)
if [ -z "$(echo "$STATUS" | json plugin_version)" ]; then
	echo "Abbruch: 'wp lsec' nicht verfügbar. Ist das Plugin aktiviert? (WP=$WP)"
	exit 2
fi
WP_VERSION=$(wpc core version | grep -E '^[0-9]+\.[0-9]' | tail -1)
RANK_MATH=$(echo "$STATUS" | json rank_math_active)
echo "WordPress $WP_VERSION, PHP $(php -r 'echo PHP_VERSION;'), Plugin $(echo "$STATUS" | json plugin_version), Rank Math aktiv: $RANK_MATH"
PREFIX=$(wpc db prefix | last_word)
check "Schemaversion installiert = Code" "$(echo "$STATUS" | json schema_version_code)" "$(echo "$STATUS" | json schema_version_installed)"

cleanup_keywords() {
	wpc db query "DELETE FROM ${PREFIX}lsec_keyword_metrics WHERE source = 'csv:lsec_test'" >/dev/null
	wpc db query "DELETE FROM ${PREFIX}lsec_imports WHERE source = 'csv:lsec_test'" >/dev/null
	wpc db query "DELETE k FROM ${PREFIX}lsec_keywords k LEFT JOIN ${PREFIX}lsec_keyword_metrics m ON m.keyword_id = k.id WHERE m.id IS NULL" >/dev/null
}
cleanup_keywords

# ---------------------------------------------------------------------------
echo "M10 CSV-/Excel-Import"
# Aufbau wie die Exporte vom 17.10.2026 (Keyword Revealer, Keyword Chef) und ein deutscher Excel-Export.
printf '\xef\xbb\xbf"keywordsInclude","searches","difficulty","keyword","search_intent","cpc","historical_volume"\n"fl\xc3\xa4chenreinigung","110","35","fl\xc3\xa4chenreinigung","navigational","4.36","140,90,90,90,140,170,140,90,140,70,70,70"\n"thermo fl\xc3\xa4chenreinigung","20","","thermo fl\xc3\xa4chenreinigung","navigational","","30,30,10,40,10,10,10,30,10,10,10,20"\n' > "$TMP/keyword-revealer.csv"
printf '\xef\xbb\xbfKeyword,Avg,High,Low,Category\nhamburger fl\xc3\xa4chenreinigung,0,0,0,Wildcard\nfl\xc3\xa4chenreinigung terrasse,0,0,0,Wildcard\nfl\xc3\xa4chenreinigung hochdruck,0,0,0,Wildcard\nthermische fl\xc3\xa4chenreinigung,0,0,0,Wildcard\n' > "$TMP/keyword-chef.csv"
printf 'Keyword;Suchvolumen;CPC (EUR);Schwierigkeit\r\nFl\xe4chenreinigung Hannover;1.200;2,35;41\r\n"Pflaster, reinigen";90;;12\r\n' > "$TMP/excel-export.csv"

OUT=$(wpc lsec keywords import "$TMP/keyword-revealer.csv" --source=csv:lsec_test)
contains "Keyword Revealer erkannt" "Vorlage: Keyword Revealer" "$OUT"
contains "UTF-8 mit BOM erkannt" "Kodierung: UTF-8 (BOM)" "$OUT"
L=$(wpc lsec keywords list --source=csv:lsec_test --search="thermo" --format=json)
check "Umlaute korrekt" "thermo flächenreinigung" "$(echo "$L" | json 0.keyword)"
check "Volumen" "20" "$(echo "$L" | json 0.volumen)"
check "Monatswerte als Spannweite" "10–40" "$(echo "$L" | json 0.spannweite)"
check "Trend ohne Monatszuordnung" "0" "$(wpc db query "SELECT COUNT(*) FROM ${PREFIX}lsec_keyword_metrics WHERE source='csv:lsec_test' AND trend NOT LIKE '%\"labeled\":false%'" --skip-column-names | last_number)"

OUT=$(wpc lsec keywords import "$TMP/keyword-chef.csv" --source=csv:lsec_test)
contains "Keyword Chef erkannt" "Vorlage: Keyword Chef" "$OUT"
contains "Hinweis auf Nullwerte" "unbekannt" "$OUT"
check "Nullwerte als unbekannt (NULL) gespeichert" "0" "$(wpc db query "SELECT COUNT(*) FROM ${PREFIX}lsec_keyword_metrics m JOIN ${PREFIX}lsec_imports i ON i.id = m.import_batch_id WHERE i.filename = 'keyword-chef.csv' AND m.source='csv:lsec_test' AND m.search_volume IS NOT NULL" --skip-column-names | last_number)"

OUT=$(wpc lsec keywords import "$TMP/excel-export.csv" --dry-run)
contains "Windows-1252 erkannt" "Kodierung: Windows-1252" "$OUT"
contains "Semikolon erkannt" "Trennzeichen: ;" "$OUT"
contains "deutsche Zahlen und Umlaute" "$(printf 'Flächenreinigung Hannover\t1200\t41\t2,35')" "$OUT"
contains "Komma in Anführungszeichen" "Pflaster, reinigen" "$OUT"

# ---------------------------------------------------------------------------
echo "M17 Tippfehlerprüfung"
OUT=$(wpc lsec keywords check "flächenreingung" "Flaechenreinigung Terrasse")
contains "flächenreingung → flächenreinigung" "$(printf 'flächenreingung\tflächenreingung\tflächenreinigung')" "$OUT"
contains "Umlaut-Variante erkannt" "Umlaut-Schreibweise" "$OUT"
OUT=$($WP "${WP_ARGS[@]}" lsec textfocus keyword "flächenreingung" --dry-run 2>&1)
contains "Textfocus-Abfrage wird vor dem Aufruf gestoppt" "keine Credits verbraucht" "$OUT"
cleanup_keywords

# ---------------------------------------------------------------------------
echo "M12/M13 Entwurf mit Blöcken und Rank-Math-Angaben"
DRAFT=$(wpc lsec draft sample --porcelain | last_number)
if [ -z "$DRAFT" ]; then
	check "Entwurf angelegt" "ja" "nein"
else
	D=$(wpc lsec draft show "$DRAFT" --format=json)
	check "Status Entwurf" "draft" "$(echo "$D" | json status)"
	check "9 Blöcke" "9" "$(echo "$D" | json blocks.count)"
	check "Block-Markup unverändert nach parse/serialize" "true" "$(echo "$D" | json roundtrip)"
	check "Kategorie gesetzt" "1" "$(echo "$D" | json categories.count)"
	check "Schlagwörter gesetzt" "2" "$(echo "$D" | json tags.count)"
	check "kaputter interner Link entfernt" "0" "$(wpc post get "$DRAFT" --field=post_content | grep -c 'gibt-es-nicht-lsec')"
	if [ "$RANK_MATH" = "true" ]; then
		check "rank_math_focus_keyword" "Flächenreinigung Burgdorf,Terrassenreinigung,Pflasterreinigung" "$(echo "$D" | json rank_math_focus_keyword)"
		contains "rank_math_title" "Flächenreinigung Burgdorf" "$(echo "$D" | json rank_math_title)"
		contains "rank_math_description" "Thermische Flächenreinigung" "$(echo "$D" | json rank_math_description)"
	else
		skip "Rank Math nicht aktiv: Angaben nur als Vorschlag"
		contains "Vorschlag gespeichert" "Flächenreinigung Burgdorf" "$(wpc post meta get "$DRAFT" _lsec_seo_suggestion)"
	fi
	if [ "$KEEP" -eq 1 ]; then
		echo "  Sichtprüfung im Block-Editor (keine Meldung „unerwarteter Inhalt“, Rank-Math-Seitenleiste):"
		echo "  $(wpc eval "echo admin_url('post.php?post=${DRAFT}&action=edit');")"
		echo "  Danach löschen: wp post delete ${DRAFT} --force"
	else
		wpc post delete "$DRAFT" --force >/dev/null
		for TAG in flaechenreinigung burgdorf; do
			T=$(wpc term get post_tag "$TAG" --by=slug --field=count 2>/dev/null | last_number)
			[ "$T" = "0" ] && wpc term delete post_tag "$TAG" --by=slug >/dev/null 2>&1
		done
	fi
fi

# ---------------------------------------------------------------------------
echo "M14 Elementor-Text"
ELEM='[{"id":"a1","elType":"container","settings":{},"elements":[{"id":"h1","elType":"widget","widgetType":"heading","settings":{"title":"Flächenreinigung in Burgdorf","header_size":"h1"},"elements":[]},{"id":"t1","elType":"widget","widgetType":"text-editor","settings":{"editor":"<p>Schonend mit Heißwasser &amp; ohne Chemie.</p>"},"elements":[]},{"id":"h2","elType":"widget","widgetType":"heading","settings":{"title":"Häufige Fragen","header_size":"h2"},"elements":[]},{"id":"ac","elType":"widget","widgetType":"accordion","settings":{"tabs":[{"tab_title":"Was kostet es?","tab_content":"Ab 4 € pro m²."}]},"elements":[]},{"id":"ht","elType":"widget","widgetType":"html","settings":{"html":"<script>x()</script>"},"elements":[]}]}]'
PAGE=$(wpc post create --post_type=page --post_status=draft --post_title="lsec Elementor-Test" --porcelain | last_number)
wpc post meta update "$PAGE" _elementor_edit_mode builder >/dev/null
wpc post meta update "$PAGE" _elementor_data "$ELEM" >/dev/null
C=$(wpc lsec check content "$PAGE" --format=json)
check "Quelle elementor" "elementor" "$(echo "$C" | json source)"
check "zwei Überschriften" "2" "$(echo "$C" | json headings.count)"
check "H1 erkannt" "Flächenreinigung in Burgdorf" "$(echo "$C" | json headings.0.text)"
contains "Text mit Entitäten dekodiert" "Heißwasser & ohne Chemie" "$(echo "$C" | json text)"
contains "Akkordeon gelesen" "Ab 4 € pro m²." "$(echo "$C" | json text)"
check "HTML-Widget ignoriert" "0" "$(echo "$C" | json text | grep -c 'x()')"
wpc post delete "$PAGE" --force >/dev/null

# ---------------------------------------------------------------------------
echo "M15 REST-Zugriffsschutz"
OUT=$($WP "${WP_ARGS[@]}" lsec check rest 2>&1)
contains "alle Routen 403 für Besucher und Abonnenten" "Routen geschützt" "$OUT"

# ---------------------------------------------------------------------------
echo "M18 Ortsabgleich (Wikidata)"
if [ "$ONLINE" -eq 0 ]; then
	skip "nur mit --online"
else
	SAVED=$(wpc option get lsec_settings --format=json)
	OUT=$($WP "${WP_ARGS[@]}" lsec entities resolve-place Burgdorf --no-area 2>&1)
	if printf '%s' "$OUT" | grep -q "zu viele Anfragen"; then
		skip "Wikidata meldet zu viele Anfragen, später wiederholen"
	else
		contains "ohne Kontext unresolved" "Status: unresolved" "$OUT"
		contains "Kandidat Region Hannover" "Q555618" "$OUT"
		contains "Kandidat Landkreis Wolfenbüttel" "Q634167" "$OUT"
		wpc lsec entities service-area --id=Q5963 >/dev/null
		OUT=$($WP "${WP_ARGS[@]}" lsec entities resolve-place Burgdorf 2>&1)
		contains "mit Region Hannover probable" "Status: probable" "$OUT"
		contains "gewählt: Q555618" "$(printf 'Q555618\tStadt in der Region Hannover')" "$OUT"
	fi
	if [ -n "$SAVED" ]; then
		echo "$SAVED" | wpc option update lsec_settings --format=json >/dev/null
	else
		wpc lsec entities service-area --clear >/dev/null
	fi
fi

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen, $SKIP übersprungen"
cat <<'EOF'

Von Hand (verbrauchen Guthaben):
  M7    wp lsec ai test
  M7a   wp lsec ai test --web
  M7b   wp lsec ai test --provider=anthropic --model=claude-sonnet-5-5   (bzw. openai)
  M11   wp lsec entities extract <Beitrags-ID> --dry-run   danach ohne --dry-run,
        dann Local SEO → Entitäten: Bestätigen/Ablehnen
  M16   wp lsec textfocus account
        wp lsec textfocus langs --search=de
        wp lsec textfocus keyword "flächenreinigung" "terrassenreinigung hannover" --dry-run   (danach ohne)
  M12   im Block-Editor ansehen: bash bin/machbarkeitstest-etappe3.sh --path=… --keep-draft
EOF
[ "$FAIL" -eq 0 ]
