<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\EditScreen\Hosts;

use Automattic\WooCommerce\Utilities\OrderUtil;
use DeepWebSolutions\Framework\Settings\EditScreen\Hosts\PostMetaBox;
use DeepWebSolutions\Framework\Settings\EditScreen\ObjectForm;
use DeepWebSolutions\Framework\Settings\EditScreen\ObjectFormHostInterface;
use DeepWebSolutions\Framework\Settings\EditScreen\ObjectStoreInterface;
use DeepWebSolutions\Framework\WooCommerce\EditScreen\Stores\OrderMetaStore;

\defined( 'ABSPATH' ) || exit;

/**
 * Hosts an object form in a meta box on the edit screens of orders of the given types, wherever WooCommerce stores orders, with the field rows stacked so that they fit any of the box's contexts.
 *
 * A row is a block, so a field's render callable may return block content.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-import-type Row from ObjectFormHostInterface
 */
final class OrderMetaBox implements ObjectFormHostInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The form the box hosts.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     ObjectForm
	 */
	protected ObjectForm $form;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @phpstan-param 'high'|'core'|'default'|'low' $priority
	 *
	 * @param   list<string> $order_types Optional. The order types whose edit screens show the box. Default is shop_order.
	 * @param   string       $context     Optional. The screen area the box shows in, as add_meta_box() takes it. Default is advanced.
	 * @param   string       $priority    Optional. The box's priority within its context, as add_meta_box() takes it. Default is default.
	 */
	public function __construct(
		protected array $order_types = array( 'shop_order' ),
		protected string $context = 'advanced',
		protected string $priority = 'default'
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function register_hooks( ObjectForm $form ): void {
		$this->form = $form;
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_meta_box' ), 35 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function get_object_capability(): string {
		return 'edit_post';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function get_default_store(): ObjectStoreInterface {
		return new OrderMetaStore();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function render_rows( string $title, array $rows ): void {
		( new PostMetaBox( array() ) )->render_rows( $title, $rows );
	}

	// endregion

	// region HOOKS

	/**
	 * Adds the box to the edit screen of an order of the box's types when the form shows for it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $screen_id The screen's id, which is the post type when orders are stored as posts.
	 * @param   mixed  $order     The edited object, which on an order screen is a WC_Order in WooCommerce's order tables and a WP_Post in the posts table.
	 *
	 * @return  void
	 */
	public function add_meta_box( string $screen_id, mixed $order ): void {
		// WooCommerce's order screen ids depend on the storage, the order type and whether the user sees its menu.
		$order_id = OrderUtil::get_post_or_order_id( $order );
		if ( 0 === $order_id || ! \in_array( $screen_id, \array_map( 'wc_get_page_screen_id', $this->order_types ), true ) || ! $this->form->can_render( $order_id ) ) {
			return;
		}

		// WordPress prints a box's title as markup, so the title goes in as the form defines it.
		add_meta_box( $this->form->get_id(), $this->form->get_title( $order_id ), array( $this, 'render_meta_box' ), $screen_id, $this->context, $this->priority );
	}

	/**
	 * Prints the form inside the box.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $order The edited order, a WC_Order in WooCommerce's order tables and a WP_Post in the posts table.
	 *
	 * @return  void
	 */
	public function render_meta_box( mixed $order ): void {
		$this->form->render( OrderUtil::get_post_or_order_id( $order ) );
	}

	/**
	 * Saves the form when WooCommerce saves an order from its edit screen, before WooCommerce saves the order's data, so that a status change and its emails see the form's values.
	 *
	 * On the order tables WooCommerce fires the hook for every order type, and in the posts table only for the types wc_get_order_types( 'order-meta-boxes' ) lists; only the box's own screens print the form's nonce.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $order_id The saved order's id.
	 *
	 * @return  void
	 */
	public function save_meta_box( int $order_id ): void {
		$this->form->save( $order_id );
	}

	// endregion
}
