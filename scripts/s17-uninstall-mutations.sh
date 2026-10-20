#!/usr/bin/env bash
# Gegenprobe für tests/UninstallTest.php.
#
# Bricht uninstall.php auf vier Arten (je ein klassischer Fehler) und
# protokolliert, welche Testmethoden dabei rot werden. Danach wird die
# Originaldatei zurückgeschrieben. Ein Test, der bei kaputtem Verhalten
# grün bleibt, ist kein Test.
#
# Aufruf aus dem Repo-Wurzelverzeichnis: bash scripts/s17-uninstall-mutations.sh
set -u

CONTAINER="wp-env-pods-repeater-54d58dae-cli-1"
ORIG="${ORIG:-/home/hermes/.hermes/profiles/neo/cache/scratch/uninstall.php.orig}"
WS="/var/www/html/wp-content/plugins/pods-repeater"

run_suite() {
    docker exec -w "$WS" "$CONTAINER" sh -c 'vendor/bin/phpunit --testsuite PodsRepeater --filter UninstallTest 2>&1' \
        | grep -E "^(OK|FAILURES|ERRORS|[0-9]+\) )" | sed 's/^/    /'
}

restore() {
    cp "$ORIG" ./uninstall.php
}

echo "=== M1: löscht ohne Konstante (Guard übersprungen) ==="
# Den Standard-Rückgabezweig entfernen: es wird immer gelöscht.
sed -i 's/^\t\tif ( ! \$remove_data ) {$/\t\tif ( false ) {/' ./uninstall.php
grep -n "if ( false )" ./uninstall.php | sed 's/^/    /'
run_suite
restore

echo "=== M2: ignoriert die Konstante (null-Zweig fest false) ==="
# $remove_data immer als false behandeln, wenn nichts explizit übergeben wurde.
sed -i 's/&& true === PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL;/\&\& false;/' ./uninstall.php
grep -n "&& false;" ./uninstall.php | sed 's/^/    /'
run_suite
restore

echo "=== M3: ignoriert den expliziten Parameter (Konstante gewinnt immer) ==="
sed -i 's/^\t\tif ( null === \$remove_data ) {$/\t\tif ( true ) {/' ./uninstall.php
grep -n "if ( true )" ./uninstall.php | sed 's/^/    /'
run_suite
restore

echo "=== M4: löscht auch Repeater-Post-Meta ==="
# Zusätzliche Aufräumaktion: globales Löschen der Meta-Schlüssel.
sed -i 's/^\t\tdelete_option( .pods_repeater_groups. );$/&\n\t\tdelete_post_meta_by_key( "repeater_field" );/' ./uninstall.php
grep -n "delete_post_meta_by_key" ./uninstall.php | sed 's/^/    /'
run_suite
restore

echo "=== Wiederhergestellt ==="
diff -q "$ORIG" ./uninstall.php && echo "    uninstall.php == Original"
