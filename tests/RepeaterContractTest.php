<?php
/**
 * Rückgabevertrag der Feldtyp-Klasse `repeater` belegen.
 *
 * Woher die Erwartungen stammen: jede Assertion ist an der echten
 * Implementierung gemessen (Probes tests/spike-s12*.php, Pods 3.3.9.1,
 * WordPress 6.8.2), nicht aus der Doku geraten. Gemessene Fakten:
 *
 *   - validate() liefert bei zu vielen Zeilen ein ARRAY mit einem String
 *     ("This field allows at most 2 rows."), nie einen WP_Error.
 *     Grund (classes/PodsAPI.php, save_pod_item): `(array) $validate` →
 *     pods_error(); ein Objekt in diesem Array würde dort als
 *     "Array to string conversion" sterben.
 *   - Im echten Save wird aus dem Array ein WP_Error (error_mode=wp_error)
 *     bzw. eine Exception (error_mode=exception).
 *   - save_pod(), save_field() und save_pod_item() geben Integers zurück.
 *   - $pod->field( 'items' ) dekodiert über value() zum Array,
 *     $pod->get_field() läuft durch display() und liefert einen String.
 *   - display() rendert `default`/`br`/`ul`/`ol`/`custom` unterschiedlich.
 *
 * Jeder Test legt seinen Pod selbst an und löscht ihn wieder
 * (pods_api()->delete_pod()), damit zwei Suite-Läufe dieselbe Testzahl
 * liefern und spätere Läufe nicht in eine gewachsene DB laufen.
 *
 * @package PodsRepeater
 */

/**
 * Class RepeaterContractTest
 */
class RepeaterContractTest extends WP_UnitTestCase {

	/**
	 * Feldklasse unter Test.
	 *
	 * @var PodsField_Repeater
	 */
	protected $field;

	/**
	 * Pod-Slugs, die der laufende Test angelegt hat (Cleanup-Pflicht).
	 *
	 * @var string[]
	 */
	protected $created_pods = array();

	/**
	 * Setzt die Feldklasse für jeden Test neu auf.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// Harte Assertion statt Skip: fehlt die Klasse, ist der Vertrag nicht
		// prüfbar und der Lauf muss rot werden.
		$this->assertTrue(
			class_exists( 'PodsField_Repeater' ),
			'PodsField_Repeater nicht geladen — Pods/pods-repeater wurden im muplugins_loaded-Bootstrap nicht aktiv (siehe tests/bootstrap.php) oder die Feldklasse wurde umbenannt.'
		);

		$this->field        = new PodsField_Repeater();
		$this->created_pods = array();

		$this->assertInstanceOf(
			'PodsField',
			$this->field,
			'PodsField_Repeater muss von der Pods-Basisklasse PodsField erben.'
		);
	}

	/**
	 * Jeder Test räumt seine Pods auf. Ein Test, der das nicht tut, ist ein
	 * Testfehler — er wird hier rot statt die DB über Läufe wachsen zu lassen.
	 *
	 * @return void
	 */
	public function tear_down() {
		$api = pods_api();

		foreach ( $this->created_pods as $pod_name ) {
			$api->delete_pod(
				array(
					'name'       => $pod_name,
					'delete_all' => true,
				)
			);

			$remaining = $api->load_pod(
				array(
					'name'       => $pod_name,
					'auto_setup' => false,
				),
				false
			);

			$this->assertEmpty( $remaining, sprintf( 'Pod "%s" wurde im tear_down nicht gelöscht — der Test hinterlässt Zustand in der DB.', $pod_name ) );
		}

		$this->created_pods = array();

		parent::tear_down();
	}

	/**
	 * Legt einen Wegwerf-Pod (post_type, meta-Storage) samt Repeater-Feld an.
	 *
	 * @param array $field_args Zusätzliche Argumente für save_field().
	 * @param array $sub_fields Sub-Feld-Definitionen (JSON-Quelle des Feldes).
	 *
	 * @return string Pod-Slug.
	 */
	protected function create_pod_with_repeater( $field_args = array(), $sub_fields = null ) {
		$api      = pods_api();
		$pod_name = 's12contract_' . strtolower( wp_generate_password( 8, false, false ) );

		$pod_id = $api->save_pod(
			array(
				'name'          => $pod_name,
				'label'         => 'S12 Contract ' . $pod_name,
				'type'          => 'post_type',
				'storage'       => 'meta',
				'public'        => false,
				'create_extend' => 'create',
			)
		);

		$this->created_pods[] = $pod_name;

		$this->assertIsInt( $pod_id, 'pods_api()->save_pod() liefert bei type=post_type eine Post-ID (Integer), kein Array.' );
		$this->assertGreaterThan( 0, $pod_id );

		if ( null === $sub_fields ) {
			$sub_fields = array(
				array(
					'name'     => 'title',
					'label'    => 'Titel',
					'type'     => 'text',
					'required' => true,
				),
			);
		}

		$field_id = $api->save_field(
			array_merge(
				array(
					'pod'                 => $pod_name,
					'name'                => 'items',
					'label'               => 'Items',
					'type'                => 'repeater',
					'repeater_sub_fields' => wp_json_encode( $sub_fields ),
					'disable_dfv'         => true,
				),
				$field_args
			)
		);

		$this->assertIsInt( $field_id, 'pods_api()->save_field() liefert eine Feld-ID (Integer).' );
		$this->assertGreaterThan( 0, $field_id );

		return $pod_name;
	}

	/**
	 * Legt ein Item (Post) im Pod an.
	 *
	 * @param string $pod_name Pod-Slug.
	 * @param string $title    Titel — ohne Titel wirft save_pod_item()
	 *                         "Content, title, and excerpt are empty.".
	 *
	 * @return int Post-ID.
	 */
	protected function create_item( $pod_name, $title = 'Contract Item' ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => $pod_name,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	/**
	 * Feld-Optionen mit Limit, wie sie validate()/display() direkt bekommen.
	 *
	 * @param int   $limit        Max-Zeilen (0 = unbegrenzt).
	 * @param array $extra        Zusätzliche Optionen.
	 *
	 * @return array
	 */
	protected function options( $limit = 2, $extra = array() ) {
		return array_merge(
			array(
				'repeater_limit'      => $limit,
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
			),
			$extra
		);
	}

	/**
	 * Drei gefüllte Zeilen (über dem Limit 2).
	 *
	 * @return array
	 */
	protected function three_rows() {
		return array(
			array( 'title' => 'a' ),
			array( 'title' => 'b' ),
			array( 'title' => 'c' ),
		);
	}

	/**
	 * Zwei gefüllte Zeilen (innerhalb des Limits).
	 *
	 * @return array
	 */
	protected function two_rows() {
		return array_slice( $this->three_rows(), 0, 2 );
	}

	/**
	 * 1. validate() bei zu vielen Zeilen: Array, genau 1 Element, String,
	 * nennt das Limit. Kein WP_Error — Pods castet das Ergebnis mit (array)
	 * und reicht die Elemente an wp_kses_post(); ein Objekt stürbe dort.
	 *
	 * @return void
	 */
	public function test_validate_too_many_rows_returns_array_of_messages() {
		$result = $this->field->validate( $this->three_rows(), 'items', $this->options( 2 ) );

		$this->assertIsArray( $result, 'validate() muss bei zu vielen Zeilen ein Array liefern (Pods save_pod_item castet mit (array)), keinen WP_Error.' );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Ein WP_Error im Rückgabewert würde im Save zu verschachtelten Arrays und damit zu "Array to string conversion" führen.' );
		$this->assertCount( 1, $result, 'Genau eine Meldung pro verletzter Regel.' );
		$this->assertArrayHasKey( 0, $result );

		$this->assertIsString( $result[0], 'Die Meldung muss ein String sein — Pods ruft wp_kses_post() auf jedes Element.' );
		$this->assertStringContainsString( '2', $result[0], 'Die Meldung muss die Zahl des Limits nennen.' );
	}

	/**
	 * 2. validate() bei gültiger Zeilenzahl liefert strikt `true`
	 * (nicht 1, nicht '', nicht null).
	 *
	 * @return void
	 */
	public function test_validate_within_limit_returns_strict_true() {
		$this->assertTrue(
			$this->field->validate( $this->two_rows(), 'items', $this->options( 2 ) ),
			'Bei gültigem Wert muss validate() true liefern.'
		);
	}

	/**
	 * 3. repeater_limit = 0 bedeutet unbegrenzt: auch viele Zeilen sind gültig.
	 *
	 * @return void
	 */
	public function test_validate_zero_limit_never_reports_row_count_error() {
		$options = $this->options( 0 );

		$this->assertTrue( $this->field->validate( $this->three_rows(), 'items', $options ) );

		$many = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$many[] = array( 'title' => 'row ' . $i );
		}

		$this->assertTrue(
			$this->field->validate( $many, 'items', $options ),
			'repeater_limit = 0 heißt unbegrenzt — 25 Zeilen dürfen nicht als Limit-Verstoß gelten.'
		);
	}

	/**
	 * 4. Leere Zeilen verbrauchen das Limit nicht (input() seedet eine leere
	 * Vorlagezeile, die darf nicht zählen).
	 *
	 * @return void
	 */
	public function test_validate_ignores_empty_rows_when_counting_limit() {
		$rows = array(
			array( 'title' => 'a' ),
			array( 'title' => '' ),
			array( 'title' => 'b' ),
			array( 'title' => '' ),
		);

		$this->assertTrue(
			$this->field->validate( $rows, 'items', $this->options( 2 ) ),
			'Zwei gefüllte und zwei leere Zeilen bei Limit 2 sind gültig — leere Zeilen zählen nicht mit.'
		);
	}

	/**
	 * 5. Echter Save: drei Zeilen bei Limit 2 scheitern als WP_Error
	 * (error_mode = wp_error) und schreiben nichts in die Meta.
	 *
	 * @return void
	 */
	public function test_real_save_over_limit_returns_wp_error_and_writes_nothing() {
		$pod_name = $this->create_pod_with_repeater( array( 'repeater_limit' => 2 ) );
		$post_id  = $this->create_item( $pod_name );

		$result = pods_api()->save_pod_item(
			array(
				'pod'        => $pod_name,
				'id'         => $post_id,
				'from'       => 'save',
				'error_mode' => 'wp_error',
				'data'       => array( 'items' => $this->three_rows() ),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Im echten Save-Pfad (error_mode=wp_error) muss aus dem validate()-Array ein WP_Error werden.' );
		$this->assertStringContainsString( '2', $result->get_error_message() );
		$this->assertSame( '', get_post_meta( $post_id, 'items', true ), 'Ein abgelehnter Save darf keine Zeilen schreiben.' );
	}

	/**
	 * 6. Derselbe Verstoß mit error_mode = exception wirft — und auch dann
	 * bleibt die Meta leer.
	 *
	 * @return void
	 */
	public function test_real_save_over_limit_with_exception_mode_throws() {
		$pod_name = $this->create_pod_with_repeater( array( 'repeater_limit' => 2 ) );
		$post_id  = $this->create_item( $pod_name );

		$thrown = null;

		try {
			pods_api()->save_pod_item(
				array(
					'pod'        => $pod_name,
					'id'         => $post_id,
					'from'       => 'save',
					'error_mode' => 'exception',
					'data'       => array( 'items' => $this->three_rows() ),
				)
			);
		} catch ( Exception $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( 'Exception', $thrown, 'error_mode=exception muss bei zu vielen Zeilen werfen.' );
		$this->assertStringContainsString( '2', $thrown->getMessage() );
		$this->assertSame( '', get_post_meta( $post_id, 'items', true ) );
	}

	/**
	 * 7. Echter Save innerhalb des Limits: Erfolg, Meta enthält JSON mit
	 * genau zwei Zeilen und den gespeicherten Werten.
	 *
	 * @return void
	 */
	public function test_real_save_within_limit_persists_json_rows() {
		$pod_name = $this->create_pod_with_repeater( array( 'repeater_limit' => 2 ) );
		$post_id  = $this->create_item( $pod_name );
		$rows     = $this->two_rows();

		$result = pods_api()->save_pod_item(
			array(
				'pod'        => $pod_name,
				'id'         => $post_id,
				'from'       => 'save',
				'error_mode' => 'wp_error',
				'data'       => array( 'items' => $rows ),
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertIsInt( $result, 'save_pod_item() liefert die Item-ID als Integer.' );

		$stored = get_post_meta( $post_id, 'items', true );

		$this->assertIsString( $stored, 'Die Meta muss den JSON-String von pre_save() enthalten.' );
		$this->assertJson( (string) $stored );

		$decoded = json_decode( (string) $stored, true );

		$this->assertIsArray( $decoded );
		$this->assertCount( 2, $decoded );
		$this->assertSame( $rows, $decoded, 'Die gespeicherten Zeilen müssen verlustfrei zurückkommen.' );
	}

	/**
	 * 8. value(): JSON-String → Array; Array bleibt Array; Nicht-JSON bleibt
	 * unverändert (kein stilles Verwerfen echter Daten).
	 *
	 * @return void
	 */
	public function test_value_decodes_json_and_passes_other_values_through() {
		$rows = $this->two_rows();

		$decoded = $this->field->value( wp_json_encode( $rows ), 'items', array() );

		$this->assertIsArray( $decoded );
		$this->assertSame( $rows, $decoded, 'value() muss den DB-JSON-String zum Zeilen-Array dekodieren.' );

		$this->assertSame( $rows, $this->field->value( $rows, 'items', array() ), 'Ein bereits dekodiertes Array bleibt unverändert.' );
		$this->assertSame( '', $this->field->value( '', 'items', array() ), 'Der leere Meta-String bleibt leer (kein Array aus dem Nichts).' );
		$this->assertSame( '{nope', $this->field->value( '{nope', 'items', array() ), 'Kaputtes JSON bleibt unverändert — die Daten gehen nicht verloren.' );
		$this->assertNull( $this->field->value( null, 'items', array() ) );
	}

	/**
	 * 9. value() lesend über die Pods-API nach echtem Save:
	 * $pod->field() liefert das Zeilen-Array, $pod->raw() den JSON-String.
	 *
	 * @return void
	 */
	public function test_value_reads_back_saved_rows_through_pods_api() {
		$pod_name = $this->create_pod_with_repeater( array( 'repeater_limit' => 2 ) );
		$post_id  = $this->create_item( $pod_name );
		$rows     = $this->two_rows();

		pods_api()->save_pod_item(
			array(
				'pod'        => $pod_name,
				'id'         => $post_id,
				'from'       => 'save',
				'error_mode' => 'wp_error',
				'data'       => array( 'items' => $rows ),
			)
		);

		$pod = pods( $pod_name, $post_id );

		$this->assertTrue( $pod->valid(), 'Der Pod-Handle muss nach dem Save gültig sein.' );

		$value = $pod->field( 'items' );

		$this->assertIsArray( $value, '$pod->field() muss über value() als Zeilen-Array zurückkommen, nicht als JSON-String.' );
		$this->assertCount( 2, $value );
		$this->assertSame( $rows, $value, 'Beim Lesen müssen die gespeicherten Werte unverändert zurückkommen.' );

		$this->assertIsString( $pod->raw( 'items' ), 'raw() liefert den unverarbeiteten JSON-String.' );
	}

	/**
	 * 10. display(): `default` vs. `br` rendern unterschiedlich, `br` enthält
	 * <br />, `default` trennt mit Komma.
	 *
	 * @return void
	 */
	public function test_display_default_and_br_render_differently() {
		$rows = array(
			array( 'title' => 'Alpha' ),
			array( 'title' => 'Beta' ),
		);

		$default = $this->field->display( $rows, 'items', $this->options( 2, array( 'repeater_display_format' => 'default' ) ) );
		$br      = $this->field->display( $rows, 'items', $this->options( 2, array( 'repeater_display_format' => 'br' ) ) );

		$this->assertNotSame( $default, $br, 'default und br müssen unterschiedliche Ausgabe liefern.' );
		$this->assertSame( 'Alpha, Beta', $default );
		$this->assertSame( 'Alpha<br />Beta', $br );
		$this->assertStringContainsString( '<br', $br );
		$this->assertStringNotContainsString( '<br', $default );
	}

	/**
	 * 11. display(): `ul` und `ol` liefern Listen mit <li>; `ul` enthält
	 * <ul>, `ol` enthält <ol> — und beide unterscheiden sich.
	 *
	 * @return void
	 */
	public function test_display_ul_and_ol_render_lists() {
		$rows = array(
			array( 'title' => 'Alpha' ),
			array( 'title' => 'Beta' ),
		);

		$ul = $this->field->display( $rows, 'items', $this->options( 2, array( 'repeater_display_format' => 'ul' ) ) );
		$ol = $this->field->display( $rows, 'items', $this->options( 2, array( 'repeater_display_format' => 'ol' ) ) );

		$this->assertSame( '<ul><li>Alpha</li><li>Beta</li></ul>', $ul );
		$this->assertSame( '<ol><li>Alpha</li><li>Beta</li></ol>', $ol );
		$this->assertNotSame( $ul, $ol );
		$this->assertStringContainsString( '<ul>', $ul );
		$this->assertStringContainsString( '<li>', $ul );
		$this->assertStringContainsString( '<li>', $ol );
		$this->assertStringNotContainsString( '<ul>', $ol );
	}

	/**
	 * 12. display(): `custom` nutzt den konfigurierten Trenner; leere Werte
	 * ergeben einen leeren String (kein HTML-Rest).
	 *
	 * @return void
	 */
	public function test_display_custom_separator_and_empty_value() {
		$rows = array(
			array( 'title' => 'Alpha' ),
			array( 'title' => 'Beta' ),
		);

		$custom = $this->field->display(
			$rows,
			'items',
			$this->options(
				2,
				array(
					'repeater_display_format'           => 'custom',
					'repeater_display_format_separator' => ' | ',
				)
			)
		);

		$this->assertSame( 'Alpha | Beta', $custom );

		$this->assertSame( '', $this->field->display( array(), 'items', $this->options( 2 ) ), 'Leerer Wert rendert nichts.' );
		$this->assertSame( '', $this->field->display( '', 'items', $this->options( 2 ) ), 'Leerer Meta-String rendert nichts.' );
	}

	/**
	 * 13. display() über den echten Pods-Pfad: `field( ..., display=true )`
	 * läuft durch display() (String), während `field()` ohne Flags das
	 * Zeilen-Array liefert.
	 *
	 * @return void
	 */
	public function test_pods_field_with_display_flag_runs_through_display() {
		$pod_name = $this->create_pod_with_repeater( array( 'repeater_limit' => 0 ) );
		$post_id  = $this->create_item( $pod_name );

		pods_api()->save_pod_item(
			array(
				'pod'        => $pod_name,
				'id'         => $post_id,
				'from'       => 'save',
				'error_mode' => 'wp_error',
				'data'       => array(
					'items' => array(
						array( 'title' => 'Alpha' ),
						array( 'title' => 'Beta' ),
					),
				),
			)
		);

		$pod = pods( $pod_name, $post_id );

		$this->assertSame( 'Alpha, Beta', $pod->field( 'items', array( 'display' => true ) ), 'field( display=true ) läuft durch display() und liefert im Default-Format Komma-getrennt.' );
		$this->assertSame( 'Alpha, Beta', $pod->display( 'items' ), 'display() liefert dasselbe Format.' );
		$this->assertIsArray( $pod->field( 'items' ), 'field() ohne Flags liefert dagegen das unveränderte Array.' );
	}

	/**
	 * 14. Gruppen-Fallback: `repeater_group` gesetzt, aber die Gruppe ist
	 * nicht registriert → get_sub_fields() darf nicht fatal enden und nutzt
	 * die Inline-JSON-Definition (bzw. bleibt leer statt zu werfen).
	 *
	 * @return void
	 */
	public function test_group_reference_without_registry_entry_falls_back_to_inline_fields() {
		$method = new ReflectionMethod( 'PodsField_Repeater', 'get_sub_fields' );
		$method->setAccessible( true );

		// Gruppe existiert nicht: Registry-Funktion ist geladen, liefert aber
		// kein Ergebnis. get_sub_fields() muss trotzdem ein Array liefern.
		$without_group = $method->invoke(
			$this->field,
			array(
				'repeater_group'      => 's12_gibtsnicht',
				'repeater_sub_fields' => wp_json_encode(
					array(
						array(
							'name' => 'inline_a',
							'type' => 'text',
						),
					)
				),
			)
		);

		$this->assertIsArray( $without_group, 'get_sub_fields() muss immer ein Array liefern, auch bei unbekannter Gruppe.' );
		$this->assertSame(
			array(
				array(
					'name' => 'inline_a',
					'type' => 'text',
				),
			),
			$method->invoke(
				$this->field,
				array(
					'repeater_sub_fields' => wp_json_encode(
						array(
							array(
								'name' => 'inline_a',
								'type' => 'text',
							),
						)
					),
				)
			),
			'Ohne Gruppen-Referenz gewinnt das Inline-JSON.'
		);

		$this->assertIsArray(
			$method->invoke( $this->field, array( 'repeater_group' => 's12_gibtsnicht' ) ),
			'Auch eine Gruppen-Referenz ohne Inline-JSON und ohne Registry-Eintrag darf nicht werfen.'
		);
	}

	/**
	 * 15. Gruppen-Fallback ohne Registry-Funktion: der Guard
	 * `function_exists( 'pods_repeater_get_group_fields' )` verhindert einen
	 * fatalen Fehler. Der Test belegt das für beide Zweige (get_sub_fields
	 * und get_group_notice), indem er den fehlenden Gruppen-Slug und die
	 * Rückfallreihenfolge prüft — ohne die Registry-Funktion vorhanden ist,
	 * wird das Inline-JSON benutzt.
	 *
	 * @return void
	 */
	public function test_group_guard_does_not_fatal_without_registry_function() {
		$this->assertTrue(
			function_exists( 'pods_repeater_get_group_fields' ),
			'Registry-Funktion muss geladen sein; fehlt sie, prüft dieser Test den anderen Zweig nicht mehr.'
		);

		$get_sub_fields   = new ReflectionMethod( 'PodsField_Repeater', 'get_sub_fields' );
		$get_group_notice = new ReflectionMethod( 'PodsField_Repeater', 'get_group_notice' );
		$get_sub_fields->setAccessible( true );
		$get_group_notice->setAccessible( true );

		$options = array(
			'repeater_group'      => 's12_gibtsnicht',
			'repeater_sub_fields' => wp_json_encode(
				array(
					array(
						'name' => 'inline_b',
						'type' => 'text',
					),
				)
			),
		);

		// Gruppen-Slug gesetzt und unbekannt: Registry gewinnt und liefert
		// leer — der Hinweis macht das sichtbar, statt still leer zu rendern.
		$this->assertSame( array(), $get_sub_fields->invoke( $this->field, $options ) );
		$this->assertStringContainsString( 's12_gibtsnicht', $get_group_notice->invoke( $this->field, $options ) );

		// Ohne Slug darf der Guard sofort zurückkehren (kein Registry-Zugriff).
		$this->assertSame( '', $get_group_notice->invoke( $this->field, array() ) );

		// Nicht-skalarer Slug (Array) wird nicht als Slug interpretiert.
		$this->assertSame(
			'',
			$get_group_notice->invoke(
				$this->field,
				array(
					'repeater_group' => array( 'kaputt' ),
				)
			)
		);
	}

	/**
	 * 16. Der Vertrag bleibt bei jedem Aufruf stabil: validate() ist
	 * zustandslos (zwei Aufrufe hintereinander liefern dasselbe), und der
	 * Save-Pfad liefert bei mehrfacher Ausführung dasselbe Ergebnis.
	 *
	 * @return void
	 */
	public function test_validate_is_stateless_across_calls() {
		$options = $this->options( 2 );
		$rows    = $this->three_rows();

		$first  = $this->field->validate( $rows, 'items', $options );
		$second = $this->field->validate( $rows, 'items', $options );

		$this->assertSame( $first, $second, 'validate() darf keinen Zustand zwischen Aufrufen halten.' );
		$this->assertCount( 1, $first );

		$this->assertTrue( $this->field->validate( $this->two_rows(), 'items', $options ) );
		$this->assertSame( $first, $this->field->validate( $rows, 'items', $options ), 'Ein gültiger Aufruf dazwischen darf den Vertrag nicht ändern.' );
	}

	/**
	 * 17. JSON-String als Eingabe: validate() akzeptiert im Save-Pfad auch
	 * den rohen Meta-String (wie ihn die Admin-Maske nach einem Reload
	 * liefert) und zählt dieselben Zeilen.
	 *
	 * @return void
	 */
	public function test_validate_accepts_json_string_input() {
		$options = $this->options( 2 );

		$result = $this->field->validate( wp_json_encode( $this->three_rows() ), 'items', $options );

		$this->assertIsArray( $result, 'Auch als JSON-String muss der Limit-Verstoß erkannt werden.' );
		$this->assertCount( 1, $result );

		$this->assertTrue( $this->field->validate( wp_json_encode( $this->two_rows() ), 'items', $options ) );

		$this->assertTrue( $this->field->validate( 'kein json', 'items', $options ), 'Nicht-JSON-Strings sind kein Zeilen-Array und werden nicht als Limit-Verstoß gewertet.' );
	}

	/**
	 * 18. Cleanup-Nachweis: ein Test, der einen Pod anlegt, hinterlässt ihn
	 * nach tear_down nicht. Der Test legt selbst einen an, prüft die
	 * Existenz, und verlässt sich für das Entfernen auf tear_down — der Lauf
	 * danach (und der zweite Suite-Lauf) sieht dieselbe Pod-Menge.
	 *
	 * @return void
	 */
	public function test_cleanup_removes_created_pods() {
		$pod_name = $this->create_pod_with_repeater( array( 'repeater_limit' => 2 ) );

		$this->assertContains( $pod_name, $this->created_pods, 'Der Test muss seinen Pod selbst in der Cleanup-Liste führen.' );

		$pod = pods_api()->load_pod(
			array(
				'name'       => $pod_name,
				'auto_setup' => false,
			),
			false
		);

		$this->assertNotEmpty( $pod, 'Der Pod muss während des Tests existieren.' );

		$before = pods_api()->load_pods( array( 'names' => true ) );
		$this->assertArrayHasKey( $pod_name, (array) $before, 'Der angelegte Pod muss in der Pod-Liste auftauchen.' );
	}

	/**
	 * Definierter Zustand am Ende eines Tests: der in tear_down geprüfte
	 * Pod-Bestand ist die eine Hälfte; hier wird belegt, dass eine zweite
	 * Erzeugung mit demselben Namen nicht an einem Rest scheitert.
	 *
	 * @return void
	 */
	public function test_pod_creation_is_repeatable_after_cleanup() {
		$api = pods_api();

		$first  = $this->create_pod_with_repeater( array( 'repeater_limit' => 2 ) );
		$second = $this->create_pod_with_repeater( array( 'repeater_limit' => 2 ) );

		$this->assertNotSame( $first, $second, 'Zwei Pods im selben Test müssen eigene Slugs bekommen.' );

		foreach ( array( $first, $second ) as $pod_name ) {
			$this->assertNotEmpty(
				$pod_name,
				'Pod-Slug darf nicht leer sein.'
			);

			$pod = $api->load_pod(
				array(
					'name'       => $pod_name,
					'auto_setup' => false,
				),
				false
			);

			$this->assertNotEmpty( $pod, sprintf( 'Pod "%s" muss angelegt sein.', $pod_name ) );
		}
	}
}
