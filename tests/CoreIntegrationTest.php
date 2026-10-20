<?php
/**
 * Integrationsbeleg: genügt `PodsForm::register_field_type()` dem Pods-Kern?
 *
 * Frage der Karte (S13): Wie viel muss ein PR am Pods-Kern ändern, damit der
 * Feldtyp `repeater` dort existiert? Dieser Test belegt die Antwort an der
 * echten Installation (Pods 3.3.9.1, WordPress 6.8.2) — er beschreibt
 * KEINE neue Funktionalität, sondern hält die Zusage des Kerns fest, damit
 * ein späterer Kern-Umbau sie nicht still bricht.
 *
 * Woher die Erwartungen stammen: jede Assertion ist an der echten
 * Implementierung gemessen (Pods 3.3.9.1, per `wp eval` gegen die laufende
 * Installation geprüft), nicht aus der Doku abgeleitet. Kern-Dateien, auf die
 * sich die Zusagen stützen (Pods 3.3.9.1, nur lesen):
 *
 *   - classes/PodsForm.php:1654 register_field_type() → field_loader(),
 *     schreibt self::$field_types[ $type ] (1663) und cached per Transient.
 *   - classes/PodsForm.php:1693-1725 field_types_list(): statische Liste
 *     (1694-1718), `array_merge( …, array_keys( self::$field_types ) )` in
 *     1720, `array_unique`/`array_filter` in 1722 → registrierte Typen
 *     landen automatisch in der Liste.
 *   - classes/PodsForm.php:1550-1610 field_loader(): Klassennamen-Konvention
 *     `PodsField_` + ucfirst($type) in 1565-1566, Include der übergebenen
 *     Datei über `pods_form_field_include` in 1581/1589, Instanziierung in
 *     1594-1599, Fallback auf die Basisklasse `PodsField` in 1597.
 *   - classes/PodsForm.php:1734-1770 field_types(): Admin-Daten, baut aus
 *     field_types_list() (1736) und den Klassenvariablen (1756-1759).
 *   - classes/PodsForm.php:1827-1852 repeatable_field_types(): feste Liste
 *     (1831-1846), Filter in 1848.
 *   - classes/PodsInit.php:193-204 Autoloader-Pfad `PODS_DIR . 'classes/fields'`
 *     (203) — er findet nur Klassen, die im Kern-Verzeichnis liegen; für ein
 *     externes Plugin greift stattdessen die beim Registrieren übergebene
 *     Datei (PodsForm.php:1569-1571).
 *
 * Die Tests laufen gegen den Zustand, den das Plugin selbst herstellt
 * (pods-repeater.php:83-112 registriert beim plugins_loaded). Zusätzlich
 * wird die Registrierung hier idempotent wiederholt: register_field_type()
 * prüft Transient und Datei und registriert nur bei Abweichung neu
 * (PodsForm.php:1656-1669), ein zweiter Aufruf mit gleichem Pfad ist also
 * folgenlos.
 *
 * @package PodsRepeater
 */

/**
 * Class CoreIntegrationTest
 */
class CoreIntegrationTest extends WP_UnitTestCase {

	/**
	 * Pfad der Feldklasse, wie ihn das Plugin registriert.
	 *
	 * @var string
	 */
	protected $field_file;

	/**
	 * Setzt den Pfad und stellt sicher, dass Kern und Plugin geladen sind.
	 *
	 * Wichtig: Der Lauf startet mit dem Zustand, den das Plugin herstellt —
	 * und pods-repeater.php:99-110 füllt PodsForm::$loaded['repeater']
	 * selbst nach, weil field_loader() einen frühen Basisklassen-Eintrag
	 * behalten würde. Der Loader-Pfad des Kerns ist dadurch maskiert und
	 * würde hier auch bei kaputtem Kern (Konvention 1565-1566, Include
	 * 1569-1591) grün bleiben. Darum setzt der Test den Kern-Zustand
	 * vor jeder Messung explizit zurück: transienten Cache, $loaded und
	 * $field_types leeren, Datei und Klasse wie ein kalter Frontend-Request
	 * behandeln. Danach steht nur noch die Plugin-Registrierung dahinter.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// Harte Assertion statt Skip: ohne Kern ist der Beleg nicht führbar
		// und der Lauf muss rot werden.
		$this->assertTrue(
			class_exists( 'PodsForm' ),
			'PodsForm fehlt — Pods wurde im muplugins_loaded-Bootstrap nicht aktiv (siehe tests/bootstrap.php).'
		);

		$this->assertTrue(
			defined( 'PODS_REPEATER_DIR' ),
			'PODS_REPEATER_DIR fehlt — pods-repeater.php wurde nicht geladen (siehe tests/bootstrap.php).'
		);

		$this->field_file = PODS_REPEATER_DIR . 'classes/fields/repeater.php';

		$this->assertFileExists( $this->field_file, 'Die Feldklasse, die registriert wird, muss auf der Platte liegen.' );

		$this->reset_core_state();
	}

	/**
	 * Setzt den Feldtyp-Zustand des Kerns auf „kalt" zurück.
	 *
	 * Entfernt alles, was das Plugin beim Bootstrap bereits eingetragen hat
	 * (Transient, $loaded, $field_types) — sonst misst der Test nur die
	 * Nachlade-Logik des Plugins, nicht die Registrierungsfähigkeit des
	 * Kerns.
	 *
	 * @return void
	 */
	protected function reset_core_state() {
		delete_transient( 'pods_field_type_repeater' );
		delete_transient( 'pods_field_types' );

		unset( PodsForm::$loaded['repeater'] );
		unset( PodsForm::$field_types['repeater'] );

		// Idempotenter Nachvollzug der Plugin-Registrierung (siehe Klassenkopf).
		PodsForm::register_field_type( 'repeater', $this->field_file );

		// Auch die Registrierung selbst muss den Typ eingetragen haben —
		// sonst wäre der kalte Ausgangszustand schon defekt.
		$this->assertArrayHasKey(
			'repeater',
			PodsForm::$field_types,
			'register_field_type() hat \'repeater\' nicht in PodsForm::$field_types eingetragen (PodsForm.php:1663).'
		);
	}

	/**
	 * 1) Registrierung wirkt: der Typ steht in field_types_list().
	 *
	 * Bricht, wenn der Kern die registrierten Typen nicht mehr in die Liste
	 * merged (PodsForm.php:1720) — dann müsste ein PR field_types_list()
	 * anfassen.
	 *
	 * @return void
	 */
	public function test_registered_type_appears_in_field_types_list() {
		// Nach einer echten Entleerung neu registrieren und prüfen, dass
		// die Registrierung aus dem Nichts heraus trägt (nicht aus einem
		// Restzustand des Plugins). set_up() hat bereits kalt aufgesetzt;
		// hier wird derselbe Weg erneut gegangen, damit der Test auch
		// allein (--filter) den kalten Pfad misst.
		$this->reset_core_state();

		$list = PodsForm::field_types_list();

		$this->assertIsArray( $list, 'PodsForm::field_types_list() muss ein Array liefern.' );

		$this->assertContains(
			'repeater',
			$list,
			'Nach register_field_type( \'repeater\', <pfad> ) muss \'repeater\' in PodsForm::field_types_list() stehen. Fehlt er, müsste ein PR field_types_list() ändern (PodsForm.php:1693-1725).'
		);

		// Gegenprobe zum Merge: ein Name, der nirgends registriert ist, darf
		// ebenfalls nicht auftauchen — sonst wäre der Test blind für eine
		// Liste, die einfach alles durchreicht.
		$this->assertNotContains(
			'exists_nur_in_diesem_test',
			$list,
			'field_types_list() enthält einen nicht registrierten Namen — der Test kann die Registrierung dann nicht von einer offenen Liste unterscheiden.'
		);

		// Die Liste muss stabil bleiben: kein zweiter Eintrag durch den Merge.
		$repeater_entries = array_keys( $list, 'repeater', true );

		$this->assertCount(
			1,
			$repeater_entries,
			'\'repeater\' steht mehrfach in field_types_list() — array_unique() (PodsForm.php:1722) greift nicht mehr.'
		);
	}

	/**
	 * 2) Feld-Instanz entsteht: field_loader() liefert PodsField_Repeater.
	 *
	 * Bricht, wenn die Klassennamen-Konvention (PodsForm.php:1565-1566) oder
	 * der Include der übergebenen Datei (1581/1589) nicht mehr greift und
	 * der Loader auf die Basisklasse PodsField zurückfällt (1597).
	 *
	 * @return void
	 */
	public function test_field_loader_returns_repeater_instance() {
		$instance = PodsForm::field_loader( 'repeater' );

		$this->assertNotNull( $instance, 'PodsForm::field_loader( \'repeater\' ) liefert null.' );
		$this->assertIsObject( $instance, 'PodsForm::field_loader( \'repeater\' ) liefert kein Objekt.' );

		$this->assertInstanceOf(
			'PodsField_Repeater',
			$instance,
			'field_loader( \'repeater\' ) muss eine Instanz von PodsField_Repeater liefern, nicht die Basisklasse PodsField (PodsForm.php:1594-1599).'
		);

		// Kein stiller Fallback: die Basisklasse wäre ein gültiges PodsField,
		// aber der falsche Typ.
		$this->assertNotSame(
			'PodsField',
			get_class( $instance ),
			'field_loader( \'repeater\' ) ist auf die Basisklasse zurückgefallen — die Registrierungsdatei wurde nicht eingebunden (PodsForm.php:1569-1571).'
		);

		// Der Kern liest Typ und Gruppe über get_class_vars(); beide müssen
		// den Feldtyp beschreiben, sonst landet er in der falschen Gruppe.
		$this->assertSame(
			'repeater',
			PodsField_Repeater::$type,
			'PodsField_Repeater::$type muss \'repeater\' sein — field_loader() leitet den Klassennamen daraus ab (PodsForm.php:1565-1566).'
		);

		// Die Instanz muss gespeichert sein; field() rendert später über
		// self::$loaded[ $type ] (PodsForm.php:281, 310).
		$this->assertSame(
			$instance,
			PodsForm::field_loader( 'repeater' ),
			'field_loader() liefert beim zweiten Aufruf eine andere Instanz — self::$loaded (PodsForm.php:1607) wird nicht genutzt.'
		);
	}

	/**
	 * 3) 'repeater' steht NICHT in der Repeatable-Whitelist.
	 *
	 * Ein Eintrag dort schaltet den Feldtyp lautlos um: PodsForm::field()
	 * setzt `repeatable` nur für Whitelist-Typen zurück (PodsForm.php:312)
	 * und PodsForm::value() dekodiert dann generisch JSON (1113-1128).
	 * Der Repeater bringt sein eigenes Rendering (input()) mit und muss
	 * deshalb draußen bleiben.
	 *
	 * @return void
	 */
	public function test_repeater_is_not_a_repeatable_field_type() {
		$repeatable = PodsForm::repeatable_field_types();

		$this->assertIsArray( $repeatable, 'PodsForm::repeatable_field_types() muss ein Array liefern.' );

		$this->assertNotContains(
			'repeater',
			$repeatable,
			'\'repeater\' steht in PodsForm::repeatable_field_types() — dann setzt der Kern repeatable=0 für das Feld (PodsForm.php:312) und value() dekodiert generisch (1113-1128), das eigene Rendering des Feldtyps wird umgangen.'
		);

		// Gegenprobe, dass die Whitelist überhaupt Werte hat und der
		// NotContains nicht auf einer leeren Liste grün wird.
		$this->assertNotEmpty( $repeatable, 'repeatable_field_types() ist leer — der Test kann daraus nichts schließen.' );
		$this->assertContains( 'text', $repeatable, 'repeatable_field_types() enthält \'text\' nicht — die Liste wurde umgebaut, der Test misst dann das Falsche.' );
	}

	/**
	 * 4) Admin-Liste: field_types() liefert den Typ samt Label.
	 *
	 * Bricht, wenn der Kern die Klassenvariablen nicht mehr in die
	 * Feldtyp-Daten schreibt (PodsForm.php:1756-1759) oder den Typ aus der
	 * Liste verliert — dann fehlt er im Feldtyp-Picker der Pods-Admin.
	 *
	 * @return void
	 */
	public function test_repeater_appears_in_admin_field_types_with_label() {
		$types = PodsForm::field_types();

		$this->assertIsArray( $types, 'PodsForm::field_types() muss ein Array liefern.' );

		$this->assertArrayHasKey(
			'repeater',
			$types,
			'\'repeater\' fehlt in PodsForm::field_types() — der Typ erscheint nicht im Feldtyp-Picker der Pods-Admin.'
		);

		$data = $types['repeater'];

		$this->assertIsArray( $data, 'Die Feldtyp-Daten für \'repeater\' müssen ein Array aus get_class_vars() sein.' );

		$this->assertArrayHasKey( 'label', $data, 'Feldtyp-Daten für \'repeater\' haben kein \'label\' — der Picker hätte einen leeren Eintrag.' );

		$this->assertIsString( $data['label'], '\'label\' muss ein String sein.' );

		$this->assertNotSame(
			'',
			trim( $data['label'] ),
			'\'label\' ist leer — der Feldtyp wäre in der Admin-Liste nicht benennbar.'
		);

		$this->assertSame(
			'repeater',
			$data['type'],
			'Feldtyp-Daten für \'repeater\' müssen \'type\' => \'repeater\' tragen (PodsForm.php:1756-1760).'
		);

		$this->assertSame(
			$this->field_file,
			$data['file'],
			'Feldtyp-Daten für \'repeater\' müssen den Registrierungspfad tragen (PodsForm.php:1759), sonst lädt die Admin den Typ nach einem Cache-Verlust nicht mehr.'
		);

		// Alle Typen aus der Admin-Liste müssen in field_types_list() stehen:
		// belegt, dass die Admin-Daten aus der Liste abgeleitet sind.
		foreach ( array_keys( $types ) as $type ) {
			$this->assertContains(
				$type,
				PodsForm::field_types_list(),
				sprintf( 'Feldtyp \'%s\' liefert field_types(), fehlt aber in field_types_list() — die beiden Kern-Funktionen sind auseinandergelaufen.', $type )
			);
		}
	}
}
