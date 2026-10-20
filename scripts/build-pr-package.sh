#!/usr/bin/env bash
#
# build-pr-package.sh — erzeugt das PR-Paket fuer pods-framework/pods.
#
# Ergebnis: dist/pods-pr/ (generiert, per .gitignore ausgeschlossen)
#
#   classes/fields/repeater.php       Feldtyp in der Pods-Konvention:
#                                     Textdomain 'pods' statt 'pods-repeater'
#   ui/js/repeater.js                 Admin-Skript, CSS-Vertrag auf pods-*
#   tests/wpunit/PodsRepeaterTest.php            Limit-Test
#   tests/wpunit/PodsRepeaterContractTest.php    Vertragstest (S12)
#   tests/wpunit/PodsRepeaterCoreIntegrationTest.php  Integrationsbeleg (S13)
#   tests/wpunit/PodsRepeaterNestedTest.php      Nested-Ablehnung (S21)
#   readme-changelog.txt              Changelog-Block fuer Pods' readme.txt
#   PR-BESCHREIBUNG.md                Merge-Begruendung fuer den Kern
#   DISCUSSION-KOMMENTAR.md           Entwurf fuer Discussion #7238
#   CHECKLISTE.md                     Was vor dem Einreichen zu entscheiden ist
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

# Alle vier Testdateien, die der PR mitbringt. Der Limit-Test ist die
# aelteste Zusage (serverseitige Durchsetzung), der Vertragstest (S12) prueft
# den Feldtyp als Ganzes, der Integrationsbeleg (S13) die Kern-Registrierung,
# der Nested-Test (S21) die Ablehnung verschachtelter Repeater. Jede Datei
# traegt eine Zusage aus den Dokumenten — fehlt eine, behauptet der PR mehr,
# als er beweist.
SOURCE_TESTS=(
  "${REPO_ROOT}/tests/RepeaterLimitTest.php"
  "${REPO_ROOT}/tests/RepeaterContractTest.php"
  "${REPO_ROOT}/tests/CoreIntegrationTest.php"
  "${REPO_ROOT}/tests/RepeaterNestedTest.php"
)
TARGET_TESTS=(
  "PodsRepeaterTest.php"
  "PodsRepeaterContractTest.php"
  "PodsRepeaterCoreIntegrationTest.php"
  "PodsRepeaterNestedTest.php"
)

DIE() { printf 'FEHLER: %s\n' "$*" >&2; exit 1; }

[[ -f "${SOURCE_FIELD}" ]] || DIE "Quelldatei fehlt: ${SOURCE_FIELD}"
for source_test in "${SOURCE_TESTS[@]}"; do
  [[ -f "${source_test}" ]] || DIE "Quelldatei fehlt: ${source_test}"
done
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
#
# Die Zahl der Testmethoden wird gegen die Quelle geprueft. Eine stille
# Verkleinerung des Pakets (z. B. weil ein Porter eine Datei halbiert) waere
# hier der teuerste Fehler: der PR behauptet dann eine Abdeckung, die er
# nicht hat.
# ---------------------------------------------------------------------------
TEST_COUNTS=()
for i in "${!SOURCE_TESTS[@]}"; do
  source_test="${SOURCE_TESTS[$i]}"
  target_name="${TARGET_TESTS[$i]}"
  test_out="${OUT_DIR}/tests/wpunit/${target_name}"

  "${PYTHON}" "${SCRIPT_DIR}/port_test.py" "${source_test}" "${test_out}" || \
    DIE "Portierung von ${source_test} fehlgeschlagen"

  source_count="$(grep -c 'public function test_' "${source_test}" || true)"
  ported_count="$(grep -c 'public function test_' "${test_out}" || true)"

  [[ "${source_count}" -gt 0 ]] || DIE "Quelle hat keine Testmethoden: ${source_test}"
  [[ "${source_count}" -eq "${ported_count}" ]] || \
    DIE "${target_name}: ${ported_count} Testmethoden portiert, Quelle hat ${source_count}"

  TEST_COUNTS+=("${ported_count}")
done

TEST_TOTAL=0
for count in "${TEST_COUNTS[@]}"; do
  TEST_TOTAL=$(( TEST_TOTAL + count ))
done
TEST_FILES=${#TEST_COUNTS[@]}

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
#
# Die Testzahlen kommen aus dem Lauf oben, nicht aus dem Kopf: TEST_TOTAL und
# die Einzelzahlen werden eingesetzt. Aendert sich ein Test, aendert sich der
# Text mit.
# ---------------------------------------------------------------------------
cat > "${OUT_DIR}/PR-BESCHREIBUNG.md" <<'PRDESC'
# Feldtyp: Repeater (wiederholbare Feldgruppe)

## Was dieser PR macht

Fuegt Pods einen Feldtyp `repeater` hinzu: eine konfigurierbare Gruppe von
Sub-Feldern (`text`, `paragraph`, `number`, `website`, `pick`, …), die beliebig
oft wiederholt werden kann. Werte werden als JSON gespeichert und beim Lesen
als PHP-Array zurueckgegeben. Funktional entspricht das dem ACF-Repeater, ohne
Abhaengigkeit von ACF.

## Warum

Discussion #7238 und Issue #109 fragen seit Jahren nach wiederholbaren
Feldgruppen im Kern. Bisher gibt es dafuer nur Zusatz-Plugins und
ACF-Interoperabilitaet. Der Bedarf ist belegt, die Bauform ist klein und
isoliert: ein neuer Feldtyp, den niemand einschalten muss.

## Was der PR am Kern anfasst — Integrationsbeleg

Der PR ist **additiv**. Er aendert keine bestehende Kern-Datei:

| Datei | Status |
| --- | --- |
| `classes/fields/repeater.php` | neu (Feldklasse `PodsField_Repeater`) |
| `ui/js/repeater.js` | neu (Admin-Interaktion) |
| `tests/wpunit/PodsRepeaterTest.php` | neu |
| `tests/wpunit/PodsRepeaterContractTest.php` | neu |
| `tests/wpunit/PodsRepeaterCoreIntegrationTest.php` | neu |
| `tests/wpunit/PodsRepeaterNestedTest.php` | neu |
| `readme.txt` / `changelog.txt` | ein Eintrag |

Er nutzt vier bestehende Kern-Schnittstellen, alle unveraendert:

1. **Registrierung.** Der Typ wird ueber `PodsForm::register_field_type()`
   angemeldet (Konvention `PODS_DIR . 'classes/fields/{type}.php'`, Klasse
   `PodsField_{Type}`). Der Autoloader des Kerns
   (`PodsInit.php:193-204`) findet nur klassen im Kern-Verzeichnis; fuer
   einen externen Pfad greift der beim Registrieren uebergebene Dateipfad
   (`PodsForm.php:1569-1571`). Im Kern liegt die Datei am Konventionsort,
   beide Wege zeigen also auf dieselbe Datei.
2. **Formular-Rendering.** Sub-Felder werden durch `PodsForm::field()`
   gerendert — die typspezifischen Optionen und Sanitizer des Kerns gelten
   damit unveraendert auch fuer Sub-Felder.
3. **Speichern.** `validate()` gibt ein **Array von Meldungen** zurueck, kein
   `WP_Error`. Das ist der Pods-Vertrag: `PodsAPI::save_pod_item()` castet
   das Ergebnis mit `(array)` und reicht die Elemente an `wp_kses_post()`
   weiter. Ein `WP_Error` an dieser Stelle bricht den Save mit einem
   PHP-Fehler ab. Der `PodsRepeaterCoreIntegrationTest` haelt genau das fest.
4. **Anzeige.** Frontend-Ausgabe folgt der Pods-Konvention
   `repeater_display_format` (`default` / `br` / `ul` / `ol` / `custom`) plus
   Separator-Option, Muster wie `PodsField_Pick::_display_format_multi()`.

Der Integrationsbeleg laeuft gegen Pods 3.3.9.1 und prueft alle vier Zusagen
mit harten Assertions (kein Skip): Registrierung landet in
`PodsForm::\$field_types['repeater']`, die Felddatei liegt am
Konventionspfad, die Klasse laedt aus dem Kern-Verzeichnis, und `validate()`
liefert ein Array, das `save_pod_item()` ohne Fehler verarbeitet.

## Wichtig: NICHT in `pods_repeatable_field_types` eintragen

Der Typ gehoert ausdruecklich **nicht** in den Filter
`pods_repeatable_field_types`. Steht er dort, behandelt Pods ihn als
wiederholbaren Standard-Typ und rendert ihn durch den generischen
`PodsForm`-Renderer statt durch die eigene `input()`-Methode.

**Folge, konkret:** das Zeilengeruest, die Add/Remove/Move-JS-Vertraege und
die JSON-Speicherlogik greifen nicht mehr — das Feld erscheint als einzelnes
Eingabefeld, und `pre_save()` bekommt eine Zeichenkette statt eines Arrays.
Der Repeater bringt seine eigene Wiederholung mit (in `input()`, `pre_save()`
und `value()`); er braucht den Filter nicht, und `form_field_type` bleibt
`repeater` (kein `repeatable=1`).

## Admin-UI: Skript liegt bei

Die Feldklasse rendert die Zeilen; `ui/js/repeater.js` haengt die Interaktion
daran (Zeile hinzufuegen, entfernen, verschieben). Beide sprechen denselben
CSS-Vertrag: `pods-rows`, `pods-row`, `pods-subfield`, `pods-add`,
`pods-remove`, `pods-move-up`, `pods-move-down`.

Die Klassen heissen `pods-*` und nicht `pods-repeater-*`, weil sie im Kern
im Pods-Namensraum liegen. Der Kern kennt keine dieser Namen (geprueft gegen
3.3.9.1), also kollidiert das nicht mit Bestandsklassen. Ein Test haelt den
Vertrag zwischen PHP und JS fest.

## Deinstallation

Dieses Paket bringt kein `uninstall.php` mit, und der Kern braucht keins: der
Feldtyp legt keine eigene Tabelle und keine eigene Option an. Die Werte stehen
im Post-Meta bzw. in den Pods-Feldoptionen — beides gehoert Pods und wird von
Pods' eigener Deinstallationslogik behandelt. Ein Feldtyp, der beim
Deinstallieren eigenmaechtig Meta loescht, wuerde Daten fremder Felder
mitreissen.

## Tests

{{TOTAL}} Testmethoden in {{TFILES}} Dateien, alle {{BT}}WP_UnitTestCase{{BT}}, keine
Fixtures, keine Netzwerkzugriffe:

| Datei | Testmethoden | deckt ab |
| --- | --- | --- |
| {{BT}}PodsRepeaterTest.php{{BT}} | {{C0}} | serverseitiges Limit: {{BT}}validate(){{BT}} meldet, {{BT}}pre_save(){{BT}} schneidet zurueck, leere Template-Zeilen zaehlen nicht |
| {{BT}}PodsRepeaterContractTest.php{{BT}} | {{C1}} | Feldtyp als Ganzes: {{BT}}validate(){{BT}}-Rueckgabevertrag, echter Save ueber die Pods-API, {{BT}}value(){{BT}}, {{BT}}display(){{BT}}, Options- und CSS-Vertrag |
| {{BT}}PodsRepeaterCoreIntegrationTest.php{{BT}} | {{C2}} | Kern-Integration: Registrierung, Konventionspfad, Laden aus dem Kern-Verzeichnis, Save-Vertrag |
| {{BT}}PodsRepeaterNestedTest.php{{BT}} | {{C3}} | verschachtelte Repeater: {{BT}}validate(){{BT}} lehnt Sub-Felder vom Typ {{BT}}repeater{{BT}} ab (Meldung mit Sub-Feld-Label, vor Limit/required), {{BT}}pre_save(){{BT}} verwirft die Werte; Gegenproben: legitime Felder bleiben gueltig |

Im Plugin-Repo laufen alle {{TFILES}} Dateien in der Standard-Suite mit. Die
portierten Fassungen von Vertrag, Kern-Integration und Nested-Ablehnung tragen
zusaetzlich die Gruppenmarke {{BT}}@group podscore{{BT}}: im Pods-Repo wuerden
sie sonst gegen eine Feldklasse laufen, die es dort erst mit diesem PR gibt.

## Feldgruppen-Registry: im Kern nicht enthalten

Die Option `repeater_group` verweist auf eine wiederverwendbare Feldgruppe.
Diese Registry ist ein Plugin-Konzept; im Kern gibt es sie nicht. Die
Feldklasse prueft mit `function_exists()` und faellt sauber auf Inline-JSON
zurueck: `options()` liefert `repeater_group` mit leerer Auswahl, wenn
`repeater_get_groups()` fehlt; `get_sub_fields()` und `get_group_notice()`
lesen `repeater_sub_fields` (JSON), wenn `repeater_get_group_fields()` fehlt.
Kein Fatal, keine Warnung — der Typ ist im Kern voll benutzbar, nur ohne den
Komfort geteilter Gruppen. Beide Funktionen haben im Kern keinen
Plugin-Praefix: der PR bringt die Feldklasse, der Name gehoert ihm.

## Maintenance

Ich uebernehme die Pflege des Feldtyps: Bugfixes, Kompatibilitaet mit neuen
Pods-Versionen und Anpassungen am Datenformat. Der Typ stammt aus einem
produktiven Einsatz und laeuft dort mit echtem Redaktionsbetrieb — die
Portierung ist keine Skizze, sondern der Code, der dort schreibt und liest.

## Nicht enthalten (bewusst)

- Ein React-DFV-Editor fuer die Sub-Feld-Konfiguration: die Konfiguration
  ist zunaechst JSON bzw. eine Feldgruppe (siehe oben).

## Verschachtelte Repeater: abgelehnt, nicht nur im Editor gesperrt

Ein Repeater in einem Repeater waere ein eigenes Datenformat-Thema.
Verschachtelte Repeater werden serverseitig abgelehnt, nicht bloss im UI
versteckt:

- {{BT}}validate(){{BT}} prueft die Sub-Feldtypen vor Limit und
  {{BT}}required{{BT}} und liefert fuer ein Sub-Feld vom Typ {{BT}}repeater{{BT}}
  ein Meldungs-Array mit dem Label des betroffenen Feldes
  ({{BT}}Nested repeaters are not supported: "…"{{BT}}). Die Meldung nennt das
  Sub-Feld, damit der Redakteur es findet; fehlt das Label, steht der Name da.
- {{BT}}pre_save(){{BT}} verwirft das betroffene Sub-Feld auf Schreibwegen, die
  nicht validieren (REST, {{BT}}pods()->save(){{BT}}, Import).
- Die Gegenprobe steht mit im Test: dieselbe Struktur ohne
  {{BT}}repeater{{BT}}-Sub-Feld bleibt gueltig, und {{BT}}pre_save(){{BT}}
  speichert legitime Werte vollstaendig.

Belegt durch {{BT}}PodsRepeaterNestedTest.php{{BT}} ({{C3}} Testmethoden); die
Kern-Voraussetzung (Gruppenmarke) steht in der Checkliste, Abschnitt 2.
Zusaetzlich schliesst die Feldgruppen-Registry des Plugins den Typ aus; die
Inline-JSON-Konfiguration ({{BT}}repeater_sub_fields{{BT}}) geht an dieser
Registry vorbei, deshalb die Pruefung in der Feldklasse selbst.
PRDESC

# Platzhalter einsetzen. Das Heredoc ist bewusst quoted ('PRDESC'), damit
# Backticks und ${...} im Text stehen bleiben — ein unquoted Heredoc haette
# die Inline-Code-Markierungen als Command-Substitution ausgefuehrt und
# jeden Backtick-Ausdruck durch eine leere Zeichenkette ersetzt.
PR_DESC="${OUT_DIR}/PR-BESCHREIBUNG.md"
# Zaehler und Dateien muessen deckungsgleich sein; sonst beendet sich das
# Skript mit "unbound variable" statt mit einer Meldung, die den Fehler nennt.
if [[ ${#TEST_COUNTS[@]} -ne ${#TARGET_TESTS[@]} ]]; then
  DIE "TEST_COUNTS (${#TEST_COUNTS[@]}) und TARGET_TESTS (${#TARGET_TESTS[@]}) passen nicht zusammen"
fi
# sed-Ausdruecke aus den Zaehlern bauen statt {{C0}}..{{C3}} zu verdrahten:
# eine fuenfte Testdatei soll nur einen Eintrag in SOURCE_TESTS/TARGET_TESTS
# kosten und nicht still in ein 'unbound variable' laufen.
SED_ARGS=(
  -e "s/{{TOTAL}}/${TEST_TOTAL}/g"
  -e "s/{{TFILES}}/${TEST_FILES}/g"
  -e "s/{{BT}}/\`/g"
)
for i in "${!TEST_COUNTS[@]}"; do
  SED_ARGS+=( -e "s/{{C${i}}}/${TEST_COUNTS[$i]}/g" )
done
sed -i "${SED_ARGS[@]}" "${PR_DESC}"

# Jede portierte Datei muss im Text mit ihrer Zahl auftauchen. Der Test nennt
# sonst eine Zusage ohne Beleg — genau der Fehler, den S22 gerade behebt.
for i in "${!TARGET_TESTS[@]}"; do
  grep -q "${TARGET_TESTS[$i]}\` | ${TEST_COUNTS[$i]}" "${PR_DESC}" || \
    DIE "PR-Beschreibung fuehrt ${TARGET_TESTS[$i]} nicht mit ${TEST_COUNTS[$i]} Testmethoden auf"
done

# Kein Platzhalter darf stehenbleiben, und die Testzahl muss drin sein.
grep -q '{{' "${PR_DESC}" && DIE "PR-Beschreibung enthaelt unersetzte Platzhalter"
grep -q "${TEST_TOTAL} Testmethoden" "${PR_DESC}" || \
  DIE "PR-Beschreibung nennt die Testzahl ${TEST_TOTAL} nicht"

# ---------------------------------------------------------------------------
# 6. Discussion-Kommentar (#7238)
#
# Kurz, konkret, ohne Marktschreier-Ton. Zwei Zusagen: ein Feldtyp als
# Bauform-Vorschlag und die Bereitschaft, ihn umzubauen, wenn die
# Maintainer die Erweiterung des bestehenden `repeatable`-Mechanismus
# bevorzugen. Die Bauform-Frage ist die eigentliche Entscheidung, die der
# PR nicht selbst treffen kann.
# ---------------------------------------------------------------------------
cat > "${OUT_DIR}/DISCUSSION-KOMMENTAR.md" <<'DISCUSSION'
# Kommentar-Entwurf fuer Discussion #7238

> Vor dem Posten pruefen: `<REPO-URL>` am Ende durch den Repo-Link ersetzen.

---

Ich habe die Anfrage aus diesem Thread und aus Issue #109 zum Anlass
genommen, einen Repeater-Feldtyp zu bauen und als PR vorzubereiten:
wiederholbare Feldgruppen, Sub-Felder konfigurierbar, Werte als JSON.

Zur Bauform habe ich eine Frage, die ich nicht selbst entscheiden will:

**Ein eigener Feldtyp** (was ich gebaut habe) oder **eine Erweiterung des
bestehenden `repeatable`-Mechanismus**? Mein Feldtyp bringt die Wiederholung
komplett selbst mit — `input()`, `pre_save()`, `value()`, dazu ein eigenes
Admin-Skript. Das laesst den Kern unangetastet, kostet aber eine zweite
Stelle, an der Zeilenverwaltung implementiert ist. Wenn ihr den bestehenden
Mechanismus erweitern wollt, ist mein Code die halbe Arbeit dafuer: die
Feldklasse, die Speicherlogik und die Tests bleiben brauchbar, das
Admin-Skript geht in die vorhandene Loesung auf.

Was der PR mitbringt:

- Additiv, keine Aenderung an bestehenden Kern-Dateien. Der Typ wird ueber
  `PodsForm::register_field_type()` registriert wie jeder andere Feldtyp.
- Sub-Felder rendern durch `PodsForm::field()`, also mit den Sanitizern und
  Optionen des Kerns.
- {{TOTAL}} Testmethoden in {{TFILES}} Dateien gegen Pods 3.3.9.1:
  Limit-Invariante, Feldvertrag, Kern-Integration (Registrierung,
  Konventionspfad, Save-Vertrag) und die serverseitige Ablehnung
  verschachtelter Repeater. Keine Fixtures, kein Netzwerk.
- Admin-UI im echten Browser geprueft: 18 Schritte, 18 PASS (Lauf 4) —
  Zeile anlegen, Limit greift, verschieben, loeschen, speichern, Werte nach
  dem Neuladen korrekt; der Feldgruppen-Pfad laeuft mit, ohne Inline-JSON.

Wichtig fuer die Einordnung: der Typ gehoert **nicht** in
`pods_repeatable_field_types`. Steht er dort, rendert der generische
Renderer statt meiner `input()`, und das Feld zerfaellt zu einem einzelnen
Eingabefeld. Die eigene Wiederholung ist gerade der Punkt.

Repo: <REPO-URL>

Ich passe den PR gern an die Bauform an, die ihr fuer richtig haltet — auch
wenn das heisst, ihn auf den bestehenden Mechanismus umzuschreiben. Ein
kurzes „so" oder „anders" reicht mir als Richtung.
DISCUSSION

# ---------------------------------------------------------------------------
# 7. Abnahme-Checkliste fuer den Auftraggeber
#
# Der Sprint reicht nicht ein. Diese Liste ist die Entscheidungsvorlage:
# jeder Punkt ist eine Entscheidung, nicht eine Aufgabe.
# ---------------------------------------------------------------------------
cat > "${OUT_DIR}/CHECKLISTE.md" <<'CHECKLISTE'
# Checkliste vor dem Einreichen

Der Sprint stoppt hier bewusst: kein `git push`, kein `gh pr create`, keine
Nachricht an Dritte. Alles darunter ist eine Entscheidung des Auftraggebers.

## 1. Muss entschieden werden

- [ ] **Repo-Name und Link.** Der Platzhalter `<REPO-URL>` im
      Discussion-Kommentar muss durch das oeffentliche Repo ersetzt werden.
      Gibt es keins, faellt der Verweis weg — dann traegt der Kommentar nur
      die Beschreibung, und der PR braucht einen Fork.
- [ ] **Fork oder Branch.** Ziel ist `pods-framework/pods`. Der PR kommt aus
      einem Fork, weil das Arbeitsrepo kein Remote hat.
- [ ] **Reihenfolge: erst Kommentar, dann PR?** Empfehlung: erst der
      Kommentar in #7238, zwei bis drei Tage warten, dann der PR mit dem
      Verweis auf die Antwort. Ein PR ohne Vorlauf in einem alten Thread
      wird eher geschlossen als gelesen.
- [ ] **Screenshots beilegen?** Die Admin-UI ist visuell; ein Bild des
      Repeaters mit zwei Zeilen im Editor hilft mehr als drei Absaetze. Es
      liegen zwei passende Dateien aus dem E2E-Lauf vor, beide gitignored und
      nicht im Paket:
      `dist/e2e/gruppe-nach-neuladen.png` (Feldgruppen-Pfad, aus
      `node scripts/e2e-group-path.mjs`) und `dist/e2e/nach-neuladen.png`
      (Inline-JSON-Pfad, aus `node scripts/e2e-admin-ui.mjs`). Bei Bedarf vor
      dem PR neu erzeugen — der Lauf schreibt sie ohnehin neu.
- [ ] **Lizenzheader.** Feldklasse und Skript tragen den GPL-2.0-or-later-
      Header des Plugins. Vor dem PR gegen die Kopfzeilen bestehender
      Kern-Felddateien abgleichen (Jahr, Copyright-Inhaber).
- [ ] **Versionsnummer im Kern.** Dieses Paket traegt `0.2.1` als Plugin-
      Version und im Changelog-Eintrag `= 3.3.10 =` fuer Pods. Vor dem PR
      pruefen, welche Nummer der Kern als naechste fuehrt — die Zahl im
      Changelog-Block ist eine Annahme, nicht gemessen.
- [ ] **Ob der Fix in 0.2.1 oeffentlich auftauchen soll.** Der Eintrag
      „Fixed: field groups admin form posted to `admin.php`" beschreibt einen
      Fehler, der nie veroeffentlicht war (0.2.0 war die letzte Fassung mit
      dem Defekt). Wer den Fix nicht als Bugfix in der Versionshistorie
      stehen haben will, streicht die Zeile aus `readme.txt` — die
      Versionsnummer bleibt davon unberuehrt.

## 2. Vor dem Absenden technisch pruefen

- [ ] `bash scripts/build-pr-package.sh && bash scripts/verify-pr-package.sh`
      — muss durchlaufen, Ausgabe ohne `FEHLER`.
- [ ] `php -l` auf den vier portierten Testdateien und der Feldklasse
      (im Container, nicht auf dem Host).
- [ ] Testzahlen in `PR-BESCHREIBUNG.md` gegen den eigenen Suite-Lauf
      abgleichen (der Generator setzt sie beim Bauen ein).
- [ ] `grep -c 'pods-repeater' dist/pods-pr/classes/fields/repeater.php` —
      muss 0 sein (nur `pods-*`-CSS-Klassen duerfen bleiben, sie tragen
      keinen Domain-Suffix).
- [ ] Gruppenmarke pruefen: die portierten Fassungen von Vertrag,
      Kern-Integration und Nested-Ablehnung tragen `@group podscore`. Im
      Plugin-Repo laufen sie in der Standard-Suite mit; im Pods-Repo wuerde die
      Suite ohne die Marke rot, weil die Feldklasse dort erst mit dem PR
      existiert.
- [ ] Nested-Ablehnung gegenlesen: `tests/wpunit/PodsRepeaterNestedTest.php`
      haelt fest, dass `validate()` einen Sub-Typ `repeater` meldet (Meldung
      mit Sub-Feld-Label, vor Limit und `required`) und `pre_save()` die
      verschachtelten Werte verwirft. Die zwei Gegenproben (legitime Felder
      bleiben gueltig) gehoeren dazu — sie sind der Beleg, dass der Guard nicht
      zu breit greift.

## 3. Was im Paket bewusst fehlt

- React-DFV-Editor fuer die Sub-Feld-Konfiguration — Konfiguration ist
  zunaechst JSON bzw. eine Feldgruppe.
- Die Feldgruppen-Registry — Plugin-Konzept, im Kern nicht enthalten. Der
  Typ faellt per `function_exists()` sauber auf Inline-JSON zurueck (Test 15
  haelt den Zweig fest: bei fehlender Funktion kommt ein leerer Hinweis, kein
  Fatal).
- `uninstall.php` und `languages/` — Plugin-Haushalt (Opt-in-Cleanup zweier
  Optionen, POT-Datei). Im Kern deinstalliert man kein Feld, und die
  Strings laufen dort ueber die Kern-Textdomain `pods`. Aufschluesselung
  (gemessen mit `python3 scripts/s20-i18n-count.py`): 33 i18n-Aufrufe mit
  Domain `pods-repeater` in der Quelle, im Paket 33 mit Domain `pods`;
  35 Literale `pods-repeater` in der Quelle, 34 `pods`-Literale im Paket
  (eines davon im Datei-Kommentar), 0 verbleibende `pods-repeater`-Literale;
  dazu 13 CSS-Klassen `pods-repeater-*`, die als `pods-*` mitportiert werden.
  `grep -c` zaehlt hier Zeilen, nicht Aufrufe — 31 Zeilen mit 34 Literalen.

## 4. Nach dem Einreichen

- [ ] Antworten im Thread beobachten; die Bauform-Frage ist gestellt und
      braucht eine Antwort, bevor Review-Arbeit sinnvoll ist.
- [ ] Bei Aenderungswunschen: Testsuite bleibt der Massstab, keine
      Assertion abschwaechen, um Review-Kommentare zu erfuellen.
CHECKLISTE

# Platzhalter einsetzen. Das Heredoc ist bewusst quoted ('DISCUSSION'), damit
# Backticks und ${...} im Text stehen bleiben — ein unquoted Heredoc haette
# die Inline-Code-Markierungen als Command-Substitution ausgefuehrt und
# jeden Backtick-Ausdruck durch eine leere Zeichenkette ersetzt. Weil das
# quoted ist, muessen die Platzhalter hier separat ersetzt werden; der
# frueher fest eingetragene Wert "33" ist genau daran veraltet stehen
# geblieben.
DISCUSSION_DOC="${OUT_DIR}/DISCUSSION-KOMMENTAR.md"
sed -i \
  -e "s/{{TOTAL}}/${TEST_TOTAL}/g" \
  -e "s/{{TFILES}}/${TEST_FILES}/g" \
  "${DISCUSSION_DOC}"

grep -q '{{' "${DISCUSSION_DOC}" && DIE "Discussion-Kommentar enthaelt unersetzte Platzhalter"
grep -q "${TEST_TOTAL} Testmethoden" "${DISCUSSION_DOC}" || \
  DIE "Discussion-Kommentar nennt die Testzahl ${TEST_TOTAL} nicht"

# ---------------------------------------------------------------------------
# 8. Selbstpruefung: alle Artefakte vorhanden und nicht leer
# ---------------------------------------------------------------------------
for artifact in "${FIELD_OUT}" "${JS_OUT}" \
                "${OUT_DIR}/tests/wpunit/PodsRepeaterTest.php" \
                "${OUT_DIR}/tests/wpunit/PodsRepeaterContractTest.php" \
                "${OUT_DIR}/tests/wpunit/PodsRepeaterCoreIntegrationTest.php" \
                "${OUT_DIR}/tests/wpunit/PodsRepeaterNestedTest.php" \
                "${OUT_DIR}/readme-changelog.txt" \
                "${OUT_DIR}/PR-BESCHREIBUNG.md" \
                "${OUT_DIR}/DISCUSSION-KOMMENTAR.md" \
                "${OUT_DIR}/CHECKLISTE.md"; do
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
printf '  ui/js/repeater.js                 %5s Zeilen\n' \
  "$(wc -l < "${JS_OUT}" | tr -d ' ')"
for i in "${!SOURCE_TESTS[@]}"; do
  source_test="${SOURCE_TESTS[$i]}"
  target_name="${TARGET_TESTS[$i]}"
  test_out="${OUT_DIR}/tests/wpunit/${target_name}"
  printf '  tests/wpunit/%-38s %5s Zeilen (Quelle: %s), %2s Tests\n' \
    "${target_name}" \
    "$(wc -l < "${test_out}" | tr -d ' ')" \
    "$(wc -l < "${source_test}" | tr -d ' ')" \
    "${TEST_COUNTS[$i]}"
done
printf '  Testmethoden gesamt               %5s\n' "${TEST_TOTAL}"
printf '  Textdomain-Fundstellen            %5s\n' "${DOMAIN_HITS}"
printf '  Artefakte                         %5s\n' \
  "$(find "${OUT_DIR}" -maxdepth 1 -type f | wc -l | tr -d ' ')"
