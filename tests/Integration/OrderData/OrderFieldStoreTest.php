<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration\OrderData;

use Automattic\WooCommerce\Utilities\OrderUtil;
use DeepWebSolutions\Framework\Settings\MetaField\ObjectFieldForm;
use DeepWebSolutions\Framework\Settings\MetaField\ValueObjects\FieldGroup;
use DeepWebSolutions\Framework\Settings\MetaField\ValueObjects\MetaBoxPlacement;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\DuplicateSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\Field\FieldProcessor;
use DeepWebSolutions\Framework\Settings\Schema\Field\FieldRenderer;
use DeepWebSolutions\Framework\Settings\Schema\Field\FieldType;
use DeepWebSolutions\Framework\Settings\Schema\Options\OptionsResolver;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\CustomFieldType;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\WooCommerce\OrderData\Exceptions\UnsupportedOrderScreenException;
use DeepWebSolutions\Framework\WooCommerce\OrderData\OrderFieldStore;
use DeepWebSolutions\Framework\WooCommerce\OrderData\OrderMetaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( OrderFieldStore::class )]
#[UsesClass( ObjectFieldForm::class )]
#[UsesClass( OrderMetaRepository::class )]
#[UsesClass( FieldGroup::class )]
#[UsesClass( MetaBoxPlacement::class )]
#[UsesClass( SettingsField::class )]
#[UsesClass( FieldRenderer::class )]
#[UsesClass( FieldProcessor::class )]
#[UsesClass( OptionsResolver::class )]
#[UsesClass( FieldType::class )]
#[UsesClass( CustomFieldType::class )]
final class OrderFieldStoreTest extends TestCase {
	private const GROUP_ID = 'dws_unlock';

	private const ISOLATED_HOOKS = array(
		'woocommerce_process_shop_order_meta',
		'woocommerce_after_order_object_save',
		'add_meta_boxes_woocommerce_page_wc-orders',
		'add_meta_boxes_admin_page_wc-orders',
		'add_meta_boxes_shop_order',
	);

	private int $order_id = 0;

	/**
	 * @var array<string, mixed>
	 */
	private array $saved_hooks = array();

	protected function setUp(): void {
		parent::setUp();

		if ( ! \function_exists( 'wc_create_order' ) ) {
			self::markTestSkipped( 'WooCommerce is not active.' );
		}

		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/user.php';

		\wp_set_current_user( 1 );

		global $wp_filter;
		foreach ( self::ISOLATED_HOOKS as $hook ) {
			$this->saved_hooks[ $hook ] = $wp_filter[ $hook ] ?? null;
			unset( $wp_filter[ $hook ] );
		}

		$GLOBALS['wp_meta_boxes'] = array();
		$_POST                    = array();

		$order = \wc_create_order();
		\assert( $order instanceof \WC_Order );
		$this->order_id = $order->get_id();
	}

	protected function tearDown(): void {
		// Drop the HPOS override before the order lookup so cleanup runs against the real storage mode.
		\remove_all_filters( 'option_woocommerce_custom_orders_table_enabled' );

		$order = \wc_get_order( $this->order_id );
		if ( $order instanceof \WC_Order ) {
			$order->delete( true );
		}
		$_POST                     = array();
		$GLOBALS['current_screen'] = null;

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

	public function test_register_adds_the_box_on_the_resolved_order_screen(): void {
		$screen = $this->order_screen();
		\set_current_screen( $screen );

		( new OrderFieldStore() )->register( $this->group(), $this->placement() );
		\do_action( "add_meta_boxes_$screen", \wc_get_order( $this->order_id ) );

		self::assertArrayHasKey( self::GROUP_ID, $this->boxes_on( $screen ) );

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			self::assertNotFalse( \has_action( 'add_meta_boxes_woocommerce_page_wc-orders' ) );
			self::assertNotFalse( \has_action( 'add_meta_boxes_admin_page_wc-orders' ) );
			self::assertFalse( \has_action( 'add_meta_boxes_shop_order' ) );
		}
	}

	public function test_register_targets_the_legacy_post_screen_when_hpos_is_disabled(): void {
		\add_filter( 'option_woocommerce_custom_orders_table_enabled', static fn (): string => 'no' );

		( new OrderFieldStore() )->register( $this->group(), $this->placement() );

		self::assertNotFalse( \has_action( 'add_meta_boxes_shop_order' ) );
		self::assertFalse( \has_action( 'add_meta_boxes_woocommerce_page_wc-orders' ) );
		self::assertFalse( \has_action( 'add_meta_boxes_admin_page_wc-orders' ) );
	}

	public function test_register_targets_both_hpos_screens_when_hpos_is_enabled(): void {
		\add_filter( 'option_woocommerce_custom_orders_table_enabled', static fn (): string => 'yes' );

		( new OrderFieldStore() )->register( $this->group(), $this->placement() );

		self::assertNotFalse( \has_action( 'add_meta_boxes_woocommerce_page_wc-orders' ) );
		self::assertNotFalse( \has_action( 'add_meta_boxes_admin_page_wc-orders' ) );
		self::assertFalse( \has_action( 'add_meta_boxes_shop_order' ) );
	}

	public function test_the_registered_box_renders_a_nonce_and_its_field_control(): void {
		$screen = $this->order_screen();
		\set_current_screen( $screen );

		( new OrderFieldStore() )->register( $this->group(), $this->placement() );
		\do_action( "add_meta_boxes_$screen", \wc_get_order( $this->order_id ) );

		$html = $this->render_box( $screen );

		self::assertStringContainsString( $this->nonce_name(), $html );
		self::assertStringContainsString( 'name="dws_unlock[unlocked]"', $html );
	}

	public function test_a_bespoke_render_and_save_group_emits_the_nonce_and_saves(): void {
		$screen = $this->order_screen();
		\set_current_screen( $screen );

		$saved_for = 0;
		$group     = new FieldGroup(
			id: self::GROUP_ID,
			title: 'Bespoke',
			fields_provider: static fn ( int $object_id ): array => array(),
			render: static fn ( int $object_id ): string => '<p>bespoke</p>',
			save: function ( int $object_id ) use ( &$saved_for ): void {
				$saved_for = $object_id;
			},
		);
		$store = new OrderFieldStore();
		$store->register( $group, $this->placement() );
		\do_action( "add_meta_boxes_$screen", \wc_get_order( $this->order_id ) );

		$html = $this->render_box( $screen );
		self::assertStringContainsString( $this->nonce_name(), $html );
		self::assertStringContainsString( 'bespoke', $html );

		$_POST = array( $this->nonce_name() => $this->nonce() );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
		self::assertSame( $this->order_id, $saved_for );
	}

	public function test_a_truthy_submission_is_stored_and_a_falsy_one_deletes_the_meta(): void {
		$repo  = new OrderMetaRepository();
		$store = new OrderFieldStore();
		$store->register( $this->group(), $this->placement() );

		$_POST = array( $this->nonce_name() => $this->nonce(), self::GROUP_ID => array( 'unlocked' => '1' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
		self::assertTrue( $repo->has( $this->order_id, 'unlocked' ) );

		$_POST = array( $this->nonce_name() => $this->nonce() );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
		self::assertFalse( $repo->has( $this->order_id, 'unlocked' ) );
	}

	public function test_a_zero_value_is_stored_not_revoked(): void {
		$repo  = new OrderMetaRepository();
		$store = new OrderFieldStore();
		$store->register( $this->group_with( new SettingsField( id: 'note', type: 'text', label: 'Note' ) ), $this->placement() );

		$_POST = array( $this->nonce_name() => $this->nonce(), self::GROUP_ID => array( 'note' => '0' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		self::assertTrue( $repo->has( $this->order_id, 'note' ) );
		self::assertSame( '0', $repo->get( $this->order_id, 'note' ) );
	}

	public function test_save_applies_the_builtin_default_sanitizer(): void {
		$repo  = new OrderMetaRepository();
		$store = new OrderFieldStore();
		$store->register( $this->group_with( new SettingsField( id: 'note', type: 'text', label: 'Note' ) ), $this->placement() );

		$_POST = array( $this->nonce_name() => $this->nonce(), self::GROUP_ID => array( 'note' => '<script>x</script>' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		self::assertSame( 'x', $repo->get( $this->order_id, 'note' ) );
	}

	public function test_save_is_skipped_without_a_valid_nonce(): void {
		$repo  = new OrderMetaRepository();
		$store = new OrderFieldStore();
		$store->register( $this->group(), $this->placement() );

		$_POST = array( self::GROUP_ID => array( 'unlocked' => '1' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		self::assertFalse( $repo->has( $this->order_id, 'unlocked' ) );
	}

	public function test_register_also_registers_on_the_restricted_hpos_screen(): void {
		if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			self::markTestSkipped( 'Requires HPOS for the admin.php order screen variant.' );
		}
		\set_current_screen( 'admin_page_wc-orders' );

		( new OrderFieldStore() )->register( $this->group(), $this->placement() );
		\do_action( 'add_meta_boxes_admin_page_wc-orders', \wc_get_order( $this->order_id ) );

		self::assertArrayHasKey( self::GROUP_ID, $this->boxes_on( 'admin_page_wc-orders' ) );
	}

	public function test_a_field_meta_key_overrides_the_id_for_storage(): void {
		$repo  = new OrderMetaRepository();
		$store = new OrderFieldStore();
		$store->register(
			$this->group_with( new SettingsField( id: 'unlocked', type: 'checkbox', label: 'Unlocked', meta_key: '_lpm_unlocked' ) ),
			$this->placement(),
		);

		$_POST = array( $this->nonce_name() => $this->nonce(), self::GROUP_ID => array( 'unlocked' => '1' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		self::assertTrue( $repo->has( $this->order_id, '_lpm_unlocked' ) );
		self::assertFalse( $repo->has( $this->order_id, 'unlocked' ) );
	}

	public function test_save_is_skipped_for_a_user_without_the_order_capability(): void {
		$subscriber = \wp_insert_user(
			array( 'user_login' => 'dws_sub_' . $this->order_id, 'user_pass' => 'x', 'role' => 'subscriber' ),
		);
		\assert( \is_int( $subscriber ) );
		\wp_set_current_user( $subscriber );

		$repo  = new OrderMetaRepository();
		$store = new OrderFieldStore();
		$store->register( $this->group(), $this->placement() );

		$_POST = array( $this->nonce_name() => $this->nonce(), self::GROUP_ID => array( 'unlocked' => '1' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
		self::assertFalse( $repo->has( $this->order_id, 'unlocked' ) );

		\wp_delete_user( $subscriber );
	}

	public function test_a_configured_box_capability_overrides_the_default(): void {
		$repo      = new OrderMetaRepository();
		$placement = new MetaBoxPlacement( screen: 'shop_order', context: 'side', priority: 'default', capability: 'dws_nonexistent_cap' );
		$store     = new OrderFieldStore();
		$store->register( $this->group(), $placement );

		// The administrator passes the default order-edit gate but lacks the configured capability, so the save is refused.
		$_POST = array( $this->nonce_name() => $this->nonce(), self::GROUP_ID => array( 'unlocked' => '1' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		self::assertFalse( $repo->has( $this->order_id, 'unlocked' ) );
	}

	public function test_the_box_is_not_added_for_a_user_who_cannot_edit_the_order(): void {
		$screen = $this->order_screen();
		\set_current_screen( $screen );

		$subscriber = \wp_insert_user(
			array( 'user_login' => 'dws_sub_render_' . $this->order_id, 'user_pass' => 'x', 'role' => 'subscriber' ),
		);
		\assert( \is_int( $subscriber ) );
		\wp_set_current_user( $subscriber );

		( new OrderFieldStore() )->register( $this->group(), $this->placement() );
		\do_action( "add_meta_boxes_$screen", \wc_get_order( $this->order_id ) );

		self::assertArrayNotHasKey( self::GROUP_ID, $this->boxes_on( $screen ) );

		\wp_delete_user( $subscriber );
	}

	public function test_a_multi_field_save_persists_the_order_once(): void {
		$repo  = new OrderMetaRepository();
		$store = new OrderFieldStore();
		$store->register(
			new FieldGroup(
				id: self::GROUP_ID,
				title: 'Multi',
				fields_provider: static fn ( int $object_id ): array => array(
					new SettingsField( id: 'first', type: 'text', label: 'First' ),
					new SettingsField( id: 'second', type: 'text', label: 'Second' ),
				),
			),
			$this->placement(),
		);

		$saves = 0;
		\add_action(
			'woocommerce_after_order_object_save',
			static function () use ( &$saves ): void {
				++$saves;
			},
		);

		$_POST = array( $this->nonce_name() => $this->nonce(), self::GROUP_ID => array( 'first' => 'A', 'second' => 'B' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		self::assertSame( 'A', $repo->get( $this->order_id, 'first' ) );
		self::assertSame( 'B', $repo->get( $this->order_id, 'second' ) );
		self::assertSame( 1, $saves );
	}

	public function test_a_no_op_save_does_not_persist_the_order(): void {
		$store = new OrderFieldStore();
		$store->register( $this->group(), $this->placement() );

		$saves = 0;
		\add_action(
			'woocommerce_after_order_object_save',
			static function () use ( &$saves ): void {
				++$saves;
			},
		);

		// The checkbox is absent (unchecked) and was never stored, so the revoke is a no-op — no order write.
		$_POST = array( $this->nonce_name() => $this->nonce() );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		self::assertSame( 0, $saves );
	}

	public function test_a_duplicate_field_id_in_a_group_is_rejected(): void {
		$store = new OrderFieldStore();
		$store->register(
			new FieldGroup(
				id: self::GROUP_ID,
				title: 'Dup',
				fields_provider: static fn ( int $object_id ): array => array(
					new SettingsField( id: 'flag', type: 'checkbox', label: 'A' ),
					new SettingsField( id: 'flag', type: 'checkbox', label: 'B' ),
				),
			),
			$this->placement(),
		);

		$_POST = array( $this->nonce_name() => $this->nonce(), self::GROUP_ID => array( 'flag' => '1' ) );

		$this->expectException( DuplicateSettingsFieldException::class );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
	}

	public function test_register_rejects_a_non_order_screen(): void {
		$placement = new MetaBoxPlacement( screen: 'post', context: 'side', priority: 'default' );

		$this->expectException( UnsupportedOrderScreenException::class );

		( new OrderFieldStore() )->register( $this->group(), $placement );
	}

	public function test_a_group_title_is_escaped_before_registration(): void {
		$screen = $this->order_screen();
		\set_current_screen( $screen );

		$group = new FieldGroup(
			id: self::GROUP_ID,
			title: '<script>alert(1)</script>',
			fields_provider: static fn ( int $object_id ): array => array(),
		);
		( new OrderFieldStore() )->register( $group, $this->placement() );
		\do_action( "add_meta_boxes_$screen", \wc_get_order( $this->order_id ) );

		$title = (string) ( ( (array) ( $this->boxes_on( $screen )[ self::GROUP_ID ] ?? array() ) )['title'] ?? '' );
		self::assertStringNotContainsString( '<script>', $title );
		self::assertStringContainsString( '&lt;script&gt;', $title );
	}

	public function test_a_custom_field_type_renders_and_saves_through_the_order_surface(): void {
		$screen = $this->order_screen();
		\set_current_screen( $screen );

		$custom_types = array(
			'single_select_page' => new CustomFieldType(
				type: 'single_select_page',
				render: static fn ( SettingsField $field, mixed $value, string $name ): string => \sprintf(
					'<select class="dws-page-select" name="%s"><option value="42"%s>Sample</option></select>',
					\esc_attr( $name ),
					\selected( '42', (string) $value, false ),
				),
			),
		);
		$group = $this->group_with( new SettingsField( id: 'home_page', type: 'single_select_page', label: 'Home Page' ) );
		$repo  = new OrderMetaRepository();
		$store = new OrderFieldStore(
			renderer: new FieldRenderer( custom_types: $custom_types ),
			processor: new FieldProcessor( custom_types: $custom_types ),
		);
		$store->register( $group, $this->placement() );

		\do_action( "add_meta_boxes_$screen", \wc_get_order( $this->order_id ) );
		$html = $this->render_box( $screen );
		self::assertStringContainsString( 'class="dws-page-select"', $html );
		self::assertStringContainsString( 'name="dws_unlock[home_page]"', $html );

		$_POST = array( $this->nonce_name() => $this->nonce(), self::GROUP_ID => array( 'home_page' => '42' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		self::assertSame( '42', $repo->get( $this->order_id, 'home_page' ) );
	}

	public function test_clearing_a_field_with_a_default_revokes_it_without_restoring_the_default(): void {
		$screen = $this->order_screen();
		\set_current_screen( $screen );

		$repo  = new OrderMetaRepository();
		$store = new OrderFieldStore();
		$store->register(
			$this->group_with( new SettingsField( id: 'note', type: 'text', label: 'Note', default_value: 'preset' ) ),
			$this->placement(),
		);

		$_POST = array( $this->nonce_name() => $this->nonce(), self::GROUP_ID => array( 'note' => 'typed' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
		self::assertSame( 'typed', $repo->get( $this->order_id, 'note' ) );

		$_POST = array( $this->nonce_name() => $this->nonce(), self::GROUP_ID => array( 'note' => '' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
		self::assertFalse( $repo->has( $this->order_id, 'note' ) );

		\do_action( "add_meta_boxes_$screen", \wc_get_order( $this->order_id ) );
		$html = $this->render_box( $screen );
		self::assertStringNotContainsString( 'preset', $html );
	}

	private function order_screen(): string {
		return OrderUtil::custom_orders_table_usage_is_enabled() ? 'woocommerce_page_wc-orders' : 'shop_order';
	}

	private function render_box( string $screen ): string {
		$definition = (array) ( $this->boxes_on( $screen )[ self::GROUP_ID ] ?? array() );
		$callback   = $definition['callback'] ?? null;
		\assert( \is_callable( $callback ) );

		\ob_start();
		$callback( \wc_get_order( $this->order_id ) );

		return (string) \ob_get_clean();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function boxes_on( string $screen ): array {
		global $wp_meta_boxes;
		$by_priority = (array) ( ( (array) ( ( (array) $wp_meta_boxes )[ $screen ] ?? array() ) )['side'] ?? array() );

		return (array) ( $by_priority['default'] ?? array() );
	}

	private function group(): FieldGroup {
		return $this->group_with( new SettingsField( id: 'unlocked', type: 'checkbox', label: 'Unlocked' ) );
	}

	private function group_with( SettingsField $field ): FieldGroup {
		return new FieldGroup(
			id: self::GROUP_ID,
			title: 'Unlock',
			fields_provider: static fn ( int $object_id ): array => array( $field ),
		);
	}

	private function placement(): MetaBoxPlacement {
		return new MetaBoxPlacement( screen: 'shop_order', context: 'side', priority: 'default' );
	}

	protected function nonce_name(): string {
		return ( new ObjectFieldForm( new OrderMetaRepository() ) )->get_nonce_name( $this->group() );
	}

	private function nonce(): string {
		return \wp_create_nonce( ( new ObjectFieldForm( new OrderMetaRepository() ) )->get_nonce_action( $this->group(), $this->order_id ) );
	}
}
