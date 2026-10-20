<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\ProductData;

use DeepWebSolutions\Framework\Settings\Forms;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * Adds a tab to WooCommerce's product data box whose panel edits product meta through field arrays.
 *
 * Fields take the arguments of WooCommerce's woocommerce_wp_* functions, which draw the built-in types, plus a type, a default, validate and render, and each stores under its name, or its id, without brackets. A field with a render callable and a checkboxes group print in WooCommerce's row around Forms::render_control(), so render returns its control as inline content, escapes, names and describes it, and prints a hidden empty input before it unless the field is disabled.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-type ProductField array{type: string, id?: string, label?: string, description?: string, desc_tip?: bool, name?: string, default?: mixed, options?: array<int|string, string>, placeholder?: string, class?: string, style?: string, wrapper_class?: string, custom_attributes?: array<string, scalar>, disabled?: bool|list<int|string>, validate?: callable(mixed): mixed, render?: callable(array<string, mixed>, mixed, string, string): string}
 * @phpstan-type Group array{class?: list<string>, fields: list<ProductField>|\Closure(\WC_Product): list<ProductField>}
 * @phpstan-type Tab array{label: string, priority?: int, class?: list<string>, supports?: \Closure(\WC_Product): bool, groups: list<Group>}
 */
final class ProductDataTab {
	// region FIELDS AND CONSTANTS

	/**
	 * The tab's definition, evaluated on first use.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     Tab|null
	 */
	protected ?array $tab = null;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string          $id         The tab's key among the product data tabs, which also prefixes its panel's HTML id and its groups filter.
	 * @param   \Closure(): Tab $definition The definition, which is evaluated on first use after init and must not throw.
	 *
	 * @throws  InvalidArgumentException Thrown when the id is not a valid key.
	 */
	public function __construct(
		protected string $id,
		protected \Closure $definition
	) {
		if ( ! self::is_valid_key( $id ) ) {
			throw new InvalidArgumentException( "Product data tab id '$id' is not a lowercase letter, after an optional underscore, followed by lowercase letters, digits, underscores and hyphens." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	// endregion

	// region METHODS

	/**
	 * Registers the hooks that add the tab and print its panel.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function register_hooks(): void {
		\add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		\add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
	}

	// endregion

	// region HOOKS

	/**
	 * Adds the tab for a product the tab supports.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<mixed> $tabs The product data tabs.
	 *
	 * @return  array<mixed>
	 */
	public function add_tab( array $tabs ): array {
		$product = self::get_edited_product();
		if ( \is_null( $product ) || ! $this->supports( $product ) ) {
			return $tabs;
		}

		$tab               = $this->get_tab();
		$tabs[ $this->id ] = array(
			'label'    => $tab['label'],
			'target'   => "{$this->id}_product_data",
			'class'    => $tab['class'] ?? array(),
			'priority' => $tab['priority'] ?? 65,
		);

		return $tabs;
	}

	/**
	 * Prints the panel of a product the tab supports, with one options group per group.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function render_panel(): void {
		$product = self::get_edited_product();
		if ( \is_null( $product ) || ! $this->supports( $product ) ) {
			return;
		}

		echo '<div id="' . \esc_attr( "{$this->id}_product_data" ) . '" class="panel woocommerce_options_panel hidden">';
		foreach ( $this->get_groups( $product ) as $group ) {
			echo '<div class="' . \esc_attr( \implode( ' ', \array_merge( array( 'options_group' ), $group['class'] ?? array() ) ) ) . '">';
			foreach ( self::get_fields( $group, $product ) as $field ) {
				self::render_field( $field, $product );
			}
			echo '</div>';
		}
		echo '</div>';
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the tab's definition, evaluating it on first use.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  Tab
	 */
	protected function get_tab(): array {
		return $this->tab ??= ( $this->definition )();
	}

	/**
	 * Returns whether the tab supports the product, which holds for every product when the tab sets no supports callable.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \WC_Product $product The product.
	 *
	 * @return  bool
	 */
	protected function supports( \WC_Product $product ): bool {
		$supports = $this->get_tab()['supports'] ?? null;

		return \is_null( $supports ) || $supports( $product );
	}

	/**
	 * Returns the tab's groups for the product, as other plugins filter them.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \WC_Product $product The product being edited or saved.
	 *
	 * @return  list<Group>
	 */
	protected function get_groups( \WC_Product $product ): array {
		/**
		 * Filters the groups of the product data tab, so that other plugins can add groups or fields.
		 *
		 * @since   2.0.0
		 * @version 2.0.0
		 *
		 * @param   list<Group> $groups  The tab's groups.
		 * @param   \WC_Product $product The product being edited or saved.
		 */
		return \apply_filters( "{$this->id}_groups", $this->get_tab()['groups'], $product );
	}

	/**
	 * Returns the group's fields for the product.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Group       $group   The group.
	 * @param   \WC_Product $product The product being edited or saved.
	 *
	 * @return  list<ProductField>
	 */
	protected static function get_fields( array $group, \WC_Product $product ): array {
		return $group['fields'] instanceof \Closure ? ( $group['fields'] )( $product ) : $group['fields'];
	}

	/**
	 * Prints the field's row with its stored value, through WooCommerce's function for its type, or through Forms::render_control() for a render callable or a checkboxes group.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ProductField $field   The field.
	 * @param   \WC_Product  $product The product being edited.
	 */
	protected static function render_field( array $field, \WC_Product $product ): void {
		if ( ! isset( $field['id'] ) ) {
			return;
		}

		$id             = $field['id'];
		$key            = self::get_key( $field );
		$field['label'] = self::get_title( $field );
		$field['name']  = $key;
		// WooCommerce reads post meta by id when the value is null, and the edit context keeps a view filter's value from being saved back.
		$field['value'] = ProductMeta::get_value( $product, $key, $field['default'] ?? '', 'edit' );
		$row_class      = \trim( "form-field {$id}_field " . ( $field['wrapper_class'] ?? '' ) );

		if ( isset( $field['render'] ) ) {
			echo '<p class="' . \esc_attr( $row_class ) . '"><label for="' . \esc_attr( $id ) . '">' . \wp_kses_post( $field['label'] ) . '</label>';
			echo Forms::render_control( $field, $field['value'], $key, $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The render callable escapes its control.
			echo '</p>';
			return;
		}

		switch ( $field['type'] ) {
			case 'text':
			case 'number':
			case 'email':
			case 'url':
				\woocommerce_wp_text_input( $field );
				break;
			case 'textarea':
				\woocommerce_wp_textarea_input( $field );
				break;
			case 'checkbox':
				// WooCommerce ignores a checkbox's top-level disabled, and prints its hidden unchecked input even for a disabled checkbox.
				if ( Forms::is_disabled( $field ) ) {
					$field['custom_attributes'] = ( $field['custom_attributes'] ?? array() ) + array( 'disabled' => 'disabled' );
				} else {
					$field['unchecked_value'] = 'no';
				}
				\woocommerce_wp_checkbox( array( 'checked_value' => 'yes' ) + $field );
				break;
			case 'select':
				\woocommerce_wp_select( $field );
				break;
			case 'multiselect':
				if ( ! Forms::is_disabled( $field ) ) {
					echo '<input type="hidden" name="' . \esc_attr( $key ) . '" value="" />';
				}
				$field['name']              = "{$key}[]";
				$field['custom_attributes'] = ( $field['custom_attributes'] ?? array() ) + array( 'multiple' => 'multiple' );
				\woocommerce_wp_select( $field );
				break;
			case 'radio':
				\woocommerce_wp_radio( $field );
				break;
			case 'checkboxes':
				// The row's legend names the group, so Forms draws the choices without one of its own and with the description as their help.
				$group = array(
					'title' => '',
					'desc'  => $field['description'] ?? '',
				) + $field;
				echo '<fieldset class="' . \esc_attr( $row_class ) . '"><legend>' . \wp_kses_post( $field['label'] ) . '</legend>';
				echo Forms::render_control( $group, $field['value'], $key, $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_control() escapes the control.
				echo '</fieldset>';
				break;
		}
	}

	/**
	 * Returns the meta key the field stores under, its name or its id without brackets.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ProductField $field The field.
	 *
	 * @return  string
	 */
	protected static function get_key( array $field ): string {
		return \rtrim( $field['name'] ?? $field['id'] ?? '', '[]' );
	}

	/**
	 * Returns the field's label, or its id when the label is empty, so that every row and rejection names its field.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ProductField $field The field.
	 *
	 * @return  string
	 */
	protected static function get_title( array $field ): string {
		return '' !== ( $field['label'] ?? '' ) ? $field['label'] : ( $field['id'] ?? '' );
	}

	/**
	 * Returns the product that WooCommerce's product data box is editing, or null outside it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  \WC_Product|null
	 */
	protected static function get_edited_product(): ?\WC_Product {
		$product = $GLOBALS['product_object'] ?? null;

		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Returns whether the key is valid as a helper id.
	 *
	 * The pattern accepts every v1 option key, meta key, prefix and slug, so that ports keep their keys.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $key The key.
	 *
	 * @return  bool
	 */
	protected static function is_valid_key( string $key ): bool {
		return 1 === \preg_match( '/\A_?[a-z][a-z0-9_-]*\z/', $key );
	}

	// endregion
}
