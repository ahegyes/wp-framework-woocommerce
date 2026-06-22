<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\OrderData;

use Automattic\WooCommerce\Utilities\OrderUtil;
use DeepWebSolutions\Framework\Settings\ObjectField\Exceptions\InvalidObjectMetaBoxException;
use DeepWebSolutions\Framework\Settings\ObjectField\ObjectFieldStoreInterface;
use DeepWebSolutions\Framework\Settings\ObjectField\ValueObjects\ObjectMetaBox;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\DuplicateSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\FieldProcessor;
use DeepWebSolutions\Framework\Settings\Schema\FieldRenderer;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;

use function DeepWebSolutions\Framework\Settings\Schema\is_field_editable_by_current_user;

/**
 * WooCommerce-order object-field store: an order meta box plus per-object meta CRUD.
 *
 * Accepted scope is WooCommerce order meta, with a post-meta fallback for an object
 * id that is not an order.
 *
 * Registers a meta box on the order edit screen — the legacy post screen or the
 * HPOS orders page, resolved at registration so the box renders under either
 * storage mode — and reads/writes its fields as order meta through WC_Order, so
 * values follow the order whichever table backs it. CRUD also falls back to post
 * meta for an id that is not an order. On save, an absent or falsy submission
 * deletes the meta key (revoke semantics) rather than storing a falsy value.
 *
 * Requires WooCommerce active: the order-screen resolution, meta box, and order
 * CRUD all call WooCommerce APIs and fatal without it.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class OrderFieldStore implements ObjectFieldStoreInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * Legacy order edit screen (the shop_order post type), also the descriptor's order-screen token.
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
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  InvalidObjectMetaBoxException If the box targets a screen other than the WooCommerce order screen.
	 */
	public function register_meta_box( ObjectMetaBox $box ): void {
		foreach ( $this->resolve_screens( $box->screen ) as $screen ) {
			\add_action( "add_meta_boxes_$screen", fn () => $this->add_box( $box, $screen ) );
		}

		\add_action( 'woocommerce_process_shop_order_meta', fn ( int $object_id ) => $this->save_box( $box, $object_id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function get( int $object_id, string $meta_key, mixed $default_value = null ): mixed {
		$order = \wc_get_order( $object_id );
		if ( $order instanceof \WC_Abstract_Order ) {
			return $order->meta_exists( $meta_key ) ? $order->get_meta( $meta_key, true ) : $default_value;
		}

		return \metadata_exists( 'post', $object_id, $meta_key ) ? \get_post_meta( $object_id, $meta_key, true ) : $default_value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function set( int $object_id, string $meta_key, mixed $value ): void {
		$order = \wc_get_order( $object_id );
		if ( $order instanceof \WC_Abstract_Order ) {
			$order->update_meta_data( $meta_key, $value );
			$order->save();
			return;
		}

		// update_post_meta() runs the value through wp_unslash(); slash string/array values first so any
		// backslashes survive the round-trip (scalars need no slashing and pass through unchanged).
		$slashed = ( \is_string( $value ) || \is_array( $value ) ) ? \wp_slash( $value ) : $value;
		\update_post_meta( $object_id, $meta_key, $slashed );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function has( int $object_id, string $meta_key ): bool {
		$order = \wc_get_order( $object_id );
		if ( $order instanceof \WC_Abstract_Order ) {
			return $order->meta_exists( $meta_key );
		}

		return \metadata_exists( 'post', $object_id, $meta_key );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function delete( int $object_id, string $meta_key ): bool {
		$order = \wc_get_order( $object_id );
		if ( $order instanceof \WC_Abstract_Order ) {
			if ( ! $order->meta_exists( $meta_key ) ) {
				return false;
			}
			$order->delete_meta_data( $meta_key );
			$order->save();
			return true;
		}

		return \delete_post_meta( $object_id, $meta_key );
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves the descriptor's screen token to the live admin screens, accounting for HPOS. An HPOS
	 * order screen resolves to both the menu-visible page and the admin.php variant WooCommerce uses
	 * for a user who cannot view the WooCommerce menu, so the box registers wherever the user lands.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $screen Screen token from the descriptor.
	 *
	 * @throws  InvalidObjectMetaBoxException If the token is not the WooCommerce order screen.
	 *
	 * @return  list<string>
	 */
	protected function resolve_screens( string $screen ): array {
		if ( self::ORDER_SCREEN !== $screen ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			throw new InvalidObjectMetaBoxException( "OrderFieldStore registers meta boxes on the WooCommerce order screen ('shop_order') only; got '$screen'." );
		}
		if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return array( self::ORDER_SCREEN );
		}

		return array( self::HPOS_ORDER_SCREEN, self::HPOS_ORDER_SCREEN_RESTRICTED );
	}

	/**
	 * Registers the box with WordPress. Hooked to add_meta_boxes_{screen}.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ObjectMetaBox $box    Box to add.
	 * @param   string        $screen Resolved screen the box renders on.
	 */
	protected function add_box( ObjectMetaBox $box, string $screen ): void {
		$priority = match ( $box->priority ) {
			'core', 'high', 'low' => $box->priority,
			default               => 'default',
		};

		\add_meta_box(
			$box->id,
			\esc_html( $box->title ),
			fn ( mixed $object ) => $this->render_box( $box, $object ),
			$screen,
			$box->context,
			$priority,
		);
	}

	/**
	 * Renders the box: a bespoke renderer if the descriptor carries one, else the default field controls.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ObjectMetaBox $box    Box being rendered.
	 * @param   mixed         $object Screen object WordPress passes the callback (a WooCommerce order or WP_Post).
	 */
	protected function render_box( ObjectMetaBox $box, mixed $object ): void {
		$object_id = $this->object_id_of( $object );

		// Emitted for every box, bespoke renderer included: save_box() verifies this nonce before it runs
		// the bespoke save handler, so a bespoke renderer must not have to reimplement the convention.
		\wp_nonce_field( $this->nonce_action( $box, $object_id ), $this->nonce_name( $box ) );

		if ( null !== $box->render ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bespoke renderer owns its escaping.
			echo (string) ( $box->render )( $object_id );
			return;
		}

		foreach ( $this->fields_of( $box, $object_id ) as $field ) {
			if ( ! is_field_editable_by_current_user( $field ) ) {
				continue;
			}
			// Object fields are revoke-based: an absent meta renders as unset, NOT the field default, so a
			// value cleared via delete-on-falsy does not spring back to its default on the next render.
			$value = $this->get( $object_id, $field->meta_key ?? $field->id );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldRenderer returns markup already escaped at each interpolation point.
			echo $this->renderer->render( $field, $value, $box->id . '[' . $field->id . ']' );
		}
	}

	/**
	 * Persists the box's submitted fields. Hooked to woocommerce_process_shop_order_meta.
	 *
	 * Guards on nonce and capability, then either runs the descriptor's bespoke save handler or
	 * processes each field and writes its meta key — deleting the key on a falsy value so an
	 * unchecked control revokes the meta rather than storing a falsy value. WooCommerce skips
	 * autosaves before firing this hook, so no autosave guard is needed here.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ObjectMetaBox $box       Box being saved.
	 * @param   int           $object_id Order whose meta to write.
	 */
	protected function save_box( ObjectMetaBox $box, int $object_id ): void {
		$name = $this->nonce_name( $box );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce read here and verified on the next line.
		$nonce = isset( $_POST[ $name ] ) ? \sanitize_text_field( \wp_unslash( $_POST[ $name ] ) ) : '';
		if ( false === \wp_verify_nonce( $nonce, $this->nonce_action( $box, $object_id ) ) ) {
			return;
		}
		if ( ! \current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		if ( null !== $box->save ) {
			( $box->save )( $object_id );
			return;
		}

		$order = \wc_get_order( $object_id );
		if ( ! $order instanceof \WC_Abstract_Order ) {
			return;
		}

		// The default controls namespace their names under the box id (box_id[field_id]), so read only that
		// subarray — avoiding collisions with WooCommerce's own order fields and other meta boxes on the screen.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$submitted = \wp_unslash( $_POST[ $box->id ] ?? array() );
		$submitted = \is_array( $submitted ) ? $submitted : array();

		// Batch every field's mutation onto the order and persist once — and only when at least one field
		// queued a write — so a K-field box is one order write (or none for a no-op submit) rather than K.
		$changed = false;
		foreach ( $this->fields_of( $box, $object_id ) as $field ) {
			if ( ! is_field_editable_by_current_user( $field ) ) {
				continue;
			}

			$meta_key = $field->meta_key ?? $field->id;
			$value    = $this->processor->process( $field, $submitted );
			// An empty result — a cleared control or a rejected/invalid submission — revokes the meta key
			// (delete-on-falsy), the object-field counterpart of the settings backend's coerce-to-false.
			// A revoke of an already-unset key is a no-op, so it queues no write.
			if ( $this->should_store( $value ) ) {
				$order->update_meta_data( $meta_key, $value );
				$changed = true;
			} elseif ( $order->meta_exists( $meta_key ) ) {
				$order->delete_meta_data( $meta_key );
				$changed = true;
			}
		}

		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Builds the box's fields for an object, rejecting a duplicate field id within the box.
	 *
	 * The ids are the form keys and the processor reads each field's submission by id, so a duplicate
	 * would render colliding controls and route one submitted value into several meta keys.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ObjectMetaBox $box       Box whose fields to build.
	 * @param   int           $object_id Object the fields are built for.
	 *
	 * @throws  DuplicateSettingsFieldException If two fields in the box share an id.
	 *
	 * @return  list<SettingsField>
	 */
	protected function fields_of( ObjectMetaBox $box, int $object_id ): array {
		/** @var list<SettingsField> $fields */
		$fields = ( $box->fields_provider )( $object_id );

		$seen = array();
		foreach ( $fields as $field ) {
			if ( \array_key_exists( $field->id, $seen ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
				throw new DuplicateSettingsFieldException( "Duplicate object field id in meta box '$box->id': '$field->id'" );
			}
			$seen[ $field->id ] = true;
		}

		return $fields;
	}

	/**
	 * Extracts the integer object id from the screen object WordPress hands the render callback.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $object Screen object (a WooCommerce order or WP_Post), or anything else.
	 *
	 * @return  int
	 */
	protected function object_id_of( mixed $object ): int {
		if ( $object instanceof \WC_Abstract_Order ) {
			return $object->get_id();
		}
		if ( $object instanceof \WP_Post ) {
			return $object->ID;
		}

		return 0;
	}

	/**
	 * Whether a processed value should be stored. An empty value — an unchecked control (false), a
	 * cleared field ('') or an empty multi-select (array()) — is not stored; its meta key is deleted
	 * instead (revoke semantics). A meaningful zero (0, '0') is a value and is preserved.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $value Processed field value.
	 *
	 * @return  bool
	 */
	protected function should_store( mixed $value ): bool {
		return false !== $value && '' !== $value && array() !== $value;
	}

	/**
	 * The nonce action for a box's save on a given object. Object-scoped so a token minted for one
	 * order cannot authorize a write to another.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ObjectMetaBox $box       Box the nonce guards.
	 * @param   int           $object_id Object the nonce is bound to.
	 *
	 * @return  string
	 */
	protected function nonce_action( ObjectMetaBox $box, int $object_id ): string {
		return 'dws_object_field_' . $box->id . '_' . $object_id;
	}

	/**
	 * The nonce field name for a box's save.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ObjectMetaBox $box Box the nonce guards.
	 *
	 * @return  string
	 */
	protected function nonce_name( ObjectMetaBox $box ): string {
		return 'dws_object_field_' . $box->id . '_nonce';
	}

	// endregion
}
