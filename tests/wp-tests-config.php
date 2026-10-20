<?php
/**
 * wp-tests-config.php — DB-/Umgebungskonfiguration der eigenständigen
 * WP-Testbasis (Pods-Repeater, Option 2 laut Frank-Entscheidung).
 *
 * Diese Datei ist das wp-tests-config.php-Äquivalent für die WP-Test-Lib
 * (wp-phpunit 6.8.2 in tests/wp-tests/). Der dort mitgelieferte Shim
 * (tests/wp-tests/wp-tests-config.php) lädt sie über die Umgebungsvariable
 * WP_PHPUNIT__TESTS_CONFIG, die tests/bootstrap.php vor dem Start der
 * Test-Umgebung setzt.
 *
 * ABSPATH zeigt auf die WP-Installation im wp-env-Container — die Tests
 * laufen ausschließlich dort (docker exec … phpunit). Die Test-DB
 * „wp-tests" ist bewusst getrennt von der Live-DB „wordpress" des wp-env.
 *
 * @package PodsRepeater
 */

// WP-Codebasis (Pflicht: Slash am Ende). Default ist der wp-env-Container;
// die CI setzt WP_TEST_ABSPATH auf ihr eigenes Verzeichnis.
define( 'ABSPATH', rtrim( getenv( 'WP_TEST_ABSPATH' ) ?: '/var/www/html', '/' ) . '/' );

// Pflichtkonstanten der WP-Test-Lib (inkl. tests/phpunit/includes/bootstrap.php).
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

// PHP-Binary für den Install-Subprozess (lib installiert WP in die Test-DB).
define( 'WP_PHP_BINARY', getenv( 'WP_TEST_PHP_BINARY' ) ?: PHP_BINARY );

// Kein Multisite.
define( 'WP_TESTS_MULTISITE', false );

/**
 * MySQL-Zugang: wp-env-MariaDB-Container (Service-Name „mysql" im
 * wp-env-Netzwerk), root mit Passwort „password" (wp-env-Default,
 * MYSQL_ROOT_HOST=%).
 */
define( 'DB_NAME', getenv( 'WP_TEST_DB_NAME' ) ?: 'wp-tests' );
define( 'DB_USER', getenv( 'WP_TEST_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TEST_DB_PASSWORD' ) ?: 'password' );
define( 'DB_HOST', getenv( 'WP_TEST_DB_HOST' ) ?: 'mysql' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_DEBUG', true );

/**
 * Tabellen-Präfix als lokale Variable (WP-Test-Lib-Konvention; der
 * wp-phpunit-Shim definiert daraus WP_TESTS_TABLE_PREFIX).
 */
$table_prefix = 'wptests_';
