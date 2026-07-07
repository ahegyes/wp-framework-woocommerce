<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration\ProductData;

use DeepWebSolutions\Framework\Settings\Schema\Exceptions\DuplicateSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\InvalidSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\CustomFieldType;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsSection;
use DeepWebSolutions\Framework\Settings\Tests\Support\IsolatesHooks;
use DeepWebSolutions\Framework\WooCommerce\ProductData\Exceptions\InvalidProductDataTabException;
use DeepWebSolutions\Framework\WooCommerce\ProductData\ProductDataFieldRenderer;
use DeepWebSolutions\Framework\WooCommerce\ProductData\ProductDataFieldSurface;
use DeepWebSolutions\Framework\WooCommerce\ProductData\ProductDataTab;
use DeepWebSolutions\Framework\WooCommerce\Tests\Support\RequiresWooCommerce;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( ProductDataFieldSurface::class )]
final class ProductDataFieldSurfaceTest extends TestCase {
	use IsolatesHooks;
	use RequiresWooCommerce;

	// The isolated set includes the two global default-injection filters so a registered tab
	// cannot leak its callbacks into other tests.
	protected const ISOLATED_HOOKS = array(
		'woocommerce_product_data_tabs',
		'woocommerce_product_data_panels',
		'woocommerce_process_product_meta',
		'default_post_metadata',
		'woocommerce_data_store_wp_post_read_meta',
		'woocommerce_before_product_object_save',
	);

	private int $product_id = 0;

	protected function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 1 );

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
		$_POST                = array();
		$GLOBALS['thepostid'] = null;
		$GLOBALS['post']      = null;

		parent::tearDown();
	}

	public function test_registers_the_tab_for_a_supported_product(): void {
		$this->set_current_product( $this->product_id );
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

		$tabs = \apply_filters( 'woocommerce_product_data_tabs', array() );

		self::assertArrayHasKey( 'dws_warranty', $tabs );
		self::assertSame( 'Warranty', $tabs['dws_warranty']['label'] );
		self::assertSame( 'dws_warranty_product_data', $tabs['dws_warranty']['target'] );
		self::assertContains( 'dws_warranty_tab', $tabs['dws_warranty']['class'] );
		self::assertSame( 65, $tabs['dws_warranty']['priority'] );
	}

	public function test_does_not_register_the_tab_for_an_unsupported_product(): void {
		$this->set_current_product( $this->product_id );
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab( supports: static fn ( int $product_id ): bool => false ) );

		$tabs = \apply_filters( 'woocommerce_product_data_tabs', array() );

		self::assertArrayNotHasKey( 'dws_warranty', $tabs );
	}

	public function test_dynamic_classes_closure_contributes_product_type_classes(): void {
		$this->set_current_product( $this->product_id );
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab( classes: static fn ( int $product_id ): array => array( 'show_if_simple' ) ) );

		$tabs = \apply_filters( 'woocommerce_product_data_tabs', array() );

		self::assertContains( 'show_if_simple', $tabs['dws_warranty']['class'] );
		self::assertContains( 'dws_warranty_tab', $tabs['dws_warranty']['class'] );
	}

	public function test_renders_the_panel_with_each_field_control(): void {
		$this->set_current_product( $this->product_id );
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

		\ob_start();
		\do_action( 'woocommerce_product_data_panels' );
		$html = (string) \ob_get_clean();

		self::assertStringContainsString( 'id="dws_warranty_product_data"', $html );
		self::assertStringContainsString( 'woocommerce_options_panel', $html );
		self::assertStringContainsString( 'name="_dws-wrwc_general_warranty-type"', $html );
	}

	public function test_does_not_render_the_panel_for_an_unsupported_product(): void {
		$this->set_current_product( $this->product_id );
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab( supports: static fn ( int $product_id ): bool => false ) );

		\ob_start();
		\do_action( 'woocommerce_product_data_panels' );
		$html = (string) \ob_get_clean();

		self::assertStringNotContainsString( 'dws_warranty_product_data', $html );
	}

	public function test_save_persists_submitted_values(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

		$_POST = array( '_dws-wrwc_general_warranty-type' => 'addon' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertSame( 'addon', $surface->get( 'general', $this->product_id, 'warranty-type' ) );
	}

	public function test_save_applies_the_field_sanitizer(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
			$this->tab_with(
				new SettingsField( id: 'code', type: 'text', label: 'Code', sanitize: static fn ( mixed $v ): string => \strtoupper( (string) $v ) ),
			),
		);

		$_POST = array( '_dws-wrwc_general_code' => 'abc' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertSame( 'ABC', $surface->get( 'general', $this->product_id, 'code' ) );
	}

	public function test_save_applies_the_builtin_default_sanitizer(): void {
		$surface = new ProductDataFieldSurface();
		$raw     = '<b>x</b>';
		$surface->register_tab(
			$this->tab_with(
				new SettingsField( id: 'code', type: 'text', label: 'Code' ),
			),
		);

		$_POST = array( '_dws-wrwc_general_code' => $raw );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertSame( \sanitize_text_field( $raw ), $surface->get( 'general', $this->product_id, 'code' ) );
	}

	public function test_save_preserves_an_existing_value_when_a_present_submission_is_invalid(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
			$this->tab_with(
				new SettingsField(
					id: 'warranty-type',
					type: 'select',
					label: 'Type',
					options: array(
						'global' => 'Global',
						'addon'  => 'Add-on',
					)
				),
			),
		);
		$surface->set( 'general', $this->product_id, 'warranty-type', 'global' );

		$_POST = array( '_dws-wrwc_general_warranty-type' => 'tampered' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertSame( 'global', $surface->get( 'general', $this->product_id, 'warranty-type' ) );
	}

	public function test_save_is_skipped_for_an_unsupported_product(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab( supports: static fn ( int $product_id ): bool => false ) );

		$_POST = array( '_dws-wrwc_general_warranty-type' => 'addon' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertFalse( \metadata_exists( 'post', $this->product_id, '_dws-wrwc_general_warranty-type' ) );
	}

	public function test_save_persists_a_multiselect_selection(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
			$this->tab_with(
				new SettingsField(
					id: 'locations',
					type: 'multiselect',
					label: 'Locations',
					options: array(
						'cart'     => 'Cart',
						'checkout' => 'Checkout',
						'email'    => 'Email',
					)
				),
			),
		);

		$_POST = array( '_dws-wrwc_general_locations' => array( 'cart', 'email' ) );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertEqualsCanonicalizing( array( 'cart', 'email' ), $surface->get( 'general', $this->product_id, 'locations' ) );
	}

	public function test_save_preserves_a_checkbox_when_validation_rejects_the_submission(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
			$this->tab_with(
				// A validator that rejects 'yes' makes the checked submission invalid.
				new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag', validate: static fn ( mixed $v ): bool => 'yes' !== $v ),
			),
		);
		// Prior value differs from the rejected submission so accept-and-store would land 'yes', not the
		// preserved 'no' — the assertion fails unless the rejection-preserve branch actually fires.
		$surface->set( 'general', $this->product_id, 'flag', false );

		$_POST = array( '_dws-wrwc_general_flag' => 'yes' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertSame( 'no', $surface->get( 'general', $this->product_id, 'flag' ) );
	}

	public function test_save_runs_sanitize_and_validate_on_a_custom_field(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
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
		self::assertSame( '', $surface->get( 'general', $this->product_id, 'span' ) );
	}

	public function test_a_custom_field_without_a_renderer_is_rejected_at_registration(): void {
		$surface = new ProductDataFieldSurface();

		$this->expectException( InvalidProductDataTabException::class );

		$surface->register_tab(
			$this->tab_with(
				new SettingsField( id: 'span', type: 'dws_custom', label: 'Span', sanitize: static fn ( mixed $v ): string => (string) $v ),
			),
		);
	}

	public function test_a_custom_field_without_a_sanitize_is_rejected_at_registration(): void {
		$surface = new ProductDataFieldSurface();

		$this->expectException( InvalidProductDataTabException::class );

		$surface->register_tab(
			$this->tab_with(
				new SettingsField( id: 'span', type: 'dws_custom', label: 'Span' ),
				array( 'dws_custom' => $this->noop_renderer() ),
			),
		);
	}

	public function test_an_absent_custom_field_stores_the_sanitized_empty_not_a_null_or_default(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
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

		self::assertSame( 'sanitized:', $surface->get( 'general', $this->product_id, 'span' ) );
	}

	public function test_a_non_scalar_custom_field_submission_is_coerced_before_sanitize(): void {
		$seen    = null;
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
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
		self::assertSame( 'sanitized:', $surface->get( 'general', $this->product_id, 'span' ) );
	}

	public function test_the_before_save_hook_strips_an_injected_default(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

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
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

		\clean_post_cache( $this->product_id );
		$product = \wc_get_product( $this->product_id );
		\assert( $product instanceof \WC_Product );
		$product->update_meta_data( '_dws-wrwc_general_warranty-type', 'addon' );

		// A value the consumer set differs from the injected default, so the strip leaves it to persist.
		\do_action( 'woocommerce_before_product_object_save', $product );

		self::assertSame( 'addon', $product->get_meta( '_dws-wrwc_general_warranty-type', true ) );
	}

	public function test_a_new_product_reads_the_default_through_get_post_meta(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

		self::assertSame( 'global', \get_post_meta( $this->product_id, '_dws-wrwc_general_warranty-type', true ) );
	}

	public function test_a_new_product_reads_one_list_default_row_through_non_single_get_post_meta(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
			$this->tab_with(
				new SettingsField(
					id: 'locations',
					type: 'multiselect',
					label: 'Locations',
					default_value: array( 'cart', 'email' ),
					options: array(
						'cart'     => 'Cart',
						'checkout' => 'Checkout',
						'email'    => 'Email',
					),
				),
			),
		);

		self::assertSame( array( array( 'cart', 'email' ) ), \get_post_meta( $this->product_id, '_dws-wrwc_general_locations', false ) );
	}

	public function test_a_new_product_reads_the_default_through_the_wc_product(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

		\clean_post_cache( $this->product_id );
		$fresh = \wc_get_product( $this->product_id );
		\assert( $fresh instanceof \WC_Product );

		self::assertSame( 'global', $fresh->get_meta( '_dws-wrwc_general_warranty-type', true ) );
	}

	public function test_a_pre_existing_product_renders_the_default_without_a_stored_row(): void {
		// The product was created and saved in setUp before the tab existed — the regression-prone case.
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

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
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab( supports: static fn ( int $product_id ): bool => false ) );

		self::assertSame( '', \get_post_meta( $this->product_id, '_dws-wrwc_general_warranty-type', true ) );
	}

	public function test_after_save_the_real_value_replaces_the_default(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

		$_POST = array( '_dws-wrwc_general_warranty-type' => 'addon' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertTrue( \metadata_exists( 'post', $this->product_id, '_dws-wrwc_general_warranty-type' ) );
		self::assertSame( 'addon', \get_post_meta( $this->product_id, '_dws-wrwc_general_warranty-type', true ) );
	}

	public function test_default_injection_does_not_leak_into_non_products(): void {
		$surface = new ProductDataFieldSurface();
		// A permissive gate must still be floored by product-existence: the global default filters must not
		// inject a product field's default into an unrelated post that happens to read the same meta key.
		$surface->register_tab( $this->tab( supports: static fn ( int $product_id ): bool => true ) );

		$post_id = \wp_insert_post(
			array(
				'post_title'  => 'Page',
				'post_status' => 'publish',
			)
		);
		\assert( \is_int( $post_id ) );
		self::assertFalse( \wc_get_product( $post_id ) );

		self::assertSame( '', \get_post_meta( $post_id, '_dws-wrwc_general_warranty-type', true ) );

		\wp_delete_post( $post_id, true );
	}

	public function test_bulk_default_injection_skips_the_consumer_gate_when_no_owned_key_is_missing(): void {
		$gate_calls = 0;
		$surface    = new ProductDataFieldSurface();
		$surface->register_tab(
			$this->tab(
				supports: static function ( int $product_id ) use ( &$gate_calls ): bool {
					++$gate_calls;
					return true;
				},
			),
		);
		$product = \wc_get_product( $this->product_id );
		\assert( $product instanceof \WC_Product );
		$meta_data = array(
			(object) array( 'meta_key' => '_dws-wrwc_general_warranty-type' ),
			(object) array( 'meta_key' => '_dws-wrwc_general_code' ),
		);

		$filtered = \apply_filters( 'woocommerce_data_store_wp_post_read_meta', $meta_data, $product );

		self::assertSame( $meta_data, $filtered );
		self::assertSame( 0, $gate_calls );
	}

	public function test_a_field_the_user_cannot_edit_is_not_rendered(): void {
		$this->set_current_product( $this->product_id );
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
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
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
			$this->tab_with(
				new SettingsField( id: 'secret', type: 'text', label: 'Secret', capability: 'dws_protected_cap' ),
			),
		);

		$_POST = array( '_dws-wrwc_general_secret' => 'tampered' );
		\do_action( 'woocommerce_process_product_meta', $this->product_id );

		self::assertFalse( \metadata_exists( 'post', $this->product_id, '_dws-wrwc_general_secret' ) );
	}

	public function test_save_does_not_freeze_an_injected_default_for_a_field_the_user_cannot_edit(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
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

	public function test_a_custom_field_type_renders_via_its_renderer_and_saves_via_sanitize(): void {
		$this->set_current_product( $this->product_id );
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
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
		self::assertSame( array( 'raw' => '12' ), $surface->get( 'general', $this->product_id, 'span' ) );
	}

	public function test_a_renderer_registered_custom_type_counts_as_wired_and_renders_through_the_bridge(): void {
		$this->set_current_product( $this->product_id );
		$surface = new ProductDataFieldSurface(
			new ProductDataFieldRenderer(
				custom_types: array(
					'dws_rds' => new CustomFieldType(
						'dws_rds',
						static fn ( SettingsField $field, mixed $value, string $name ): string => '<span class="dws-rds-bridge" data-key="' . \esc_attr( $name ) . '"></span>',
					),
				),
			),
		);

		// No tab-level renderer: the renderer-registered CustomFieldType satisfies the render requirement.
		$surface->register_tab(
			$this->tab_with(
				new SettingsField( id: 'span', type: 'dws_rds', label: 'Span', sanitize: static fn ( mixed $v ): string => (string) $v ),
			),
		);

		\ob_start();
		\do_action( 'woocommerce_product_data_panels' );
		$html = (string) \ob_get_clean();

		self::assertStringContainsString( 'dws-rds-bridge', $html );
		self::assertStringContainsString( 'data-key="_dws-wrwc_general_span"', $html );
	}

	public function test_a_tab_level_custom_renderer_wins_over_a_renderer_registered_custom_type(): void {
		$this->set_current_product( $this->product_id );
		$surface = new ProductDataFieldSurface(
			new ProductDataFieldRenderer(
				custom_types: array(
					'dws_rds' => new CustomFieldType( 'dws_rds', static fn (): string => '<span class="dws-rds-bridge"></span>' ),
				),
			),
		);

		$surface->register_tab(
			$this->tab_with(
				new SettingsField( id: 'span', type: 'dws_rds', label: 'Span', sanitize: static fn ( mixed $v ): string => (string) $v ),
				array(
					'dws_rds' => static function ( SettingsField $field, mixed $value, string $meta_key ): void {
						echo '<span class="dws-rds-tab"></span>';
					},
				),
			),
		);

		\ob_start();
		\do_action( 'woocommerce_product_data_panels' );
		$html = (string) \ob_get_clean();

		self::assertStringContainsString( 'dws-rds-tab', $html );
		self::assertStringNotContainsString( 'dws-rds-bridge', $html );
	}

	public function test_a_renderer_registered_custom_type_still_requires_a_sanitize_callback(): void {
		$surface = new ProductDataFieldSurface(
			new ProductDataFieldRenderer(
				custom_types: array(
					'dws_rds' => new CustomFieldType( 'dws_rds', static fn (): string => '' ),
				),
			),
		);

		$this->expectException( InvalidProductDataTabException::class );

		// The custom-type registry is render-only, so a covered type without a field sanitize still fails.
		$surface->register_tab(
			$this->tab_with(
				new SettingsField( id: 'span', type: 'dws_rds', label: 'Span' ),
			),
		);
	}

	public function test_crud_round_trips_by_section_and_field(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

		self::assertFalse( $surface->has( 'general', $this->product_id, 'code' ) );
		self::assertFalse( $surface->delete( 'general', $this->product_id, 'code' ) );

		$surface->set( 'general', $this->product_id, 'code', 'X1' );
		self::assertTrue( $surface->has( 'general', $this->product_id, 'code' ) );
		self::assertSame( 'X1', $surface->get( 'general', $this->product_id, 'code' ) );

		self::assertTrue( $surface->delete( 'general', $this->product_id, 'code' ) );
		self::assertFalse( $surface->has( 'general', $this->product_id, 'code' ) );
	}

	public function test_set_normalizes_a_checkbox_value_to_yes_no(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab_with( new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag' ) ) );

		// A boolean written through CRUD must persist as WooCommerce's 'yes', matching the form-save path.
		$surface->set( 'general', $this->product_id, 'flag', true );

		self::assertSame( 'yes', $surface->get( 'general', $this->product_id, 'flag' ) );
	}

	public function test_set_persists_a_value_equal_to_the_default(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

		// Setting a field to a value that equals its default must persist a real row — matching the form save's
		// store-all — rather than be mistaken for an injected default and stripped by the pre-save hook.
		$surface->set( 'general', $this->product_id, 'warranty-type', 'global' );

		self::assertTrue( $surface->has( 'general', $this->product_id, 'warranty-type' ) );
		self::assertSame( 'global', $surface->get( 'general', $this->product_id, 'warranty-type' ) );
	}

	public function test_get_returns_the_descriptor_default_for_an_unstored_supported_field(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

		// get() reads the descriptor default while nothing is stored, agreeing with the injected read paths and
		// with has() reporting no real value yet.
		self::assertFalse( $surface->has( 'general', $this->product_id, 'warranty-type' ) );
		self::assertSame( 'global', $surface->get( 'general', $this->product_id, 'warranty-type' ) );
	}

	public function test_get_returns_the_caller_fallback_for_an_unsupported_product(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab( supports: static fn ( int $product_id ): bool => false ) );

		self::assertSame( 'na', $surface->get( 'general', $this->product_id, 'warranty-type', 'na' ) );
	}

	public function test_meta_key_derivation_and_override(): void {
		$surface = new ProductDataFieldSurface();
		$surface->register_tab(
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

		self::assertEqualsCanonicalizing(
			array( '_dws-wrwc_general_derived', '_legacy_v1_key' ),
			$surface->meta_keys(),
		);

		// The CRUD addressing resolves to those exact keys: a write by section/field id lands on the derived
		// key for a plain field and on the byte-exact override for a legacy one.
		$surface->set( 'general', $this->product_id, 'derived', 'd-value' );
		$surface->set( 'general', $this->product_id, 'explicit', 'e-value' );

		self::assertSame( 'd-value', \get_post_meta( $this->product_id, '_dws-wrwc_general_derived', true ) );
		self::assertSame( 'e-value', \get_post_meta( $this->product_id, '_legacy_v1_key', true ) );
	}

	public function test_a_duplicate_meta_key_is_rejected(): void {
		$surface = new ProductDataFieldSurface();

		$this->expectException( DuplicateSettingsFieldException::class );

		$surface->register_tab(
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
		$surface = new ProductDataFieldSurface();
		$surface->register_tab( $this->tab() );

		$this->expectException( InvalidSettingsFieldException::class );

		$surface->get( 'general', $this->product_id, 'nope' );
	}

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
						new SettingsField(
							id: 'warranty-type',
							type: 'select',
							label: 'Type',
							default_value: 'global',
							options: array(
								'global' => 'Global',
								'addon'  => 'Add-on',
							)
						),
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
}
