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
			'tests/wpunit/PodsRepeaterNestedTest.php',
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
			'RepeaterNestedTest.php'   => 'PodsRepeaterNestedTest.php',
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
		$this->assertGreaterThanOrEqual( 40, $gesamt, "Nur {$gesamt} Testmethoden im Paket (erwartet 10+19+4+7)" );

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
	 * Die Zusage "verschachtelte Repeater werden serverseitig abgelehnt" steht
	 * im Paket — mit dem Test daneben, der sie belegt. Ohne diesen Test waere
	 * die Aussage in den Dokumenten eine Behauptung.
	 */
	public function test_nested_zusage_ist_belegt() {
		$nested = $this->out_dir . '/tests/wpunit/PodsRepeaterNestedTest.php';
		$this->assertFileExists( $nested, 'PodsRepeaterNestedTest.php fehlt im Paket' );

		$src   = file_get_contents( $nested );
		$desc  = file_get_contents( $this->out_dir . '/PR-BESCHREIBUNG.md' );
		$check = file_get_contents( $this->out_dir . '/CHECKLISTE.md' );

		$this->assertStringContainsString( 'Nested repeaters are not supported', $src );
		$this->assertStringContainsString( 'class PodsRepeaterNestedTest', $src );
		$this->assertStringContainsString( '@group podscore', $src, 'Nested-Test braucht die Gruppenmarke (Feldklasse existiert im Kern erst mit dem PR)' );
		$this->assertStringNotContainsString( 'pods_repeater_', $src, 'Plugin-Praefix steht noch im portierten Test' );

		// Gegenprobe muss mitportiert sein: legitime Felder bleiben gueltig.
		$this->assertStringContainsString( 'test_validate_passes_without_nested_repeater', $src );
		$this->assertStringContainsString( 'test_pre_save_keeps_legit_sub_field_values', $src );

		$this->assertStringContainsString( 'PodsRepeaterNestedTest.php', $desc, 'PR-Beschreibung nennt den Nested-Test nicht' );
		$this->assertStringContainsString( 'PodsRepeaterNestedTest.php', $check, 'Checkliste nennt den Nested-Test nicht' );
		// Absolute Pfade des Quellrepos haben im Paket nichts zu suchen: der
		// Test laeuft beim Maintainer in einem anderen Verzeichnisbaum.
		$this->assertDoesNotMatchRegularExpression(
			'#(?:/home/|/Users/|\\\\Users\\\\|/root/)[A-Za-z0-9._-]+/#',
			$src . $desc . $check,
			'Absoluter Home-Pfad aus dem Quellrepos steht im Paket'
		);
		$this->assertStringNotContainsString(
			'validate()` (ab Z. 288) filtert den Sub-Typ **nicht**',
			$check,
			'Checkliste behauptet noch, validate() filtere nicht — der Fix aus S21 ist im Paket'
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

	/**
	 * readme.txt und Plugin-Header nennen fuer "Requires at least" und
	 * "Requires PHP" denselben Wert.
	 *
	 * Beide Angaben gehen an unterschiedliche Stellen (wp.org liest das
	 * Readme, WordPress selbst den Header). Laufen sie auseinander, luegt
	 * eine von beiden — genau das war der Stand vor S22.
	 */
	public function test_mindestversionen_stimmen_zwischen_readme_und_header() {
		$repo  = dirname( __DIR__ );
		$readme = file_get_contents( $repo . '/readme.txt' );
		$header = file_get_contents( $repo . '/pods-repeater.php' );

		foreach ( array( 'Requires at least', 'Requires PHP' ) as $feld ) {
			$pattern = '/^' . preg_quote( $feld, '/' ) . ':[ 	]*([0-9.]+)/m';

			$this->assertSame(
				1,
				preg_match( $pattern, $readme, $readme_treffer ),
				"readme.txt nennt {$feld} nicht genau einmal"
			);
			$this->assertSame(
				1,
				preg_match( '/\*[ 	]*' . preg_quote( $feld, '/' ) . ':[ 	]*([0-9.]+)/', $header, $header_treffer ),
				"Plugin-Header nennt {$feld} nicht genau einmal"
			);

			$this->assertSame(
				$readme_treffer[1],
				$header_treffer[1],
				"{$feld} weicht ab: readme.txt sagt {$readme_treffer[1]}, der Plugin-Header sagt {$header_treffer[1]}"
			);
		}
	}

	/**
	 * Text des Abschnitts "== Known Limitations ==" aus dem Wurzel-readme.txt.
	 *
	 * Gelesen wird das Wurzel-readme, nicht dist/pods-pr/: der
	 * Paketgenerator (scripts/build-pr-package.sh) kopiert readme.txt nicht,
	 * er erzeugt nur readme-changelog.txt.
	 *
	 * @return string Abschnittstext ohne die folgende Abschnittsueberschrift.
	 */
	private function known_limitations_abschnitt() {
		$readme  = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
		$treffer = array();

		if ( 1 !== preg_match( '/^== Known Limitations ==$(.*?)^== /ms', $readme, $treffer ) ) {
			return '';
		}

		return $treffer[1];
	}

	/**
	 * readme.txt traegt den Abschnitt "== Known Limitations ==" genau einmal,
	 * zwischen FAQ und Uninstall.
	 *
	 * Die Grenzen des Feldtyps sind bewusste Design-Entscheidungen. Ohne den
	 * Abschnitt liest ein Fremder jede davon als Bug; dieser Test haelt ihn
	 * vor dem naechsten Umbau fest.
	 */
	public function test_known_limitations_abschnitt_steht_an_der_richtigen_stelle() {
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );

		$this->assertSame(
			1,
			substr_count( $readme, '== Known Limitations ==' ),
			'readme.txt muss den Abschnitt "== Known Limitations ==" genau einmal nennen'
		);

		$faq     = strpos( $readme, "\n== Frequently Asked Questions ==\n" );
		$grenzen = strpos( $readme, "\n== Known Limitations ==\n" );
		$deinst  = strpos( $readme, "\n== Uninstall ==\n" );

		$this->assertNotFalse( $faq, 'Abschnitt "== Frequently Asked Questions ==" fehlt' );
		$this->assertNotFalse( $grenzen, 'Abschnitt "== Known Limitations ==" fehlt' );
		$this->assertNotFalse( $deinst, 'Abschnitt "== Uninstall ==" fehlt' );

		$this->assertGreaterThan( $faq, $grenzen, 'Known Limitations muss nach den FAQ stehen' );
		$this->assertGreaterThan( $grenzen, $deinst, 'Known Limitations muss vor Uninstall stehen' );
	}

	/**
	 * Der Abschnitt benennt die belegten Grenzen.
	 *
	 * `file`/`avatar`/`oembed` (Punkt 1), `nested` (Punkt 2), die
	 * Admin-only-Registry (Punkt 3), `ACF` (Punkt 4) und den fehlenden
	 * Block-/REST-Editor (Punkt 5).
	 */
	public function test_known_limitations_benennt_die_grenzen() {
		$abschnitt = $this->known_limitations_abschnitt();

		$this->assertNotSame( '', $abschnitt, 'Abschnitt "== Known Limitations ==" fehlt oder ist leer' );

		$stichworte = array(
			'file'                 => 'Punkt 1: file als nicht unterstuetztes Sub-Feld',
			'avatar'               => 'Punkt 1: avatar als nicht unterstuetztes Sub-Feld',
			'oembed'               => 'Punkt 1: oembed als nicht unterstuetztes Sub-Feld',
			'nested'               => 'Punkt 2: verschachtelte Repeater',
			'ACF'                  => 'Punkt 4: keine ACF-Migration',
			'pods_repeater_save_group' => 'Punkt 3: Registry nur ueber die Admin-Seite',
		);

		foreach ( $stichworte as $stichwort => $zweck ) {
			$this->assertStringContainsString( $stichwort, $abschnitt, "Known Limitations nennt \"{$stichwort}\" nicht ({$zweck})" );
		}

		$this->assertStringContainsString( 'block', strtolower( $abschnitt ), 'Punkt 5 nennt den Block-Editor nicht' );
	}

	/**
	 * Die FAQ beantwortet "Can I use a file/image field inside a repeater?"
	 * und verweist auf den Abschnitt.
	 */
	public function test_faq_verweist_auf_known_limitations() {
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );

		$faq_start = strpos( $readme, "\n== Frequently Asked Questions ==\n" );
		$faq_ende  = strpos( $readme, "\n== Known Limitations ==\n" );

		$this->assertNotFalse( $faq_start, 'Abschnitt "== Frequently Asked Questions ==" fehlt' );
		$this->assertNotFalse( $faq_ende, 'Abschnitt "== Known Limitations ==" fehlt' );

		$faq = substr( $readme, $faq_start, $faq_ende - $faq_start );

		$this->assertStringContainsString( 'Can I use a file/image field inside a repeater?', $faq, 'FAQ-Frage zu Datei-Feldern fehlt' );
		$this->assertStringContainsString( 'Known Limitations', $faq, 'FAQ verweist nicht auf den Abschnitt Known Limitations' );
	}

	/**
	 * Der Abschnitt behauptet keine Funktion, die der Code nicht hat.
	 *
	 * Gegenprobe gegen den Code: `file`, `avatar`, `oembed` und `repeater`
	 * duerfen nicht in der Whitelist stehen — stuende einer drin, waere der
	 * entsprechende Known-Limitations-Punkt unwahr und muesste raus. Punkt 2
	 * haengt an der serverseitigen Ablehnung in der Feldklasse.
	 */
	public function test_known_limitations_deckt_sich_mit_der_whitelist() {
		$erlaubt = pods_repeater_supported_sub_types();

		foreach ( array( 'file', 'avatar', 'oembed', 'repeater' ) as $typ ) {
			$this->assertNotContains(
				$typ,
				$erlaubt,
				"Whitelist fuehrt \"{$typ}\" — dann waere der Known-Limitations-Punkt unwahr und muesste raus"
			);
		}

		$methode = new ReflectionMethod( 'PodsField_Repeater', 'get_nested_repeater_error' );
		$this->assertTrue( $methode->isProtected(), 'get_nested_repeater_error() fehlt — Punkt 2 (kein Nesting) waere unbelegt' );
	}

	/* ---------------------------------------------------------------------
	 * Installierbares Alpha-Paket (dist/release/)
	 *
	 * Das Zip ist das Artefakt, das einen Fremden erreicht, waehrend das
	 * Repo niemand sieht. Ein Fehler darin — flaches Entpacken, ein
	 * vergessener Version-Bump, ein vendor/ mit 10 MB — faellt sonst erst
	 * beim Tester auf.
	 *
	 * Geprueft wird gegen das Manifest, das scripts/build-release-package.sh
	 * aus dem gebauten Zip erzeugt. Kein handgepflegtes Manifest: waere es
	 * handgepflegt, wuerde der Test die Absicht pruefen, nicht das Produkt.
	 * Fehlt das Manifest, ist der Test rot (nicht skippen) — ein gruener
	 * Lauf ohne Pruefobjekt waere wertlos.
	 * ------------------------------------------------------------------ */

	/**
	 * Pfad zum gebauten Zip (Versionsnummer aus dem Dateinamen gesucht).
	 *
	 * @return string Leerer String, wenn kein Zip da ist.
	 */
	private function release_zip() {
		$treffer = glob( dirname( __DIR__ ) . '/dist/release/pods-repeater-*.zip' );

		return empty( $treffer ) ? '' : $treffer[0];
	}

	/**
	 * Zeilen des vom Build erzeugten Manifests.
	 *
	 * @return array<int, string> Dateipfade im Zip, mit Praefix pods-repeater/.
	 */
	private function release_manifest() {
		$zip = $this->release_zip();

		$this->assertNotSame(
			'',
			$zip,
			'Release-Zip fehlt. Vor dem Testlauf auf dem Host bauen: bash scripts/build-release-package.sh'
		);

		$manifest = preg_replace( '/\.zip$/', '.manifest.txt', $zip );

		$this->assertFileExists(
			$manifest,
			'Manifest fehlt. Es wird vom Build erzeugt (scripts/build-release-package.sh) — nicht von Hand anlegen'
		);

		$zeilen = file( $manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		$this->assertNotEmpty( $zeilen, 'Manifest ist leer' );

		return $zeilen;
	}

	/**
	 * Das Paket existiert, hat eine plausible Groesse und traegt den
	 * Plugin-Ordner als Wurzel.
	 *
	 * Der Ordnername im Zip entscheidet, ob WordPress das Plugin erkennt:
	 * liegt pods-repeater.php auf oberster Ebene, entpackt das Zip flach
	 * neben die anderen Plugins, und WordPress findet keinen Header.
	 */
	public function test_release_zip_existiert_und_hat_plugin_ordner_als_wurzel() {
		$zip  = $this->release_zip();
		$size = filesize( $zip );

		$this->assertGreaterThan( 20 * 1024, $size, "Release-Zip ist nur {$size} Bytes — das ist kein vollstaendiges Paket" );

		$manifest = $this->release_manifest();

		$this->assertSame(
			'pods-repeater/pods-repeater.php',
			$manifest[0],
			'Erster Eintrag im Zip ist nicht der Plugin-Einstieg — das Zip entpackt sich flach'
		);

		foreach ( $manifest as $pfad ) {
			$this->assertStringStartsWith(
				'pods-repeater/',
				$pfad,
				"Eintrag \"{$pfad}\" liegt ausserhalb von pods-repeater/ und faellt beim Entpacken neben das Plugin"
			);
		}
	}

	/**
	 * Alles, was WordPress zum Laufen braucht, ist im Zip.
	 *
	 * readme.txt und uninstall.php sind Pflicht, nicht Kuer: das readme ist
	 * die einzige Dokumentation, die ein Tester im Plugin-Screen sieht, und
	 * uninstall.php ist der Opt-in-Cleanup, den die Uninstall-Sektion
	 * verspricht. Fehlt eine davon, ist das readme eine Luege.
	 */
	public function test_release_zip_enthaelt_die_laufzeitdateien() {
		$manifest = $this->release_manifest();

		$pflicht = array(
			'pods-repeater/pods-repeater.php',
			'pods-repeater/uninstall.php',
			'pods-repeater/readme.txt',
			'pods-repeater/classes/fields/repeater.php',
			'pods-repeater/classes/admin/field-groups-page.php',
			'pods-repeater/includes/field-groups.php',
			'pods-repeater/includes/template-functions.php',
			'pods-repeater/includes/template-functions-aliases.php',
			'pods-repeater/ui/js/repeater.js',
			'pods-repeater/ui/js/field-groups.js',
			'pods-repeater/languages/pods-repeater.pot',
		);

		foreach ( $pflicht as $pfad ) {
			$this->assertContains( $pfad, $manifest, "Release-Zip enthaelt \"{$pfad}\" nicht" );
		}
	}

	/**
	 * Der Entwicklungsstand bleibt draussen.
	 *
	 * vendor/ waere toter Ballast (der Autoloader ist im Plugin nicht in
	 * Benutzung), und tests/, scripts/, dist/ und .git/ sind Arbeitsstand —
	 * ein Paket, das Fremde sehen, darf den nicht mitliefern.
	 */
	public function test_release_zip_enthaelt_keinen_entwicklungsstand() {
		$manifest = $this->release_manifest();

		$verboten = array( 'vendor/', 'tests/', 'dist/', '/.git/', 'node_modules/', 'scripts/', 'assets/' );

		foreach ( $verboten as $muster ) {
			foreach ( $manifest as $pfad ) {
				$this->assertStringNotContainsString(
					$muster,
					$pfad,
					"Release-Zip enthaelt \"{$pfad}\" (verboten: {$muster})"
				);
			}
		}

		foreach ( array( 'composer.json', 'composer.lock', 'phpunit.xml.dist' ) as $datei ) {
			$this->assertNotContains(
				'pods-repeater/' . $datei,
				$manifest,
				"Release-Zip enthaelt die Entwicklungsdatei {$datei}"
			);
		}
	}

	/**
	 * Dateiname, Header im Zip, Stable tag im Zip und Wurzel-readme tragen
	 * dieselbe Version.
	 *
	 * Der Bump muss vier Stellen treffen (Header, Konstante, Stable tag,
	 * Changelog). Eine vergessene Stelle ist der klassische Fehler: WordPress
	 * zeigt dann eine andere Version an, als im Dateinamen steht, und das
	 * Update-Erkennung des Testergebnisse laeuft ins Leere. Gelesen wird aus
	 * dem Zip, nicht aus der Arbeitskopie — sonst pruefte der Test nur den
	 * Arbeitsstand und nicht das Artefakt.
	 */
	public function test_release_version_stimmt_mit_header_und_readme_ueberein() {
		$zip = $this->release_zip();

		$this->assertSame(
			1,
			preg_match( '/pods-repeater-(.+)\.zip$/', $zip, $datei_treffer ),
			'Versionsnummer im Zip-Dateinamen nicht lesbar'
		);
		$version = $datei_treffer[1];

		$php = $this->zip_eintrag( $zip, 'pods-repeater/pods-repeater.php' );
		$rdm = $this->zip_eintrag( $zip, 'pods-repeater/readme.txt' );

		$this->assertNotSame( '', $php, 'pods-repeater.php ist im Zip nicht lesbar' );
		$this->assertNotSame( '', $rdm, 'readme.txt ist im Zip nicht lesbar' );

		$this->assertSame(
			1,
			preg_match( '/^[ 	]*\*[ 	]*Version:[ 	]*(\S+)/m', $php, $header_treffer ),
			'Plugin-Header im Zip nennt keine Version'
		);
		$this->assertSame( $version, $header_treffer[1], "Header im Zip ({$header_treffer[1]}) weicht vom Dateinamen ({$version}) ab" );

		$this->assertSame(
			1,
			preg_match( "/^define\( 'PODS_REPEATER_VERSION', '([^']+)' \);$/m", $php, $konst_treffer ),
			'PODS_REPEATER_VERSION im Zip nicht gefunden'
		);
		$this->assertSame( $version, $konst_treffer[1], "PODS_REPEATER_VERSION im Zip ({$konst_treffer[1]}) weicht vom Dateinamen ab" );

		$this->assertSame(
			1,
			preg_match( '/^Stable tag:[ 	]*(\S+)/m', $rdm, $stable_treffer ),
			'readme.txt im Zip nennt keinen Stable tag'
		);
		$this->assertSame( $version, $stable_treffer[1], "Stable tag im Zip ({$stable_treffer[1]}) weicht vom Dateinamen ab" );

		// Und die Arbeitskopie, aus der das Zip gebaut wurde, muss denselben
		// Wert tragen — sonst baut der naechste Lauf ein anderes Paket.
		$repo = dirname( __DIR__ );
		$this->assertStringContainsString(
			"Stable tag: {$version}",
			(string) file_get_contents( $repo . '/readme.txt' ),
			"readme.txt im Repo traegt nicht den Stable tag {$version}"
		);
		$this->assertStringContainsString(
			"* Version:     {$version}",
			(string) file_get_contents( $repo . '/pods-repeater.php' ),
			"Plugin-Header im Repo traegt nicht die Version {$version}"
		);
		$this->assertStringContainsString(
			"= {$version} =",
			(string) file_get_contents( $repo . '/readme.txt' ),
			"readme.txt hat keinen Changelog-Eintrag '= {$version} ='"
		);
	}

	/**
	 * Die Versionsangabe steht nirgends mehr auf dem alten Wert.
	 *
	 * Gegenstueck zum Akzeptanzkriterium der Karte: 0.2.1 darf in keiner
	 * Datei mehr stehen, die WordPress liest. Einzige Ausnahme ist die
	 * Versionshistorie — dort gehoert der alte Wert hin, sonst waere der
	 * Changelog falsch.
	 */
	public function test_keine_alte_versionsnummer_im_laufzeitcode() {
		$repo = dirname( __DIR__ );

		foreach ( array( 'pods-repeater.php', 'readme.txt', 'uninstall.php' ) as $datei ) {
			$inhalt = (string) file_get_contents( $repo . '/' . $datei );

			// Header-Block: nur der Header, nicht der Changelog.
			if ( 'readme.txt' === $datei ) {
				list( $kopf ) = explode( '== Changelog ==', $inhalt, 2 );
			} else {
				$kopf = $inhalt;
			}

			$this->assertStringNotContainsString(
				'0.2.1',
				$kopf,
				"{$datei} nennt ausserhalb des Changelogs noch 0.2.1"
			);
		}

		$treffer = array();
		exec(
			'grep -rn ' . escapeshellarg( '0.2.1' ) . ' '
			. escapeshellarg( $repo . '/includes' ) . ' '
			. escapeshellarg( $repo . '/classes' ) . ' 2>&1',
			$treffer
		);
		$this->assertSame( array(), $treffer, 'Klassen/Includes nennen noch 0.2.1: ' . implode( ' | ', $treffer ) );
	}

	/**
	 * Die Installationsanleitung liegt neben dem Zip und nennt die Schritte,
	 * die ein Tester braucht.
	 *
	 * Ein Zip ohne Anleitung beantwortet die Frage "kann ich das jemandem
	 * geben" nur halb: ohne die Voraussetzungen (Pods aktiv) scheitert der
	 * erste Versuch, und ohne die Known Limitations liest der Tester die
	 * bewussten Grenzen als Bugs.
	 */
	public function test_installationsanleitung_liegt_bei() {
		$anleitung = dirname( __DIR__ ) . '/dist/release/INSTALL.md';

		$this->assertFileExists( $anleitung, 'dist/release/INSTALL.md fehlt' );

		$text = (string) file_get_contents( $anleitung );

		$stichworte = array(
			'Plugins'             => 'Weg ueber den Plugin-Screen',
			'Hochladen'           => 'Upload-Schritt',
			'Pods'                => 'Voraussetzung Pods',
			'Known Limitations'   => 'Verweis auf die bekannten Grenzen',
			'pods_field'          => 'Leseschritt im Template',
			'Repeater'            => 'Feldtyp',
		);

		foreach ( $stichworte as $wort => $zweck ) {
			$this->assertStringContainsString( $wort, $text, "INSTALL.md nennt \"{$wort}\" nicht ({$zweck})" );
		}

		$zip = $this->release_zip();
		$name = basename( $zip );
		$this->assertStringContainsString( $name, $text, "INSTALL.md verweist nicht auf das gebaute Paket {$name}" );
	}

	/**
	 * Inhalt eines Eintrags aus dem Zip lesen.
	 *
	 * `unzip -p` im Testcontainer (BusyBox-Variante, kein PHP-ZipArchive im
	 * Testlauf garantiert). Schlaegt der Aufruf fehl, kommt ein leerer String
	 * zurueck und die Assertion nennt den Pfad.
	 *
	 * @param string $zip    Pfad zum Zip.
	 * @param string $eintrag Eintragsname im Zip.
	 *
	 * @return string Inhalt oder leerer String.
	 */
	private function zip_eintrag( $zip, $eintrag ) {
		$out = shell_exec(
			'unzip -p ' . escapeshellarg( $zip ) . ' ' . escapeshellarg( $eintrag ) . ' 2>/dev/null'
		);

		return (string) $out;
	}
}
