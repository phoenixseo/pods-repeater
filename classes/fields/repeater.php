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
 * This is the CORE port of the standalone plugin field type
 * (Pods_Repeater_Field in pods-repeater/classes/field-repeater.php): class
 * renamed to the core PodsField_<Type> convention, text domain switched to
 * 'pods'. Logic is identical to the plugin implementation.
 *
 * @package Pods\Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

/**
 * Class PodsField_Repeater
 *
 * A repeatable group of sub-fields (text, textarea, number, pick, ...) stored
 * as a JSON string — the repeatable-field-groups feature requested in
 * Discussion #7238 / Issue #109.
 */
class PodsField_Repeater extends PodsField {
	/**
	 * Field type group (Pods core picker groups by this).
	 *
	 * @var string
	 */
	public static $group = 'Other';

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
		static::$label = __( 'Repeater (Repeatable Group)', 'pods' );
		static::$group = __( 'Other', 'pods' );
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
		// Auswahl der registrierten Feldgruppen (Slug => Label). Der
		// function_exists()-Guard hält options() auch dann benutzbar, wenn
		// die Registry (includes/field-groups.php) nicht geladen ist —
		// ohne Guard wäre das ein Fatal beim Rendern des Feld-Dialogs.
		$group_choices = array();

		if ( function_exists( 'pods_repeater_get_groups' ) ) {
			foreach ( pods_repeater_get_groups() as $group_slug => $group ) {
				$group_choices[ (string) $group_slug ] = isset( $group['label'] ) && is_scalar( $group['label'] )
					? (string) $group['label']
					: (string) $group_slug;
			}
		}

		return array(
			'repeater_group'                    => array(
				'label'   => __( 'Field Group', 'pods' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Textdomain bleibt bis S6 'pods'.
				'help'    => __( 'Wenn gesetzt, kommen die Sub-Felder aus dieser Feldgruppe; das JSON darunter wird ignoriert.', 'pods' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Siehe S6.
				'type'    => 'pick',
				'default' => '',
				'data'    => $group_choices,
			),
			'repeater_sub_fields'               => array(
				'label'   => __( 'Sub-Fields (JSON)', 'pods' ),
				'help'    => __( 'JSON array of sub-field definitions.', 'pods' ),
				'type'    => 'paragraph',
				'default' => '[]',
			),
			'repeater_limit'                    => array(
				'label'   => __( 'Max rows', 'pods' ),
				'type'    => 'number',
				'default' => 0, // 0 = unlimited
			),
			// Pods-Display-Konvention (Muster pick.php _display_format_multi):
			// Format ist Feldoption, keine hartkodierte Liste. default reiht
			// die Zeilen mit Komma aneinander, ul/ol als Liste, br mit <br />.
			'repeater_display_format'           => array(
				'label'      => __( 'Display Format', 'pods' ),
				'help'       => __( 'Used as format for front-end display.', 'pods' ),
				'default'    => 'default',
				'required'   => true,
				'type'       => 'pick',
				'data'       => array(
					'default' => __( 'Item 1, Item 2', 'pods' ),
					'br'      => __( 'One per line (br HTML tags)', 'pods' ),
					'ul'      => __( 'Unordered HTML list', 'pods' ),
					'ol'      => __( 'Ordered HTML list', 'pods' ),
					'custom'  => __( 'Custom separator', 'pods' ),
				),
				'dependency' => true,
			),
			'repeater_display_format_separator' => array(
				'label'      => __( 'Display Format Separator', 'pods' ),
				'help'       => __( 'Used as separator for front-end display.', 'pods' ),
				'depends-on' => array(
					'repeater_display_format' => 'custom',
				),
				'default'    => ', ',
				'type'       => 'text',
			),
			'disable_dfv'                       => array(
				'label'   => __( 'Disable DFV', 'pods' ),
				'help'    => __( 'Render with the PHP input() renderer instead of the React DFV pipeline (required for custom field types without a DFV component).', 'pods' ),
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
	 * @param int|string|null $id      Item ID.
	 * @param string|null     $name    Field name.
	 * @param array|null      $options Field options.
	 * @param array|null      $fields  All fields of the pod.
	 * @param object|null     $pod     Pod object.
	 * @param array|null      $params  Additional parameters.
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
			$value = array_values(
				array_filter(
					$value,
					function ( $row ) {
						return ! $this->is_row_empty( $row );
					}
				)
			);

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
	 * @param array|null      $fields  All fields of the pod.
	 * @param object|null     $pod     Pod object.
	 * @param int|string|null $id      Item ID.
	 * @param array|null      $params  Additional parameters.
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
					return new WP_Error(
						'pods_repeater_required',
						sprintf(
						/* translators: %s: sub-field label */
							__( 'Field "%s" is required in every row.', 'pods' ),
							$sub['label']
						)
					);
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

		$sub_fields = $this->get_sub_fields( $options );

		// Pods-Display-Konvention (Muster classes/fields/pick.php): das
		// Container-Format ist eine Feldoption, kein hartkodiertes HTML.
		// `default` reiht die Zeilen mit Komma aneinander (pods_serial_comma),
		// `ul`/`ol` nutzen das Pods-Listenmuster, `br` trennt mit <br />.
		$format    = pods_v( 'repeater_display_format', $options, 'default', true );
		$separator = pods_v( 'repeater_display_format_separator', $options, ', ', true );

		$lines = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( empty( $sub_fields ) ) {
				$lines[] = esc_html( (string) $row );

				continue;
			}

			$cells = array();

			foreach ( $sub_fields as $sub ) {
				$sub_name = isset( $sub['name'] ) ? $sub['name'] : '';
				$sub_type = isset( $sub['type'] ) ? $sub['type'] : 'text';

				if ( '' === $sub_name || ! array_key_exists( $sub_name, $row ) ) {
					continue;
				}

				$sub_value = $row[ $sub_name ];

				// Pods-Konvention: den Wert durch die display()-Methode des
				// Sub-Feldtyps rendern (link -> natives <a>, website -> <a>
				// wenn clickable, text -> esc_html) — Muster PodsForm::field_method().
				$rendered = PodsForm::field_method( $sub_type, 'display', $sub_value, $sub_name, $sub, $pod, $id );

				if ( null === $rendered ) {
					$rendered = esc_html( (string) $sub_value );
				}

				if ( '' !== trim( (string) $rendered ) ) {
					$cells[] = $rendered;
				}
			}

			// Eine Zeile = ihre Sub-Werte, durch Komma getrennt (keine Label-Spannen).
			$lines[] = implode( ', ', $cells );
		}

		switch ( $format ) {
			case 'ul':
			case 'ol':
				$value = '<' . $format . '><li>' . implode( '</li><li>', $lines ) . '</li></' . $format . '>';
				break;
			case 'br':
				$value = implode( '<br />', $lines );
				break;
			case 'custom':
				$value = implode( $separator, $lines );
				break;
			default:
				$value = implode( ', ', $lines );
				break;
		}

		return $value;
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
	 * @param string|null       $name    Field name.
	 * @param mixed|null        $value   Current value (array of rows).
	 * @param array|object|null $options Field options (Array oder Whatsit\Field-Objekt).
	 * @param object|null       $pod     Pod object.
	 * @param int|string|null   $id      Item ID.
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

		// Gesetzter, aber unbekannter/leerer Gruppen-Slug: sichtbar machen,
		// statt das Feld still leer zu rendern.
		$group_notice = $this->get_group_notice( $options );

		// Field name used by Pods for this field (array syntax for rows).
		$field_name = $this->get_field_name( $name, $options );

		// Seed at least one empty row so the JS template + "add" always work.
		if ( empty( $rows ) ) {
			$rows = array( array() );
		}

		ob_start();
		?>
		<div class="pods-form-ui-field pods-form-ui-field-type-repeater pods-form-ui-field-name-<?php echo esc_attr( PodsForm::clean( $name, true ) ); ?> pods-repeater" data-limit="<?php echo esc_attr( $limit ); ?>">
			<?php if ( '' !== $group_notice ) : ?>
				<p class="description pods-repeater-group-notice"><?php echo esc_html( $group_notice ); ?></p>
			<?php endif; ?>
			<div class="pods-repeater-rows" data-name="<?php echo esc_attr( $field_name ); ?>">
				<?php foreach ( $rows as $index => $row ) : ?>
					<div class="pods-repeater-row" data-index="<?php echo esc_attr( $index ); ?>">
						<?php foreach ( $sub_fields as $sub ) : ?>
							<?php
							// Innen liegende <label>s (boolean/pick-radio) machen ein
							// äußeres <label> ungültig — daher div-Wrapper. heading/html
							// sind display-only und haben kein Eingabe-Element.
							$sub_type        = isset( $sub['type'] ) ? $sub['type'] : 'text';
							$is_display_only = in_array( $sub_type, array( 'heading', 'html' ), true );
							?>
							<div class="pods-repeater-subfield pods-repeater-subfield-<?php echo esc_attr( isset( $sub['name'] ) ? $sub['name'] : $sub_type ); ?>">
								<?php if ( ! $is_display_only ) : ?>
									<span class="pods-repeater-subfield-label"><?php echo esc_html( isset( $sub['label'] ) ? $sub['label'] : $sub_type ); ?></span>
								<?php endif; ?>
								<?php $this->render_sub_field_input( $field_name, $index, $sub, $row ); ?>
							</div>
						<?php endforeach; ?>
						<button type="button" class="button pods-repeater-move-up" aria-label="<?php esc_attr_e( 'Move up', 'pods' ); ?>" title="<?php esc_attr_e( 'Move up', 'pods' ); ?>">↑</button>
						<button type="button" class="button pods-repeater-move-down" aria-label="<?php esc_attr_e( 'Move down', 'pods' ); ?> " title="<?php esc_attr_e( 'Move down', 'pods' ); ?>">↓</button>
						<button type="button" class="button pods-repeater-remove" aria-label="<?php esc_attr_e( 'Remove row', 'pods' ); ?>" title="<?php esc_attr_e( 'Remove row', 'pods' ); ?>">×</button>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button pods-repeater-add"><?php esc_html_e( 'Add row', 'pods' ); ?></button>
		</div>
		<?php
		// Pods-Konvention: input() echo-t; PodsForm::options() fängt die Ausgabe
		// via ob_start()/ob_get_clean() ein (classes/PodsForm.php:706-712).
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffered markup, individually escaped above.
	}

	/**
	 * Render a single sub-field input.
	 *
	 * Delegates to Pods' own field pipeline (PodsForm::field()) so that any
	 * Pods field type registered in field_types() works out of the box —
	 * including its type-specific options. The nested HTML name
	 * (<field>[<idx>][<sub>]) is produced by passing the full expression as
	 * $name (PodsForm::merge_attributes() builds name/id from it), plus
	 * data-sub on the attributes for the JS row-clone/renumber contract.
	 *
	 * @param string $field_name Parent field name (HTML name prefix).
	 * @param int    $index      Row index.
	 * @param array  $sub        Sub-field definition.
	 * @param array  $row        Row values.
	 */
	protected function render_sub_field_input( $field_name, $index, $sub, $row ) {
		$sub_name = isset( $sub['name'] ) ? (string) $sub['name'] : '';
		$sub_type = isset( $sub['type'] ) ? (string) $sub['type'] : 'text';

		if ( '' === $sub_name ) {
			return;
		}

		$value = isset( $row[ $sub_name ] ) ? $row[ $sub_name ] : '';

		// Repeater darf nicht in sich selbst verschachtelt werden.
		if ( 'repeater' === $sub_type ) {
			echo '<p class="description">' . esc_html__( 'Nested repeaters are not supported.', 'pods' ) . '</p>';

			return;
		}

		// Reine Anzeige-Typen haben keinen speicherbaren Wert.
		if ( in_array( $sub_type, array( 'heading', 'html' ), true ) ) {
			$this->render_sub_field_display( $sub );

			return;
		}

		// pick/tableless rendert nur über DFV (React). Für den Repeater
		// (disable_dfv=1, JS-zeilenbasiert) bauen wir ein natives serverseitiges
		// Kontrollelement aus den Optionen — kein React-Script in Zeilen.
		if ( 'pick' === $sub_type ) {
			$this->render_sub_field_pick( $field_name, $index, $sub, $value );

			return;
		}

		$input_name = $field_name . '[' . $index . '][' . $sub_name . ']';
		$input_id   = $field_name . '_' . $index . '_' . $sub_name;

		// Pods-eigene Pipeline: Typ-Klasse + ui/fields/<type>.php-Template.
		// merge_attributes() baut name/id aus $name, data-sub wird über die
		// attributes durchgereicht. DFV ist deaktiviert — bewusst, der
		// Repeater klont Zeilen serverseitig gerendert per JS.
		$options = array(
			'type'        => $sub_type,
			'name_prefix' => '',
			'disable_dfv' => '1',
			'label'       => isset( $sub['label'] ) ? $sub['label'] : $sub_name,
			'attributes'  => array(
				'id'       => $input_id,
				'data-sub' => $sub_name,
			),
		);

		// Typspezifische Sub-Feld-Optionen generisch durchreichen: Die
		// Sub-Feld-Definition (gespeichertes JSON) ist eine freie
		// Options-Map im Pods-Sinne. Nur die Kern-Keys werden ausgenommen,
		// die der Repeater selbst verwaltet (Name/Type/Label sind bereits
		// oben verarbeitet; 'options' würde PodsForm-Optionen überschreiben).
		// Dadurch wirken alle Feldtyp-Optionen (z. B. boolean_format_type,
		// number_format, currency_format_sign, date_format, wysiwyg_editor)
		// sowohl im Editor als auch in der display()-Pipeline.
		$sub_option_blacklist = array(
			'name',
			'type',
			'label',
			'required', // Validierung läuft über validate(); soll nicht als PodsForm-Option wirken.
			'name_prefix', // Von der Repeater-Pipeline hart gesetzt.
			'disable_dfv', // Von der Repeater-Pipeline hart gesetzt (Zeilen-Input).
			'attributes',  // Von der Repeater-Pipeline hart gesetzt (id/data-sub).
			'options', // Kollision: wäre die Pods-Options-Map selbst.
		);

		foreach ( $sub as $opt_key => $opt_value ) {
			if ( in_array( $opt_key, $sub_option_blacklist, true ) ) {
				continue;
			}

			$options[ $opt_key ] = $opt_value;
		}

		try {
			$html = (string) PodsForm::field( $input_name, $value, $sub_type, $options, null, null );
		} catch ( Throwable $e ) {
			// Wir rendern kein halbes Feld: Fallback auf natives Text-Input.
			$html = '';
		}

		// disable_dfv wird nur von Feldklassen respektiert, die input()
		// selbst überschreiben. Typen ohne eigene Klasse — textarea ist der
		// wichtigste, Pods hat kein classes/fields/textarea.php — fallen auf
		// PodsField::input() zurück und liefern einen DFV-React-Mount. Dessen
		// Feldname steht nur im JSON-Blob (pods-dfv-field-data), es gibt kein
		// name="..." im HTML: ein Formular-POST bekäme für solche Spalten nie
		// einen Wert. Deshalb hier generisch nachziehen und auf Pods' eigenes
		// Template (ui/fields/<type>.php) nativ rendern. Fehlt das Template,
		// bleibt das bisherige HTML stehen — kein harter Fehler.
		if ( '' !== $html && false !== strpos( $html, 'pods-dfv-field-data' ) ) {
			$native_type     = PodsForm::clean( $sub_type, true, true );
			$native_template = PODS_DIR . 'ui/fields/' . $native_type . '.php';

			if ( '' !== $native_type && file_exists( $native_template ) ) {
				// Variablen exakt wie in Pods' Feldklassen (Muster
				// PodsField_Paragraph::input(), das für disable_dfv auf
				// ui/fields/textarea.php zeigt): $name ist der volle
				// HTML-Name, damit merge_attributes() name/id baut,
				// $options trägt name_prefix/attributes (id, data-sub).
				// $form_field_type ist die Typ-Kennung, auf die Templates
				// verzweigen (datetime.php: date/time/datetime) — nicht
				// PodsForm::$field_type, das außerhalb des Datei-Renderings
				// null ist.
				$name            = $input_name;
				$form_field_type = $native_type;
				$pod             = null;
				$id              = null;

				$native_html = (string) pods_view( $native_template, compact( array_keys( get_defined_vars() ) ), false, 'cache', true );

				if ( '' !== $native_html ) {
					$html = $native_html;
				}
			}
		}

		// wysiwyg (TinyMCE/Quicktags) verschluckt data-sub in seinem eigenen
		// Options-Handling; der JS-Kontrakt braucht data-sub aber auf einem
		// Element in der Zeile. Fallback: data-sub am Wrapper statt am Input.
		if ( '' === $html || false === strpos( $html, 'data-sub="' . $sub_name . '"' ) ) {
			echo '<span class="pods-repeater-subfield-input" data-sub="' . esc_attr( $sub_name ) . '">';
			if ( '' !== $html ) {
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pods-generated field markup.
			}
			echo '</span>';

			return;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pods-generated field markup.
	}

	/**
	 * Render display-only sub-fields (heading/html): no input, no name.
	 *
	 * @param array $sub Sub-field definition.
	 */
	protected function render_sub_field_display( $sub ) {
		$text = isset( $sub['label'] ) ? $sub['label'] : ( isset( $sub['name'] ) ? $sub['name'] : '' );

		if ( isset( $sub['type'] ) && 'html' === $sub['type'] ) {
			$allowed = array(
				'a'      => array(
					'href'   => true,
					'title'  => true,
					'target' => true,
				),
				'strong' => array(),
				'em'     => array(),
				'br'     => array(),
				'code'   => array(),
			);
			echo wp_kses( $text, $allowed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses() output is safe by construction.
		} else {
			echo '<h4>' . esc_html( $text ) . '</h4>';
		}
	}

	/**
	 * Render a pick sub-field as native controls (no DFV/React).
	 *
	 * @param string $field_name Parent field name.
	 * @param int    $index      Row index.
	 * @param array  $sub        Sub-field definition.
	 * @param mixed  $value      Current value.
	 */
	protected function render_sub_field_pick( $field_name, $index, $sub, $value ) {
		$sub_name   = isset( $sub['name'] ) ? (string) $sub['name'] : '';
		$input_name = $field_name . '[' . $index . '][' . $sub_name . ']';
		$input_id   = $field_name . '_' . $index . '_' . $sub_name;

		$format = isset( $sub['pick_format_type'] ) ? $sub['pick_format_type'] : 'single';
		$format = in_array( $format, array( 'single', 'multi' ), true ) ? $format : 'single';

		$data = isset( $sub['data'] ) ? $sub['data'] : array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( empty( $data ) ) {
			echo '<p class="description">' . esc_html__( 'No options defined for this pick field.', 'pods' ) . '</p>';

			return;
		}

		if ( 'multi' === $format ) {
			// Multi: Checkbox-Gruppe; der Name ist bereits der vollständige
			// items[i][sub] — ein weiteres [sub] würde doppelt verschachteln.
			echo '<div class="pods-repeater-pick-multi" data-sub="' . esc_attr( $sub_name ) . '">';
			$values = is_array( $value ) ? $value : array( $value );
			foreach ( $data as $opt_value => $opt_label ) {
				$opt_value = (string) $opt_value;
				printf(
					'<label><input type="checkbox" name="%1$s" value="%2$s" data-sub="%3$s"%4$s /> %5$s</label>',
					esc_attr( $input_name ),
					esc_attr( $opt_value ),
					esc_attr( $sub_name ),
					checked( in_array( $opt_value, array_map( 'strval', $values ), true ), true, false ),
					esc_html( $opt_label )
				);
			}
			echo '</div>';

			return;
		}

		$format_type = isset( $sub['pick_format_type_single'] ) ? $sub['pick_format_type_single'] : 'dropdown';
		if ( 'radio' === $format_type ) {
			foreach ( $data as $opt_value => $opt_label ) {
				$opt_value = (string) $opt_value;
				printf(
					'<label><input type="radio" name="%1$s" value="%2$s" data-sub="%3$s"%4$s /> %5$s</label>',
					esc_attr( $input_name ),
					esc_attr( $opt_value ),
					esc_attr( $sub_name ),
					checked( (string) $value, $opt_value, false ),
					esc_html( $opt_label )
				);
			}

			return;
		}

		// Default: Dropdown.
		printf(
			'<select id="%1$s" name="%2$s" data-sub="%3$s">',
			esc_attr( $input_id ),
			esc_attr( $input_name ),
			esc_attr( $sub_name )
		);
		echo '<option value="">' . esc_html__( '— Select —', 'pods' ) . '</option>';
		foreach ( $data as $opt_value => $opt_label ) {
			$opt_value = (string) $opt_value;
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $opt_value ),
				selected( (string) $value, $opt_value, false ),
				esc_html( $opt_label )
			);
		}
		echo '</select>';
	}

	/**
	 * List-view display (admin columns): e.g. "3 entries".
	 *
	 * @param int|string|null $id      Item ID.
	 * @param mixed|null      $value   Array of rows or raw.
	 * @param string|null     $name    Field name.
	 * @param array|null      $options Field options.
	 * @param array|null      $fields  All fields of the pod.
	 * @param object|null     $pod     Pod object.
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
		return sprintf( _n( '%s entry', '%s entries', $count, 'pods' ), number_format_i18n( $count ) );
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
	 * This seeds one empty template row in the UI when the stored value is
	 * empty (so the JS template and "add" always work). Such a row must
	 * never persist as an empty '{}' entry, and it must not block required
	 * sub-field validation. A row counts as empty when none of its values is
	 * set (''/null/missing). Explicit values like 0, '0' or false count as
	 * filled and are kept.
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
	 * Parse the sub-field configuration from the field options.
	 *
	 * Zwei Quellen, eine Reihenfolge:
	 *   1. Feldgruppe (`repeater_group`): eine registrierte, wiederverwendbare
	 *      Definition aus der Registry (includes/field-groups.php). Ist der
	 *      Slug gesetzt, gewinnt die Gruppe — auch dann, wenn zusätzlich
	 *      Inline-JSON hinterlegt ist (so steht es im Help-Text der Option).
	 *   2. Inline-JSON (`repeater_sub_fields`): der bisherige Weg, unverändert
	 *      für alle Felder ohne Gruppen-Referenz (Abwärtskompatibilität).
	 *
	 * Ein gesetzter, aber unbekannter (oder leerer) Slug liefert bewusst ein
	 * leeres Array statt eines Rückfalls aufs JSON: sonst würde eine
	 * gelöschte Gruppe stillschweigend alte Inline-Definitionen reaktivieren.
	 * input() macht den Fall als Hinweis sichtbar.
	 *
	 * @param array|object|null $options Field options (Array oder Whatsit\Field-Objekt).
	 *
	 * @return array List of sub-field definitions.
	 */
	protected function get_sub_fields( $options = null ) {
		$options    = $this->normalize_options( $options );
		$group_slug = $this->get_group_slug( $options );

		if ( '' !== $group_slug && function_exists( 'pods_repeater_get_group_fields' ) ) {
			$group_fields = pods_repeater_get_group_fields( $group_slug );

			return is_array( $group_fields ) ? array_values( $group_fields ) : array();
		}

		$raw     = isset( $options['repeater_sub_fields'] ) ? $options['repeater_sub_fields'] : '';
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Liest den normalisierten Gruppen-Slug aus den Feld-Optionen.
	 *
	 * @param array|object|null $options Field options.
	 *
	 * @return string Slug oder '' wenn keine Gruppe referenziert ist.
	 */
	protected function get_group_slug( $options = null ) {
		$options = $this->normalize_options( $options );

		if ( ! isset( $options['repeater_group'] ) || ! is_scalar( $options['repeater_group'] ) ) {
			return '';
		}

		return trim( (string) $options['repeater_group'] );
	}

	/**
	 * Hinweistext, wenn eine Gruppe referenziert ist, aber keine Sub-Felder
	 * liefert (unbekannter Slug oder leere Gruppe). Leerer String = kein
	 * Hinweis nötig.
	 *
	 * @param array|object|null $options Field options.
	 *
	 * @return string Hinweis (unübersetzt escaped der Aufrufer).
	 */
	protected function get_group_notice( $options = null ) {
		$options    = $this->normalize_options( $options );
		$group_slug = $this->get_group_slug( $options );

		if ( '' === $group_slug || ! function_exists( 'pods_repeater_get_group_fields' ) ) {
			return '';
		}

		$group_fields = pods_repeater_get_group_fields( $group_slug );

		if ( is_array( $group_fields ) && ! empty( $group_fields ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: field group slug */
			__( 'Die referenzierte Feldgruppe "%s" existiert nicht oder enthält keine Felder.', 'pods' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Textdomain bleibt bis S6 'pods'.
			$group_slug
		);
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
		$options = $this->normalize_options( $options );

		$field_name = $name;
		if ( ! empty( $options['name'] ) ) {
			// Options may carry a 'name' override (e.g. prefixed meta key).
			$field_name = $options['name'];
		}

		// Admin meta-box forms run under a field prefix (PodsMeta.php:1354
		// sets $field_prefix = 'pods_meta_'; PodsForm::field() passes it
		// through $options['name_prefix'], PodsForm.php:117/178/620).
		// The POST data is keyed by prefix+name, so the rendered inputs must
		// use that prefixed name — otherwise the admin save path reads an
		// empty $_POST slot and wipes the stored value.
		$prefix = ! empty( $options['name_prefix'] ) ? (string) $options['name_prefix'] : '';
		if ( '' !== $prefix && 0 !== strpos( (string) $field_name, $prefix ) ) {
			$field_name = $prefix . $field_name;
		}

		return (string) $field_name;
	}
}

