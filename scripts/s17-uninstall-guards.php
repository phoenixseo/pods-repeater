<?php
/**
 * Probe: Guards von uninstall.php ohne WordPress.
 *
 * Läuft mit reinem PHP (kein WP-Core). Vier Fälle, je eine Zeile. Die
 * Guards werden dadurch belegt, dass der Prozess nach dem Einbinden
 * entweder weiterläuft oder vorher beendet — unterscheidbar über einen
 * Sentinel, der in einem register_shutdown_function-Handler ausgegeben
 * wird (also auch bei exit() läuft, aber nur einmal).
 *
 * @package PodsRepeater
 */

$target = dirname( __DIR__ ) . '/uninstall.php';

if ( ! file_exists( $target ) ) {
	fwrite( STDERR, "uninstall.php fehlt: $target\n" );
	exit( 1 );
}

/**
 * Baut ein Probeskript und liefert dessen Ausgabe.
 *
 * @param string $prelude PHP-Code vor dem require (Konstanten, Stubs).
 * @param string $target  Absoluter Pfad zu uninstall.php.
 *
 * @return array{out: string, exit: int}
 */
function probe_run( $prelude, $target ) {
	$body = '<?php' . PHP_EOL
		. $prelude . PHP_EOL
		. 'register_shutdown_function( function () {'
		. ' echo "PROZESS_ENDE=ja" . PHP_EOL;'
		. ' echo "FUNKTION=" . ( function_exists( "pods_repeater_uninstall" ) ? "ja" : "nein" ) . PHP_EOL;'
		. ' } );' . PHP_EOL
		. 'require ' . var_export( $target, true ) . ';' . PHP_EOL
		. 'echo "NACH_REQUIRE=erreicht" . PHP_EOL;' . PHP_EOL
		. 'echo "LOESCHAUFRUFE=" . count( $GLOBALS["calls"] ?? array() ) . PHP_EOL;'
		. 'echo "ERGEBNIS=" . var_export( pods_repeater_uninstall( true ), true ) . PHP_EOL;'
		. 'echo "LOESCHAUFRUFE_NACH=" . count( $GLOBALS["calls"] ?? array() ) . PHP_EOL;';

	$file = tempnam( sys_get_temp_dir(), 'uninst-probe-' );
	file_put_contents( $file, $body );

	$out = array();
	$rc  = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $out, $rc );
	unlink( $file );

	return array(
		'out'  => implode( PHP_EOL, $out ),
		'exit' => $rc,
	);
}

/**
 * Gibt einen Fall mit Erwartung aus.
 *
 * @param string $label    Bezeichnung.
 * @param array  $result   Ergebnis von probe_run().
 * @param string $expected Erwartung im Klartext.
 *
 * @return void
 */
function probe_report( $label, array $result, $expected ) {
	echo "=== $label ===" . PHP_EOL;
	foreach ( explode( PHP_EOL, $result['out'] ) as $line ) {
		echo '    ' . $line . PHP_EOL;
	}
	echo '    exit=' . $result['exit'] . PHP_EOL;
	echo '    → erwartet: ' . $expected . PHP_EOL . PHP_EOL;
}

$stub_options = '$GLOBALS["calls"] = array();'
	. ' function delete_option( $key ) { $GLOBALS["calls"][] = $key; return true; }'
	. ' function delete_post_meta_by_key( $key ) { $GLOBALS["calls"][] = "META:" . $key; return true; }';

probe_report(
	'1) nur ABSPATH, kein WP_UNINSTALL_PLUGIN',
	probe_run( $stub_options . ' define( "ABSPATH", "/tmp/" );', $target ),
	'kein NACH_REQUIRE (Guard greift), FUNKTION=nein'
);

probe_report(
	'2) nur WP_UNINSTALL_PLUGIN, kein ABSPATH',
	probe_run( $stub_options . ' define( "WP_UNINSTALL_PLUGIN", "pods-repeater/pods-repeater.php" );', $target ),
	'kein NACH_REQUIRE (Guard greift), FUNKTION=nein'
);

probe_report(
	'3) beide Guards erfüllt, Standardmodus',
	probe_run(
		$stub_options . ' define( "ABSPATH", "/tmp/" ); define( "WP_UNINSTALL_PLUGIN", "pods-repeater/pods-repeater.php" );',
		$target
	),
	'NACH_REQUIRE=erreicht, FUNKTION=ja, LOESCHAUFRUFE=0 (Standard löscht nichts), ERGEBNIS=true nur bei Aufruf mit true'
);

probe_report(
	'4) beide Guards erfüllt, Konstante true',
	probe_run(
		$stub_options . ' define( "ABSPATH", "/tmp/" ); define( "WP_UNINSTALL_PLUGIN", "pods-repeater/pods-repeater.php" );'
			. ' define( "PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL", true );',
		$target
	),
	'LOESCHAUFRUFE=2 direkt beim Einbinden (beide Optionen), KEIN META-Aufruf'
);
