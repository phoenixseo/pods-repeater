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
}
add_action( 'plugins_loaded', 'pods_repeater_init' );
