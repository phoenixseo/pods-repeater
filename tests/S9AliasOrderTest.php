<?php
/**
 * S9AliasOrderTest — Prioritätsnachweis für die ACF-Aliase.
 *
 * Pods Core bringt eine eigene ACF-Kompatibilitätsschicht mit
 * (`includes/compatibility/acf.php`, geladen aus
 * `PodsInit::after_setup_theme()`, PodsInit.php:467). Sie deklariert
 * Stubs, die immer `false` liefern:
 *
 *     have_rows()     → false
 *     get_sub_field() → false
 *     the_sub_field() → null
 *
 * `the_row()` fehlt dort. Läge unsere Alias-Registrierung auf `init`, wäre
 * `after_setup_theme` längst gelaufen — die Stubs stünden und ein
 * ACF-Template iterierte nie. Dieser Test ist der Nachweis, dass UNSERE
 * Deklaration gewinnt, also vor dem Shim gelaufen ist.
 *
 * Er prüft die Herkunft über ReflectionFunction: nicht nur "Funktion
 * existiert", sondern "kommt aus unserem Plugin".
 *
 * @package PodsRepeater
 */

/**
 * Class S9AliasOrderTest
 */
class S9AliasOrderTest extends WP_UnitTestCase {

	/**
	 * have_rows() muss aus unserem Plugin stammen, nicht aus Pods' Shim.
	 *
	 * @return void
	 */
	public function test_have_rows_comes_from_our_plugin() {
		$this->assertTrue( function_exists( 'have_rows' ) );

		$ref = new ReflectionFunction( 'have_rows' );

		$this->assertStringContainsString(
			'pods-repeater/includes/template-functions-aliases.php',
			str_replace( '\\', '/', $ref->getFileName() ),
			'have_rows() muss aus unserem Plugin kommen, nicht aus Pods\' ACF-Shim.'
		);
	}

	/**
	 * Alle drei Aliase stammen aus unserem Plugin.
	 *
	 * @return void
	 */
	public function test_all_aliases_come_from_our_plugin() {
		foreach ( array( 'have_rows', 'the_row', 'get_sub_field' ) as $name ) {
			$this->assertTrue( function_exists( $name ), $name . '() muss existieren.' );

			$ref = new ReflectionFunction( $name );

			$this->assertStringContainsString(
				'pods-repeater/includes/template-functions-aliases.php',
				str_replace( '\\', '/', $ref->getFileName() ),
				$name . '() muss aus unserem Plugin kommen.'
			);
		}
	}

	/**
	 * Die Aliase funktionieren wirklich als Schleife über echte Daten.
	 *
	 * Deckt die Delegation ab: haben die Aliase nur die richtige Herkunft,
	 * aber falsche Parameter, ist diese Assertion rot.
	 *
	 * @return void
	 */
	public function test_alias_loop_works_over_real_data() {
		$pod    = 's9_alias_probe';
		$api    = pods_api();
		$exists = $api->load_pod( array( 'name' => $pod ) );

		if ( $exists instanceof \Pods\Whatsit\Pod ) {
			$api->delete_pod( array( 'id' => $exists['id'] ) );
		}

		$api->save_pod(
			array(
				'name'    => $pod,
				'label'   => 'S9 Alias Probe',
				'type'    => 'post_type',
				'storage' => 'meta',
			)
		);

		$api->save_field(
			array(
				'pod'                 => $pod,
				'name'                => 'items',
				'label'               => 'Items',
				'type'                => 'repeater',
				'repeater_sub_fields' => wp_json_encode(
					array(
						array(
							'name'  => 'subtitle',
							'label' => 'Untertitel',
							'type'  => 'text',
						),
					)
				),
				'disable_dfv'         => true,
			)
		);

		$item_id = wp_insert_post(
			array(
				'post_type'   => $pod,
				'post_status' => 'publish',
				'post_title'  => 'S9 Alias Item',
			),
			true
		);

		$this->assertIsInt( $item_id );

		$saved = pods_api()->save_pod_item(
			array(
				'pod'        => $pod,
				'id'         => $item_id,
				'from'       => 'save',
				'error_mode' => 'wp_error',
				'data'       => array(
					'items' => array(
						array( 'subtitle' => 'Alias A' ),
						array( 'subtitle' => 'Alias B' ),
					),
				),
			)
		);

		$this->assertNotWPError( $saved );

		$titles = array();

		if ( have_rows( 'items', $item_id, $pod ) ) {
			while ( have_rows( 'items', $item_id, $pod ) ) {
				the_row();

				$titles[] = get_sub_field( 'subtitle' );
			}
		}

		$this->assertSame( array( 'Alias A', 'Alias B' ), $titles, 'Die ACF-Aliase müssen auf die pods_repeater_*-Funktionen delegieren.' );

		wp_delete_post( $item_id, true );

		$pod_obj = pods_api()->load_pod( array( 'name' => $pod ) );

		if ( $pod_obj instanceof \Pods\Whatsit\Pod ) {
			pods_api()->delete_pod( array( 'id' => $pod_obj['id'] ) );
		}
	}
}
