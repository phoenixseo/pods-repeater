<?php
/**
 * T4-Pipeline-Beweis über die ECHTE Pods-Formular-API — gleicher Codepfad wie
 * die Admin-Meta-Box (Pods.php:411 → PodsForm::field($name,$value,$type,$options,$pod,$id)).
 *
 * Signatur-Nutzung wie von PodsForm::field() unterstützt (Z. 238–248):
 *   $name = Feld-Objekt (Whatsit\Field, lädt name+type+disable_dfv)
 *   $type = Pod-Objekt
 *   $id   = Item-ID
 * field() → options() (lädt Feldklasse via field_loader) → ob_start → input() (echo-t)
 * → ob_get_clean → Filter. Vor dem Echo-Fix war $output leer → 0 Zeilen.
 */

$post_id = 23;
$api     = pods_api();
$pod     = $api->load_pod( array( 'name' => 'test_repeater' ) );
if ( is_wp_error( $pod ) ) {
	wp_die( 'Pod-Fehler: ' . $pod->get_error_message() );
}

$field = $pod['fields']['items']; // Whatsit\Field-Objekt
echo 'Feld: ' . $field['name'] . ' | type=' . $field['type'] . ' | disable_dfv=' . var_export( $field['disable_dfv'] ?? 'NOT SET', true ) . "\n";

// Wert wie im Admin: Feld-Klasse lädt den gespeicherten Wert (JSON → Array).
$field_class   = new Pods_Repeater_Field();
$value_decoded = $field_class->value( get_post_meta( $post_id, 'items', true ) );
echo 'value(): Array mit ' . count( $value_decoded ) . " Reihen\n";

$pod_obj = pods( 'test_repeater', $post_id );

// Exakt der Pods.php:411-Pfad: Feld-Objekt, Wert, Pod-Objekt, Item-ID.
$html = PodsForm::field( $field, $value_decoded, $pod_obj, $post_id );

echo 'PodsForm::field() liefert ' . strlen( $html ) . " Bytes HTML\n";

$checks = array(
	'HTML nicht leer'                   => strlen( $html ) > 100,
	'pods-repeater-Container'           => strpos( $html, 'pods-repeater' ) !== false,
	'3 Zeilen gerendert'                => substr_count( $html, 'pods-repeater-row' ) >= 3,
	'Erste Zeile im Output'             => strpos( $html, 'Erste Zeile' ) !== false,
	'Zweite Zeile im Output'            => strpos( $html, 'Zweite Zeile' ) !== false,
	'Dritte Zeile im Output'            => strpos( $html, 'Dritte Zeile' ) !== false,
	'Array-Name-Syntax (items[0][...])' => strpos( $html, 'items[0][subtitle]' ) !== false,
	'Add-Button'                        => strpos( $html, 'pods-repeater-add' ) !== false,
	'Move/Remove-Buttons'               => strpos( $html, 'pods-repeater-remove' ) !== false && strpos( $html, 'pods-repeater-move-up' ) !== false,
	'data-sub Attribute'                => strpos( $html, 'data-sub=' ) !== false,
);

$all_ok = true;
foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . "\n";
	if ( ! $ok ) {
		$all_ok = false;
	}
}

echo "\n=== HTML (Auszug) ===\n";
echo substr( $html, 0, 1200 ) . "\n";

echo "\n" . ( $all_ok ? 'T4-PIPELINE ERFOLGREICH' : 'T4-PIPELINE FEHLGESCHLAGEN' ) . "\n";
