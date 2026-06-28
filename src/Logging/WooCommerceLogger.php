<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * PSR-3 logger that writes through WooCommerce's logging stack under a fixed source channel.
 *
 * Forwards each record to a {@see \WC_Logger_Interface} — resolved lazily via wc_get_logger() unless one
 * is supplied — at the matching WooCommerce level, since the PSR-3 and WooCommerce level names coincide.
 * An unrecognized level is delegated to WooCommerce, which drops it rather than throwing. The source
 * channel is stamped ahead of the caller's context so a record cannot redirect itself to a different
 * WooCommerce log file.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class WooCommerceLogger implements LoggerInterface {
	// region TRAITS

	use LoggerTrait;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string                    $source Source channel every record is written under (the WooCommerce log handle).
	 * @param   \WC_Logger_Interface|null $logger Backing WooCommerce logger; null resolves wc_get_logger() on first use.
	 */
	public function __construct(
		protected string $source,
		protected ?\WC_Logger_Interface $logger = null,
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
	public function log( $level, string|\Stringable $message, array $context = array() ): void {
		// Stamp the source ahead of the caller's context (left wins on key union), so a record cannot
		// redirect itself to another WooCommerce log channel by passing its own 'source'.
		$this->logger()->log( $level, (string) $message, array( 'source' => $this->source ) + $context );
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves the backing WooCommerce logger, lazily defaulting to wc_get_logger() so construction stays
	 * free of WooCommerce side effects.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  \WC_Logger_Interface
	 */
	protected function logger(): \WC_Logger_Interface {
		return $this->logger ??= \wc_get_logger();
	}

	// endregion
}
