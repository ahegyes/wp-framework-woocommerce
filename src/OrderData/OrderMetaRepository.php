<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\OrderData;

use DeepWebSolutions\Framework\Storage\ObjectMeta\ObjectMetaRepositoryInterface;

/**
 * Object-meta repository over WooCommerce orders.
 *
 * Reads and writes order meta through WC_Order, so a value follows the order whichever table backs it
 * under HPOS, and falls back to post meta for an object id that is not an order. The batch apply()
 * persists an order's queued writes and deletes in a single save() — and only when something changed,
 * so a no-op submission writes nothing. CRUD keys off meta_exists() rather than value truthiness.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class OrderMetaRepository implements ObjectMetaRepositoryInterface {
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

		return \metadata_exists( 'post', $object_id, $meta_key ) ? \get_post_meta( $object_id, $meta_key, true ) : $default_value;
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

		// update_post_meta() unslashes both the meta key and the value; slash the key, and any string or
		// array value, first so backslashes survive the round-trip — get()/has() look the key up raw, so a
		// raw write would store it under a different key. Other value shapes pass through unchanged.
		$slashed = ( \is_string( $value ) || \is_array( $value ) ) ? \wp_slash( $value ) : $value;
		\update_post_meta( $object_id, \wp_slash( $meta_key ), $slashed );
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

		return \metadata_exists( 'post', $object_id, $meta_key );
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

		// delete_post_meta() unslashes the key as update_post_meta() does; slash it so a backslash key matches.
		return \delete_post_meta( $object_id, \wp_slash( $meta_key ) );
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

		foreach ( $sets as $meta_key => $value ) {
			$this->set( $object_id, (string) $meta_key, $value );
		}
		foreach ( $deletes as $meta_key ) {
			// Mirror the order path: skip an absent key so the fallback runs no delete query for a never-set one.
			if ( $this->has( $object_id, $meta_key ) ) {
				$this->delete( $object_id, $meta_key );
			}
		}
	}

	// endregion
}
