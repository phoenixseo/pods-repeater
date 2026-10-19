<?php
/**
 * Feldgruppen-Registry (Datenebene + API).
 *
 * Eine Feldgruppe ist eine benannte, geordnete Liste von Sub-Feld-Definitionen
 * (Name, Label, Typ, required, options) — exakt das Format, das der
 * Repeater-Feldtyp in der Feld-Option `repeater_sub_fields` als JSON speichert.
 * Damit kann ein Repeater-Feld später statt handgeschriebenem JSON eine
 * Gruppe referenzieren, und die SDC-Komponenten lassen sich als Gruppen
 * definieren.
 *
 * Speicher: eine WP-Option `pods_repeater_groups` (assoziatives Array
 * slug => array( label, fields )) plus `pods_repeater_groups_version` (int)
 * für spätere Migrationen. Bewusst keine eigene Tabelle und kein CPT:
 * Gruppen sind Konfiguration, kein Nutzdatenvolumen; Optionen sind
 * exportierbar und brauchen keine Migration.
 *
 * @package PodsRepeater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optionsname der Gruppen-Registry.
 */
const PODS_REPEATER_GROUPS_OPTION = 'pods_repeater_groups';

/**
 * Optionsname der Registry-Schemaversion.
 */
const PODS_REPEATER_GROUPS_VERSION_OPTION = 'pods_repeater_groups_version';

/**
 * Schemaversion der Registry (Start 1).
 */
const PODS_REPEATER_GROUPS_SCHEMA_VERSION = 1;

/**
 * Liefert die Feldtypen, die als Repeater-Sub-Feld nativ rendern.
 *
 * Die Liste ist verifiziert (19.10.2026) gegen den echten Produktiv-Pfad
 * PodsField_Repeater::render_sub_field_input(): jeder Typ rendert dort ein
 * Eingabeelement mit name="<feld>[<idx>][<sub>]" und ohne DFV-React-Mount
 * (Spike tests/spike-type-whitelist.php + spike-type-whitelist-extra.php).
 *
 * Bewusst NICHT enthalten:
 * - url:   kein Pods-Feldtyp (Pods nennt ihn 'website'); ui/fields/url.php
 *          existiert nicht, der Name fällt in den DFV-Fallback (Spike: kein
 *          name-Attribut, pods-dfv-field-data vorhanden). Ersatz: 'website'.
 * - select/radio/checkbox: eigener Pods-Typ 'pick' (rendert mit data nativ,
 *          ohne data nur ein Hinweis-Absatz).
 * - heading/html: display-only, kein speicherbarer Wert.
 * - repeater:     self-referenziell (verschachtelte Repeater abgewiesen).
 * - avatar/file:  erfordern Upload-Rechte (current_user_can('upload_files')),
 *                 im Repeater-Kontext nicht sinnvoll.
 *
 * 'textarea' und 'paragraph' rendern beide nativ: 'paragraph' ist der
 * Pods-Katalogtyp (eigene Feldklasse), 'textarea' läuft über Pods'
 * ui/fields/textarea.php-Template (Fallback in render_sub_field_input()).
 *
 * @return array Liste der Typnamen.
 */
function pods_repeater_supported_sub_types() {
	$types = array(
		'text',
		'textarea',
		'paragraph',
		'number',
		'website',
		'email',
		'password',
		'wysiwyg',
		'code',
		'date',
		'time',
		'datetime',
		'color',
		'boolean',
		'pick',
	);

	/**
	 * Filtert die als Repeater-Sub-Feld erlaubten Feldtypen.
	 *
	 * @param array $types Typnamen (Pods-Feldtyp-Kennungen).
	 */
	$types = apply_filters( 'pods_repeater_supported_sub_types', $types );

	if ( ! is_array( $types ) ) {
		return array();
	}

	return array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );
}

/**
 * Liest die Gruppen-Registry.
 *
 * Kaputte Optionswerte (String, null, Liste statt Map) liefern ein leeres
 * Array — ohne Notice/Warnung. Einträge, die keine Map mit 'fields' sind,
 * werden verworfen.
 *
 * @return array Gruppen, slug => array( 'label' => string, 'fields' => array ).
 */
function pods_repeater_get_groups() {
	$stored = get_option( PODS_REPEATER_GROUPS_OPTION, array() );

	if ( ! is_array( $stored ) ) {
		return array();
	}

	$groups = array();

	foreach ( $stored as $slug => $group ) {
		if ( ! is_string( $slug ) || '' === $slug || ! is_array( $group ) ) {
			continue;
		}

		$groups[ $slug ] = array(
			'label'  => isset( $group['label'] ) && is_scalar( $group['label'] ) ? (string) $group['label'] : $slug,
			'fields' => isset( $group['fields'] ) && is_array( $group['fields'] ) ? array_values( $group['fields'] ) : array(),
		);
	}

	return $groups;
}

/**
 * Liefert eine einzelne Gruppe.
 *
 * @param string $slug Gruppen-Slug.
 *
 * @return array|null Gruppe oder null, wenn unbekannt.
 */
function pods_repeater_get_group( $slug ) {
	$slug = pods_repeater_normalize_group_slug( $slug );

	if ( '' === $slug ) {
		return null;
	}

	$groups = pods_repeater_get_groups();

	return isset( $groups[ $slug ] ) ? $groups[ $slug ] : null;
}

/**
 * Liefert die Feldliste einer Gruppe.
 *
 * @param string $slug Gruppen-Slug.
 *
 * @return array Felder (leer, wenn Gruppe unbekannt oder ohne Felder).
 */
function pods_repeater_get_group_fields( $slug ) {
	$group = pods_repeater_get_group( $slug );

	return ( null !== $group && isset( $group['fields'] ) && is_array( $group['fields'] ) ) ? $group['fields'] : array();
}

/**
 * Legt eine Feldgruppe an oder aktualisiert eine bestehende.
 *
 * Erwartete Args:
 *   - label:  Pflicht, wird per sanitize_text_field() bereinigt.
 *   - slug:   optional; sonst aus dem Label via sanitize_title().
 *   - fields: Liste von Feld-Definitionen (leer erlaubt = Entwurf).
 *
 * Jedes Feld: name (Pflicht, sanitize_key), label (optional, sanitize_text_field),
 * type (Default 'text', muss in supported_sub_types() stehen), required (bool),
 * options (Array, sonst verworfen). Alle übrigen Keys bleiben erhalten (freie
 * Options-Map wie im Repeater-JSON), werden aber nicht validiert.
 *
 * @param array $args Gruppen-Argumente.
 *
 * @return string|WP_Error Slug bei Erfolg.
 */
function pods_repeater_save_group( $args ) {
	if ( ! is_array( $args ) ) {
		return new WP_Error( 'pods_repeater_invalid_args', __( 'Group arguments must be an array.', 'pods-repeater' ), array( 'status' => 400 ) );
	}

	$label = isset( $args['label'] ) && is_scalar( $args['label'] ) ? sanitize_text_field( (string) $args['label'] ) : '';

	if ( '' === $label ) {
		return new WP_Error( 'pods_repeater_missing_label', __( 'A field group needs a label.', 'pods-repeater' ) );
	}

	// Slug: explizit übergeben oder aus dem Label ableiten.
	$slug_source = isset( $args['slug'] ) && is_scalar( $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : '';
	$slug        = '' !== $slug_source ? $slug_source : sanitize_title( $label );

	if ( '' === $slug ) {
		$slug = sanitize_key( $label );
	}

	if ( '' === $slug ) {
		return new WP_Error( 'pods_repeater_missing_slug', __( 'Could not derive a slug from the group label.', 'pods-repeater' ) );
	}

	$fields_in = isset( $args['fields'] ) && is_array( $args['fields'] ) ? $args['fields'] : array();
	$fields    = array();
	$seen      = array();
	$allowed   = pods_repeater_supported_sub_types();

	foreach ( $fields_in as $field ) {
		if ( ! is_array( $field ) ) {
			return new WP_Error(
				'pods_repeater_missing_field_name',
				__( 'Every field in a group needs a name.', 'pods-repeater' ),
				array( 'field' => $field )
			);
		}

		$name = isset( $field['name'] ) && is_scalar( $field['name'] ) ? sanitize_key( (string) $field['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error(
				'pods_repeater_missing_field_name',
				__( 'Every field in a group needs a name.', 'pods-repeater' ),
				array( 'field' => $field )
			);
		}

		if ( isset( $seen[ $name ] ) ) {
			return new WP_Error(
				'pods_repeater_duplicate_field',
				/* translators: %s: field name. */
				sprintf( __( 'Duplicate field name "%s" in this group.', 'pods-repeater' ), $name ),
				array( 'field' => $name )
			);
		}

		$type = isset( $field['type'] ) && is_scalar( $field['type'] ) ? (string) $field['type'] : 'text';

		if ( ! in_array( $type, $allowed, true ) ) {
			return new WP_Error(
				'pods_repeater_invalid_type',
				/* translators: 1: field name, 2: field type. */
				sprintf( __( 'Field "%1$s" uses the unsupported type "%2$s".', 'pods-repeater' ), $name, $type ),
				array(
					'field' => $name,
					'type'  => $type,
				)
			);
		}

		$clean = array(
			'name'     => $name,
			'label'    => isset( $field['label'] ) && is_scalar( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '',
			'type'     => $type,
			'required' => ! empty( $field['required'] ),
			'options'  => isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array(),
		);

		// Freie Options-Map (wie im Repeater-JSON): zusätzliche Keys erhalten,
		// Kern-Keys dürfen nicht überschrieben werden.
		foreach ( $field as $key => $value ) {
			if ( ! is_string( $key ) || array_key_exists( $key, $clean ) ) {
				continue;
			}

			$clean[ $key ] = $value;
		}

		$seen[ $name ] = true;
		$fields[]      = $clean;
	}

	$groups = pods_repeater_get_groups();

	// Bestehender Slug wird aktualisiert, nicht dupliziert.
	$groups[ $slug ] = array(
		'label'  => $label,
		'fields' => $fields,
	);

	update_option( PODS_REPEATER_GROUPS_OPTION, $groups, false );
	pods_repeater_touch_groups_version();

	return $slug;
}

/**
 * Löscht eine Feldgruppe.
 *
 * @param string $slug Gruppen-Slug.
 *
 * @return bool True, wenn eine Gruppe entfernt wurde.
 */
function pods_repeater_delete_group( $slug ) {
	$slug = pods_repeater_normalize_group_slug( $slug );

	if ( '' === $slug ) {
		return false;
	}

	$groups = pods_repeater_get_groups();

	if ( ! isset( $groups[ $slug ] ) ) {
		return false;
	}

	unset( $groups[ $slug ] );

	update_option( PODS_REPEATER_GROUPS_OPTION, $groups, false );
	pods_repeater_touch_groups_version();

	return true;
}

/**
 * Normalisiert einen übergebenen Gruppen-Slug.
 *
 * @param mixed $slug Roher Slug.
 *
 * @return string Normalisierter Slug oder ''.
 */
function pods_repeater_normalize_group_slug( $slug ) {
	if ( ! is_scalar( $slug ) ) {
		return '';
	}

	$slug = (string) $slug;

	if ( '' === $slug ) {
		return '';
	}

	$normalized = sanitize_title( $slug );

	return '' !== $normalized ? $normalized : sanitize_key( $slug );
}

/**
 * Setzt die Registry-Schemaversion und erhöht sie bei Änderungen.
 *
 * Startwert ist PODS_REPEATER_GROUPS_SCHEMA_VERSION; jede Änderung an der
 * Registry erhöht die gespeicherte Version um eins (Migrationszähler).
 *
 * @return int Neue Version.
 */
function pods_repeater_touch_groups_version() {
	$current = (int) get_option( PODS_REPEATER_GROUPS_VERSION_OPTION, 0 );
	$next    = max( PODS_REPEATER_GROUPS_SCHEMA_VERSION, $current + 1 );

	update_option( PODS_REPEATER_GROUPS_VERSION_OPTION, $next, false );

	return $next;
}

/**
 * Liefert die gespeicherte Registry-Schemaversion.
 *
 * @return int Version (0, wenn noch nie geschrieben).
 */
function pods_repeater_get_groups_version() {
	return (int) get_option( PODS_REPEATER_GROUPS_VERSION_OPTION, 0 );
}
