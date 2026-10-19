<?php
/**
 * ACF-kompatible Lese-API für Repeater-Felder.
 *
 * ACF-Templates arbeiten mit einer globalen Iterationsschleife, nicht mit
 * Arrays:
 *
 *     if ( have_rows( 'items' ) ) {
 *         while ( have_rows( 'items' ) ) {
 *             the_row();
 *             echo get_sub_field( 'subtitle' );
 *         }
 *     }
 *
 * Diese Datei stellt dieselbe API unter eigenem Präfix bereit
 * (`pods_repeater_*`) und registriert auf Wunsch die ACF-Namen selbst.
 *
 * ## Warum die Alias-Registrierung HIER läuft und nicht auf `init`
 *
 * Pods Core liefert eine eigene ACF-Kompatibilitätsschicht:
 * `includes/compatibility/acf.php`, geladen aus
 * `PodsInit::after_setup_theme()` (PodsInit.php:467, nur außerhalb von
 * `is_admin()`). Diese Datei deklariert Stubs, die IMMER `false` liefern:
 *
 *     have_rows()     → false
 *     get_sub_field() → false
 *     the_sub_field() → null
 *
 * `the_row()` fehlt dort. `after_setup_theme` läuft VOR `init` — ein auf
 * `init` gehängter Alias-Hook findet die Namen also bereits belegt vor und
 * überspringt sie (mit `! function_exists()`-Guard). Ergebnis: die
 * ACF-Namen bleiben Pods' tote Stubs, ein ACF-Template iteriert nie.
 *
 * Deshalb hängt die Registrierung an `muplugins_loaded` (Priorität 1):
 * dieser Hook läuft beim Laden der Plugins, also VOR `after_setup_theme`.
 * Wir deklarieren zuerst; der `function_exists()`-Guard in Pods' acf.php
 * lässt die Stubs dann fallen. Läuft ACF selbst, hat es seine Funktionen
 * früher deklariert (Plugin-Ladevorgang) — wir überschreiben sie nie.
 *
 * Zusätzlich prüft die Registrierung nach jedem Deklarationsversuch, ob der
 * Name wirklich auf unsere Funktion zeigt; Pods' Stub kann unter
 * Sonderreihenfolgen gewinnen, und ein stiller Fehlschlag wäre schlimmer
 * als ein dokumentierter Zustand.
 *
 * @package PodsRepeater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alle Zeilen eines Repeater-Felds.
 *
 * Immer ein Array: fehlendes Feld, fehlendes Item, leerer oder defekter
 * Meta-Wert und ein Feld eines anderen Typs ergeben `array()`. Aufrufer
 * müssen nicht auf `null` prüfen.
 *
 * @param string      $field Feldname des Repeater-Felds.
 * @param int|null    $id    Item-ID; null = Item aus dem Loop-Kontext.
 * @param string|null $pod   Pod-Name; null = aus dem Loop-Kontext.
 *
 * @return array Zeilen als Liste von assoziativen Arrays.
 */
function pods_repeater_get_rows( $field, $id = null, $pod = null ) {
	$field = is_scalar( $field ) ? (string) $field : '';

	if ( '' === $field ) {
		return array();
	}

	$id  = is_numeric( $id ) ? (int) $id : null;
	$pod = is_scalar( $pod ) ? (string) $pod : null;

	if ( null === $pod && null === $id ) {
		// Loop-Kontext: pods_field() ohne Pod/ID löst über den aktuellen
		// Post auf. Ohne Loop-Kontext (CLI/Tests) ergibt das keine Zeilen.
		$value = pods_field( $field, true );
	} else {
		$value = pods_field( $pod, $id, $field, true );
	}

	return pods_repeater_normalize_rows( $value );
}

/**
 * Normalisiert einen Pods-Feldwert auf eine Zeilenliste.
 *
 * `value()` des Feldtyps liefert bei gültigem JSON ein Array. Zusätzlich
 * toleriert werden: null/false (kein Wert), ein JSON-String (falls ein
 * Aufrufer den rohen Meta-Wert durchreicht) und ein versehentlich eine
 * Ebene tief verschachteltes Zeilen-Array (Pods liefert bei fehlendem
 * `single` ein Wrapper-Array).
 *
 * @param mixed $value Rohwert aus Pods.
 *
 * @return array Liste von Zeilen-Arrays.
 */
function pods_repeater_normalize_rows( $value ) {
	if ( is_string( $value ) ) {
		$decoded = json_decode( $value, true );

		$value = ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : null;
	}

	if ( ! is_array( $value ) ) {
		return array();
	}

	// Ein einzelnes Zeilen-Array (assoziativ) ist keine Zeilenliste.
	if ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
		$value = array( $value );
	}

	$rows = array();

	foreach ( $value as $row ) {
		if ( is_array( $row ) ) {
			$rows[] = $row;
		}
	}

	return $rows;
}

/**
 * Prüft, ob eine aktuelle Zeile zum Ausgeben existiert.
 *
 * Rein lesend: die Funktion startet die Schleife beim ersten Aufruf (lädt
 * die Zeilen) und rückt NICHT vor. Das Vorrücken übernimmt
 * `pods_repeater_the_row()`. Damit liefern beide verbreiteten Muster
 * dieselbe Zeilenfolge, inklusive des kanonischen ACF-Musters:
 *
 *     if ( have_rows( 'items' ) ) {
 *         while ( have_rows( 'items' ) ) {
 *             the_row();
 *             …
 *         }
 *     }
 *
 * Genauso wie WordPress' `have_posts()`/`the_post()`: die Bedingung liest,
 * der Schritt rückt vor. Ist das Ende erreicht, wird der Schleifenzustand
 * zurückgesetzt und `false` geliefert — der nächste Aufruf startet wieder
 * bei der ersten Zeile.
 *
 * @param string      $field Feldname des Repeater-Felds.
 * @param int|null    $id    Item-ID; null = Item aus dem Loop-Kontext.
 * @param string|null $pod   Pod-Name; null = aus dem Loop-Kontext.
 *
 * @return bool True, solange eine aktuelle Zeile existiert.
 */
function pods_repeater_have_rows( $field, $id = null, $pod = null ) {
	$field = is_scalar( $field ) ? (string) $field : '';

	if ( '' === $field ) {
		return false;
	}

	$state = &pods_repeater_get_loop_state();

	if ( ! isset( $state[ $field ] ) || null === $state[ $field ] ) {
		$rows = pods_repeater_get_rows( $field, $id, $pod );

		if ( empty( $rows ) ) {
			return false;
		}

		$state[ $field ] = array(
			'rows'     => $rows,
			'index'    => 0,
			'current'  => null,
			'consumed' => false,
		);
	}

	/*
	 * `the_row()` hat die aktuelle Zeile gesetzt — die nächste Prüfung gilt
	 * der Folgzeile. Ohne vorheriges `the_row()` (Vorlauf im `if`) bleibt
	 * der Zeiger stehen und die erste Zeile geht nicht verloren.
	 */
	if ( $state[ $field ]['consumed'] ) {
		++$state[ $field ]['index'];
	}

	/*
	 * Schutz gegen Endlosschleifen: der Index kann die Zeilenzahl nie
	 * überschreiten. Ohne diesen Deckel hängt ein fehlerhafter
	 * Schleifenzustand das ganze Template (real beobachtet: PHPUnit wurde
	 * bei einer Mutation mit "Killed" abgebrochen).
	 */
	if ( $state[ $field ]['index'] >= count( $state[ $field ]['rows'] ) ) {
		$state[ $field ] = null;

		return false;
	}

	$state[ $field ]['consumed'] = false;

	return true;
}

/**
 * Setzt den Zeiger auf die aktuelle Zeile der Schleife.
 *
 * Analog zu `the_post()`: rückt auf die Zeile, die `have_rows()` als
 * nächstes gemeldet hat, und macht sie zur aktuellen Zeile für
 * `pods_repeater_get_sub_field()`. Läuft der Aufruf ohne laufende
 * Schleife, bleibt er wirkungslos.
 *
 * @return void
 */
function pods_repeater_the_row() {
	$state = &pods_repeater_get_loop_state();

	foreach ( $state as $field => $loop ) {
		if ( ! is_array( $loop ) ) {
			continue;
		}

		if ( isset( $loop['rows'][ $loop['index'] ] ) ) {
			$state[ $field ]['current']  = $loop['rows'][ $loop['index'] ];
			$state[ $field ]['consumed'] = true;
		}
	}
}

/**
 * Wert eines Sub-Felds in der aktuellen Zeile.
 *
 * Außerhalb einer laufenden Schleife `null`. Findet die aktuelle Zeile das
 * Sub-Feld nicht, ist das Ergebnis ebenfalls `null` (ACF liefert dort
 * `null`/`false`; `null` ist der eindeutigere "kein Wert"-Marker).
 *
 * @param string $name Name des Sub-Felds.
 *
 * @return mixed Wert des Sub-Felds oder null.
 */
function pods_repeater_get_sub_field( $name ) {
	$name = is_scalar( $name ) ? (string) $name : '';

	if ( '' === $name ) {
		return null;
	}

	$state = &pods_repeater_get_loop_state();

	foreach ( $state as $loop ) {
		if ( is_array( $loop ) && isset( $loop['current'] ) && is_array( $loop['current'] ) ) {
			if ( array_key_exists( $name, $loop['current'] ) ) {
				return $loop['current'][ $name ];
			}
		}
	}

	return null;
}

/**
 * Setzt alle laufenden Schleifen zurück.
 *
 * Ohne Feldname werden alle Felder zurückgesetzt. Templates, die eine
 * Schleife mehrfach durchlaufen wollen, rufen das zwischen den Läufen auf.
 *
 * @param string|null $field Feldname; null = alle Felder.
 *
 * @return void
 */
function pods_repeater_reset_rows( $field = null ) {
	$state = &pods_repeater_get_loop_state();

	if ( null === $field ) {
		$state = array();

		return;
	}

	$field = is_scalar( $field ) ? (string) $field : '';

	if ( '' !== $field ) {
		$state[ $field ] = null;
	}
}

/**
 * Referenz auf den Schleifenzustand (eine globale Variable).
 *
 * Eine eigene Funktion statt eines direkten `global`-Zugriffs, damit alle
 * Helfer garantiert dieselbe Referenz sehen.
 *
 * @return array Referenz auf den Schleifenzustand.
 */
function &pods_repeater_get_loop_state() {
	static $state = array();

	return $state;
}

/**
 * Registriert die ACF-Aliasnamen, sofern frei und nicht abgeschaltet.
 *
 * Wird direkt beim Laden von includes/template-functions.php aufgerufen
 * (siehe Dateiende), NICHT über einen Hook. Grund: das Plugin wird von
 * WordPress selbst während `plugins_loaded` geladen — ein `add_action` auf
 * einen früheren Hook käme zu spät. Im PHPUnit-Bootstrap
 * (tests/bootstrap.php) wird das Plugin auf `muplugins_loaded` Priorität 10
 * geladen, also noch später.
 *
 * Direkt beim Laden ist der richtige Zeitpunkt: er liegt vor jedem
 * `after_setup_theme` (Pods' ACF-Shim) und vor `init`.
 *
 * Die Alias-Funktionen selbst liegen in einer separaten Datei, die hier
 * geladen wird. Läge sie im Plugin-Start, würde PHP die ACF-Namen beim
 * Parsen deklarieren — noch bevor dieser Filter greifen kann.
 *
 * @return void
 */
function pods_repeater_register_acf_aliases() {
	/**
	 * Ob die ACF-kompatiblen Funktionsnamen registriert werden sollen.
	 *
	 * @param bool $register Default true.
	 */
	if ( ! apply_filters( 'pods_repeater_register_acf_aliases', true ) ) {
		return;
	}

	require_once __DIR__ . '/template-functions-aliases.php';
}

pods_repeater_register_acf_aliases();
