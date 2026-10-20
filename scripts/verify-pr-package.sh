#!/usr/bin/env bash
#
# verify-pr-package.sh — Reproduzierbarkeitstest fuer das PR-Paket.
#
# Baut dist/pods-pr zweimal unabhaengig (zwei getrennte Ziele unter einem
# mktemp -d), vergleicht die sha256summen aller Dateien und meldet jede
# Abweichung namentlich. Die echte Ausgabe dist/ wird NICHT angefasst.
#
#   bash scripts/verify-pr-package.sh      # Exit 0 = identisch, 1 = Abweichung
#
# Geprueft wird:
#   1. beide Laeufe erfolgreich
#   2. gleiche Dateiliste (relativ, sortiert)
#   3. gleiche Inhalte je Datei
#   4. gleiche Dateirechte je Datei (Executable-Bit ist Teil der Ausgabe)
#   5. Inhaltliche Mindestpruefung (seit S15):
#      - alle erwarteten Artefakte vorhanden und nicht leer
#      - Testmethoden portiert == Testmethoden in der Quelle (je Datei)
#      - Summe >= 40 (10+19+4+7), nicht nur "irgendwas portiert"
#      - Nested-Zusage im Text nur mit dem portierten Nested-Test daneben
#      - Felddatei ohne 'pods-repeater', JS-Skript ohne 'pods-repeater-'
#      - PR-Beschreibung, Discussion-Kommentar und Checkliste nicht leer
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
BUILD="${SCRIPT_DIR}/build-pr-package.sh"

DIE() { printf 'FEHLER: %s\n' "$*" >&2; exit 1; }

[[ -f "${BUILD}" ]] || DIE "build-pr-package.sh fehlt: ${BUILD}"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/verify-pr-package.XXXXXX")" || DIE "mktemp -d fehlgeschlagen"
cleanup() { rm -rf "${WORK}"; }
trap cleanup EXIT

RUN1="${WORK}/lauf1"
RUN2="${WORK}/lauf2"

# Manifest: "<sha256>  <pfad>" + Rechte, sortiert nach Pfad.
manifest() {
  local dir="$1"
  (
    cd "${dir}" || exit 1
    find . -type f -print0 \
      | sort -z \
      | while IFS= read -r -d '' f; do
          printf '%s %s %s\n' \
            "$(sha256sum "$f" | cut -d' ' -f1)" "$(stat -c '%a' "$f")" "${f#./}"
        done
  )
}

for run in 1 2; do
  target="${WORK}/lauf${run}"
  mkdir -p "${target}"
  printf 'Lauf %s: baue nach %s\n' "${run}" "${target}"
  if ! PR_OUT_DIR="${target}" bash "${BUILD}" >"${WORK}/build${run}.log" 2>&1; then
    sed 's/^/  /' "${WORK}/build${run}.log" >&2
    DIE "Build in Lauf ${run} fehlgeschlagen (Log oben)"
  fi
done

# Leerer Build waere ein trivialer "Erfolg" — das ist kein Nachweis.
for run in 1 2; do
  count="$(find "${WORK}/lauf${run}" -type f | wc -l | tr -d ' ')"
  [[ "${count}" -gt 0 ]] || DIE "Lauf ${run} hat keine Dateien erzeugt"
done

manifest "${RUN1}" >"${WORK}/manifest1"
manifest "${RUN2}" >"${WORK}/manifest2"

# ---------------------------------------------------------------------------
# Inhaltliche Mindestpruefung — laeuft auf Lauf 1.
#
# Der Reproduzierbarkeitstest allein sagt nichts ueber den Inhalt: ein Build,
# der zweimal dieselbe halbe Datei erzeugt, waere "reproduzierbar". Darum
# hier die Zusagen, die der PR macht, hart nachpruefen.
# ---------------------------------------------------------------------------
CHECK_DIR="${RUN1}"

check_artifacts() {
  local missing=0
  local expected=(
    "classes/fields/repeater.php"
    "ui/js/repeater.js"
    "tests/wpunit/PodsRepeaterTest.php"
    "tests/wpunit/PodsRepeaterContractTest.php"
    "tests/wpunit/PodsRepeaterCoreIntegrationTest.php"
    "tests/wpunit/PodsRepeaterNestedTest.php"
    "readme-changelog.txt"
    "PR-BESCHREIBUNG.md"
    "DISCUSSION-KOMMENTAR.md"
    "CHECKLISTE.md"
  )
  for rel in "${expected[@]}"; do
    if [[ ! -s "${CHECK_DIR}/${rel}" ]]; then
      printf '  fehlt oder leer: %s\n' "${rel}" >&2
      missing=$((missing + 1))
    fi
  done
  [[ "${missing}" -eq 0 ]] || DIE "${missing} Artefakt(e) fehlen oder sind leer in ${CHECK_DIR}"
}

check_test_counts() {
  local pairs=(
    "tests/RepeaterLimitTest.php:tests/wpunit/PodsRepeaterTest.php"
    "tests/RepeaterContractTest.php:tests/wpunit/PodsRepeaterContractTest.php"
    "tests/CoreIntegrationTest.php:tests/wpunit/PodsRepeaterCoreIntegrationTest.php"
    "tests/RepeaterNestedTest.php:tests/wpunit/PodsRepeaterNestedTest.php"
  )
  local total=0
  for pair in "${pairs[@]}"; do
    local source="${REPO_ROOT}/${pair%%:*}"
    local ported="${CHECK_DIR}/${pair##*:}"
    local source_count ported_count

    source_count="$(grep -c 'public function test_' "${source}" || true)"
    ported_count="$(grep -c 'public function test_' "${ported}" || true)"

    [[ "${source_count}" -eq "${ported_count}" ]] || \
      DIE "${pair##*:}: ${ported_count} Tests portiert, Quelle ${pair%%:*} hat ${source_count}"
    [[ "${ported_count}" -gt 0 ]] || DIE "${pair##*:}: keine Testmethoden portiert"

    printf '  Tests %-42s %2s (Quelle: %s)\n' "${pair##*:}" "${ported_count}" "${source_count}"
    total=$((total + ported_count))
  done
  printf '  Tests gesamt%-42s %2s\n' "" "${total}"
  # Untergrenze 40 = 10+19+4+7. Die Summe wird hier nachgezaehlt, nicht aus der
  # Quelle uebernommen: faellt eine Datei aus der Portierung, muss der Lauf
  # rot werden und nicht still kleiner.
  [[ "${total}" -ge 40 ]] || DIE "Nur ${total} Testmethoden im Paket (erwartet mindestens 40: 10+19+4+7)"
}

check_porting_residue() {
  local field="${CHECK_DIR}/classes/fields/repeater.php"
  local js="${CHECK_DIR}/ui/js/repeater.js"

  local field_hits
  field_hits="$(grep -c 'pods-repeater' "${field}" || true)"
  [[ "${field_hits}" -eq 0 ]] || \
    DIE "Felddatei enthaelt noch ${field_hits} Treffer 'pods-repeater' (Textdomain or Plugin-Prefix)"

  # Im JS sind die Klassen pods-* erlaubt; 'pods-repeater-' waere ein
  # Rest des Plugin-Namensraums.
  local js_hits
  js_hits="$(grep -c 'pods-repeater-' "${js}" || true)"
  [[ "${js_hits}" -eq 0 ]] || \
    DIE "Admin-Skript enthaelt noch ${js_hits} Treffer 'pods-repeater-'"

  printf '  Portierungsreste Felddatei/JS%s %s/%s\n' "" "${field_hits}" "${js_hits}"
}

check_pr_text() {
  local desc="${CHECK_DIR}/PR-BESCHREIBUNG.md"

  # Die drei Pflichtabschnitte aus dem Sprint-Vertrag.
  grep -q 'pods_repeatable_field_types' "${desc}" || \
    DIE "PR-Beschreibung nennt pods_repeatable_field_types nicht"
  grep -qi 'integrationsbeleg' "${desc}" || \
    DIE "PR-Beschreibung hat keinen Integrationsbeleg aus S13"
  grep -q 'Maintenance' "${desc}" || \
    DIE "PR-Beschreibung enthaelt kein Maintenance-Angebot"
  grep -qi 'Nicht enthalten' "${desc}" || \
    DIE "PR-Beschreibung nennt nicht, was bewusst fehlt"

  # Die Nested-Zusage nennt die Checkliste; dort steht die Kern-Voraussetzung
  # (Gruppenmarke). Beides muss zusammenpassen: der Verweis auf eine Datei,
  # die den Test nicht kennt, ist eine Behauptung ohne Beleg.
  grep -q "PodsRepeaterNestedTest.php" "${CHECK_DIR}/CHECKLISTE.md" || \
    DIE "Checkliste nennt den Nested-Test nicht — die Durchsicht vor dem Einreichen kennt ihn dann nicht"

  # Die Testzahl muss aus dem Build stammen, nicht aus dem Kopf: die
  # Beschreibung muss die Gesamtzahl der portierten Tests enthalten.
  local total
  total="$(grep -c 'public function test_' "${CHECK_DIR}/tests/wpunit/PodsRepeaterTest.php" || true)"
  total=$((total \
    + $(grep -c 'public function test_' "${CHECK_DIR}/tests/wpunit/PodsRepeaterContractTest.php" || true) \
    + $(grep -c 'public function test_' "${CHECK_DIR}/tests/wpunit/PodsRepeaterCoreIntegrationTest.php" || true) \
    + $(grep -c 'public function test_' "${CHECK_DIR}/tests/wpunit/PodsRepeaterNestedTest.php" || true) ))
  grep -q "${total} Testmethoden" "${desc}" || \
    DIE "PR-Beschreibung nennt nicht '${total} Testmethoden' (Zahl aus dem Build)"

  # Der Discussion-Kommentar ist der Text, den ein Fremder liest — die
  # Testzahl darin muss ebenfalls aus dem Build stammen. Sie stand dort bis
  # S21 fest als "33" im Heredoc und wanderte beim Testzuwachs nicht mit.
  grep -q "${total} Testmethoden" "${CHECK_DIR}/DISCUSSION-KOMMENTAR.md" || \
    DIE "Discussion-Kommentar nennt nicht '${total} Testmethoden' (Zahl aus dem Build)"

  # Die Nested-Zusage im Text braucht ihren Beleg im Paket: steht die Meldung
  # von validate() in der Beschreibung, muss der Test sie auch pruefen.
  # Den Satz zur Ablehnung woertlich treffen, nicht nur die Meldung: der
  # Meldungstext steht auch in der Code-Erlaeuterung (Z. ~143), der Satz
  # "Verschachtelte Repeater werden serverseitig abgelehnt" ist die Zusage.
  if grep -qi 'verschachtelte Repeater werden serverseitig abgelehnt' "${desc}"; then
    grep -q "Nested repeaters are not supported" "${CHECK_DIR}/tests/wpunit/PodsRepeaterNestedTest.php" || \
      DIE "PR-Beschreibung behauptet die Nested-Ablehnung, der portierte Test prueft die Meldung nicht"
    grep -q "PodsRepeaterNestedTest" "${desc}" || \
      DIE "PR-Beschreibung behauptet die Nested-Ablehnung ohne den zugehoerigen Test zu nennen"
  fi

  # Kein Plugin-Praefix in der Beschreibung: die Feldklasse prueft im Kern
  # `repeater_get_groups()` / `repeater_get_group_fields()`. Stand hier
  # `pods_repeater_...`, beschrieb der PR einen Guard, den der Code nicht hat.
  if grep -n 'pods_repeater_[a-z_]*(' "${desc}"; then
    DIE "PR-Beschreibung nennt Plugin-Funktionen mit Praefix — im Kern heissen sie repeater_*"
  fi

  # Jede in der Beschreibung genannte Feldklassen-Funktion, die als
  # function_exists()-Zweig beschrieben wird, muss im portierten Code
  # tatsaechlich hinter einem Guard stehen. Sonst beschreibt der Text eine
  # Absicherung, die es nicht gibt.
  local func
  for func in repeater_get_groups repeater_get_group_fields; do
    if grep -q "${func}()" "${desc}"; then
      grep -q "function_exists( '${func}' )" "${CHECK_DIR}/classes/fields/repeater.php" || \
        DIE "Beschreibung verweist auf ${func}() als guarded, die Feldklasse hat den Guard nicht"
    fi
  done

  # Version: der Header ist die Wahrheit. Steht im PR-Text eine andere
  # Version, ist eine davon falsch.
  local header_version
  header_version="$(sed -n "s/.*\* Version:[[:space:]]*\([0-9][0-9.]*\).*/\1/p" "${REPO_ROOT}/pods-repeater.php" | head -1)"
  local readme_version
  readme_version="$(sed -n 's/^Stable tag:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' "${REPO_ROOT}/readme.txt" | head -1)"
  [[ -n "${header_version}" && -n "${readme_version}" ]] || \
    DIE "Version nicht lesbar (Header: '${header_version}', readme.txt: '${readme_version}')"
  [[ "${header_version}" == "${readme_version}" ]] || \
    DIE "Version auseinander: Header ${header_version}, readme.txt Stable tag ${readme_version}"
  printf '  Version Header/readme.txt%s %s/%s\n' "" "${header_version}" "${readme_version}"

  # Ein unquoted Heredoc haette die Backticks als Command-Substitution
  # ausgefuehrt und die Inline-Code-Begriffe durch Leerstrings ersetzt.
  # Indiz: eine Stelle wie "(, , ," oder "Typ  hinzu:" — zwei Leerzeichen
  # in Folge mitten im Fliesstext, oder ein Komma direkt nach einer Klammer.
  if grep -qE '\(, |, , |Typ {2,}[a-z]|alle {2,}\`' "${desc}"; then
    DIE "PR-Beschreibung hat leere Stellen — Backticks wurden ausgewertet (unquoted Heredoc?)"
  fi
  grep -q '{{' "${desc}" && DIE "PR-Beschreibung enthaelt unersetzte Platzhalter"

  printf '  PR-Beschreibung: Pflichtabschnitte vorhanden, Testzahl %s\n' "${total}"
}

printf 'Inhaltliche Pruefung (%s):\n' "${CHECK_DIR}"
check_artifacts
check_test_counts
check_porting_residue
check_pr_text

if diff -u "${WORK}/manifest1" "${WORK}/manifest2" >"${WORK}/diff.txt"; then
  files="$(wc -l <"${WORK}/manifest1" | tr -d ' ')"
  printf 'OK: PR-Paket reproduzierbar — %s Dateien, zwei Laeufe identisch\n' "${files}"
  while IFS= read -r line; do
    printf '  %s  %s\n' "$(printf '%s' "${line}" | cut -d' ' -f1)" \
      "$(printf '%s' "${line}" | cut -d' ' -f3)"
  done <"${WORK}/manifest1"
  exit 0
fi

# Abweichungen namentlich benennen: Pfade, die nur in einem Lauf vorkommen,
# plus Pfade mit abweichender Pruefsumme oder abweichenden Rechten.
declare -A H1=() H2=()
while read -r sum mode path; do H1["${path}"]="${sum} ${mode}"; done <"${WORK}/manifest1"
while read -r sum mode path; do H2["${path}"]="${sum} ${mode}"; done <"${WORK}/manifest2"

echo "FEHLER: PR-Paket nicht reproduzierbar" >&2
only1=0
only2=0
differ=0
while IFS= read -r path; do
  [[ -n "${path}" ]] || continue
  in1=0; in2=0
  [[ -n "${H1[${path}]:-}" ]] && in1=1
  [[ -n "${H2[${path}]:-}" ]] && in2=1
  if [[ ${in1} -eq 1 && ${in2} -eq 0 ]]; then
    echo "  nur in Lauf 1: ${path}" >&2; only1=$((only1 + 1))
  elif [[ ${in1} -eq 0 && ${in2} -eq 1 ]]; then
    echo "  nur in Lauf 2: ${path}" >&2; only2=$((only2 + 1))
  elif [[ ${H1[${path}]} != "${H2[${path}]}" ]]; then
    echo "  abweichend:    ${path}" >&2
    echo "    Lauf 1: ${H1[${path}]}" >&2
    echo "    Lauf 2: ${H2[${path}]}" >&2
    differ=$((differ + 1))
  fi
done < <(printf '%s\n' "${!H1[@]}" "${!H2[@]}" | sort -u)

printf '  Summe: %s nur Lauf 1, %s nur Lauf 2, %s abweichend\n' \
  "${only1}" "${only2}" "${differ}" >&2
printf '  Diff (Manifest):\n' >&2
sed 's/^/    /' "${WORK}/diff.txt" >&2
exit 1
