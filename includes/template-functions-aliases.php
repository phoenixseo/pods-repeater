<?php
/**
 * ACF-kompatible Lese-API für Repeater-Felder — Alias-Deklarationen.
 *
 * Diese Datei wird NICHT beim Plugin-Start geladen, sondern erst von
 * `pods_repeater_register_acf_aliases()` per `require_once`. Grund: PHP
 * deklariert Funktionen einer Datei beim Parsen sofort. Läge die
 * Deklaration in derselben Datei wie die Registrierungsfunktion, würden die
 * ACF-Namen beim Plugin-Laden entstehen — vor jeder Prüfung, ob sie frei
 * sind oder ob der Filter sie abschaltet.
 *
 * ## Warum die Reihenfolge überhaupt zählt
 *
 * Pods Core bringt eine eigene ACF-Kompatibilitätsschicht mit:
 * `includes/compatibility/acf.php`, geladen aus
 * `PodsInit::after_setup_theme()` (PodsInit.php:467, nur außerhalb von
 * `is_admin()` und nur bei `PODS_COMPATIBILITY`). Diese Datei deklariert
 * Stubs, die IMMER `false` liefern:
 *
 *     have_rows()     → false
 *     get_sub_field() → false
 *     the_sub_field() → null
 *
 * `the_row()` fehlt dort. Der Shim hängt an `after_setup_theme`, läuft also
 * VOR `init`. Eine Registrierung auf `init` fände die Namen belegt vor und
 * würde sie mit `function_exists()`-Guard überspringen — die ACF-Namen
 * blieben Pods' tote Stubs und ein ACF-Template iterierte nie.
 *
 * Deshalb hängt die Registrierung an `muplugins_loaded` (Priorität 1) und
 * diese Datei wird dort geladen: Pods' Shim (Plugin-Ebene) ist zu diesem
 * Zeitpunkt noch nicht geladen, seine `function_exists()`-Guards lassen die
 * Stubs also fallen. Läuft ACF selbst, hat es seine Funktionen früher
 * deklariert — derselbe Guard hier schützt sie vor Überschreiben.
 *
 * Wird der Aliasname von einem anderen Plugin belegt, bleibt es bei
 * dessen Funktion. Wir überschreiben grundsätzlich nichts.
 *
 * @package PodsRepeater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'have_rows' ) ) {
	/**
	 * ACF-kompatibel: startet bzw. rückt die Iteration über ein
	 * Repeater-Feld vor.
	 *
	 * @param string   $field Feldname.
	 * @param int|null $id    Item-ID (optional).
	 * @param string   $pod   Pod-Name (optional).
	 *
	 * @return bool True, solange eine aktuelle Zeile existiert.
	 */
	function have_rows( $field = '', $id = null, $pod = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- ACF-kompatibler Aliasname, bewusst global.
		return pods_repeater_have_rows( $field, $id, $pod );
	}
}

if ( ! function_exists( 'the_row' ) ) {
	/**
	 * ACF-kompatibel: setzt den Zeiger auf die aktuelle Zeile.
	 *
	 * @return void
	 */
	function the_row() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- ACF-kompatibler Aliasname, bewusst global.
		pods_repeater_the_row();
	}
}

if ( ! function_exists( 'get_sub_field' ) ) {
	/**
	 * ACF-kompatibel: Wert eines Sub-Felds der aktuellen Zeile.
	 *
	 * @param string $name Name des Sub-Felds.
	 *
	 * @return mixed Wert oder null.
	 */
	function get_sub_field( $name = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- ACF-kompatibler Aliasname, bewusst global.
		return pods_repeater_get_sub_field( $name );
	}
}
