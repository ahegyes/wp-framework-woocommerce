<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Setting;

use DeepWebSolutions\Framework\Settings\Forms;

\defined( 'ABSPATH' ) || exit;

/**
 * Claims, sanitizes, renders and reports the fields of the WooCommerce settings helpers' sections.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 *
 * @phpstan-import-type Field from Forms
 */
final class Fields {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	protected function __construct() {}

	// endregion

	// region METHODS

	/**
	 * Adds a section's fields to its owner's fields by id, and hooks the owner's row callback to each type among them that WooCommerce does not draw and Forms does.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<mixed>         $settings   The section's settings after every filter.
	 * @param   array<string, Field> $owned      The owner's fields by id, to which the section's fields are added.
	 * @param   callable             $render_row The owner's row callback.
	 */
	public static function claim( array $settings, array &$owned, callable $render_row ): void {
		foreach ( $settings as $setting ) {
			if ( ! self::is_field( $setting ) ) {
				continue;
			}

			// is_field() has checked the id, which the Field shape leaves optional.
			$owned[ $setting['id'] ?? '' ] = $setting;
			if ( self::is_drawn_by_forms( $setting ) ) {
				\add_action( "woocommerce_admin_field_{$setting['type']}", $render_row );
			}
		}
	}

	/**
	 * Returns the value to store for an owned field's submission, or null, under which WooCommerce keeps the stored value.
	 *
	 * A disabled field and a field the form did not post are never saved. A field with validate, a render callable or the checkboxes type goes through Forms::sanitize_value(), and a rejection becomes one of WooCommerce's settings errors. Every other field keeps WooCommerce's value.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Field $field The owned field.
	 * @param   mixed $value WooCommerce's value for the field.
	 * @param   mixed $raw   The unslashed submission, which over REST is WooCommerce's normalized value and is null when the form posted nothing for the field.
	 *
	 * @return  mixed
	 */
	public static function sanitize_option( array $field, mixed $value, mixed $raw ): mixed {
		if ( Forms::is_disabled( $field ) ) {
			return null;
		}

		// WooCommerce prints no hidden input before its own checkboxes and multi-value selects, so a cleared one posts nothing, while any other field that posts nothing was not rendered.
		if ( \is_null( $raw ) ) {
			$raw = match ( $field['type'] ) {
				'checkbox'                              => 'no',
				'multiselect', 'multi_select_countries' => array(),
				default                                 => null,
			};
			if ( \is_null( $raw ) ) {
				return null;
			}
		}

		if ( ! isset( $field['validate'] ) && ! self::is_drawn_by_forms( $field ) ) {
			return $value;
		}

		$sanitized = Forms::sanitize_value( $field, $raw );
		if ( \is_wp_error( $sanitized ) ) {
			// The message is plain text, because WooCommerce escapes its settings errors when it prints them.
			\WC_Admin_Settings::add_error( Forms::get_rejection_message( Forms::get_title( $field ), $sanitized ) );
			return null;
		}

		return $sanitized;
	}

	/**
	 * Prints the field in WooCommerce's settings row, with its control from Forms::render_control().
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Field        $field    The owned field.
	 * @param   array<mixed> $resolved The field as WooCommerce prepared it for its row, with its stored value, field name and row class.
	 */
	public static function render_row( array $field, array $resolved ): void {
		$id             = $field['id'] ?? '';
		$field['title'] = Forms::get_title( $field );
		$name           = \is_string( $resolved['field_name'] ?? null ) && '' !== $resolved['field_name'] ? $resolved['field_name'] : $id;
		$row_class      = \is_string( $resolved['row_class'] ?? null ) ? $resolved['row_class'] : '';

		echo '<tr class="' . \esc_attr( $row_class ) . '"><th scope="row" class="titledesc">';
		// A checkboxes group's legend names it, so its row heading is plain text.
		echo 'checkboxes' === $field['type'] ? \esc_html( $field['title'] ) : '<label for="' . \esc_attr( $id ) . '">' . \esc_html( $field['title'] ) . '</label>';
		echo '</th><td class="forminp forminp-' . \esc_attr( \sanitize_title( $field['type'] ) ) . '">';
		echo Forms::render_control( $field, $resolved['value'] ?? '', $name, $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_control() escapes the control.
		echo '</td></tr>';
	}

	// endregion

	// region HELPERS

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
