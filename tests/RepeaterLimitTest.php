<?php
/**
 * RepeaterLimitTest — serverseitige Durchsetzung von `repeater_limit`.
 *
 * Ausgangsbefund (S8, 2026-10-19): Die Feldoption `repeater_limit` wurde
 * ausschliesslich im Browser ausgewertet (ui/js/repeater.js blockt "Add row").
 * Jeder andere Schreibweg — Import, Code via pods()->save(), ein manipulierter
 * POST — kam ungeprueft durch: `pre_save()` speicherte drei Zeilen auch bei
 * `repeater_limit = 2`. Die Invariante "Limit wird respektiert" war damit
 * serverseitig nicht wahr.
 *
 * Diese Klasse haelt beide Ebenen fest:
 *
 *   - validate(): zu viele gefuellte Zeilen liefern einen WP_Error mit dem
 *     Code `pods_repeater_limit_exceeded`. Vollstaendig leere Zeilen zaehlen
 *     nicht mit (input() seedet eine Leerzeile, die darf das Limit nicht
 *     verbrauchen).
 *   - pre_save(): schneidet auf das Limit zurueck. Das ist der Schutz fuer
 *     Schreibwege, die validate() nicht durchlaufen; die Reihenfolge bleibt
 *     erhalten (die ersten N Zeilen gewinnen).
 *
 * Die Existenzpruefung der Feldklasse ist eine harte Assertion (kein Skip).
 *
 * @package PodsRepeater
 */

/**
 * Class RepeaterLimitTest
 */
class RepeaterLimitTest extends WP_UnitTestCase {

	/**
	 * Feldklasse unter Test.
	 *
	 * @var PodsField_Repeater
	 */
	protected $field;

	/**
	 * Feld-Optionen mit einem required-Sub-Feld.
	 *
	 * @var array
	 */
	protected $options;

	/**
	 * Drei gefuellte Zeilen.
	 *
	 * @var array
	 */
	protected $rows;

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

		$this->options = array(
			'repeater_sub_fields' => wp_json_encode(
				array(
					array(
						'name'     => 'title',
						'label'    => 'Titel',
						'type'     => 'text',
						'required' => true,
					),
				)
			),
			'disable_dfv'         => true,
		);

		$this->rows = array(
			array( 'title' => 'Zeile 1' ),
			array( 'title' => 'Zeile 2' ),
			array( 'title' => 'Zeile 3' ),
		);
	}

	/**
	 * Baut Optionen mit gesetztem Limit.
	 *
	 * @param int $limit Max-Zeilen (0 = unbegrenzt).
	 *
	 * @return array
	 */
	protected function options_with_limit( $limit ) {
		$options                    = $this->options;
		$options['repeater_limit'] = $limit;

		return $options;
	}

	/**
	 * Genau am Limit ist gueltig.
	 *
	 * @return void
	 */
	public function test_validate_passes_at_limit() {
		$rows = array_slice( $this->rows, 0, 2 );

		$this->assertTrue( $this->field->validate( $rows, 'items', $this->options_with_limit( 2 ) ) );
	}

	/**
	 * Ueber dem Limit liefert validate() einen WP_Error.
	 *
	 * @return void
	 */
	public function test_validate_rejects_above_limit() {
		$result = $this->field->validate( $this->rows, 'items', $this->options_with_limit( 2 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pods_repeater_limit_exceeded', $result->get_error_code() );
	}

	/**
	 * Limit 0 bedeutet unbegrenzt.
	 *
	 * @return void
	 */
	public function test_zero_limit_is_unlimited() {
		$this->assertTrue( $this->field->validate( $this->rows, 'items', $this->options_with_limit( 0 ) ) );
	}

	/**
	 * Die von input() geseedete Leerzeile verbraucht kein Limit.
	 *
	 * @return void
	 */
	public function test_empty_rows_do_not_count_toward_limit() {
		$rows   = $this->rows;
		$rows[] = array(
			'title' => '',
			'body'  => '',
		);

		$this->assertTrue(
			$this->field->validate( $rows, 'items', $this->options_with_limit( 3 ) ),
			'Vollstaendig leere Zeilen werden in pre_save verworfen und duerfen das Limit nicht verbrauchen.'
		);
	}

	/**
	 * pre_save() schneidet auf das Limit zurueck (Schutz ohne Validierung).
	 *
	 * @return void
	 */
	public function test_pre_save_truncates_to_limit() {
		$saved = $this->field->pre_save( $this->rows, null, 'items', $this->options_with_limit( 2 ) );
		$rows  = json_decode( $saved, true );

		$this->assertIsArray( $rows );
		$this->assertCount( 2, $rows );
	}

	/**
	 * Beim Abschneiden bleiben die ersten Zeilen in ihrer Reihenfolge.
	 *
	 * @return void
	 */
	public function test_pre_save_keeps_first_rows_in_order() {
		$saved = $this->field->pre_save( $this->rows, null, 'items', $this->options_with_limit( 2 ) );
		$rows  = json_decode( $saved, true );

		$this->assertSame( array( 'Zeile 1', 'Zeile 2' ), wp_list_pluck( $rows, 'title' ) );
	}

	/**
	 * Leere Zeilen zaehlen beim Abschneiden nicht mit: erst verwerfen, dann kappen.
	 *
	 * @return void
	 */
	public function test_pre_save_drops_empty_rows_before_truncating() {
		$rows = array(
			array( 'title' => 'Zeile 1' ),
			array( 'title' => '' ),
			array( 'title' => 'Zeile 2' ),
		);

		$saved = $this->field->pre_save( $rows, null, 'items', $this->options_with_limit( 2 ) );
		$clean = json_decode( $saved, true );

		$this->assertSame( array( 'Zeile 1', 'Zeile 2' ), wp_list_pluck( $clean, 'title' ) );
	}

	/**
	 * Innerhalb des Limits bleibt der required-Fehler unveraendert.
	 *
	 * @return void
	 */
	public function test_required_error_still_reported_within_limit() {
		$rows = array(
			array( 'title' => '' ),
			array( 'title' => '' ),
		);
		// Teilgefuellte Zeile: ein anderes Sub-Feld gesetzt, 'title' fehlt.
		$rows[0]['other'] = 'x';

		$result = $this->field->validate( $rows, 'items', $this->options_with_limit( 5 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pods_repeater_required', $result->get_error_code() );
	}
}
