#!/usr/bin/env bash
# S22: Mindestversionen belegen — Laufzeitmatrix WP 6.8.2 x PHP 7.4 / 8.3.
#
# Warum nicht wp-env fuer 7.4: das wp-env-fuer-dieses-Setup laeuft ueber
# Docker Compose (wp-env 1.x), dessen PHP-Images nur fuer 8.x existieren;
# fuer 7.4 gibt es hier kein Image. Statt die Zahl zu raten, wird der
# ABSPATH-Baum genauso gebaut wie in scripts/s16-php74-suite.sh (WP-Core +
# Plugins + Plugin inkl. vendor) und die Suite mit dem lokalen PHP-7.4-Image
# bzw. dem wp-env-CLI-Container (8.3) gefahren. Beide Laeufe teilen sich
# dieselbe WP-Core-Version — nur so ist die PHP-Aussage unabhaengig von der
# WordPress-Aussage.
#
# WP-Core: 6.8.2 (die Version, die die Test-Lib in tests/wp-tests/ abbildet).
# Das laufende wp-env faehrt 7.1.3 und belegt damit "Tested up to".
#
# Voraussetzungen:
#   - /home/hermes/.hermes/profiles/neo/cache/scratch/wp68/wordpress (6.8.2)
#   - Images php74-wp:local und php83-wp:local (php:8.3-cli ohne mysqli/zip
#     reicht nicht; siehe scratch/php83-wp/Dockerfile)
#   - Netz wp-env-pods-repeater-54d58dae_default (MariaDB erreichbar)
#
# Aufruf: bash scripts/s22-min-version-matrix.sh [7.4|8.3|beide]
set -eu

REPO="${REPO:-/home/hermes/hermes_agent/workspaces/phoenixseo/sdc-page-builder/pods-repeater}"
SCRATCH="${SCRATCH:-/home/hermes/.hermes/profiles/neo/cache/scratch}"
WP68="${WP68:-$SCRATCH/wp68/wordpress}"
NET="${NET:-wp-env-pods-repeater-54d58dae_default}"
IMG74="${IMG74:-php74-wp:local}"
IMG83="${IMG83:-php83-wp:local}"
WPCT="${WPCT:-wp-env-pods-repeater-54d58dae-wordpress-1}"
WHICH="${1:-beide}"

TREE="$SCRATCH/s22-wp682"
PLUGIN_DIR="$TREE/wp-content/plugins/pods-repeater"

[ -f "$WP68/wp-includes/version.php" ] || { printf 'FEHLER: WP 6.8.2 fehlt unter %s\n' "$WP68" >&2; exit 1; }

# 1) Baum neu: WP-Core 6.8.2 + Pods aus der laufenden Instanz + Plugin aus dem Repo.
#    Vorherige Laeufe hinterlassen root-eigene Dateien (composer im Container) —
#    darum das Verzeichnis aus dem Container heraus leeren.
#    SKIP_BUILD=1 haelt den bestehenden Baum (z.B. um nur die DB umzubiegen).
if [ "${SKIP_BUILD:-0}" = "1" ] && [ -d "$TREE/wp-includes" ]; then
    printf 'Baum bleibt stehen: %s\n' "$TREE"
else
if [ -d "$TREE" ]; then
    docker run --rm -v "$SCRATCH":/w php:8.3-cli sh -c "rm -rf /w/$(basename "$TREE")" 2>/dev/null \
        || sudo -n rm -rf "$TREE" 2>/dev/null \
        || rm -rf "$TREE" || { printf 'FEHLER: Baum %s nicht leerbar\n' "$TREE" >&2; exit 1; }
fi
mkdir -p "$TREE" "$PLUGIN_DIR"
tar -c -C "$WP68" . | tar -x -C "$TREE"
mkdir -p "$TREE/wp-content/plugins"
docker cp "$WPCT:/var/www/html/wp-content/plugins/pods" "$TREE/wp-content/plugins/pods"
tar -c -C "$REPO" --exclude=./vendor --exclude=./assets --exclude=./dist . | tar -x -C "$PLUGIN_DIR"
# PrPackageTest prueft dist/pods-pr/ — Paket in den Baum bauen (nicht
# mitkopieren: der Test soll den Stand des Commits sehen, nicht den von
# gestern). Der Build laeuft auf dem Host, python3 gibt es im Container nicht.
( cd "$REPO" && PR_OUT_DIR="$PLUGIN_DIR/dist/pods-pr" bash scripts/build-pr-package.sh >/dev/null ) \
    || { printf 'FEHLER: PR-Paket-Build fuer den Baum fehlgeschlagen\n' >&2; exit 1; }

printf 'WP-Core im Baum: %s\n' "$(grep -oE "wp_version = '[^']+'" "$TREE/wp-includes/version.php" 2>/dev/null || echo '(Baum bleibt stehen)')"
fi

# 1b) Eigene Test-DB: die geteilte wp-tests-DB wird parallel von anderen
#     Laeufen benutzt (Deadlocks mitten in der Suite, siehe S22-Bericht).
#     Nur die Kopie im Baum wird umgebogen, tests/wp-tests-config.php im Repo
#     bleibt unveraendert (sie ist die dokumentierte Standardkonfiguration).
DB_OVERRIDE="${DB_OVERRIDE:-wp-tests-s22}"
if [ "$DB_OVERRIDE" != "" ]; then
    CFG="$PLUGIN_DIR/tests/wp-tests-config.php"
    sed -i "s/define( 'DB_NAME', '[^']*' );/define( 'DB_NAME', '${DB_OVERRIDE}' );/" "$CFG"
    printf 'Test-DB im Baum: %s\n' "$(grep -oE "define\( 'DB_NAME', '[^']*' \)" "$CFG")"
fi

# 2) Abhaengigkeiten je PHP-Version unter dieser Version aufloesen.
resolve() {
    img="$1"
    docker run --rm -v "$PLUGIN_DIR":/app -w /app "$img" sh -c '
        php -v | head -1
        if [ ! -x /tmp/composer ]; then
            curl -sSfL -o /tmp/cs.php https://getcomposer.org/installer && php /tmp/cs.php --install-dir=/tmp --filename=composer >/dev/null
        fi
        php /tmp/composer update --no-interaction --prefer-dist --no-progress >/tmp/composer.log 2>&1 || { tail -20 /tmp/composer.log; exit 1; }
        php /tmp/composer show phpunit/phpunit --no-ansi | grep -i "^versions" || true
    '
}

run_suite() {
    img="$1"
    docker run --rm --network "$NET" -v "$TREE":/var/www/html -w /var/www/html/wp-content/plugins/pods-repeater "$img" \
        sh -c "php -v | head -1; php vendor/bin/phpunit --version; php vendor/bin/phpunit --testsuite PodsRepeater 2>&1 | grep -vE '_load_textdomain_just_in_time|already defined' | tail -30"
}

for v in $([ "$WHICH" = beide ] && echo "7.4 8.3" || echo "$WHICH"); do
    case "$v" in
        7.4) img="$IMG74" ;;
        8.3) img="$IMG83" ;;
        *) printf 'Unbekannte PHP-Version: %s\n' "$v" >&2; exit 2 ;;
    esac
    db="wp-tests-s22-${v//./}"
    printf '\n======== PHP %s — Abhaengigkeiten ========\n' "$v"
    resolve "$img"
    printf '\n======== PHP %s — Suite (WP 6.8.2, DB %s) ========\n' "$v" "$db"
    run_suite "$img"
done
