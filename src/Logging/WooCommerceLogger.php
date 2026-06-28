<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Logging;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

/**
 * PSR-3 logger that writes through WooCommerce's logging stack under a fixed source channel.
 *
 * Forwards each record to a {@see \WC_Logger_Interface} — resolved lazily via wc_get_logger() unless one
 * is supplied — at the matching WooCommerce level, since the PSR-3 and WooCommerce level names coincide;
 * an unrecognized level is rejected. The caller's context rides along to WooCommerce rather than being
 * interpolated into the message, and the source channel is stamped ahead of it so a record cannot
 * redirect itself to another WooCommerce log file.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class WooCommerceLogger implements LoggerInterface {
	// region TRAITS

	use LoggerTrait;

	// endregion

	// region FIELDS AND CONSTANTS

	/**
	 * The PSR-3 levels, which WooCommerce names identically, used to reject an unrecognized level.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, true>
	 */
	protected const LEVELS = array(
		LogLevel::EMERGENCY => true,
		LogLevel::ALERT     => true,
		LogLevel::CRITICAL  => true,
		LogLevel::ERROR     => true,
		LogLevel::WARNING   => true,
		LogLevel::NOTICE    => true,
		LogLevel::INFO      => true,
		LogLevel::DEBUG     => true,
	);

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
	 *
	 * @throws  InvalidArgumentException When $level is not a PSR-3 level.
	 */
	#[\Override]
	public function log( $level, string|\Stringable $message, array $context = array() ): void {
		if ( ! \is_string( $level ) || ! isset( self::LEVELS[ $level ] ) ) {
			throw new InvalidArgumentException( 'Unknown log level: ' . ( \is_scalar( $level ) ? (string) $level : \gettype( $level ) ) );
		}

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
