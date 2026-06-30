<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration;

use DeepWebSolutions\Framework\Settings\Schema\Exceptions\DuplicateSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\InvalidSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsSection;
use DeepWebSolutions\Framework\WooCommerce\ProductData\Exceptions\InvalidProductDataTabException;
use DeepWebSolutions\Framework\WooCommerce\ProductData\ProductDataFieldStore;
use DeepWebSolutions\Framework\WooCommerce\ProductData\ProductDataTab;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( ProductDataFieldStore::class )]
final class ProductDataFieldStoreTest extends TestCase {
	private const ISOLATED_HOOKS = array(
		'woocommerce_product_data_tabs',
		'woocommerce_product_data_panels',
		'woocommerce_process_product_meta',
		'default_post_metadata',
		'woocommerce_data_store_wp_post_read_meta',
		'woocommerce_before_product_object_save',
	);

	private int $product_id = 0;

	/**
	 * @var array<string, mixed>
	 */
	private array $saved_hooks = array();

	protected function setUp(): void {
		parent::setUp();

		if ( ! \function_exists( 'wc_get_product' ) ) {
			self::markTestSkipped( 'WooCommerce is not active.' );
		}
		if ( ! \function_exists( 'woocommerce_wp_text_input' ) ) {
			require_once WP_PLUGIN_DIR . '/woocommerce/includes/admin/wc-meta-box-functions.php';
		}

		\wp_set_current_user( 1 );

		// Isolate this store's hooks — including the two global default-injection filters — so a registered
		// tab cannot leak its callbacks into other tests; restore the originals in tearDown.
		global $wp_filter;
		foreach ( self::ISOLATED_HOOKS as $hook ) {
			$this->saved_hooks[ $hook ] = $wp_filter[ $hook ] ?? null;
			unset( $wp_filter[ $hook ] );
		}

		$_POST = array();

		$product = new \WC_Product_Simple();
		$product->set_name( 'Probe' );
		$this->product_id = $product->save();
	}

	protected function tearDown(): void {
		$product = \wc_get_product( $this->product_id );
		if ( $product instanceof \WC_Product ) {
			$product->delete( true );
		}
		$_POST                 = array();
		$GLOBALS['thepostid']  = null;
		$GLOBALS['post']       = null;

		global $wp_filter;
		foreach ( $this->saved_hooks as $hook => $saved ) {
			if ( null !== $saved ) {
				$wp_filter[ $hook ] = $saved;
			} else {
				unset( $wp_filter[ $hook ] );
			}
		}

		parent::tearDown();
	}

	// region REGISTRATION + GATING

	public function test_registers_the_tab_for_a_supported_product(): void {
		$this->set_current_product( $this->product_id );
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		$tabs = \apply_filters( 'woocommerce_product_data_tabs', array() );

		self::assertArrayHasKey( 'dws_warranty', $tabs );
		self::assertSame( 'Warranty', $tabs['dws_warranty']['label'] );
		self::assertSame( 'dws_warranty_product_data', $tabs['dws_warranty']['target'] );
		self::assertContains( 'dws_warranty_tab', $tabs['dws_warranty']['class'] );
		self::assertSame( 65, $tabs['dws_warranty']['priority'] );
	}

	public function test_does_not_register_the_tab_for_an_unsupported_product(): void {
		$this->set_current_product( $this->product_id );
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab( supports: static fn ( int $product_id ): bool => false ) );

		$tabs = \apply_filters( 'woocommerce_product_data_tabs', array() );

		self::assertArrayNotHasKey( 'dws_warranty', $tabs );
	}

	public function test_dynamic_classes_closure_contributes_product_type_classes(): void {
		$this->set_current_product( $this->product_id );
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab( classes: static fn ( int $product_id ): array => array( 'show_if_simple' ) ) );

		$tabs = \apply_filters( 'woocommerce_product_data_tabs', array() );

		self::assertContains( 'show_if_simple', $tabs['dws_warranty']['class'] );
		self::assertContains( 'dws_warranty_tab', $tabs['dws_warranty']['class'] );
	}

	// endregion

	// region RENDER

	public function test_renders_the_panel_with_each_field_control(): void {
		$this->set_current_product( $this->product_id );
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		\ob_start();
		\do_action( 'woocommerce_product_data_panels' );
		$html = (string) \ob_get_clean();

		self::assertStringContainsString( 'id="dws_warranty_product_data"', $html );
		self::assertStringContainsString( 'woocommerce_options_panel', $html );
		self::assertStringContainsString( 'name="_dws-wrwc_general_warranty-type"', $html );
	}

	public function test_does_not_render_the_panel_for_an_unsupported_product(): void {
		$this->set_current_product( $this->product_id );
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab( supports: static fn ( int $product_id ): bool => false ) );

		\ob_start();
		\do_action( 'woocommerce_product_data_panels' );
		$html = (string) \ob_get_clean();

		self::assertStringNotContainsString( 'dws_warranty_product_data', $html );
	}

	// endregion

	// region SAVE

	public function test_save_persists_submitted_values(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		$_POST = array( '_dws-wrwc_general_warranty-type' => 'addon' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertSame( 'addon', $store->get( $this->product_id, 'general', 'warranty-type' ) );
	}

	public function test_save_applies_the_field_sanitizer(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab(
			$this->tab_with(
				new SettingsField( id: 'code', type: 'text', label: 'Code', sanitize: static fn ( mixed $v ): string => \strtoupper( (string) $v ) ),
			),
		);

		$_POST = array( '_dws-wrwc_general_code' => 'abc' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertSame( 'ABC', $store->get( $this->product_id, 'general', 'code' ) );
	}

	public function test_save_applies_the_builtin_default_sanitizer(): void {
		$store = new ProductDataFieldStore();
		$raw   = '<b>x</b>';
		$store->register_tab(
			$this->tab_with(
				new SettingsField( id: 'code', type: 'text', label: 'Code' ),
			),
		);

		$_POST = array( '_dws-wrwc_general_code' => $raw );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertSame( \sanitize_text_field( $raw ), $store->get( $this->product_id, 'general', 'code' ) );
	}

	public function test_save_preserves_an_existing_value_when_a_present_submission_is_invalid(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab(
			$this->tab_with(
				new SettingsField( id: 'warranty-type', type: 'select', label: 'Type', options: array( 'global' => 'Global', 'addon' => 'Add-on' ) ),
			),
		);
		$store->set( $this->product_id, 'general', 'warranty-type', 'global' );

		$_POST = array( '_dws-wrwc_general_warranty-type' => 'tampered' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertSame( 'global', $store->get( $this->product_id, 'general', 'warranty-type' ) );
	}

	public function test_save_is_skipped_for_an_unsupported_product(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab( supports: static fn ( int $product_id ): bool => false ) );

		$_POST = array( '_dws-wrwc_general_warranty-type' => 'addon' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertFalse( \metadata_exists( 'post', $this->product_id, '_dws-wrwc_general_warranty-type' ) );
	}

	public function test_save_persists_a_multiselect_selection(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab(
			$this->tab_with(
				new SettingsField( id: 'locations', type: 'multiselect', label: 'Locations', options: array( 'cart' => 'Cart', 'checkout' => 'Checkout', 'email' => 'Email' ) ),
			),
		);

		$_POST = array( '_dws-wrwc_general_locations' => array( 'cart', 'email' ) );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertEqualsCanonicalizing( array( 'cart', 'email' ), $store->get( $this->product_id, 'general', 'locations' ) );
	}

	public function test_save_preserves_a_checkbox_when_validation_rejects_the_submission(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab(
			$this->tab_with(
				// A validator that rejects 'yes' makes the checked submission invalid.
				new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag', validate: static fn ( mixed $v ): bool => 'yes' !== $v ),
			),
		);
		// Prior value differs from the rejected submission so accept-and-store would land 'yes', not the
		// preserved 'no' — the assertion fails unless the rejection-preserve branch actually fires.
		$store->set( $this->product_id, 'general', 'flag', false );

		$_POST = array( '_dws-wrwc_general_flag' => 'yes' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertSame( 'no', $store->get( $this->product_id, 'general', 'flag' ) );
	}

	public function test_save_runs_sanitize_and_validate_on_a_custom_field(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab(
			$this->tab_with(
				new SettingsField(
					id: 'span',
					type: 'dws_custom',
					label: 'Span',
					default_value: 'fallback',
					sanitize: static fn ( mixed $v ): string => \trim( (string) $v ),
					validate: static fn ( mixed $v ): bool => 'reject' !== $v,
				),
				array( 'dws_custom' => $this->noop_renderer() ),
			),
		);

		$_POST = array( '_dws-wrwc_general_span' => '  reject  ' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		// Sanitize trims to 'reject'; the validator rejects it, so the field clears to the sanitized empty
		// (sanitize of an absent submission) rather than the descriptor default.
		self::assertSame( '', $store->get( $this->product_id, 'general', 'span' ) );
	}

	public function test_a_custom_field_without_a_renderer_is_rejected_at_registration(): void {
		$store = new ProductDataFieldStore();

		$this->expectException( InvalidProductDataTabException::class );

		$store->register_tab(
			$this->tab_with(
				new SettingsField( id: 'span', type: 'dws_custom', label: 'Span', sanitize: static fn ( mixed $v ): string => (string) $v ),
			),
		);
	}

	public function test_a_custom_field_without_a_sanitize_is_rejected_at_registration(): void {
		$store = new ProductDataFieldStore();

		$this->expectException( InvalidProductDataTabException::class );

		$store->register_tab(
			$this->tab_with(
				new SettingsField( id: 'span', type: 'dws_custom', label: 'Span' ),
				array( 'dws_custom' => $this->noop_renderer() ),
			),
		);
	}

	public function test_an_absent_custom_field_stores_the_sanitized_empty_not_a_null_or_default(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab(
			$this->tab_with(
				new SettingsField(
					id: 'span',
					type: 'dws_custom',
					label: 'Span',
					default_value: 'fallback',
					sanitize: static fn ( mixed $v ): string => 'sanitized:' . (string) $v,
				),
				array( 'dws_custom' => $this->noop_renderer() ),
			),
		);

		// The submission omits the custom field entirely; its sanitize runs on the empty string, so the stored
		// value is the sanitized empty — never a raw null and never the descriptor default.
		$_POST = array();
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertSame( 'sanitized:', $store->get( $this->product_id, 'general', 'span' ) );
	}

	public function test_a_non_scalar_custom_field_submission_is_coerced_before_sanitize(): void {
		$seen  = null;
		$store = new ProductDataFieldStore();
		$store->register_tab(
			$this->tab_with(
				new SettingsField(
					id: 'span',
					type: 'dws_custom',
					label: 'Span',
					default_value: 'fallback',
					sanitize: static function ( mixed $value ) use ( &$seen ): string {
						$seen = $value;
						return 'sanitized:' . (string) $value;
					},
				),
				array( 'dws_custom' => $this->noop_renderer() ),
			),
		);

		$_POST = array( '_dws-wrwc_general_span' => array( 'tampered' ) );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertSame( '', $seen );
		self::assertSame( 'sanitized:', $store->get( $this->product_id, 'general', 'span' ) );
	}

	public function test_the_before_save_hook_strips_an_injected_default(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		// A fresh read injects the field defaults into the product's meta.
		\clean_post_cache( $this->product_id );
		$product = \wc_get_product( $this->product_id );
		\assert( $product instanceof \WC_Product );
		self::assertSame( 'global', $product->get_meta( '_dws-wrwc_general_warranty-type', true ) );

		// The hook WooCommerce fires before persisting a product removes that injected default, so a save for
		// any reason cannot freeze it as a real row; a deliberately set value would survive.
		\do_action( 'woocommerce_before_product_object_save', $product );

		self::assertSame( '', $product->get_meta( '_dws-wrwc_general_warranty-type', true ) );
	}

	public function test_the_before_save_hook_keeps_a_set_value(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		\clean_post_cache( $this->product_id );
		$product = \wc_get_product( $this->product_id );
		\assert( $product instanceof \WC_Product );
		$product->update_meta_data( '_dws-wrwc_general_warranty-type', 'addon' );

		// A value the consumer set differs from the injected default, so the strip leaves it to persist.
		\do_action( 'woocommerce_before_product_object_save', $product );

		self::assertSame( 'addon', $product->get_meta( '_dws-wrwc_general_warranty-type', true ) );
	}

	// endregion

	// region DEFAULT INJECTION

	public function test_a_new_product_reads_the_default_through_get_post_meta(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		self::assertSame( 'global', \get_post_meta( $this->product_id, '_dws-wrwc_general_warranty-type', true ) );
	}

	public function test_a_new_product_reads_the_default_through_the_wc_product(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		\clean_post_cache( $this->product_id );
		$fresh = \wc_get_product( $this->product_id );
		\assert( $fresh instanceof \WC_Product );

		self::assertSame( 'global', $fresh->get_meta( '_dws-wrwc_general_warranty-type', true ) );
	}

	public function test_a_pre_existing_product_renders_the_default_without_a_stored_row(): void {
		// The product was created and saved in setUp before the tab existed — the regression-prone case.
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		// Both read paths return the descriptor default…
		self::assertSame( 'global', \get_post_meta( $this->product_id, '_dws-wrwc_general_warranty-type', true ) );
		\clean_post_cache( $this->product_id );
		$fresh = \wc_get_product( $this->product_id );
		\assert( $fresh instanceof \WC_Product );
		self::assertSame( 'global', $fresh->get_meta( '_dws-wrwc_general_warranty-type', true ) );

		// …while no physical postmeta row exists until the product is saved.
		self::assertFalse( \metadata_exists( 'post', $this->product_id, '_dws-wrwc_general_warranty-type' ) );
	}

	public function test_default_injection_is_scoped_to_supported_products(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab( supports: static fn ( int $product_id ): bool => false ) );

		self::assertSame( '', \get_post_meta( $this->product_id, '_dws-wrwc_general_warranty-type', true ) );
	}

	public function test_after_save_the_real_value_replaces_the_default(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		$_POST = array( '_dws-wrwc_general_warranty-type' => 'addon' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertTrue( \metadata_exists( 'post', $this->product_id, '_dws-wrwc_general_warranty-type' ) );
		self::assertSame( 'addon', \get_post_meta( $this->product_id, '_dws-wrwc_general_warranty-type', true ) );
	}

	public function test_default_injection_does_not_leak_into_non_products(): void {
		$store = new ProductDataFieldStore();
		// A permissive gate must still be floored by product-existence: the global default filters must not
		// inject a product field's default into an unrelated post that happens to read the same meta key.
		$store->register_tab( $this->tab( supports: static fn ( int $product_id ): bool => true ) );

		$post_id = \wp_insert_post( array( 'post_title' => 'Page', 'post_status' => 'publish' ) );
		\assert( \is_int( $post_id ) );
		self::assertFalse( \wc_get_product( $post_id ) );

		self::assertSame( '', \get_post_meta( $post_id, '_dws-wrwc_general_warranty-type', true ) );

		\wp_delete_post( $post_id, true );
	}

	// endregion

	// region CAPABILITY

	public function test_a_field_the_user_cannot_edit_is_not_rendered(): void {
		$this->set_current_product( $this->product_id );
		$store = new ProductDataFieldStore();
		$store->register_tab(
			$this->tab_with(
				new SettingsField( id: 'secret', type: 'text', label: 'Secret', capability: 'dws_protected_cap' ),
			),
		);

		\ob_start();
		\do_action( 'woocommerce_product_data_panels' );
		$html = (string) \ob_get_clean();

		self::assertStringNotContainsString( '_dws-wrwc_general_secret', $html );
	}

	public function test_a_field_the_user_cannot_edit_is_not_saved(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab(
			$this->tab_with(
				new SettingsField( id: 'secret', type: 'text', label: 'Secret', capability: 'dws_protected_cap' ),
			),
		);

		$_POST = array( '_dws-wrwc_general_secret' => 'tampered' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertFalse( \metadata_exists( 'post', $this->product_id, '_dws-wrwc_general_secret' ) );
	}

	public function test_save_does_not_freeze_an_injected_default_for_a_field_the_user_cannot_edit(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab(
			$this->tab_with(
				new SettingsField( id: 'secret', type: 'text', label: 'Secret', default_value: 'fallback', capability: 'dws_protected_cap' ),
			),
		);

		\clean_post_cache( $this->product_id );
		$product = \wc_get_product( $this->product_id );
		\assert( $product instanceof \WC_Product );
		self::assertSame( 'fallback', $product->get_meta( '_dws-wrwc_general_secret', true ) );

		$_POST = array();
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertFalse( \metadata_exists( 'post', $this->product_id, '_dws-wrwc_general_secret' ) );
	}

	// endregion

	// region CUSTOM FIELD SEAM

	public function test_a_custom_field_type_renders_via_its_renderer_and_saves_via_sanitize(): void {
		$this->set_current_product( $this->product_id );
		$store = new ProductDataFieldStore();
		$store->register_tab(
			new ProductDataTab(
				slug: 'dws_warranty',
				label: 'Warranty',
				meta_key_prefix: '_dws-wrwc_',
				sections: array(
					new SettingsSection(
						'general',
						'General',
						array(
							new SettingsField(
								id: 'span',
								type: 'dws_relative_date_selector',
								label: 'Span',
								sanitize: static fn ( mixed $v ): array => array( 'raw' => $v ),
							),
						),
					),
				),
				custom_renderers: array(
					'dws_relative_date_selector' => static function ( SettingsField $field, mixed $value, string $meta_key ): void {
						echo '<span class="dws-rds" data-key="' . \esc_attr( $meta_key ) . '"></span>';
					},
				),
			),
		);

		\ob_start();
		\do_action( 'woocommerce_product_data_panels' );
		$html = (string) \ob_get_clean();
		self::assertStringContainsString( 'data-key="_dws-wrwc_general_span"', $html );

		$_POST = array( '_dws-wrwc_general_span' => '12' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );
		self::assertSame( array( 'raw' => '12' ), $store->get( $this->product_id, 'general', 'span' ) );
	}

	// endregion

	// region CRUD + UNINSTALL SURFACE

	public function test_crud_round_trips_by_section_and_field(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		self::assertFalse( $store->has( $this->product_id, 'general', 'code' ) );
		self::assertFalse( $store->delete( $this->product_id, 'general', 'code' ) );

		$store->set( $this->product_id, 'general', 'code', 'X1' );
		self::assertTrue( $store->has( $this->product_id, 'general', 'code' ) );
		self::assertSame( 'X1', $store->get( $this->product_id, 'general', 'code' ) );

		self::assertTrue( $store->delete( $this->product_id, 'general', 'code' ) );
		self::assertFalse( $store->has( $this->product_id, 'general', 'code' ) );
	}

	public function test_set_normalizes_a_checkbox_value_to_yes_no(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab_with( new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag' ) ) );

		// A boolean written through CRUD must persist as WooCommerce's 'yes', matching the form-save path.
		$store->set( $this->product_id, 'general', 'flag', true );

		self::assertSame( 'yes', $store->get( $this->product_id, 'general', 'flag' ) );
	}

	public function test_set_persists_a_value_equal_to_the_default(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		// Setting a field to a value that equals its default must persist a real row — matching the form save's
		// store-all — rather than be mistaken for an injected default and stripped by the pre-save hook.
		$store->set( $this->product_id, 'general', 'warranty-type', 'global' );

		self::assertTrue( $store->has( $this->product_id, 'general', 'warranty-type' ) );
		self::assertSame( 'global', $store->get( $this->product_id, 'general', 'warranty-type' ) );
	}

	public function test_get_returns_the_descriptor_default_for_an_unstored_supported_field(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		// get() reads the descriptor default while nothing is stored, agreeing with the injected read paths and
		// with has() reporting no real value yet.
		self::assertFalse( $store->has( $this->product_id, 'general', 'warranty-type' ) );
		self::assertSame( 'global', $store->get( $this->product_id, 'general', 'warranty-type' ) );
	}

	public function test_get_returns_the_caller_fallback_for_an_unsupported_product(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab( supports: static fn ( int $product_id ): bool => false ) );

		self::assertSame( 'na', $store->get( $this->product_id, 'general', 'warranty-type', 'na' ) );
	}

	public function test_meta_key_derivation_and_override(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab(
			new ProductDataTab(
				slug: 'dws_warranty',
				label: 'Warranty',
				meta_key_prefix: '_dws-wrwc_',
				sections: array(
					new SettingsSection(
						'general',
						'General',
						array(
							new SettingsField( id: 'derived', type: 'text', label: 'D' ),
							new SettingsField( id: 'explicit', type: 'text', label: 'E', meta_key: '_legacy_v1_key' ),
						),
					),
				),
			),
		);

		self::assertSame( '_dws-wrwc_general_derived', $store->meta_key( 'general', 'derived' ) );
		self::assertSame( '_legacy_v1_key', $store->meta_key( 'general', 'explicit' ) );
		self::assertEqualsCanonicalizing(
			array( '_dws-wrwc_general_derived', '_legacy_v1_key' ),
			$store->meta_keys(),
		);
	}

	public function test_a_duplicate_meta_key_is_rejected(): void {
		$store = new ProductDataFieldStore();

		$this->expectException( DuplicateSettingsFieldException::class );

		$store->register_tab(
			new ProductDataTab(
				slug: 'dws_warranty',
				label: 'Warranty',
				meta_key_prefix: '_dws-wrwc_',
				sections: array(
					new SettingsSection( 'a', 'A', array( new SettingsField( id: 'k', type: 'text', label: 'A', meta_key: '_shared' ) ) ),
					new SettingsSection( 'b', 'B', array( new SettingsField( id: 'k', type: 'text', label: 'B', meta_key: '_shared' ) ) ),
				),
			),
		);
	}

	public function test_crud_on_an_unregistered_field_throws(): void {
		$store = new ProductDataFieldStore();
		$store->register_tab( $this->tab() );

		$this->expectException( InvalidSettingsFieldException::class );

		$store->get( $this->product_id, 'general', 'nope' );
	}

	// endregion

	// region HELPERS

	private function set_current_product( int $product_id ): void {
		$GLOBALS['thepostid'] = $product_id;
		$GLOBALS['post']      = \get_post( $product_id );
	}

	/**
	 * @param list<string>|\Closure $classes
	 */
	private function tab( ?\Closure $supports = null, array|\Closure $classes = array() ): ProductDataTab {
		return new ProductDataTab(
			slug: 'dws_warranty',
			label: 'Warranty',
			meta_key_prefix: '_dws-wrwc_',
			sections: array(
				new SettingsSection(
					'general',
					'General',
					array(
						new SettingsField( id: 'warranty-type', type: 'select', label: 'Type', default_value: 'global', options: array( 'global' => 'Global', 'addon' => 'Add-on' ) ),
						new SettingsField( id: 'code', type: 'text', label: 'Code' ),
					),
				),
			),
			classes: $classes,
			supports_product: $supports,
		);
	}

	/**
	 * @param array<string, callable> $custom_renderers
	 */
	private function tab_with( SettingsField $field, array $custom_renderers = array() ): ProductDataTab {
		return new ProductDataTab(
			slug: 'dws_warranty',
			label: 'Warranty',
			meta_key_prefix: '_dws-wrwc_',
			sections: array( new SettingsSection( 'general', 'General', array( $field ) ) ),
			custom_renderers: $custom_renderers,
		);
	}

	private function noop_renderer(): \Closure {
		return static function ( SettingsField $field, mixed $value, string $meta_key ): void {};
	}

	// endregion
}
