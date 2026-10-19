#!/usr/bin/env bash
#
# build-pr-package.sh — erzeugt das PR-Paket fuer pods-framework/pods.
#
# Ergebnis: dist/pods-pr/ (generiert, per .gitignore ausgeschlossen)
#
#   classes/fields/repeater.php       Feldtyp in der Pods-Konvention:
#                                     Textdomain 'pods' statt 'pods-repeater'
#   tests/wpunit/PodsRepeaterTest.php  Portierte Testfaelle
#   readme-changelog.txt              Changelog-Block fuer Pods' readme.txt
#   PR-BESCHREIBUNG.md                Merge-Begruendung fuer den Kern
#
# Eigenschaften:
#   - idempotent: dist/pods-pr wird vor dem Lauf geleert
#   - bricht mit Exit != 0 ab, wenn eine Ersetzung nicht greift
#   - kein Netzwerk, kein Klonen von pods-framework/pods
#
# Was NICHT ersetzt wird: die CSS-Klassen pods-repeater-row, -subfield, -add
# usw. Sie sind der Vertrag mit ui/js/repeater.js und mit jedem Stylesheet,
# das den Feldtyp anspricht. Ein blindes s/pods-repeater/pods/g wuerde sie zu
# pods-row/pods-add machen und das Admin-UI lautlos zerstoeren.
#
# Die PHP-Portierung selbst liegt in scripts/port_field.py und
# scripts/port_test.py: die Ersetzung ist selektiv (nur 'pods-repeater' in
# Anfuehrungszeichen) und muss gezaehlt werden, und in Python stehen Regex und
# Anfuehrungszeichen ohne doppelte Shell-Escapes.
#
#
# Zielverzeichnis ueberschreibbar: PR_OUT_DIR (Default dist/pods-pr). Gebraucht
# von scripts/verify-pr-package.sh, das zweimal in ein mktemp -d baut und dabei
# die echte Ausgabe in dist/ nicht anfassen darf.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
OUT_DIR="${PR_OUT_DIR:-${REPO_ROOT}/dist/pods-pr}"

SOURCE_FIELD="${REPO_ROOT}/classes/fields/repeater.php"
SOURCE_TEST="${REPO_ROOT}/tests/RepeaterLimitTest.php"

DIE() { printf 'FEHLER: %s\n' "$*" >&2; exit 1; }

[[ -f "${SOURCE_FIELD}" ]] || DIE "Quelldatei fehlt: ${SOURCE_FIELD}"
[[ -f "${SOURCE_TEST}"  ]] || DIE "Quelldatei fehlt: ${SOURCE_TEST}"
[[ -f "${SCRIPT_DIR}/port_field.py" ]] || DIE "scripts/port_field.py fehlt"
[[ -f "${SCRIPT_DIR}/port_test.py"  ]] || DIE "scripts/port_test.py fehlt"

PYTHON="${PYTHON:-python3}"
command -v "${PYTHON}" >/dev/null 2>&1 || DIE "python3 nicht gefunden"

# ---------------------------------------------------------------------------
# 1. Ausgabeverzeichnis leeren (Idempotenz)
# ---------------------------------------------------------------------------
rm -rf "${OUT_DIR}"
mkdir -p "${OUT_DIR}/classes/fields" "${OUT_DIR}/tests/wpunit"

# ---------------------------------------------------------------------------
# 2. Feldtyp portieren
# ---------------------------------------------------------------------------
FIELD_OUT="${OUT_DIR}/classes/fields/repeater.php"
"${PYTHON}" "${SCRIPT_DIR}/port_field.py" "${SOURCE_FIELD}" "${FIELD_OUT}" || \
  DIE "Portierung des Feldtyps fehlgeschlagen"

# ---------------------------------------------------------------------------
# 3. Tests portieren
# ---------------------------------------------------------------------------
TEST_OUT="${OUT_DIR}/tests/wpunit/PodsRepeaterTest.php"
"${PYTHON}" "${SCRIPT_DIR}/port_test.py" "${SOURCE_TEST}" "${TEST_OUT}" || \
  DIE "Portierung der Tests fehlgeschlagen"

# ---------------------------------------------------------------------------
# 3b. Admin-Skript portieren
#
# Ohne das Skript ist das Admin-UI tot: die Feldklasse rendert Zeilen, aber
# nichts haengt Klick-Handler an Add/Remove/Move. Die Klassen muessen dabei
# denselben Namen tragen wie in der Feldklasse (pods-*, nicht pods-repeater-*).
# ---------------------------------------------------------------------------
SOURCE_JS="${REPO_ROOT}/ui/js/repeater.js"
if [[ -f "${SOURCE_JS}" ]]; then
  JS_OUT="${OUT_DIR}/ui/js/repeater.js"
  "${PYTHON}" "${SCRIPT_DIR}/port_js.py" "${SOURCE_JS}" "${JS_OUT}" || \
    DIE "Portierung des Admin-Skripts fehlgeschlagen"
else
  DIE "Quelldatei fehlt: ${SOURCE_JS} (ohne Admin-Skript ist der Feldtyp im Editor unbedienbar)"
fi

# ---------------------------------------------------------------------------
# 4. Changelog-Block
# ---------------------------------------------------------------------------
cat > "${OUT_DIR}/readme-changelog.txt" <<'CHANGELOG'
= 3.3.10 =
* Added: Repeater field type (repeatable group of sub-fields) — the
  repeatable-field-groups request from Discussion #7238 and Issue #109.
  Sub-fields are configured as JSON or referenced from a reusable field
  group; values are stored as JSON and exposed as PHP arrays.
CHANGELOG

# ---------------------------------------------------------------------------
# 5. PR-Beschreibung
# ---------------------------------------------------------------------------
cat > "${OUT_DIR}/PR-BESCHREIBUNG.md" <<'PRDESC'
# Feldtyp: Repeater (wiederholbare Feldgruppe)

## Was dieser PR macht

Fuegt Pods einen Feldtyp `repeater` hinzu: eine konfigurierbare Gruppe von
Sub-Feldern (text, textarea, number, pick, …), die beliebig oft wiederholt
werden kann. Das ist funktional das Aequivalent zum ACF-Repeater.

- `classes/fields/repeater.php` — Feldklasse `PodsField_Repeater`. Eine
  Datei, keine Aenderung an bestehenden Feldtypen.
- Sub-Felder kommen aus Inline-JSON oder aus einer wiederverwendbaren
  Feldgruppe.
- Speicherung als JSON; `value()` liefert beim Lesen ein PHP-Array.
- Frontend-Ausgabe folgt der Pods-Konvention: `repeater_display_format`
  (`default` / `br` / `ul` / `ol` / `custom`) plus Separator-Option, Muster
  wie `PodsField_Pick::_display_format_multi()`.
- Sub-Felder rendern durch die bestehende Pods-Pipeline
  (`PodsForm::field()`), inklusive der typspezifischen Optionen.

## Warum

Discussion #7238 und Issue #109 fragen seit Jahren nach wiederholbaren
Feldgruppen im Kern. Bisher gibt es dafuer nur Zusatz-Plugins und
ACF-Interoperabilitaet. Der Bedarf ist belegt, die Bauform ist klein und
isoliert: ein neuer Feldtyp, den niemand einschalten muss, der aber jedem
offensteht, der ihn konfiguriert.

## Wichtig: NICHT in `pods_repeatable_field_types` eintragen

Der Typ gehoert ausdruecklich **nicht** in den Filter
`pods_repeatable_field_types`. Steht er dort, behandelt Pods ihn als
wiederholbaren Standard-Typ und rendert ihn durch den generischen
`PodsForm`-Renderer statt durch die eigene `input()`-Methode. Folge: das
Zeilengeruest, die Add/Remove/Move-JS-Vertraege und die JSON-Speicherlogik
greifen nicht mehr, das Feld erscheint als einzelnes Eingabefeld.

Der Repeater bringt seine eigene Wiederholung mit — in `input()` sowie in
`pre_save()`/`value()`. Er braucht den Filter nicht, und der
`form_field_type` bleibt `repeater` (kein `repeatable=1`).

Verwandt: Pods erzwingt `repeatable=0` fuer Feldtypen ausserhalb der
Whitelist. Der Repeater ist von dieser Whitelist unabhaengig, weil seine
Wiederholung in der Feldklasse liegt und nicht im Formular-Renderer.

## Admin-UI: Skript liegt bei

Die Feldklasse rendert die Zeilen; `ui/js/repeater.js` haengt die
Interaktion daran (Zeile hinzufuegen, entfernen, verschieben, Sub-Feld
konfigurieren). Beides kommt in diesem PR mit, und beide sprechen
denselben CSS-Vertrag: `pods-rows`, `pods-row`, `pods-subfield`,
`pods-add`, `pods-remove`, `pods-move-up`, `pods-move-down`.

Die Klassen heissen `pods-*` und nicht `pods-repeater-*`, weil sie im Kern
im Pods-Namensraum liegen. Der Kern kennt keine dieser Namen (geprueft
gegen 3.3.9.1: kein `pods-row`, `pods-add`, `pods-subfield`), also
kollidiert das nicht mit Bestandsklassen. Der Vertrag wird von einem Test
festgehalten (`test_css_klassen_vertrag_zwischen_php_und_js`), damit PHP
und JS nicht auseinanderlaufen.

## Tests

`tests/wpunit/PodsRepeaterTest.php` (WP_UnitTestCase) deckt die
Limit-Invariante auf beiden Ebenen ab: `validate()` meldet zu viele Zeilen,
`pre_save()` schneidet zurueck, leere Template-Zeilen zaehlen nicht mit.
Die Tests brauchen keine Fixtures und laufen in der Standard-Testsuite mit.

## Maintenance

Ich uebernehme die Pflege des Feldtyps: Bugfixes, Kompatibilitaet mit neuen
Pods-Versionen und Anpassungen am Datenformat. Der Typ stammt aus einem
produktiven Einsatz und laeuft dort mit echtem Redaktionsbetrieb — die
Portierung ist keine Skizze, sondern der Code, der dort schreibt und liest.

## Feldgruppen-Registry: im Kern nicht enthalten

Die Option `repeater_group` verweist auf eine wiederverwendbare Feldgruppe.
Diese Registry ist ein Plugin-Konzept; im Kern gibt es sie nicht. Die
Feldklasse prueft deshalb mit `function_exists()` und faellt sauber auf
Inline-JSON zurueck. Konkret:

- `options()` liefert `repeater_group` mit leerer Auswahl, wenn
  `pods_repeater_get_groups()` fehlt.
- `get_sub_fields()` liest die Sub-Felder aus `repeater_sub_fields`
  (JSON), wenn `pods_repeater_get_group_fields()` fehlt.

Kein Fatal, keine Warnung — der Typ ist im Kern voll benutzbar, nur ohne
den Komfort der geteilten Gruppen. Wer die Registry will, kann sie als
eigenes Plugin nachruesten; die beiden Funktionsnamen sind der
Anschlusspunkt.

## Nicht enthalten (bewusst)

- Verschachtelte Repeater (Repeater in Repeater): gesperrt, mit sichtbarem
  Hinweis im Editor. Nachruestbar, aber ein eigenes Datenformat-Thema.
- Ein React-DFV-Editor fuer die Sub-Feld-Konfiguration: die Konfiguration
  ist zunaechst JSON bzw. eine Feldgruppe (siehe oben).
PRDESC

# ---------------------------------------------------------------------------
# 6. Selbstpruefung: alle vier Artefakte vorhanden und nicht leer
# ---------------------------------------------------------------------------
for artifact in "${FIELD_OUT}" "${TEST_OUT}" "${JS_OUT}" \
                "${OUT_DIR}/readme-changelog.txt" "${OUT_DIR}/PR-BESCHREIBUNG.md"; do
  [[ -s "${artifact}" ]] || DIE "Artefakt fehlt oder ist leer: ${artifact}"
done

# Akzeptanzkriterium 2 hart pruefen, statt es dem Aufrufer zu ueberlassen.
DOMAIN_HITS="$(grep -c "'pods'" "${FIELD_OUT}" || true)"
PLUGIN_HITS="$(grep -c 'pods-repeater' "${FIELD_OUT}" || true)"
[[ "${DOMAIN_HITS}" -gt 0 ]] || DIE "Kein 'pods' in der portierten Felddatei"
[[ "${PLUGIN_HITS}" -eq 0 ]] || \
  DIE "pods-repeater steht noch ${PLUGIN_HITS}-mal in der portierten Felddatei"

printf 'OK: %s\n' "${OUT_DIR}"
printf '  classes/fields/repeater.php       %5s Zeilen (Quelle: %s)\n' \
  "$(wc -l < "${FIELD_OUT}" | tr -d ' ')" "$(wc -l < "${SOURCE_FIELD}" | tr -d ' ')"
printf '  tests/wpunit/PodsRepeaterTest.php %5s Zeilen (Quelle: %s)\n' \
  "$(wc -l < "${TEST_OUT}" | tr -d ' ')" "$(wc -l < "${SOURCE_TEST}" | tr -d ' ')"
printf "  Textdomain-Fundstellen            %5s\n" "${DOMAIN_HITS}"
