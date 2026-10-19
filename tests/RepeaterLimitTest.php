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
	 * Ueber dem Limit liefert validate() ein Array mit der Limit-Meldung.
	 *
	 * Pods' Vertrag (PodsField_Text::validate(), PodsAPI::save_pod_item()):
	 * true oder Liste von Fehlermeldungen — kein WP_Error, weil PodsAPI das
	 * Ergebnis mit (array) castet und an wp_kses_post() weiterreicht.
	 *
	 * @return void
	 */
	public function test_validate_rejects_above_limit() {
		$result = $this->field->validate( $this->rows, 'items', $this->options_with_limit( 2 ) );

		$this->assertIsArray( $result, 'validate() muss bei Limit-Ueberschreitung ein Meldungs-Array liefern.' );
		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'at most 2 rows', $result[0] );
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
	 * Formular-Hinweis: gespeicherte Zeilen ueber dem Limit werden gemeldet,
	 * aber nicht versteckt.
	 *
	 * @return void
	 */
	public function test_input_warns_when_stored_rows_exceed_limit() {
		$html = $this->render_input( $this->rows, $this->options_with_limit( 2 ) );

		$this->assertStringContainsString( 'pods-repeater-limit-notice', $html );
		$this->assertStringContainsString( 'holds 3 rows but allows 2', $html );
		$this->assertSame(
			3,
			$this->count_rows( $html ),
			'Ueberschuessige Zeilen duerfen nicht aus der Ansicht verschwinden, sonst bearbeitet der Nutzer unbemerkt weniger Daten als gespeichert sind.'
		);
	}

	/**
	 * Kein Hinweis, solange das Limit eingehalten wird.
	 *
	 * @return void
	 */
	public function test_input_has_no_limit_notice_within_limit() {
		$html = $this->render_input( array_slice( $this->rows, 0, 2 ), $this->options_with_limit( 2 ) );

		$this->assertStringNotContainsString( 'pods-repeater-limit-notice', $html );
	}

	/**
	 * Rendert das Feld ueber input() und gibt das HTML zurueck.
	 *
	 * input() echo-t (Pods-Konvention, kein Rueckgabewert).
	 *
	 * @param mixed $value   Feldwert.
	 * @param array $options Feld-Optionen.
	 *
	 * @return string
	 */
	protected function render_input( $value, $options ) {
		ob_start();
		$this->field->input( 'items', $value, $options );

		return (string) ob_get_clean();
	}

	/**
	 * Zaehlt die gerenderten Zeilen-Container.
	 *
	 * @param string $html Gerendertes HTML.
	 *
	 * @return int
	 */
	protected function count_rows( $html ) {
		return (int) preg_match_all( '/class="pods-repeater-row"/', $html );
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

		$this->assertIsArray( $result, 'required-Verstoss muss als Meldungs-Array zurueckkommen (Pods-Vertrag).' );
		$this->assertStringContainsString( 'is required in every row', $result[0] );
	}
}
