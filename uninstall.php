<?php declare( strict_types=1 );
/**
 * Loads the class whose handlers remove a plugin's WooCommerce order data on uninstall.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @package DeepWebSolutions\Framework\WooCommerce\Bootstrap
 */

namespace DeepWebSolutions\Framework\WooCommerce\Bootstrap;

\defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/bootstrap/WooCommerceUninstaller.php';
