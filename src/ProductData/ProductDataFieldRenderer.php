<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\ProductData;

use DeepWebSolutions\Framework\Settings\Schema\Exceptions\UnknownFieldTypeException;
use DeepWebSolutions\Framework\Settings\Schema\Field\FieldType;
use DeepWebSolutions\Framework\Settings\Schema\Options\OptionsResolver;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\CustomFieldType;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;

use function DeepWebSolutions\Framework\Settings\Schema\filter_field_attributes;
use function DeepWebSolutions\Framework\Settings\Schema\normalize_checkbox_value;
use function DeepWebSolutions\Framework\Settings\Schema\stringify_for_output;
use function DeepWebSolutions\Framework\Settings\Schema\stringify_option_labels;

/**
 * Renders a product-data field as a native WooCommerce control.
 *
 * Maps a SettingsField to the argument array WooCommerce's woocommerce_wp_* control functions consume,
 * then dispatches to the function for the field's type, so a product-data tab renders with the markup the
 * product editor's panel expects. A checkbox value is normalized to WooCommerce's yes/no string, on which
 * its control's checked state turns. WooCommerce escapes and emits the control. A type outside the
 * taxonomy renders through the registered custom type's seam by echoing its returned markup; a type
 * neither the taxonomy nor the registry covers throws.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class ProductDataFieldRenderer {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   OptionsResolver                $options_resolver Resolver for choice fields' option sets.
	 * @param   array<string, CustomFieldType> $custom_types     Registry of render seams for types outside the taxonomy, keyed by type token; a tab-level custom renderer for the same token takes precedence.
	 */
	public function __construct(
		protected OptionsResolver $options_resolver = new OptionsResolver(),
		protected array $custom_types = array(),
	) {}

	// endregion

	// region METHODS

	/**
	 * Builds the woocommerce_wp_* argument array for a field.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsField $field    Field to map.
	 * @param   FieldType     $type     Resolved field type.
	 * @param   mixed         $value    Current value to bind into the control.
	 * @param   string        $meta_key Product-meta key, used as the control id and name.
	 *
	 * @return  array<string, mixed>
	 */
	public function args( SettingsField $field, FieldType $type, mixed $value, string $meta_key ): array {
		$args = array(
			'id'    => $meta_key,
			'name'  => $meta_key,
			'label' => $field->label,
		);

		if ( null !== $field->description ) {
			$args['description'] = $field->description;
			$args['desc_tip']    = true;
		}

		$custom_attributes = filter_field_attributes( $field->attributes );

		switch ( $type ) {
			case FieldType::Checkbox:
				$args['value'] = normalize_checkbox_value( $value );
				break;
			case FieldType::Multiselect:
				$args['name'] = $meta_key . '[]';
				// WooCommerce marks options selected via in_array() over the values, so the keys are irrelevant.
				$args['value']                 = \is_array( $value ) ? $value : array();
				$args['options']               = stringify_option_labels( $this->options_resolver->resolve( $field->options ) );
				$custom_attributes['multiple'] = 'multiple';
				break;
			case FieldType::Select:
			case FieldType::Radio:
				$args['value']   = stringify_for_output( $value );
				$args['options'] = stringify_option_labels( $this->options_resolver->resolve( $field->options ) );
				break;
			case FieldType::Textarea:
				$args['value'] = stringify_for_output( $value );
				break;
			default:
				$args['type']  = $type->value;
				$args['value'] = stringify_for_output( $value );
				break;
		}

		if ( array() !== $custom_attributes ) {
			$args['custom_attributes'] = $custom_attributes;
		}

		return $args;
	}

	/**
	 * Renders a field's control: a taxonomy type dispatches to its WooCommerce control function; a type in
	 * the custom-type registry echoes the markup its render seam returns.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsField $field    Field to render.
	 * @param   mixed         $value    Current value to bind into the control.
	 * @param   string        $meta_key Product-meta key, used as the control id and name.
	 *
	 * @throws  UnknownFieldTypeException If the field declares a type outside both the taxonomy and the custom-type registry.
	 */
	public function render( SettingsField $field, mixed $value, string $meta_key ): void {
		$type = FieldType::tryFrom( $field->type );
		if ( null === $type ) {
			if ( isset( $this->custom_types[ $field->type ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the custom type's render seam returns escaped markup.
				echo (string) ( $this->custom_types[ $field->type ]->render )( $field, $value, $meta_key );
				return;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			throw new UnknownFieldTypeException( "Unknown settings field type: '$field->type'" );
		}

		$args = $this->args( $field, $type, $value, $meta_key );

		switch ( $type ) {
			case FieldType::Textarea:
				\woocommerce_wp_textarea_input( $args );
				break;
			case FieldType::Checkbox:
				\woocommerce_wp_checkbox( $args );
				break;
			case FieldType::Select:
			case FieldType::Multiselect:
				\woocommerce_wp_select( $args );
				break;
			case FieldType::Radio:
				\woocommerce_wp_radio( $args );
				break;
			default:
				\woocommerce_wp_text_input( $args );
				break;
		}
	}

	/**
	 * Whether a custom-type render seam is registered for a type token.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $type Field-type token to check.
	 *
	 * @return  bool
	 */
	public function has_custom_type( string $type ): bool {
		return isset( $this->custom_types[ $type ] );
	}

	// endregion
}
