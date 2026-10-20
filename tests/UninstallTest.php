<?php
/**
 * UninstallTest — Deinstallation (uninstall.php).
 *
 * Das Deinstall-Skript läuft bei WordPress nur einmal und nur beim echten
 * Löschen des Plugins; in der Testumgebung passiert das nie. Geprüft wird
 * deshalb die Funktion `pods_repeater_uninstall()` aus uninstall.php
 * selbst, eingebunden über den Pfad aus dem Plugin-Wurzelverzeichnis.
 *
 * Vertrag:
 *   - Standard (keine Konstante)  → nichts löschen. Feldgruppen überleben
 *     eine Deinstallation, damit Neuinstallieren die Arbeit nicht vernichtet.
 *   - Konstante true               → beide Optionen weg.
 *   - Post-Meta                    → in keinem Modus angefasst. Die Werte
 *     gehören Felddefinitionen des Betreibers; ein Deinstall fremder Daten
 *     ist Datenverlust.
 *
 * @package PodsRepeater
 */

/**
 * Class UninstallTest
 */
class UninstallTest extends WP_UnitTestCase {

	/**
	 * Optionen und Post-Meta vor jedem Test zurücksetzen.
	 *
	 * WP_UnitTestCase rollt die DB zurück, lässt aber den Options- und
	 * Meta-Cache stehen; ohne explizites Löschen würden Werte zwischen den
	 * Tests lecken.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( PODS_REPEATER_GROUPS_OPTION );
		delete_option( PODS_REPEATER_GROUPS_VERSION_OPTION );
	}

	/**
	 * Legt die beiden Plugin-Optionen mit realistischen Werten an.
	 *
	 * @return void
	 */
	private function seed_options() {
		update_option(
			PODS_REPEATER_GROUPS_OPTION,
			array(
				'hero' => array(
					'label'  => 'Hero',
					'fields' => array(
						array(
							'name' => 'title',
							'type' => 'text',
						),
					),
				),
			),
			false
		);
		update_option( PODS_REPEATER_GROUPS_VERSION_OPTION, 3, false );
	}

	/**
	 * Legt einen Beitrag mit Repeater-Feldwert an.
	 *
	 * @return int Beitrags-ID.
	 */
	private function seed_post_meta() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Uninstall-Probe' ) );

		update_post_meta( $post_id, 'repeater_field', '[{"title":"Zeile 1"},{"title":"Zeile 2"}]' );

		return $post_id;
	}

	/**
	 * Bindet uninstall.php ein (einmal pro Prozess) und liefert den
	 * eingebundenen Pfad zurück.
	 *
	 * uninstall.php bricht ohne ABSPATH bzw. WP_UNINSTALL_PLUGIN ab. Im
	 * Testlauf sind beide definiert (der Bootstrap definiert WP_UNINSTALL_PLUGIN
	 * nicht — dort greift die Definition im Test unten), damit die Datei
	 * nicht vorzeitig aussteigt.
	 *
	 * @return string Eingebundener Pfad.
	 */
	private function load_uninstall_file() {
		$path = dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFileExists( $path, 'uninstall.php muss im Plugin-Wurzelverzeichnis liegen.' );

		// Der WPUnit-Bootstrap lädt das Plugin über muplugins_loaded und
		// definiert kein WP_UNINSTALL_PLUGIN. Für diesen Test wird der Guard
		// erfüllt, damit die Datei ihre Funktion definieren kann.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'pods-repeater/pods-repeater.php' );
		}

		require_once $path;

		$this->assertTrue(
			function_exists( 'pods_repeater_uninstall' ),
			'uninstall.php muss die Funktion pods_repeater_uninstall() bereitstellen.'
		);

		return $path;
	}

	/**
	 * (1) Standardmodus: Optionen bleiben stehen.
	 *
	 * Gegenprobe: ruft der Test ohne Konstante delete_option() auf, wird
	 * diese Methode rot — die Optionen wären weg.
	 *
	 * @return void
	 */
	public function test_default_mode_keeps_options() {
		$this->load_uninstall_file();

		$this->seed_options();

		// Vorbedingung: die Optionen existieren wirklich.
		$this->assertSame( 3, (int) get_option( PODS_REPEATER_GROUPS_VERSION_OPTION, 0 ) );
		$this->assertArrayHasKey( 'hero', pods_repeater_get_groups() );

		$deleted = pods_repeater_uninstall();

		$this->assertFalse( $deleted, 'Ohne ausdrücklichen Wunsch darf pods_repeater_uninstall() nichts löschen.' );
		$this->assertArrayHasKey(
			'hero',
			pods_repeater_get_groups(),
			'Standard-Deinstallation darf die Feldgruppen nicht löschen.'
		);
		$this->assertSame(
			3,
			(int) get_option( PODS_REPEATER_GROUPS_VERSION_OPTION, 0 ),
			'Standard-Deinstallation darf die Schemaversion nicht löschen.'
		);
	}

	/**
	 * (2) Konstante true: beide Optionen sind weg.
	 *
	 * Gegenprobe: ignoriert die Implementierung die Konstante, bleiben die
	 * Optionen stehen und beide assertFalse( false === get_option() )-Prüfungen
	 * werden rot.
	 *
	 * @return void
	 */
	public function test_remove_data_constant_deletes_options() {
		$this->load_uninstall_file();

		$this->seed_options();

		// Der echte Weg aus wp-config.php: die Konstante existiert, bevor
		// uninstall.php läuft. Ist sie im Testprozess schon (fälschlich)
		// definiert, lässt sie sich nicht neu belegen — dann ist der Test
		// nicht aussagekräftig und muss scheitern, nicht stillschweigend
		// grün werden.
		$this->assertFalse(
			defined( 'PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL' ),
			'PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL darf vor diesem Test nicht definiert sein (Testreihenfolge/Altlast).'
		);

		define( 'PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL', true );

		$deleted = pods_repeater_uninstall();

		$this->assertTrue( $deleted, 'Mit gesetzter Konstante muss pods_repeater_uninstall() löschen.' );
		$this->assertFalse(
			get_option( PODS_REPEATER_GROUPS_OPTION, false ),
			'Mit gesetzter Konstante muss pods_repeater_groups gelöscht sein.'
		);
		$this->assertFalse(
			get_option( PODS_REPEATER_GROUPS_VERSION_OPTION, false ),
			'Mit gesetzter Konstante muss pods_repeater_groups_version gelöscht sein.'
		);
	}

	/**
	 * (3) Post-Meta bleibt in beiden Modi unangetastet.
	 *
	 * Gegenprobe: löscht die Implementierung Meta des Repeaters (etwa über
	 * delete_post_meta() oder eine Abfrage auf meta_key LIKE
	 * 'repeater%'), ist der Wert nach dem Löschlauf weg und die Prüfung
	 * nach dem zweiten Aufruf wird rot.
	 *
	 * @return void
	 */
	public function test_post_meta_survives_both_modes() {
		$this->load_uninstall_file();

		$expected = '[{"title":"Zeile 1"},{"title":"Zeile 2"}]';
		$post_id  = $this->seed_post_meta();

		$this->assertSame( $expected, get_post_meta( $post_id, 'repeater_field', true ) );

		// Standardmodus.
		pods_repeater_uninstall();
		$this->assertSame(
			$expected,
			get_post_meta( $post_id, 'repeater_field', true ),
			'Standard-Deinstallation darf Repeater-Post-Meta nicht anfassen.'
		);

		// Löschmodus — explizit übergeben, damit der Test unabhängig von der
		// Konstante aus Methode (2) ist.
		$this->seed_options();
		$this->assertTrue( pods_repeater_uninstall( true ) );

		$this->assertSame(
			$expected,
			get_post_meta( $post_id, 'repeater_field', true ),
			'Auch im Löschmodus darf Repeater-Post-Meta nicht angefasst werden — die Felder gehören dem Betreiber.'
		);
	}

	/**
	 * (4) Expliziter Parameter false schlägt die Konstante.
	 *
	 * Ein Test, der nur prüft "Konstante gesetzt → gelöscht", würde auch
	 * grün, wenn die Implementierung delete_option() unbedingt aufriefe
	 * und der Parameter ignoriert würde. Hier ist die Konstante gesetzt,
	 * der Aufruf verlangt aber ausdrücklich "nicht löschen".
	 *
	 * @return void
	 */
	public function test_explicit_false_overrides_constant() {
		$this->load_uninstall_file();

		$this->seed_options();

		if ( ! defined( 'PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL' ) ) {
			define( 'PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL', true );
		}

		$deleted = pods_repeater_uninstall( false );

		$this->assertFalse( $deleted, 'Explizites false muss die Konstante überstimmen.' );
		$this->assertArrayHasKey(
			'hero',
			pods_repeater_get_groups(),
			'Explizites false darf die Feldgruppen nicht löschen.'
		);
	}
}
