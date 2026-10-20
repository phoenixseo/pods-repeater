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
#   - Das Repo ignoriert /dist/ (.gitignore), und `dist/` enthaelt untracked
#     Altbestand (e2e-Logs, PNGs, das Pods-PR-Paket). git archive nimmt nur
#     getrackte Dateien — es wuerde also genau das Verzeichnis auslassen, in
#     das das Ergebnis soll, und bei den Quellen nur zufaellig richtig raten.
#     `zip -r .` muesste jeden Pfad einzeln ausschliessen.
#   - zip/unzip sind auf dem Host nicht installiert; im CLI-Container sind
#     PHP 8.3 mit ZipArchive und unzip vorhanden. Der Container ist die
#     Umgebung, in der das Plugin ohnehin laeuft.
# Darum: Allowlist pro Verzeichnis, Allowlist pro Datei, Bau im Container.
# Die Allowlist ist kuerzer und lesbarer als die Ausschlussliste, und sie
# erlaubt den Fehler in die sichere Richtung: eine neue Datei fehlt im Paket,
# statt dass sie versehentlich mitgeht.
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
for eintrag in "${FILES[@]}"; do
  [[ -f "${REPO_ROOT}/${eintrag}" ]] || DIE "Datei fehlt: ${eintrag}"
done
for eintrag in "${DIRS[@]}"; do
  dir="${eintrag%%:*}"
  [[ -d "${REPO_ROOT}/${dir}" ]] || DIE "Verzeichnis fehlt: ${dir}"
done

docker inspect -f '{{.State.Running}}' "${CONTAINER}" 2>/dev/null | grep -q true || \
  DIE "Container ${CONTAINER} laeuft nicht (docker ps | grep pods-repeater)"

# Das Repo ist als wp-content/plugins/pods-repeater in den Container gemountet,
# NICHT unter dem Host-Pfad. Die Angabe "Host- und Container-Pfade sind
# identisch" aus der Sprint-Karte ist falsch; der Pfad wird hier aus dem Mount
# gelesen, statt ihn zu raten.
CONTAINER_ROOT="${PODS_REPEATER_CONTAINER_ROOT:-}"
if [[ -z "${CONTAINER_ROOT}" ]]; then
  CONTAINER_ROOT="$(docker inspect -f \
    '{{range .Mounts}}{{if eq .Destination "/var/www/html/wp-content/plugins/pods-repeater"}}{{.Destination}}{{end}}{{end}}' \
    "${CONTAINER}")"
fi
[[ -n "${CONTAINER_ROOT}" ]] || \
  DIE "Container ${CONTAINER} hat kein Mount auf wp-content/plugins/pods-repeater"

docker exec "${CONTAINER}" test -f "${CONTAINER_ROOT}/pods-repeater.php" || \
  DIE "${CONTAINER_ROOT} ist im Container ${CONTAINER} nicht lesbar"

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

# Derselbe Pfad aus Sicht des Containers. Der Repo-Ordner ist als
# wp-content/plugins/pods-repeater gemountet; der Host-Pfad existiert dort
# nicht (die Sprint-Karte behauptet das Gegenteil). Einmal berechnet, danach
# nur noch benutzt — kein "../" in den Aufrufen.
CONTAINER_ZIP="${CONTAINER_ROOT}/${REL_ZIP}"

printf 'Baue pods-repeater %s\n' "${VERSION}"
printf '  Host:      %s\n' "${REPO_ROOT}"
printf '  Container: %s\n' "${CONTAINER_ROOT}"

# ---------------------------------------------------------------------------
# 2. Zip im Container bauen
#
# Gebaut wird aus dem Mount, also exakt aus dem Arbeitsstand — aber nur aus
# den Pfaden der Allowlist. ZipArchive mit Overwrite, kein addGlob: addGlob
# hat seine eigene Ausschluss-Semantik, und die will ich hier nicht.
#
# setMtimeName setzt den Zeitstempel jeder Datei auf deren echte mtime. Ohne
# das traegt jeder Eintrag die Build-Zeit, und zwei Laeufe mit identischem
# Inhalt erzeugen verschiedene Zips. So ist das Paket reproduzierbar, solange
# die Dateien unveraendert sind.
# ---------------------------------------------------------------------------
mkdir -p "${OUT_DIR}"
rm -f "${ZIP_PATH}" "${MANIFEST}"

DIRS_SPEC="$(printf '%s;' "${DIRS[@]}")"
FILES_SPEC="$(printf '%s;' "${FILES[@]}")"

docker exec \
  -e "PR_ROOT=${CONTAINER_ROOT}" \
  -e "PR_ZIP=${CONTAINER_ZIP}" \
  -e "PR_DIRS=${DIRS_SPEC}" \
  -e "PR_FILES=${FILES_SPEC}" \
  "${CONTAINER}" php -r '
$root  = rtrim( getenv( "PR_ROOT" ), "/" );
$zip_p = getenv( "PR_ZIP" );
$dirs  = array_filter( explode( ";", getenv( "PR_DIRS" ) ) );
$files = array_filter( explode( ";", getenv( "PR_FILES" ) ) );

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_p, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
    fwrite( STDERR, "ZipArchive konnte {$zip_p} nicht anlegen\n" );
    exit( 1 );
}

$added = 0;
$add = function ( string $abs, string $rel ) use ( $zip, &$added ) {
    if ( ! is_file( $abs ) ) {
        fwrite( STDERR, "fehlt: {$abs}\n" );
        exit( 1 );
    }
    $zip->addFile( $abs, "pods-repeater/" . $rel );
    $zip->setMtimeName( "pods-repeater/" . $rel, filemtime( $abs ) );
    $added++;
};

foreach ( $files as $file ) {
    $add( $root . "/" . $file, $file );
}

foreach ( $dirs as $spec ) {
    list( $dir, $recursive ) = array_pad( explode( ":", $spec ), 2, "0" );
    $base = $root . "/" . $dir;
    if ( "1" === $recursive ) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $info ) {
            if ( ! $info->isFile() ) {
                continue;
            }
            $rel = $dir . "/" . substr( $info->getPathname(), strlen( $base ) + 1 );
            $add( $info->getPathname(), $rel );
        }
    } else {
        foreach ( scandir( $base ) as $entry ) {
            if ( "." === $entry || ".." === $entry ) {
                continue;
            }
            if ( ! is_file( $base . "/" . $entry ) ) {
                continue;
            }
            $add( $base . "/" . $entry, $dir . "/" . $entry );
        }
    }
}

if ( 0 === $added ) {
    fwrite( STDERR, "keine Datei hinzugefuegt\n" );
    exit( 1 );
}
$zip->close();
echo "Dateien im Zip: {$added}\n";
' || DIE "Zip-Bau im Container fehlgeschlagen"

[[ -f "${ZIP_PATH}" ]] || DIE "Zip wurde nicht geschrieben: ${ZIP_PATH}"

# ---------------------------------------------------------------------------
# 3. Manifest aus dem gebauten Zip erzeugen
#
# Das Manifest ist keine Abschrift einer Absicht, sondern die Liste aus dem
# Zip selbst. Der Test prueft spaeter gegen genau diese Datei — driftet das
# Skript, faellt es dort auf, nicht erst beim Tester.
#
# Gelesen wird `unzip -l` in der BusyBox-Variante des Containers (kein -Z,
# kein Info-ZIP). Zeilenformat: "<Laenge>  <Datum>  <Zeit>   <Name>".
# Die Kopf- und Summenzeilen fallen durch die Namenspruefung, das Datum durch
# das Muster — kein Sed-Gefrickel an Spaltenpositionen.
# ---------------------------------------------------------------------------
docker exec "${CONTAINER}" unzip -l "${CONTAINER_ZIP}" \
  | awk '$4 ~ /^pods-repeater\// { print $4 }' > "${MANIFEST}"

[[ -s "${MANIFEST}" ]] || DIE "Manifest ist leer — unzip -l lieferte keine Dateinamen"

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
    printf '%s\n' "$(grep -F -- "${verboten}" "${MANIFEST}")" >&2
  fi
done

# Jede Zeile muss unter pods-repeater/ liegen — kein Eintrag auf oberster
# Ebene, der beim Entpacken neben das Plugin-Verzeichnis faellt.
FREMD="$(grep -vc '^pods-repeater/' "${MANIFEST}" || true)"
[[ "${FREMD}" -eq 0 ]] || DIE "${FREMD} Eintraege liegen ausserhalb von pods-repeater/"

# Der Header im Zip muss die Version tragen, die der Dateiname behauptet.
# Gelesen aus dem Zip, nicht aus der Arbeitskopie: das ist der Punkt, an dem
# ein vergessener Bump auffliegt.
ZIP_VERSION="$(docker exec "${CONTAINER}" unzip -p "${CONTAINER_ZIP}" pods-repeater/pods-repeater.php \
  | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -n1)"
[[ "${ZIP_VERSION}" == "${VERSION}" ]] || \
  DIE "Header im Zip sagt ${ZIP_VERSION}, Dateiname sagt ${VERSION}"

ZIP_PHP="$(docker exec "${CONTAINER}" unzip -p "${CONTAINER_ZIP}" pods-repeater/pods-repeater.php \
  | sed -n 's/^ \* Requires PHP:[[:space:]]*\(.*\)$/\1/p' | head -n1)"
ZIP_WP="$(docker exec "${CONTAINER}" unzip -p "${CONTAINER_ZIP}" pods-repeater/pods-repeater.php \
  | sed -n 's/^ \* Requires at least:[[:space:]]*\(.*\)$/\1/p' | head -n1)"
[[ -n "${ZIP_PHP}" && -n "${ZIP_WP}" ]] || DIE "Voraussetzungen fehlen im Zip-Header"

# Stable tag im Zip-readme muss denselben Wert tragen — die dritte Stelle,
# die ein Bump treffen muss, und die einzige, die WordPress selbst liest.
ZIP_STABLE="$(docker exec "${CONTAINER}" unzip -p "${CONTAINER_ZIP}" pods-repeater/readme.txt \
  | sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -n1)"
[[ "${ZIP_STABLE}" == "${VERSION}" ]] || \
  DIE "Stable tag im Zip sagt ${ZIP_STABLE}, erwartet ${VERSION}"

printf 'OK: %s\n' "${ZIP_PATH}"
NOTE "Groesse        $(printf '%s' "${MENGE}") Bytes"
NOTE "Dateien        $(wc -l < "${MANIFEST}" | tr -d ' ')"
NOTE "Version        ${VERSION} (Header = Stable tag = Konstante = Changelog)"
NOTE "Voraussetzungen WP ${ZIP_WP}, PHP ${ZIP_PHP}"
NOTE "Manifest       ${MANIFEST}"
