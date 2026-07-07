<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Support;

use PHPUnit\Framework\Attributes\Before;

trait RequiresWooCommerce {
	#[Before]
	protected function require_woocommerce(): void {
		if ( ! \function_exists( 'wc_get_product' ) ) {
			self::markTestSkipped( 'WooCommerce is not active.' );
		}
		if ( ! \function_exists( 'woocommerce_wp_text_input' ) ) {
			require_once WP_PLUGIN_DIR . '/woocommerce/includes/admin/wc-meta-box-functions.php';
		}
	}
}
