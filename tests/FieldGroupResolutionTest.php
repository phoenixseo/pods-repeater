<?php
/**
 * FieldGroupResolutionTest — Repeater-Feld mit Feldgruppen-Referenz (S5).
 *
 * Prüft die neue Feldoption `repeater_group`: ein Repeater-Feld kann statt
 * Inline-JSON eine registrierte Feldgruppe aus includes/field-groups.php
 * referenzieren. Vertrag:
 *
 *   - `repeater_group` ist eine `pick`-Option mit `data` = Slug => Label,
 *     steht VOR `repeater_sub_fields` und bleibt auch ohne geladene Registry
 *     aufrufbar (function_exists()-Guard in options()).
 *   - Ist ein Slug gesetzt, gewinnt die Gruppe — auch wenn zusätzlich
 *     Inline-JSON hinterlegt ist (so steht es im Help-Text der Option).
 *   - Ohne Slug bleibt der JSON-Weg unverändert (Abwärtskompatibilität).
 *   - Unbekannter/leerer Slug: kein Fatal, kein PHP-Notice, leeres
 *     Sub-Feld-Array plus sichtbarer Hinweis in input().
 *   - validate(), pre_save()/value() und display() arbeiten über
 *     get_sub_fields() und damit automatisch auf den Gruppenfeldern.
 *
 * Die Existenzprüfung der Feldklasse ist eine harte Assertion (kein Skip):
 * eine Umbenennung soll den Lauf rot machen, nicht still entwerten.
 *
 * @package PodsRepeater
 */

/**
 * Class FieldGroupResolutionTest
 */
class FieldGroupResolutionTest extends WP_UnitTestCase {

	/**
	 * Feldklasse unter Test.
	 *
	 * @var PodsField_Repeater
	 */
	protected $field;

	/**
	 * Feldname der Testgruppe: 'g_title' ist required, 'g_subtitle' nicht.
	 * Die Präfixe 'g_' halten die Gruppenfelder von den Inline-Feldern
	 * ('in_only') unterscheidbar — Namens-Assertions dürfen nicht zufällig
	 * auf Klassen- oder Label-Texte treffen.
	 *
	 * @var array
	 */
	protected $rows;

	/**
	 * Registry und Testdaten für jeden Test neu aufbauen.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// Harte Assertion statt markTestSkipped(): ohne Feldklasse ist die
		// Gruppen-Auflösung ungetestet und der Lauf muss rot werden.
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

		// Registry-Zustand isolieren: WP_UnitTestCase rollt die DB zurück,
		// lässt aber den Options-Object-Cache stehen (Muster FieldGroupsTest).
		delete_option( PODS_REPEATER_GROUPS_OPTION );
		delete_option( PODS_REPEATER_GROUPS_VERSION_OPTION );

		$slug = pods_repeater_save_group(
			array(
				'slug'   => 'hero',
				'label'  => 'Hero Group',
				'fields' => array(
					array(
						'name'     => 'g_title',
						'label'    => 'Titel',
						'type'     => 'text',
						'required' => true,
					),
					array(
						'name'  => 'g_subtitle',
						'label' => 'Untertitel',
						'type'  => 'text',
					),
				),
			)
		);

		$this->assertSame( 'hero', $slug, 'Testgruppe "hero" muss in der Registry liegen.' );

		$this->rows = array(
			array(
				'g_title'    => 'Erster Titel',
				'g_subtitle' => 'Erster Untertitel',
			),
			array(
				'g_title'    => 'Zweiter Titel',
				'g_subtitle' => 'Zweiter Untertitel',
			),
		);
	}

	/**
	 * Feld-Optionen mit Gruppen-Referenz.
	 *
	 * @param string $slug Gruppen-Slug ('' = keine Referenz).
	 *
	 * @return array
	 */
	protected function group_options( $slug = 'hero' ) {
		$options = array( 'disable_dfv' => true );

		if ( '' !== $slug ) {
			$options['repeater_group'] = $slug;
		}

		return $options;
	}

	/**
	 * Feld-Optionen mit Inline-JSON (der bisherige Weg).
	 *
	 * @param string $slug Optional zusätzlich gesetzter Gruppen-Slug.
	 *
	 * @return array
	 */
	protected function inline_options( $slug = '' ) {
		$options = $this->group_options( $slug );

		$options['repeater_sub_fields'] = wp_json_encode(
			array(
				array(
					'name'  => 'in_only',
					'label' => 'Inline Only',
					'type'  => 'text',
				),
			)
		);

		return $options;
	}

	/**
	 * Rendert input() in einen String (input() echo-t, kein Rückgabewert).
	 *
	 * @param mixed  $value   Feldwert.
	 * @param array  $options Feld-Optionen.
	 * @param string $name    Feldname.
	 *
	 * @return string Gerendertes HTML.
	 */
	protected function render_input( $value, $options, $name = 'items' ) {
		ob_start();
		$this->field->input( $name, $value, $options );

		return (string) ob_get_clean();
	}

	/**
	 * Zählt die gerenderten Zeilen-Container (quote-anchored; die Container-
	 * Klasse 'pods-repeater-rows' darf nicht mitzählen).
	 *
	 * @param string $html Gerendertes HTML.
	 *
	 * @return int
	 */
	protected function count_rows( $html ) {
		return (int) preg_match_all( '/class="pods-repeater-row"/', $html );
	}

	/**
	 * (1) options() enthält die Gruppen-Auswahl als pick-Feld mit den
	 * registrierten Gruppen als data (Slug => Label).
	 *
	 * @return void
	 */
	public function test_options_exposes_group_selector_as_pick_with_registered_groups() {
		$options = $this->field->options();

		$this->assertArrayHasKey( 'repeater_group', $options );
		$this->assertSame( 'pick', $options['repeater_group']['type'] );
		$this->assertSame( '', $options['repeater_group']['default'], 'Ohne Auswahl darf kein Slug gesetzt sein.' );
		$this->assertIsArray( $options['repeater_group']['data'] );
		$this->assertArrayHasKey( 'hero', $options['repeater_group']['data'] );
		$this->assertSame( 'Hero Group', $options['repeater_group']['data']['hero'] );
		$this->assertNotEmpty( $options['repeater_group']['help'], 'Die Option braucht einen Help-Text.' );
	}

	/**
	 * (2) Die neue Option steht VOR dem Inline-JSON, damit die Gruppen-Wahl
	 * im Feld-Dialog zuerst sichtbar ist.
	 *
	 * @return void
	 */
	public function test_options_places_group_selector_before_inline_json() {
		$keys = array_keys( $this->field->options() );

		$group_pos  = array_search( 'repeater_group', $keys, true );
		$inline_pos = array_search( 'repeater_sub_fields', $keys, true );

		$this->assertNotFalse( $group_pos );
		$this->assertNotFalse( $inline_pos );
		$this->assertLessThan( $inline_pos, $group_pos, 'repeater_group muss vor repeater_sub_fields stehen.' );
	}

	/**
	 * (3) Ohne Registry-Einträge bleibt options() aufrufbar und liefert eine
	 * leere Auswahl (kein Fatal, keine Notice).
	 *
	 * @return void
	 */
	public function test_options_without_registry_entries_yields_empty_group_data() {
		delete_option( PODS_REPEATER_GROUPS_OPTION );

		$options = $this->field->options();

		$this->assertArrayHasKey( 'repeater_group', $options );
		$this->assertSame( array(), $options['repeater_group']['data'] );
	}

	/**
	 * (4) Gesetzte Gruppe: input() rendert die Felder der Gruppe mit dem
	 * üblichen Namensmuster, ohne dass Inline-JSON existiert.
	 *
	 * @return void
	 */
	public function test_group_reference_renders_group_field_names() {
		$html = $this->render_input( array(), $this->group_options() );

		$this->assertStringContainsString( 'name="items[0][g_title]"', $html );
		$this->assertStringContainsString( 'name="items[0][g_subtitle]"', $html );
		$this->assertStringNotContainsString( 'pods-repeater-group-notice', $html, 'Bei gültiger Gruppe darf kein Hinweis erscheinen.' );
	}

	/**
	 * (5) Gruppe UND Inline-JSON: die Gruppe gewinnt, das Inline-Feld kommt
	 * im HTML nicht vor.
	 *
	 * @return void
	 */
	public function test_group_wins_over_inline_json() {
		$html = $this->render_input( array(), $this->inline_options( 'hero' ) );

		$this->assertStringContainsString( 'name="items[0][g_title]"', $html );
		$this->assertStringContainsString( 'name="items[0][g_subtitle]"', $html );
		$this->assertStringNotContainsString( 'in_only', $html, 'Inline-JSON muss bei gesetzter Gruppe ignoriert werden.' );
	}

	/**
	 * (6) Regression: ohne Gruppen-Slug bleibt der Inline-JSON-Weg aktiv.
	 *
	 * @return void
	 */
	public function test_inline_json_still_used_without_group() {
		$html = $this->render_input( array(), $this->inline_options() );

		$this->assertStringContainsString( 'name="items[0][in_only]"', $html );
		$this->assertStringNotContainsString( 'items[0][g_title]', $html, 'Ohne Gruppen-Referenz dürfen keine Gruppenfelder erscheinen.' );
	}

	/**
	 * (7) Unbekannter Slug: kein Fatal, leeres Sub-Feld-Array (nur die
	 * geseedete Leerzeile), Hinweis mit Slug im HTML, validate() bei leerem
	 * Wert weiterhin true.
	 *
	 * @return void
	 */
	public function test_unknown_group_slug_renders_notice_and_does_not_fatal() {
		$options = array(
			'repeater_group' => 'gibt-es-nicht',
			'disable_dfv'    => true,
		);

		$html = $this->render_input( array(), $options );

		$this->assertStringContainsString( 'pods-repeater-group-notice', $html );
		$this->assertStringContainsString( 'gibt-es-nicht', $html, 'Der Hinweis muss den gesuchten Slug nennen.' );
		$this->assertSame( 1, $this->count_rows( $html ), 'Ohne Sub-Felder bleibt genau die geseedete Leerzeile.' );
		$this->assertStringNotContainsString( 'items[0][g_title]', $html );

		$this->assertSame( true, $this->field->validate( null, 'items', $options ) );
		$this->assertSame( true, $this->field->validate( array( array() ), 'items', $options ) );
	}

	/**
	 * (8) Unbekannter Slug mit zusätzlichem Inline-JSON: der Rückfall aufs
	 * JSON ist bewusst aus (eine gelöschte Gruppe darf keine alten
	 * Inline-Definitionen reaktivieren) — der Hinweis bleibt sichtbar.
	 *
	 * @return void
	 */
	public function test_unknown_group_slug_does_not_fall_back_to_inline_json() {
		$options                   = $this->inline_options();
		$options['repeater_group'] = 'geloescht';

		$html = $this->render_input( array(), $options );

		$this->assertStringContainsString( 'pods-repeater-group-notice', $html );
		$this->assertStringNotContainsString( 'in_only', $html, 'Ein gesetzter Slug darf das Inline-JSON nicht reaktivieren.' );
	}

	/**
	 * (9) validate() respektiert `required` aus den Gruppenfeldern: eine
	 * teilgefüllte Zeile ohne das Pflichtfeld ist ein WP_Error mit Code
	 * 'pods_repeater_required'; die geseedete Leerzeile blockiert nicht.
	 *
	 * @return void
	 */
	public function test_validate_respects_required_from_group_fields() {
		$options = $this->group_options();

		$this->assertSame(
			true,
			$this->field->validate( array( array() ), 'items', $options ),
			'Die leere Seed-Zeile darf required-Gruppenfelder nicht blockieren.'
		);

		$this->assertSame( true, $this->field->validate( $this->rows, 'items', $options ) );

		$result = $this->field->validate(
			array(
				array( 'g_subtitle' => 'nur Untertitel' ),
			),
			'items',
			$options
		);

		$this->assertIsArray( $result, 'Pflichtfeld-Verstoss muss als Meldungs-Array zurueckkommen (Pods-Vertrag).' );
		$this->assertStringContainsString( 'is required in every row', $result[0] );
	}

	/**
	 * (10) pre_save()/value() Roundtrip mit Gruppenfeldern: die Zeilen gehen
	 * verlustfrei durch das JSON-Meta, leere Zeilen werden verworfen.
	 *
	 * @return void
	 */
	public function test_pre_save_and_value_roundtrip_with_group_fields() {
		$json = $this->field->pre_save( $this->rows );

		$this->assertIsString( $json );

		$decoded = json_decode( $json, true );
		$this->assertSame( $this->rows, $decoded, 'pre_save() muss die Gruppen-Zeilen unverändert serialisieren.' );

		$this->assertSame( $this->rows, $this->field->value( $json ) );

		$this->assertSame( '[]', $this->field->pre_save( array( array() ) ), 'Leere Zeilen dürfen nicht gespeichert werden.' );
	}

	/**
	 * (11) display() gibt die Werte der Gruppenfelder aus und ignoriert
	 * Daten, die nur im (bei gesetzter Gruppe irrelevanten) Inline-Schema
	 * stehen.
	 *
	 * @return void
	 */
	public function test_display_renders_group_field_values() {
		$options = $this->group_options();

		$rows   = $this->rows;
		$rows[] = array(
			'g_title'    => 'Dritter Titel',
			'g_subtitle' => 'Dritter Untertitel',
			'in_only'    => 'INLINE_GEHEIM',
		);

		$html = $this->field->display( $rows, 'items', $options );

		$this->assertStringContainsString( 'Erster Titel', $html );
		$this->assertStringContainsString( 'Zweiter Untertitel', $html );
		$this->assertStringContainsString( 'Dritter Titel', $html );
		$this->assertStringNotContainsString( 'INLINE_GEHEIM', $html, 'Nicht deklarierte Zeilen-Keys dürfen nicht ausgegeben werden.' );
	}
}
