<?php
/**
 * PHPUnit-Bootstrap der eigenständigen WP-Testbasis für Pods-Repeater.
 *
 * Aufbau (Option 2, Frank-Entscheidung):
 *   - WP-Test-Lib: wp-phpunit 6.8.2 (entspricht WordPress 6.8.2), entpackt
 *     nach tests/wp-tests/  (includes/ + data/, kein svn/kein WP-Core)
 *   - DB: MariaDB des wp-env-Stacks, Datenbank „wp-tests" (root/„password",
 *     Host „mysql") — getrennt von der wp-env-Live-DB „wordpress"
 *   - PHPUnit 10.x, dev-Abhängigkeiten via composer.json:
 *       phpunit/phpunit ^10.5, yoast/phpunit-polyfills ^2.0
 *
 * Ablauf:
 *   1. WP_PHPUNIT__TESTS_CONFIG auf tests/wp-tests-config.php setzen
 *      (der Shim in tests/wp-tests/wp-tests-config.php lädt sie).
 *   2. WP_TESTS_PHPUNIT_POLYFILLS_PATH auf das Composer-Vendor setzen
 *      (von der Lib zwingend benötigt).
 *   3. tests_add_filter() laden, dann Pods + pods-repeater über
 *      muplugins_loaded manuell aktivieren (Test-DB hat keine aktiven
 *      Plugins — wp-cli-Muster für Plugin-Tests).
 *   4. Lib-Bootstrap starten: installiert WP in die wp-tests-DB (einmalig)
 *      und lädt WordPress + Test-Klassen (WP_UnitTestCase …).
 *
 * @package PodsRepeater
 */

// 1) WP-Test-Lib lokalisieren (tests/wp-tests/ im Plugin-Repo).
$wp_tests_dir = __DIR__ . '/wp-tests';

if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	fprintf(
		STDERR,
		"Error: WP-Test-Lib fehlt unter %s.\nErwartet: wp-phpunit 6.8.2 (includes/functions.php).\n",
		$wp_tests_dir
	);
	exit( 1 );
}

// 2) Shim-Konfiguration: echte wp-tests-config + Tabellen-Präfix.
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
putenv( 'WP_PHPUNIT__TABLE_PREFIX=wptests_' );

// 3) PHPUnit-Polyfills (yoast/phpunit-polyfills, require-dev in composer.json).
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

// 4) tests_add_filter() verfügbar machen (Lib-Funktionen).
require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Lädt Pods + pods-repeater, sobald die WP-Test-Umgebung die mu-plugins-Phase
 * erreicht (wp-settings.php). Die frische Test-DB hat keine aktiven Plugins,
 * daher manuelles Laden — identisch zum wp-cli-Scaffold-Muster. Reihenfolge:
 * erst Pods (Basisklasse PodsField), dann das Plugin.
 */
function _pods_repeater_manually_load_plugins() {
	$pods_main_file = ABSPATH . 'wp-content/plugins/pods/init.php';
	if ( file_exists( $pods_main_file ) ) {
		require_once $pods_main_file;
	}
	require_once dirname( __DIR__ ) . '/pods-repeater.php';
}
tests_add_filter( 'muplugins_loaded', '_pods_repeater_manually_load_plugins' );

// 5) WP-Test-Umgebung starten (installiert WP in die wp-tests-DB, lädt WP).
require $wp_tests_dir . '/includes/bootstrap.php';
