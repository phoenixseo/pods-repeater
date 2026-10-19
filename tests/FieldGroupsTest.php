<?php
/**
 * FieldGroupsTest — Feldgruppen-Registry (Datenebene + API).
 *
 * Prüft die API aus includes/field-groups.php: CRUD auf der WP-Option
 * `pods_repeater_groups`, Slug-/Label-/Feld-Normalisierung, Validierung
 * (Typ-Whitelist, doppelte Feldnamen, Pflichtfelder), Feldreihenfolge,
 * Versionszähler und das Verhalten bei kaputten Optionswerten.
 *
 * Die Tests laufen gegen die frische wp-tests-DB. Jeder Test räumt die
 * beiden Registry-Optionen in set_up() weg — WP_UnitTestCase rollt zwar die
 * DB zurück, lässt aber den Options-Object-Cache stehen; ohne explizites
 * delete_option() würden Werte zwischen Tests lecken.
 *
 * @package PodsRepeater
 */

/**
 * Class FieldGroupsTest
 */
class FieldGroupsTest extends WP_UnitTestCase {

	/**
	 * Registry-Zustand vor jedem Test zurücksetzen.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( PODS_REPEATER_GROUPS_OPTION );
		delete_option( PODS_REPEATER_GROUPS_VERSION_OPTION );
	}

	/**
	 * Baut eine einfache Feld-Definition.
	 *
	 * @param string $name Feldname.
	 * @param string $type Feldtyp.
	 *
	 * @return array
	 */
	private function field( $name, $type = 'text' ) {
		return array(
			'name'  => $name,
			'label' => ucfirst( $name ),
			'type'  => $type,
		);
	}

	/**
	 * (1) Registry ist initial leer und nach dem ersten Save gefüllt.
	 *
	 * @return void
	 */
	public function test_get_groups_empty_initially_and_filled_after_save() {
		$this->assertSame( array(), pods_repeater_get_groups(), 'Frische Registry muss leer sein.' );

		$slug = pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array( $this->field( 'title' ) ),
			)
		);

		$this->assertSame( 'hero', $slug );

		$groups = pods_repeater_get_groups();
		$this->assertIsArray( $groups );
		$this->assertCount( 1, $groups );
		$this->assertArrayHasKey( 'hero', $groups );
		$this->assertSame( 'Hero', $groups['hero']['label'] );
		$this->assertCount( 1, $groups['hero']['fields'] );
	}

	/**
	 * (2) Save legt an; der Slug wird aus dem Label abgeleitet.
	 *
	 * @return void
	 */
	public function test_save_creates_group_and_derives_slug_from_label() {
		$slug = pods_repeater_save_group(
			array(
				'label'  => 'Call to Action',
				'fields' => array( $this->field( 'headline' ) ),
			)
		);

		$this->assertSame( 'call-to-action', $slug );

		$group = pods_repeater_get_group( 'call-to-action' );
		$this->assertIsArray( $group );
		$this->assertSame( 'Call to Action', $group['label'] );
		$this->assertSame( 'headline', $group['fields'][0]['name'] );
		$this->assertSame( 'text', $group['fields'][0]['type'] );
		$this->assertSame( array( 'call-to-action' ), array_keys( pods_repeater_get_groups() ) );
	}

	/**
	 * (3) Gleicher Slug aktualisiert die bestehende Gruppe, statt sie zu
	 * duplizieren — sowohl über das abgeleitete Label als auch über einen
	 * explizit übergebenen Slug.
	 *
	 * @return void
	 */
	public function test_save_with_same_slug_updates_instead_of_duplicating() {
		pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array( $this->field( 'title' ) ),
			)
		);

		pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array( $this->field( 'subtitle' ), $this->field( 'link', 'website' ) ),
			)
		);

		$this->assertCount( 1, pods_repeater_get_groups(), 'Gleiches Label darf keine zweite Gruppe anlegen.' );
		$this->assertSame( array( 'subtitle', 'link' ), array_column( pods_repeater_get_group_fields( 'hero' ), 'name' ) );

		// Expliziter Slug + neues Label: derselbe Eintrag wird umbenannt.
		$slug = pods_repeater_save_group(
			array(
				'slug'   => 'hero',
				'label'  => 'Hero Neu',
				'fields' => array( $this->field( 'title' ) ),
			)
		);

		$this->assertSame( 'hero', $slug );
		$this->assertCount( 1, pods_repeater_get_groups() );
		$this->assertSame( 'Hero Neu', pods_repeater_get_group( 'hero' )['label'] );
		$this->assertSame( array( 'title' ), array_column( pods_repeater_get_group_fields( 'hero' ), 'name' ) );
	}

	/**
	 * (4) Slug-Normalisierung: Großbuchstaben, Leerzeichen, Sonderzeichen.
	 *
	 * @return void
	 */
	public function test_slug_normalization() {
		$this->assertSame(
			'mein-hero-mehr',
			pods_repeater_save_group( array( 'label' => 'Mein Hero & Mehr!' ) )
		);

		$this->assertSame(
			'gross-klein-2026',
			pods_repeater_save_group( array( 'label' => 'GROSS klein 2026' ) )
		);

		// Explizit übergebener Slug wird genauso normalisiert.
		$this->assertSame(
			'hero-2026-test',
			pods_repeater_save_group(
				array(
					'slug'  => ' Hero 2026 / Test ',
					'label' => 'Beliebig',
				)
			)
		);

		// Abfrage mit rohem Slug findet den normalisierten Eintrag.
		$this->assertIsArray( pods_repeater_get_group( 'MEIN HERO & MEHR!' ) );
		$this->assertSame( 'Mein Hero & Mehr!', pods_repeater_get_group( 'mein-hero-mehr' )['label'] );
	}

	/**
	 * (5) Unbekannter Feldtyp → WP_Error, bestehende Gruppe bleibt unverändert.
	 *
	 * @return void
	 */
	public function test_unknown_field_type_returns_wp_error_and_keeps_group() {
		pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array( $this->field( 'title' ) ),
			)
		);
		$before = pods_repeater_get_group( 'hero' );

		$this->assertNotContains( 'url', pods_repeater_supported_sub_types(), 'url ist kein nativer Pods-Typ.' );

		$result = pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array( $this->field( 'title', 'url' ) ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pods_repeater_invalid_type', $result->get_error_code() );
		$this->assertSame( 'title', $result->get_error_data()['field'] );
		$this->assertSame( 'url', $result->get_error_data()['type'] );

		$this->assertSame( $before, pods_repeater_get_group( 'hero' ), 'Fehlgeschlagener Save darf die Gruppe nicht verändern.' );

		// Auch eine noch nicht existierende Gruppe wird nicht angelegt.
		$new = pods_repeater_save_group(
			array(
				'label'  => 'Kaputt',
				'fields' => array( $this->field( 'x', 'does_not_exist' ) ),
			)
		);
		$this->assertWPError( $new );
		$this->assertNull( pods_repeater_get_group( 'kaputt' ) );
		$this->assertCount( 1, pods_repeater_get_groups() );
	}

	/**
	 * (6) Doppelter Feldname → WP_Error (auch nach sanitize_key-Kollision).
	 *
	 * @return void
	 */
	public function test_duplicate_field_name_returns_wp_error() {
		$result = pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array( $this->field( 'title' ), $this->field( 'title' ) ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pods_repeater_duplicate_field', $result->get_error_code() );
		$this->assertNull( pods_repeater_get_group( 'hero' ) );

		// Kollision erst nach Normalisierung: 'Titel' und 'titel' → 'titel'.
		$collision = pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array( $this->field( 'Titel' ), $this->field( 'titel' ) ),
			)
		);

		$this->assertWPError( $collision );
		$this->assertSame( 'pods_repeater_duplicate_field', $collision->get_error_code() );
		$this->assertCount( 0, pods_repeater_get_groups() );
	}

	/**
	 * (7) Feldreihenfolge bleibt erhalten — auch bei erneutem Save in anderer
	 * Reihenfolge (keine Sortierung, keine Map-Semantik).
	 *
	 * @return void
	 */
	public function test_field_order_is_preserved() {
		pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array(
					$this->field( 'zebra' ),
					$this->field( 'alpha' ),
					$this->field( 'mitte' ),
				),
			)
		);

		$this->assertSame(
			array( 'zebra', 'alpha', 'mitte' ),
			array_column( pods_repeater_get_group_fields( 'hero' ), 'name' )
		);

		pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array(
					$this->field( 'mitte' ),
					$this->field( 'zebra' ),
					$this->field( 'alpha' ),
				),
			)
		);

		$this->assertSame(
			array( 'mitte', 'zebra', 'alpha' ),
			array_column( pods_repeater_get_group_fields( 'hero' ), 'name' ),
			'Gespeicherte Reihenfolge muss der übergebenen Liste folgen.'
		);
	}

	/**
	 * (8) Label (Gruppe und Feld) wird durch sanitize_text_field() bereinigt.
	 *
	 * @return void
	 */
	public function test_labels_are_sanitized() {
		$slug = pods_repeater_save_group(
			array(
				'label'  => '<b>Hero</b> & <script>alert(1)</script>Titel',
				'fields' => array(
					array(
						'name'  => 'title',
						'label' => '<i>Titel</i> <script>x</script>Fett',
						'type'  => 'text',
					),
				),
			)
		);

		$group = pods_repeater_get_group( $slug );

		$this->assertSame( 'hero-titel', $slug, 'Slug wird aus dem bereinigten Label abgeleitet.' );
		$this->assertIsArray( $group );
		$this->assertStringNotContainsString( '<', $group['label'] );
		$this->assertStringNotContainsString( 'script', $group['label'] );
		$this->assertSame( 'Hero & Titel', $group['label'] );
		$this->assertStringNotContainsString( '<', $group['fields'][0]['label'] );
		$this->assertSame( 'Titel Fett', $group['fields'][0]['label'] );
	}

	/**
	 * (9) delete_group() entfernt genau eine Gruppe; andere bleiben.
	 *
	 * @return void
	 */
	public function test_delete_group_removes_only_that_group() {
		pods_repeater_save_group( array( 'label' => 'Hero' ) );
		pods_repeater_save_group( array( 'label' => 'Footer' ) );
		pods_repeater_save_group( array( 'label' => 'Sidebar' ) );

		$this->assertCount( 3, pods_repeater_get_groups() );
		$this->assertTrue( pods_repeater_delete_group( 'footer' ) );

		$groups = pods_repeater_get_groups();
		$this->assertCount( 2, $groups );
		$this->assertArrayHasKey( 'hero', $groups );
		$this->assertArrayHasKey( 'sidebar', $groups );
		$this->assertArrayNotHasKey( 'footer', $groups );
		$this->assertNull( pods_repeater_get_group( 'footer' ) );
	}

	/**
	 * (10) delete_group() mit unbekanntem Slug → false.
	 *
	 * @return void
	 */
	public function test_delete_unknown_group_returns_false() {
		pods_repeater_save_group( array( 'label' => 'Hero' ) );

		$this->assertFalse( pods_repeater_delete_group( 'gibt-es-nicht' ) );
		$this->assertFalse( pods_repeater_delete_group( '' ) );
		$this->assertTrue( pods_repeater_delete_group( 'HERO ' ), 'Bekannter Slug muss löschbar sein.' );
		$this->assertCount( 0, pods_repeater_get_groups() );
	}

	/**
	 * (11) get_group_fields() mit unbekanntem Slug → leeres Array.
	 *
	 * @return void
	 */
	public function test_get_group_fields_unknown_slug_returns_empty_array() {
		$this->assertSame( array(), pods_repeater_get_group_fields( 'unbekannt' ) );
		$this->assertNull( pods_repeater_get_group( 'unbekannt' ) );

		// Gruppe ohne Felder (Entwurf) liefert ebenfalls ein leeres Array.
		pods_repeater_save_group( array( 'label' => 'Entwurf' ) );
		$this->assertSame( array(), pods_repeater_get_group_fields( 'entwurf' ) );
	}

	/**
	 * (12) Versions-Option wird beim ersten Save gesetzt und danach bei jeder
	 * Änderung erhöht.
	 *
	 * @return void
	 */
	public function test_version_option_is_set_and_incremented() {
		$this->assertSame( 0, pods_repeater_get_groups_version(), 'Vor dem ersten Schreiben gibt es keine Version.' );

		pods_repeater_save_group( array( 'label' => 'Hero' ) );
		$this->assertSame( 1, pods_repeater_get_groups_version() );
		$this->assertSame( 1, (int) get_option( PODS_REPEATER_GROUPS_VERSION_OPTION ) );

		pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array( $this->field( 'title' ) ),
			)
		);
		$this->assertSame( 2, pods_repeater_get_groups_version(), 'Update muss die Version erhöhen.' );

		pods_repeater_save_group( array( 'label' => 'Footer' ) );
		$this->assertSame( 3, pods_repeater_get_groups_version() );

		pods_repeater_delete_group( 'footer' );
		$this->assertSame( 4, pods_repeater_get_groups_version(), 'Auch Löschen ist eine Änderung.' );
	}

	/**
	 * (13) Kaputte Optionswerte (String/Liste/null) → leeres Array, ohne
	 * Notice/Warnung. PHPUnit wandelt Notices in Exceptions, der Test würde
	 * also bei einer Warnung rot.
	 *
	 * @return void
	 */
	public function test_broken_option_value_returns_empty_array_without_notice() {
		update_option( PODS_REPEATER_GROUPS_OPTION, 'kaputter-string' );
		$this->assertSame( array(), pods_repeater_get_groups() );
		$this->assertNull( pods_repeater_get_group( 'irgendwas' ) );
		$this->assertSame( array(), pods_repeater_get_group_fields( 'irgendwas' ) );

		// Liste statt Map (numerische Keys) → keine gültigen Gruppen.
		update_option( PODS_REPEATER_GROUPS_OPTION, array( array( 'label' => 'Hero' ), array( 'label' => 'Footer' ) ) );
		$this->assertSame( array(), pods_repeater_get_groups() );

		// Gemischt: gültiger Eintrag bleibt, Müll wird verworfen.
		update_option(
			PODS_REPEATER_GROUPS_OPTION,
			array(
				'hero'  => array(
					'label'  => 'Hero',
					'fields' => array( $this->field( 'title' ) ),
				),
				'muell' => 'kein-array',
			)
		);

		$groups = pods_repeater_get_groups();
		$this->assertCount( 1, $groups );
		$this->assertArrayHasKey( 'hero', $groups );
		$this->assertArrayNotHasKey( 'muell', $groups );

		// Ein anschließender Save darf die kaputte Struktur reparieren.
		pods_repeater_save_group( array( 'label' => 'Footer' ) );
		$this->assertCount( 2, pods_repeater_get_groups() );
	}

	/**
	 * (14) supported_sub_types() enthält die verifizierte Startliste, filterbar.
	 *
	 * @return void
	 */
	public function test_supported_sub_types_contains_startlist_and_is_filterable() {
		$types = pods_repeater_supported_sub_types();

		$expected = array(
			'text',
			'textarea',
			'paragraph',
			'number',
			'website',
			'email',
			'password',
			'wysiwyg',
			'code',
			'date',
			'time',
			'datetime',
			'color',
			'boolean',
			'pick',
		);

		foreach ( $expected as $type ) {
			$this->assertContains( $type, $types, sprintf( 'Typ "%s" muss unterstützt werden.', $type ) );
		}

		$this->assertNotContains( 'repeater', $types, 'Repeater darf sich nicht selbst verschachteln.' );
		$this->assertNotContains( 'heading', $types, 'heading ist display-only.' );
		$this->assertNotContains( 'html', $types, 'html ist display-only.' );
		$this->assertSame( $types, array_values( array_unique( $types ) ), 'Keine Duplikate in der Whitelist.' );

		// Filter kann Typen ergänzen.
		$add_heading = static function ( $types ) {
			$types[] = 'heading';
			return $types;
		};
		add_filter( 'pods_repeater_supported_sub_types', $add_heading );
		$this->assertContains( 'heading', pods_repeater_supported_sub_types() );
		remove_filter( 'pods_repeater_supported_sub_types', $add_heading );

		// Filter kann Typen entfernen — und der Save respektiert das.
		$drop_pick = static function ( $types ) {
			return array_values( array_diff( $types, array( 'pick' ) ) );
		};
		add_filter( 'pods_repeater_supported_sub_types', $drop_pick );
		$this->assertNotContains( 'pick', pods_repeater_supported_sub_types() );
		$rejected = pods_repeater_save_group(
			array(
				'label'  => 'Mit Pick',
				'fields' => array( $this->field( 'auswahl', 'pick' ) ),
			)
		);
		remove_filter( 'pods_repeater_supported_sub_types', $drop_pick );

		$this->assertWPError( $rejected );
		$this->assertSame( 'pods_repeater_invalid_type', $rejected->get_error_code() );

		// Nicht-Array-Rückgabe des Filters → leere Liste statt Fatal.
		add_filter( 'pods_repeater_supported_sub_types', '__return_false' );
		$this->assertSame( array(), pods_repeater_supported_sub_types() );
		remove_filter( 'pods_repeater_supported_sub_types', '__return_false' );
	}

	/**
	 * (15) Pflichtfeld Label: fehlt/leer → WP_Error, Registry unverändert.
	 *
	 * @return void
	 */
	public function test_missing_label_returns_wp_error() {
		$missing = pods_repeater_save_group( array( 'fields' => array( $this->field( 'title' ) ) ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'pods_repeater_missing_label', $missing->get_error_code() );

		$empty = pods_repeater_save_group(
			array(
				'label'  => '   ',
				'fields' => array( $this->field( 'title' ) ),
			)
		);
		$this->assertWPError( $empty );
		$this->assertSame( 'pods_repeater_missing_label', $empty->get_error_code() );

		$this->assertSame( array(), pods_repeater_get_groups() );
	}

	/**
	 * (16) Feld-Normalisierung: Name via sanitize_key, required als bool,
	 * options nur als Array, fehlender Name → WP_Error.
	 *
	 * @return void
	 */
	public function test_field_normalization_required_and_options() {
		$slug = pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array(
					array(
						'name'     => ' Title ',
						'label'    => 'Titel',
						'type'     => 'text',
						'required' => '1',
						'options'  => 'kein-array',
					),
					array(
						'name'     => 'cta',
						'type'     => 'website',
						'required' => 0,
						'options'  => array( 'placeholder' => 'https://' ),
					),
				),
			)
		);

		$fields = pods_repeater_get_group_fields( $slug );

		$this->assertSame( 'title', $fields[0]['name'] );
		$this->assertTrue( $fields[0]['required'] );
		$this->assertSame( array(), $fields[0]['options'], 'Nicht-Array-Options müssen verworfen werden.' );
		$this->assertFalse( $fields[1]['required'] );
		$this->assertSame( array( 'placeholder' => 'https://' ), $fields[1]['options'] );

		// Fehlender Feldname → WP_Error, nichts wird gespeichert.
		$no_name = pods_repeater_save_group(
			array(
				'label'  => 'Ohne Namen',
				'fields' => array( array( 'type' => 'text' ) ),
			)
		);
		$this->assertWPError( $no_name );
		$this->assertSame( 'pods_repeater_missing_field_name', $no_name->get_error_code() );
		$this->assertNull( pods_repeater_get_group( 'ohne-namen' ) );
	}

	/**
	 * (17) Leere Feldliste ist erlaubt (Gruppe als Entwurf).
	 *
	 * @return void
	 */
	public function test_empty_field_list_is_allowed_as_draft() {
		$slug = pods_repeater_save_group( array( 'label' => 'Entwurf' ) );

		$this->assertSame( 'entwurf', $slug );
		$this->assertSame( array(), pods_repeater_get_group_fields( 'entwurf' ) );
		$this->assertSame(
			array(
				'label'  => 'Entwurf',
				'fields' => array(),
			),
			pods_repeater_get_group( 'entwurf' )
		);
	}

	/**
	 * (18) Nicht-Array-Argumente und unbekannte Slugs werden sauber abgewiesen.
	 *
	 * @return void
	 */
	public function test_invalid_arguments_are_rejected() {
		$this->assertWPError( pods_repeater_save_group( 'kein-array' ) );
		$this->assertNull( pods_repeater_get_group( array( 'kein', 'string' ) ) );
		$this->assertSame( array(), pods_repeater_get_group_fields( null ) );
		$this->assertFalse( pods_repeater_delete_group( array( 'x' ) ) );

		// Feld-Eintrag, der kein Array ist, hat keinen Namen → WP_Error statt
		// stillem Verwerfen (Datenverlust wäre hier nicht erkennbar).
		$bad_field = pods_repeater_save_group(
			array(
				'label'  => 'Kaputt',
				'fields' => array( 'kein-array' ),
			)
		);
		$this->assertWPError( $bad_field );
		$this->assertSame( 'pods_repeater_missing_field_name', $bad_field->get_error_code() );
		$this->assertNull( pods_repeater_get_group( 'kaputt' ) );
	}
}
