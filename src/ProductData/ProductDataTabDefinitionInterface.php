<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\ProductData;

\defined( 'ABSPATH' ) || exit;

/**
 * Defines a product data tab for each product it edits, which asks for its definition once per product, on the product's edit screen or save.
 *
 * An implementer keeps the product parameter's type \WC_Product, or widens it to mixed, since PHP would load \WC_Product to check any other type against it, which fails while WooCommerce is inactive.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-import-type Tab from ProductDataTab
 */
interface ProductDataTabDefinitionInterface {
	/**
	 * Returns the definition of the tab for the product, or null when the tab does not apply to it, which must not throw.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string      $tab_id  The tab's key among the product data tabs.
	 * @param   \WC_Product $product The product being edited or saved.
	 *
	 * @return  Tab|null
	 */
	public function get_product_data_tab( string $tab_id, \WC_Product $product ): ?array;
}
