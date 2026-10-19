<?php
/**
 * Admin-Seite zur Verwaltung der Feldgruppen.
 *
 * Oberfläche zur Registry aus includes/field-groups.php: Liste, Formular,
 * Anlegen/Bearbeiten und Löschen — ohne JSON-Handarbeit.
 *
 * Aufbau bewusst trennbar: die Render- und Handler-Funktionen bekommen ihre
 * Eingaben als Parameter und geben Strings bzw. WP_Error zurück, damit sie
 * ohne HTTP-Pipeline testbar sind. Die admin_post_-Wrapper machen nur Nonce-
 * Dispatch, Redirect und exit; die eigentliche Prüfung (Capability, Nonce,
 * Sanitizing) liegt in den Handlern selbst.
 *
 * @package PodsRepeater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-Seite der Feldgruppen (Submenü von Pods).
 */
const PODS_REPEATER_GROUPS_PAGE = 'pods-repeater-groups';

/**
 * Nonce-Action für Speichern und Löschen.
 */
const PODS_REPEATER_GROUP_NONCE_ACTION = 'pods_repeater_group_nonce';

/**
 * Formularfeld der Nonce.
 */
const PODS_REPEATER_GROUP_NONCE_FIELD = 'pods_repeater_group_nonce';

/**
 * Liefert die Capability für die Feldgruppen-Verwaltung.
 *
 * Pods' eigene Capability (`pods_manage`) existiert erst, wenn die Rollen
 * von Pods eingerichtet wurden. Auf Installationen, auf denen sie fehlt,
 * fällt die Seite auf manage_options zurück — sonst wäre sie für
 * Administratoren unsichtbar und unbenutzbar.
 *
 * @return string Capability-Name.
 */
function pods_repeater_admin_capability() {
	$capability = 'pods_manage';

	if ( ! pods_repeater_admin_capability_exists( $capability ) ) {
		$capability = 'manage_options';
	}

	/**
	 * Filtert die Capability der Feldgruppen-Verwaltung.
	 *
	 * @param string $capability Capability-Name.
	 */
	return (string) apply_filters( 'pods_repeater_admin_capability', $capability );
}

/**
 * Existiert eine Capability auf dieser Installation?
 *
 * Gefragt wird nicht der aktuelle Benutzer, sondern die Rollen-Tabelle:
 * eine Capability, die keine Rolle besitzt, gilt als nicht vorhanden.
 *
 * @param string $capability Capability-Name.
 *
 * @return bool
 */
function pods_repeater_admin_capability_exists( $capability ) {
	$capability = (string) $capability;

	if ( '' === $capability || ! function_exists( 'wp_roles' ) ) {
		return false;
	}

	$roles = wp_roles();

	if ( ! $roles || ! is_array( $roles->roles ) ) {
		return false;
	}

	foreach ( $roles->roles as $role ) {
		if ( isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) && ! empty( $role['capabilities'][ $capability ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Darf der aktuelle Benutzer Feldgruppen verwalten?
 *
 * @return bool
 */
function pods_repeater_admin_can_manage() {
	return current_user_can( pods_repeater_admin_capability() );
}

/**
 * Registriert das Submenü unter Pods.
 *
 * @return void
 */
function pods_repeater_admin_register_menu() {
	add_submenu_page(
		'pods',
		__( 'Field Groups', 'pods-repeater' ),
		__( 'Field Groups', 'pods-repeater' ),
		pods_repeater_admin_capability(),
		PODS_REPEATER_GROUPS_PAGE,
		'pods_repeater_admin_render_page',
		null
	);
}
add_action( 'admin_menu', 'pods_repeater_admin_register_menu' );

/**
 * Cron-/Admin-Kontext: assets nur auf der eigenen Seite laden.
 *
 * @param string $hook_suffix Aktueller Admin-Hook.
 *
 * @return void
 */
function pods_repeater_admin_enqueue_assets( $hook_suffix ) {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Seiten-Erkennung, keine Zustandsänderung.

	// Hook-Suffix prüfen (zuverlässig), Fallback auf page= für Seiten, die
	// vor der Menü-Registrierung gerendert werden.
	if ( false === strpos( (string) $hook_suffix, PODS_REPEATER_GROUPS_PAGE ) && PODS_REPEATER_GROUPS_PAGE !== $page ) {
		return;
	}

	wp_enqueue_script(
		'pods-repeater-field-groups',
		PODS_REPEATER_URL . 'ui/js/field-groups.js',
		array(),
		PODS_REPEATER_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'pods_repeater_admin_enqueue_assets' );

/**
 * Einstiegspunkt der Seite: Liste oder Formular.
 *
 * @return void
 */
function pods_repeater_admin_render_page() {
	if ( ! pods_repeater_admin_can_manage() ) {
		wp_die( esc_html__( 'You are not allowed to manage field groups.', 'pods-repeater' ), 403 );
	}

	// Read-only Navigation: der Slug wählt nur die Ansicht.
	$slug = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Ansichtswahl, keine Zustandsänderung.
	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Ansichtswahl, keine Zustandsänderung.

	$is_form = ( 'new' === $view || '' !== $slug );

	echo '<div class="wrap pods-repeater-field-groups">';

	if ( $is_form ) {
		printf(
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">',
			esc_html( '' !== $slug ? __( 'Edit Field Group', 'pods-repeater' ) : __( 'Add Field Group', 'pods-repeater' ) ),
			esc_url( pods_repeater_admin_page_url() ),
			esc_html__( 'Back to list', 'pods-repeater' )
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_form() escaped jede Ausgabe selbst.
		echo pods_repeater_admin_render_form( $slug );
	} else {
		printf(
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">',
			esc_html__( 'Field Groups', 'pods-repeater' ),
			esc_url( pods_repeater_admin_page_url( array( 'view' => 'new' ) ) ),
			esc_html__( 'Add Field Group', 'pods-repeater' )
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_list() escaped jede Ausgabe selbst.
		echo pods_repeater_admin_render_list();
	}

	echo '</div>';
}

/**
 * URL der eigenen Seite.
 *
 * @param array $args Zusätzliche Query-Argumente.
 *
 * @return string Absolute Admin-URL.
 */
function pods_repeater_admin_page_url( $args = array() ) {
	$args = is_array( $args ) ? $args : array();

	return add_query_arg(
		array_merge( array( 'page' => PODS_REPEATER_GROUPS_PAGE ), $args ),
		admin_url( 'admin.php' )
	);
}

/**
 * Baut das Lösch-Ziel (admin-post) für einen Slug.
 *
 * @param string $slug Gruppen-Slug.
 *
 * @return string URL mit Nonce.
 */
function pods_repeater_admin_delete_url( $slug ) {
	$url = add_query_arg(
		array(
			'action' => 'pods_repeater_group_delete',
			'slug'   => (string) $slug,
		),
		admin_url( 'admin-post.php' )
	);

	return wp_nonce_url( $url, PODS_REPEATER_GROUP_NONCE_ACTION );
}

/**
 * Rendert die Liste aller Feldgruppen.
 *
 * @return string HTML.
 */
function pods_repeater_admin_render_list() {
	$groups = pods_repeater_get_groups();

	$notice = pods_repeater_admin_take_notice();
	$html   = '';

	if ( '' !== $notice ) {
		$html .= $notice;
	}

	if ( array() === $groups ) {
		$html .= '<p>' . esc_html__( 'No field groups yet.', 'pods-repeater' ) . '</p>';
		$html .= '<p><a class="button" href="' . esc_url( pods_repeater_admin_page_url( array( 'view' => 'new' ) ) ) . '">' . esc_html__( 'Add Field Group', 'pods-repeater' ) . '</a></p>';

		return $html;
	}

	$html .= '<table class="wp-list-table widefat fixed striped"><thead><tr>';
	$html .= '<th scope="col">' . esc_html__( 'Slug', 'pods-repeater' ) . '</th>';
	$html .= '<th scope="col">' . esc_html__( 'Label', 'pods-repeater' ) . '</th>';
	$html .= '<th scope="col">' . esc_html__( 'Fields', 'pods-repeater' ) . '</th>';
	$html .= '<th scope="col">' . esc_html__( 'Actions', 'pods-repeater' ) . '</th>';
	$html .= '</tr></thead><tbody>';

	foreach ( $groups as $slug => $group ) {
		$fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array();
		$label  = isset( $group['label'] ) ? (string) $group['label'] : $slug;

		$edit_url   = pods_repeater_admin_page_url( array( 'slug' => $slug ) );
		$delete_url = pods_repeater_admin_delete_url( $slug );

		$html .= '<tr>';
		$html .= '<td><code>' . esc_html( (string) $slug ) . '</code></td>';
		$html .= '<td>' . esc_html( $label ) . '</td>';
		$html .= '<td>' . esc_html( (string) count( $fields ) ) . '</td>';
		$html .= '<td>';
		$html .= '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'pods-repeater' ) . '</a>';
		$html .= ' | ';
		$html .= '<a class="pods-repeater-group-delete submitdelete" href="' . esc_url( $delete_url ) . '" data-label="' . esc_attr( $label ) . '">' . esc_html__( 'Delete', 'pods-repeater' ) . '</a>';
		$html .= '</td>';
		$html .= '</tr>';
	}

	$html .= '</tbody></table>';

	return $html;
}

/**
 * Rendert das Formular für eine Gruppe (leerer Slug = neue Gruppe).
 *
 * @param string $slug Gruppen-Slug; '' für eine neue Gruppe.
 *
 * @return string HTML.
 */
function pods_repeater_admin_render_form( $slug = '' ) {
	$slug   = (string) $slug;
	$group  = '' !== $slug ? pods_repeater_get_group( $slug ) : null;
	$fields = ( is_array( $group ) && isset( $group['fields'] ) && is_array( $group['fields'] ) ) ? $group['fields'] : array();
	$label  = ( is_array( $group ) && isset( $group['label'] ) ) ? (string) $group['label'] : '';
	$action = pods_repeater_admin_page_url( array( 'slug' => $slug ) );

	ob_start();

	$notice = pods_repeater_admin_take_notice( true );

	if ( '' !== $notice ) {
		echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bereits escaped in pods_repeater_admin_notice().
	}

	?>
	<form method="post" action="<?php echo esc_url( $action ); ?>" class="pods-repeater-group-form">
		<input type="hidden" name="pods_repeater_group_action" value="save" />
		<input type="hidden" name="pods_repeater_group_original_slug" value="<?php echo esc_attr( $slug ); ?>" />
		<?php wp_nonce_field( PODS_REPEATER_GROUP_NONCE_ACTION, PODS_REPEATER_GROUP_NONCE_FIELD ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pods-repeater-group-label"><?php esc_html_e( 'Label', 'pods-repeater' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="pods-repeater-group-label" name="label" value="<?php echo esc_attr( $label ); ?>" required="required" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pods-repeater-group-slug"><?php esc_html_e( 'Slug', 'pods-repeater' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="pods-repeater-group-slug" name="slug" value="<?php echo esc_attr( $slug ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. Derived from the label when left empty.', 'pods-repeater' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Fields', 'pods-repeater' ); ?></h2>

		<table class="wp-list-table widefat fixed striped pods-repeater-group-fields">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'pods-repeater' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Label', 'pods-repeater' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'pods-repeater' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Required', 'pods-repeater' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Remove', 'pods-repeater' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				if ( array() === $fields ) {
					echo pods_repeater_admin_render_field_row( 0, array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in der Zeile.
				} else {
					$index = 0;

					foreach ( $fields as $field ) {
						echo pods_repeater_admin_render_field_row( $index, is_array( $field ) ? $field : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in der Zeile.
						++$index;
					}
				}
				?>
			</tbody>
		</table>

		<p>
			<button type="button" class="button pods-repeater-group-add-field"><?php esc_html_e( 'Add field', 'pods-repeater' ); ?></button>
		</p>

		<?php submit_button( '' !== $slug ? __( 'Save Changes', 'pods-repeater' ) : __( 'Add Field Group', 'pods-repeater' ) ); ?>
	</form>
	<?php

	return (string) ob_get_clean();
}

/**
 * Rendert eine Zeile der Feld-Tabelle.
 *
 * Feldnamen sind tabellarisch: name[][] statt Index, damit die Reihenfolge
 * aus dem DOM folgt und keine Lücken entstehen — die Spalten eines Eintrags
 * werden über denselben Listenindex zusammengehalten.
 *
 * @param int   $index Listenindex im Formular.
 * @param array $field Feld-Definition (name, label, type, required).
 *
 * @return string HTML.
 */
function pods_repeater_admin_render_field_row( $index, $field ) {
	$index = (int) $index;
	$field = is_array( $field ) ? $field : array();

	$name     = isset( $field['name'] ) && is_scalar( $field['name'] ) ? (string) $field['name'] : '';
	$label    = isset( $field['label'] ) && is_scalar( $field['label'] ) ? (string) $field['label'] : '';
	$type     = isset( $field['type'] ) && is_scalar( $field['type'] ) ? (string) $field['type'] : 'text';
	$required = ! empty( $field['required'] );

	$base = 'pods_repeater_group_fields[' . $index . ']';
	$id   = 'pods-repeater-group-field-' . $index;

	ob_start();
	?>
	<tr class="pods-repeater-group-field-row">
		<td>
			<input type="text" name="<?php echo esc_attr( $base . '[name]' ); ?>" value="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id . '-name' ); ?>" class="pods-repeater-group-field-name" />
		</td>
		<td>
			<input type="text" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" id="<?php echo esc_attr( $id . '-label' ); ?>" class="pods-repeater-group-field-label" />
		</td>
		<td>
			<select name="<?php echo esc_attr( $base . '[type]' ); ?>" id="<?php echo esc_attr( $id . '-type' ); ?>" class="pods-repeater-group-field-type">
				<?php foreach ( pods_repeater_supported_sub_types() as $supported ) : ?>
					<option value="<?php echo esc_attr( $supported ); ?>"<?php echo selected( $supported, $type, false ); ?>><?php echo esc_html( $supported ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
		<td>
			<input type="checkbox" name="<?php echo esc_attr( $base . '[required]' ); ?>" value="1" id="<?php echo esc_attr( $id . '-required' ); ?>" class="pods-repeater-group-field-required"<?php checked( $required ); ?> />
		</td>
		<td>
			<button type="button" class="button-link button-link-delete pods-repeater-group-remove-field"><?php esc_html_e( 'Remove', 'pods-repeater' ); ?></button>
		</td>
	</tr>
	<?php

	return (string) ob_get_clean();
}

/**
 * Liest und verwirft die Redirect-Notice der letzten Aktion.
 *
 * Die Handler leiten mit einem Query-Argument (`pods_repeater_notice`)
 * zurück; der Wert ist eine feste Kennung, kein freier Text.
 *
 * @param bool $form Nur Formular-Notices (Fehler) liefern.
 *
 * @return string HTML oder ''.
 */
function pods_repeater_admin_take_notice( $form = false ) {
	$code = isset( $_GET['pods_repeater_notice'] ) ? sanitize_key( wp_unslash( $_GET['pods_repeater_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Anzeige einer festen Kennung, keine Zustandsänderung.

	if ( '' === $code ) {
		return '';
	}

	$error_codes = array( 'error' );

	if ( $form && ! in_array( $code, $error_codes, true ) ) {
		return '';
	}

	if ( ! $form && in_array( $code, $error_codes, true ) ) {
		return '';
	}

	return pods_repeater_admin_notice( $code );
}

/**
 * Baut einen Admin-Hinweis aus einer festen Kennung.
 *
 * @param string $code Kennung (saved, deleted, error).
 *
 * @return string HTML.
 */
function pods_repeater_admin_notice( $code ) {
	switch ( (string) $code ) {
		case 'saved':
			$class  = 'notice notice-success is-dismissible';
			$text   = __( 'Field group saved.', 'pods-repeater' );
			$bullet = 'saved';
			break;
		case 'deleted':
			$class  = 'notice notice-success is-dismissible';
			$text   = __( 'Field group deleted.', 'pods-repeater' );
			$bullet = 'deleted';
			break;
		case 'error':
		default:
			$class  = 'notice notice-error';
			$text   = __( 'The field group could not be saved.', 'pods-repeater' );
			$bullet = 'error';
			break;
	}

	return '<div class="' . esc_attr( $class ) . '" data-pods-repeater-notice="' . esc_attr( $bullet ) . '"><p>' . esc_html( $text ) . '</p></div>';
}

/**
 * Speichert eine Gruppe aus Formulardaten.
 *
 * Prüft Capability und Nonce selbst; alle Eingaben werden über
 * pods_repeater_save_group() normalisiert (sanitize_*).
 *
 * @param array $post Formulardaten (typisch $_POST).
 *
 * @return string|WP_Error Slug bei Erfolg.
 */
function pods_repeater_admin_handle_save( $post ) {
	if ( ! is_array( $post ) ) {
		return new WP_Error( 'pods_repeater_invalid_request', __( 'Invalid request.', 'pods-repeater' ) );
	}

	if ( ! pods_repeater_admin_can_manage() ) {
		return new WP_Error( 'pods_repeater_forbidden', __( 'You are not allowed to manage field groups.', 'pods-repeater' ) );
	}

	$nonce = isset( $post[ PODS_REPEATER_GROUP_NONCE_FIELD ] ) && is_scalar( $post[ PODS_REPEATER_GROUP_NONCE_FIELD ] )
		? (string) $post[ PODS_REPEATER_GROUP_NONCE_FIELD ]
		: '';

	if ( '' === $nonce || ! wp_verify_nonce( $nonce, PODS_REPEATER_GROUP_NONCE_ACTION ) ) {
		return new WP_Error( 'pods_repeater_invalid_nonce', __( 'Security check failed. Please reload the page and try again.', 'pods-repeater' ) );
	}

	$label = isset( $post['label'] ) && is_scalar( $post['label'] ) ? (string) $post['label'] : '';
	$slug  = isset( $post['slug'] ) && is_scalar( $post['slug'] ) ? (string) $post['slug'] : '';

	$rows = isset( $post['pods_repeater_group_fields'] ) && is_array( $post['pods_repeater_group_fields'] )
		? $post['pods_repeater_group_fields']
		: array();

	$fields = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$name = isset( $row['name'] ) && is_scalar( $row['name'] ) ? trim( (string) $row['name'] ) : '';

		// Leere Zeilen sind Reste des Formulars ("Feld hinzufügen" ohne
		// Eingabe), keine Felder. Eine Zeile mit Namen aber ungültigen
		// Angaben muss dagegen im Save scheitern.
		if ( '' === $name ) {
			continue;
		}

		$field = array(
			'name'     => $name,
			'label'    => isset( $row['label'] ) && is_scalar( $row['label'] ) ? (string) $row['label'] : '',
			'type'     => isset( $row['type'] ) && is_scalar( $row['type'] ) ? (string) $row['type'] : 'text',
			'required' => ! empty( $row['required'] ),
			'options'  => array(),
		);

		// Optionen einer bestehenden Gruppe übernehmen: das Formular
		// bearbeitet sie nicht, ein Save darf sie nicht verlieren.
		$fields[] = $field;
	}

	$args = array(
		'label'  => $label,
		'slug'   => $slug,
		'fields' => $fields,
	);

	$result = pods_repeater_save_group( $args );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Nicht editierte Zusatz-Keys (options, placeholder, ...) der bereits
	// bestehenden Felder wieder herstellen.
	$existing_slug = '' !== $slug ? pods_repeater_normalize_group_slug( $slug ) : $result;
	$existing      = '' !== $existing_slug ? pods_repeater_get_group( $existing_slug ) : null;

	if ( is_array( $existing ) && ! empty( $existing['fields'] ) ) {
		$merged = pods_repeater_admin_merge_field_extras( $fields, $existing['fields'] );

		if ( $merged !== $fields ) {
			$result = pods_repeater_save_group(
				array(
					'label'  => $label,
					'slug'   => $result,
					'fields' => $merged,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
	}

	return $result;
}

/**
 * Übernimmt nicht editierte Keys bestehender Felder in die Formularfelder.
 *
 * Das Formular kennt nur name, label, type, required. Alles andere
 * (options-Map, placeholder, …) wird aus dem gespeicherten Feld mit
 * gleichem Namen übernommen, damit ein Edit keine Daten verliert.
 *
 * @param array $fields   Felder aus dem Formular.
 * @param array $existing Gespeicherte Felder der Gruppe.
 *
 * @return array Felder mit übernommenen Zusatz-Keys.
 */
function pods_repeater_admin_merge_field_extras( $fields, $existing ) {
	$by_name = array();

	foreach ( (array) $existing as $saved ) {
		if ( ! is_array( $saved ) || ! isset( $saved['name'] ) || ! is_scalar( $saved['name'] ) ) {
			continue;
		}

		$by_name[ (string) $saved['name'] ] = $saved;
	}

	$merged = array();

	foreach ( (array) $fields as $field ) {
		if ( ! is_array( $field ) || ! isset( $field['name'] ) || ! is_scalar( $field['name'] ) ) {
			continue;
		}

		$name = (string) $field['name'];

		if ( isset( $by_name[ $name ] ) ) {
			foreach ( $by_name[ $name ] as $key => $value ) {
				if ( ! is_string( $key ) || array_key_exists( $key, $field ) ) {
					continue;
				}

				$field[ $key ] = $value;
			}
		}

		$merged[] = $field;
	}

	return $merged;
}

/**
 * Löscht eine Gruppe aus Request-Daten.
 *
 * @param array $request Request-Daten (typisch $_GET).
 *
 * @return bool|WP_Error True bei Erfolg.
 */
function pods_repeater_admin_handle_delete( $request ) {
	if ( ! is_array( $request ) ) {
		return new WP_Error( 'pods_repeater_invalid_request', __( 'Invalid request.', 'pods-repeater' ) );
	}

	if ( ! pods_repeater_admin_can_manage() ) {
		return new WP_Error( 'pods_repeater_forbidden', __( 'You are not allowed to manage field groups.', 'pods-repeater' ) );
	}

	$nonce = isset( $request['_wpnonce'] ) && is_scalar( $request['_wpnonce'] )
		? (string) $request['_wpnonce']
		: '';

	if ( '' === $nonce || ! wp_verify_nonce( $nonce, PODS_REPEATER_GROUP_NONCE_ACTION ) ) {
		return new WP_Error( 'pods_repeater_invalid_nonce', __( 'Security check failed. Please reload the page and try again.', 'pods-repeater' ) );
	}

	$slug = isset( $request['slug'] ) && is_scalar( $request['slug'] ) ? (string) $request['slug'] : '';

	if ( ! pods_repeater_delete_group( $slug ) ) {
		return new WP_Error(
			'pods_repeater_unknown_group',
			__( 'This field group does not exist.', 'pods-repeater' ),
			array( 'slug' => $slug )
		);
	}

	return true;
}

/**
 * Admin-post-Wrapper: Speichern.
 *
 * Prüft nichts selbst — der Handler kennt Capability und Nonce. Hier nur
 * Dispatch, Redirect und exit.
 *
 * @return void
 */
function pods_repeater_admin_post_save() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- der Handler verifiziert die Nonce.
	$result = pods_repeater_admin_handle_save( $_POST );
	$slug   = is_wp_error( $result ) ? '' : (string) $result;
	$notice = is_wp_error( $result ) ? 'error' : 'saved';

	$args = array( 'pods_repeater_notice' => $notice );

	if ( '' !== $slug ) {
		$args['slug'] = $slug;
	}

	wp_safe_redirect( pods_repeater_admin_page_url( $args ) );
	exit;
}
add_action( 'admin_post_pods_repeater_group_save', 'pods_repeater_admin_post_save' );

/**
 * Admin-post-Wrapper: Löschen.
 *
 * @return void
 */
function pods_repeater_admin_post_delete() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- der Handler verifiziert die Nonce selbst.
	$result = pods_repeater_admin_handle_delete( $_GET );
	$notice = is_wp_error( $result ) ? 'error' : 'deleted';

	wp_safe_redirect( pods_repeater_admin_page_url( array( 'pods_repeater_notice' => $notice ) ) );
	exit;
}
add_action( 'admin_post_pods_repeater_group_delete', 'pods_repeater_admin_post_delete' );
