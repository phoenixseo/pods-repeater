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
 * Domain Path: /languages
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

// Feldgruppen-Registry (Datenebene + API): wiederverwendbare Gruppen aus
// Slug, Label und geordneter Feldliste. Unabhängig von Pods ladbar.
require_once PODS_REPEATER_DIR . 'includes/field-groups.php';

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
	PodsForm::register_field_type( 'repeater', PODS_REPEATER_DIR . 'classes/fields/repeater.php' );

	// Pods loads field types lazily (first field render). If a field_loader()
	// call for 'repeater' happened before this file was included (e.g. a Pod
	// referencing the type during an earlier boot phase), $loaded['repeater']
	// silently holds a base PodsField instance whose input() ignores
	// disable_dfv and always renders DFV. Ensure the real class is loaded and
	// force $loaded['repeater'] to it — field_loader()'s early return (see
	// PodsForm.php field_loader()) would otherwise keep serving the base class.
	if ( ! class_exists( 'PodsField_Repeater' ) ) {
		include_once PODS_REPEATER_DIR . 'classes/fields/repeater.php';
	}

	if ( class_exists( 'PodsField_Repeater' ) ) {
		$loaded = PodsForm::field_loader( 'repeater', PODS_REPEATER_DIR . 'classes/fields/repeater.php' );

		if ( ! $loaded instanceof PodsField_Repeater ) {
			// Replace the cached base-class instance with the real field class.
			PodsForm::$loaded['repeater'] = new PodsField_Repeater();
		}
	}
}
add_action( 'plugins_loaded', 'pods_repeater_init' );

/**
 * Add a custom field option (editor container) to the Repeater field type's
 * options tab in the Pods field editor (admin.php?page=pods&action=edit).
 *
 * The Pods field editor builds the per-type "Additional Field Options" schema
 * in src/Pods/Admin/Config/Field.php (get_fields()). For every registered
 * field type it fires the type-specific filter
 * 'pods_admin_setup_edit_{$type}_additional_field_options' (Field.php:678).
 * We append an 'html' option to that schema: DFV renders an 'html' option as a
 * raw div (ui/js/dfv/src/fields/html-field/index.js) via dangerouslySetInnerHTML
 * with class "pods-form-ui-html-{name}" — no React involvement needed.
 *
 * The option is intentionally STATIC (schema is built once per pod, not per
 * field instance, and carries no field value). It renders a container that the
 * client-side script (ui/js/repeater.js) fills with the actual sub-field
 * manager UI when the field dialog opens (it reads the repeater_sub_fields
 * textarea, shows a row list with add/edit/remove, and writes changes back
 * into that textarea, which is saved to post meta as before).
 *
 * @param array                 $type_options Additional field type options (repeater).
 * @param string                $type         Field type.
 * @param array                 $options      Tabs, indexed by label.
 * @param null|Pods\Whatsit\Pod $pod          Pods object.
 *
 * @return array
 */
function pods_repeater_add_subfield_editor_option( $type_options, $type, $options, $pod ) {
	if ( 'repeater' !== $type ) {
		return $type_options;
	}

	// Static container; ui/js/repeater.js renders the manager UI into it.
	$type_options['repeater_sub_fields_editor'] = array(
		'name'         => 'repeater_sub_fields_editor',
		'label'        => __( 'Sub-Fields', 'pods-repeater' ),
		'help'         => __( 'Manage the sub-fields of this repeater group.', 'pods-repeater' ),
		'type'         => 'html',
		'html_content' => '<div id="pods-repeater-subfield-editor" class="pods-repeater-subfield-editor" data-editor="1"><p class="description">' . esc_html__( 'Sub-field editor loads when this dialog opens.', 'pods-repeater' ) . '</p></div>',
		'html_wpautop' => 0,
	);

	return $type_options;
}
add_filter( 'pods_admin_setup_edit_repeater_additional_field_options', 'pods_repeater_add_subfield_editor_option', 10, 4 );

/**
 * Translations laden (Text Domain 'pods-repeater').
 *
 * Gebündelte .mo-Dateien liegen in /languages (siehe Domain-Path-Header);
 * wp.org-Sprachpakete übernimmt WordPress automatisch. Hook auf 'init' wie
 * von WP 6.7+ empfohlen (JIT-Warnung „translation loading triggered too
 * early" bei plugins_loaded). Bewusst nicht an Pods gebunden: Die
 * __()-Aufrufe (classes/field-repeater.php) feuern erst beim Feld-Rendering
 * im Admin, also lange nach init.
 */
function pods_repeater_load_textdomain() {
	load_plugin_textdomain( 'pods-repeater', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'pods_repeater_load_textdomain' );

/**
 * Enqueue the repeater admin JS (ui/js/repeater.js).
 *
 * The admin UI is server-side rendered (input()) and driven by this vanilla
 * script (add/move/remove rows). Without enqueueing it, the buttons are inert
 * (Judge-Review 24e355c, Empfehlung 2). admin_enqueue_scripts only fires in
 * the admin; on top of that we limit loading to screens that can host Pods
 * forms (post/user/comment/term editors + Pods admin UI) so the script does
 * not load on every admin page. Registered with a unique handle, no
 * dependencies, loaded in the footer → no conflict with other plugins.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function pods_repeater_enqueue_admin_assets( $hook_suffix ) {
	if ( ! class_exists( 'Pods' ) ) {
		return; // No Pods → no repeater field possible.
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	// Screen unknown (very early hook / CLI context): load conservatively —
	// the hook itself is already the admin boundary.
	if ( $screen && ! pods_repeater_screen_hosts_forms( $screen ) ) {
		return;
	}

	wp_enqueue_script(
		'pods-repeater',
		PODS_REPEATER_URL . 'ui/js/repeater.js',
		array(),
		PODS_REPEATER_VERSION,
		true
	);
}

/**
 * Can this admin screen host Pods forms (meta boxes / Pods UI)?
 *
 * @param WP_Screen $screen Current screen.
 *
 * @return bool
 */
function pods_repeater_screen_hosts_forms( $screen ) {
	if ( ! $screen instanceof WP_Screen ) {
		return false;
	}

	// Pods admin (UI / Settings / editor pages). Pods registers its admin
	// pages as a top-level menu with sub-pages; on those screens WP_Screen's
	// parent_base is NULL (the page IS the top level), so checking the
	// request's 'page' parameter (set by add_menu_page/add_submenu_page
	// handling) is the reliable marker — covers page=pods (list), action=edit
	// (field editor, hosts the repeater options dialog) and settings.
	// Read-only screen detection: no state change, so no nonce required.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( 'pods' === $page ) {
		return true;
	}

	// Editor screens where Pods meta boxes / fields can appear.
	return in_array(
		$screen->base,
		array( 'post', 'user-edit', 'profile', 'comment', 'term', 'edit-tags' ),
		true
	);
}
add_action( 'admin_enqueue_scripts', 'pods_repeater_enqueue_admin_assets' );
