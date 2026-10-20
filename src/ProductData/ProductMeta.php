<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\ProductData;

\defined( 'ABSPATH' ) || exit;

/**
 * Reads the values of product fields from a product's meta.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class ProductMeta {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	protected function __construct() {}

	// endregion

	// region METHODS

	/**
	 * Returns the product's meta value under the key, or the fallback when the product has no meta under it, so that a stored empty value is never read as the fallback.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \WC_Product $product  The product.
	 * @param   string      $key      The meta key.
	 * @param   mixed       $fallback Optional. The value when the product has no meta under the key. Default is an empty string.
	 * @param   string      $context  Optional. The context of WooCommerce's read, where view applies the woocommerce_product_get_{key} filter and edit returns the stored value. Default is view.
	 *
	 * @return  mixed
	 */
	public static function get_value( \WC_Product $product, string $key, mixed $fallback = '', string $context = 'view' ): mixed {
		return $product->meta_exists( $key ) ? $product->get_meta( $key, true, $context ) : $fallback;
	}

	// endregion
}
