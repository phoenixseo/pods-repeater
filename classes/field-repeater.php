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
	public function pre_save( $value = null, $name = null, $options = null, $pod = null, $id = null ) {
		if ( is_array( $value ) || is_object( $value ) ) {
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
	public function validate( $value = null, $name = null, $options = null, $pod = null, $id = null ) {
		$rows = is_string( $value ) ? json_decode( $value, true ) : $value;
		if ( ! is_array( $rows ) ) {
			return true; // Nothing to validate.
		}
		$sub_fields = $this->get_sub_fields( $options );
		foreach ( $rows as $row ) {
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
	 * @param mixed|null      $value   Current value (array of rows).
	 * @param string|null     $name    Field name.
	 * @param array|null      $options Field options.
	 * @param object|null     $pod     Pod object.
	 * @param int|string|null $id      Item ID.
	 *
	 * @return string HTML.
	 */
	public function input( $value = null, $name = null, $options = null, $pod = null, $id = null ) {
		$rows       = is_array( $value ) ? $value : array();
		$sub_fields = $this->get_sub_fields( $options );
		$limit      = ! empty( $options['repeater_limit'] ) ? (int) $options['repeater_limit'] : 0;

		// Field name used by Pods for this field (array syntax for rows).
		$field_name = $this->get_field_name( $name, $options );

		ob_start();
		?>
		<div class="pods-repeater" data-limit="<?php echo esc_attr( $limit ); ?>">
			<div class="pods-repeater-rows" data-name="<?php echo esc_attr( $field_name ); ?>">
				<?php foreach ( $rows as $index => $row ) : ?>
					<div class="pods-repeater-row" data-index="<?php echo esc_attr( $index ); ?>">
						<?php foreach ( $sub_fields as $sub ) : ?>
							<label>
								<span><?php echo esc_html( $sub['label'] ); ?></span>
								<input type="text"
									name="<?php echo esc_attr( $field_name ); ?>[<?php echo esc_attr( $index ); ?>][<?php echo esc_attr( $sub['name'] ); ?>]"
									value="<?php echo esc_attr( isset( $row[ $sub['name'] ] ) ? $row[ $sub['name'] ] : '' ); ?>" />
							</label>
						<?php endforeach; ?>
						<button type="button" class="pods-repeater-remove" aria-label="<?php esc_attr_e( 'Remove row', 'pods-repeater' ); ?>">×</button>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button pods-repeater-add"><?php esc_html_e( 'Add row', 'pods-repeater' ); ?></button>
		</div>
		<?php
		return ob_get_clean();
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
	public function ui( $value = null, $name = null, $options = null, $pod = null, $id = null ) {
		$rows = is_string( $value ) ? json_decode( $value, true ) : $value;
		if ( ! is_array( $rows ) ) {
			return '';
		}
		$count = count( $rows );
		/* translators: %s: number of entries */
		return sprintf( _n( '%s entry', '%s entries', $count, 'pods-repeater' ), number_format_i18n( $count ) );
	}

	/**
	 * Parse the sub-field configuration from the field options (JSON string).
	 *
	 * @param array|null $options Field options.
	 *
	 * @return array List of sub-field definitions.
	 */
	protected function get_sub_fields( $options = null ) {
		$raw = '';
		if ( is_array( $options ) ) {
			$raw = isset( $options['repeater_sub_fields'] ) ? $options['repeater_sub_fields'] : '';
		}
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
