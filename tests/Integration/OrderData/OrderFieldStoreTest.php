<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration\OrderData;

use Automattic\WooCommerce\Utilities\OrderUtil;
use DeepWebSolutions\Framework\Settings\Exceptions\DuplicateSettingsFieldException;
use DeepWebSolutions\Framework\Settings\FieldProcessor;
use DeepWebSolutions\Framework\Settings\FieldRenderer;
use DeepWebSolutions\Framework\Settings\FieldType;
use DeepWebSolutions\Framework\Settings\ObjectField\Exceptions\InvalidObjectMetaBoxException;
use DeepWebSolutions\Framework\Settings\ObjectField\ValueObjects\ObjectMetaBox;
use DeepWebSolutions\Framework\Settings\OptionsResolver;
use DeepWebSolutions\Framework\Settings\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\WooCommerce\OrderData\OrderFieldStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( OrderFieldStore::class )]
#[UsesClass( ObjectMetaBox::class )]
#[UsesClass( SettingsField::class )]
#[UsesClass( FieldRenderer::class )]
#[UsesClass( FieldProcessor::class )]
#[UsesClass( OptionsResolver::class )]
#[UsesClass( FieldType::class )]
final class OrderFieldStoreTest extends TestCase {
	private const BOX_ID       = 'dws_unlock';
	private const NONCE_NAME   = 'dws_object_field_dws_unlock_nonce';
	private const NONCE_ACTION = 'dws_object_field_dws_unlock';

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

		// add_meta_box() and WP_Screen (its screen resolution) live in the admin includes, loaded on
		// real admin requests where add_meta_boxes_{screen} fires; the CLI context must require them.
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/user.php';

		\wp_set_current_user( 1 );

		// Isolate this store's hooks (the order save and each order screen's add_meta_boxes) without
		// stripping WooCommerce's own callbacks for the rest of the process: stash each hook's WP_Hook
		// and unset it here, restoring the originals in tearDown.
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

	public function test_set_and_get_round_trip_on_an_order(): void {
		$store = new OrderFieldStore();

		$store->set( $this->order_id, '_dws_unlocked', 'yes' );

		self::assertSame( 'yes', $store->get( $this->order_id, '_dws_unlocked' ) );
	}

	public function test_get_returns_the_default_when_nothing_is_stored(): void {
		$store = new OrderFieldStore();

		self::assertSame( 'fallback', $store->get( $this->order_id, '_dws_absent', 'fallback' ) );
	}

	public function test_has_reports_presence_and_delete_removes_the_value(): void {
		$store = new OrderFieldStore();

		self::assertFalse( $store->has( $this->order_id, '_dws_flag' ) );

		$store->set( $this->order_id, '_dws_flag', '1' );
		self::assertTrue( $store->has( $this->order_id, '_dws_flag' ) );

		self::assertTrue( $store->delete( $this->order_id, '_dws_flag' ) );
		self::assertFalse( $store->has( $this->order_id, '_dws_flag' ) );
		self::assertFalse( $store->delete( $this->order_id, '_dws_flag' ) );
	}

	public function test_crud_falls_back_to_post_meta_for_a_non_order_id(): void {
		$post_id = \wp_insert_post( array( 'post_title' => 'Probe', 'post_status' => 'publish' ) );
		\assert( \is_int( $post_id ) );
		self::assertFalse( \wc_get_order( $post_id ) ); // guarantee the non-order fallback branch, not an id collision
		$store = new OrderFieldStore();

		$store->set( $post_id, '_dws_post_key', 'value' );

		self::assertSame( 'value', \get_post_meta( $post_id, '_dws_post_key', true ) ); // landed in post meta, not order meta
		self::assertSame( 'value', $store->get( $post_id, '_dws_post_key' ) );
		self::assertTrue( $store->has( $post_id, '_dws_post_key' ) );
		self::assertTrue( $store->delete( $post_id, '_dws_post_key' ) );
		self::assertFalse( $store->has( $post_id, '_dws_post_key' ) );

		\wp_delete_post( $post_id, true );
	}

	public function test_register_meta_box_registers_on_the_resolved_order_screen(): void {
		$screen = OrderUtil::custom_orders_table_usage_is_enabled() ? 'woocommerce_page_wc-orders' : 'shop_order';
		\set_current_screen( $screen );

		( new OrderFieldStore() )->register_meta_box( $this->box() );
		\do_action( "add_meta_boxes_$screen" );

		self::assertArrayHasKey( self::BOX_ID, $this->boxes_on( $screen ) );

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// Both HPOS order screens are hooked — the menu-visible page and the admin.php variant for a
			// user who cannot view the WooCommerce menu — while the legacy screen is not.
			self::assertNotFalse( \has_action( 'add_meta_boxes_woocommerce_page_wc-orders' ) );
			self::assertNotFalse( \has_action( 'add_meta_boxes_admin_page_wc-orders' ) );
			self::assertFalse( \has_action( 'add_meta_boxes_shop_order' ) );
		}
	}

	public function test_the_registered_box_renders_a_nonce_and_its_field_control(): void {
		$screen = OrderUtil::custom_orders_table_usage_is_enabled() ? 'woocommerce_page_wc-orders' : 'shop_order';
		\set_current_screen( $screen );

		( new OrderFieldStore() )->register_meta_box( $this->box() );
		\do_action( "add_meta_boxes_$screen" );

		$definition = (array) ( $this->boxes_on( $screen )[ self::BOX_ID ] ?? array() );
		$callback   = $definition['callback'] ?? null;
		\assert( \is_callable( $callback ) );

		\ob_start();
		$callback( \wc_get_order( $this->order_id ) );
		$html = (string) \ob_get_clean();

		self::assertStringContainsString( self::NONCE_NAME, $html );
		self::assertStringContainsString( 'name="dws_unlock[unlocked]"', $html );
	}

	public function test_a_bespoke_render_and_save_descriptor_emits_the_nonce_and_saves(): void {
		$screen = OrderUtil::custom_orders_table_usage_is_enabled() ? 'woocommerce_page_wc-orders' : 'shop_order';
		\set_current_screen( $screen );

		$saved_for = 0;
		$box       = new ObjectMetaBox(
			id: self::BOX_ID,
			title: 'Bespoke',
			screen: 'shop_order',
			context: 'side',
			priority: 'default',
			fields_provider: static fn ( int $object_id ): array => array(),
			render: static fn ( int $object_id ): string => '<p>bespoke</p>',
			save: function ( int $object_id ) use ( &$saved_for ): void {
				$saved_for = $object_id;
			},
		);
		$store = new OrderFieldStore();
		$store->register_meta_box( $box );
		\do_action( "add_meta_boxes_$screen" );

		// A bespoke renderer still emits the store nonce, so the bespoke save's guard can pass.
		$definition = (array) ( $this->boxes_on( $screen )[ self::BOX_ID ] ?? array() );
		$callback   = $definition['callback'] ?? null;
		\assert( \is_callable( $callback ) );
		\ob_start();
		$callback( \wc_get_order( $this->order_id ) );
		$html = (string) \ob_get_clean();
		self::assertStringContainsString( self::NONCE_NAME, $html );
		self::assertStringContainsString( 'bespoke', $html );

		// And the bespoke save handler runs once the nonce is valid.
		$_POST = array( self::NONCE_NAME => $this->nonce() );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
		self::assertSame( $this->order_id, $saved_for );
	}

	public function test_a_truthy_submission_is_stored_and_a_falsy_one_deletes_the_meta(): void {
		$store = new OrderFieldStore();
		$store->register_meta_box( $this->box() );

		// Checkbox checked → meta stored.
		$_POST = array(
			self::NONCE_NAME => $this->nonce(),
			self::BOX_ID     => array( 'unlocked' => '1' ),
		);
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
		self::assertTrue( $store->has( $this->order_id, 'unlocked' ) );

		// Checkbox unchecked (absent from the submission) → meta deleted (revoke semantics).
		$_POST = array( self::NONCE_NAME => $this->nonce() );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
		self::assertFalse( $store->has( $this->order_id, 'unlocked' ) );
	}

	public function test_a_zero_value_is_stored_not_revoked(): void {
		$box = new ObjectMetaBox(
			id: self::BOX_ID,
			title: 'Note',
			screen: 'shop_order',
			context: 'side',
			priority: 'default',
			fields_provider: static fn ( int $object_id ): array => array(
				new SettingsField( id: 'note', type: 'text', label: 'Note' ),
			),
		);
		$store = new OrderFieldStore();
		$store->register_meta_box( $box );

		// A literal "0" is a real value, not an empty submission, so it must persist rather than revoke.
		$_POST = array(
			self::NONCE_NAME => $this->nonce(),
			self::BOX_ID     => array( 'note' => '0' ),
		);
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		self::assertTrue( $store->has( $this->order_id, 'note' ) );
		self::assertSame( '0', $store->get( $this->order_id, 'note' ) );
	}

	public function test_save_is_skipped_without_a_valid_nonce(): void {
		$store = new OrderFieldStore();
		$store->register_meta_box( $this->box() );

		$_POST = array( self::BOX_ID => array( 'unlocked' => '1' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		self::assertFalse( $store->has( $this->order_id, 'unlocked' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function boxes_on( string $screen ): array {
		global $wp_meta_boxes;
		$by_priority = (array) ( ( (array) ( ( (array) $wp_meta_boxes )[ $screen ] ?? array() ) )['side'] ?? array() );

		return (array) ( $by_priority['default'] ?? array() );
	}

	public function test_the_post_meta_fallback_preserves_backslashes(): void {
		$post_id = \wp_insert_post( array( 'post_title' => 'Probe', 'post_status' => 'publish' ) );
		\assert( \is_int( $post_id ) );
		self::assertFalse( \wc_get_order( $post_id ) );
		$store = new OrderFieldStore();

		$store->set( $post_id, '_dws_path', 'C:\\Users\\dev\\file.txt' );

		// update_post_meta() unslashes internally; without the compensating slash the backslashes drop.
		self::assertSame( 'C:\\Users\\dev\\file.txt', $store->get( $post_id, '_dws_path' ) );

		\wp_delete_post( $post_id, true );
	}

	public function test_clearing_a_field_with_a_default_revokes_it_without_restoring_the_default(): void {
		$screen = OrderUtil::custom_orders_table_usage_is_enabled() ? 'woocommerce_page_wc-orders' : 'shop_order';
		\set_current_screen( $screen );

		$box = new ObjectMetaBox(
			id: self::BOX_ID,
			title: 'Note',
			screen: 'shop_order',
			context: 'side',
			priority: 'default',
			fields_provider: static fn ( int $object_id ): array => array(
				new SettingsField( id: 'note', type: 'text', label: 'Note', default: 'preset' ),
			),
		);
		$store = new OrderFieldStore();
		$store->register_meta_box( $box );

		// Store a value, then submit it empty: delete-on-falsy revokes the meta.
		$_POST = array( self::NONCE_NAME => $this->nonce(), self::BOX_ID => array( 'note' => 'typed' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
		self::assertSame( 'typed', $store->get( $this->order_id, 'note' ) );

		$_POST = array( self::NONCE_NAME => $this->nonce(), self::BOX_ID => array( 'note' => '' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
		self::assertFalse( $store->has( $this->order_id, 'note' ) );

		// The cleared field renders unset — its non-empty default must not spring back.
		\do_action( "add_meta_boxes_$screen" );
		$definition = (array) ( $this->boxes_on( $screen )[ self::BOX_ID ] ?? array() );
		$callback   = $definition['callback'] ?? null;
		\assert( \is_callable( $callback ) );
		\ob_start();
		$callback( \wc_get_order( $this->order_id ) );
		$html = (string) \ob_get_clean();
		self::assertStringNotContainsString( 'preset', $html );
	}

	public function test_register_meta_box_also_registers_on_the_restricted_hpos_screen(): void {
		if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			self::markTestSkipped( 'Requires HPOS for the admin.php order screen variant.' );
		}
		\set_current_screen( 'admin_page_wc-orders' );

		( new OrderFieldStore() )->register_meta_box( $this->box() );
		\do_action( 'add_meta_boxes_admin_page_wc-orders' );

		self::assertArrayHasKey( self::BOX_ID, $this->boxes_on( 'admin_page_wc-orders' ) );
	}

	public function test_a_field_meta_key_overrides_the_id_for_storage(): void {
		$box = new ObjectMetaBox(
			id: self::BOX_ID,
			title: 'Unlock',
			screen: 'shop_order',
			context: 'side',
			priority: 'default',
			fields_provider: static fn ( int $object_id ): array => array(
				new SettingsField( id: 'unlocked', type: 'checkbox', label: 'Unlocked', meta_key: '_lpm_unlocked' ),
			),
		);
		$store = new OrderFieldStore();
		$store->register_meta_box( $box );

		$_POST = array( self::NONCE_NAME => $this->nonce(), self::BOX_ID => array( 'unlocked' => '1' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		// Persisted under the field's meta_key, not its id.
		self::assertTrue( $store->has( $this->order_id, '_lpm_unlocked' ) );
		self::assertFalse( $store->has( $this->order_id, 'unlocked' ) );
	}

	public function test_save_is_skipped_for_a_user_without_the_orders_capability(): void {
		$subscriber = \wp_insert_user(
			array( 'user_login' => 'dws_sub_' . $this->order_id, 'user_pass' => 'x', 'role' => 'subscriber' ),
		);
		\assert( \is_int( $subscriber ) );
		\wp_set_current_user( $subscriber );

		$store = new OrderFieldStore();
		$store->register_meta_box( $this->box() );

		// A valid nonce for this user, but the user lacks edit_shop_orders: the save must be refused.
		$_POST = array( self::NONCE_NAME => $this->nonce(), self::BOX_ID => array( 'unlocked' => '1' ) );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
		self::assertFalse( $store->has( $this->order_id, 'unlocked' ) );

		\wp_delete_user( $subscriber );
	}

	public function test_a_multi_field_save_persists_the_order_once(): void {
		$box = new ObjectMetaBox(
			id: self::BOX_ID,
			title: 'Multi',
			screen: 'shop_order',
			context: 'side',
			priority: 'default',
			fields_provider: static fn ( int $object_id ): array => array(
				new SettingsField( id: 'first', type: 'text', label: 'First' ),
				new SettingsField( id: 'second', type: 'text', label: 'Second' ),
			),
		);
		$store = new OrderFieldStore();
		$store->register_meta_box( $box );

		$saves = 0;
		\add_action(
			'woocommerce_after_order_object_save',
			static function () use ( &$saves ): void {
				++$saves;
			},
		);

		$_POST = array(
			self::NONCE_NAME => $this->nonce(),
			self::BOX_ID     => array( 'first' => 'A', 'second' => 'B' ),
		);
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		self::assertSame( 'A', $store->get( $this->order_id, 'first' ) );
		self::assertSame( 'B', $store->get( $this->order_id, 'second' ) );
		// Both fields persist in a single order write, not one per field.
		self::assertSame( 1, $saves );
	}

	public function test_a_no_op_save_does_not_persist_the_order(): void {
		$store = new OrderFieldStore();
		$store->register_meta_box( $this->box() );

		$saves = 0;
		\add_action(
			'woocommerce_after_order_object_save',
			static function () use ( &$saves ): void {
				++$saves;
			},
		);

		// The checkbox is absent (unchecked) and was never stored, so the revoke is a no-op — no order write.
		$_POST = array( self::NONCE_NAME => $this->nonce() );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );

		self::assertSame( 0, $saves );
	}

	public function test_a_duplicate_field_id_in_a_box_is_rejected(): void {
		$box = new ObjectMetaBox(
			id: self::BOX_ID,
			title: 'Dup',
			screen: 'shop_order',
			context: 'side',
			priority: 'default',
			fields_provider: static fn ( int $object_id ): array => array(
				new SettingsField( id: 'flag', type: 'checkbox', label: 'A' ),
				new SettingsField( id: 'flag', type: 'checkbox', label: 'B' ),
			),
		);
		$store = new OrderFieldStore();
		$store->register_meta_box( $box );

		$_POST = array( self::NONCE_NAME => $this->nonce(), self::BOX_ID => array( 'flag' => '1' ) );

		$this->expectException( DuplicateSettingsFieldException::class );
		\do_action( 'woocommerce_process_shop_order_meta', $this->order_id );
	}

	public function test_register_meta_box_rejects_a_non_order_screen(): void {
		$box = new ObjectMetaBox(
			id: self::BOX_ID,
			title: 'Box',
			screen: 'post',
			context: 'side',
			priority: 'default',
			fields_provider: static fn ( int $object_id ): array => array(),
		);

		$this->expectException( InvalidObjectMetaBoxException::class );

		( new OrderFieldStore() )->register_meta_box( $box );
	}

	public function test_a_meta_box_title_is_escaped_before_registration(): void {
		$screen = OrderUtil::custom_orders_table_usage_is_enabled() ? 'woocommerce_page_wc-orders' : 'shop_order';
		\set_current_screen( $screen );

		$box = new ObjectMetaBox(
			id: self::BOX_ID,
			title: '<script>alert(1)</script>',
			screen: 'shop_order',
			context: 'side',
			priority: 'default',
			fields_provider: static fn ( int $object_id ): array => array(),
		);
		( new OrderFieldStore() )->register_meta_box( $box );
		\do_action( "add_meta_boxes_$screen" );

		// WordPress echoes the stored title raw in do_meta_boxes(), so the store hands it pre-escaped.
		$title = (string) ( ( (array) ( $this->boxes_on( $screen )[ self::BOX_ID ] ?? array() ) )['title'] ?? '' );
		self::assertStringNotContainsString( '<script>', $title );
		self::assertStringContainsString( '&lt;script&gt;', $title );
	}

	private function nonce(): string {
		return \wp_create_nonce( self::NONCE_ACTION . '_' . $this->order_id );
	}

	private function box(): ObjectMetaBox {
		return new ObjectMetaBox(
			id: self::BOX_ID,
			title: 'Unlock',
			screen: 'shop_order',
			context: 'side',
			priority: 'default',
			fields_provider: static fn ( int $object_id ): array => array(
				new SettingsField( id: 'unlocked', type: 'checkbox', label: 'Unlocked' ),
			),
		);
	}
}
