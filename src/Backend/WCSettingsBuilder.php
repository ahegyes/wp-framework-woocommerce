<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Backend;

use DeepWebSolutions\Framework\Settings\Schema\FieldType;
use DeepWebSolutions\Framework\Settings\Schema\OptionsResolver;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsPage;

/**
 * Translates a settings page descriptor into WooCommerce's settings-array format.
 *
 * Pure and WordPress-free: it shapes the array WooCommerce renders and saves, mapping
 * each section to a title/sectionend group and each field to an entry keyed by a
 * slug-prefixed option id. A field's type token passes through unchanged, since the
 * framework taxonomy is a subset of WooCommerce's own field types.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class WCSettingsBuilder {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   OptionsResolver $options_resolver Resolves a field's option source to a value-to-label map.
	 */
	public function __construct(
		private OptionsResolver $options_resolver = new OptionsResolver(),
	) {}

	// endregion

	// region METHODS

	/**
	 * Builds the WooCommerce settings array for a page.
	 *
	 * Each section yields a title row, its fields, then a sectionend row anchored on the
	 * same id, in declaration order; a page without sections yields an empty array.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsPage $page Page descriptor to translate.
	 *
	 * @return  list<array<string, mixed>>
	 */
	public function build( SettingsPage $page ): array {
		$settings = array();

		foreach ( $page->sections as $section ) {
			$anchor = $page->slug . '_' . $section->id;

			$settings[] = array(
				'type'  => 'title',
				'id'    => $anchor,
				'title' => $section->title,
			);

			foreach ( $section->fields as $field ) {
				$settings[] = $this->build_field( $page->slug, $field );
			}

			$settings[] = array(
				'type' => 'sectionend',
				'id'   => $anchor,
			);
		}

		return $settings;
	}

	// endregion

	// region HELPERS

	/**
	 * Maps one field descriptor to a WooCommerce field entry.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string        $slug  Page slug prefixed onto the field's option id.
	 * @param   SettingsField $field Field descriptor to map.
	 *
	 * @return  array<string, mixed>
	 */
	private function build_field( string $slug, SettingsField $field ): array {
		$entry = array(
			'id'    => $slug . '_' . $field->id,
			'type'  => $field->type,
			'title' => $field->label,
		);

		if ( null !== $field->default ) {
			$entry['default'] = $this->map_default( $field );
		}

		// A choice field always carries an options array: WooCommerce iterates it unconditionally when rendering.
		if ( $this->expects_options( $field->type ) ) {
			$entry['options'] = $this->stringify_labels( $this->options_resolver->resolve( $field->options ) );
		}

		$attributes = $this->filter_attributes( $field->attributes );
		if ( array() !== $attributes ) {
			$entry['custom_attributes'] = $attributes;
		}

		return $entry;
	}

	/**
	 * Maps a field's default into the representation WooCommerce expects for its type.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsField $field Field whose default to map.
	 *
	 * @return  mixed
	 */
	private function map_default( SettingsField $field ): mixed {
		return match ( $field->type ) {
			FieldType::Checkbox->value    => $this->checkbox_default( $field->default ),
			// WooCommerce matches multiselect selections with a strict (string) in_array, so the set must be strings.
			FieldType::Multiselect->value => $this->stringify_selected( $field->default ),
			default                       => $field->default,
		};
	}

	/**
	 * Maps a checkbox default to WooCommerce's yes/no string.
	 *
	 * WooCommerce compares a checkbox against the literal 'yes'; the framework's checkbox value is
	 * boolean, so any truthy default becomes 'yes'.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $default Field default to coerce.
	 *
	 * @return  string
	 */
	private function checkbox_default( mixed $default ): string {
		return match ( (bool) $default ) {
			true  => 'yes',
			false => 'no',
		};
	}

	/**
	 * Whether a field type renders a WooCommerce options list.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $type Field-type token.
	 *
	 * @return  bool
	 */
	private function expects_options( string $type ): bool {
		return \in_array(
			$type,
			array( FieldType::Select->value, FieldType::Multiselect->value, FieldType::Radio->value ),
			true,
		);
	}

	/**
	 * Stringifies a resolved options map's labels, matching the framework renderer's coercion.
	 *
	 * WooCommerce passes each option label through esc_html(), which expects a string; a non-scalar
	 * label becomes an empty string, exactly as the WordPress field renderer coerces it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<array-key, mixed> $options Resolved value-to-label map.
	 *
	 * @return  array<array-key, string>
	 */
	private function stringify_labels( array $options ): array {
		$labels = array();
		foreach ( $options as $value => $label ) {
			$labels[ $value ] = \is_scalar( $label ) ? (string) $label : '';
		}

		return $labels;
	}

	/**
	 * Stringifies a multiselect default's selected values; a non-array default selects nothing.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $default Field default to coerce into a list of selected string values.
	 *
	 * @return  array<array-key, string>
	 */
	private function stringify_selected( mixed $default ): array {
		if ( ! \is_array( $default ) ) {
			return array();
		}

		return \array_map(
			static fn ( mixed $value ): string => \is_scalar( $value ) ? (string) $value : '',
			$default,
		);
	}

	/**
	 * Keeps only safe HTML attributes, dropping malformed names and executable on* event handlers.
	 *
	 * WooCommerce escapes attribute names and values but does not reject event handlers, so a descriptor
	 * is filtered here to the same allow-list the WordPress field renderer enforces.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<string, scalar> $attributes Descriptor attribute map.
	 *
	 * @return  array<string, scalar>
	 */
	private function filter_attributes( array $attributes ): array {
		$filtered = array();
		foreach ( $attributes as $attribute => $value ) {
			if ( 1 !== \preg_match( '/\A[a-z][a-z0-9-]*\z/i', $attribute ) || 0 === \stripos( $attribute, 'on' ) ) {
				continue;
			}

			$filtered[ $attribute ] = $value;
		}

		return $filtered;
	}

	// endregion
}
