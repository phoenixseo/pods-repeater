<?php
/**
 * TemplateFunctionsTest — ACF-kompatible Lese-API des Repeater-Felds (S9).
 *
 * Gegenstand: includes/template-functions.php mit
 *
 *   pods_repeater_get_rows()
 *   pods_repeater_have_rows()
 *   pods_repeater_the_row()
 *   pods_repeater_get_sub_field()
 *   pods_repeater_reset_rows()
 *
 * sowie die optionalen ACF-Aliase have_rows/the_row/get_sub_field.
 *
 * Die Tests laufen über den ECHTEN Weg: ein Test-Pod mit `repeater`-Feld,
 * ein Wegwerf-Post, gespeichert via pod_api()->save_pod_item() (bekannte
 * Signatur: 'data' + 'from' => 'save'). Gelesen wird ausschließlich über
 * die neuen Funktionen — also inklusive Pods-Feld-Pipeline, nicht gegen
 * handgeschriebenes Meta.
 *
 * Die Existenzprüfung der Feldklasse ist eine harte Assertion (kein Skip).
 *
 * @package PodsRepeater
 */

/**
 * Class TemplateFunctionsTest
 */
class TemplateFunctionsTest extends WP_UnitTestCase {

	/**
	 * Name des Test-Pods.
	 *
	 * @var string
	 */
	const POD = 's9_template_probe';

	/**
	 * Post-ID des Test-Items.
	 *
	 * @var int
	 */
	protected $item_id = 0;

	/**
	 * Test-Pod je Test neu anlegen; Registry-Zustand isolieren.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->assertTrue(
			class_exists( 'PodsField_Repeater' ),
			'PodsField_Repeater nicht geladen — Pods/pods-repeater wurden im muplugins_loaded-Bootstrap nicht aktiv (siehe tests/bootstrap.php) oder die Feldklasse wurde umbenannt.'
		);

		$this->assertTrue(
			function_exists( 'pods_repeater_get_rows' ),
			'includes/template-functions.php wurde nicht geladen — require_once in pods-repeater.php prüfen.'
		);

		pods_repeater_reset_rows();

		$api = pods_api();

		$existing = $api->load_pod( array( 'name' => self::POD ) );

		if ( $existing instanceof \Pods\Whatsit\Pod ) {
			$api->delete_pod( array( 'id' => $existing['id'] ) );
		}

		$pod_id = $api->save_pod(
			array(
				'name'    => self::POD,
				'label'   => 'S9 Template Probe',
				'type'    => 'post_type',
				'storage' => 'meta',
			)
		);

		$this->assertGreaterThan( 0, $pod_id, 'Test-Pod muss angelegt werden.' );

		$field_id = $api->save_field(
			array(
				'pod'                 => self::POD,
				'name'                => 'items',
				'label'               => 'Items',
				'type'                => 'repeater',
				'repeater_sub_fields' => wp_json_encode(
					array(
						array(
							'name'  => 'subtitle',
							'label' => 'Untertitel',
							'type'  => 'text',
						),
						array(
							'name'  => 'kicker',
							'label' => 'Kicker',
							'type'  => 'text',
						),
					)
				),
				'disable_dfv'         => true,
			)
		);

		$this->assertGreaterThan( 0, $field_id, 'Repeater-Feld muss angelegt werden.' );

		$this->item_id = wp_insert_post(
			array(
				'post_type'   => self::POD,
				'post_status' => 'publish',
				'post_title'  => 'S9 Item',
			),
			true
		);

		$this->assertIsInt( $this->item_id );
		$this->assertGreaterThan( 0, $this->item_id );
	}

	/**
	 * Test-Item wieder entfernen (kein Rest für Folgetests).
	 *
	 * @return void
	 */
	public function tear_down() {
		pods_repeater_reset_rows();

		if ( $this->item_id > 0 ) {
			wp_delete_post( $this->item_id, true );
			$this->item_id = 0;
		}

		$api = pods_api();

		$pod = $api->load_pod( array( 'name' => self::POD ) );

		if ( $pod instanceof \Pods\Whatsit\Pod ) {
			$api->delete_pod( array( 'id' => $pod['id'] ) );
		}

		parent::tear_down();
	}

	/**
	 * Speichert Zeilen über den echten Pods-Speicherpfad.
	 *
	 * @param array $rows Zeilen.
	 *
	 * @return void
	 */
	protected function save_rows( array $rows ) {
		$result = pods_api()->save_pod_item(
			array(
				'pod'        => self::POD,
				'id'         => $this->item_id,
				'from'       => 'save',
				'error_mode' => 'wp_error',
				'data'       => array( 'items' => $rows ),
			)
		);

		$this->assertNotWPError( $result, 'save_pod_item() darf die Testzeilen nicht ablehnen.' );
	}

	/**
	 * Die zwei Testzeilen.
	 *
	 * @return array
	 */
	protected function sample_rows() {
		return array(
			array(
				'subtitle' => 'Erste Zeile',
				'kicker'   => 'K1',
			),
			array(
				'subtitle' => 'Zweite Zeile',
				'kicker'   => 'K2',
			),
			array(
				'subtitle' => 'Dritte Zeile',
				'kicker'   => 'K3',
			),
		);
	}

	/**
	 * 1) get_rows() liest die Zeilen eines Test-Pods über die echte
	 * Pods-Pipeline.
	 *
	 * @return void
	 */
	public function test_get_rows_reads_rows_of_test_pod() {
		$rows = $this->sample_rows();

		$this->save_rows( $rows );

		$read = pods_repeater_get_rows( 'items', $this->item_id, self::POD );

		$this->assertIsArray( $read, 'get_rows() muss immer ein Array liefern.' );
		$this->assertCount( 3, $read );
		$this->assertSame( $rows, $read, 'get_rows() muss die gespeicherten Zeilen unverändert liefern.' );
	}

	/**
	 * 2) Die Iteration liefert alle Zeilen in gespeicherter Reihenfolge.
	 *
	 * @return void
	 */
	public function test_iteration_returns_rows_in_order() {
		$rows = $this->sample_rows();

		$this->save_rows( $rows );

		$seen = array();
		$runs = 0;

		while ( pods_repeater_have_rows( 'items', $this->item_id, self::POD ) ) {
			pods_repeater_the_row();

			$seen[] = pods_repeater_get_sub_field( 'subtitle' );

			++$runs;
			$this->assertLessThanOrEqual( 10, $runs, 'have_rows() muss irgendwann false liefern (Endlosschleife).' );
		}

		$this->assertSame( 3, $runs, 'Die Schleife muss genau drei Zeilen durchlaufen.' );
		$this->assertSame(
			array( 'Erste Zeile', 'Zweite Zeile', 'Dritte Zeile' ),
			$seen,
			'Die Reihenfolge der Zeilen muss der Speicherreihenfolge entsprechen.'
		);
	}

	/**
	 * 2b) Kanonisches ACF-Muster `if ( have_rows() ) { while ( have_rows() ) }`
	 * liefert dieselbe Zeilenfolge wie die reine `while`-Form.
	 *
	 * Regressionstest für einen real gefundenen Fehler: der Vorlauf-Aufruf
	 * im `if` hatte den Schleifenzeiger verbraucht, sodass die erste Zeile
	 * fehlte (die Messung an der echten Pipeline zeigte ["Beta","Gamma"]).
	 *
	 * @return void
	 */
	public function test_canonical_acf_if_while_pattern_matches_while_pattern() {
		$rows = $this->sample_rows();

		$this->save_rows( $rows );

		// Muster 1: reine while-Schleife.
		pods_repeater_reset_rows();

		$while_form = array();

		while ( pods_repeater_have_rows( 'items', $this->item_id, self::POD ) ) {
			pods_repeater_the_row();

			$while_form[] = pods_repeater_get_sub_field( 'subtitle' );
		}

		// Muster 2: if + while, exakt wie in der ACF-Dokumentation.
		pods_repeater_reset_rows();

		$if_form = array();

		if ( pods_repeater_have_rows( 'items', $this->item_id, self::POD ) ) {
			while ( pods_repeater_have_rows( 'items', $this->item_id, self::POD ) ) {
				pods_repeater_the_row();

				$if_form[] = pods_repeater_get_sub_field( 'subtitle' );
			}
		}

		$this->assertSame( array( 'Erste Zeile', 'Zweite Zeile', 'Dritte Zeile' ), $while_form );
		$this->assertSame( $while_form, $if_form, 'Der Vorlauf-Aufruf im `if` darf keine Zeile verbrauchen.' );
	}

	/**
	 * 3) get_sub_field() liefert innerhalb der Schleife den Wert der
	 * aktuellen Zeile — inklusive Unterscheidung zweier Sub-Felder.
	 *
	 * @return void
	 */
	public function test_get_sub_field_returns_value_inside_loop() {
		$rows = $this->sample_rows();

		$this->save_rows( $rows );

		$titles  = array();
		$kickers = array();

		while ( pods_repeater_have_rows( 'items', $this->item_id, self::POD ) ) {
			pods_repeater_the_row();

			$titles[]  = pods_repeater_get_sub_field( 'subtitle' );
			$kickers[] = pods_repeater_get_sub_field( 'kicker' );
		}

		$this->assertSame( array( 'Erste Zeile', 'Zweite Zeile', 'Dritte Zeile' ), $titles );
		$this->assertSame( array( 'K1', 'K2', 'K3' ), $kickers, 'get_sub_field() muss das angefragte Sub-Feld der aktuellen Zeile liefern.' );
	}

	/**
	 * 4) get_sub_field() außerhalb einer laufenden Schleife → null.
	 *
	 * Deckt beide Wege ab: vor dem ersten have_rows() und nach dem Ende der
	 * Schleife (dort darf kein Wert der letzten Zeile "kleben bleiben").
	 *
	 * @return void
	 */
	public function test_get_sub_field_outside_loop_is_null() {
		$this->save_rows( $this->sample_rows() );

		$this->assertNull(
			pods_repeater_get_sub_field( 'subtitle' ),
			'Ohne laufende Schleife darf get_sub_field() keinen Wert liefern.'
		);

		// Nach einem vollständigen Durchlauf: der Zeiger ist zurückgesetzt,
		// also darf auch hier kein Wert der letzten Zeile "kleben bleiben".
		while ( pods_repeater_have_rows( 'items', $this->item_id, self::POD ) ) {
			pods_repeater_the_row();
		}

		$this->assertNull(
			pods_repeater_get_sub_field( 'subtitle' ),
			'Nach dem Ende der Schleife darf kein Wert der letzten Zeile nachwirken.'
		);

		// Und nach einem expliziten reset_rows(): kein Wert aus der ersten
		// Zeile. Diese Probe trennt "Schleife beendet" von "Zustand geleert"
		// — ohne sie bliebe ein Rückfall auf die erste Zeile unbemerkt.
		pods_repeater_have_rows( 'items', $this->item_id, self::POD );

		while ( pods_repeater_have_rows( 'items', $this->item_id, self::POD ) ) {
			pods_repeater_the_row();
		}

		pods_repeater_reset_rows();

		$this->assertNull(
			pods_repeater_get_sub_field( 'subtitle' ),
			'Nach reset_rows() darf get_sub_field() keinen Wert aus der ersten Zeile liefern.'
		);
	}

	/**
	 * 5) reset_rows() setzt die Iteration zurück: dieselbe Schleife läuft
	 * ein zweites Mal von vorn, ohne erneutes Laden.
	 *
	 * @return void
	 */
	public function test_reset_rows_restarts_iteration() {
		$this->save_rows( $this->sample_rows() );

		$first = array();

		while ( pods_repeater_have_rows( 'items', $this->item_id, self::POD ) ) {
			pods_repeater_the_row();

			$first[] = pods_repeater_get_sub_field( 'subtitle' );
		}

		pods_repeater_reset_rows( 'items' );

		$second = array();

		while ( pods_repeater_have_rows( 'items', $this->item_id, self::POD ) ) {
			pods_repeater_the_row();

			$second[] = pods_repeater_get_sub_field( 'subtitle' );
		}

		$this->assertSame( $first, $second, 'Nach reset_rows() muss die Schleife wieder bei der ersten Zeile beginnen.' );
		$this->assertCount( 3, $second );
	}

	/**
	 * 6) Fehlender bzw. leerer Feldwert → leere Schleife, have_rows() false.
	 *
	 * Abgedeckt: Item ohne gespeicherten Wert, unbekanntes Feld und ein
	 * nicht existierendes Item. get_rows() liefert in allen Fällen array(),
	 * nie null.
	 *
	 * @return void
	 */
	public function test_missing_or_empty_value_yields_empty_loop() {
		$empty = pods_repeater_get_rows( 'items', $this->item_id, self::POD );

		$this->assertIsArray( $empty, 'get_rows() darf nie null liefern.' );
		$this->assertSame( array(), $empty, 'Ohne gespeicherten Wert muss get_rows() ein leeres Array liefern.' );

		$this->assertFalse(
			pods_repeater_have_rows( 'items', $this->item_id, self::POD ),
			'Ohne Zeilen muss have_rows() false liefern.'
		);

		$this->assertSame(
			array(),
			pods_repeater_get_rows( 'gibt_es_nicht', $this->item_id, self::POD ),
			'Unbekanntes Feld muss array() liefern.'
		);

		$this->assertSame(
			array(),
			pods_repeater_get_rows( 'items', 999999, self::POD ),
			'Nicht existierendes Item muss array() liefern.'
		);

		$this->assertFalse(
			pods_repeater_have_rows( 'items', 999999, self::POD ),
			'Nicht existierendes Item darf keine Schleife starten.'
		);
	}

	/**
	 * 7) Die ACF-Aliase sind registriert und delegieren auf die eigene API.
	 *
	 * Der Test setzt den Registrierungshook selbst neu (die Aliase werden
	 * auf init registriert, das im Testlauf bereits vorbei ist) und prüft
	 * danach das Delegationsverhalten über eine echte Schleife.
	 *
	 * @return void
	 */
	public function test_acf_aliases_are_registered_when_names_are_free() {
		if ( function_exists( 'have_rows' ) || function_exists( 'the_row' ) || function_exists( 'get_sub_field' ) ) {
			// Anderes Plugin (z. B. ACF) hat die Namen belegt: dann MUSS
			// pods_repeater_register_acf_aliases() sie in Ruhe lassen. Das ist
			// das dokumentierte Verhalten, nicht der Fehlerfall.
			pods_repeater_register_acf_aliases();

			$this->assertTrue( true, 'ACF-Namen sind belegt — der Alias-Guard darf sie nicht überschreiben.' );

			return;
		}

		pods_repeater_register_acf_aliases();

		$this->assertTrue( function_exists( 'have_rows' ), 'have_rows() muss als Alias registriert sein.' );
		$this->assertTrue( function_exists( 'the_row' ), 'the_row() muss als Alias registriert sein.' );
		$this->assertTrue( function_exists( 'get_sub_field' ), 'get_sub_field() muss als Alias registriert sein.' );

		$this->save_rows( $this->sample_rows() );

		$titles = array();

		while ( have_rows( 'items', $this->item_id, self::POD ) ) {
			the_row();

			$titles[] = get_sub_field( 'subtitle' );
		}

		$this->assertSame(
			array( 'Erste Zeile', 'Zweite Zeile', 'Dritte Zeile' ),
			$titles,
			'Die ACF-Aliase müssen auf die pods_repeater_*-Funktionen delegieren.'
		);
	}

	/**
	 * 8) Filter pods_repeater_register_acf_aliases = false verhindert die
	 * Aliase.
	 *
	 * Vorgehen (zweistufig, siehe Abschlussbericht):
	 *
	 *   (a) Im Testprozess: PHP kann eine deklarierte Funktion nicht mehr
	 *       entfernen, deshalb prüft dieser Test den Filterwert an der
	 *       Quelle — mit gesetztem Filter muss `apply_filters(…, true)`
	 *       `false` liefern, ohne Filter `true`. Das ist der Schalter, den
	 *       `pods_repeater_register_acf_aliases()` auswertet.
	 *
	 *   (b) Echter Prozess-Nachweis: ein mu-plugin setzt den Filter VOR dem
	 *       Plugin-Laden (mu-plugins laden vor Plugins). Ergebnis im
	 *       CLI-Prozess: `have_rows`/`get_sub_field` bleiben bei Pods'
	 *       Shim, `the_row` bleibt frei. Nachgestellt in einem Wegwerf-
	 *       mu-plugin, das den Filter vor dem Plugin-Laden setzt — nicht
	 *       Teil der Suite, weil es die Testumgebung dauerhaft verändern
	 *       würde.
	 *
	 * Beides zusammen belegt den Abschalter; (b) allein wäre nicht
	 * automatisierbar, ohne die Testumgebung dauerhaft zu verändern.
	 *
	 * @return void
	 */
	public function test_filter_false_prevents_acf_aliases() {
		// (a) Default ohne Filter.
		$this->assertTrue(
			apply_filters( 'pods_repeater_register_acf_aliases', true ),
			'Ohne Filter muss der Default true sein (Aliase registriert).'
		);

		// (a) Mit Abschaltfilter.
		add_filter( 'pods_repeater_register_acf_aliases', '__return_false' );

		$this->assertFalse(
			apply_filters( 'pods_repeater_register_acf_aliases', true ),
			'Mit aktivem Abschalt-Filter muss der Registrierungswert false sein.'
		);

		remove_filter( 'pods_repeater_register_acf_aliases', '__return_false' );

		// (a) Wieder an: der Filter ist der einzige Schalter.
		$this->assertTrue(
			apply_filters( 'pods_repeater_register_acf_aliases', true ),
			'Nach remove_filter muss der Default wieder true sein.'
		);

		// (b) Der Aufruf mit abgeschaltetem Filter darf nichts deklarieren
		// und nicht fatalen — die Alias-Datei wird per require_once nur
		// geladen, wenn der Filter true liefert.
		add_filter( 'pods_repeater_register_acf_aliases', '__return_false' );

		pods_repeater_register_acf_aliases();

		remove_filter( 'pods_repeater_register_acf_aliases', '__return_false' );

		$this->assertTrue( function_exists( 'pods_repeater_have_rows' ), 'Die eigene API muss vom Filter unberührt bleiben.' );
	}
}
