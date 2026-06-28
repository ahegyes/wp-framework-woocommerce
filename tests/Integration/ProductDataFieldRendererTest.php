<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration;

use DeepWebSolutions\Framework\Settings\Schema\Exceptions\UnknownFieldTypeException;
use DeepWebSolutions\Framework\Settings\Schema\Options\OptionsResolver;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\WooCommerce\ProductData\ProductDataFieldRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( ProductDataFieldRenderer::class )]
#[UsesClass( SettingsField::class )]
#[UsesClass( OptionsResolver::class )]
final class ProductDataFieldRendererTest extends TestCase {
	private int $product_id = 0;

	protected function setUp(): void {
		parent::setUp();

		if ( ! \function_exists( 'wc_get_product' ) ) {
			self::markTestSkipped( 'WooCommerce is not active.' );
		}
		if ( ! \function_exists( 'woocommerce_wp_text_input' ) ) {
			require_once WP_PLUGIN_DIR . '/woocommerce/includes/admin/wc-meta-box-functions.php';
		}

		$product = new \WC_Product_Simple();
		$product->set_name( 'Probe' );
		$this->product_id = $product->save();
		// woocommerce_wp_* read the global $post when a value is not supplied; the renderer always supplies one,
		// but WooCommerce evaluates that fallback eagerly, so the global must point at a real product.
		$GLOBALS['post'] = \get_post( $this->product_id );
	}

	protected function tearDown(): void {
		$product = \wc_get_product( $this->product_id );
		if ( $product instanceof \WC_Product ) {
			$product->delete( true );
		}
		$GLOBALS['post'] = null;

		parent::tearDown();
	}

	public function test_renders_a_text_control_bound_to_the_meta_key(): void {
		$html = $this->render( new SettingsField( id: 'store', type: 'text', label: 'Store' ), 'Acme', '_p_general_store' );

		self::assertStringContainsString( 'name="_p_general_store"', $html );
		self::assertStringContainsString( 'value="Acme"', $html );
		self::assertStringContainsString( 'form-field', $html );
	}

	public function test_renders_a_checked_checkbox_for_a_truthy_value(): void {
		$html = $this->render( new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag' ), true, '_p_flag' );

		self::assertStringContainsString( 'type="checkbox"', $html );
		self::assertStringContainsString( 'checked=', $html );
	}

	public function test_renders_an_unchecked_checkbox_for_a_falsy_value(): void {
		$html = $this->render( new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag' ), false, '_p_flag' );

		self::assertStringContainsString( 'type="checkbox"', $html );
		self::assertStringNotContainsString( 'checked=', $html );
	}

	public function test_renders_a_select_with_the_current_option_selected(): void {
		$field = new SettingsField( id: 'gw', type: 'select', label: 'Gateway', options: array( 'stripe' => 'Stripe', 'paypal' => 'PayPal' ) );

		$html = $this->render( $field, 'paypal', '_p_gw' );

		self::assertStringContainsString( '<select', $html );
		self::assertMatchesRegularExpression( '/<option value="paypal"[^>]*selected/', $html );
		self::assertDoesNotMatchRegularExpression( '/<option value="stripe"[^>]*selected/', $html );
	}

	public function test_renders_a_multiselect_with_brackets_and_multiple(): void {
		$field = new SettingsField( id: 'tags', type: 'multiselect', label: 'Tags', options: array( 'a' => 'A', 'b' => 'B', 'c' => 'C' ) );

		$html = $this->render( $field, array( 'a', 'c' ), '_p_tags' );

		self::assertStringContainsString( 'name="_p_tags[]"', $html );
		self::assertStringContainsString( 'multiple', $html );
		self::assertMatchesRegularExpression( '/<option value="a"[^>]*selected/', $html );
		self::assertMatchesRegularExpression( '/<option value="c"[^>]*selected/', $html );
		self::assertDoesNotMatchRegularExpression( '/<option value="b"[^>]*selected/', $html );
	}

	public function test_renders_radio_inputs_with_the_current_value_checked(): void {
		$field = new SettingsField( id: 'size', type: 'radio', label: 'Size', options: array( 's' => 'Small', 'l' => 'Large' ) );

		$html = $this->render( $field, 'l', '_p_size' );

		self::assertStringContainsString( 'type="radio"', $html );
		self::assertMatchesRegularExpression( '/value="l"[^>]*checked/', $html );
	}

	public function test_renders_a_textarea_with_its_content(): void {
		$html = $this->render( new SettingsField( id: 'note', type: 'textarea', label: 'Note' ), 'hello world', '_p_note' );

		self::assertStringContainsString( '<textarea', $html );
		self::assertStringContainsString( 'hello world', $html );
	}

	public function test_renders_a_description_as_a_help_tip(): void {
		$html = $this->render( new SettingsField( id: 'x', type: 'text', label: 'X', description: 'Helpful hint.' ), '', '_p_x' );

		self::assertStringContainsString( 'Helpful hint.', $html );
	}

	public function test_an_unknown_type_throws(): void {
		$this->expectException( UnknownFieldTypeException::class );

		// Called directly, not through the buffering helper: render() rejects the type before emitting output.
		( new ProductDataFieldRenderer() )->render( new SettingsField( id: 'x', type: 'dws_custom', label: 'X' ), '', '_p_x' );
	}

	private function render( SettingsField $field, mixed $value, string $meta_key ): string {
		\ob_start();
		( new ProductDataFieldRenderer() )->render( $field, $value, $meta_key );

		return (string) \ob_get_clean();
	}
}
