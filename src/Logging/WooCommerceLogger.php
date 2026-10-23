<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Logging;

use Automattic\WooCommerce\Utilities\LoggingUtil;
use DeepWebSolutions\Framework\Core\Logging\AbstractLogger;
use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\Log\LogLevel as PsrLogLevel;

\defined( 'ABSPATH' ) || exit;

/**
 * Writes log records to WooCommerce's log under the plugin's source, and errors that WooCommerce drops to the PHP error log.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class WooCommerceLogger extends AbstractLogger {
	// region FIELDS AND CONSTANTS

	/**
	 * The severity of the lowest level the threshold logs, or null for the WP_DEBUG default.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     int|null
	 */
	protected ?int $floor = null;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string                    $source    The source that files every record under the plugin in WooCommerce's log.
	 * @param   string|null               $threshold Optional. The lowest level to log, which replaces the default of warning, or of every level under WP_DEBUG. Default is null.
	 * @param   \WC_Logger_Interface|null $logger    Optional. The WooCommerce logger to write to. Default is null, which resolves WooCommerce's logger on the first record, so construction never needs WooCommerce.
	 *
	 * @throws  PsrInvalidArgumentException Thrown when the threshold is not a PSR-3 level.
	 */
	public function __construct(
		protected string $source,
		?string $threshold = null,
		protected ?\WC_Logger_Interface $logger = null
	) {
		if ( ! \is_null( $threshold ) ) {
			$this->floor = self::get_severity( $threshold );
		}
	}

	// endregion

	// region INHERITED METHODS

	/**
	 * Writes a record to WooCommerce's log, and an error that WooCommerce drops to the PHP error log.
	 *
	 * Records below the threshold are dropped, and without one, records below warning are dropped unless WP_DEBUG is on. WooCommerce then applies its own logging switch and threshold.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed              $level   The record's level, one of the PsrLogLevel constants.
	 * @param   string|\Stringable $message The message, with placeholders.
	 * @param   array<mixed>       $context The values to interpolate, and any Throwable to write after the message under the exception key.
	 *
	 * @throws  PsrInvalidArgumentException Thrown when the level is not a PSR-3 level.
	 */
	#[\Override]
	public function log( mixed $level, string|\Stringable $message, array $context = array() ): void {
		$severity = self::get_severity( $level );
		if ( $severity < ( $this->floor ?? self::get_default_threshold() ) ) {
			return;
		}

		$message        = self::interpolate( (string) $message, $context );
		$this->logger ??= \function_exists( 'wc_get_logger' ) ? \wc_get_logger() : null;
		$this->logger?->log( $level, $message, array( 'source' => $this->source ) + self::encode_context( $context ) );

		if ( $severity >= self::SEVERITIES[ PsrLogLevel::ERROR ] && self::is_dropped_by_woocommerce( $severity ) ) {
			\error_log( self::format_record( $this->source, $level, $message, $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Errors must stay visible when WooCommerce drops them.
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the context with a Throwable under the exception key written as a string, and each value that does not encode as JSON replaced by its type.
	 *
	 * WooCommerce's file handler encodes the context as JSON, which turns a Throwable into an empty object and lets a throwing jsonSerialize() escape the logger.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<mixed> $context The record's context.
	 *
	 * @return  array<mixed>
	 */
	protected static function encode_context( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( 'exception' === $key && $value instanceof \Throwable ) {
				$context[ $key ] = (string) $value;
				continue;
			}

			try {
				$encoded = \wp_json_encode( $value );
			} catch ( \Throwable ) {
				$encoded = false;
			}
			if ( false === $encoded ) {
				$context[ $key ] = \get_debug_type( $value );
			}
		}

		return $context;
	}

	/**
	 * Returns whether WooCommerce drops a record of the severity, because it is not loaded, its logging is off or its threshold is above the severity.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $severity The record's severity.
	 *
	 * @return  bool
	 */
	protected static function is_dropped_by_woocommerce( int $severity ): bool {
		return ! \class_exists( LoggingUtil::class ) || ! LoggingUtil::logging_is_enabled() || \WC_Log_Levels::get_level_severity( LoggingUtil::get_level_threshold() ) > $severity;
	}

	// endregion
}
