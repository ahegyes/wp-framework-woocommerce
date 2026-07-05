<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Unit;

use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsSection;
use DeepWebSolutions\Framework\WooCommerce\ProductData\Exceptions\InvalidProductDataTabException;
use DeepWebSolutions\Framework\WooCommerce\ProductData\ProductDataTab;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesFunction;
use PHPUnit\Framework\TestCase;

#[CoversClass( ProductDataTab::class )]
#[UsesClass( SettingsField::class )]
#[UsesClass( SettingsSection::class )]
#[UsesFunction( 'DeepWebSolutions\Framework\Shared\Identifier\is_valid_identifier' )]
#[UsesFunction( 'DeepWebSolutions\Framework\Shared\Identifier\is_valid_global_name_prefix' )]
final class ProductDataTabTest extends TestCase {
	public function test_minimal_construction_exposes_defaults(): void {
		$tab = new ProductDataTab( slug: 'dws_warranty', label: 'Warranty', meta_key_prefix: '_dws-wrwc_', sections: array() );

		self::assertSame( 'dws_warranty', $tab->slug );
		self::assertSame( 'Warranty', $tab->label );
		self::assertSame( '_dws-wrwc_', $tab->meta_key_prefix );
		self::assertSame( array(), $tab->sections );
		self::assertSame( array(), $tab->classes );
		self::assertSame( 65, $tab->priority );
		self::assertNull( $tab->supports_product );
		self::assertSame( array(), $tab->custom_renderers );
	}

	public function test_full_construction_round_trips_values(): void {
		$section  = new SettingsSection( 'general', 'General', array( new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag' ) ) );
		$supports = static fn ( int $product_id ): bool => true;
		$renderer = static function ( SettingsField $field, mixed $value, string $meta_key ): void {};
		$tab      = new ProductDataTab(
			slug: 'dws_quote_requests',
			label: 'Quote Requests',
			meta_key_prefix: '_dws-qrwc_',
			sections: array( $section ),
			classes: array( 'show_if_simple' ),
			priority: 70,
			supports_product: $supports,
			custom_renderers: array( 'dws_relative_date_selector' => $renderer ),
		);

		self::assertSame( array( $section ), $tab->sections );
		self::assertSame( array( 'show_if_simple' ), $tab->classes );
		self::assertSame( 70, $tab->priority );
		self::assertInstanceOf( \Closure::class, $tab->supports_product );
		self::assertArrayHasKey( 'dws_relative_date_selector', $tab->custom_renderers );
		self::assertInstanceOf( \Closure::class, $tab->custom_renderers['dws_relative_date_selector'] );
	}

	public function test_classes_accepts_a_closure_for_dynamic_product_type_classes(): void {
		$closure = static fn ( int $product_id ): array => array( 'show_if_simple' );
		$tab     = new ProductDataTab( slug: 'dws_warranty', label: 'W', meta_key_prefix: '_p_', sections: array(), classes: $closure );

		self::assertSame( $closure, $tab->classes );
	}

	public function test_a_supports_product_array_callable_is_normalized_to_a_closure(): void {
		$gate = new class() {
			public function supports( int $product_id ): bool {
				return $product_id > 0;
			}
		};
		$tab  = new ProductDataTab( slug: 'dws_warranty', label: 'W', meta_key_prefix: '_p_', sections: array(), supports_product: array( $gate, 'supports' ) );

		self::assertInstanceOf( \Closure::class, $tab->supports_product );
		self::assertTrue( ( $tab->supports_product )( 5 ) );
	}

	public function test_a_custom_renderer_callable_is_normalized_to_a_closure(): void {
		// An array callable (not already a Closure) so the normalization is actually exercised.
		$renderer = new class() {
			public string $received = '';
			public function render( SettingsField $field, mixed $value, string $meta_key ): void {
				$this->received = $meta_key;
			}
		};
		$tab      = new ProductDataTab( slug: 'dws_warranty', label: 'W', meta_key_prefix: '_p_', sections: array(), custom_renderers: array( 'composite' => array( $renderer, 'render' ) ) );

		self::assertInstanceOf( \Closure::class, $tab->custom_renderers['composite'] );
		( $tab->custom_renderers['composite'] )( new SettingsField( id: 'x', type: 'composite', label: 'X' ), null, '_p_x' );
		self::assertSame( '_p_x', $renderer->received );
	}

	#[DataProvider( 'valid_slugs' )]
	public function test_accepts_valid_slugs( string $slug ): void {
		$tab = new ProductDataTab( slug: $slug, label: 'L', meta_key_prefix: '_p_', sections: array() );

		self::assertSame( $slug, $tab->slug );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function valid_slugs(): array {
		return array(
			'single letter' => array( 'a' ),
			'word'          => array( 'warranty' ),
			'underscored'   => array( 'dws_quote_requests' ),
			'hyphenated'    => array( 'dws-warranty' ),
			'with digit'    => array( 'tab2' ),
		);
	}

	#[DataProvider( 'invalid_slugs' )]
	public function test_rejects_an_invalid_slug( string $slug ): void {
		$this->expectException( InvalidProductDataTabException::class );

		new ProductDataTab( slug: $slug, label: 'L', meta_key_prefix: '_p_', sections: array() );
	}

	#[DataProvider( 'invalid_meta_key_prefixes' )]
	public function test_rejects_an_invalid_meta_key_prefix( string $prefix ): void {
		$this->expectException( InvalidProductDataTabException::class );

		new ProductDataTab( slug: 'dws_warranty', label: 'L', meta_key_prefix: $prefix, sections: array() );
	}

	public function test_an_invalid_slug_throws_an_invalid_argument(): void {
		$this->expectException( \InvalidArgumentException::class );

		new ProductDataTab( slug: '1tab', label: 'L', meta_key_prefix: '_p_', sections: array() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalid_slugs(): array {
		return array(
			'empty'         => array( '' ),
			'leading digit' => array( '1tab' ),
			'leading dash'  => array( '-tab' ),
			'uppercase'     => array( 'Warranty' ),
			'space'         => array( 'my tab' ),
			'slash'         => array( 'a/b' ),
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalid_meta_key_prefixes(): array {
		return array(
			'empty'         => array( '' ),
			'leading digit' => array( '1dws_' ),
			'uppercase'     => array( '_DWS_' ),
			'space'         => array( '_dws warranty_' ),
			'slash'         => array( '_dws/warranty_' ),
		);
	}
}
