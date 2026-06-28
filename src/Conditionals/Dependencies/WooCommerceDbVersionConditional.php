<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Conditionals\Dependencies;

use DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface;
use DeepWebSolutions\Framework\Shared\Version\Version;

/**
 * Pre-resolution gate that passes iff the stored WooCommerce database version is at least the minimum.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class WooCommerceDbVersionConditional implements ConditionalInterface {
	// region MAGIC METHODS

	/**
	 * Constructs the conditional with the minimum WooCommerce database version required.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Version $minimum Minimum WooCommerce database version that satisfies the gate.
	 */
	public function __construct(
		protected readonly Version $minimum,
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function is_met(): bool {
		// The schema version WooCommerce records after a database migration; an absent option (never
		// installed) is a non-string and leaves the gate unmet. The option persists across deactivation,
		// so this gate reflects the recorded schema only — compose it with WooCommerceVersionConditional
		// when the requirement is "WooCommerce active AND schema at least X".
		$db_version = \get_option( 'woocommerce_db_version' );

		return \is_string( $db_version ) && \version_compare( $db_version, $this->minimum->value, '>=' );
	}

	// endregion
}
