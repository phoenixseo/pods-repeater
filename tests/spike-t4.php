<?php
/**
 * T4-Spike (angepasst): Nutzt den BESTEHENDEN Pod 'test_repeater' (Feld 'items',
 * Typ repeater, disable_dfv='1', Sub-Felder subtitle/link) und Post 23 mit 3
 * gespeicherten JSON-Zeilen. Rendert input() WIE PODS ES IM ADMIN TUT:
 * PodsForm::options() macht ob_start() → input() (echo-t) → ob_get_clean().
 * Der Rückgabewert von input() wird von Pods IGNORIERT — nur geechtes HTML zählt.
 * Wird via wp eval-file im wp-env-CLI-Container ausgeführt.
 */

$post_id = 23;
$meta    = get_post_meta( $post_id, 'items', true );
echo 'Post ' . $post_id . ' Meta items: ' . var_export( $meta, true ) . "\n";

$api   = pods_api();
$pod   = $api->load_pod( array( 'name' => 'test_repeater' ) );
if ( is_wp_error( $pod ) ) {
	wp_die( 'Pod-Fehler: ' . $pod->get_error_message() );
}

$field_def = $pod['fields']['items'];
echo 'Feld items: type=' . $field_def['type'] . ' disable_dfv=' . var_export( isset( $field_def['disable_dfv'] ) ? $field_def['disable_dfv'] : 'NOT SET', true ) . "\n";

// So kommt der Wert im Admin an: Pods lädt die Feld-Klasse und ruft value() auf.
$field = new Pods_Repeater_Field();
$value_decoded = $field->value( $meta );
echo 'value() liefert: ' . ( is_array( $value_decoded ) ? 'Array mit ' . count( $value_decoded ) . ' Reihen' : var_export( $value_decoded, true ) ) . "\n";

// Whatsit\Field → Array (so wie PodsForm::options() die Feld-Optionen übergibt).
$options = is_object( $field_def ) && method_exists( $field_def, 'get_args' ) ? $field_def->get_args() : (array) $field_def;
$options['disable_dfv'] = true;

// Pods-Integration exakt nachbilden: ob_start → input() → ob_get_clean.
ob_start();
$field->input( 'items', $value_decoded, $options, null, $post_id );
$html = ob_get_clean();

// Prüfungen: Werte im HTML? 3 Reihen? Move/Add/Remove-Buttons? Array-Name-Syntax?
$checks = array(
	'3 Zeilen gerendert'  => substr_count( $html, 'pods-repeater-row' ) >= 3,
	'Erste Zeile'         => strpos( $html, 'Erste Zeile' ) !== false,
	'Zweite Zeile'        => strpos( $html, 'Zweite Zeile' ) !== false,
	'Dritte Zeile'        => strpos( $html, 'Dritte Zeile' ) !== false,
	'Link 1'              => strpos( $html, 'https://example.com/1' ) !== false,
	'Add-Button'          => strpos( $html, 'pods-repeater-add' ) !== false,
	'Remove-Button'       => strpos( $html, 'pods-repeater-remove' ) !== false,
	'Move-up-Button'      => strpos( $html, 'pods-repeater-move-up' ) !== false,
	'Move-down-Button'    => strpos( $html, 'pods-repeater-move-down' ) !== false,
	'data-sub vorhanden'  => strpos( $html, 'data-sub=' ) !== false,
	'Array-Name-Syntax'   => strpos( $html, 'items[0][subtitle]' ) !== false,
);

$all_ok = true;
foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . "\n";
	if ( ! $ok ) {
		$all_ok = false;
	}
}

echo "\n=== HTML (Auszug) ===\n";
echo substr( $html, 0, 1500 ) . "\n";

echo "\n" . ( $all_ok ? 'T4 ERFOLGREICH' : 'T4 FEHLGESCHLAGEN' ) . "\n";
