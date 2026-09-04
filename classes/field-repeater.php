<?php
/**
 * Pods field type: repeater (repeatable group, ACF-Repeater equivalent).
 *
 * Generic, community-first field type: a configurable group of sub-fields
 * (scalar types) that can be repeated. SDC Page Builder is one consumer.
 *
 * Storage: JSON string in wp_postmeta (meta-based pods) / longtext column
 * (table-based pods via schema()).
 *
 * Server-side UI only (input() + own vanilla JS). No DFV-React involvement:
 * Pods forces repeatable=0 for non-whitelist types and renders via input()
 * (classes/PodsForm.php:312-316), which bypasses the hard-coded JS whitelist
 * (ui/js/dfv/src/helpers/isFieldRepeatable.js).
 *
 * The class is named Pods_Repeater_Field (plugin prefix) to stay collision-free
 * if Pods core ever ships a PodsField_Repeater. The method signatures mirror
 * the PodsField base class 1:1 so porting to core
 * (classes/fields/repeater.php, class PodsField_Repeater) is rename + move.
 *
 * @package PodsRepeater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pods_Repeater_Field
 *
 * Registered in pods-repeater.php via
 *   PodsForm::register_field_type( 'repeater', ... )
 * and found by PodsForm::field_loader() via the PodsField_ prefix convention
 * (PodsInit.php:200-203) — the plugin prefix maps to the same file on disk.
 */
class Pods_Repeater_Field extends PodsField {
	/**
	 * Static properties are read by PodsForm::field_loader() via get_class_vars()
	 * (classes/PodsForm.php:1561-1564, 1615-1618).
	 *
	 * @var string
	 */
	public static $type = 'repeater';

	/**
	 * Human-readable type name (shown in the Pods field-type picker).
	 *
	 * Static property defaults cannot call functions, so this stays the
	 * untranslated fallback for get_class_vars() consumers; setup() swaps
	 * in the __()-translated label (same pattern as Pods core
	 * classes/fields/text.php).
	 *
	 * @var string
	 */
	public static $label = 'Repeater (Repeatable Group)';

	/**
	 * DB format placeholder — we store JSON as a string.
	 *
	 * @var string
	 */
	public static $prepare = '%s';

	/**
	 * Supported pod types: meta-based. Table-based pods refuse repeatable
	 * anyway (src/Pods/Whatsit/Field.php:192).
	 *
	 * @var array|bool
	 */
	public static $pod_types = array( 'post_type', 'user', 'comment', 'settings' );

	/**
	 * Constructor: call parent init (loads $this->pod_types etc.).
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Initial setup: translate the static type label.
	 *
	 * PodsField::__construct() ruft setup() auf; field_loader() liest die
	 * Klassenvariablen erst NACH der Instantiierung (classes/PodsForm.php:1601,
	 * field_types():1750-1760), daher greift hier die übersetzte Fassung —
	 * identisches Muster wie Pods Core (classes/fields/text.php, setup()).
	 */
	public function setup() {
		static::$label = __( 'Repeater (Repeatable Group)', 'pods-repeater' );
	}

	/**
	 * Field options (field admin UI): sub-field configuration.
	 *
	 * Returns an array of option definitions in Pods format
	 * ( 'option_name' => array( 'label' => ..., 'type' => ..., 'default' => ... ) ).
	 *
	 * V1 scope (community-first, disciplined): sub-fields as JSON string in a
	 * textarea. Later: real sub-field picker UX after community feedback.
	 *
	 * @return array
	 */
	public function options() {
		return array(
			'repeater_sub_fields' => array(
				'label'   => __( 'Sub-Fields (JSON)', 'pods-repeater' ),
				'help'    => __( 'JSON array of sub-field definitions.', 'pods-repeater' ),
				'type'    => 'paragraph',
				'default' => '[]',
			),
			'repeater_limit'      => array(
				'label'   => __( 'Max rows', 'pods-repeater' ),
				'type'    => 'number',
				'default' => 0, // 0 = unlimited
			),
			'disable_dfv'         => array(
				'label'   => __( 'Disable DFV', 'pods-repeater' ),
				'help'    => __( 'Render with the PHP input() renderer instead of the React DFV pipeline (required for custom field types without a DFV component).', 'pods-repeater' ),
				'type'    => 'boolean',
				'default' => true,
			),
		);
	}

	/**
	 * Admin options in the field editor = options().
	 *
	 * @return array
	 */
	public function ui_options() {
		return $this->options();
	}

	/**
	 * DB schema for table-based pods: JSON needs LONGTEXT.
	 * (Only relevant if the type lands on a table-based pod.)
	 *
	 * @param array|null $options Field options.
	 *
	 * @return string|false
	 */
	public function schema( $options = null ) {
		return 'longtext';
	}

	/**
	 * Value preparation ON READ: JSON string → array.
	 *
	 * Called by Pods when the value comes from the DB. We decode here so that
	 * pods_field() / $pod->field() returns an array (spike criterion 4).
	 * Careful: pipeline calls (value → format → display) — only decode when
	 * is_string() && json_decode() is valid; otherwise pass $value through.
	 *
	 * @param mixed|null      $value   Raw value from DB (JSON string or null).
	 * @param string|null     $name    Field name.
	 * @param array|null      $options Field options.
	 * @param object|null     $pod     Pod object.
	 * @param int|string|null $id      Item ID.
	 *
	 * @return mixed Array (decoded) or $value.
	 */
	public function value( $value = null, $name = null, $options = null, $pod = null, $id = null ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return (array) $decoded;
			}
		}
		return $value;
	}

	/**
	 * Value preparation ON WRITE: array → JSON string (before Pods stores it).
	 *
	 * @param mixed|null      $value   Value to save (array of rows).
	 * @param string|null     $name    Field name.
	 * @param array|null      $options Field options.
	 * @param object|null     $pod     Pod object.
	 * @param int|string|null $id      Item ID.
	 *
	 * @return mixed JSON string or $value.
	 */
	public function pre_save( $value, $id = null, $name = null, $options = null, $fields = null, $pod = null, $params = null ) {
		if ( is_array( $value ) ) {
			// Vollständig leere Zeilen verwerfen: input() seedet bei leerem Wert
			// eine leere Template-Zeile (Judge-Review 24e355c, Empfehlung 1) — die
			// darf nie als '[{}]'-Zeile persistieren (würde mit required-Sub-Feldern
			// jeden Save blockieren). Zeilen mit mindestens einem gesetzten Wert
			// (auch 0/'0') bleiben erhalten.
			$value = array_values( array_filter(
				$value,
				function ( $row ) {
					return ! $this->is_row_empty( $row );
				}
			) );

			return wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
		}

		if ( is_object( $value ) ) {
			return wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
		}

		return $value;
	}

	/**
	 * Validate: check required sub-fields before saving.
	 *
	 * @param mixed|null      $value   Value to validate (array of rows or JSON string).
	 * @param string|null     $name    Field name.
	 * @param array|null      $options Field options.
	 * @param object|null     $pod     Pod object.
	 * @param int|string|null $id      Item ID.
	 *
	 * @return bool|WP_Error
	 */
	public function validate( $value = null, $name = null, $options = null, $fields = null, $pod = null, $id = null, $params = null ) {
		$rows = is_string( $value ) ? json_decode( $value, true ) : $value;
		if ( ! is_array( $rows ) ) {
			return true; // Nothing to validate.
		}
		$sub_fields = $this->get_sub_fields( $options );
		foreach ( $rows as $row ) {
			// Vollständig leere Zeilen überspringen: die von input() geseedete
			// Leerzeile darf required-Sub-Felder nicht blockieren (sie wird in
			// pre_save ohnehin verworfen). Teilgefüllte Zeilen mit leerem
			// required-Wert bleiben ein Validierungsfehler.
			if ( $this->is_row_empty( $row ) ) {
				continue;
			}

			foreach ( $sub_fields as $sub ) {
				if ( ! empty( $sub['required'] ) && empty( $row[ $sub['name'] ] ) ) {
					return new WP_Error( 'pods_repeater_required', sprintf(
						/* translators: %s: sub-field label */
						__( 'Field "%s" is required in every row.', 'pods-repeater' ),
						$sub['label']
					) );
				}
			}
		}
		return true;
	}

	/**
	 * Display value (frontend output).
	 *
	 * @param mixed|null      $value   Array of rows (already decoded) or raw.
	 * @param string|null     $name    Field name.
	 * @param array|null      $options Field options.
	 * @param object|null     $pod     Pod object.
	 * @param int|string|null $id      Item ID.
	 *
	 * @return string HTML.
	 */
	public function display( $value = null, $name = null, $options = null, $pod = null, $id = null ) {
		$rows = is_string( $value ) ? json_decode( $value, true ) : $value;
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return '';
		}
		return '<ul class="pods-repeater-display">' . implode( '', array_map(
			static function ( $row ) use ( $options ) {
				return '<li>' . esc_html( wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) ) . '</li>';
			},
			$rows
		) ) . '</ul>';
	}

	/**
	 * Admin input UI: server-side repeatable rows (add/remove/move), no DFV.
	 *
	 * Renders each sub-field via PodsForm::field() where possible; falls back
	 * to native inputs for types Pods cannot render standalone.
	 *
	 * Pods-Konvention: input() gibt KEINEN Wert zurück, sondern echo-t das
	 * HTML. PodsForm::field() fängt den Output via ob_start()/ob_get_clean()
	 * ein (classes/PodsForm.php:289/328) und ignoriert den Rückgabewert.
	 *
	 * @param string|null     $name    Field name.
	 * @param mixed|null      $value   Current value (array of rows).
	 * @param array|object|null $options Field options (Array oder Whatsit\Field-Objekt).
	 * @param object|null     $pod     Pod object.
	 * @param int|string|null $id      Item ID.
	 *
	 * @return void Gibt HTML direkt aus.
	 */
	public function input( $name, $value = null, $options = null, $pod = null, $id = null ) {
		// Whatsit\Field-Objekte via get_args() normalisieren (wie PodsForm::options(),
		// classes/PodsForm.php:706-712) — get_sub_fields() etc. arbeiten nur auf Arrays.
		$options = $this->normalize_options( $options );

		// DFV-Pfad (React) nur, wenn der Nutzer DFV nicht explizit deaktiviert hat.
		// Custom-Feldtypen ohne DFV-Komponente rendern über die eigene input()-Logik
		// (Muster wie classes/fields/phone.php — disable_dfv => eigenes PHP-HTML).
		if ( empty( $options['disable_dfv'] ) ) {
			$args = (object) compact( array_keys( get_defined_vars() ) );
			$this->render_input_script( $args );

			return;
		}

		// Admin-Render-Pfad (PodsMeta::meta_post → ui/forms/table-rows.php →
		// PodsForm::field): $pod->field( [ 'name' => ..., 'in_form' => true ] )
		// liefert den ROHEN JSON-String aus wp_postmeta — value()/Decode wird
		// bei in_form übersprungen (E2E-Trace 04.09.2026). input() akzeptiert
		// daher beides: Array (API/Direktaufruf) und JSON-String (Admin-Formular).
		// Nicht-JSON-Strings ('' wenn keine Meta existiert) bleiben leer.
		$rows = array();

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$rows = $decoded;
			}
		} elseif ( is_array( $value ) ) {
			$rows = $value;
		}

		$sub_fields = $this->get_sub_fields( $options );
		$limit      = ! empty( $options['repeater_limit'] ) ? (int) $options['repeater_limit'] : 0;

		// Field name used by Pods for this field (array syntax for rows).
		$field_name = $this->get_field_name( $name, $options );

		// Seed at least one empty row so the JS template + "add" always work.
		if ( empty( $rows ) ) {
			$rows = array( array() );
		}

		ob_start();
		?>
		<div class="pods-repeater" data-limit="<?php echo esc_attr( $limit ); ?>">
			<div class="pods-repeater-rows" data-name="<?php echo esc_attr( $field_name ); ?>">
				<?php foreach ( $rows as $index => $row ) : ?>
					<div class="pods-repeater-row" data-index="<?php echo esc_attr( $index ); ?>">
						<?php foreach ( $sub_fields as $sub ) : ?>
							<label>
								<span><?php echo esc_html( $sub['label'] ); ?></span>
								<?php $this->render_sub_field_input( $field_name, $index, $sub, $row ); ?>
							</label>
						<?php endforeach; ?>
						<button type="button" class="pods-repeater-move-up" aria-label="<?php esc_attr_e( 'Move up', 'pods-repeater' ); ?>" title="<?php esc_attr_e( 'Move up', 'pods-repeater' ); ?>">↑</button>
						<button type="button" class="pods-repeater-move-down" aria-label="<?php esc_attr_e( 'Move down', 'pods-repeater' ); ?>" title="<?php esc_attr_e( 'Move down', 'pods-repeater' ); ?>">↓</button>
						<button type="button" class="pods-repeater-remove" aria-label="<?php esc_attr_e( 'Remove row', 'pods-repeater' ); ?>" title="<?php esc_attr_e( 'Remove row', 'pods-repeater' ); ?>">×</button>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button pods-repeater-add"><?php esc_html_e( 'Add row', 'pods-repeater' ); ?></button>
		</div>
		<?php
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — Pods-Konvention: input() echo-t, PodsForm::options() fängt es via ob_start()/ob_get_clean() ein.
	}

	/**
	 * Render a single sub-field input based on its declared type.
	 *
	 * Supports the scalar types from the V1 scope (text/textarea/select/number/url).
	 * Every input carries data-sub so the JS row-clone can rebuild names correctly.
	 *
	 * @param string $field_name Parent field name (HTML name prefix).
	 * @param int    $index      Row index.
	 * @param array  $sub        Sub-field definition.
	 * @param array  $row        Row values.
	 */
	protected function render_sub_field_input( $field_name, $index, $sub, $row ) {
		$sub_name = isset( $sub['name'] ) ? $sub['name'] : '';
		$sub_type = isset( $sub['type'] ) ? $sub['type'] : 'text';
		$value    = isset( $row[ $sub_name ] ) ? $row[ $sub_name ] : '';
		$input_id = $field_name . '_' . $index . '_' . $sub_name;

		// HTML name pattern: <field>[<idx>][<sub>] — array arrives server-side.
		$input_name = $field_name . '[' . $index . '][' . $sub_name . ']';

		switch ( $sub_type ) {
			case 'textarea':
				printf(
					'<textarea id="%s" name="%s" data-sub="%s" rows="3">%s</textarea>',
					esc_attr( $input_id ),
					esc_attr( $input_name ),
					esc_attr( $sub_name ),
					esc_textarea( $value )
				);
				break;

			case 'select':
				$options_html = '';
				$choices      = isset( $sub['options'] ) && is_array( $sub['options'] ) ? $sub['options'] : array();
				foreach ( $choices as $opt_value => $opt_label ) {
					$options_html .= sprintf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $opt_value ),
						selected( (string) $value, (string) $opt_value, false ),
						esc_html( $opt_label )
					);
				}
				printf(
					'<select id="%s" name="%s" data-sub="%s">%s</select>',
					esc_attr( $input_id ),
					esc_attr( $input_name ),
					esc_attr( $sub_name ),
					$options_html
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%s" name="%s" data-sub="%s" value="%s" />',
					esc_attr( $input_id ),
					esc_attr( $input_name ),
					esc_attr( $sub_name ),
					esc_attr( $value )
				);
				break;

			case 'url':
				printf(
					'<input type="url" id="%s" name="%s" data-sub="%s" value="%s" />',
					esc_attr( $input_id ),
					esc_attr( $input_name ),
					esc_attr( $sub_name ),
					esc_attr( $value )
				);
				break;

			case 'text':
			default:
				printf(
					'<input type="text" id="%s" name="%s" data-sub="%s" value="%s" />',
					esc_attr( $input_id ),
					esc_attr( $input_name ),
					esc_attr( $sub_name ),
					esc_attr( $value )
				);
				break;
		}
	}

	/**
	 * List-view display (admin columns): e.g. "3 entries".
	 *
	 * @param mixed|null      $value   Array of rows or raw.
	 * @param string|null     $name    Field name.
	 * @param array|null      $options Field options.
	 * @param object|null     $pod     Pod object.
	 * @param int|string|null $id      Item ID.
	 *
	 * @return string
	 */
	public function ui( $id = null, $value = null, $name = null, $options = null, $fields = null, $pod = null ) {
		$rows = is_string( $value ) ? json_decode( $value, true ) : $value;
		if ( ! is_array( $rows ) ) {
			return '';
		}
		$count = count( $rows );
		/* translators: %s: number of entries */
		return sprintf( _n( '%s entry', '%s entries', $count, 'pods-repeater' ), number_format_i18n( $count ) );
	}

	/**
	 * Normalize field options to a plain array.
	 *
	 * Pods übergibt Optionen mal als Array (PodsForm::options() merged Defaults
	 * und liefert ein Array), mal als Whatsit\Field-Objekt (z. B. direkte
	 * input()-Aufrufe mit $pod['fields'][...]). Whatsit-Objekte sind zwar
	 * ArrayAccess, aber die interne Logik (get_sub_fields(), get_field_name())
	 * arbeitet nur auf echten Arrays — Objekte daher via get_args() auflösen
	 * (gleiches Muster wie PodsForm::options(), classes/PodsForm.php:706-712).
	 *
	 * @param array|object|null $options Feld-Optionen (Array, Whatsit\Field oder null).
	 *
	 * @return array
	 */
	protected function normalize_options( $options ) {
		if ( is_object( $options ) ) {
			if ( method_exists( $options, 'get_args' ) ) {
				$options = $options->get_args();
			} else {
				$options = (array) $options;
			}
		}

		return is_array( $options ) ? $options : array();
	}

	/**
	 * Is a repeater row completely empty?
	 *
	 * input() seeds one empty template row when the value is empty (so the JS
	 * template and "add" always work). Such a row must never persist as an
	 * empty '{}' entry, and it must not block required sub-field validation.
	 * A row counts as empty when none of its values is set (''/null/missing).
	 * Explicit values like 0, '0' or false count as filled and are kept.
	 *
	 * @param mixed $row Repeater row (expected array).
	 *
	 * @return bool
	 */
	protected function is_row_empty( $row ) {
		if ( ! is_array( $row ) ) {
			return false; // Unexpected non-array row: do not discard.
		}

		foreach ( $row as $value ) {
			if ( is_array( $value ) ) {
				if ( ! $this->is_row_empty( $value ) ) {
					return false;
				}
			} elseif ( '' !== $value && null !== $value ) {
				return false; // Any set value → row is filled.
			}
		}

		return true;
	}

	/**
	 * Parse the sub-field configuration from the field options (JSON string).
	 *
	 * @param array|object|null $options Field options (Array oder Whatsit\Field-Objekt).
	 *
	 * @return array List of sub-field definitions.
	 */
	protected function get_sub_fields( $options = null ) {
		$options = $this->normalize_options( $options );
		$raw     = isset( $options['repeater_sub_fields'] ) ? $options['repeater_sub_fields'] : '';
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Determine the field name used in form POST data.
	 *
	 * @param string|null $name    Field name.
	 * @param array|null  $options Field options.
	 *
	 * @return string
	 */
	protected function get_field_name( $name, $options = null ) {
		// Pods typically names the input after the field name. Options may
		// carry a 'name' override (e.g. prefixed meta key).
		if ( is_array( $options ) && ! empty( $options['name'] ) ) {
			return (string) $options['name'];
		}
		return (string) $name;
	}
}

/**
 * Class PodsField_Repeater
 *
 * Pods' field_loader() (classes/PodsForm.php:1550) resolves a field type to a
 * class by the hard-coded convention "PodsField_" + ucfirst( $field_type ).
 * A plugin-prefixed class (Pods_Repeater_Field) alone is therefore never
 * found: field_loader silently falls back to the base PodsField class, whose
 * input() ignores disable_dfv and always renders the DFV (React) script —
 * which is why the admin meta box showed 0 rows despite disable_dfv='1'.
 *
 * This thin subclass bridges the convention: field_loader finds
 * PodsField_Repeater, which carries all logic via its parent. The plugin
 * prefix stays as the main implementation (collision-free if Pods core ever
 * ships its own PodsField_Repeater — this file simply would not be loaded for
 * core types then, since core registers its own path first).
 */
class PodsField_Repeater extends Pods_Repeater_Field {}

