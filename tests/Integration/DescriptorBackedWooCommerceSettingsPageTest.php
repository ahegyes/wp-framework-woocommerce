<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration;

use DeepWebSolutions\Framework\Settings\Schema\Exceptions\DuplicateSettingsSectionException;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\InvalidSettingsSectionException;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsPage;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsSection;
use DeepWebSolutions\Framework\WooCommerce\Backend\DescriptorBackedWooCommerceSettingsPage;
use DeepWebSolutions\Framework\WooCommerce\Backend\Exceptions\UnboundSettingsPageException;
use DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Fixtures\BarWooCommerceSettingsPage;
use DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Fixtures\FooWooCommerceSettingsPage;
use DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Fixtures\UnboundWooCommerceSettingsPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( DescriptorBackedWooCommerceSettingsPage::class )]
final class DescriptorBackedWooCommerceSettingsPageTest extends TestCase {
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
		$descriptors = new \ReflectionProperty( DescriptorBackedWooCommerceSettingsPage::class, 'descriptors' );
		$descriptors->setValue( null, array() );

		parent::tearDown();
	}

	public function test_recovers_its_descriptor_id_and_label_from_the_static_map(): void {
		DescriptorBackedWooCommerceSettingsPage::bind( FooWooCommerceSettingsPage::class, $this->page( 'dws-foo', 'dws_foo', 'Foo' ) );

		$page = new FooWooCommerceSettingsPage();

		self::assertSame( 'dws_foo', $page->get_id() );
		self::assertSame( 'Foo', $page->get_label() );
	}

	public function test_falls_back_to_the_slug_when_the_descriptor_has_no_location(): void {
		DescriptorBackedWooCommerceSettingsPage::bind(
			FooWooCommerceSettingsPage::class,
			new SettingsPage( slug: 'dws-foo', page_title: 'Foo', menu_title: 'Foo', capability: 'manage_woocommerce', sections: array() ),
		);

		self::assertSame( 'dws-foo', ( new FooWooCommerceSettingsPage() )->get_id() );
	}

	public function test_the_tab_id_is_sanitized_for_woocommerce_routing(): void {
		DescriptorBackedWooCommerceSettingsPage::bind(
			FooWooCommerceSettingsPage::class,
			new SettingsPage( slug: 'dws-foo', page_title: 'Foo', menu_title: 'Foo', capability: 'manage_woocommerce', sections: array(), location: 'DWS Foo' ),
		);

		// WooCommerce routes the settings screen by sanitize_title($_GET['tab']) but the page registers its
		// output/save hooks against $this->id, so a non-slug-stable id neither renders nor saves.
		self::assertSame( 'dws-foo', ( new FooWooCommerceSettingsPage() )->get_id() );
	}

	public function test_builds_its_section_settings_from_the_descriptor(): void {
		DescriptorBackedWooCommerceSettingsPage::bind( FooWooCommerceSettingsPage::class, $this->page( 'dws-foo', 'dws_foo', 'Foo' ) );

		$settings = ( new FooWooCommerceSettingsPage() )->get_settings_for_section( '' );
		$ids      = \array_column( $settings, 'id' );

		self::assertContains( 'dws-foo_general', $ids );
		self::assertContains( 'dws-foo_enabled', $ids );
	}

	public function test_maps_the_first_section_to_the_default_and_later_sections_to_their_slug(): void {
		DescriptorBackedWooCommerceSettingsPage::bind( FooWooCommerceSettingsPage::class, $this->multi_section_page() );

		// get_sections() (public) returns the filtered get_own_sections() map.
		$sections = ( new FooWooCommerceSettingsPage() )->get_sections();

		self::assertSame( 'General', $sections[''] ?? null );
		self::assertSame( 'Advanced', $sections['advanced'] ?? null );
		self::assertArrayNotHasKey( 'general', $sections );
	}

	public function test_routes_a_later_section_to_its_own_fields(): void {
		DescriptorBackedWooCommerceSettingsPage::bind( FooWooCommerceSettingsPage::class, $this->multi_section_page() );

		$settings = ( new FooWooCommerceSettingsPage() )->get_settings_for_section( 'advanced' );
		$ids      = \array_column( $settings, 'id' );

		self::assertContains( 'dws-foo_advanced', $ids );
		self::assertContains( 'dws-foo_mode', $ids );
		self::assertNotContains( 'dws-foo_enabled', $ids );
	}

	public function test_routes_a_later_section_by_woocommerces_sanitized_section_id(): void {
		DescriptorBackedWooCommerceSettingsPage::bind( FooWooCommerceSettingsPage::class, $this->unstable_section_page() );

		$page     = new FooWooCommerceSettingsPage();
		$sections = $page->get_sections();
		$settings = $page->get_settings_for_section( 'advanced' );
		$ids      = \array_column( $settings, 'id' );

		self::assertSame( 'Advanced', $sections['advanced'] ?? null );
		self::assertArrayNotHasKey( 'advanced-', $sections );
		self::assertContains( 'dws-foo_mode', $ids );
	}

	public function test_two_distinct_subclasses_recover_their_own_descriptors(): void {
		DescriptorBackedWooCommerceSettingsPage::bind( FooWooCommerceSettingsPage::class, $this->page( 'dws-foo', 'dws_foo', 'Foo' ) );
		DescriptorBackedWooCommerceSettingsPage::bind( BarWooCommerceSettingsPage::class, $this->page( 'dws-bar', 'dws_bar', 'Bar' ) );

		self::assertSame( 'dws_foo', ( new FooWooCommerceSettingsPage() )->get_id() );
		self::assertSame( 'dws_bar', ( new BarWooCommerceSettingsPage() )->get_id() );
	}

	public function test_a_rebuilt_instance_recovers_the_same_descriptor(): void {
		DescriptorBackedWooCommerceSettingsPage::bind( FooWooCommerceSettingsPage::class, $this->page( 'dws-foo', 'dws_foo', 'Foo' ) );

		// WooCommerce rebuilds the page object on each request; a fresh instance must still resolve its descriptor.
		$first  = new FooWooCommerceSettingsPage();
		$second = new FooWooCommerceSettingsPage();

		self::assertSame( $first->get_id(), $second->get_id() );
		self::assertSame( 'Foo', $second->get_label() );
	}

	public function test_instantiating_an_unbound_page_class_throws(): void {
		$this->expectException( UnboundSettingsPageException::class );

		new UnboundWooCommerceSettingsPage();
	}

	public function test_the_first_section_matches_only_the_default_token(): void {
		DescriptorBackedWooCommerceSettingsPage::bind( FooWooCommerceSettingsPage::class, $this->multi_section_page() );

		$page = new FooWooCommerceSettingsPage();

		// The first section's fields answer to WooCommerce's default token, not to the
		// section's own sanitized id — otherwise it could shadow a later section.
		self::assertNotSame( array(), $page->get_settings_for_section( '' ) );
		self::assertSame( array(), $page->get_settings_for_section( 'general' ) );
	}

	public function test_binding_two_sections_colliding_on_the_sanitized_id_throws(): void {
		$page = new SettingsPage(
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
				new SettingsSection(
					'advanced-',
					'Advanced Too',
					array( new SettingsField( id: 'variant', type: 'text', label: 'Variant' ) ),
				),
			),
		);

		$this->expectException( DuplicateSettingsSectionException::class );
		$this->expectExceptionMessage( 'advanced' );

		DescriptorBackedWooCommerceSettingsPage::bind( FooWooCommerceSettingsPage::class, $page );
	}

	public function test_binding_a_section_whose_id_sanitizes_to_the_default_token_throws(): void {
		// The section-id charset cannot produce an empty sanitize_title() result on its own, but
		// sanitize_title() is filterable at runtime; the guard fails closed against that seam.
		$force_empty = static fn ( string $title ): string => '';
		\add_filter( 'sanitize_title', $force_empty );

		try {
			$this->expectException( InvalidSettingsSectionException::class );

			DescriptorBackedWooCommerceSettingsPage::bind( FooWooCommerceSettingsPage::class, $this->multi_section_page() );
		} finally {
			\remove_filter( 'sanitize_title', $force_empty );
		}
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

	private function unstable_section_page(): SettingsPage {
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
					'advanced-',
					'Advanced',
					array( new SettingsField( id: 'mode', type: 'text', label: 'Mode' ) ),
				),
			),
		);
	}
}
