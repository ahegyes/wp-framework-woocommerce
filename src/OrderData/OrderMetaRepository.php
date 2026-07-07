<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\OrderData;

use DeepWebSolutions\Framework\Storage\ObjectMeta\MetadataRepository;
use DeepWebSolutions\Framework\Storage\ObjectMeta\MetaType;
use DeepWebSolutions\Framework\Storage\ObjectMeta\ObjectMetaRepositoryInterface;

/**
 * Object-meta repository over WooCommerce orders.
 *
 * Reads and writes order meta through WC_Order, so a value follows the order whichever table backs it
 * under HPOS, and delegates an object id that is not an order to the composed fallback repository. The
 * batch apply() persists an order's queued writes and deletes in a single save() — and only when
 * something changed, so a no-op submission writes nothing. CRUD keys off meta_exists() rather than
 * value truthiness.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class OrderMetaRepository implements ObjectMetaRepositoryInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ObjectMetaRepositoryInterface $fallback Repository handling an object id that is not an order.
	 */
	public function __construct(
		protected ObjectMetaRepositoryInterface $fallback = new MetadataRepository( MetaType::Post ),
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
	public function get( int $object_id, string $meta_key, mixed $default_value = null ): mixed {
		$order = \wc_get_order( $object_id );
		if ( $order instanceof \WC_Abstract_Order ) {
			return $order->meta_exists( $meta_key ) ? $order->get_meta( $meta_key, true ) : $default_value;
		}

		return $this->fallback->get( $object_id, $meta_key, $default_value );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function set( int $object_id, string $meta_key, mixed $value ): void {
		$order = \wc_get_order( $object_id );
		if ( $order instanceof \WC_Abstract_Order ) {
			$order->update_meta_data( $meta_key, $value );
			$order->save();
			return;
		}

		$this->fallback->set( $object_id, $meta_key, $value );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function has( int $object_id, string $meta_key ): bool {
		$order = \wc_get_order( $object_id );
		if ( $order instanceof \WC_Abstract_Order ) {
			return $order->meta_exists( $meta_key );
		}

		return $this->fallback->has( $object_id, $meta_key );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
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

		return $this->fallback->delete( $object_id, $meta_key );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function apply( int $object_id, array $sets, array $deletes ): void {
		$order = \wc_get_order( $object_id );
		if ( $order instanceof \WC_Abstract_Order ) {
			// Batch every mutation onto the order and persist once — and only when at least one field queued
			// a write — so a K-field group is one order write (or none for a no-op submit) rather than K.
			$changed = false;
			foreach ( $sets as $meta_key => $value ) {
				$order->update_meta_data( (string) $meta_key, $value );
				$changed = true;
			}
			foreach ( $deletes as $meta_key ) {
				if ( $order->meta_exists( $meta_key ) ) {
					$order->delete_meta_data( $meta_key );
					$changed = true;
				}
			}
			if ( $changed ) {
				$order->save();
			}
			return;
		}

		$this->fallback->apply( $object_id, $sets, $deletes );
	}

	// endregion
}
