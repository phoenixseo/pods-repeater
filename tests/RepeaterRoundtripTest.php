<?php
/**
 * RepeaterRoundtripTest — JSON-Roundtrip des Pods-Repeater-Felds.
 *
 * Erste echte Testklasse auf der eigenständigen WP-Testbasis
 * (wp-phpunit 6.8.2 + PHPUnit 9.6, siehe tests/bootstrap.php). Inhaltliche
 * Vorlage: Wegwerf-Proben an der echten Installation (dort verifizierte
 * Save-Signatur: save_pod_item mit 'data' + 'from' => 'save').
 *
 * Klassenname: seit dem Core-Port (9fce5a3) heißt der Feldtyp
 * PodsField_Repeater (classes/fields/repeater.php), nicht mehr
 * Pods_Repeater_Field. Die Existenzprüfung ist eine harte Assertion —
 * eine künftige Umbenennung soll den Lauf rot machen, nicht still skippen.
 *
 * Getestet wird der Speicherpfad des Felds, wie ihn Pods in wp_postmeta
 * nutzt (JSON-String):
 *   - (a) pre_save(): Array (Zeilen) → JSON-String; update_post_meta
 *         persistiert den String roh; value(): JSON-String → Array.
 *         Verlustfreier Roundtrip über beide Richtungen.
 *   - (b) pre_save() verwirft vollständig leere Zeilen — die von input()
 *         geseedete Template-Zeile darf nie als '[{}]' persistieren.
 *   - (c) Feld-Option disable_dfv hat Default true (PHP-Renderer-Pfad).
 *
 * Die Tests laufen gegen eine FRISCHE wp-tests-DB (kein Pod-Seed nötig):
 * sie brauchen nur WP-Core-Meta + die Feldklasse, kein Pods-Pod-Objekt.
 *
 * @package PodsRepeater
 */

/**
 * Class RepeaterRoundtripTest
 */
class RepeaterRoundtripTest extends WP_UnitTestCase {

	/**
	 * Feldklasse unter Test (echte Plugin-Klasse, nicht die Pods-Bridge).
	 *
	 * @var PodsField_Repeater
	 */
	protected $field;

	/**
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// Harte Assertion statt markTestSkipped(): Lädt der Bootstrap den
		// Feldtyp nicht (oder wird die Klasse umbenannt), muss der Lauf rot
		// werden. Ein Skip würde die drei inhaltlichen Tests still entwerten.
		$this->assertTrue(
			class_exists( 'PodsField_Repeater' ),
			'PodsField_Repeater nicht geladen — Pods/pods-repeater wurden im muplugins_loaded-Bootstrap nicht aktiv (siehe tests/bootstrap.php) oder die Feldklasse wurde umbenannt.'
		);

		$this->field = new PodsField_Repeater();

		$this->assertInstanceOf(
			'PodsField',
			$this->field,
			'PodsField_Repeater muss von der Pods-Basisklasse PodsField erben.'
		);
	}

	/**
	 * (a) Zwei gefüllte Zeilen: pre_save() → update_post_meta → rohes
	 * wp_postmeta → value() ergibt einen verlustfreien JSON-Roundtrip.
	 * Bewusst mit UTF-8-Inhalten (JSON_UNESCAPED_UNICODE) und einem
	 * frischen Wegwerf-Post — exakt der Admin-Speicherpfad (JSON-String
	 * in wp_postmeta), ohne Abhängigkeit von einem geseedeten Pod.
	 *
	 * @return void
	 */
	public function test_two_filled_rows_roundtrip_lossless_via_wp_postmeta() {
		$rows = array(
			array(
				'subtitle' => 'Erste Zeile — Ünïcode',
				'link'     => 'https://example.com/erste?q=äöü',
			),
			array(
				'subtitle' => 'Zweite Zeile (geändert)',
				'link'     => 'https://example.com/zweite',
			),
		);

		// Array → JSON-String (pre_save, On-Write-Pfad).
		$json = $this->field->pre_save( $rows );
		$this->assertIsString( $json );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded, 'pre_save() muss valides JSON liefern.' );
		$this->assertCount( 2, $decoded );

		// Persistenz: roher wp_postmeta-String (wie der Admin-Reload ihn liest).
		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );

		update_post_meta( $post_id, 'items', $json );
		$stored = get_post_meta( $post_id, 'items', true );

		// Wert-Vergleich statt Byte-Vergleich: WP normalisiert beim Meta-
		// Roundtrip JSON-Slash-Escaping (\/ → /, stripslashes) — semantisch
		// identisch. Garantiert ist verlustfreier ROUNDTRIP DER WERTE.
		$this->assertIsString( $stored, 'wp_postmeta muss einen JSON-String enthalten.' );
		$this->assertJson( (string) $stored );
		$this->assertSame( $rows, json_decode( (string) $stored, true ), 'Meta-Roundtrip muss die Zeilen verlustfrei rekonstruieren.' );
		$this->assertStringNotContainsString( '[{}]', (string) $stored );

		// Rückweg: JSON-String → Array (value, On-Read-Pfad).
		$value = $this->field->value( $stored );
		$this->assertIsArray( $value );
		$this->assertSame( $rows, $value, 'value() muss das Zeilen-Array verlustfrei rekonstruieren.' );

		// Beide Zeilen einzeln geprüft (Reihenfolge A vor B bleibt erhalten).
		$this->assertSame( 'Erste Zeile — Ünïcode', $value[0]['subtitle'] );
		$this->assertSame( 'https://example.com/erste?q=äöü', $value[0]['link'] );
		$this->assertSame( 'Zweite Zeile (geändert)', $value[1]['subtitle'] );
		$this->assertSame( 'https://example.com/zweite', $value[1]['link'] );
	}

	/**
	 * (b) Vollständig leere Zeilen werden von pre_save() verworfen —
	 * insbesondere die von input() geseedete Template-Zeile. Kein '[{}]'-
	 * Artefakt, keine leeren Objekte im persistierten JSON. Gefüllte Zeilen
	 * (auch teilgefüllte) bleiben erhalten.
	 *
	 * @return void
	 */
	public function test_pre_save_discards_empty_rows_no_empty_object_artifact() {
		// Geseedete Einzel-Leerzeile (array()) → leeres Array-JSON.
		$this->assertSame( '[]', $this->field->pre_save( array( array() ) ) );
		// Abgeschickte Formularzeile mit lauter ''-Werten → ebenfalls leer.
		$this->assertSame( '[]', $this->field->pre_save( array( array( 'subtitle' => '', 'link' => '' ) ) ) );

		// Gemischt: gefüllte + geseedete Leerzeile + Formular-Leerzeile.
		$json = $this->field->pre_save(
			array(
				array(
					'subtitle' => 'Bleibt erhalten',
					'link'     => 'https://example.com/bleibt',
				),
				array( 'subtitle' => '', 'link' => '' ),
				array(),
			)
		);

		$this->assertStringNotContainsString( '[{}]', (string) $json );
		$this->assertStringNotContainsString( '{}', (string) $json );

		$decoded = json_decode( (string) $json, true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 1, $decoded, 'Nur die gefüllte Zeile darf persistieren.' );
		$this->assertSame( 'Bleibt erhalten', $decoded[0]['subtitle'] );
	}

	/**
	 * (c) Feld-Option disable_dfv: Default muss true sein (PHP-Renderer via
	 * input(), kein DFV-React — Pflicht für Custom-Feldtypen ohne DFV-
	 * Komponente; siehe options() in classes/fields/repeater.php).
	 *
	 * @return void
	 */
	public function test_disable_dfv_option_defaults_to_true() {
		$options = $this->field->options();

		$this->assertIsArray( $options );
		$this->assertArrayHasKey( 'disable_dfv', $options );
		$this->assertArrayHasKey( 'default', $options['disable_dfv'] );
		$this->assertSame( true, $options['disable_dfv']['default'], 'disable_dfv muss per Default aktiv (true) sein.' );
	}
}
