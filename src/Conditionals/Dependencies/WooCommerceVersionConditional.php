<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Conditionals\Dependencies;

use DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface;
use DeepWebSolutions\Framework\Shared\Version\Version;

/**
 * Pre-resolution gate that passes iff the active WooCommerce version is at least the minimum.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class WooCommerceVersionConditional implements ConditionalInterface {
	// region MAGIC METHODS

	/**
	 * Constructs the conditional with the minimum WooCommerce version required.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Version $minimum Minimum WooCommerce version that satisfies the gate.
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
		// WooCommerce defines WC_VERSION at include time, before plugins_loaded; an undefined constant
		// means WooCommerce is inactive, so the gate is unmet rather than reading an undefined constant.
		return \defined( 'WC_VERSION' ) && \version_compare( \WC_VERSION, $this->minimum->value, '>=' );
	}

	// endregion
}
