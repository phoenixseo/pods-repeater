<?php
/**
 * Roundtrip100Test — 100 Speicher-Zyklen ohne Datenverlust (GO-Kriterium Phase B).
 *
 * Speicherweg (Entscheidung Orchestrator, 2026-10-19): der ECHTE Plugin-Pfad
 *
 *     set_up()  → Pod + Repeater-Feld über pods_api()->save_pod()/save_field()
 *     Zyklus n  → pods_api()->save_pod_item( 'from' => 'save' )
 *                 (das ruft pre_save() der Feldklasse und schreibt wp_postmeta)
 *               → frisch laden: pods( <pod>, <id>, true )->field( <feld> )
 *               → deep-equal gegen den Ausgangswert
 *
 * Bewusst NICHT als Verlustfreiheits-Garantie getestet wird der rohe
 * Speicherweg `update_post_meta( $id, $key, $json )`:
 *
 *   WordPress' sanitize_meta() läuft durch wp_unslash(). Ein direkt
 *   geschriebener JSON-String verliert dadurch je Zyklus die Hälfte seiner
 *   Backslashes (`C:\\a\\b` → `C:ab` nach 4 Zyklen, gemessen), `\n`/`\r`
 *   werden zu `n`/`r`, und ein `"` im Wert macht das JSON unparsebar
 *   (value() gibt dann den String statt des Arrays zurück). Das ist
 *   WP-Kernverhalten, kein Defekt des Repeater-Felds: Pods speichert selbst
 *   über pods_slash()/save_pod_item. Wer Meta direkt schreibt, muss
 *   wp_slash() verwenden — test_raw_meta_without_wp_slash_loses_backslashes()
 *   und test_raw_meta_with_wp_slash_keeps_backslashes() halten genau diesen
 *   Unterschied fest, damit der Befund nicht in Vergessenheit gerät.
 *
 * Invarianten (jede als eigene Assertion, über alle 100 Zyklen gesammelt):
 *   - Zeilenzahl bleibt konstant, keine Duplikate (stabile Zeilen-Multiset),
 *   - Reihenfolge bleibt stabil,
 *   - vollständig leere Zeilen werden verworfen (auch zwischen gefüllten),
 *   - repeater_limit wird respektiert (pre_save() kappt; validate() lehnt ab).
 *
 * Der Zykluszähler wird hart geprüft (assertSame( 100, $cycles )): der Test
 * beweist 100 Iterationen, nicht nur einen geglückten Durchlauf.
 *
 * Unbekannte Sub-Keys werden GEMESSEN, nicht erzwungen: sie bleiben in der
 * aktuellen Implementierung erhalten (pre_save() encodiert das rohe Zeilen-
 * Array). Der Test hält das Ist-Verhalten fest und dokumentiert es im
 * Bericht — ein künftiges Weglassen ist damit eine bewusste Änderung.
 *
 * @package PodsRepeater
 */

/**
 * Class Roundtrip100Test
 */
class Roundtrip100Test extends WP_UnitTestCase {

	/**
	 * Anzahl der Speicher-Zyklen je Konfiguration (GO-Kriterium).
	 */
	const CYCLES = 100;

	/**
	 * Pod-Slug der Wegwerf-Pods (Registry-Aufräumen in tear_down()).
	 *
	 * @var string[]
	 */
	protected $pod_names = array();

	/**
	 * Sub-Felder für Konfiguration (a), inline-JSON.
	 *
	 * @var array
	 */
	protected $inline_sub_fields;

	/**
	 * Gruppen-Slug für Konfiguration (b).
	 *
	 * @var string
	 */
	protected $group_slug = 's8_hero';

	/**
	 * Registrierten Gruppen-Slug und Sub-Feld-Definitionen vorbereiten.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->assertTrue(
			class_exists( 'PodsField_Repeater' ),
			'PodsField_Repeater nicht geladen — Pods/pods-repeater wurden im muplugins_loaded-Bootstrap nicht aktiv (siehe tests/bootstrap.php) oder die Feldklasse wurde umbenannt.'
		);

		// Registry-Zustand isolieren (Options-Cache überlebt das DB-Rollback).
		delete_option( PODS_REPEATER_GROUPS_OPTION );
		delete_option( PODS_REPEATER_GROUPS_VERSION_OPTION );

		$this->inline_sub_fields = array(
			array(
				'name'  => 'subtitle',
				'label' => 'Untertitel',
				'type'  => 'text',
			),
			array(
				'name'  => 'note',
				'label' => 'Notiz',
				'type'  => 'text',
			),
			array(
				'name'  => 'link',
				'label' => 'Link',
				'type'  => 'text',
			),
		);

		$slug = pods_repeater_save_group(
			array(
				'slug'   => $this->group_slug,
				'label'  => 'S8 Hero',
				'fields' => array(
					array(
						'name'  => 'g_title',
						'label' => 'Titel',
						'type'  => 'text',
					),
					array(
						'name'  => 'g_note',
						'label' => 'Notiz',
						'type'  => 'text',
					),
					array(
						'name'  => 'g_link',
						'label' => 'Link',
						'type'  => 'text',
					),
				),
			)
		);

		$this->assertSame( $this->group_slug, $slug, 'Testgruppe muss in der Registry liegen.' );
	}

	/**
	 * Wegwerf-Pods wieder entfernen (Test-DB ist flüchtig, aber die
	 * Pod-Objekte liegen in der übergeordneten DB und würden sonst
	 * zwischen Tests derselben Klasse kollidieren).
	 *
	 * @return void
	 */
	public function tear_down() {
		$api = pods_api();

		foreach ( $this->pod_names as $name ) {
			$pod = $api->load_pod(
				array(
					'name'       => $name,
					'auto_setup' => false,
				)
			);

			if ( ! is_wp_error( $pod ) && $pod instanceof Pods\Whatsit\Pod ) {
				$api->delete_pod( array( 'id' => $pod['id'] ) );
			}
		}

		$this->pod_names = array();

		delete_option( PODS_REPEATER_GROUPS_OPTION );
		delete_option( PODS_REPEATER_GROUPS_VERSION_OPTION );

		parent::tear_down();
	}

	/**
	 * Legt einen Wegwerf-Pod (CPT, meta storage) mit Repeater-Feld an.
	 *
	 * @param string $field_key     Feldname des Repeaters.
	 * @param array  $field_options Zusätzliche Feld-Optionen (z. B. repeater_limit).
	 * @param string $group         Gruppen-Slug ('' = inline-JSON).
	 *
	 * @return array{0:string,1:string} Pod-Name und Feldname.
	 */
	protected function create_repeater_pod( $field_key, $field_options, $group = '' ) {
		$api = pods_api();
		$pod = 's8_rt_' . strtolower( preg_replace( '/[^a-z0-9]/i', '', $field_key ) ) . '_' . wp_generate_password( 4, false, false );
		$pod = strtolower( substr( $pod, 0, 20 ) );

		$pod_id = $api->save_pod(
			array(
				'name'          => $pod,
				'label'         => 'S8 Roundtrip',
				'type'          => 'post_type',
				'storage'       => 'meta',
				'public'        => false,
				'create_extend' => 'create',
			)
		);

		$this->assertNotWPError( $pod_id, 'Wegwerf-Pod muss anlegbar sein.' );
		$this->pod_names[] = $pod;

		$args = array_merge(
			array(
				'pod'                 => $pod,
				'name'                => $field_key,
				'label'               => 'Items',
				'type'                => 'repeater',
				'repeater_sub_fields' => wp_json_encode( $this->inline_sub_fields ),
				'disable_dfv'         => true,
			),
			$field_options
		);

		if ( '' !== $group ) {
			$args['repeater_group'] = $group;
		}

		$field_id = $api->save_field( $args );

		$this->assertNotWPError( $field_id, 'Repeater-Feld muss anlegbar sein.' );
		$this->assertIsInt( (int) $field_id );

		return array( $pod, $field_key );
	}

	/**
	 * Legt einen Wegwerf-Item-Post im Pod an.
	 *
	 * @param string $pod Pod-Name.
	 *
	 * @return int
	 */
	protected function create_item( $pod ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => $pod,
				'post_status' => 'publish',
				'post_title'  => 'S8 Roundtrip Item',
			),
			true
		);

		$this->assertNotWPError( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		return (int) $post_id;
	}

	/**
	 * Schreibt Zeilen über den echten Plugin-Pfad und liest sie frisch zurück.
	 *
	 * @param string $pod      Pod-Name.
	 * @param int    $post_id  Item-ID.
	 * @param string $field    Feldname.
	 * @param array  $rows     Zeilen (Ausgangswert).
	 *
	 * @return array Zurückgelesener Wert.
	 */
	protected function save_and_reload( $pod, $post_id, $field, $rows ) {
		$result = pods_api()->save_pod_item(
			array(
				'pod'        => $pod,
				'id'         => $post_id,
				'from'       => 'save',
				'error_mode' => 'wp_error',
				'data'       => array( $field => $rows ),
			)
		);

		$this->assertNotWPError( $result, 'save_pod_item() darf keine Zeilen mit Sonderzeichen ablehnen.' );

		// Frisch laden: kein Pod-Cache, neuer Lesevorgang aus wp_postmeta.
		$item = pods( $pod, $post_id, true );

		$this->assertTrue( $item->exists(), 'Item muss nach dem Speichern existieren.' );

		return $item->field( $field );
	}

	/**
	 * Sonderfall-Zeilen für Konfiguration (a) — inline-JSON-Sub-Felder.
	 *
	 * Enthält: Unicode/Emoji, HTML, einfache und doppelte Anführungszeichen,
	 * Backslash-Pfad, LF/CRLF-Zeilenumbrüche, 10k-Zeichen-String, einen
	 * Leerstring-Wert, '0', 0, false und null.
	 *
	 * @return array
	 */
	protected function special_rows_inline() {
		return array(
			array(
				'subtitle' => 'Ünïcode — äöüß 漢字 😀 🎉',
				'note'     => '',
				'link'     => 'https://example.com/ünïcode?q=äöü',
			),
			array(
				'subtitle' => '<script>alert(1)</script>',
				'note'     => '<b>bold</b> & "quoted"',
				'link'     => 'https://example.com/html?a=1&b=2',
			),
			array(
				'subtitle' => 'Er sagte: "Hallo" und \'Tschüss\'',
				'note'     => "it's a \"test\"",
				'link'     => 'https://example.com/q?s="x"',
			),
			array(
				'subtitle' => 'C:\\pfad\\zum\\ziel',
				'note'     => '\\\\server\\share\\file.txt',
				'link'     => 'https://example.com/backslash',
			),
			array(
				'subtitle' => "Zeile 1\nZeile 2\n\nZeile 4",
				'note'     => "CRLF\r\nEnde\r\n",
				'link'     => 'https://example.com/newlines',
			),
			array(
				'subtitle' => str_repeat( 'x', 10000 ),
				'note'     => str_repeat( 'ä', 100 ),
				'link'     => 'https://example.com/10k',
			),
			array(
				'subtitle' => '0',
				'note'     => '',
				'link'     => 'https://example.com/string-zero',
			),
			array(
				'subtitle' => 0,
				'note'     => '',
				'link'     => 'https://example.com/int-zero',
			),
			array(
				'subtitle' => false,
				'note'     => '',
				'link'     => 'https://example.com/false',
			),
			array(
				'subtitle' => null,
				'note'     => '',
				'link'     => 'https://example.com/null',
			),
		);
	}

	/**
	 * Sonderfall-Zeilen für Konfiguration (b) — Feldgruppe aus der Registry.
	 * Andere Sub-Key-Namen (g_*), damit ein versehentlicher JSON-Fallback auf
	 * die Inline-Definition auffällt.
	 *
	 * @return array
	 */
	protected function special_rows_group() {
		return array(
			array(
				'g_title' => 'Gruppe Ünïcode 😀',
				'g_note'  => '',
				'g_link'  => 'https://example.com/gruppe',
			),
			array(
				'g_title' => '<script>alert(1)</script>',
				'g_note'  => 'C:\\pfad\\zum\\ziel',
				'g_link'  => 'https://example.com/g?q="x"',
			),
			array(
				'g_title' => "Zeile 1\nZeile 2",
				'g_note'  => 'Er sagte: "Hallo"',
				'g_link'  => 'https://example.com/g-newlines',
			),
			array(
				'g_title' => str_repeat( 'y', 10000 ),
				'g_note'  => '',
				'g_link'  => 'https://example.com/g-10k',
			),
			array(
				'g_title' => '0',
				'g_note'  => '',
				'g_link'  => 'https://example.com/g-string-zero',
			),
			array(
				'g_title' => 0,
				'g_note'  => '',
				'g_link'  => 'https://example.com/g-int-zero',
			),
			array(
				'g_title' => false,
				'g_note'  => '',
				'g_link'  => 'https://example.com/g-false',
			),
			array(
				'g_title' => null,
				'g_note'  => '',
				'g_link'  => 'https://example.com/g-null',
			),
		);
	}

	/**
	 * Prüft eine Zeile auf Duplikate im gesamten Multiset (nicht nur
	 * benachbart) und gibt die Duplikate zurück.
	 *
	 * @param array $rows Zeilen.
	 *
	 * @return array Liste der doppelt vorkommenden Zeilen-Hashes.
	 */
	protected function find_duplicate_rows( $rows ) {
		$seen  = array();
		$dupes = array();

		foreach ( $rows as $row ) {
			$hash = md5( (string) wp_json_encode( $row ) );

			if ( isset( $seen[ $hash ] ) ) {
				$dupes[ $hash ] = true;
			}

			$seen[ $hash ] = true;
		}

		return array_keys( $dupes );
	}

	/**
	 * Konfiguration (a): 100 Zyklen über inline-JSON-Sub-Felder.
	 *
	 * Jeder Zyklus speichert denselben Sonderfall-Datensatz erneut und liest
	 * ihn frisch zurück. Abweichungen werden gesammelt und am Ende mit den
	 * ersten drei Beispielen gemeldet — so fällt auch ein Drift ab Zyklus 87
	 * auf, statt beim ersten Fehlschlag abzubrechen.
	 *
	 * @return void
	 */
	public function test_inline_json_sub_fields_survive_100_cycles() {
		list( $pod, $field ) = $this->create_repeater_pod( 'items', array() );
		$post_id             = $this->create_item( $pod );

		$rows     = $this->special_rows_inline();
		$expected = $rows;
		$cycles   = 0;
		$first    = null;
		$drifts   = array();
		$counts   = array();

		for ( $i = 1; $i <= self::CYCLES; $i++ ) {
			$value = $this->save_and_reload( $pod, $post_id, $field, $rows );
			++$cycles;

			$counts[] = is_array( $value ) ? count( $value ) : -1;

			if ( $value !== $expected ) {
				if ( null === $first ) {
					$first = array(
						'cycle'    => $i,
						'expected' => $expected,
						'actual'   => $value,
					);
				}

				if ( count( $drifts ) < 3 ) {
					$drifts[] = $i;
				}
			}
		}

		$this->assertSame( 100, $cycles, 'Es müssen genau 100 Speicher-Zyklen gelaufen sein.' );
		$this->assertCount( self::CYCLES, $counts, 'Pro Zyklus muss ein Rücklesewert vorliegen.' );
		$this->assertSame(
			array( count( $expected ) ),
			array_values( array_unique( $counts ) ),
			'Zeilenanzahl muss über alle 100 Zyklen konstant bleiben.'
		);
		$this->assertNull(
			$first,
			'Konfiguration (a): Werte weichen in Zyklus ' . ( null === $first ? '-' : $first['cycle'] ) . ' ab. Abweichungen in Zyklen: ' . implode( ',', $drifts )
		);
		$this->assertSame( $expected, $this->save_and_reload( $pod, $post_id, $field, $rows ) );

		// Duplikate: keine Zeile darf doppelt im Multiset vorkommen.
		$this->assertSame( array(), $this->find_duplicate_rows( $rows ), 'Der Testdatensatz selbst darf keine doppelten Zeilen enthalten.' );
		$this->assertSame(
			array(),
			$this->find_duplicate_rows( $this->save_and_reload( $pod, $post_id, $field, $rows ) ),
			'Nach 100 Zyklen dürfen keine Zeilen dupliziert sein.'
		);
	}

	/**
	 * Konfiguration (b): 100 Zyklen über eine Feldgruppe aus der Registry.
	 *
	 * Dieselben Invarianten wie (a), aber der Sub-Feld-Satz kommt aus
	 * pods_repeater_get_group_fields() (Feld-Option `repeater_group`).
	 *
	 * @return void
	 */
	public function test_registry_group_sub_fields_survive_100_cycles() {
		list( $pod, $field ) = $this->create_repeater_pod(
			'gitems',
			array(
				'repeater_sub_fields' => '[]',
				'repeater_group'      => $this->group_slug,
			),
			$this->group_slug
		);

		$post_id = $this->create_item( $pod );
		$rows    = $this->special_rows_group();

		$cycles   = 0;
		$drifts   = array();
		$counts   = array();
		$first    = null;
		$expected = $rows;

		for ( $i = 1; $i <= self::CYCLES; $i++ ) {
			$value = $this->save_and_reload( $pod, $post_id, $field, $rows );
			++$cycles;

			$counts[] = is_array( $value ) ? count( $value ) : -1;

			if ( $value !== $expected ) {
				if ( null === $first ) {
					$first = array(
						'cycle'    => $i,
						'expected' => $expected,
						'actual'   => $value,
					);
				}

				if ( count( $drifts ) < 3 ) {
					$drifts[] = $i;
				}
			}
		}

		$this->assertSame( 100, $cycles, 'Es müssen genau 100 Speicher-Zyklen gelaufen sein.' );
		$this->assertCount( self::CYCLES, $counts );
		$this->assertSame(
			array( count( $expected ) ),
			array_values( array_unique( $counts ) ),
			'Zeilenanzahl muss über alle 100 Zyklen konstant bleiben.'
		);
		$this->assertNull(
			$first,
			'Konfiguration (b): Werte weichen in Zyklus ' . ( null === $first ? '-' : $first['cycle'] ) . ' ab. Abweichungen in Zyklen: ' . implode( ',', $drifts )
		);
		$this->assertSame( $expected, $this->save_and_reload( $pod, $post_id, $field, $rows ) );

		// Die Gruppe gewinnt: g_*-Keys kommen zurück, keine Inline-Keys.
		$value = $this->save_and_reload( $pod, $post_id, $field, $rows );
		$this->assertSame( 0, count( $this->find_duplicate_rows( $value ) ), 'Keine Duplikate nach 100 Zyklen.' );
	}

	/**
	 * Reihenfolge: die gespeicherte Zeilenfolge wird exakt so zurückgegeben,
	 * auch wenn die Zeilen inhaltlich fast gleich sind (nur die letzte Ziffer
	 * unterscheidet sich). Ein Sortier- oder Dedupe-Schritt würde das brechen.
	 *
	 * @return void
	 */
	public function test_row_order_stable_over_100_cycles() {
		list( $pod, $field ) = $this->create_repeater_pod( 'items', array() );
		$post_id             = $this->create_item( $pod );

		$rows = array();

		foreach ( range( 1, 20 ) as $n ) {
			$rows[] = array(
				'subtitle' => 'Zeile ' . $n,
				'note'     => '',
				'link'     => 'https://example.com/' . $n,
			);
		}

		$cycles = 0;
		$order  = array();

		for ( $i = 1; $i <= self::CYCLES; $i++ ) {
			$value = $this->save_and_reload( $pod, $post_id, $field, $rows );
			++$cycles;

			$this->assertIsArray( $value );

			$order[] = implode( '|', array_column( $value, 'subtitle' ) );
		}

		$expected_order = implode( '|', array_column( $rows, 'subtitle' ) );

		$this->assertSame( 100, $cycles );
		$this->assertSame(
			array( $expected_order ),
			array_values( array_unique( $order ) ),
			'Reihenfolge muss über alle 100 Zyklen stabil und identisch zum Ausgangswert sein.'
		);
		$this->assertSame( $rows, $this->save_and_reload( $pod, $post_id, $field, $rows ) );
	}

	/**
	 * Leere Zeilen: eine komplett leere Zeile zwischen gefüllten Zeilen (und
	 * am Ende) wird verworfen, die gefüllten Zeilen bleiben in Reihenfolge.
	 * Mehrfach gespeichert, damit das Verwerfen idempotent bleibt.
	 *
	 * @return void
	 */
	public function test_empty_rows_between_filled_rows_are_dropped() {
		list( $pod, $field ) = $this->create_repeater_pod( 'items', array() );
		$post_id             = $this->create_item( $pod );

		$filled = array(
			array(
				'subtitle' => 'Erste',
				'note'     => '',
				'link'     => 'https://example.com/1',
			),
			array(
				'subtitle' => 'Zweite',
				'note'     => '',
				'link'     => 'https://example.com/2',
			),
			array(
				'subtitle' => 'Dritte',
				'note'     => '',
				'link'     => 'https://example.com/3',
			),
		);

		$empty = array(
			'subtitle' => '',
			'note'     => '',
			'link'     => '',
		);

		$with_empty = array(
			$filled[0],
			$empty,
			$filled[1],
			array(
				'subtitle' => null,
				'note'     => null,
				'link'     => null,
			),
			$filled[2],
			$empty,
		);

		$cycles = 0;

		for ( $i = 1; $i <= self::CYCLES; $i++ ) {
			$value = $this->save_and_reload( $pod, $post_id, $field, $with_empty );
			++$cycles;

			$this->assertSame( $filled, $value, 'Leere Zeilen müssen verworfen werden (Zyklus ' . $i . '), Rest in Originalreihenfolge.' );
		}

		$this->assertSame( 100, $cycles );
	}

	/**
	 * `repeater_limit` wird respektiert: pre_save() kappt auf die ersten N
	 * Zeilen (Reihenfolge bleibt), validate() meldet den Verstoß.
	 *
	 * Beide Ebenen werden geprüft, weil sie unterschiedliche Schreibwege
	 * abdecken: save_pod_item() fängt den Verstoß in validate() ab,
	 * direkte pre_save()-Aufrufe (Import/Tools) werden gekappt.
	 *
	 * @return void
	 */
	public function test_repeater_limit_respected() {
		list( $pod, $field ) = $this->create_repeater_pod( 'items', array( 'repeater_limit' => 2 ) );
		$post_id             = $this->create_item( $pod );

		$rows = array();

		foreach ( range( 1, 5 ) as $n ) {
			$rows[] = array(
				'subtitle' => 'Zeile ' . $n,
				'note'     => '',
				'link'     => 'https://example.com/' . $n,
			);
		}

		// Ebene 1: validate() lehnt ab (save_pod_item liefert WP_Error, nichts wird geschrieben).
		$result = pods_api()->save_pod_item(
			array(
				'pod'        => $pod,
				'id'         => $post_id,
				'from'       => 'save',
				'error_mode' => 'wp_error',
				'data'       => array( $field => $rows ),
			)
		);

		$this->assertWPError( $result, 'Mehr Zeilen als das Limit müssen von validate() abgelehnt werden.' );
		$this->assertStringContainsString( 'at most 2 rows', $result->get_error_message() );
		// Ein abgelehnter Save darf nichts persistieren: kein Meta-Eintrag
		// (get_post_meta mit single=true liefert '' wenn der Key fehlt).
		$this->assertSame( array(), get_post_meta( $post_id, $field, false ), 'Ein abgelehnter Save darf nichts persistieren.' );

		// Ebene 2: pre_save() kappt — 100 Zyklen bleiben bei genau 2 Zeilen.
		$field_object = new PodsField_Repeater();
		$options      = array(
			'repeater_sub_fields' => wp_json_encode( $this->inline_sub_fields ),
			'repeater_limit'      => 2,
			'disable_dfv'         => true,
		);

		$cycles = 0;

		for ( $i = 1; $i <= self::CYCLES; $i++ ) {
			$encoded = $field_object->pre_save( $rows, $post_id, $field, $options );
			$decoded = $field_object->value( $encoded );

			++$cycles;

			$this->assertIsArray( $decoded );
			$this->assertCount( 2, $decoded, 'pre_save() muss auf das Limit kappen (Zyklus ' . $i . ').' );
			$this->assertSame( array_slice( $rows, 0, 2 ), $decoded, 'Die ersten N Zeilen müssen in Reihenfolge erhalten bleiben.' );
		}

		$this->assertSame( 100, $cycles );

		// Limit = 0 bedeutet unbegrenzt: alle Zeilen überleben.
		$unlimited = $field_object->pre_save(
			$rows,
			$post_id,
			$field,
			array(
				'repeater_sub_fields' => wp_json_encode( $this->inline_sub_fields ),
				'repeater_limit'      => 0,
			)
		);

		$this->assertCount( 5, $field_object->value( $unlimited ), 'repeater_limit = 0 muss unbegrenzt bedeuten.' );
	}

	/**
	 * Befund-Messung: unbekannte Sub-Keys überleben den echten Pfad.
	 *
	 * Pre_save() encodiert das rohe Zeilen-Array — es gibt keine Whitelist
	 * gegen die Sub-Feld-Definition. Der Test hält das Ist-Verhalten fest,
	 * damit eine spätere Filterung eine bewusste Entscheidung ist.
	 *
	 * @return void
	 */
	public function test_unknown_sub_keys_are_measured_not_enforced() {
		list( $pod, $field ) = $this->create_repeater_pod( 'items', array() );
		$post_id             = $this->create_item( $pod );

		$rows = array(
			array(
				'subtitle'      => 'Mit Extra',
				'note'          => '',
				'link'          => 'https://example.com/x',
				'zz_unknown'    => 'extra-wert Ünïcode 😀',
				'zz_numeric'    => 42,
				'zz_structured' => array(
					'deep' => array( 'key' => 'value' ),
				),
			),
			array(
				'zz_only' => 'nur unbekannt',
			),
		);

		$value = $this->save_and_reload( $pod, $post_id, $field, $rows );

		$this->assertIsArray( $value );
		$this->assertCount( 2, $value, 'Zeilen mit unbekannten Keys sind gefüllt und dürfen nicht verworfen werden.' );
		$this->assertSame( 'extra-wert Ünïcode 😀', $value[0]['zz_unknown'] );
		$this->assertSame( 42, $value[0]['zz_numeric'] );
		$this->assertSame( array( 'deep' => array( 'key' => 'value' ) ), $value[0]['zz_structured'] );
		$this->assertArrayHasKey( 'zz_only', $value[1] );
		$this->assertSame( $rows, $value, 'Ist-Verhalten: unbekannte Sub-Keys bleiben erhalten.' );
	}

	/**
	 * Kern-Befund (dokumentiert, nicht als Plugin-Garantie): der rohe
	 * update_post_meta()-Pfad verliert Backslashes, weil sanitize_meta() →
	 * wp_unslash() läuft. Wer direkt Meta schreibt, muss wp_slash() nutzen.
	 *
	 * @return void
	 */
	public function test_raw_meta_without_wp_slash_loses_backslashes() {
		$post_id = $this->create_item( 'post' );

		update_post_meta( $post_id, 's8_raw', 'C:\\a\\b' );

		$this->assertSame( 'C:ab', get_post_meta( $post_id, 's8_raw', true ), 'wp_unslash() im Meta-Write muss die Backslashes kollabieren — sonst ist der dokumentierte Befund falsch.' );
	}

	/**
	 * Gegenprobe zum Befund: mit wp_slash() ist der rohe Meta-Pfad byte-identisch.
	 *
	 * @return void
	 */
	public function test_raw_meta_with_wp_slash_keeps_backslashes() {
		$post_id = $this->create_item( 'post' );
		$raw     = 'C:\\a\\b "q" ' . "line1\nline2";

		update_post_meta( $post_id, 's8_raw', wp_slash( $raw ) );

		$this->assertSame( $raw, get_post_meta( $post_id, 's8_raw', true ) );
	}
}
