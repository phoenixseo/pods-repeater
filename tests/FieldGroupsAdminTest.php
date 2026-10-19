<?php
/**
 * FieldGroupsAdminTest — Admin-Verwaltung der Feldgruppen (S7).
 *
 * Prüft classes/admin/field-groups-page.php ohne HTTP-Pipeline: die
 * Render- und Handler-Funktionen sind so gebaut, dass sie Eingaben als
 * Parameter nehmen und Strings bzw. WP_Error zurückgeben.
 *
 * Vertrag:
 *   - handle_save(): Capability + Nonce werden im Handler geprüft;
 *     ungültige Requests ändern die Registry nicht.
 *   - handle_delete(): dasselbe, unbekannter Slug → WP_Error.
 *   - render_list(): Slug, Label und Feldanzahl; jede Ausgabe escaped.
 *   - render_form(): Slug-, Label- und Nonce-Feld sowie ein Dropdown mit
 *     allen supported_types pro Feldzeile.
 *
 * @package PodsRepeater
 */

/**
 * Class FieldGroupsAdminTest
 */
class FieldGroupsAdminTest extends WP_UnitTestCase {

	/**
	 * Admin-Kontext herstellen und Admin-Datei laden.
	 *
	 * Die Test-Suite lädt das Plugin aus dem muplugins_loaded-Kontext, in
	 * dem is_admin() noch nicht existiert; die Admin-Seite wird dort also
	 * nicht automatisch eingebunden. Für diese Testklasse wird ein
	 * Admin-Screen gesetzt (is_admin() === true) und die Datei einmalig
	 * geladen — genau das, was der Bootstrap in einer echten
	 * wp-admin-Anfrage tut.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		set_current_screen( 'dashboard' );

		if ( ! function_exists( 'pods_repeater_admin_handle_save' ) ) {
			require_once PODS_REPEATER_DIR . 'classes/admin/field-groups-page.php';
		}

		delete_option( PODS_REPEATER_GROUPS_OPTION );
		delete_option( PODS_REPEATER_GROUPS_VERSION_OPTION );

		unset( $_GET['pods_repeater_notice'], $_GET['slug'], $_GET['view'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Admin-Kontext nach jedem Test zurücknehmen.
	 *
	 * @return void
	 */
	public function tear_down() {
		set_current_screen( 'front' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Legt einen Administrator an, der die Capability besitzt.
	 *
	 * @return int Benutzer-ID.
	 */
	private function login_admin() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Baut Formulardaten für handle_save() mit gültigem Nonce.
	 *
	 * @param array $overrides Überschreibungen einzelner Felder.
	 *
	 * @return array
	 */
	private function save_request( $overrides = array() ) {
		$base = array(
			PODS_REPEATER_GROUP_NONCE_FIELD => wp_create_nonce( PODS_REPEATER_GROUP_NONCE_ACTION ),
			'label'                         => 'Hero',
			'slug'                          => '',
			'pods_repeater_group_fields'    => array(
				array(
					'name'     => 'title',
					'label'    => 'Titel',
					'type'     => 'text',
					'required' => '1',
				),
			),
		);

		return array_merge( $base, $overrides );
	}

	/**
	 * (1) Gültiger Nonce + Administrator → Gruppe wird gespeichert.
	 *
	 * @return void
	 */
	public function test_handle_save_stores_group_with_valid_nonce_and_capability() {
		$this->login_admin();

		$result = pods_repeater_admin_handle_save( $this->save_request() );

		$this->assertNotWPError( $result );
		$this->assertSame( 'hero', $result );

		$group = pods_repeater_get_group( 'hero' );
		$this->assertIsArray( $group );
		$this->assertSame( 'Hero', $group['label'] );
		$this->assertSame( array( 'title' ), array_column( $group['fields'], 'name' ) );
		$this->assertTrue( $group['fields'][0]['required'] );

		// Expliziter Slug + Mehrfach-Felder.
		$second = pods_repeater_admin_handle_save(
			$this->save_request(
				array(
					'slug'                       => 'hero-zwei',
					'label'                      => 'Hero Zwei',
					'pods_repeater_group_fields' => array(
						array(
							'name'  => 'headline',
							'label' => 'Headline',
							'type'  => 'textarea',
						),
						array(
							'name'  => 'cta',
							'label' => 'CTA',
							'type'  => 'website',
						),
					),
				)
			)
		);

		$this->assertSame( 'hero-zwei', $second );
		$this->assertSame(
			array( 'headline', 'cta' ),
			array_column( pods_repeater_get_group_fields( 'hero-zwei' ), 'name' )
		);
		$this->assertFalse( pods_repeater_get_group_fields( 'hero-zwei' )[1]['required'] );
	}

	/**
	 * (2) Fehlender und falscher Nonce → WP_Error, Registry unverändert.
	 *
	 * @return void
	 */
	public function test_handle_save_rejects_invalid_nonce_without_change() {
		$this->login_admin();

		pods_repeater_save_group( array( 'label' => 'Bestehend' ) );
		$before = pods_repeater_get_groups();

		$missing = $this->save_request( array( 'label' => 'Ohne Nonce' ) );
		unset( $missing[ PODS_REPEATER_GROUP_NONCE_FIELD ] );

		$missing_result = pods_repeater_admin_handle_save( $missing );

		$this->assertWPError( $missing_result );
		$this->assertSame( 'pods_repeater_invalid_nonce', $missing_result->get_error_code() );
		$this->assertSame( $before, pods_repeater_get_groups(), 'Fehlender Nonce darf nichts speichern.' );

		$wrong = $this->save_request(
			array(
				'label'                         => 'Falscher Nonce',
				PODS_REPEATER_GROUP_NONCE_FIELD => 'deadbeef00',
			)
		);
		$wrong_result = pods_repeater_admin_handle_save( $wrong );

		$this->assertWPError( $wrong_result );
		$this->assertSame( 'pods_repeater_invalid_nonce', $wrong_result->get_error_code() );
		$this->assertSame( $before, pods_repeater_get_groups(), 'Falscher Nonce darf nichts speichern.' );
		$this->assertNull( pods_repeater_get_group( 'falscher-nonce' ) );

		// Löschen: derselbe Vertrag.
		$delete = pods_repeater_admin_handle_delete(
			array(
				'slug'     => 'bestehend',
				'_wpnonce' => 'deadbeef00',
			)
		);
		$this->assertWPError( $delete );
		$this->assertSame( 'pods_repeater_invalid_nonce', $delete->get_error_code() );
		$this->assertSame( $before, pods_repeater_get_groups() );
	}

	/**
	 * (3) Fehlende Capability → WP_Error, Registry unverändert.
	 *
	 * @return void
	 */
	public function test_handle_save_requires_capability() {
		pods_repeater_save_group( array( 'label' => 'Bestehend' ) );
		$before = pods_repeater_get_groups();

		// Abgemeldet: kein Zugriff, obwohl Nonce gültig ist.
		$anon = pods_repeater_admin_handle_save( $this->save_request( array( 'label' => 'Anonym' ) ) );

		$this->assertWPError( $anon );
		$this->assertSame( 'pods_repeater_forbidden', $anon->get_error_code() );
		$this->assertSame( $before, pods_repeater_get_groups() );

		// Angemeldeter Abonnent: gültige Nonce, aber keine Verwaltungsrechte.
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$low = pods_repeater_admin_handle_save( $this->save_request( array( 'label' => 'Subscriber' ) ) );

		$this->assertWPError( $low );
		$this->assertSame( 'pods_repeater_forbidden', $low->get_error_code() );
		$this->assertSame( $before, pods_repeater_get_groups() );

		$delete = pods_repeater_admin_handle_delete(
			array(
				'slug'     => 'bestehend',
				'_wpnonce' => wp_create_nonce( PODS_REPEATER_GROUP_NONCE_ACTION ),
			)
		);
		$this->assertWPError( $delete );
		$this->assertSame( 'pods_repeater_forbidden', $delete->get_error_code() );
		$this->assertSame( $before, pods_repeater_get_groups() );
	}

	/**
	 * (4) render_list(): Slug, Label und Feldanzahl; HTML im Label escaped.
	 *
	 * @return void
	 */
	public function test_render_list_shows_groups_and_escapes_output() {
		$this->login_admin();

		pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array(
					array( 'name' => 'title', 'label' => 'Titel', 'type' => 'text' ),
					array( 'name' => 'cta', 'label' => 'CTA', 'type' => 'website' ),
				),
			)
		);

		// Label mit HTML direkt in die Option schreiben: save_group()
		// säubert bereits, der Renderer muss aber auch Rohwerte escapen.
		$groups          = pods_repeater_get_groups();
		$groups['boese'] = array(
			'label'  => '<script>alert("xss")</script><em>Böse</em>',
			'fields' => array(),
		);
		update_option( PODS_REPEATER_GROUPS_OPTION, $groups );

		$html = pods_repeater_admin_render_list();

		$this->assertStringContainsString( 'hero', $html );
		$this->assertStringContainsString( 'Hero', $html );
		$this->assertStringContainsString( 'boese', $html );

		// Escaping: kein roher Tag-Durchschlag aus dem Label.
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<em>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '&lt;em&gt;Böse&lt;/em&gt;', $html );

		// Feldanzahl pro Gruppe: 2 für hero, 0 für boese.
		$this->assertMatchesRegularExpression( '/hero.*?<td>2<\/td>/s', $html );
		$this->assertMatchesRegularExpression( '/boese.*?<td>0<\/td>/s', $html );

		// Bearbeiten-/Löschen-Links der Gruppen sind vorhanden.
		$this->assertStringContainsString( 'pods-repeater-groups', $html );
		$this->assertStringContainsString( 'action=pods_repeater_group_delete', $html );
		$this->assertStringContainsString( 'slug=hero', $html );
	}

	/**
	 * (5) render_form(): Slug-, Label- und Nonce-Feld, Typ-Dropdown mit
	 * allen supported types.
	 *
	 * @return void
	 */
	public function test_render_form_contains_fields_types_and_nonce() {
		$this->login_admin();

		pods_repeater_save_group(
			array(
				'label'  => 'Hero',
				'fields' => array(
					array(
						'name'     => 'title',
						'label'    => 'Titel',
						'type'     => 'textarea',
						'required' => true,
					),
				),
			)
		);

		$html = pods_repeater_admin_render_form( 'hero' );

		// Slug- und Label-Feld mit vorbelegten Werten.
		$this->assertStringContainsString( 'name="slug"', $html );
		$this->assertStringContainsString( 'id="pods-repeater-group-slug"', $html );
		$this->assertStringContainsString( 'value="hero"', $html );
		$this->assertStringContainsString( 'name="label"', $html );
		$this->assertStringContainsString( 'id="pods-repeater-group-label"', $html );
		$this->assertStringContainsString( 'value="Hero"', $html );

		// Nonce-Feld (wp_nonce_field).
		$this->assertStringContainsString( 'name="' . PODS_REPEATER_GROUP_NONCE_FIELD . '"', $html );
		$this->assertMatchesRegularExpression(
			'/<input type="hidden" id="pods_repeater_group_nonce" name="pods_repeater_group_nonce" value="[a-f0-9]+"/',
			$html
		);

		// Typ-Dropdown enthält jeden unterstützten Typ als <option>.
		foreach ( pods_repeater_supported_sub_types() as $type ) {
			$this->assertStringContainsString(
				'<option value="' . esc_attr( $type ) . '"',
				$html,
				sprintf( 'Dropdown muss den Typ "%s" anbieten.', $type )
			);
		}

		// Der gespeicherte Typ ist vorausgewählt.
		$this->assertMatchesRegularExpression( '/<option value="textarea" selected=\'selected\'>/', $html );

		// Feldzeile: Name, Label, Pflicht-Checkbox, Entfernen-Button,
		// tabellarische Feldnamen.
		$this->assertStringContainsString( 'pods_repeater_group_fields[0][name]', $html );
		$this->assertStringContainsString( 'value="title"', $html );
		$this->assertStringContainsString( 'value="Titel"', $html );
		$this->assertStringContainsString( 'pods_repeater_group_fields[0][required]', $html );
		$this->assertStringContainsString( 'checked=\'checked\'', $html );
		$this->assertStringContainsString( 'pods-repeater-group-remove-field', $html );
		$this->assertStringContainsString( 'pods-repeater-group-add-field', $html );
		$this->assertStringContainsString( 'type="submit"', $html );

		// Neues Formular: leere Felder, eine Startzeile mit Dropdown.
		$empty = pods_repeater_admin_render_form();

		$this->assertStringContainsString( 'name="slug"', $empty );
		$this->assertStringContainsString( 'value=""', $empty );
		$this->assertStringContainsString( 'pods_repeater_group_fields[0][type]', $empty );
		$this->assertStringContainsString( 'name="' . PODS_REPEATER_GROUP_NONCE_FIELD . '"', $empty );
	}

	/**
	 * (6) handle_delete() entfernt die Gruppe; unbekannter Slug → WP_Error.
	 *
	 * @return void
	 */
	public function test_handle_delete_removes_group_and_rejects_unknown_slug() {
		$this->login_admin();

		pods_repeater_save_group( array( 'label' => 'Hero' ) );
		pods_repeater_save_group( array( 'label' => 'Footer' ) );

		$result = pods_repeater_admin_handle_delete(
			array(
				'slug'     => 'hero',
				'_wpnonce' => wp_create_nonce( PODS_REPEATER_GROUP_NONCE_ACTION ),
			)
		);

		$this->assertTrue( $result );
		$this->assertNull( pods_repeater_get_group( 'hero' ) );
		$this->assertIsArray( pods_repeater_get_group( 'footer' ), 'Andere Gruppen müssen bleiben.' );
		$this->assertCount( 1, pods_repeater_get_groups() );

		$unknown = pods_repeater_admin_handle_delete(
			array(
				'slug'     => 'gibt-es-nicht',
				'_wpnonce' => wp_create_nonce( PODS_REPEATER_GROUP_NONCE_ACTION ),
			)
		);

		$this->assertWPError( $unknown );
		$this->assertSame( 'pods_repeater_unknown_group', $unknown->get_error_code() );
		$this->assertCount( 1, pods_repeater_get_groups(), 'Unbekannter Slug darf nichts löschen.' );
	}
}
