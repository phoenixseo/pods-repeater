<?php
/**
 * T5-Spike (Judge-Review Commit 24e355c — Empfehlungen 1-4 + Escaping-Regression).
 *
 * Verifiziert im wp-env-Stack (Pods 3.3.9.1):
 *  1. pre_save/validate: vollständig leere Zeilen werden verworfen — die von
 *     input() geseedete Leerzeile persistiert nie als '[{}]'-Zeile; gefüllte
 *     Zeilen (auch 0/'0') bleiben erhalten. Plus echter Speicher-Roundtrip
 *     über pods_api()->save_pod_item() auf einem Wegwerf-Post.
 *  2. admin_enqueue_scripts registriert/enqueued ui/js/repeater.js
 *     (wp_script_is + Registry-Check).
 *  3. input() mit Whatsit\Field-Objekt (statt Array) rendert Sub-Inputs korrekt.
 *  4. Escaping-Regression: hostile Werte bleiben in allen Kontexten escaped.
 *
 * Ausführung: docker exec wp-env-pods-repeater-54d58dae-cli-1 sh -c
 *   'cd /var/www/html && wp eval-file .../tests/spike-t5.php 2>&1'
 */

$GLOBALS['t5_pass'] = 0;
$GLOBALS['t5_fail'] = 0;

function t5_check( $label, $ok, $detail = '' ) {
	if ( $ok ) {
		$GLOBALS['t5_pass']++;
		echo 'PASS  ' . $label . "\n";
	} else {
		$GLOBALS['t5_fail']++;
		echo 'FAIL  ' . $label . ( '' !== $detail ? '  → ' . $detail : '' ) . "\n";
	}
}

$field = new Pods_Repeater_Field();
echo 'Feld-Klasse: ' . get_class( $field ) . "\n\n";

// =====================================================================
// A. Empfehlung 1 — pre_save: vollständig leere Zeilen verwerfen (Unit)
// =====================================================================
echo "=== A. pre_save: leere Zeilen verwerfen ===\n";

// Nie angefasstes Feld: input() seedet eine leere Zeile array().
$json = $field->pre_save( array( array() ) );
t5_check( 'pre_save: geseedete Leerzeile array() → "[]"', '[]' === $json, var_export( $json, true ) );

// Leere Sub-Werte aus dem abgeschickten Formular (''/'').
$json = $field->pre_save( array( array( 'subtitle' => '', 'link' => '' ) ) );
t5_check( 'pre_save: Zeile mit lauter ""-Werten → "[]"', '[]' === $json, var_export( $json, true ) );

// Gemischt: gefüllte Zeile + geseedete Leerzeile → nur die gefüllte bleibt.
$rows = json_decode( (string) $field->pre_save( array(
	array( 'subtitle' => 'Erste Zeile', 'link' => 'https://example.com/1' ),
	array( 'subtitle' => '', 'link' => '' ),
) ), true );
t5_check( 'pre_save: gefüllte Zeile bleibt, leere fällt (1 Zeile)',
	is_array( $rows ) && 1 === count( $rows ) && 'Erste Zeile' === $rows[0]['subtitle'], var_export( $rows, true ) );

// Teilgefüllte Zeile (nur link) ist NICHT leer → bleibt.
$rows = json_decode( (string) $field->pre_save( array(
	array( 'subtitle' => '', 'link' => 'https://example.com/teil' ),
) ), true );
t5_check( 'pre_save: teilgefüllte Zeile bleibt erhalten',
	is_array( $rows ) && 1 === count( $rows ) && 'https://example.com/teil' === $rows[0]['link'], var_export( $rows, true ) );

// Explizite 0 zählt als gefüllt (number-Sub-Feld).
$rows = json_decode( (string) $field->pre_save( array( array( 'zaehler' => 0 ) ) ), true );
t5_check( 'pre_save: Wert 0 zählt als gefüllt (Zeile bleibt)',
	is_array( $rows ) && 1 === count( $rows ) && 0 === $rows[0]['zaehler'], var_export( $rows, true ) );

// Nicht-Array-Werte passieren unverändert (kein Verhaltensbruch).
t5_check( 'pre_save: Nicht-Array (String) passt durch', 'roh' === $field->pre_save( 'roh' ) );

echo "\n";

// =====================================================================
// B. Empfehlung 1 — validate: leere Zeilen blockieren required nicht
// =====================================================================
echo "=== B. validate: leere Zeilen vs. required-Sub-Felder ===\n";

$opts_required = array(
	'disable_dfv'         => true,
	'name'                => 'items',
	'repeater_sub_fields' => wp_json_encode( array(
		array( 'name' => 'subtitle', 'label' => 'Untertitel', 'type' => 'text', 'required' => 1 ),
		array( 'name' => 'link', 'label' => 'Link', 'type' => 'url' ),
	) ),
);

// Nie angefasstes Feld (nur die geseedete Leerzeile) → kein Blocker.
$res = $field->validate( array( array( 'subtitle' => '', 'link' => '' ) ), 'items', $opts_required );
t5_check( 'validate: nur leere Zeile + required-Sub → true (kein Blocker)', true === $res,
	is_wp_error( $res ) ? $res->get_error_message() : var_export( $res, true ) );

// Teilgefüllte Zeile, required-Wert leer → bleibt Blocker.
$res = $field->validate( array( array( 'subtitle' => '', 'link' => 'https://x.example' ) ), 'items', $opts_required );
t5_check( 'validate: teilgefüllte Zeile + required leer → WP_Error', is_wp_error( $res ),
	is_wp_error( $res ) ? '' : var_export( $res, true ) );

// Gefüllte Zeile, required erfüllt → true.
$res = $field->validate( array( array( 'subtitle' => 'Okay', 'link' => '' ) ), 'items', $opts_required );
t5_check( 'validate: required erfüllt → true', true === $res,
	is_wp_error( $res ) ? $res->get_error_message() : var_export( $res, true ) );

echo "\n";

// =====================================================================
// C. Empfehlung 1 — echter Speicher-Roundtrip über pods_api()->save_pod_item
// =====================================================================
echo "=== C. Roundtrip: save_pod_item (leerer Seed vs. gefüllte Zeilen) ===\n";

$api = pods_api();
$pod = $api->load_pod( array( 'name' => 'test_repeater' ) );
if ( is_wp_error( $pod ) ) {
	t5_check( 'Roundtrip: Pod test_repeater ladbar', false, $pod->get_error_message() );
} else {
	echo 'Pod: ' . $pod['name'] . ' | Typ: ' . $pod['type'] . "\n";
	$src_post_type = get_post_type( 23 );
	$post_type     = $src_post_type ? $src_post_type : $pod['name'];
	$new_id        = wp_insert_post( array(
		'post_type'   => $post_type,
		'post_status' => 'publish',
		'post_title'  => 'T5-Spike Roundtrip ' . wp_generate_password( 6, false ),
	) );
	t5_check( 'Roundtrip: Wegwerf-Post angelegt (ID ' . $new_id . ', Typ ' . $post_type . ')', ! is_wp_error( $new_id ) && $new_id > 0 );

	if ( ! is_wp_error( $new_id ) && $new_id > 0 ) {
		// 1) Nie angefasstes Feld simulieren: Formular schickt nur die
		//    geseedete Leerzeile (items[0][subtitle]='' items[0][link]='').
		$saved = $api->save_pod_item( array(
			'pod'        => 'test_repeater',
			'id'         => $new_id,
			'from'       => 'save',
			'error_mode' => 'wp_error',
			'data'       => array( 'items' => array( array( 'subtitle' => '', 'link' => '' ) ) ),
		) );

		$raw_meta = get_post_meta( $new_id, 'items', true );
		$ok       = ! is_wp_error( $saved );
		$ok       = $ok && false === strpos( (string) $raw_meta, '[{}]' );
		$decoded  = json_decode( (string) $raw_meta, true );
		$ok       = $ok && ( ! is_array( $decoded ) || 0 === count( $decoded ) );
		t5_check( 'Roundtrip: Save leerer Seed → keine [{}]-Zeile persistiert (Meta: ' . var_export( $raw_meta, true ) . ')',
			$ok, is_wp_error( $saved ) ? $saved->get_error_message() : '' );

		// 2) Gefüllte Zeilen → beide müssen persistieren.
		$saved = $api->save_pod_item( array(
			'pod'        => 'test_repeater',
			'id'         => $new_id,
			'from'       => 'save',
			'error_mode' => 'wp_error',
			'data'       => array( 'items' => array(
				array( 'subtitle' => 'Roundtrip Eins', 'link' => 'https://example.com/eins' ),
				array( 'subtitle' => 'Roundtrip Zwei', 'link' => 'https://example.com/zwei' ),
			) ),
		) );

		$raw_meta = get_post_meta( $new_id, 'items', true );
		$decoded  = json_decode( (string) $raw_meta, true );
		$ok       = ! is_wp_error( $saved ) && is_array( $decoded ) && 2 === count( $decoded );
		$ok       = $ok && 'Roundtrip Eins' === $decoded[0]['subtitle'] && 'Roundtrip Zwei' === $decoded[1]['subtitle'];
		t5_check( 'Roundtrip: 2 gefüllte Zeilen bleiben erhalten', $ok,
			is_wp_error( $saved ) ? $saved->get_error_message() : var_export( $raw_meta, true ) );

		wp_delete_post( $new_id, true );
		t5_check( 'Roundtrip: Wegwerf-Post aufgeräumt', ! get_post( $new_id ) );
	}
}

echo "\n";

// =====================================================================
// D. Empfehlung 2 — admin_enqueue_scripts registriert ui/js/repeater.js
// =====================================================================
echo "=== D. Enqueue: ui/js/repeater.js im Admin ===\n";

t5_check( 'Enqueue: Hook admin_enqueue_scripts gebunden',
	false !== has_action( 'admin_enqueue_scripts', 'pods_repeater_enqueue_admin_assets' ) );

// Im CLI gibt es keinen Screen (get_current_screen() = null) → Callback lädt
// konservativ; der Hook selbst ist die Admin-Grenze.
do_action( 'admin_enqueue_scripts', 'post.php' );

$registered = wp_script_is( 'pods-repeater', 'registered' );
$enqueued   = wp_script_is( 'pods-repeater', 'enqueued' );
t5_check( 'Enqueue: Handle "pods-repeater" registriert', $registered );
t5_check( 'Enqueue: Handle "pods-repeater" enqueued', $enqueued );

$src = '';
if ( $registered ) {
	$scripts = wp_scripts();
	$src     = isset( $scripts->registered['pods-repeater']->src ) ? $scripts->registered['pods-repeater']->src : '';
	t5_check( 'Enqueue: src zeigt auf ui/js/repeater.js', false !== strpos( $src, 'ui/js/repeater.js' ), $src );
}
t5_check( 'Enqueue: JS-Datei existiert auf Disk', file_exists( PODS_REPEATER_DIR . 'ui/js/repeater.js' ) );

// Button-Funktionen erreichbar: Event-Delegation + Renumber-Rebuild sind in
// der Datei vorhanden (Statik-Check; DOM-Check optional/nicht im CLI möglich).
$js = file_get_contents( PODS_REPEATER_DIR . 'ui/js/repeater.js' );
t5_check( 'Enqueue: Add/Remove/Move-Buttons im JS verdrahtet',
	false !== strpos( $js, '.pods-repeater-add' ) && false !== strpos( $js, '.pods-repeater-remove' ) && false !== strpos( $js, '.pods-repeater-move-up' ) );
t5_check( 'Enqueue: renumber baut Namen aus data-sub (keine /_\\d+_/-Regex)',
	false !== strpos( $js, "name + '[' + i + '][' + sub + ']'" ) && false === strpos( $js, 'replace(/_\\d+_/' ) );

echo "\n";

// =====================================================================
// E. Empfehlung 3 — input() mit Whatsit\Field-Objekt rendert Sub-Inputs
// =====================================================================
echo "=== E. input() mit Whatsit\\Field-Objekt (get_args-Normalisierung) ===\n";

$field_obj = $pod['fields']['items'];
echo 'options-Typ: ' . ( is_object( $field_obj ) ? get_class( $field_obj ) : gettype( $field_obj ) ) . "\n";
echo 'disable_dfv: ' . var_export( ( is_object( $field_obj ) && method_exists( $field_obj, 'get_arg' ) ) ? $field_obj->get_arg( 'disable_dfv' ) : 'n/a', true ) . "\n";

$subs = array();
if ( is_object( $field_obj ) && method_exists( $field_obj, 'get_args' ) ) {
	$args = $field_obj->get_args();
	$subs = json_decode( (string) ( isset( $args['repeater_sub_fields'] ) ? $args['repeater_sub_fields'] : '[]' ), true );
}
$subs = is_array( $subs ) ? $subs : array();
echo 'Sub-Felder: ' . implode( ', ', array_map( static function ( $s ) {
	return isset( $s['name'] ) ? $s['name'] . ':' . ( isset( $s['type'] ) ? $s['type'] : '?' ) : '?';
}, $subs ) ) . "\n";

// Direkter input()-Aufruf mit dem Whatsit-Feld-Objekt als $options.
ob_start();
$field->input( 'items', array( array( 'subtitle' => 'Objekt-Pfad', 'link' => 'https://example.com/obj' ) ), $field_obj, null, 23 );
$html_obj = ob_get_clean();

t5_check( 'input(Obj): rendert pods-repeater-Container', false !== strpos( $html_obj, 'pods-repeater-rows' ) );
t5_check( 'input(Obj): rendert Sub-Inputs (data-sub)', false !== strpos( $html_obj, 'data-sub=' ) );
$sub_ok = true;
foreach ( $subs as $sub ) {
	if ( ! isset( $sub['name'] ) ) {
		continue;
	}
	if ( false === strpos( $html_obj, 'items[0][' . $sub['name'] . ']' ) ) {
		$sub_ok = false;
		echo '  (fehlendes Sub-Input: items[0][' . $sub['name'] . '])' . "\n";
	}
}
t5_check( 'input(Obj): Array-Name-Syntax items[0][<sub>] für alle Sub-Felder', $sub_ok );
t5_check( 'input(Obj): Feldwert "Objekt-Pfad" im HTML', false !== strpos( $html_obj, 'Objekt-Pfad' ) );
t5_check( 'input(Obj): keine DFV-Script-Ausgabe (PHP-Render aktiv)', false === strpos( $html_obj, 'podsforms' ) && false === strpos( $html_obj, 'dfv' ) );

echo "\n";

// =====================================================================
// F. Escaping-Regression: hostile Werte bleiben escaped
// =====================================================================
echo "=== F. Escaping: hostile Werte in text/url/textarea ===\n";

$hostile    = '<script>alert(1)</script>';
$hostile_ui = '"><img src=x onerror=alert(1)>';

// F1: Echter Pod-Pfad (text + url, je nach Sub-Konfiguration).
$row_hostile = array();
foreach ( $subs as $sub ) {
	if ( isset( $sub['name'] ) && 'number' !== ( isset( $sub['type'] ) ? $sub['type'] : '' ) ) {
		$row_hostile[ $sub['name'] ] = 0 === count( $row_hostile ) ? $hostile : $hostile_ui;
	}
}
ob_start();
$field->input( 'items', array( $row_hostile ), $field_obj, null, 23 );
$html_xss = ob_get_clean();

t5_check( 'Escaping: kein rohes <script> im HTML', false === strpos( $html_xss, '<script>' ) );
t5_check( 'Escaping: <script> ist &lt;script&gt;-kodiert', false !== strpos( $html_xss, '&lt;script&gt;' ) );
t5_check( 'Escaping: kein Attribut-Breakout ("><img roh)', false === strpos( $html_xss, '"><img' ) );
t5_check( 'Escaping: Breakout-Versuch &quot;-kodiert', false !== strpos( $html_xss, '&quot;&gt;&lt;img' ) );
t5_check( 'Escaping: onerror nur als escaped Daten-Text (kein " onerror=" -Attribut)', false === strpos( $html_xss, ' onerror="' ) );
t5_check( 'Escaping: onerror-Versuch &lt;-escaped im value', false !== strpos( $html_xss, '&lt;img src=x onerror=alert(1)&gt;' ) );

// F2: textarea-Zweig (esc_textarea) über ein manuelles Options-Array.
$opts_textarea = array(
	'disable_dfv'         => true,
	'name'                => 'items',
	'repeater_sub_fields' => wp_json_encode( array(
		array( 'name' => 'notiz', 'label' => 'Notiz', 'type' => 'textarea' ),
	) ),
);
ob_start();
$field->input( 'items', array( array( 'notiz' => $hostile ) ), $opts_textarea, null, 23 );
$html_ta = ob_get_clean();

t5_check( 'Escaping (textarea): kein rohes <script>', false === strpos( $html_ta, '<script>' ) );
t5_check( 'Escaping (textarea): esc_textarea kodiert &lt;script&gt;', false !== strpos( $html_ta, '&lt;script&gt;' ) );

echo "\n";
echo 'ERGEBNIS: ' . $GLOBALS['t5_pass'] . ' PASS, ' . $GLOBALS['t5_fail'] . ' FAIL' . "\n";
echo ( 0 === $GLOBALS['t5_fail'] ? 'T5 ERFOLGREICH' : 'T5 FEHLGESCHLAGEN' ) . "\n";
