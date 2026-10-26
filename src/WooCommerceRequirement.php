<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce;

use DeepWebSolutions\Framework\Bootstrap\Requirements;

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
	 *
	 * @param   string $plugin_file The path to the plugin's entry file.
	 */
	public function __construct(
		protected string $plugin_file
	) {}

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
	 * @return  bool
	 */
	public function check(): bool {
		$floor = get_file_data( $this->plugin_file, array( 'floor' => 'WC requires at least' ) )['floor'];
		if ( \defined( 'WC_VERSION' ) && ( '' === $floor || \version_compare( WC_VERSION, $floor, '>=' ) ) ) {
			return true;
		}

		$plugin_file = $this->plugin_file;
		add_action(
			'all_admin_notices',
			static function () use ( $plugin_file, $floor ): void {
				if ( ! \defined( 'WC_VERSION' ) ) {
					// WordPress core names a missing WooCommerce only for a plugin whose Requires Plugins header lists it.
					$requirements = validate_plugin_requirements( plugin_basename( $plugin_file ) );
					if ( is_wp_error( $requirements ) ) {
						Requirements::print_admin_notice( $requirements );
					}
					return;
				}

				Requirements::print_admin_notice(
					\sprintf(
						/* translators: 1: The plugin's name. 2: The lowest WooCommerce version the plugin runs on. */
						esc_html__( '%1$s needs WooCommerce %2$s or later, so its features stay off until WooCommerce is updated.', 'wp-framework' ),
						esc_html( get_plugin_data( $plugin_file, false )['Name'] ),
						esc_html( $floor )
					)
				);
			}
		);

		return false;
	}

	// endregion
}
