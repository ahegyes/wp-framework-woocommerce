<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Unit;

use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsPage;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsSection;
use DeepWebSolutions\Framework\WooCommerce\Backend\DescriptorBackedWooCommerceSettingsPage;
use DeepWebSolutions\Framework\WooCommerce\Backend\WooCommerceSettingsBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesFunction;
use PHPUnit\Framework\TestCase;

#[CoversClass( WooCommerceSettingsBackend::class )]
#[UsesClass( SettingsField::class )]
#[UsesClass( SettingsSection::class )]
#[UsesClass( SettingsPage::class )]
#[UsesFunction( 'DeepWebSolutions\Framework\Shared\Identifier\is_valid_identifier' )]
final class WooCommerceSettingsBackendTest extends TestCase {
	public function test_option_keys_derives_one_prefixed_key_per_field_across_sections_in_declaration_order(): void {
		$page = new SettingsPage(
			slug: 'dws-foo',
			page_title: 'Foo',
			menu_title: 'Foo',
			capability: 'manage_woocommerce',
			sections: array(
				new SettingsSection(
					'general',
					'General',
					array(
						new SettingsField( id: 'store_name', type: 'text', label: 'Store Name' ),
						new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag' ),
					),
				),
				new SettingsSection( 'advanced', 'Advanced', array( new SettingsField( id: 'code', type: 'text', label: 'Code' ) ) ),
			),
		);

		self::assertSame(
			array( 'dws-foo_store_name', 'dws-foo_flag', 'dws-foo_code' ),
			$this->backend()->option_keys( $page ),
		);
	}

	public function test_option_keys_is_empty_for_a_page_without_fields(): void {
		$page = new SettingsPage(
			slug: 'dws-foo',
			page_title: 'Foo',
			menu_title: 'Foo',
			capability: 'manage_woocommerce',
			sections: array( new SettingsSection( 'general', 'General', array() ) ),
		);

		self::assertSame( array(), $this->backend()->option_keys( $page ) );
	}

	private function backend(): WooCommerceSettingsBackend {
		// The page subclass is never loaded or instantiated by option_keys(), so the abstract base's own
		// class-string satisfies the constructor without WooCommerce present.
		return new WooCommerceSettingsBackend( DescriptorBackedWooCommerceSettingsPage::class );
	}
}
