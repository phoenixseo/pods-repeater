<?php
/**
 * RepeaterNestedTest — serverseitige Ablehnung verschachtelter Repeater.
 *
 * Ausgangsbefund (S21, 2026-10-20): ui/js/repeater.js behauptete in einem
 * Kommentar "nested repeaters are rejected server-side". Das war falsch.
 * validate() prüfte ausschliesslich das Zeilenlimit und required-Sub-Felder;
 * ein Sub-Feld vom Typ 'repeater' kam ungeprüft durch. Die Feldgruppen-
 * Registry schliesst 'repeater' zwar aus
 * (pods_repeater_supported_sub_types() in includes/field-groups.php), aber
 * die Inline-JSON-Variante (`repeater_sub_fields`) geht an ihr vorbei.
 *
 * Diese Klasse hält die Durchsetzung auf beiden Ebenen fest:
 *
 *   - validate(): Konfiguration mit einem verschachtelten Repeater liefert
 *     ein Meldungs-Array (Pods-Vertrag: true oder Liste von Strings), NICHT
 *     true. Die Prüfung greift vor Limit und required — die Struktur ist
 *     grundlegender kaputt als eine fehlende Pflichtangabe.
 *   - pre_save(): derselbe Aufbau wird auf dem Schreibweg verworfen, damit
 *     REST und pods()->save() (die validate() nicht durchlaufen) keine
 *     verschachtelten Daten persistieren.
 *
 * Die Existenzprüfung der Feldklasse ist eine harte Assertion (kein Skip).
 *
 * @package PodsRepeater
 */

/**
 * Class RepeaterNestedTest
 */
class RepeaterNestedTest extends WP_UnitTestCase {

	/**
	 * Feldklasse unter Test.
	 *
	 * @var PodsField_Repeater
	 */
	protected $field;

	/**
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->assertTrue(
			class_exists( 'PodsField_Repeater' ),
			'PodsField_Repeater nicht geladen — Pods/pods-repeater wurden im muplugins_loaded-Bootstrap nicht aktiv (siehe tests/bootstrap.php) oder die Feldklasse wurde umbenannt.'
		);

		$this->field = new PodsField_Repeater();
	}

	/**
	 * Baut Feld-Optionen aus Inline-Sub-Felddefinitionen.
	 *
	 * @param array $sub_fields Sub-Felddefinitionen.
	 * @param array $extra      Zusätzliche Optionen.
	 *
	 * @return array
	 */
	protected function options_with( $sub_fields, $extra = array() ) {
		return array_merge(
			array(
				'repeater_sub_fields' => wp_json_encode( $sub_fields ),
				'disable_dfv'         => true,
			),
			$extra
		);
	}

	/**
	 * Sub-Felddefinition eines verschachtelten Repeaters.
	 *
	 * @return array
	 */
	protected function nested_sub_field() {
		return array(
			'name'  => 'inner_items',
			'label' => 'Innere Einträge',
			'type'  => 'repeater',
		);
	}

	/**
	 * Inline-JSON mit Sub-Feld type=repeater → validate() liefert ein
	 * nicht-leeres Fehler-Array, nicht true.
	 *
	 * @return void
	 */
	public function test_validate_rejects_inline_nested_repeater() {
		$options = $this->options_with(
			array(
				array(
					'name'  => 'title',
					'label' => 'Titel',
					'type'  => 'text',
				),
				$this->nested_sub_field(),
			)
		);

		$rows = array(
			array(
				'title'       => 'Zeile 1',
				'inner_items' => array( array( 'x' => 'verschachtelt' ) ),
			),
		);

		$result = $this->field->validate( $rows, 'items', $options );

		$this->assertIsArray( $result, 'validate() muss bei verschachteltem Repeater ein Meldungs-Array liefern (Pods-Vertrag: keine WP_Error-Objekte).' );
		$this->assertNotEmpty( $result, 'Das Meldungs-Array darf nicht leer sein.' );
		$this->assertNotTrue( $result, 'validate() darf verschachtelte Repeater nicht durchlassen.' );
		$this->assertIsString( $result[0] );
	}

	/**
	 * Gegenprobe: dieselbe Struktur mit reinem text-Sub-Feld bleibt gültig.
	 *
	 * @return void
	 */
	public function test_validate_passes_without_nested_repeater() {
		$options = $this->options_with(
			array(
				array(
					'name'  => 'title',
					'label' => 'Titel',
					'type'  => 'text',
				),
			)
		);

		$rows = array( array( 'title' => 'Zeile 1' ) );

		$this->assertTrue(
			$this->field->validate( $rows, 'items', $options ),
			'Der Fix darf legitime Felder nicht blockieren.'
		);
	}

	/**
	 * Die Meldung nennt das Label des betroffenen Sub-Feldes.
	 *
	 * @return void
	 */
	public function test_validate_error_names_the_sub_field_label() {
		$options = $this->options_with( array( $this->nested_sub_field() ) );
		$rows    = array( array( 'inner_items' => array( array( 'x' => 'y' ) ) ) );

		$result = $this->field->validate( $rows, 'items', $options );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Nested repeaters are not supported', $result[0] );
		$this->assertStringContainsString( 'Innere Einträge', $result[0], 'Die Meldung muss das Label nennen, damit der Redakteur das Feld findet.' );
	}

	/**
	 * Fehlt das Label, greift der Name als Fallback.
	 *
	 * @return void
	 */
	public function test_validate_error_falls_back_to_sub_field_name() {
		$options = $this->options_with(
			array(
				array(
					'name' => 'inner_items',
					'type' => 'repeater',
				),
			)
		);

		$result = $this->field->validate( array( array( 'inner_items' => array() ) ), 'items', $options );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'inner_items', $result[0] );
	}

	/**
	 * Die Strukturprüfung greift vor Limit und required: ein Aufbau, der
	 * beide Regeln zusätzlich verletzt, wird als verschachtelter Repeater
	 * gemeldet (grundlegenderer Fehler), nicht als Zeilenlimit.
	 *
	 * @return void
	 */
	public function test_validate_nested_check_precedes_limit_and_required() {
		$options = $this->options_with(
			array(
				array(
					'name'     => 'title',
					'label'    => 'Titel',
					'type'     => 'text',
					'required' => true,
				),
				$this->nested_sub_field(),
			),
			array( 'repeater_limit' => 1 )
		);

		// Zwei Zeilen (über dem Limit von 1), title leer (required-Verstoss).
		$rows = array(
			array(
				'title'       => '',
				'inner_items' => array(),
			),
			array(
				'title'       => '',
				'inner_items' => array(),
			),
		);

		$result = $this->field->validate( $rows, 'items', $options );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result, 'Es darf genau die Struktur-Meldung zurückkommen.' );
		$this->assertStringContainsString( 'Nested repeaters are not supported', $result[0] );
		$this->assertStringNotContainsString( 'at most', $result[0], 'Die Strukturprüfung muss vor der Limit-Prüfung greifen.' );
	}

	/**
	 * pre_save() verwirft das verschachtelte Sub-Feld (Schreibweg ohne
	 * Validierung, z.B. REST oder pods()->save()).
	 *
	 * @return void
	 */
	public function test_pre_save_strips_nested_repeater_values() {
		$options = $this->options_with(
			array(
				array(
					'name'  => 'title',
					'label' => 'Titel',
					'type'  => 'text',
				),
				$this->nested_sub_field(),
			)
		);

		$rows = array(
			array(
				'title'       => 'Zeile 1',
				'inner_items' => array( array( 'x' => 'verschachtelt' ) ),
			),
			array(
				'title'       => 'Zeile 2',
				'inner_items' => array(),
			),
		);

		$saved = $this->field->pre_save( $rows, null, 'items', $options );
		$clean = json_decode( $saved, true );

		$this->assertIsArray( $clean, 'pre_save() muss JSON liefern.' );
		$this->assertCount( 2, $clean, 'Die Zeilen selbst bleiben erhalten — nur das verschachtelte Sub-Feld fällt weg.' );
		$this->assertSame( array( 'Zeile 1', 'Zeile 2' ), wp_list_pluck( $clean, 'title' ) );
		$this->assertArrayNotHasKey( 'inner_items', $clean[0], 'Verschachtelte Repeater-Daten dürfen nicht persistieren.' );
		$this->assertArrayNotHasKey( 'inner_items', $clean[1] );
		$this->assertStringNotContainsString( 'verschachtelt', $saved );
	}

	/**
	 * Gegenprobe zum Schreibweg: ohne verschachtelten Repeater bleibt das
	 * gespeicherte JSON vollständig.
	 *
	 * @return void
	 */
	public function test_pre_save_keeps_legit_sub_field_values() {
		$options = $this->options_with(
			array(
				array(
					'name'  => 'title',
					'label' => 'Titel',
					'type'  => 'text',
				),
			)
		);

		$rows = array( array( 'title' => 'Zeile 1' ) );

		$saved = json_decode( $this->field->pre_save( $rows, null, 'items', $options ), true );

		$this->assertSame( array( 'Zeile 1' ), wp_list_pluck( $saved, 'title' ) );
	}
}
