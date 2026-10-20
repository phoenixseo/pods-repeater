#!/usr/bin/env bash
#
# build-release-package.sh — baut das installierbare Alpha-Paket.
#
# Ergebnis:
#   dist/release/pods-repeater-<version>.zip   Upload-fertig fuer
#                                              "Plugins -> Hochladen"
#   dist/release/pods-repeater-<version>.manifest.txt
#                                              Dateiliste aus dem
#                                              tatsaechlich gebauten Zip
#
# Die Version steht NIRGENDS in diesem Skript. Sie wird zur Laufzeit aus dem
# gebauten Zip gelesen (Header "Version:") und mit "Stable tag:" aus dem
# Wurzel-readme verglichen. Laufen die beiden auseinander, bricht der Build
# ab. Eine handgepflegte Version im Skript waere eine dritte Stelle, die
# still veralten kann.
#
# Warum nicht `git archive` / `zip`:
#   - `zip -r .` muesste jeden Pfad einzeln ausschliessen; die Allowlist ist
#     kuerzer und erlaubt den Fehler nur in die sichere Richtung (eine neue
#     Datei fehlt im Paket, statt dass sie versehentlich mitgeht).
#   - zip/unzip sind auf dem Host nicht installiert.
# Darum: Allowlist pro Verzeichnis und pro Datei; gebaut wird mit ZipArchive
# in scripts/release-package.php.
#
# Der Bau braucht nur PHP mit ZipArchive, keinen laufenden Webserver: `php`
# lokal, wenn vorhanden, sonst `docker exec` in den wp-env-CLI-Container
# (dort ist PHP da, wo das Plugin ohnehin laeuft). Damit laeuft dasselbe
# Skript auf dem Host und in CI.
#
# Eigenschaften:
#   - baut nicht, wenn Version/Header/Stable tag/readme im Zip fehlen
#   - baut nicht, wenn das Zip ein verbotenes Verzeichnis enthaelt
#   - leert dist/release/ vorher (idempotent)
#   - kein Netzwerk
#
# Aufruf (Host): bash scripts/build-release-package.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
OUT_DIR="${REPO_ROOT}/dist/release"
CONTAINER="${PODS_REPEATER_CONTAINER:-wp-env-pods-repeater-54d58dae-cli-1}"
HELPER="scripts/release-package.php"

# Ordner mit Angabe, ob rekursiv (1) oder nur die erste Ebene (0).
# ui/ und classes/ und includes/ sind rekursiv: dort liegt die eigentliche
# Feldlogik in Unterordnern.
DIRS=(
  "classes:1"
  "includes:1"
  "ui:1"
  "languages:0"
)
# Dateien. README.md (Entwickler-Sicht) und readme.txt (WordPress-Sicht) sind
# beide drin: der eine liest im Browser, der andere im Plugin-Screen. Fehlt
# README.md, ist das Paket fuer einen Tester halb blind.
FILES=(
  "pods-repeater.php"
  "uninstall.php"
  "readme.txt"
  "README.md"
)

DIE() { printf 'FEHLER: %s\n' "$*" >&2; exit 1; }
NOTE() { printf '  %s\n' "$*"; }

# ---------------------------------------------------------------------------
# 0. Vorbedingungen
# ---------------------------------------------------------------------------
[[ -f "${REPO_ROOT}/pods-repeater.php" ]] || DIE "pods-repeater.php fehlt in ${REPO_ROOT}"
[[ -f "${REPO_ROOT}/readme.txt" ]] || DIE "readme.txt fehlt in ${REPO_ROOT}"
[[ -f "${REPO_ROOT}/${HELPER}" ]] || DIE "${HELPER} fehlt"
for eintrag in "${FILES[@]}"; do
  [[ -f "${REPO_ROOT}/${eintrag}" ]] || DIE "Datei fehlt: ${eintrag}"
done
for eintrag in "${DIRS[@]}"; do
  dir="${eintrag%%:*}"
  [[ -d "${REPO_ROOT}/${dir}" ]] || DIE "Verzeichnis fehlt: ${dir}"
done

# PHP-Runner: lokal, wenn php mit ZipArchive da ist; sonst der Container.
# Alle Pfade bleiben relativ zur Repo-Wurzel, damit beide Wege identisch sind
# und keine Pfaduebersetzung noetig wird.
PHP_MODE=""
if command -v php >/dev/null 2>&1 && php -r 'exit(class_exists("ZipArchive") ? 0 : 1);' >/dev/null 2>&1; then
  PHP_MODE="lokal"
elif command -v docker >/dev/null 2>&1 \
  && docker inspect -f '{{.State.Running}}' "${CONTAINER}" 2>/dev/null | grep -q true; then
  PHP_MODE="container:${CONTAINER}"
else
  DIE "kein PHP mit ZipArchive und kein laufender Container ${CONTAINER} — php-zip installieren oder wp-env starten"
fi

# Im Container ist das Repo als wp-content/plugins/pods-repeater gemountet,
# NICHT unter dem Host-Pfad. Arbeitsverzeichnis deshalb aus dem Mount lesen,
# statt einen Pfad zu raten.
CONTAINER_ROOT=""
if [[ "${PHP_MODE}" == container:* ]]; then
  CONTAINER_ROOT="$(docker inspect -f \
    '{{range .Mounts}}{{if eq .Destination "/var/www/html/wp-content/plugins/pods-repeater"}}{{.Destination}}{{end}}{{end}}' \
    "${CONTAINER}")"
  [[ -n "${CONTAINER_ROOT}" ]] || \
    DIE "Container ${CONTAINER} hat kein Mount auf wp-content/plugins/pods-repeater"
  docker exec "${CONTAINER}" test -f "${CONTAINER_ROOT}/pods-repeater.php" || \
    DIE "${CONTAINER_ROOT} ist im Container ${CONTAINER} nicht lesbar"
fi

# Ein Aufruf, zwei Umgebungen.
pr_php() {
  if [[ "${PHP_MODE}" == "lokal" ]]; then
    ( cd "${REPO_ROOT}" && php "${HELPER}" "$@" )
  else
    docker exec -w "${CONTAINER_ROOT}" "${CONTAINER}" php "${HELPER}" "$@"
  fi
}

# ---------------------------------------------------------------------------
# 1. Versionen vor dem Bauen vergleichen
#
# Lieber hier abbrechen als ein Zip zu schreiben, dessen Header eine andere
# Version traegt als der Changelog-Eintrag. Genau das ist der Fehler, den ein
# Tester als erstes sieht ("installiert 0.2.1, readme sagt 0.3.0").
# ---------------------------------------------------------------------------
HEADER_VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "${REPO_ROOT}/pods-repeater.php" | head -n1)"
STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' "${REPO_ROOT}/readme.txt" | head -n1)"

[[ -n "${HEADER_VERSION}" ]] || DIE "Plugin-Header ohne Version:"
[[ -n "${STABLE_TAG}" ]] || DIE "readme.txt ohne Stable tag:"
[[ "${HEADER_VERSION}" == "${STABLE_TAG}" ]] || \
  DIE "Version weicht ab: Header ${HEADER_VERSION}, Stable tag ${STABLE_TAG}"

# Die Konstante fuehrt die Version im Code mit. Zwei Stellen, ein Wert.
CONST_VERSION="$(sed -n "s/^define( 'PODS_REPEATER_VERSION', '\([^']*\)' );$/\1/p" "${REPO_ROOT}/pods-repeater.php" | head -n1)"
[[ -n "${CONST_VERSION}" ]] || DIE "PODS_REPEATER_VERSION nicht gefunden"
[[ "${CONST_VERSION}" == "${HEADER_VERSION}" ]] || \
  DIE "PODS_REPEATER_VERSION ${CONST_VERSION} weicht vom Header ${HEADER_VERSION} ab"

# Der Changelog muss den Eintrag fuehren, sonst ist das Paket undokumentiert.
grep -q "^= ${HEADER_VERSION} =$" "${REPO_ROOT}/readme.txt" || \
  DIE "readme.txt hat keinen Changelog-Eintrag '= ${HEADER_VERSION} ='"

VERSION="${HEADER_VERSION}"
REL_ZIP="dist/release/pods-repeater-${VERSION}.zip"
REL_MANIFEST="dist/release/pods-repeater-${VERSION}.manifest.txt"
ZIP_PATH="${REPO_ROOT}/${REL_ZIP}"
MANIFEST="${REPO_ROOT}/${REL_MANIFEST}"

printf 'Baue pods-repeater %s\n' "${VERSION}"
printf '  Host:  %s\n' "${REPO_ROOT}"
printf '  PHP:   %s\n' "${PHP_MODE}"

# ---------------------------------------------------------------------------
# 2. Zip bauen
#
# Aus dem Arbeitsstand, aber nur aus den Pfaden der Allowlist. Die mtimes der
# Dateien landen im Zip (PHP 8.3+), damit zwei Laeufe mit identischem Inhalt
# dasselbe Paket ergeben.
# ---------------------------------------------------------------------------
mkdir -p "${OUT_DIR}"
rm -f "${ZIP_PATH}" "${MANIFEST}"

DIRS_SPEC="$(printf '%s;' "${DIRS[@]}")"
FILES_SPEC="$(printf '%s;' "${FILES[@]}")"

pr_php build \
  --zip "${REL_ZIP}" \
  --manifest "${REL_MANIFEST}" \
  --dirs "${DIRS_SPEC}" \
  --files "${FILES_SPEC}" \
  || DIE "Zip-Bau fehlgeschlagen"

[[ -f "${ZIP_PATH}" ]] || DIE "Zip wurde nicht geschrieben: ${ZIP_PATH}"
[[ -s "${MANIFEST}" ]] || DIE "Manifest ist leer"

# ---------------------------------------------------------------------------
# 3. Installationsanleitung dazulegen
#
# Die Anleitung ist Inhalt, nicht Build-Output: sie liegt als INSTALL.md im
# Repo und wird hier nur kopiert. Damit ist sie im Git reviewbar (und in CI
# vorhanden), steht aber im Release-Ordner neben dem Zip, wo ein Tester sie
# sucht. Der Test prueft die Kopie und ihren Bezug auf den Dateinamen — beim
# Versionsbump faellt eine vergessene Anleitung dort auf, nicht beim Tester.
# ---------------------------------------------------------------------------
cp "${REPO_ROOT}/INSTALL.md" "${OUT_DIR}/INSTALL.md"
[[ -s "${OUT_DIR}/INSTALL.md" ]] || DIE "INSTALL.md nicht kopiert"

# ---------------------------------------------------------------------------
# 4. Selbstpruefung gegen die Zusagen der Karte
# ---------------------------------------------------------------------------
MENGE="$(wc -c < "${ZIP_PATH}" | tr -d ' ')"
[[ "${MENGE}" -gt 20480 ]] || DIE "Zip ist ${MENGE} Bytes, erwartet > 20 KB"

for pflicht in \
  "pods-repeater/pods-repeater.php" \
  "pods-repeater/classes/fields/repeater.php" \
  "pods-repeater/ui/js/repeater.js" \
  "pods-repeater/readme.txt" \
  "pods-repeater/uninstall.php"; do
  grep -qxF "${pflicht}" "${MANIFEST}" || DIE "Pflichtdatei fehlt im Zip: ${pflicht}"
done

# Erster Eintrag muss der Plugin-Einstieg sein, sonst entpackt das Zip flach
# oder WordPress findet den Header nicht am erwarteten Ort.
FIRST="$(head -n1 "${MANIFEST}")"
[[ "${FIRST}" == "pods-repeater/pods-repeater.php" ]] || \
  DIE "Erster Zip-Eintrag ist '${FIRST}', erwartet 'pods-repeater/pods-repeater.php'"

# Verbotene Pfade. Ein vendor/ im Zip waere 10+ MB toter Ballast, ein tests/
# oder dist/ waere ein Leck des Arbeitsstands in ein Paket, das Fremde sehen.
for verboten in "vendor/" "tests/" "dist/" "/.git/" "node_modules/" "scripts/" \
                "composer.json" "composer.lock" ".wp-env" "phpunit.xml" "assets/"; do
  if grep -qF -- "${verboten}" "${MANIFEST}"; then
    DIE "verbotener Pfad im Zip: ${verboten}"
  fi
done

# Jede Zeile muss unter pods-repeater/ liegen — kein Eintrag auf oberster
# Ebene, der beim Entpacken neben das Plugin-Verzeichnis faellt.
FREMD="$(grep -vc '^pods-repeater/' "${MANIFEST}" || true)"
[[ "${FREMD}" -eq 0 ]] || DIE "${FREMD} Eintraege liegen ausserhalb von pods-repeater/"

# Der Header im Zip muss die Version tragen, die der Dateiname behauptet.
# Gelesen aus dem Zip, nicht aus der Arbeitskopie: das ist der Punkt, an dem
# ein vergessener Bump auffliegt.
ZIP_VERSION="$(pr_php get --zip "${REL_ZIP}" --entry pods-repeater/pods-repeater.php \
  | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -n1)"
[[ "${ZIP_VERSION}" == "${VERSION}" ]] || \
  DIE "Header im Zip sagt ${ZIP_VERSION}, Dateiname sagt ${VERSION}"

ZIP_PHP="$(pr_php get --zip "${REL_ZIP}" --entry pods-repeater/pods-repeater.php \
  | sed -n 's/^ \* Requires PHP:[[:space:]]*\(.*\)$/\1/p' | head -n1)"
ZIP_WP="$(pr_php get --zip "${REL_ZIP}" --entry pods-repeater/pods-repeater.php \
  | sed -n 's/^ \* Requires at least:[[:space:]]*\(.*\)$/\1/p' | head -n1)"
[[ -n "${ZIP_PHP}" && -n "${ZIP_WP}" ]] || DIE "Voraussetzungen fehlen im Zip-Header"

# Stable tag im Zip-readme muss denselben Wert tragen — die dritte Stelle,
# die ein Bump treffen muss, und die einzige, die WordPress selbst liest.
ZIP_STABLE="$(pr_php get --zip "${REL_ZIP}" --entry pods-repeater/readme.txt \
  | sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -n1)"
[[ "${ZIP_STABLE}" == "${VERSION}" ]] || \
  DIE "Stable tag im Zip sagt ${ZIP_STABLE}, erwartet ${VERSION}"

printf 'OK: %s\n' "${ZIP_PATH}"
NOTE "Groesse        $(printf '%s' "${MENGE}") Bytes"
NOTE "Dateien        $(wc -l < "${MANIFEST}" | tr -d ' ')"
NOTE "Version        ${VERSION} (Header = Stable tag = Konstante = Changelog)"
NOTE "Voraussetzungen WP ${ZIP_WP}, PHP ${ZIP_PHP}"
NOTE "Manifest       ${MANIFEST}"
