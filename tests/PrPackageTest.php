<?php
/**
 * Prueft das gebaute PR-Paket unter dist/pods-pr/.
 *
 * Der Generator (scripts/build-pr-package.sh) erzeugt die Dateien, die der
 * Auftraggeber einreicht. Bis S14 wurde das Paket nur von Hand gesichtet —
 * ein falsch portierter Klassenname oder eine stillschweigend verkleinerte
 * Testdatei waere erst beim Maintainer aufgefallen. Dieser Test prueft die
 * Zusagen des Pakets maschinell.
 *
 * Abhaengigkeit: dist/ ist gitignored und wird vom Generator auf dem Host
 * gebaut (scripts/build-pr-package.sh braucht python3, der Testcontainer
 * hat es nicht). Fehlt das Paket, schlaegt der Test mit dieser Anweisung
 * fehl — nicht skipped: ein gruener Lauf ohne Pruefobjekt waere wertlos.
 *
 * Vor dem Testlauf auf dem Host:
 *   bash scripts/build-pr-package.sh
 *
 * @package PodsRepeater
 */

/**
 * Class PrPackageTest
 */
class PrPackageTest extends WP_UnitTestCase {

	/**
	 * Wurzel des Pakets.
	 *
	 * @var string
	 */
	private $out_dir = '';

	/**
	 * Pfad zur portierten Feldklasse.
	 *
	 * @var string
	 */
	private $port_file = '';

	/**
	 * Paket muss vor dem Testlauf gebaut sein.
	 *
	 * Der Build laeuft auf dem Host; der Testcontainer hat kein python3.
	 * Darum hier pruefen statt bauen — mit einer Meldung, die den fehlenden
	 * Schritt nennt.
	 */
	public function set_up() {
		parent::set_up();

		$repo_root       = dirname( __DIR__ );
		$this->out_dir   = $repo_root . '/dist/pods-pr';
		$this->port_file = $this->out_dir . '/classes/fields/repeater.php';

		$this->assertFileExists(
			$this->port_file,
			'PR-Paket fehlt. Vor dem Testlauf auf dem Host bauen: bash scripts/build-pr-package.sh'
		);
	}

	/**
	 * Alle Artefakte, die der Auftraggeber einreicht, existieren und sind
	 * nicht leer. Die Liste ist die Zusage aus dem Sprint-Vertrag.
	 */
	public function test_artefakte_existieren() {
		$artefakte = array(
			'classes/fields/repeater.php',
			'ui/js/repeater.js',
			'tests/wpunit/PodsRepeaterTest.php',
			'tests/wpunit/PodsRepeaterContractTest.php',
			'tests/wpunit/PodsRepeaterCoreIntegrationTest.php',
			'readme-changelog.txt',
			'PR-BESCHREIBUNG.md',
			'DISCUSSION-KOMMENTAR.md',
			'CHECKLISTE.md',
		);

		foreach ( $artefakte as $relativ ) {
			$pfad = $this->out_dir . '/' . $relativ;
			$this->assertFileExists( $pfad, "Artefakt fehlt: {$relativ}" );
			$this->assertGreaterThan( 0, filesize( $pfad ), "Artefakt ist leer: {$relativ}" );
		}
	}

	/**
	 * Die portierten Testdateien enthalten so viele Testmethoden wie ihre
	 * Quellen. Eine halbierte Portierung waere sonst unsichtbar.
	 */
	public function test_portierte_tests_vollstaendig() {
		$paare = array(
			'RepeaterLimitTest.php'   => 'PodsRepeaterTest.php',
			'RepeaterContractTest.php' => 'PodsRepeaterContractTest.php',
			'CoreIntegrationTest.php'  => 'PodsRepeaterCoreIntegrationTest.php',
		);

		$repo_root = dirname( __DIR__ );
		$gesamt    = 0;

		foreach ( $paare as $quelle => $ziel ) {
			$quelle_anzahl = substr_count(
				file_get_contents( $repo_root . '/tests/' . $quelle ),
				'public function test_'
			);
			$ziel_anzahl   = substr_count(
				file_get_contents( $this->out_dir . '/tests/wpunit/' . $ziel ),
				'public function test_'
			);

			$this->assertGreaterThan( 0, $quelle_anzahl, "Quelle ohne Tests: {$quelle}" );
			$this->assertSame(
				$quelle_anzahl,
				$ziel_anzahl,
				"{$ziel}: {$ziel_anzahl} Tests portiert, Quelle {$quelle} hat {$quelle_anzahl}"
			);

			$gesamt += $ziel_anzahl;
		}

		// Der Gesamtwert ist die Zusage in PR-Beschreibung und Discussion-
		// Kommentar; beide Textdateien muessen ihn nennen.
		$this->assertGreaterThanOrEqual( 33, $gesamt, "Nur {$gesamt} Testmethoden im Paket" );

		$this->assertStringContainsString(
			$gesamt . ' Testmethoden',
			file_get_contents( $this->out_dir . '/PR-BESCHREIBUNG.md' ),
			"PR-Beschreibung nennt die Testzahl {$gesamt} nicht"
		);
		$this->assertStringContainsString(
			$gesamt . ' Testmethoden',
			file_get_contents( $this->out_dir . '/DISCUSSION-KOMMENTAR.md' ),
			"Discussion-Kommentar nennt die Testzahl {$gesamt} nicht"
		);
	}

	/**
	 * Die portierte Datei traegt die Kern-Konvention.
	 */
	public function test_kern_konvention() {
		$src = file_get_contents( $this->port_file );

		$this->assertStringContainsString( 'class PodsField_Repeater extends PodsField', $src );
		$this->assertStringNotContainsString( 'pods-repeater', $src, 'Textdomain oder Klassen-Praefix nicht portiert' );
		$this->assertGreaterThan( 0, substr_count( $src, "'pods'" ), 'Textdomain pods fehlt' );
		$this->assertStringContainsString( '@package Pods\\Fields', $src );
	}

	/**
	 * Die CSS-Klassen, die das mitgelieferte JS selektiert, existieren im PHP
	 * und im JS unter demselben Namen. Das ist der Vertrag, der das Admin-UI
	 * bedienbar macht — bricht er, ist der Typ im Editor tot.
	 */
	public function test_css_klassen_vertrag_zwischen_php_und_js() {
		$php = file_get_contents( $this->port_file );
		$js  = file_get_contents( $this->out_dir . '/ui/js/repeater.js' );

		$klassen = array( 'pods-row', 'pods-rows', 'pods-subfield', 'pods-add', 'pods-remove' );

		foreach ( $klassen as $klasse ) {
			$this->assertStringContainsString( $klasse, $php, "PHP rendert {$klasse} nicht" );
			$this->assertStringContainsString( $klasse, $js, "JS selektiert {$klasse} nicht" );
		}

		$this->assertStringNotContainsString( 'pods-repeater-', $js, 'JS traegt noch den Plugin-Namensraum' );
	}

	/**
	 * Der Typ steht NICHT in der Repeatable-Whitelist — sonst rendert ihn der
	 * generische PodsForm-Pfad statt der eigenen input()-Methode.
	 */
	public function test_nicht_in_repeatable_whitelist() {
		$whitelist = apply_filters( 'pods_repeatable_field_types', array() );

		$this->assertNotContains(
			'repeater',
			(array) $whitelist,
			'repeater steht in pods_repeatable_field_types — der Kern rendert ihn dann ohne eigene Zeilenlogik'
		);
	}

	/**
	 * Die portierte Datei ist syntaktisch ladbar. Geprueft ueber einen
	 * eigenen PHP-Prozess mit umbenannter Klasse, damit keine Namenskollision
	 * mit der geladenen Plugin-Klasse entsteht.
	 */
	public function test_portierte_datei_ist_ladbar() {
		$src = file_get_contents( $this->port_file );

		$probe = str_replace( 'PodsField_Repeater', 'PodsField_RepeaterPortProbe', $src );

		$tmp = wp_tempnam( 'portprobe' ) . '.php';
		file_put_contents( $tmp, $probe );

		$out = shell_exec( 'php -l ' . escapeshellarg( $tmp ) . ' 2>&1' );
		@unlink( $tmp );

		$this->assertStringContainsString( 'No syntax errors detected', (string) $out, "php -l meldet Probleme: {$out}" );
	}
}
