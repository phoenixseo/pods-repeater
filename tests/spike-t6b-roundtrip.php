<?php
/**
 * Spike T6b: Roundtrip-Beweis über wp_postmeta an einem WEGWERF-Post —
 * CLI-Äquivalent zum Admin-Save. Nutzt die exakt gleiche save_pod_item-
 * Signatur wie spike-t5 (verifiziert, 33/33): 'data' + 'from' => 'save' +
 * 'error_mode' => 'wp_error'. Der Admin-Browser schickt items[0][subtitle]
 * etc.; pre_save() (T5-Fix) verwirft leere Zeilen, validate() läuft mit.
 *
 * Ablauf: Wegwerf-Post anlegen → mit 2 neuen Zeilen speichern → rohe
 * wp_postmeta zurücklesen (wie der Admin-Reload) → Werte/Reihenfolge
 * prüfen → zusätzlich eine geseedete Leerzeile mitschicken (darf nicht
 * persistieren) → Post aufräumen. Post 23 bleibt unangetastet (spike-t4
 * erwartet dort weiterhin die 3 Zeilen).
 *
 * Läuft im wp-env-Container: docker exec ... wp eval-file tests/spike-t6b-roundtrip.php
 */

$failures = 0;
$passes   = 0;

function t6b_check( $label, $cond, $detail = '' ) {
	global $passes, $failures;
	if ( $cond ) {
		$passes++;
		echo "PASS  {$label}\n";
	} else {
		$failures++;
		echo "FAIL  {$label} " . ( $detail ? '| ' . $detail : '' ) . "\n";
	}
}

$api = pods_api();
$pod = $api->load_pod( array( 'name' => 'test_repeater' ) );

// Wegwerf-Post anlegen (Post-Typ des Pods).
$src_post_type = get_post_type( 23 );
$post_type     = $src_post_type ? $src_post_type : 'post';
$new_id        = wp_insert_post( array(
	'post_type'   => $post_type,
	'post_status' => 'publish',
	'post_title'  => 'T6B-Roundtrip ' . wp_generate_password( 6, false ),
) );
t6b_check( 'Wegwerf-Post angelegt', ! is_wp_error( $new_id ) && $new_id > 0, is_wp_error( $new_id ) ? $new_id->get_error_message() : '' );

if ( is_wp_error( $new_id ) || $new_id <= 0 ) {
	echo "\nERGEBNIS: {$passes} PASS, {$failures} FAIL\nT6B FEHLGESCHLAGEN\n";
	exit( 1 );
}

// 1. Neuer Wertesatz: 2 gefüllte Zeilen + 1 geseedete Leerzeile
//    (items[2] komplett leer — pre_save muss sie verwerfen).
$data = array( 'items' => array(
	array( 'subtitle' => 'E2E-Roundtrip-Zeile-A', 'link' => 'https://e2e.example.com/a' ),
	array( 'subtitle' => 'E2E-Roundtrip-Zeile-B (geändert)', 'link' => 'https://e2e.example.com/b-neu' ),
	array( 'subtitle' => '', 'link' => '' ),
) );

$saved = $api->save_pod_item( array(
	'pod'        => 'test_repeater',
	'id'         => $new_id,
	'from'       => 'save',
	'error_mode' => 'wp_error',
	'data'       => $data,
) );
t6b_check( 'save_pod_item ok (kein WP_Error)', ! is_wp_error( $saved ), is_wp_error( $saved ) ? $saved->get_error_message() : '' );

// 2. Zurücklesen (Admin-Reload-Pfad): roher wp_postmeta-String.
$meta_raw = get_post_meta( $new_id, 'items', true );
$decoded  = json_decode( (string) $meta_raw, true );
t6b_check( 'Meta ist valides JSON', is_array( $decoded ) );
t6b_check( 'Meta: genau 2 Zeilen (Leerzeile verworfen)', is_array( $decoded ) && count( $decoded ) === 2, var_export( $meta_raw, true ) );
t6b_check( 'Kein [{}]-Artefakt', false === strpos( (string) $meta_raw, '[{}]' ) );
t6b_check( 'Zeile 0 subtitle', is_array( $decoded ) && isset( $decoded[0]['subtitle'] ) && 'E2E-Roundtrip-Zeile-A' === $decoded[0]['subtitle'] );
t6b_check( 'Zeile 0 link', is_array( $decoded ) && isset( $decoded[0]['link'] ) && 'https://e2e.example.com/a' === $decoded[0]['link'] );
t6b_check( 'Zeile 1 subtitle (geändert)', is_array( $decoded ) && isset( $decoded[1]['subtitle'] ) && 'E2E-Roundtrip-Zeile-B (geändert)' === $decoded[1]['subtitle'] );
t6b_check( 'Zeile 1 link (geändert)', is_array( $decoded ) && isset( $decoded[1]['link'] ) && 'https://e2e.example.com/b-neu' === $decoded[1]['link'] );
t6b_check( 'Reihenfolge A vor B', is_array( $decoded ) && isset( $decoded[0], $decoded[1] ) && 'E2E-Roundtrip-Zeile-A' === $decoded[0]['subtitle'] && 'E2E-Roundtrip-Zeile-B (geändert)' === $decoded[1]['subtitle'] );

// 3. Roundtrip über input(): Gerendertes HTML (wie der Admin-Reload es anzeigt).
$pod = $api->load_pod( array( 'name' => 'test_repeater' ) );
$options = is_object( $pod['fields']['items'] ) && method_exists( $pod['fields']['items'], 'get_args' )
	? $pod['fields']['items']->get_args() : (array) $pod['fields']['items'];
$options['disable_dfv'] = true;
$field = new Pods_Repeater_Field();
ob_start();
$field->input( 'items', $meta_raw, $options, null, $new_id );
$html = ob_get_clean();
t6b_check( 'Reload-HTML: Zeile A sichtbar', strpos( $html, 'E2E-Roundtrip-Zeile-A' ) !== false );
t6b_check( 'Reload-HTML: Zeile B sichtbar', strpos( $html, 'E2E-Roundtrip-Zeile-B (geändert)' ) !== false );
t6b_check( 'Reload-HTML: Altbestand (Dritte Zeile) weg', false === strpos( $html, 'Dritte Zeile' ) );

// 4. Aufräumen: Wegwerf-Post löschen.
wp_delete_post( $new_id, true );
t6b_check( 'Wegwerf-Post aufgeräumt', ! get_post( $new_id ) );

echo "\nERGEBNIS: {$passes} PASS, {$failures} FAIL\n";
echo ( $failures > 0 ? 'T6B FEHLGESCHLAGEN' : 'T6B ERFOLGREICH' ) . "\n";
exit( $failures > 0 ? 1 : 0 );
