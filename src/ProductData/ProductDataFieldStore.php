<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\ProductData;

use DeepWebSolutions\Framework\Settings\Schema\Exceptions\DuplicateSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\InvalidSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\FieldProcessor;
use DeepWebSolutions\Framework\Settings\Schema\FieldType;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\WooCommerce\ProductData\Exceptions\InvalidProductDataTabException;

use function DeepWebSolutions\Framework\Settings\Schema\is_field_editable_by_current_user;
use function DeepWebSolutions\Framework\WooCommerce\to_yes_no;

/**
 * Registers a WooCommerce product-data settings tab and persists its fields as product meta.
 *
 * One store drives one tab. register_tab() wires WooCommerce's three product hooks — add the tab, render
 * its panel, save it — plus the two default-metadata filters that make a product predating a field render
 * its descriptor default instead of a blank. Rendering uses native woocommerce_wp_* controls; saving is
 * framework-owned (WooCommerce verifies the product-edit nonce and capability before its save hook fires).
 * Uninstall is the consumer's Installer concern; meta_keys() exposes the key set for it.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class ProductDataFieldStore {
	// region FIELDS AND CONSTANTS

	/**
	 * The registered tab; null until register_tab() runs.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     ?ProductDataTab
	 */
	protected ?ProductDataTab $tab = null;

	/**
	 * Registered fields keyed by their resolved meta key, for default injection and the save sweep.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, SettingsField>
	 */
	protected array $by_meta_key = array();

	/**
	 * Resolved meta key keyed by "section_id\0field_id", for CRUD addressing.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, string>
	 */
	protected array $by_address = array();

	/**
	 * The meta key a set() is persisting, which the pre-save strip keeps; null outside a set().
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     ?string
	 */
	protected ?string $preserve_key = null;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ProductDataFieldRenderer $renderer  Renderer for taxonomy-typed field controls.
	 * @param   FieldProcessor           $processor Processor for sanitizing submitted taxonomy-typed values.
	 */
	public function __construct(
		protected ProductDataFieldRenderer $renderer = new ProductDataFieldRenderer(),
		protected FieldProcessor $processor = new FieldProcessor(),
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers the tab: indexes its fields and wires WooCommerce's product hooks plus the default filters.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ProductDataTab $tab Tab to register.
	 *
	 * @throws  DuplicateSettingsFieldException If two fields resolve to the same meta key.
	 * @throws  InvalidProductDataTabException If a custom field type has no renderer or no sanitize callback.
	 */
	public function register_tab( ProductDataTab $tab ): void {
		$this->tab = $tab;
		$this->index_fields( $tab );

		\add_filter( 'woocommerce_product_data_tabs', fn ( array $tabs ): array => $this->register_tab_filter( $tabs ) );
		\add_action( 'woocommerce_product_data_panels', fn () => $this->render_panel() );
		\add_action( 'woocommerce_process_product_meta', fn ( int $product_id ) => $this->save( $product_id ) );
		\add_filter( 'default_post_metadata', fn ( mixed $value, int $object_id, string $meta_key ): mixed => $this->inject_default( $value, $object_id, $meta_key ), 99, 3 );
		\add_filter( 'woocommerce_data_store_wp_post_read_meta', fn ( array $meta_data, object $wc_object ): array => $this->inject_default_bulk( $meta_data, $wc_object ), 99, 2 );
		\add_action( 'woocommerce_before_product_object_save', fn ( \WC_Product $product ) => $this->strip_injected_defaults( $product ) );
	}

	/**
	 * Returns a field's effective value for a product: its stored value, or its descriptor default while none is
	 * stored on a supported product; the caller fallback for a non-product or an unsupported one.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int    $product_id    Product to read.
	 * @param   string $section_id    Section the field belongs to.
	 * @param   string $field_id      Field to read.
	 * @param   mixed  $default_value Value returned for a non-product or an unsupported product with nothing stored.
	 *
	 * @throws  InvalidSettingsFieldException If the field is not registered on this tab.
	 *
	 * @return  mixed
	 */
	public function get( int $product_id, string $section_id, string $field_id, mixed $default_value = null ): mixed {
		$meta_key = $this->require_meta_key( $section_id, $field_id );
		$product  = \wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return $default_value;
		}
		if ( \metadata_exists( 'post', $product_id, $meta_key ) ) {
			return $product->get_meta( $meta_key, true );
		}

		// No stored value: a supported product reads the descriptor default — the value the registered default
		// filters hand every other read path — resolved explicitly here rather than via the load-time injection.
		return $this->is_supported( $product_id ) ? $this->default_value( $this->by_meta_key[ $meta_key ] ) : $default_value;
	}

	/**
	 * Persists a field's value for a product.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int    $product_id Product to write.
	 * @param   string $section_id Section the field belongs to.
	 * @param   string $field_id   Field to write.
	 * @param   mixed  $value      Value to persist.
	 *
	 * @throws  InvalidSettingsFieldException If the field is not registered on this tab.
	 */
	public function set( int $product_id, string $section_id, string $field_id, mixed $value ): void {
		$meta_key = $this->require_meta_key( $section_id, $field_id );
		$product  = \wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		// A checkbox persists as WooCommerce's yes/no string on every write path, so its render and reads agree.
		if ( FieldType::Checkbox === FieldType::tryFrom( $this->by_meta_key[ $meta_key ]->type ) ) {
			$value = to_yes_no( $value );
		}

		$product->update_meta_data( $meta_key, $value );

		// The save fires the pre-save strip; flag this key so a value equal to its default is kept rather than
		// mistaken for an untouched injection, matching the form save, which materializes every editable field.
		$this->preserve_key = $meta_key;
		try {
			$product->save();
		} finally {
			$this->preserve_key = null;
		}
	}

	/**
	 * Whether a real value is stored for a field on a product — the injected default does not count.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int    $product_id Product to check.
	 * @param   string $section_id Section the field belongs to.
	 * @param   string $field_id   Field to check.
	 *
	 * @throws  InvalidSettingsFieldException If the field is not registered on this tab.
	 *
	 * @return  bool
	 */
	public function has( int $product_id, string $section_id, string $field_id ): bool {
		return \metadata_exists( 'post', $product_id, $this->require_meta_key( $section_id, $field_id ) );
	}

	/**
	 * Deletes a field's stored value from a product.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int    $product_id Product to clear.
	 * @param   string $section_id Section the field belongs to.
	 * @param   string $field_id   Field to clear.
	 *
	 * @throws  InvalidSettingsFieldException If the field is not registered on this tab.
	 *
	 * @return  bool True if a stored value was deleted, false if none existed.
	 */
	public function delete( int $product_id, string $section_id, string $field_id ): bool {
		$meta_key = $this->require_meta_key( $section_id, $field_id );
		if ( ! \metadata_exists( 'post', $product_id, $meta_key ) ) {
			return false;
		}

		$product = \wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		$product->delete_meta_data( $meta_key );
		$product->save();

		return true;
	}

	/**
	 * Resolves a field's product-meta key.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $section_id Section the field belongs to.
	 * @param   string $field_id   Field to resolve.
	 *
	 * @throws  InvalidSettingsFieldException If the field is not registered on this tab.
	 *
	 * @return  string
	 */
	public function meta_key( string $section_id, string $field_id ): string {
		return $this->require_meta_key( $section_id, $field_id );
	}

	/**
	 * Returns every meta key the tab owns, for the consumer's uninstall cleanup.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  list<string>
	 */
	public function meta_keys(): array {
		return \array_keys( $this->by_meta_key );
	}

	// endregion

	// region HOOKS

	/**
	 * Adds the tab to WooCommerce's product-data tabs for a supported product. Filters woocommerce_product_data_tabs.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<string, mixed> $tabs Tabs registered so far.
	 *
	 * @return  array<string, mixed>
	 */
	protected function register_tab_filter( array $tabs ): array {
		global $thepostid;
		$product_id = (int) $thepostid;
		if ( ! $this->is_supported( $product_id ) ) {
			return $tabs;
		}

		$tab                = $this->tab();
		$tabs[ $tab->slug ] = array(
			'label'    => $tab->label,
			'target'   => $tab->slug . '_product_data',
			'class'    => \array_merge( array( $tab->slug . '_tab' ), $this->tab_classes( $product_id ) ),
			'priority' => $tab->priority,
		);

		return $tabs;
	}

	/**
	 * Outputs the tab's panel with each editable field's control. Hooked to woocommerce_product_data_panels.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	protected function render_panel(): void {
		global $thepostid;
		$product_id = (int) $thepostid;
		if ( ! $this->is_supported( $product_id ) ) {
			return;
		}

		$tab = $this->tab();
		echo '<div id="' . \esc_attr( $tab->slug . '_product_data' ) . '" class="panel woocommerce_options_panel">';

		foreach ( $tab->sections as $section ) {
			$fields = \array_values( \array_filter( $section->fields, static fn ( SettingsField $field ): bool => is_field_editable_by_current_user( $field ) ) );
			if ( array() === $fields ) {
				continue;
			}

			echo '<div class="options_group ' . \esc_attr( $tab->slug . '_' . $section->id ) . '">';
			foreach ( $fields as $field ) {
				$meta_key = $this->meta_key_for( $section->id, $field );
				$value    = \get_post_meta( $product_id, $meta_key, true );

				if ( null !== FieldType::tryFrom( $field->type ) ) {
					$this->renderer->render( $field, $value, $meta_key );
				} elseif ( isset( $tab->custom_renderers[ $field->type ] ) ) {
					( $tab->custom_renderers[ $field->type ] )( $field, $value, $meta_key );
				}
			}
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Persists the tab's submitted fields onto a supported product. Hooked to woocommerce_process_product_meta.
	 *
	 * WooCommerce verifies the product-edit nonce and the edit_post capability before firing this hook; each
	 * field is additionally gated on its own capability. Every editable field is written and the product is
	 * saved once, so a field left at its default holds a real value after the first save.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $product_id Product being saved.
	 */
	protected function save( int $product_id ): void {
		if ( ! $this->is_supported( $product_id ) ) {
			return;
		}
		$product = \wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		foreach ( $this->tab()->sections as $section ) {
			foreach ( $section->fields as $field ) {
				if ( ! is_field_editable_by_current_user( $field ) ) {
					continue;
				}
				$meta_key = $this->meta_key_for( $section->id, $field );
				$product->update_meta_data( $meta_key, $this->submitted_value( $field, $meta_key ) );
			}
		}

		$product->save_meta_data();
	}

	/**
	 * Supplies a field's default when a single post-meta read finds nothing stored. Filters default_post_metadata.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed  $value     Default resolved so far.
	 * @param   int    $object_id Object being read.
	 * @param   string $meta_key  Meta key being read.
	 *
	 * @return  mixed
	 */
	protected function inject_default( mixed $value, int $object_id, string $meta_key ): mixed {
		$field = $this->by_meta_key[ $meta_key ] ?? null;
		if ( null === $field ) {
			return $value;
		}
		if ( ! $this->is_supported( $object_id ) ) {
			return $value;
		}

		return $this->default_value( $field );
	}

	/**
	 * Splices each unstored field's default into a product's bulk meta read. Filters woocommerce_data_store_wp_post_read_meta.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<int, object> $meta_data Raw meta rows WooCommerce read for the object.
	 * @param   object             $wc_object    Object the meta was read for.
	 *
	 * @return  array<int, object>
	 */
	protected function inject_default_bulk( array $meta_data, object $wc_object ): array {
		// The object itself proves the product exists, so the existence floor is skipped here — this runs on
		// every product meta hydration, front-end loops included — and only the consumer gate is evaluated.
		if ( ! $wc_object instanceof \WC_Product || ! $this->passes_gate( $wc_object->get_id() ) ) {
			return $meta_data;
		}

		$existing = \array_flip( \array_column( $meta_data, 'meta_key' ) );
		foreach ( $this->by_meta_key as $meta_key => $field ) {
			if ( ! isset( $existing[ $meta_key ] ) ) {
				$meta_data[] = (object) array(
					'meta_id'    => 0,
					'meta_key'   => $meta_key,
					'meta_value' => $this->default_value( $field ),
				);
			}
		}

		return $meta_data;
	}

	/**
	 * Drops the read-time default rows injected for unstored fields before WooCommerce persists a product.
	 * Hooked to woocommerce_before_product_object_save.
	 *
	 * The bulk-read injection splices a synthetic row for every unstored field so a predating product renders
	 * its default; left in place, a save for any reason — a checkout stock decrement — would freeze that
	 * default as a real row. A row is an untouched injection when it has no stored counterpart and still holds
	 * the default, so it is removed; a deliberately set value (which differs, or already has a stored row) is
	 * left to persist.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \WC_Product $product Product about to be saved.
	 */
	protected function strip_injected_defaults( \WC_Product $product ): void {
		$product_id = $product->get_id();
		foreach ( $this->by_meta_key as $meta_key => $field ) {
			if ( $meta_key === $this->preserve_key ) {
				continue; // a value a set() is persisting to its own default, kept rather than read as an injection.
			}
			if ( \metadata_exists( 'post', $product_id, $meta_key ) ) {
				continue;
			}
			if ( $product->get_meta( $meta_key, true ) === $this->default_value( $field ) ) {
				$product->delete_meta_data( $meta_key );
			}
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Indexes the tab's fields by meta key and address, rejecting two fields that resolve to the same meta key.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ProductDataTab $tab Tab whose fields to index.
	 *
	 * @throws  DuplicateSettingsFieldException If two fields resolve to the same meta key.
	 * @throws  InvalidProductDataTabException If a custom field type has no renderer or no sanitize callback.
	 */
	protected function index_fields( ProductDataTab $tab ): void {
		$this->by_meta_key = array();
		$this->by_address  = array();

		foreach ( $tab->sections as $section ) {
			foreach ( $section->fields as $field ) {
				$this->assert_custom_field_complete( $tab, $field );
				$meta_key = $this->meta_key_for( $section->id, $field );
				if ( \array_key_exists( $meta_key, $this->by_meta_key ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
					throw new DuplicateSettingsFieldException( "Duplicate product-data meta key on tab '$tab->slug': '$meta_key'" );
				}
				$this->by_meta_key[ $meta_key ]                                 = $field;
				$this->by_address[ $this->address( $section->id, $field->id ) ] = $meta_key;
			}
		}
	}

	/**
	 * Rejects a custom field type the tab cannot handle: render and save both need a consumer seam, so a type
	 * outside the framework taxonomy must declare a renderer on the tab and a sanitize callback on the field.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ProductDataTab $tab   Tab the field belongs to.
	 * @param   SettingsField  $field Field to validate.
	 *
	 * @throws  InvalidProductDataTabException If a custom field type has no renderer or no sanitize callback.
	 */
	protected function assert_custom_field_complete( ProductDataTab $tab, SettingsField $field ): void {
		if ( null !== FieldType::tryFrom( $field->type ) ) {
			return;
		}
		if ( ! isset( $tab->custom_renderers[ $field->type ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			throw new InvalidProductDataTabException( "Custom field type '$field->type' on tab '$tab->slug' has no renderer." );
		}
		if ( null === $field->sanitize ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			throw new InvalidProductDataTabException( "Custom field type '$field->type' on tab '$tab->slug' has no sanitize callback." );
		}
	}

	/**
	 * Resolves a field's meta key: its explicit meta_key override, else the prefix + section + field id.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string        $section_id Section the field belongs to.
	 * @param   SettingsField $field      Field to resolve.
	 *
	 * @return  string
	 */
	protected function meta_key_for( string $section_id, SettingsField $field ): string {
		return $field->meta_key ?? ( $this->tab()->meta_key_prefix . $section_id . '_' . $field->id );
	}

	/**
	 * Resolves a registered field's meta key by address, rejecting an unregistered field.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $section_id Section the field belongs to.
	 * @param   string $field_id   Field to resolve.
	 *
	 * @throws  InvalidSettingsFieldException If the field is not registered on this tab.
	 *
	 * @return  string
	 */
	protected function require_meta_key( string $section_id, string $field_id ): string {
		$address = $this->address( $section_id, $field_id );
		if ( ! isset( $this->by_address[ $address ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			throw new InvalidSettingsFieldException( "Product-data field '$section_id/$field_id' is not registered on this tab." );
		}

		return $this->by_address[ $address ];
	}

	/**
	 * The index key for a field address.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $section_id Section id.
	 * @param   string $field_id   Field id.
	 *
	 * @return  string
	 */
	protected function address( string $section_id, string $field_id ): string {
		return $section_id . "\0" . $field_id;
	}

	/**
	 * Turns a field's raw submission into the value to persist: a yes/no checkbox string, the field's own
	 * sanitizer for a custom type, otherwise the processor's sanitize/validate result.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsField $field    Field being saved.
	 * @param   string        $meta_key Submission key to read.
	 *
	 * @return  mixed
	 */
	protected function submitted_value( SettingsField $field, string $meta_key ): mixed {
		$type = FieldType::tryFrom( $field->type );

		if ( FieldType::Checkbox === $type ) {
			// The checkbox submit convention is its value when checked, nothing when unchecked; the descriptor's
			// sanitize/validate still apply, falling back to the off value when validation rejects.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product-edit nonce before woocommerce_process_product_meta fires.
			return $this->sanitize_and_validate( $field, isset( $_POST[ $meta_key ] ) ? 'yes' : 'no', 'no' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by WooCommerce; see above.
		$raw = isset( $_POST[ $meta_key ] ) ? \wp_unslash( $_POST[ $meta_key ] ) : null;

		if ( null === $type ) {
			// A custom type's sanitize, required at registration, is the consumer's seam: it runs on the
			// submission (an empty string when the field is absent). A value its validator rejects clears to the
			// sanitized empty — never the descriptor default, never a raw null, never a skipped write — so a save
			// cannot freeze the default for a predating product. This is the deliberate inverse of
			// FieldProcessor::process_custom_or_reject(), which folds a built-in custom rejection to the default.
			\assert( $field->sanitize instanceof \Closure );
			$value = ( $field->sanitize )( $raw ?? '' );
			if ( null !== $field->validate && ! ( $field->validate )( $value ) ) {
				return ( $field->sanitize )( '' );
			}

			return $value;
		}

		return $this->processor->process( $field, null === $raw ? array() : array( $field->id => $raw ) );
	}

	/**
	 * Runs a field's sanitize then validate, returning the fallback when validation rejects the value.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsField $field    Field whose closures to apply.
	 * @param   mixed         $value    Value to sanitize and validate.
	 * @param   mixed         $rejected Value returned when validation rejects.
	 *
	 * @return  mixed
	 */
	protected function sanitize_and_validate( SettingsField $field, mixed $value, mixed $rejected ): mixed {
		if ( null !== $field->sanitize ) {
			$value = ( $field->sanitize )( $value );
		}
		if ( null !== $field->validate && ! ( $field->validate )( $value ) ) {
			return $rejected;
		}

		return $value;
	}

	/**
	 * A field's default for injection: a checkbox default is normalized to WooCommerce's yes/no string.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsField $field Field whose default to resolve.
	 *
	 * @return  mixed
	 */
	protected function default_value( SettingsField $field ): mixed {
		return FieldType::Checkbox === FieldType::tryFrom( $field->type )
			? to_yes_no( $field->default_value )
			: $field->default_value;
	}

	/**
	 * Whether the tab applies to a product: a product WooCommerce recognizes, narrowed by the tab's gate when set.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $product_id Product to check.
	 *
	 * @return  bool
	 */
	protected function is_supported( int $product_id ): bool {
		// Product existence is the non-overridable floor: the global default filters must never inject into a
		// non-product post. A consumer's gate only narrows the set of products further.
		return false !== \WC_Product_Factory::get_product_type( $product_id ) && $this->passes_gate( $product_id );
	}

	/**
	 * Whether the tab's consumer gate admits a product, regardless of product existence.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $product_id Product to check against the gate.
	 *
	 * @return  bool
	 */
	protected function passes_gate( int $product_id ): bool {
		$gate = $this->tab()->supports_product;

		return null === $gate || (bool) $gate( $product_id );
	}

	/**
	 * The tab's extra CSS classes for a product: its literal list, or the result of its classes closure.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $product_id Product the classes are resolved for.
	 *
	 * @return  list<string>
	 */
	protected function tab_classes( int $product_id ): array {
		$classes = $this->tab()->classes;

		return \is_array( $classes ) ? \array_values( $classes ) : \array_values( (array) $classes( $product_id ) );
	}

	/**
	 * The registered tab, asserted present — every caller runs after register_tab().
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  ProductDataTab
	 */
	protected function tab(): ProductDataTab {
		\assert( $this->tab instanceof ProductDataTab );

		return $this->tab;
	}

	// endregion
}
