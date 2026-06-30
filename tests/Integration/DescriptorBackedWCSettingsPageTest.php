<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration;

use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsPage;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsSection;
use DeepWebSolutions\Framework\WooCommerce\Backend\DescriptorBackedWCSettingsPage;
use DeepWebSolutions\Framework\WooCommerce\Backend\Exceptions\UnboundSettingsPageException;
use DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Fixtures\BarWCSettingsPage;
use DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Fixtures\FooWCSettingsPage;
use DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Fixtures\UnboundWCSettingsPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( DescriptorBackedWCSettingsPage::class )]
final class DescriptorBackedWCSettingsPageTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		// WooCommerce loads the settings-page base only on the settings screen; ensure it for instantiation.
		if ( ! \class_exists( 'WC_Settings_Page' ) ) {
			require_once WP_PLUGIN_DIR . '/woocommerce/includes/admin/settings/class-wc-settings-page.php';
		}
	}

	protected function tearDown(): void {
		// The static descriptor map persists across the process; clear it so a class bound in one test
		// cannot leak into another (e.g. silently satisfying the unbound-instantiation guard test).
		$descriptors = new \ReflectionProperty( DescriptorBackedWCSettingsPage::class, 'descriptors' );
		$descriptors->setValue( null, array() );

		parent::tearDown();
	}

	public function test_recovers_its_descriptor_id_and_label_from_the_static_map(): void {
		DescriptorBackedWCSettingsPage::bind( FooWCSettingsPage::class, $this->page( 'dws-foo', 'dws_foo', 'Foo' ) );

		$page = new FooWCSettingsPage();

		self::assertSame( 'dws_foo', $page->get_id() );
		self::assertSame( 'Foo', $page->get_label() );
	}

	public function test_falls_back_to_the_slug_when_the_descriptor_has_no_location(): void {
		DescriptorBackedWCSettingsPage::bind(
			FooWCSettingsPage::class,
			new SettingsPage( slug: 'dws-foo', page_title: 'Foo', menu_title: 'Foo', capability: 'manage_woocommerce' ),
		);

		self::assertSame( 'dws-foo', ( new FooWCSettingsPage() )->get_id() );
	}

	public function test_the_tab_id_is_sanitized_for_woocommerce_routing(): void {
		DescriptorBackedWCSettingsPage::bind(
			FooWCSettingsPage::class,
			new SettingsPage( slug: 'dws-foo', page_title: 'Foo', menu_title: 'Foo', capability: 'manage_woocommerce', location: 'DWS Foo' ),
		);

		// WooCommerce routes the settings screen by sanitize_title($_GET['tab']) but the page registers its
		// output/save hooks against $this->id, so a non-slug-stable id neither renders nor saves.
		self::assertSame( 'dws-foo', ( new FooWCSettingsPage() )->get_id() );
	}

	public function test_builds_its_section_settings_from_the_descriptor(): void {
		DescriptorBackedWCSettingsPage::bind( FooWCSettingsPage::class, $this->page( 'dws-foo', 'dws_foo', 'Foo' ) );

		$settings = ( new FooWCSettingsPage() )->get_settings_for_section( '' );
		$ids      = \array_column( $settings, 'id' );

		self::assertContains( 'dws-foo_general', $ids );
		self::assertContains( 'dws-foo_enabled', $ids );
	}

	public function test_maps_the_first_section_to_the_default_and_later_sections_to_their_slug(): void {
		DescriptorBackedWCSettingsPage::bind( FooWCSettingsPage::class, $this->multi_section_page() );

		// get_sections() (public) returns the filtered get_own_sections() map.
		$sections = ( new FooWCSettingsPage() )->get_sections();

		self::assertSame( 'General', $sections[''] ?? null );
		self::assertSame( 'Advanced', $sections['advanced'] ?? null );
		self::assertArrayNotHasKey( 'general', $sections );
	}

	public function test_routes_a_later_section_to_its_own_fields(): void {
		DescriptorBackedWCSettingsPage::bind( FooWCSettingsPage::class, $this->multi_section_page() );

		$settings = ( new FooWCSettingsPage() )->get_settings_for_section( 'advanced' );
		$ids      = \array_column( $settings, 'id' );

		self::assertContains( 'dws-foo_advanced', $ids );
		self::assertContains( 'dws-foo_mode', $ids );
		self::assertNotContains( 'dws-foo_enabled', $ids );
	}

	public function test_two_distinct_subclasses_recover_their_own_descriptors(): void {
		DescriptorBackedWCSettingsPage::bind( FooWCSettingsPage::class, $this->page( 'dws-foo', 'dws_foo', 'Foo' ) );
		DescriptorBackedWCSettingsPage::bind( BarWCSettingsPage::class, $this->page( 'dws-bar', 'dws_bar', 'Bar' ) );

		self::assertSame( 'dws_foo', ( new FooWCSettingsPage() )->get_id() );
		self::assertSame( 'dws_bar', ( new BarWCSettingsPage() )->get_id() );
	}

	public function test_a_rebuilt_instance_recovers_the_same_descriptor(): void {
		DescriptorBackedWCSettingsPage::bind( FooWCSettingsPage::class, $this->page( 'dws-foo', 'dws_foo', 'Foo' ) );

		// WooCommerce rebuilds the page object on each request; a fresh instance must still resolve its descriptor.
		$first  = new FooWCSettingsPage();
		$second = new FooWCSettingsPage();

		self::assertSame( $first->get_id(), $second->get_id() );
		self::assertSame( 'Foo', $second->get_label() );
	}

	public function test_instantiating_an_unbound_page_class_throws(): void {
		$this->expectException( UnboundSettingsPageException::class );

		new UnboundWCSettingsPage();
	}

	private function page( string $slug, string $location, string $menu_title ): SettingsPage {
		return new SettingsPage(
			slug: $slug,
			page_title: $menu_title . ' Settings',
			menu_title: $menu_title,
			capability: 'manage_woocommerce',
			location: $location,
			sections: array(
				new SettingsSection(
					'general',
					'General',
					array( new SettingsField( id: 'enabled', type: 'checkbox', label: 'Enabled' ) ),
				),
			),
		);
	}

	private function multi_section_page(): SettingsPage {
		return new SettingsPage(
			slug: 'dws-foo',
			page_title: 'Foo Settings',
			menu_title: 'Foo',
			capability: 'manage_woocommerce',
			location: 'dws_foo',
			sections: array(
				new SettingsSection(
					'general',
					'General',
					array( new SettingsField( id: 'enabled', type: 'checkbox', label: 'Enabled' ) ),
				),
				new SettingsSection(
					'advanced',
					'Advanced',
					array( new SettingsField( id: 'mode', type: 'text', label: 'Mode' ) ),
				),
			),
		);
	}
}
