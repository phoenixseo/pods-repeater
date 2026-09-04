<?php
/**
 * Spike T6: Admin-Render-Pfad — input() muss rohe JSON-Strings aus
 * wp_postmeta korrekt in Zeilen rendern (E2E-Fund Echo 04.09.2026:
 * Pods überspringt value()/Decode bei in_form=true und reicht den
 * rohen wp_postmeta-String an input() durch).
 *
 * Baut den Pods-Aufruf exakt wie spike-t4 nach (ob_start → input →
 * ob_get_clean), übergibt aber den ROHEN JSON-String statt des Arrays —
 * so kommt der Wert im echten Admin-Formular an.
 *
 * Läuft im wp-env-Container: docker exec ... wp eval-file tests/spike-t6-json-input.php
 */

$failures = 0;
$passes   = 0;

function t6_check( $label, $cond ) {
	global $passes, $failures;
	if ( $cond ) {
		$passes++;
		echo "PASS  {$label}\n";
	} else {
		$failures++;
		echo "FAIL  {$label}\n";
	}
}

// Bestehenden Pod laden (wie spike-t4) — Feld-Optionen inkl. disable_dfv.
$api = pods_api();
$pod = $api->load_pod( array( 'name' => 'test_repeater' ) );
if ( is_wp_error( $pod ) ) {
	wp_die( 'Pod-Fehler: ' . $pod->get_error_message() );
}
$field_def = $pod['fields']['items'];
$options   = is_object( $field_def ) && method_exists( $field_def, 'get_args' ) ? $field_def->get_args() : (array) $field_def;
$options['disable_dfv'] = true;

$field = new Pods_Repeater_Field();

function t6_render( $field, $name, $value, $options ) {
	ob_start();
	$field->input( $name, $value, $options, null, 23 );
	return ob_get_clean();
}

// Präziser Zeilen-Zähler: 'pods-repeater-row"' (mit Anführungszeichen) matcht
// nur Zeilen-Divs, nicht den Container 'pods-repeater-rows' (Substring-Falle).
function t6_rows( $html ) {
	return substr_count( $html, 'pods-repeater-row"' );
}

// --- 1. Roh-JSON-String (Admin-Pfad: wp_postmeta, in_form) ---
$json = '[{"subtitle":"Erste Zeile","link":"https://example.com/1"},{"subtitle":"Zweite Zeile","link":"https://example.com/2"},{"subtitle":"Dritte Zeile","link":"https://example.com/3"}]';
$html_json = t6_render( $field, 'items', $json, $options );
t6_check( 'JSON-String: rendert Zeile 1', strpos( $html_json, 'Erste Zeile' ) !== false );
t6_check( 'JSON-String: rendert Zeile 2', strpos( $html_json, 'Zweite Zeile' ) !== false );
t6_check( 'JSON-String: rendert Zeile 3', strpos( $html_json, 'Dritte Zeile' ) !== false );
t6_check( 'JSON-String: genau 3 rows', t6_rows( $html_json ) === 3 );
t6_check( 'JSON-String: Name-Syntax items[0][subtitle]', strpos( $html_json, 'items[0][subtitle]' ) !== false );

// --- 2. Array (API-Pfad, weiterhin unterstützt) ---
$html_arr = t6_render( $field, 'items', array( array( 'subtitle' => 'API-Zeile', 'link' => 'https://api.example.com' ) ), $options );
t6_check( 'Array: rendert Zeile', strpos( $html_arr, 'API-Zeile' ) !== false );

// --- 3. Leerer String (keine Meta): 1 geseedete Leerzeile (Design, Z. 326-329) ---
$html_empty = t6_render( $field, 'items', '', $options );
t6_check( 'Leerstring: genau 1 geseedete Leerzeile', t6_rows( $html_empty ) === 1 );

// --- 4. Ungültiger JSON-String: wie leerer String → 1 geseedete Leerzeile ---
$html_bad = t6_render( $field, 'items', 'kein-json{', $options );
t6_check( 'Ungültiges JSON: 1 geseedete Leerzeile, kein Crash', t6_rows( $html_bad ) === 1 );

// --- 5. XSS-Escaping: JSON-Werte mit Sonderzeichen ---
$xss = '<script>alert("x")</script>';
$html_xss = t6_render( $field, 'items', '[{"subtitle":"' . $xss . '","link":"https://example.com/x"}]', $options );
t6_check( 'XSS: <script> nicht roh im HTML', strpos( $html_xss, '<script>alert' ) === false );

echo "\nERGEBNIS: {$passes} PASS, {$failures} FAIL\n";
echo ( $failures > 0 ? 'T6 FEHLGESCHLAGEN' : 'T6 ERFOLGREICH' ) . "\n";
exit( $failures > 0 ? 1 : 0 );
