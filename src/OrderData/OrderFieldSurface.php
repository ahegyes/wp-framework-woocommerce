<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\OrderData;

use Automattic\WooCommerce\Utilities\OrderUtil;
use DeepWebSolutions\Framework\Settings\MetaField\ObjectFieldForm;
use DeepWebSolutions\Framework\Settings\MetaField\ValueObjects\FieldGroup;
use DeepWebSolutions\Framework\Settings\MetaField\ValueObjects\MetaBoxPlacement;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\DuplicateSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\InvalidSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\Field\FieldProcessor;
use DeepWebSolutions\Framework\Settings\Schema\Field\FieldRenderer;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Storage\ObjectMeta\ObjectMetaRepositoryInterface;
use DeepWebSolutions\Framework\WooCommerce\OrderData\Exceptions\UnsupportedOrderScreenException;

use function DeepWebSolutions\Framework\Settings\Schema\field_label_html;

/**
 * Surface that mounts a field group onto the WooCommerce order edit screen as a meta box and stores its fields as order meta.
 *
 * Resolves the order edit screen at registration — the legacy post screen or, under HPOS, the orders
 * page (and the admin.php variant WooCommerce uses for a user who cannot see the WooCommerce menu) — so
 * the box renders under either storage mode, and delegates rendering and saving to the shared
 * object-field form engine backed by an order-meta repository. Both render and save are gated on the
 * configured box capability, defaulting to WooCommerce's own order-edit check: the order's edit
 * capability, or manage_woocommerce.
 *
 * Beyond registration, the surface exposes field-addressed CRUD over the same storage keys and value
 * semantics the form path applies — get/set/has/delete by group and field id — plus meta_keys() for the
 * consumer's uninstall cleanup. Object fields are revoke-based, so reads never fall back to the field's
 * declared default.
 *
 * Requires WooCommerce active: the order-screen resolution, meta box, and order CRUD all call
 * WooCommerce APIs and fatal without it.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class OrderFieldSurface {
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

	/**
	 * Registered groups and placements keyed by group id.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, array{group: FieldGroup, placement: MetaBoxPlacement}>
	 */
	protected array $registrations = array();

	/**
	 * Shared form engine that renders and saves the registered groups and resolves their storage keys.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     ObjectFieldForm
	 */
	protected ObjectFieldForm $form;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FieldRenderer                 $renderer   Renderer for the box's field controls.
	 * @param   ?FieldProcessor               $processor  Processor for sanitizing submitted values; null applies one carrying the per-type default sanitizers.
	 * @param   ObjectMetaRepositoryInterface $repository Repository the groups' fields read from and write to.
	 */
	public function __construct(
		FieldRenderer $renderer = new FieldRenderer(),
		?FieldProcessor $processor = null,
		protected ObjectMetaRepositoryInterface $repository = new OrderMetaRepository(),
	) {
		$this->form = new ObjectFieldForm( $this->repository, $renderer, $processor );
	}

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
		$screens = $this->resolve_screens( $placement->screen );

		$this->registrations[ $group->id ] = array(
			'group'     => $group,
			'placement' => $placement,
		);

		foreach ( $screens as $screen ) {
			\add_action( "add_meta_boxes_$screen", array( $this, 'add_boxes' ) );
		}

		\add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_boxes' ) );
	}

	/**
	 * Retrieves a field's stored value for an order — {@see ObjectFieldForm::get()} for the read semantics.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FieldGroup $group         Group that declares the field.
	 * @param   int        $order_id      Order to read.
	 * @param   string     $field_id      Field whose value to read.
	 * @param   mixed      $default_value Value to return when nothing is stored.
	 *
	 * @throws  DuplicateSettingsFieldException If two of the group's fields share an id or storage key.
	 * @throws  InvalidSettingsFieldException If the group declares no field with the given id.
	 *
	 * @return  mixed
	 */
	public function get( FieldGroup $group, int $order_id, string $field_id, mixed $default_value = null ): mixed {
		return $this->form->get( $group, $order_id, $field_id, $default_value );
	}

	/**
	 * Persists a field's value for an order — {@see ObjectFieldForm::set()} for the store-or-revoke semantics.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FieldGroup $group    Group that declares the field.
	 * @param   int        $order_id Order to write.
	 * @param   string     $field_id Field whose value to write.
	 * @param   mixed      $value    Value to persist.
	 *
	 * @throws  DuplicateSettingsFieldException If two of the group's fields share an id or storage key.
	 * @throws  InvalidSettingsFieldException If the group declares no field with the given id.
	 */
	public function set( FieldGroup $group, int $order_id, string $field_id, mixed $value ): void {
		$this->form->set( $group, $order_id, $field_id, $value );
	}

	/**
	 * Whether a real value is stored for a field on an order — {@see ObjectFieldForm::has()}.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FieldGroup $group    Group that declares the field.
	 * @param   int        $order_id Order to check.
	 * @param   string     $field_id Field to check.
	 *
	 * @throws  DuplicateSettingsFieldException If two of the group's fields share an id or storage key.
	 * @throws  InvalidSettingsFieldException If the group declares no field with the given id.
	 *
	 * @return  bool
	 */
	public function has( FieldGroup $group, int $order_id, string $field_id ): bool {
		return $this->form->has( $group, $order_id, $field_id );
	}

	/**
	 * Deletes a field's stored value from an order — {@see ObjectFieldForm::delete()}.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FieldGroup $group    Group that declares the field.
	 * @param   int        $order_id Order to clear.
	 * @param   string     $field_id Field to clear.
	 *
	 * @throws  DuplicateSettingsFieldException If two of the group's fields share an id or storage key.
	 * @throws  InvalidSettingsFieldException If the group declares no field with the given id.
	 *
	 * @return  bool True if a value was deleted, false if none existed.
	 */
	public function delete( FieldGroup $group, int $order_id, string $field_id ): bool {
		return $this->form->delete( $group, $order_id, $field_id );
	}

	/**
	 * Returns every storage key a group's fields resolve to, for the consumer's uninstall cleanup. The
	 * fields are built through the group's provider for object id 0 — the objectless evaluation — so a
	 * provider that varies its fields per object is enumerated by the consumer per object instead.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FieldGroup $group Group whose storage keys to enumerate.
	 *
	 * @throws  DuplicateSettingsFieldException If two of the group's fields share an id or storage key.
	 *
	 * @return  list<string>
	 */
	public function meta_keys( FieldGroup $group ): array {
		return $this->form->meta_keys( $group );
	}

	// endregion

	// region HOOKS

	/**
	 * Adds every registered box on the order screen whose action fired, recovering the screen from the
	 * running action's name. Hooked to add_meta_boxes_{screen} for each resolved order screen.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $wc_object Screen object WordPress passes the callback (a WooCommerce order or WP_Post).
	 */
	public function add_boxes( mixed $wc_object ): void {
		$action = \current_action();
		if ( false === $action ) {
			return;
		}

		$screen = \substr( $action, \strlen( 'add_meta_boxes_' ) );
		foreach ( $this->registrations as $registration ) {
			$this->add_box( $registration['group'], $registration['placement'], $screen, $wc_object );
		}
	}

	/**
	 * Saves every registered box for an order. Hooked to woocommerce_process_shop_order_meta.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $object_id Order whose meta to write.
	 *
	 * @throws  DuplicateSettingsFieldException If two of a group's fields share an id or storage key.
	 */
	public function save_boxes( int $object_id ): void {
		foreach ( $this->registrations as $registration ) {
			$this->save_box( $registration['group'], $registration['placement'], $object_id );
		}
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
			throw new UnsupportedOrderScreenException( "OrderFieldSurface registers meta boxes on the WooCommerce order screen ('shop_order') only; got '$screen'." );
		}
		if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return array( self::ORDER_SCREEN );
		}

		return array( self::HPOS_ORDER_SCREEN, self::HPOS_ORDER_SCREEN_RESTRICTED );
	}

	/**
	 * Registers the box with WordPress when the current user can edit the order.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FieldGroup       $group     Group the box renders.
	 * @param   MetaBoxPlacement $placement Placement describing the box's context, priority, and capability.
	 * @param   string           $screen    Resolved screen the box renders on.
	 * @param   mixed            $wc_object Screen object WordPress passes the callback (a WooCommerce order or WP_Post).
	 */
	protected function add_box( FieldGroup $group, MetaBoxPlacement $placement, string $screen, mixed $wc_object ): void {
		$order = \wc_get_order( $this->object_id_of( $wc_object ) );
		if ( ! $order instanceof \WC_Abstract_Order || ! $this->can_edit_order( $placement, $order ) ) {
			return;
		}

		/** @var 'high'|'core'|'default'|'low' $priority */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var type assertion; the placement constructor validates the closed set.
		$priority = $placement->priority;

		\add_meta_box(
			$group->id,
			\esc_html( $group->title ),
			fn ( mixed $screen_object ) => $this->form->render( $group, $this->object_id_of( $screen_object ), $this->box_row() ),
			$screen,
			$placement->context,
			$priority,
		);
	}

	/**
	 * The row closure wrapping each control in a meta-box row, its label bound to the control's DOM id.
	 * A div, not a paragraph: a radio fieldset or the description paragraph inside a p would be reparsed
	 * as invalid HTML.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  \Closure
	 */
	protected function box_row(): \Closure {
		return static fn ( SettingsField $field, string $control, string $control_id ): string =>
			'<div class="dws-meta-box-field">' . field_label_html( $field, $control_id ) . '<br />' . $control . '</div>';
	}

	/**
	 * Saves the box when the current user can edit the order.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FieldGroup       $group     Group to save.
	 * @param   MetaBoxPlacement $placement Placement whose capability gates the save.
	 * @param   int              $object_id Order whose meta to write.
	 *
	 * @throws  DuplicateSettingsFieldException If two of the group's fields share an id or storage key.
	 */
	protected function save_box( FieldGroup $group, MetaBoxPlacement $placement, int $object_id ): void {
		$order = \wc_get_order( $object_id );
		if ( ! $order instanceof \WC_Abstract_Order || ! $this->can_edit_order( $placement, $order ) ) {
			return;
		}

		$this->form->save( $group, $object_id );
	}

	/**
	 * Whether the current user may edit the order: the placement's capability if it sets one, otherwise
	 * WooCommerce's own order-edit gate — the order type's edit capability for this order, or the
	 * shop-manager capability.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   MetaBoxPlacement   $placement Placement whose capability override, if any, takes precedence.
	 * @param   \WC_Abstract_Order $order     Order being edited.
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
