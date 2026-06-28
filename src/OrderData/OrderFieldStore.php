<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\OrderData;

use Automattic\WooCommerce\Utilities\OrderUtil;
use DeepWebSolutions\Framework\Settings\MetaField\ObjectFieldForm;
use DeepWebSolutions\Framework\Settings\MetaField\ValueObjects\FieldGroup;
use DeepWebSolutions\Framework\Settings\MetaField\ValueObjects\MetaBoxPlacement;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\DuplicateSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\Field\FieldProcessor;
use DeepWebSolutions\Framework\Settings\Schema\Field\FieldRenderer;
use DeepWebSolutions\Framework\WooCommerce\OrderData\Exceptions\UnsupportedOrderScreenException;

/**
 * Registers a field group as a WooCommerce-order meta box.
 *
 * Resolves the order edit screen at registration — the legacy post screen or, under HPOS, the orders
 * page (and the admin.php variant WooCommerce uses for a user who cannot see the WooCommerce menu) — so
 * the box renders under either storage mode, and delegates rendering and saving to the shared
 * object-field form engine backed by an order-meta repository. Both render and save are gated on the
 * configured box capability, defaulting to WooCommerce's own order-edit check: the order's edit
 * capability, or manage_woocommerce.
 *
 * Requires WooCommerce active: the order-screen resolution, meta box, and order CRUD all call
 * WooCommerce APIs and fatal without it.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class OrderFieldStore {
	// region FIELDS AND CONSTANTS

	/**
	 * Legacy order edit screen (the shop_order post type), also the placement's order-screen token.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	protected const ORDER_SCREEN = 'shop_order';

	/**
	 * HPOS order edit screen, where the box must register when custom order tables are authoritative.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	protected const HPOS_ORDER_SCREEN = 'woocommerce_page_wc-orders';

	/**
	 * HPOS order edit screen for a user who cannot view the WooCommerce menu (the page falls under admin.php).
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	protected const HPOS_ORDER_SCREEN_RESTRICTED = 'admin_page_wc-orders';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FieldRenderer  $renderer  Renderer for the box's field controls.
	 * @param   FieldProcessor $processor Processor for sanitizing submitted values.
	 */
	public function __construct(
		protected FieldRenderer $renderer = new FieldRenderer(),
		protected FieldProcessor $processor = new FieldProcessor(),
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers the group's order meta box and save hook, on each screen the order edit page resolves to.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FieldGroup       $group     Group to register.
	 * @param   MetaBoxPlacement $placement Placement; its screen must be the WooCommerce order screen.
	 *
	 * @throws  UnsupportedOrderScreenException If the placement names a screen other than the order screen.
	 */
	public function register( FieldGroup $group, MetaBoxPlacement $placement ): void {
		$form = new ObjectFieldForm( new OrderMetaRepository(), $this->renderer, $this->processor );

		foreach ( $this->resolve_screens( $placement->screen ) as $screen ) {
			\add_action( "add_meta_boxes_$screen", fn ( mixed $wc_object ) => $this->add_box( $group, $placement, $screen, $form, $wc_object ) );
		}

		\add_action( 'woocommerce_process_shop_order_meta', fn ( int $object_id ) => $this->save_box( $group, $placement, $form, $object_id ) );
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves the placement's screen token to the live admin screens, accounting for HPOS. An HPOS order
	 * screen resolves to both the menu-visible page and the admin.php variant WooCommerce uses for a user
	 * who cannot view the WooCommerce menu, so the box registers wherever the user lands.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $screen Screen token from the placement.
	 *
	 * @throws  UnsupportedOrderScreenException If the token is not the WooCommerce order screen.
	 *
	 * @return  list<string>
	 */
	protected function resolve_screens( string $screen ): array {
		if ( self::ORDER_SCREEN !== $screen ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			throw new UnsupportedOrderScreenException( "OrderFieldStore registers meta boxes on the WooCommerce order screen ('shop_order') only; got '$screen'." );
		}
		if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return array( self::ORDER_SCREEN );
		}

		return array( self::HPOS_ORDER_SCREEN, self::HPOS_ORDER_SCREEN_RESTRICTED );
	}

	/**
	 * Registers the box with WordPress when the current user can edit the order. Hooked to
	 * add_meta_boxes_{screen}, which passes the order (or its post under the legacy screen).
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FieldGroup       $group     Group the box renders.
	 * @param   MetaBoxPlacement $placement Placement describing the box's context, priority, and capability.
	 * @param   string           $screen    Resolved screen the box renders on.
	 * @param   ObjectFieldForm  $form      Engine that renders the group's fields.
	 * @param   mixed            $wc_object Screen object WordPress passes the callback (a WooCommerce order or WP_Post).
	 */
	protected function add_box( FieldGroup $group, MetaBoxPlacement $placement, string $screen, ObjectFieldForm $form, mixed $wc_object ): void {
		$order = \wc_get_order( $this->object_id_of( $wc_object ) );
		if ( ! $order instanceof \WC_Abstract_Order || ! $this->can_edit_order( $placement, $order ) ) {
			return;
		}

		$priority = match ( $placement->priority ) {
			'core', 'high', 'low' => $placement->priority,
			default               => 'default',
		};

		\add_meta_box(
			$group->id,
			\esc_html( $group->title ),
			fn ( mixed $screen_object ) => $form->render( $group, $this->object_id_of( $screen_object ) ),
			$screen,
			$placement->context,
			$priority,
		);
	}

	/**
	 * Saves the box when the current user can edit the order. Hooked to woocommerce_process_shop_order_meta.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FieldGroup       $group     Group to save.
	 * @param   MetaBoxPlacement $placement Placement whose capability gates the save.
	 * @param   ObjectFieldForm  $form      Engine that processes and persists the group's fields.
	 * @param   int              $object_id Order whose meta to write.
	 *
	 * @throws  DuplicateSettingsFieldException If two of the group's fields share an id or storage key.
	 */
	protected function save_box( FieldGroup $group, MetaBoxPlacement $placement, ObjectFieldForm $form, int $object_id ): void {
		$order = \wc_get_order( $object_id );
		if ( ! $order instanceof \WC_Abstract_Order || ! $this->can_edit_order( $placement, $order ) ) {
			return;
		}

		$form->save( $group, $object_id );
	}

	/**
	 * Whether the current user may edit the order: the placement's capability if it sets one, otherwise
	 * WooCommerce's own order-edit gate — the order type's edit capability for this order, or the
	 * shop-manager capability.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   MetaBoxPlacement     $placement Placement whose capability override, if any, takes precedence.
	 * @param   \WC_Abstract_Order   $order     Order being edited.
	 *
	 * @return  bool
	 */
	protected function can_edit_order( MetaBoxPlacement $placement, \WC_Abstract_Order $order ): bool {
		if ( null !== $placement->capability ) {
			return \current_user_can( $placement->capability, $order->get_id() );
		}

		$post_type = \get_post_type_object( $order->get_type() );

		return ( null !== $post_type && \current_user_can( (string) $post_type->cap->edit_post, $order->get_id() ) )
			|| \current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- manage_woocommerce is a core WooCommerce capability.
	}

	/**
	 * Extracts the integer object id from the screen object WordPress hands a meta-box callback.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $wc_object Screen object (a WooCommerce order or WP_Post), or anything else.
	 *
	 * @return  int
	 */
	protected function object_id_of( mixed $wc_object ): int {
		if ( $wc_object instanceof \WC_Abstract_Order ) {
			return $wc_object->get_id();
		}
		if ( $wc_object instanceof \WP_Post ) {
			return $wc_object->ID;
		}

		return 0;
	}

	// endregion
}
