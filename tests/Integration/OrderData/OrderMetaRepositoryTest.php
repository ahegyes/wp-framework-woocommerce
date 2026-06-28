<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration\OrderData;

use DeepWebSolutions\Framework\Settings\MetaField\ObjectMetaRepositoryInterface;
use DeepWebSolutions\Framework\WooCommerce\OrderData\OrderMetaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( OrderMetaRepository::class )]
final class OrderMetaRepositoryTest extends TestCase {
	private const ISOLATED_HOOKS = array( 'woocommerce_after_order_object_save' );

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

		global $wp_filter;
		foreach ( self::ISOLATED_HOOKS as $hook ) {
			$this->saved_hooks[ $hook ] = $wp_filter[ $hook ] ?? null;
			unset( $wp_filter[ $hook ] );
		}

		$order = \wc_create_order();
		\assert( $order instanceof \WC_Order );
		$this->order_id = $order->get_id();
	}

	protected function tearDown(): void {
		$order = \wc_get_order( $this->order_id );
		if ( $order instanceof \WC_Order ) {
			$order->delete( true );
		}

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

	public function test_it_is_an_object_meta_repository(): void {
		self::assertInstanceOf( ObjectMetaRepositoryInterface::class, new OrderMetaRepository() );
	}

	public function test_set_and_get_round_trip_on_an_order(): void {
		$repo = new OrderMetaRepository();

		$repo->set( $this->order_id, '_dws_unlocked', 'yes' );

		self::assertSame( 'yes', $repo->get( $this->order_id, '_dws_unlocked' ) );
	}

	public function test_an_order_meta_value_preserves_backslashes(): void {
		$repo = new OrderMetaRepository();

		// WooCommerce's order data store handles slashing internally, so the order path stores the value as
		// given — unlike the post-meta fallback, which compensates for update_post_meta()'s unslash.
		$repo->set( $this->order_id, '_dws_path', 'C:\\Users\\dev\\file.txt' );

		self::assertSame( 'C:\\Users\\dev\\file.txt', $repo->get( $this->order_id, '_dws_path' ) );
	}

	public function test_get_returns_the_default_when_nothing_is_stored(): void {
		$repo = new OrderMetaRepository();

		self::assertSame( 'fallback', $repo->get( $this->order_id, '_dws_absent', 'fallback' ) );
	}

	public function test_has_reports_presence_and_delete_removes_the_value(): void {
		$repo = new OrderMetaRepository();

		self::assertFalse( $repo->has( $this->order_id, '_dws_flag' ) );

		$repo->set( $this->order_id, '_dws_flag', '1' );
		self::assertTrue( $repo->has( $this->order_id, '_dws_flag' ) );

		self::assertTrue( $repo->delete( $this->order_id, '_dws_flag' ) );
		self::assertFalse( $repo->has( $this->order_id, '_dws_flag' ) );
		self::assertFalse( $repo->delete( $this->order_id, '_dws_flag' ) );
	}

	public function test_crud_falls_back_to_post_meta_for_a_non_order_id(): void {
		$post_id = \wp_insert_post( array( 'post_title' => 'Probe', 'post_status' => 'publish' ) );
		\assert( \is_int( $post_id ) );
		self::assertFalse( \wc_get_order( $post_id ) );
		$repo = new OrderMetaRepository();

		$repo->set( $post_id, '_dws_post_key', 'value' );

		self::assertSame( 'value', \get_post_meta( $post_id, '_dws_post_key', true ) );
		self::assertSame( 'value', $repo->get( $post_id, '_dws_post_key' ) );
		self::assertTrue( $repo->has( $post_id, '_dws_post_key' ) );
		self::assertTrue( $repo->delete( $post_id, '_dws_post_key' ) );
		self::assertFalse( $repo->has( $post_id, '_dws_post_key' ) );

		\wp_delete_post( $post_id, true );
	}

	public function test_the_post_meta_fallback_preserves_backslashes(): void {
		$post_id = \wp_insert_post( array( 'post_title' => 'Probe', 'post_status' => 'publish' ) );
		\assert( \is_int( $post_id ) );
		self::assertFalse( \wc_get_order( $post_id ) );
		$repo = new OrderMetaRepository();

		$repo->set( $post_id, '_dws_path', 'C:\\Users\\dev\\file.txt' );

		self::assertSame( 'C:\\Users\\dev\\file.txt', $repo->get( $post_id, '_dws_path' ) );

		\wp_delete_post( $post_id, true );
	}

	public function test_the_post_meta_fallback_preserves_a_backslash_in_the_meta_key(): void {
		$post_id = \wp_insert_post( array( 'post_title' => 'Probe', 'post_status' => 'publish' ) );
		\assert( \is_int( $post_id ) );
		self::assertFalse( \wc_get_order( $post_id ) );
		$repo = new OrderMetaRepository();

		// update_post_meta()/delete_post_meta() unslash the key while get()/has() look it up raw; without the
		// compensating slash a key with a backslash would be written under a different key than it is read.
		$repo->set( $post_id, 'dws\\odd\\key', 'v' );

		self::assertTrue( $repo->has( $post_id, 'dws\\odd\\key' ) );
		self::assertSame( 'v', $repo->get( $post_id, 'dws\\odd\\key' ) );
		self::assertTrue( $repo->delete( $post_id, 'dws\\odd\\key' ) );
		self::assertFalse( $repo->has( $post_id, 'dws\\odd\\key' ) );

		\wp_delete_post( $post_id, true );
	}

	public function test_apply_persists_sets_and_removes_deletes_in_one_save(): void {
		$repo = new OrderMetaRepository();
		$repo->set( $this->order_id, 'old', 'gone' );

		$saves = 0;
		\add_action(
			'woocommerce_after_order_object_save',
			static function () use ( &$saves ): void {
				++$saves;
			},
		);

		$repo->apply( $this->order_id, array( 'a' => 'A', 'b' => 'B' ), array( 'old' ) );

		self::assertSame( 'A', $repo->get( $this->order_id, 'a' ) );
		self::assertSame( 'B', $repo->get( $this->order_id, 'b' ) );
		self::assertFalse( $repo->has( $this->order_id, 'old' ) );
		// The whole batch persists in a single order write.
		self::assertSame( 1, $saves );
	}

	public function test_apply_does_not_save_on_a_no_op_batch(): void {
		$repo = new OrderMetaRepository();

		$saves = 0;
		\add_action(
			'woocommerce_after_order_object_save',
			static function () use ( &$saves ): void {
				++$saves;
			},
		);

		// Deleting keys that were never stored changes nothing, so the order must not be written.
		$repo->apply( $this->order_id, array(), array( 'never_set_a', 'never_set_b' ) );

		self::assertSame( 0, $saves );
	}

	public function test_apply_falls_back_to_post_meta_for_a_non_order_id(): void {
		$post_id = \wp_insert_post( array( 'post_title' => 'Probe', 'post_status' => 'publish' ) );
		\assert( \is_int( $post_id ) );
		self::assertFalse( \wc_get_order( $post_id ) );
		$repo = new OrderMetaRepository();

		$repo->apply( $post_id, array( '_dws_x' => 'X' ), array() );

		self::assertSame( 'X', \get_post_meta( $post_id, '_dws_x', true ) );

		\wp_delete_post( $post_id, true );
	}
}
