<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration;

use DeepWebSolutions\Framework\Settings\Exceptions\DuplicateSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Exceptions\InvalidSettingsFieldException;
use DeepWebSolutions\Framework\Settings\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\ValueObjects\SettingsPage;
use DeepWebSolutions\Framework\Settings\ValueObjects\SettingsSection;
use DeepWebSolutions\Framework\WooCommerce\Exceptions\UnboundSettingsPageException;
use DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Fixtures\BarWCSettingsPage;
use DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Fixtures\FooWCSettingsPage;
use DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Fixtures\LazyBindWCSettingsPage;
use DeepWebSolutions\Framework\WooCommerce\SettingsPage\WooCommerceSettingsBackend;
use PHPUnit\Framework\TestCase;

final class WooCommerceSettingsBackendTest extends TestCase {
	private const OPTION_KEYS = array( 'dws-foo_store_name', 'dws-foo_flag', 'dws-foo_extra', 'dws-foo_code', 'dws-foo_a', 'dws-foo_open', 'dws-foo_secret', 'dws-bar_b' );

	protected function setUp(): void {
		parent::setUp();

		if ( ! \class_exists( 'WC_Settings_Page' ) ) {
			require_once WP_PLUGIN_DIR . '/woocommerce/includes/admin/settings/class-wc-settings-page.php';
		}

		\wp_set_current_user( 1 );
		\remove_all_filters( 'woocommerce_get_settings_pages' );

		// register_page() stacks a per-option sanitize/capability filter per field; clear any left by a
		// prior test so a reused option key cannot accumulate closures across tests.
		foreach ( self::OPTION_KEYS as $key ) {
			\remove_all_filters( 'woocommerce_admin_settings_sanitize_option_' . $key );
		}

		$this->clean();
	}

	protected function tearDown(): void {
		$this->clean();
		parent::tearDown();
	}

	public function test_register_page_adds_the_page_as_a_woocommerce_settings_tab(): void {
		$backend = new WooCommerceSettingsBackend( FooWCSettingsPage::class );
		$backend->register_page( $this->single_field_page( 'store_name', 'text', 'Store Name' ) );

		$ids = $this->tab_ids( \apply_filters( 'woocommerce_get_settings_pages', array() ) );

		self::assertContains( 'dws_foo', $ids );
	}

	public function test_two_backends_register_coexisting_pages_without_collision(): void {
		( new WooCommerceSettingsBackend( FooWCSettingsPage::class ) )->register_page(
			$this->page( 'dws-foo', 'dws_foo', 'Foo', array( new SettingsField( id: 'a', type: 'text', label: 'A' ) ) ),
		);
		( new WooCommerceSettingsBackend( BarWCSettingsPage::class ) )->register_page(
			$this->page( 'dws-bar', 'dws_bar', 'Bar', array( new SettingsField( id: 'b', type: 'text', label: 'B' ) ) ),
		);

		$ids = $this->tab_ids( \apply_filters( 'woocommerce_get_settings_pages', array() ) );

		self::assertContains( 'dws_foo', $ids );
		self::assertContains( 'dws_bar', $ids );
	}

	public function test_a_field_value_round_trips_through_a_prefixed_option(): void {
		$backend = new WooCommerceSettingsBackend( FooWCSettingsPage::class );
		$backend->register_page( $this->single_field_page( 'store_name', 'text', 'Store Name' ) );

		$backend->set( 'store_name', 'Acme' );

		self::assertSame( 'Acme', $backend->get( 'store_name' ) );
		self::assertSame( 'Acme', \get_option( 'dws-foo_store_name' ) );
		self::assertTrue( $backend->has( 'store_name' ) );
		self::assertTrue( $backend->delete( 'store_name' ) );
		self::assertFalse( $backend->has( 'store_name' ) );
	}

	public function test_a_stored_value_is_distinct_from_an_absent_one(): void {
		$backend = new WooCommerceSettingsBackend( FooWCSettingsPage::class );
		$backend->register_page(
			$this->page(
				'dws-foo',
				'dws_foo',
				'Foo',
				array(
					new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag' ),
					new SettingsField( id: 'extra', type: 'text', label: 'Extra' ),
				),
			),
		);

		// WooCommerce stores an off checkbox as the string 'no', never boolean false (which WordPress cannot
		// keep distinct from an absent option), so the distinct-from-absent semantic is exercised with 'no'.
		$backend->set( 'flag', 'no' );

		self::assertTrue( $backend->has( 'flag' ) );
		self::assertSame( 'no', $backend->get( 'flag' ) );
		self::assertFalse( $backend->has( 'extra' ) );
		self::assertSame( 'sentinel', $backend->get( 'extra', 'sentinel' ) );
	}

	public function test_the_sanitize_bridge_runs_the_descriptor_sanitizer(): void {
		$backend = new WooCommerceSettingsBackend( FooWCSettingsPage::class );
		$backend->register_page(
			$this->page(
				'dws-foo',
				'dws_foo',
				'Foo',
				array(
					new SettingsField( id: 'code', type: 'text', label: 'Code', sanitize: static fn ( mixed $value ): string => \strtoupper( (string) $value ) ),
				),
			),
		);

		$sanitized = \apply_filters( 'woocommerce_admin_settings_sanitize_option_dws-foo_code', 'abc', array(), 'abc' );

		self::assertSame( 'ABC', $sanitized );
	}

	public function test_register_page_defers_binding_until_woocommerce_builds_pages(): void {
		$backend = new WooCommerceSettingsBackend( LazyBindWCSettingsPage::class );
		$backend->register_page(
			$this->page( 'dws-lazy', 'dws_lazy', 'Lazy', array( new SettingsField( id: 'a', type: 'text', label: 'A' ) ) ),
		);

		// register_page() runs at plugins_loaded, before WooCommerce has loaded WC_Settings_Page, so it must
		// not touch the page subclass: binding is deferred to the get_settings_pages filter. The subclass is
		// therefore still unbound here, and instantiating it throws.
		$this->expectException( UnboundSettingsPageException::class );

		new LazyBindWCSettingsPage();
	}

	public function test_a_field_the_user_cannot_edit_is_not_rendered(): void {
		$backend = new WooCommerceSettingsBackend( FooWCSettingsPage::class );
		$backend->register_page(
			$this->page(
				'dws-foo',
				'dws_foo',
				'Foo',
				array(
					new SettingsField( id: 'open', type: 'text', label: 'Open' ),
					new SettingsField( id: 'secret', type: 'text', label: 'Secret', capability: 'dws_protected_cap' ),
				),
			),
		);

		\apply_filters( 'woocommerce_get_settings_pages', array() );
		$ids = \array_column( ( new FooWCSettingsPage() )->get_settings_for_section( '' ), 'id' );

		self::assertContains( 'dws-foo_open', $ids );
		self::assertNotContains( 'dws-foo_secret', $ids );
	}

	public function test_a_save_of_a_field_the_user_cannot_edit_is_rejected(): void {
		$backend = new WooCommerceSettingsBackend( FooWCSettingsPage::class );
		$backend->register_page(
			$this->page(
				'dws-foo',
				'dws_foo',
				'Foo',
				array( new SettingsField( id: 'secret', type: 'text', label: 'Secret', capability: 'dws_protected_cap' ) ),
			),
		);
		$backend->set( 'secret', 'original' );

		// WooCommerce fires the per-option sanitize filter on save; a user lacking the field capability
		// keeps the stored value rather than overwriting it.
		$saved = \apply_filters( 'woocommerce_admin_settings_sanitize_option_dws-foo_secret', 'tampered', array(), 'tampered' );

		self::assertSame( 'original', $saved );
	}

	public function test_a_no_cap_save_of_an_unstored_field_leaves_it_absent(): void {
		$backend = new WooCommerceSettingsBackend( FooWCSettingsPage::class );
		$backend->register_page(
			$this->page(
				'dws-foo',
				'dws_foo',
				'Foo',
				array( new SettingsField( id: 'secret', type: 'text', label: 'Secret', capability: 'dws_protected_cap' ) ),
			),
		);

		// The field was never stored; a no-cap save must not create it — the gate returns null so
		// WooCommerce skips the option rather than writing a value.
		$result = \apply_filters( 'woocommerce_admin_settings_sanitize_option_dws-foo_secret', 'tampered', array(), 'tampered' );

		self::assertNull( $result );
		self::assertFalse( $backend->has( 'secret' ) );
	}

	public function test_accessing_an_unregistered_field_throws(): void {
		$backend = new WooCommerceSettingsBackend( FooWCSettingsPage::class );
		$backend->register_page( $this->single_field_page( 'store_name', 'text', 'Store Name' ) );

		$this->expectException( InvalidSettingsFieldException::class );

		$backend->get( 'unknown' );
	}

	public function test_a_duplicate_field_id_across_sections_throws(): void {
		$backend = new WooCommerceSettingsBackend( FooWCSettingsPage::class );

		$this->expectException( DuplicateSettingsFieldException::class );

		$backend->register_page(
			new SettingsPage(
				slug: 'dws-foo',
				page_title: 'Foo',
				menu_title: 'Foo',
				capability: 'manage_woocommerce',
				location: 'dws_foo',
				sections: array(
					new SettingsSection( 'general', 'General', array( new SettingsField( id: 'dup', type: 'text', label: 'A' ) ) ),
					new SettingsSection( 'advanced', 'Advanced', array( new SettingsField( id: 'dup', type: 'text', label: 'B' ) ) ),
				),
			),
		);
	}

	/**
	 * @param  mixed $pages Result of the woocommerce_get_settings_pages filter.
	 * @return list<string>
	 */
	private function tab_ids( mixed $pages ): array {
		$ids = array();
		if ( \is_array( $pages ) ) {
			foreach ( $pages as $page ) {
				if ( $page instanceof \WC_Settings_Page ) {
					$ids[] = (string) $page->get_id();
				}
			}
		}

		return $ids;
	}

	private function single_field_page( string $field_id, string $type, string $label ): SettingsPage {
		return $this->page( 'dws-foo', 'dws_foo', 'Foo', array( new SettingsField( id: $field_id, type: $type, label: $label ) ) );
	}

	/**
	 * @param list<SettingsField> $fields
	 */
	private function page( string $slug, string $location, string $menu_title, array $fields ): SettingsPage {
		return new SettingsPage(
			slug: $slug,
			page_title: $menu_title . ' Settings',
			menu_title: $menu_title,
			capability: 'manage_woocommerce',
			location: $location,
			sections: array( new SettingsSection( 'general', 'General', $fields ) ),
		);
	}

	private function clean(): void {
		foreach ( self::OPTION_KEYS as $option ) {
			\delete_option( $option );
		}
	}
}
