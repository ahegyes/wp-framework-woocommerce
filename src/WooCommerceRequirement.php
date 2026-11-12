<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce;

use DeepWebSolutions\Framework\Bootstrap\Requirements;
use DeepWebSolutions\Framework\Core\PluginIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Checks that WooCommerce is loaded at the plugin's WC requires at least header, and otherwise tells users who can activate plugins why the plugin stays off.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class WooCommerceRequirement {
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
	 * Returns whether WooCommerce is loaded at the plugin's floor, and otherwise hooks the notice that explains it.
	 *
	 * WooCommerce defines its version when it loads, which may come after the plugin, so the check runs on plugins_loaded or later.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   PluginIdentity $identity The plugin's identity, whose entry file holds the WC requires at least header.
	 *
	 * @return  bool
	 */
	public static function check( PluginIdentity $identity ): bool {
		$floor = get_file_data( $identity->file, array( 'floor' => 'WC requires at least' ) )['floor'];
		if ( \defined( 'WC_VERSION' ) && ( '' === $floor || \version_compare( WC_VERSION, $floor, '>=' ) ) ) {
			return true;
		}

		add_action(
			'all_admin_notices',
			static function () use ( $identity, $floor ): void {
				if ( ! \defined( 'WC_VERSION' ) ) {
					$requirements = validate_plugin_requirements( plugin_basename( $identity->file ) ); // WordPress core names a missing WooCommerce only for a plugin whose Requires Plugins header lists it.
					if ( is_wp_error( $requirements ) ) {
						Requirements::print_admin_notice( $requirements );
					}
					return;
				}

				Requirements::print_admin_notice(
					\sprintf(
						/* translators: 1: The plugin's name. 2: The lowest WooCommerce version the plugin runs on. */
						esc_html__( '%1$s needs WooCommerce %2$s or later, so its features stay off until WooCommerce is updated.', 'wp-framework' ),
						esc_html( get_plugin_data( $identity->file, false )['Name'] ),
						esc_html( $floor )
					)
				);
			}
		);

		return false;
	}

	// endregion
}
