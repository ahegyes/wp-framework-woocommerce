<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Backend;

use DeepWebSolutions\Framework\Settings\Schema\Field\FieldType;
use DeepWebSolutions\Framework\Settings\Schema\Options\OptionsResolver;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsPage;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsSection;

use function DeepWebSolutions\Framework\Settings\Schema\filter_field_attributes;
use function DeepWebSolutions\Framework\WooCommerce\to_yes_no;

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
		protected OptionsResolver $options_resolver = new OptionsResolver(),
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
			foreach ( $this->build_section( $page, $section ) as $setting ) {
				$settings[] = $setting;
			}
		}

		return $settings;
	}

	/**
	 * Builds the WooCommerce settings array for one page section.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsPage    $page    Page descriptor owning the section.
	 * @param   SettingsSection $section Section descriptor to translate.
	 *
	 * @return  list<array<string, mixed>>
	 */
	public function build_section( SettingsPage $page, SettingsSection $section ): array {
		$anchor   = $page->slug . '_' . $section->id;
		$settings = array(
			array(
				'type'  => 'title',
				'id'    => $anchor,
				'title' => $section->title,
			),
		);

		foreach ( $section->fields as $field ) {
			$settings[] = $this->build_field( $page->slug, $field );
		}

		$settings[] = array(
			'type' => 'sectionend',
			'id'   => $anchor,
		);

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
	protected function build_field( string $slug, SettingsField $field ): array {
		$entry = array(
			'id'       => $slug . '_' . $field->id,
			'type'     => $field->type,
			'title'    => $field->label,
			'autoload' => $field->autoload,
		);

		if ( null !== $field->default_value ) {
			$entry['default'] = $this->map_default( $field );
		}
		if ( null !== $field->description ) {
			$entry['desc'] = $field->description;
		}

		// A choice field always carries an options array: WooCommerce iterates it unconditionally when rendering.
		if ( $this->expects_options( $field->type ) ) {
			$entry['options'] = $this->stringify_labels( $this->options_resolver->resolve( $field->options ) );
		}

		$attributes = filter_field_attributes( $field->attributes );
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
	protected function map_default( SettingsField $field ): mixed {
		return match ( $field->type ) {
			FieldType::Checkbox->value    => to_yes_no( $field->default_value ),
			// WooCommerce matches multiselect selections with a strict (string) in_array, so the set must be strings.
			FieldType::Multiselect->value => $this->stringify_selected( $field->default_value ),
			default                       => $field->default_value,
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
	protected function expects_options( string $type ): bool {
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
	protected function stringify_labels( array $options ): array {
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
	 * @param   mixed $default_value Field default to coerce into a list of selected string values.
	 *
	 * @return  array<array-key, string>
	 */
	protected function stringify_selected( mixed $default_value ): array {
		if ( ! \is_array( $default_value ) ) {
			return array();
		}

		return \array_map(
			static fn ( mixed $value ): string => \is_scalar( $value ) ? (string) $value : '',
			$default_value,
		);
	}

	// endregion
}
