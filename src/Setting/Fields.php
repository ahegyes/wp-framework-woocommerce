<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Setting;

use DeepWebSolutions\Framework\Settings\Forms;

\defined( 'ABSPATH' ) || exit;

/**
 * Claims, sanitizes, renders and reports the fields of one WooCommerce settings helper's sections.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 *
 * @phpstan-import-type Field from Forms
 */
final class Fields {
	// region FIELDS AND CONSTANTS

	/**
	 * The fields the helper owns, by id.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, Field>
	 */
	protected array $owned = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<string, mixed> $sections The helper's sections, keyed by id.
	 */
	public function __construct(
		protected array $sections
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers the hooks that claim the fields of the helper's sections on the tab and sanitize them.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $tab_id The id of the tab that holds the sections.
	 *
	 * @return  void
	 */
	public function register_hooks( string $tab_id ): void {
		add_filter( "woocommerce_get_settings_$tab_id", array( $this, 'claim' ), \PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_admin_settings_sanitize_option', array( $this, 'sanitize_option' ), 10, 3 );
	}

	// endregion

	// region HOOKS

	/**
	 * Takes ownership of the fields of one of the helper's sections, as WooCommerce's settings filter leaves them, and hooks the row printer to each type among them that WooCommerce does not draw and Forms does.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<mixed> $settings   The section's settings.
	 * @param   string       $section_id The section's id.
	 *
	 * @return  array<mixed>
	 */
	public function claim( array $settings, string $section_id ): array {
		if ( ! isset( $this->sections[ $section_id ] ) ) {
			return $settings;
		}

		foreach ( $settings as $setting ) {
			if ( ! self::is_field( $setting ) ) {
				continue;
			}

			// is_field() has checked the id, which the Field shape leaves optional.
			$this->owned[ $setting['id'] ?? '' ] = $setting;
			if ( self::is_drawn_by_forms( $setting ) ) {
				add_action( "woocommerce_admin_field_{$setting['type']}", array( $this, 'render_row' ) );
			}
		}

		return $settings;
	}

	/**
	 * Returns the value to store for an owned field's submission, or null, under which WooCommerce keeps the stored value, and any other option's value unchanged.
	 *
	 * A disabled field and a field the form did not post are never saved. The submission goes through Forms::sanitize_fields(), where a field without validate, a render callable or the checkboxes type keeps WooCommerce's value, and a rejection becomes one of WooCommerce's settings errors.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $value  WooCommerce's value for the option.
	 * @param   mixed $option The option's setting.
	 * @param   mixed $raw    The unslashed submission, which over REST is WooCommerce's normalized value and is null when the form posted nothing for the field.
	 *
	 * @return  mixed
	 */
	public function sanitize_option( mixed $value, mixed $option, mixed $raw ): mixed {
		$field = $this->get_owned( $option );
		if ( \is_null( $field ) ) {
			return $value;
		}

		// WooCommerce prints no hidden input before its own checkboxes and multi-value selects, so a cleared one posts nothing, while any other field that posts nothing was not rendered.
		$raw ??= match ( $field['type'] ) {
			'checkbox'                              => 'no',
			'multiselect', 'multi_select_countries' => array(),
			default                                 => null,
		};
		if ( ! isset( $field['validate'] ) && ! self::is_drawn_by_forms( $field ) ) {
			$field['validate'] = static fn (): mixed => $value;
		}

		$id     = $field['id'] ?? '';
		$result = Forms::sanitize_fields( array( $field ), \is_null( $raw ) ? array() : array( $id => $raw ) );
		if ( isset( $result['errors'][ $id ] ) ) {
			// The message is plain text, because WooCommerce escapes its settings errors when it prints them.
			\WC_Admin_Settings::add_error( Forms::get_rejection_message( Forms::get_title( $field ), $result['errors'][ $id ] ) );
		}

		return $result['values'][ $id ] ?? null;
	}

	/**
	 * Prints an owned field in WooCommerce's settings row, with its control from Forms::render_control().
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<mixed> $resolved The field as WooCommerce prepared it for its row, with its stored value, field name and row class.
	 *
	 * @return  void
	 */
	public function render_row( array $resolved ): void {
		$field = $this->get_owned( $resolved );
		if ( \is_null( $field ) ) {
			return;
		}

		$id             = $field['id'] ?? '';
		$field['title'] = Forms::get_title( $field );
		$name           = \is_string( $resolved['field_name'] ?? null ) && '' !== $resolved['field_name'] ? $resolved['field_name'] : $id;
		$row_class      = \is_string( $resolved['row_class'] ?? null ) ? $resolved['row_class'] : '';

		echo '<tr class="' . esc_attr( $row_class ) . '"><th scope="row" class="titledesc">';
		// A group's legend names it, so its row heading is plain text.
		echo Forms::is_group( $field ) ? esc_html( $field['title'] ) : '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['title'] ) . '</label>';
		echo '</th><td class="forminp forminp-' . esc_attr( sanitize_title( $field['type'] ) ) . '">';
		echo Forms::render_control( $field, $resolved['value'] ?? '', $name, $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_control() escapes the control.
		echo '</td></tr>';
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the owned field a WooCommerce setting names by its id, or null.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $setting The setting.
	 *
	 * @return  Field|null
	 */
	protected function get_owned( mixed $setting ): ?array {
		return \is_array( $setting ) && \is_string( $setting['id'] ?? null ) ? ( $this->owned[ $setting['id'] ] ?? null ) : null;
	}

	/**
	 * Returns whether a setting is a field a helper can own, which takes a string id and a string type.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $setting The setting.
	 *
	 * @phpstan-assert-if-true Field $setting
	 *
	 * @return  bool
	 */
	protected static function is_field( mixed $setting ): bool {
		return \is_array( $setting ) && \is_string( $setting['id'] ?? null ) && \is_string( $setting['type'] ?? null );
	}

	/**
	 * Returns whether WooCommerce leaves the field's type undrawn and Forms draws it, which holds for a field with a render callable and for a checkboxes group.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Field $field The field.
	 *
	 * @return  bool
	 */
	protected static function is_drawn_by_forms( array $field ): bool {
		return isset( $field['render'] ) || 'checkboxes' === $field['type'];
	}

	// endregion
}
