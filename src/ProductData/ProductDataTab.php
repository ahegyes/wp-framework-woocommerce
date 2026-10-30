<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\ProductData;

use DeepWebSolutions\Framework\Settings\Forms;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * Adds a tab to WooCommerce's product data box whose panel edits product meta through field arrays.
 *
 * Fields take the arguments of WooCommerce's woocommerce_wp_* functions, which draw the built-in types, plus a type, a default, validate and render, and each stores under its name, or its id, without brackets. A field with a render callable and a checkboxes group print in WooCommerce's row around Forms::render_control(), and that row is a paragraph, or for a group type a fieldset its label names, so render returns its control as inline content, escapes, names and describes it, and prints a hidden empty input before it unless the field is disabled.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-type ProductField array{type: string, id?: string, label?: string, description?: string, desc_tip?: bool, name?: string, default?: mixed, options?: array<int|string, string>, placeholder?: string, class?: string, style?: string, wrapper_class?: string, custom_attributes?: array<string, scalar>, disabled?: bool|list<int|string>, validate?: callable(mixed): mixed, render?: callable(array<string, mixed>, mixed, string, string): string}
 * @phpstan-type Group array{class?: list<string>, fields: list<ProductField>}
 * @phpstan-type Tab array{label: string, priority?: int, class?: list<string>, groups: list<Group>}
 */
final class ProductDataTab {
	// region FIELDS AND CONSTANTS

	/**
	 * The tab of each product the request has resolved, or null where the tab does not apply, keyed by the product's id.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<int, Tab|null>
	 */
	protected array $tabs = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string                            $id         The tab's key among the product data tabs, which also prefixes its panel's HTML id and its groups filter.
	 * @param   ProductDataTabDefinitionInterface $definition The definition, which the tab asks for once per product, on first use after init.
	 *
	 * @throws  InvalidArgumentException Thrown when the id is not a valid key.
	 */
	public function __construct(
		protected string $id,
		protected ProductDataTabDefinitionInterface $definition
	) {
		Forms::check_helper_id( $id );
	}

	// endregion

	// region METHODS

	/**
	 * Registers the hooks that add the tab, print its panel and save its fields.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  void
	 */
	public function register_hooks(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_fields' ) );
	}

	// endregion

	// region HOOKS

	/**
	 * Adds the tab for a product the tab applies to.
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
		$tab     = \is_null( $product ) ? null : $this->get_tab( $product );
		if ( \is_null( $tab ) ) {
			return $tabs;
		}

		$tabs[ $this->id ] = array(
			'label'    => $tab['label'],
			'target'   => "{$this->id}_product_data",
			'class'    => $tab['class'] ?? array(),
			'priority' => $tab['priority'] ?? 65,
		);

		return $tabs;
	}

	/**
	 * Prints the panel of a product the tab applies to, with one options group per group.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  void
	 */
	public function render_panel(): void {
		$product = self::get_edited_product();
		$tab     = \is_null( $product ) ? null : $this->get_tab( $product );
		if ( \is_null( $product ) || \is_null( $tab ) ) {
			return;
		}

		echo '<div id="' . esc_attr( "{$this->id}_product_data" ) . '" class="panel woocommerce_options_panel hidden">';
		foreach ( $this->get_groups( $tab, $product ) as $group ) {
			echo '<div class="' . esc_attr( \implode( ' ', \array_merge( array( 'options_group' ), $group['class'] ?? array() ) ) ) . '">';
			foreach ( $group['fields'] as $field ) {
				self::render_field( $field, $product );
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Sets the accepted fields of the edit form's submission on the product WooCommerce is about to save, for a product the tab applies to.
	 *
	 * A field the form did not post, because it was not rendered or is disabled, keeps its stored value, and a rejected field keeps its stored value and adds one of WooCommerce's meta box errors that names it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \WC_Product $product The product being saved.
	 *
	 * @return  void
	 */
	public function save_fields( \WC_Product $product ): void {
		$tab = $this->get_tab( $product );
		if ( \is_null( $tab ) ) {
			return;
		}

		$fields = array();
		foreach ( $this->get_groups( $tab, $product ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( isset( $field['id'] ) ) {
					$fields[ $field['id'] ] = $field;
				}
			}
		}

		$posted    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce verifies its meta box nonce and the edit_post capability before it fires this action, and Forms::sanitize_fields() sanitizes each field.
		$submitted = array();
		foreach ( $fields as $id => $field ) {
			if ( \array_key_exists( self::get_key( $field ), $posted ) ) {
				$submitted[ $id ] = $posted[ self::get_key( $field ) ];
			}
		}

		$result = Forms::sanitize_fields( \array_values( $fields ), $submitted );
		foreach ( $result['values'] as $id => $value ) {
			$product->update_meta_data( self::get_key( $fields[ $id ] ), $value ); // @phpstan-ignore argument.type (WooCommerce stores any value its data store can serialize, though its docblock lists only strings and arrays.)
		}
		foreach ( $result['errors'] as $id => $error ) {
			// WooCommerce prints its meta box errors through wp_kses_post(), which would keep markup from a label or a rejection's message.
			\WC_Admin_Meta_Boxes::add_error( esc_html( Forms::get_rejection_message( self::get_title( $fields[ $id ] ), $error ) ) );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the tab for the product, or null when the tab does not apply to it, resolving it once per product.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \WC_Product $product The product being edited or saved.
	 *
	 * @return  Tab|null
	 */
	protected function get_tab( \WC_Product $product ): ?array {
		$product_id = $product->get_id();
		if ( ! \array_key_exists( $product_id, $this->tabs ) ) {
			$this->tabs[ $product_id ] = $this->definition->get_product_data_tab( $this->id, $product );
		}

		return $this->tabs[ $product_id ];
	}

	/**
	 * Returns the tab's groups for the product, as other plugins filter them.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Tab         $tab     The tab for the product.
	 * @param   \WC_Product $product The product being edited or saved.
	 *
	 * @return  list<Group>
	 */
	protected function get_groups( array $tab, \WC_Product $product ): array {
		/**
		 * Filters the groups of the product data tab, so that other plugins can add groups or fields.
		 *
		 * @since   2.0.0
		 * @version 2.0.0
		 *
		 * @param   list<Group> $groups  The tab's groups.
		 * @param   \WC_Product $product The product being edited or saved.
		 */
		return apply_filters( "{$this->id}/product_data_tab/groups", $tab['groups'], $product );
	}

	/**
	 * Prints the field's row with its stored value, through WooCommerce's function for its type, or through Forms::render_control() for a render callable or a checkboxes group.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ProductField $field   The field.
	 * @param   \WC_Product  $product The product being edited.
	 *
	 * @return  void
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
			$group = Forms::is_group( $field );
			echo $group ? '<fieldset class="' . esc_attr( $row_class ) . '"><legend>' . wp_kses_post( $field['label'] ) . '</legend>' : '<p class="' . esc_attr( $row_class ) . '"><label for="' . esc_attr( $id ) . '">' . wp_kses_post( $field['label'] ) . '</label>';
			echo Forms::render_control( $field, $field['value'], $key, $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The render callable escapes its control.
			echo $group ? '</fieldset>' : '</p>';
			return;
		}

		switch ( $field['type'] ) {
			case 'text':
			case 'number':
			case 'email':
			case 'url':
				if ( \is_string( $field['value'] ) ) { // WooCommerce prints the value through esc_attr(), which leaves an escaped value unchanged.
					$field['value'] = Forms::escape_attribute( $field['value'] );
				}
				$field['custom_attributes'] = ( $field['custom_attributes'] ?? array() ) + ( Forms::DEFAULT_CUSTOM_ATTRIBUTES[ $field['type'] ] ?? array() );
				woocommerce_wp_text_input( $field );
				break;
			case 'textarea':
				woocommerce_wp_textarea_input( $field );
				break;
			case 'checkbox':
				if ( Forms::is_disabled( $field ) ) { // WooCommerce ignores a checkbox's top-level disabled, and prints its hidden unchecked input even for a disabled checkbox.
					$field['custom_attributes'] = ( $field['custom_attributes'] ?? array() ) + array( 'disabled' => 'disabled' );
				} else {
					echo Forms::render_hidden_input( $key, 'no' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_hidden_input() escapes the input.
				}
				woocommerce_wp_checkbox( array( 'checked_value' => 'yes' ) + $field );
				break;
			case 'select':
				woocommerce_wp_select( $field );
				break;
			case 'multiselect':
				if ( ! Forms::is_disabled( $field ) ) {
					echo Forms::render_hidden_input( $key, '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_hidden_input() escapes the input.
				}
				$field['name']              = "{$key}[]";
				$field['custom_attributes'] = ( $field['custom_attributes'] ?? array() ) + array( 'multiple' => 'multiple' );
				woocommerce_wp_select( $field );
				break;
			case 'radio':
				woocommerce_wp_radio( $field );
				break;
			case 'checkboxes':
				// The row's legend names the group, so Forms draws the choices without one of its own and with the description as their help.
				$group = array(
					'title' => '',
					'desc'  => $field['description'] ?? '',
				) + $field;
				echo '<fieldset class="' . esc_attr( $row_class ) . '"><legend>' . wp_kses_post( $field['label'] ) . '</legend>';
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
		return Forms::get_title( array( 'title' => $field['label'] ?? '' ) + $field );
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

	// endregion
}
