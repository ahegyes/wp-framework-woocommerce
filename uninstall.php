<?php declare( strict_types=1 );
/**
 * Loads the class whose meta types let the uninstall engine remove a plugin's WooCommerce order data.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @package DeepWebSolutions\Framework\WooCommerce\Bootstrap
 */

namespace DeepWebSolutions\Framework\WooCommerce\Bootstrap;

\defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/bootstrap/WooCommerceUninstaller.php';
