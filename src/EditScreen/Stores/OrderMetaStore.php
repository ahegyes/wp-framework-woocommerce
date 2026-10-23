<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\EditScreen\Stores;

use DeepWebSolutions\Framework\Settings\EditScreen\ObjectStoreInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps an object form's values as the order's meta through WooCommerce's order CRUD, one meta key per field, wherever WooCommerce stores orders.
 *
 * A write saves the order once, so that WooCommerce's order hooks and caches see the values.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class OrderMetaStore implements ObjectStoreInterface {
	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function get_values( int $object_id, array $keys ): array {
		$order  = \wc_get_order( $object_id );
		$values = array();
		if ( $order instanceof \WC_Abstract_Order ) {
			foreach ( $keys as $key ) {
				if ( $order->meta_exists( $key ) ) {
					$values[ $key ] = $order->get_meta( $key, true, 'edit' );
				}
			}
		}

		return $values;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function update_values( int $object_id, array $values ): void {
		$order = \wc_get_order( $object_id );
		if ( ! $order instanceof \WC_Abstract_Order ) {
			return;
		}

		foreach ( $values as $key => $value ) {
			// PHP turns an integer-like key into an int, which never matches the string keys WooCommerce reads back, so each write would add a row.
			$order->update_meta_data( (string) $key, $value ); // @phpstan-ignore argument.type (WooCommerce stores any value its data store can serialize, though its docblock lists only strings and arrays.)
		}
		$order->save();
	}

	// endregion
}
