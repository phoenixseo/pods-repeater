<?php
/**
 * Plugin Name: Pods Repeater Field
 * Plugin URI:  https://github.com/phoenixseo/pods-repeater
 * Description: Repeatable field groups (ACF-Repeater equivalent) for Pods. Generic, community-first: SDC Page Builder is one consumer among many.
 * Version:     0.1.0
 * Author:      PHOENIXSEO
 * Author URI:  https://phoenixseo.de
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pods-repeater
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: pods
 *
 * @package PodsRepeater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PODS_REPEATER_VERSION', '0.1.0' );
define( 'PODS_REPEATER_DIR', plugin_dir_path( __FILE__ ) );
define( 'PODS_REPEATER_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bootstrap: register the custom field type once Pods is loaded.
 *
 * Registration goes through the official Pods API (PodsForm::register_field_type)
 * resp. the pods_form_field_include filter — no fork, no Pods core changes.
 */
function pods_repeater_init() {
	if ( ! class_exists( 'PodsForm' ) ) {
		return; // Pods not active yet.
	}

	// Register the 'repeater' field type. The class file is loaded via the
	// pods_form_field_include filter (see field_loader()).
	PodsForm::register_field_type( 'repeater', PODS_REPEATER_DIR . 'classes/field-repeater.php' );

	// Pods loads field types lazily (first field render). If a field_loader()
	// call for 'repeater' happened before this file was included (e.g. a Pod
	// referencing the type during an earlier boot phase), $loaded['repeater']
	// silently holds a base PodsField instance whose input() ignores
	// disable_dfv and always renders DFV. Ensure the real class is loaded and
	// force $loaded['repeater'] to it — field_loader()'s early return (see
	// PodsForm.php field_loader()) would otherwise keep serving the base class.
	if ( ! class_exists( 'PodsField_Repeater' ) ) {
		include_once PODS_REPEATER_DIR . 'classes/field-repeater.php';
	}

	if ( class_exists( 'PodsField_Repeater' ) ) {
		$loaded = PodsForm::field_loader( 'repeater', PODS_REPEATER_DIR . 'classes/field-repeater.php' );

		if ( ! $loaded instanceof PodsField_Repeater ) {
			// Replace the cached base-class instance with the real field class.
			PodsForm::$loaded['repeater'] = new PodsField_Repeater();
		}
	}
}
add_action( 'plugins_loaded', 'pods_repeater_init' );
