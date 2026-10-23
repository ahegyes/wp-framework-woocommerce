<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Bootstrap;

\defined( 'ABSPATH' ) || exit;

/**
 * Returns the meta types through which the uninstall engine removes a plugin's WooCommerce order meta and order item meta, with WooCommerce loaded or not.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class WooCommerceUninstaller {
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
	 * Returns the meta types of WooCommerce's orders and order items, keyed by the names a footprint's meta kinds use.
	 *
	 * Order meta lives in post meta and, where WooCommerce created it, in the order meta table. Order item meta lives only in WooCommerce's own table, whose WordPress meta type exists only while WooCommerce is loaded, and plugins are often deleted after WooCommerce is deactivated.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  array<string, array{meta_type?: string, meta_tables: list<string>, cache_groups: list<string>}>
	 */
	public static function get_meta_types(): array {
		return array(
			'order'      => array(
				'meta_type'    => 'post',
				'meta_tables'  => array( 'wc_orders_meta' ),
				'cache_groups' => array( 'orders', 'order_objects', 'orders_data', 'orders_meta' ),
			),
			'order_item' => array(
				'meta_tables'  => array( 'woocommerce_order_itemmeta' ),
				'cache_groups' => array( 'order-items', 'order_item_meta' ),
			),
		);
	}

	// endregion
}
