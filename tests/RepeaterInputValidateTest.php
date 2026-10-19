<?php
/**
 * RepeaterInputValidateTest — Render- und Validierungspfad des Repeater-Felds.
 *
 * Zweite Testklasse auf der WPUnit-Basis (tests/bootstrap.php). Während
 * RepeaterRoundtripTest den Speicherpfad (pre_save/value + wp_postmeta)
 * absichert, prüft diese Klasse die UI-Grenze, also genau das, was der
 * Admin zwischen Speichern und erneutem Laden sieht:
 *
 *   - input(): PHP-Renderer (disable_dfv = true, der Default aus options()).
 *     Gerendert wird pro Zeile und Sub-Feld ein Eingabeelement mit dem
 *     Namensmuster <feld>[<index>][<sub>], z. B. "items[0][subtitle]".
 *     Genau dieses Muster erwartet ui/js/repeater.js beim Klonen und
 *     Umnummerieren (ui/js/repeater.js:32 addRow, :123 renumber — Name wird
 *     aus data-name des Containers + Index + data-sub neu gebaut). Der Test
 *     hält den Vertrag zwischen PHP-Renderer und JS fest, ohne das JS
 *     anzufassen.
 *   - input() seedet bei leerem Wert GENAU EINE Leerzeile (Index 0), damit
 *     das JS-Template und "Add row" immer greifen (Klassenkommentar in
 *     input(), classes/fields/repeater.php:433-436).
 *   - validate(): vollständig leere Zeilen werden ignoriert (die geseedete
 *     Leerzeile darf required-Sub-Felder nicht blockieren); eine
 *     teilgefüllte Zeile ohne required-Sub-Feld liefert einen WP_Error mit
 *     Code 'pods_repeater_required'.
 *
 * Die Existenzprüfung der Feldklasse ist eine harte Assertion (kein Skip):
 * eine Umbenennung soll den Lauf rot machen, nicht still entwerten.
 *
 * Die Tests laufen gegen eine frische wp-tests-DB: sie rendern die Feldklasse
 * direkt (kein geseedeter Pod, kein Admin-Screen nötig).
 *
 * @package PodsRepeater
 */

/**
 * Class RepeaterInputValidateTest
 */
class RepeaterInputValidateTest extends WP_UnitTestCase {

	/**
	 * Feldklasse unter Test.
	 *
	 * @var PodsField_Repeater
	 */
	protected $field;

	/**
	 * Feld-Optionen des Test-Felds: drei Sub-Felder, 'subtitle' ist required.
	 * disable_dfv ist explizit gesetzt (im Pods-Formular kommt der Default aus
	 * options(); ein direkter input()-Aufruf bekommt keine Defaults gemerged).
	 *
	 * @var array
	 */
	protected $options;

	/**
	 * Testzeilen: zwei vollständig gefüllte Zeilen.
	 *
	 * @var array
	 */
	protected $rows;

	/**
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// Harte Assertion statt markTestSkipped(): ohne Feldklasse ist die
		// gesamte UI-Grenze ungetestet und der Lauf muss rot werden.
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

		$this->options = array(
			'repeater_sub_fields' => wp_json_encode(
				array(
					array(
						'name'     => 'subtitle',
						'label'    => 'Subtitle',
						'type'     => 'text',
						'required' => true,
					),
					array(
						'name'  => 'link',
						'label' => 'Link',
						'type'  => 'text',
					),
					array(
						'name'  => 'body',
						'label' => 'Body',
						'type'  => 'textarea',
					),
				)
			),
			'disable_dfv'         => true,
		);

		$this->rows = array(
			array(
				'subtitle' => 'Erste Zeile',
				'link'     => 'https://example.com/erste',
				'body'     => 'Erster Text.',
			),
			array(
				'subtitle' => 'Zweite Zeile',
				'link'     => 'https://example.com/zweite',
				'body'     => 'Zweiter Text.',
			),
		);
	}

	/**
	 * Rendert das Feld über input() und gibt das HTML zurück.
	 *
	 * input() echo-t (Pods-Konvention, kein Rückgabewert), daher der
	 * ob_start()-Mantel.
	 *
	 * @param mixed       $value   Feldwert (Array, JSON-String oder leer).
	 * @param array|null  $options Feld-Optionen (null = Test-Defaults).
	 * @param string      $name    Feldname.
	 *
	 * @return string Gerendertes HTML.
	 */
	protected function render_input( $value, $options = null, $name = 'items' ) {
		ob_start();
		$this->field->input( $name, $value, null === $options ? $this->options : $options );

		return (string) ob_get_clean();
	}

	/**
	 * Zählt die gerenderten Zeilen-Container.
	 *
	 * @param string $html Gerendertes HTML.
	 *
	 * @return int
	 */
	protected function count_rows( $html ) {
		return (int) preg_match_all( '/class="pods-repeater-row"/', $html );
	}

	/**
	 * Liest den value-Attributwert des Eingabeelements mit dem gegebenen Namen.
	 *
	 * @param string $html Gerendertes HTML.
	 * @param string $name Vollständiger Feldname, z. B. items[0][subtitle].
	 *
	 * @return string|null Wert oder null, wenn kein Element gefunden wurde.
	 */
	protected function input_value( $html, $name ) {
		$pattern = '/name="' . preg_quote( $name, '/' ) . '"[^>]*value="([^"]*)"/';

		if ( 1 !== preg_match( $pattern, $html, $matches ) ) {
			return null;
		}

		return html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Liest den Inhalt eines <textarea>-Elements mit dem gegebenen Namen.
	 *
	 * @param string $html Gerendertes HTML.
	 * @param string $name Vollständiger Feldname.
	 *
	 * @return string|null Inhalt oder null, wenn kein Element gefunden wurde.
	 */
	protected function textarea_value( $html, $name ) {
		$pattern = '/name="' . preg_quote( $name, '/' ) . '"[^>]*>(.*?)<\/textarea>/s';

		if ( 1 !== preg_match( $pattern, $html, $matches ) ) {
			return null;
		}

		return html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * input() rendert gespeicherte Zeilen als <feld>[<index>][<sub>].
	 *
	 * Geprüft wird der konkrete Namens-String, nicht nur "nicht leer": das
	 * Muster ist der Vertrag mit ui/js/repeater.js (addRow/renumber bauen
	 * Name und ID aus data-name + Index + data-sub) und mit dem POST-Pfad
	 * des Admin-Formulars.
	 *
	 * @return void
	 */
	public function test_input_renders_stored_rows_with_expected_name_pattern() {
		$html = $this->render_input( $this->rows );

		$this->assertNotSame( '', $html, 'input() muss HTML ausgeben (echo), nicht leer bleiben.' );
		$this->assertSame( 2, $this->count_rows( $html ), 'Zwei gespeicherte Zeilen müssen zwei Zeilen-Container ergeben.' );

		// Container-Name: data-name ist die Basis, aus der das JS die
		// Namen neuer Zeilen baut (ui/js/repeater.js:22, :111).
		$this->assertStringContainsString( 'data-name="items"', $html, 'data-name muss der Feldname ohne Index sein.' );
		$this->assertStringContainsString( 'data-index="0"', $html );
		$this->assertStringContainsString( 'data-index="1"', $html );

		// Namensmuster pro Zeile und Sub-Feld, jeweils genau einmal.
		$expected_names = array(
			'items[0][subtitle]',
			'items[0][link]',
			'items[0][body]',
			'items[1][subtitle]',
			'items[1][link]',
			'items[1][body]',
		);

		foreach ( $expected_names as $expected_name ) {
			$this->assertSame(
				1,
				substr_count( $html, 'name="' . $expected_name . '"' ),
				'Eingabename "' . $expected_name . '" muss genau einmal gerendert werden.'
			);
		}

		// IDs folgen dem JS-Muster <name>_<index>_<sub> (addRow/renumber).
		$this->assertStringContainsString( 'id="items_0_subtitle"', $html );
		$this->assertStringContainsString( 'id="items_0_link"', $html );
		$this->assertStringContainsString( 'id="items_1_subtitle"', $html );

		// data-sub am Eingabeelement: Klon-/Renumber-Kontrakt des JS.
		$this->assertStringContainsString( 'data-sub="subtitle"', $html );
		$this->assertStringContainsString( 'data-sub="body"', $html );

		// Werte landen beim richtigen Feld (keine Verschiebung der Spalten).
		$this->assertSame( 'Erste Zeile', $this->input_value( $html, 'items[0][subtitle]' ) );
		$this->assertSame( 'https://example.com/erste', $this->input_value( $html, 'items[0][link]' ) );
		$this->assertSame( 'Zweite Zeile', $this->input_value( $html, 'items[1][subtitle]' ) );
		$this->assertSame( 'https://example.com/zweite', $this->input_value( $html, 'items[1][link]' ) );
		$this->assertSame( 'Erster Text.', $this->textarea_value( $html, 'items[0][body]' ) );

		// Keine Zeile über die gespeicherten Daten hinaus.
		$this->assertStringNotContainsString( 'items[2]', $html );
		$this->assertStringNotContainsString( 'id="items_2_', $html );
		$this->assertSame( '0', $this->get_data_limit( $html ), 'Ohne repeater_limit muss data-limit="0" (unbegrenzt) gerendert werden.' );
	}

	/**
	 * input() akzeptiert den rohen JSON-String aus dem Admin-Formular.
	 *
	 * Im Admin-Pfad liefert $pod->field( array( 'in_form' => true ) ) den
	 * rohen JSON-String aus wp_postmeta (value()/Decode wird dort
	 * übersprungen, siehe Kommentar in input()). Der Renderer muss ihn
	 * genauso verarbeiten wie ein Array.
	 *
	 * @return void
	 */
	public function test_input_accepts_raw_json_string_from_admin_form() {
		$html = $this->render_input( wp_json_encode( $this->rows ) );

		$this->assertSame( 2, $this->count_rows( $html ) );
		$this->assertSame(
			1,
			substr_count( $html, 'name="items[1][subtitle]"' ),
			'Auch bei JSON-String-Eingabe muss das Namensmuster items[1][subtitle] erscheinen.'
		);
		$this->assertSame( 'Zweite Zeile', $this->input_value( $html, 'items[1][subtitle]' ) );
		$this->assertSame( 'Zweiter Text.', $this->textarea_value( $html, 'items[1][body]' ) );
	}

	/**
	 * textarea-Sub-Felder rendern nativ, nicht als DFV-React-Mount.
	 *
	 * Regression zu Sprint 3: Pods hat keine Feldklasse für textarea, der Typ
	 * fällt auf PodsField::input() zurück — und das ignoriert disable_dfv und
	 * rendert einen DFV-Mount, in dem der Feldname nur im JSON-Blob steht.
	 * Folge: kein name="..." im HTML, ein Formular-POST liefert für die
	 * Spalte nie einen Wert, textarea-Sub-Felder wären nicht speicherbar.
	 *
	 * Geprüft wird deshalb das SOLL-Verhalten des POST-Pfads: genau ein
	 * natives <textarea> pro Zeile mit echtem Namensattribut, kein
	 * DFV-Markup, Wert escaped im Tag. Dazu die Gegenprobe, dass text
	 * weiterhin nativ rendert (der Fix darf nur den DFV-Fall umleiten).
	 *
	 * @return void
	 */
	public function test_textarea_sub_field_renders_native_textarea_without_dfv() {
		$body_0 = 'Zeile 1 & <b>"fett"</b>';
		$body_1 = "Zeile 2\nmit Umbruch";

		$html = $this->render_input(
			array(
				array(
					'subtitle' => 'Titel 1',
					'link'     => 'https://example.com/1',
					'body'     => $body_0,
				),
				array(
					'subtitle' => 'Titel 2',
					'link'     => 'https://example.com/2',
					'body'     => $body_1,
				),
			)
		);

		// 1. Natives <textarea> pro Zeile, getragen vom echten Namen — genau
		//    einmal, damit der POST-Wert nicht doppelt oder gar nicht ankommt.
		foreach ( array( 0, 1 ) as $index ) {
			$name = 'items[' . $index . '][body]';

			$this->assertSame(
				1,
				preg_match_all( '/<textarea\b[^>]*name="' . preg_quote( $name, '/' ) . '"/', $html ),
				'<textarea name="' . $name . '"> muss genau einmal gerendert werden (POST-Pfad).'
			);
			$this->assertSame(
				1,
				substr_count( $html, 'name="' . $name . '"' ),
				'Eingabename "' . $name . '" muss genau einmal im HTML stehen.'
			);
		}

		// 2. Kein DFV-Mount mehr: weder der React-Wrapper noch der JSON-Blob,
		//    in dem der Name sonst versteckt wäre.
		$this->assertStringNotContainsString(
			'pods-dfv-field-data',
			$html,
			'textarea darf keinen DFV-JSON-Blob mehr rendern (Name läge nur dort).'
		);
		$this->assertStringNotContainsString(
			'pods-dfv-field',
			$html,
			'textarea darf keinen DFV-React-Mount mehr rendern.'
		);

		// 3. Wert korrekt escaped im Tag (esc_textarea), inkl. Anführungs-
		//    zeichen und Umbruch; kein rohes Markup aus dem Wert.
		$this->assertStringContainsString( esc_textarea( $body_0 ), $html, 'Textarea-Wert muss escaped im Tag stehen.' );
		$this->assertStringNotContainsString( '<b>', $html, 'HTML aus dem Wert darf nicht als Markup im Feld landen.' );
		$this->assertStringNotContainsString( '"fett"', $html, 'Anführungszeichen im Wert müssen escaped sein.' );
		$this->assertSame( $body_0, $this->textarea_value( $html, 'items[0][body]' ), 'Wert der ersten Zeile muss unverändert zurückgelesen werden.' );
		$this->assertSame( $body_1, $this->textarea_value( $html, 'items[1][body]' ), 'Wert der zweiten Zeile muss unverändert zurückgelesen werden.' );

		// data-sub bleibt am Eingabeelement: davon lebt die Klon-/Renumber-
		// Logik in ui/js/repeater.js ([data-sub] pro Zeile).
		$this->assertSame( 2, substr_count( $html, 'data-sub="body"' ), 'Jede textarea-Zeile braucht data-sub für das JS.' );

		// 4. Gegenprobe: text rendert unverändert nativ (input mit value),
		//    wird also nicht versehentlich auf den Template-Pfad umgeleitet.
		$this->assertSame(
			1,
			preg_match_all( '/<input\b[^>]*name="items\[0\]\[subtitle\]"/', $html ),
			'text-Sub-Feld muss weiterhin als <input> mit echtem Namen rendern.'
		);
		$this->assertStringContainsString( 'type="text"', $html );
		$this->assertSame( 'Titel 1', $this->input_value( $html, 'items[0][subtitle]' ) );
		$this->assertSame( 'https://example.com/1', $this->input_value( $html, 'items[0][link]' ) );
	}

	/**
	 * input() seedet bei leerem Wert genau EINE Leerzeile.
	 *
	 * Die Leerzeile ist Template für "Add row" (das JS klont die erste
	 * Zeile) und wird von pre_save() beim Speichern verworfen. Mehrere
	 * Seed-Zeilen würden den Editor mit leeren Zeilen fluten.
	 *
	 * @return void
	 */
	public function test_input_seeds_exactly_one_empty_row_for_empty_value() {
		$empty_values = array(
			'leerer String (keine Meta vorhanden)' => '',
			'JSON-Leerarray'                        => '[]',
			'leeres Array'                          => array(),
			'null'                                  => null,
		);

		foreach ( $empty_values as $label => $value ) {
			$html = $this->render_input( $value );

			$this->assertSame( 1, $this->count_rows( $html ), 'Genau eine Seed-Leerzeile erwartet für: ' . $label );
			$this->assertSame(
				1,
				substr_count( $html, 'name="items[0][subtitle]"' ),
				'Seed-Zeile muss Index 0 mit Namensmuster items[0][subtitle] rendern (' . $label . ').'
			);
			$this->assertSame( '', $this->input_value( $html, 'items[0][subtitle]' ), 'Seed-Zeile muss leer sein (' . $label . ').' );
			$this->assertStringNotContainsString( 'items[1]', $html, 'Keine zweite Seed-Zeile (' . $label . ').' );
			$this->assertStringNotContainsString( 'data-index="1"', $html, 'Keine zweite Seed-Zeile (' . $label . ').' );
		}
	}

	/**
	 * validate() ignoriert vollständig leere Zeilen.
	 *
	 * Die von input() geseedete Leerzeile (und eine im Formular leer
	 * abgeschickte Zeile) darf ein required-Sub-Feld nicht blockieren —
	 * sonst wäre jedes Speichern eines noch leeren Repeaters unmöglich.
	 *
	 * @return void
	 */
	public function test_validate_ignores_completely_empty_rows() {
		// Nur die geseedete Leerzeile.
		$this->assertSame(
			true,
			$this->field->validate( array( array() ), 'items', $this->options ),
			'Eine leere Zeile darf required nicht blockieren.'
		);

		// Leerzeile mit explizit leeren Sub-Feld-Werten (Formular-POST).
		$this->assertSame(
			true,
			$this->field->validate(
				array(
					array(
						'subtitle' => '',
						'link'     => '',
						'body'     => '',
					),
				),
				'items',
				$this->options
			),
			'Eine Zeile mit lauter leeren Werten zählt als leer und darf required nicht blockieren.'
		);

		// Leerzeile zusammen mit einer vollständigen Zeile.
		$rows = $this->rows;
		array_unshift( $rows, array( 'subtitle' => '', 'link' => '', 'body' => '' ) );

		$this->assertSame(
			true,
			$this->field->validate( $rows, 'items', $this->options ),
			'Leerzeilen zwischen gefüllten Zeilen dürfen die Validierung nicht kippen.'
		);

		// JSON-String-Pfad (Admin-Formular) verhält sich gleich.
		$this->assertSame(
			true,
			$this->field->validate( wp_json_encode( array( array() ) ), 'items', $this->options ),
			'Auch als JSON-String darf eine leere Zeile required nicht blockieren.'
		);

		// Kein Wert / kein Array: nichts zu validieren.
		$this->assertSame( true, $this->field->validate( null, 'items', $this->options ) );
		$this->assertSame( true, $this->field->validate( array(), 'items', $this->options ) );
	}

	/**
	 * validate() liefert WP_Error, wenn eine teilgefüllte Zeile das
	 * required-Sub-Feld nicht enthält.
	 *
	 * "Teilgefüllt" heißt: mindestens ein Sub-Feld ist gesetzt, also zählt
	 * die Zeile nicht als leer und wird geprüft. Der Fehler kommt als
	 * WP_Error mit Code 'pods_repeater_required' und dem Sub-Feld-Label in
	 * der Meldung (siehe validate() in classes/fields/repeater.php).
	 *
	 * @return void
	 */
	public function test_validate_returns_wp_error_when_required_sub_field_missing_in_partially_filled_row() {
		$partial = array(
			array(
				'subtitle' => '',
				'link'     => 'https://example.com/nur-link',
				'body'     => 'Text ohne Titel',
			),
		);

		$result = $this->field->validate( $partial, 'items', $this->options );

		$this->assertInstanceOf( 'WP_Error', $result, 'Teilgefüllte Zeile ohne required-Sub-Feld muss einen WP_Error liefern.' );
		$this->assertSame( 'pods_repeater_required', $result->get_error_code() );
		$this->assertStringContainsString( 'Subtitle', $result->get_error_message(), 'Die Fehlermeldung muss das Label des required-Sub-Felds nennen.' );

		// Gleiches Verhalten über den JSON-String-Pfad des Admin-Formulars.
		$result_json = $this->field->validate( wp_json_encode( $partial ), 'items', $this->options );

		$this->assertInstanceOf( 'WP_Error', $result_json );
		$this->assertSame( 'pods_repeater_required', $result_json->get_error_code() );

		// Gegenprobe: required gesetzt → keine Fehlermeldung (kein Over-Blocking).
		$this->assertSame(
			true,
			$this->field->validate( $this->rows, 'items', $this->options ),
			'Zeilen mit gesetztem required-Sub-Feld müssen valide sein.'
		);

		// Gegenprobe: fehlendes optionales Sub-Feld allein ist kein Fehler.
		$this->assertSame(
			true,
			$this->field->validate(
				array(
					array( 'subtitle' => 'Nur der Titel' ),
				),
				'items',
				$this->options
			),
			'Fehlende optionale Sub-Felder dürfen keinen Fehler erzeugen.'
		);
	}

	/**
	 * input() übernimmt den name_prefix des Admin-Formulars.
	 *
	 * PodsMeta rendert Meta-Boxen mit $field_prefix = 'pods_meta_'
	 * (PodsMeta.php:1354); die POST-Daten sind entsprechend geprefixt.
	 * Rendert das Feld ungeprefixed, liest der Admin-Save einen leeren
	 * $_POST-Slot und löscht den gespeicherten Wert (siehe get_field_name()).
	 *
	 * @return void
	 */
	public function test_input_applies_admin_form_name_prefix() {
		$options                = $this->options;
		$options['name_prefix'] = 'pods_meta_';

		$html = $this->render_input( $this->rows, $options );

		$this->assertStringContainsString( 'data-name="pods_meta_items"', $html, 'data-name muss den Prefix enthalten (JS baut Namen daraus).' );
		$this->assertSame(
			1,
			substr_count( $html, 'name="pods_meta_items[0][subtitle]"' ),
			'Geprefixter Name muss pods_meta_items[0][subtitle] sein.'
		);
		$this->assertStringContainsString( 'id="pods_meta_items_0_subtitle"', $html );
		$this->assertStringNotContainsString( 'name="items[', $html, 'Ohne Prefix gerenderte Namen würden beim Admin-Save ins Leere laufen.' );
	}

	/**
	 * Liest data-limit aus dem Feld-Wrapper.
	 *
	 * @param string $html Gerendertes HTML.
	 *
	 * @return string|null
	 */
	protected function get_data_limit( $html ) {
		if ( 1 !== preg_match( '/class="[^"]*pods-repeater"[^>]*data-limit="([^"]*)"/', $html, $matches ) ) {
			return null;
		}

		return $matches[1];
	}
}
