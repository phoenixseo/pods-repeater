<?php
/**
 * Deinstallation: räumt auf — aber nur, wenn der Betreiber es verlangt.
 *
 * WordPress ruft diese Datei beim Löschen des Plugins über die
 * Plugins-Seite auf (nicht beim bloßen Deaktivieren). Sie liegt bewusst
 * außerhalb der normalen Ladereihenfolge: der Plugin-Bootstrap läuft hier
 * NICHT, keine Konstante, keine Klasse, keine der übrigen Funktionen ist
 * vorhanden. Deshalb bringt die Datei alles mit, was sie braucht.
 *
 * Aufgeräumt werden ausschließlich die beiden Optionen, die das Plugin
 * selbst anlegt (siehe includes/field-groups.php):
 *
 *   - pods_repeater_groups          (Feldgruppen-Registry)
 *   - pods_repeater_groups_version  (Schemaversion der Registry)
 *
 * Nicht angefasst werden — in beiden Modi:
 *
 *   - Post-Meta des Repeaters. Die Werte liegen unter Feldnamen, die der
 *     Betreiber in seinen Pods definiert hat. Ein Plugin, das fremde
 *     Felddaten löscht, zerstört Inhalte, die es nicht besitzt; außerdem
 *     ist eine Deinstallation nicht rückholbar.
 *   - Optionen, Tabellen und Meta anderer Plugins (insbesondere Pods).
 *     Sie werden nicht einmal abgefragt.
 *
 * Modi:
 *
 *   Standard                → nichts löschen. Deinstallieren und neu
 *                             installieren behält die Feldgruppen.
 *   PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL = true
 *                           → beide Optionen löschen.
 *
 * Die Konstante wird in wp-config.php gesetzt, also vor dem Laden der
 * Plugins und damit vor dieser Datei:
 *
 *   define( 'PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL', true );
 *
 * Bewusst nur die Konstante und keine Option: eine Option müsste im
 * Admin gesetzt werden, und genau diese Admin-Seite ist beim Löschen des
 * Plugins nicht mehr erreichbar. Ein Schalter, der nur vor dem letzten
 * Klick erreichbar ist, ist kein Schalter.
 *
 * @package PodsRepeater
 */

// Kein direkter Aufruf.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Nur im echten Deinstallationslauf.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'pods_repeater_uninstall' ) ) {
	/**
	 * Führt das Aufräumen aus.
	 *
	 * Liegt als Funktion vor, damit die Logik testbar ist: der WPUnit-Test
	 * bindet diese Datei ein und ruft die Funktion direkt auf, statt den
	 * Deinstallationslauf von WordPress zu simulieren.
	 *
	 * Idempotent: ein zweiter Aufruf findet nichts mehr und tut nichts.
	 *
	 * @param bool|null $remove_data Daten löschen? Null = Konstante
	 *                               PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL
	 *                               auswerten (Standardverhalten). Ein
	 *                               expliziter bool wird unverändert benutzt
	 *                               und die Konstante nicht gelesen.
	 *
	 * @return bool True, wenn Optionen gelöscht wurden; false, wenn nichts
	 *              angefasst wurde.
	 */
	function pods_repeater_uninstall( $remove_data = null ) {
		if ( null === $remove_data ) {
			$remove_data = defined( 'PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL' )
				&& true === PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL;
		}

		// Standard: nichts tun. Nur der ausdrückliche Wunsch löscht.
		if ( ! $remove_data ) {
			return false;
		}

		// Optionsnamen stehen hier als Literale, nicht über die Konstanten
		// aus includes/field-groups.php: der Bootstrap läuft bei der
		// Deinstallation nicht, die Konstanten existieren dann nicht.
		delete_option( 'pods_repeater_groups' );
		delete_option( 'pods_repeater_groups_version' );

		return true;
	}
}

pods_repeater_uninstall();
