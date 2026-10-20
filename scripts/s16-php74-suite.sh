#!/usr/bin/env bash
# S16: PHP-7.4-Laufzeitbeleg der Pods-Repeater-Suite.
#
# Baut aus dem aktuellen Repo einen Baum, den die WP-Test-Lib unter
# ABSPATH=/var/www/html/ erwartet (tests/wp-tests-config.php setzt das hart):
#   /var/www/html/                                  = WP-Core (aus dem WordPress-Container)
#   /var/www/html/wp-content/plugins/pods           = Pods 3.3.9.1
#   /var/www/html/wp-content/plugins/pods-repeater  = Plugin (aktueller Stand) inkl. vendor/
#
# Abhaengigkeiten werden unter PHP 7.4 aufgeloest (composer update), weil
# doctrine/instantiator 2.x und myclabs/deep-copy 1.14 PHP 8 verlangen —
# beide sind reine Test-Infrastruktur.
#
# Voraussetzung: Image php74-wp:local (PHP 7.4.33 + mysqli/pdo_mysql/zip)
# und Netz wp-env-pods-repeater-54d58dae_default mit erreichbarer MariaDB.
set -eu

REPO="${REPO:-/home/hermes/hermes_agent/workspaces/phoenixseo/sdc-page-builder/pods-repeater}"
WORK="${WORK:-/home/hermes/.hermes/profiles/neo/cache/scratch/php74-build}"
WPCT="${WPCT:-wp-env-pods-repeater-54d58dae-wordpress-1}"
NET="${NET:-wp-env-pods-repeater-54d58dae_default}"
IMG="${IMG:-php74-wp:local}"

mkdir -p "$WORK"
TREE="$WORK/run"
PLUGIN_DIR="$TREE/wp-content/plugins/pods-repeater"
mkdir -p "$PLUGIN_DIR"

# 1) WP-Core aus dem laufenden WordPress-Container.
if [ ! -f "$TREE/wp-settings.php" ]; then
    docker cp "$WPCT:/var/www/html/." "$TREE"
fi

# 2) Plugin-Stand aus dem Repo (ohne vendor/assets, die kommen separat).
tar -c -C "$REPO" --exclude=./vendor --exclude=./assets . | tar -x -C "$PLUGIN_DIR"

# 3) Abhaengigkeiten unter PHP 7.4 aufloesen und installieren.
docker run --rm -v "$PLUGIN_DIR":/app -w /app "$IMG" sh -c '
    curl -sSfL -o /tmp/cs.php https://getcomposer.org/installer
    php /tmp/cs.php --install-dir=/tmp --filename=composer >/dev/null
    php /tmp/composer update --no-interaction --prefer-dist --no-progress >/dev/null 2>&1
    php /tmp/composer show phpunit/phpunit --no-ansi 2>/dev/null | grep -i "^versions" || true
'

# 4) Suite unter PHP 7.4 fahren.
docker run --rm --network "$NET" -v "$TREE":/var/www/html \
    -w /var/www/html/wp-content/plugins/pods-repeater "$IMG" \
    sh -c 'php -v | head -1; php vendor/bin/phpunit --testsuite PodsRepeater 2>&1 | tail -15'
