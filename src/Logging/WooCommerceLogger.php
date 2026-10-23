<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Logging;

use Automattic\WooCommerce\Utilities\LoggingUtil;
use Psr\Log\AbstractLogger as PsrAbstractLogger;
use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\Log\LogLevel as PsrLogLevel;

\defined( 'ABSPATH' ) || exit;

/**
 * Writes log records to WooCommerce's log under the plugin's source, and errors that WooCommerce drops to the PHP error log.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class WooCommerceLogger extends PsrAbstractLogger {
	// region FIELDS AND CONSTANTS

	/**
	 * The severity of each level, as WooCommerce ranks them.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, int>
	 */
	protected const array SEVERITIES = array(
		PsrLogLevel::EMERGENCY => 800,
		PsrLogLevel::ALERT     => 700,
		PsrLogLevel::CRITICAL  => 600,
		PsrLogLevel::ERROR     => 500,
		PsrLogLevel::WARNING   => 400,
		PsrLogLevel::NOTICE    => 300,
		PsrLogLevel::INFO      => 200,
		PsrLogLevel::DEBUG     => 100,
	);

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
			$this->floor = self::SEVERITIES[ $threshold ] ?? throw new PsrInvalidArgumentException( "Log level '$threshold' is not a PSR-3 level." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
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
		$severity = \is_string( $level ) ? ( self::SEVERITIES[ $level ] ?? null ) : null;
		if ( \is_null( $severity ) ) {
			throw new PsrInvalidArgumentException( "Log level '" . ( \is_string( $level ) ? $level : \get_debug_type( $level ) ) . "' is not a PSR-3 level." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
		if ( $severity < ( $this->floor ?? ( \defined( 'WP_DEBUG' ) && WP_DEBUG ? self::SEVERITIES[ PsrLogLevel::DEBUG ] : self::SEVERITIES[ PsrLogLevel::WARNING ] ) ) ) {
			return;
		}

		$message        = $this->interpolate( (string) $message, $context );
		$this->logger ??= \function_exists( 'wc_get_logger' ) ? \wc_get_logger() : null;
		$this->logger?->log( $level, $message, array( 'source' => $this->source ) + self::encode_context( $context ) );

		if ( $severity >= self::SEVERITIES[ PsrLogLevel::ERROR ] && self::is_dropped_by_woocommerce( $severity ) ) {
			$exception = $context['exception'] ?? null;
			\error_log( "[$this->source.$level] $message" . ( $exception instanceof \Throwable ? "\n$exception" : '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Errors must stay visible when WooCommerce drops them.
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Replaces each {key} placeholder in the message with its scalar, null or Stringable context value.
	 *
	 * Only the values the message uses are cast, and a placeholder whose value throws while casting stays as it is, because PSR-3 forbids a context value from making logging fail.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string       $message The message with placeholders.
	 * @param   array<mixed> $context The values to interpolate.
	 *
	 * @return  string
	 */
	protected function interpolate( string $message, array $context ): string {
		$replacements = array();
		foreach ( $context as $key => $value ) {
			$placeholder = '{' . $key . '}';
			if ( ! \str_contains( $message, $placeholder ) || ! ( \is_null( $value ) || \is_scalar( $value ) || $value instanceof \Stringable ) ) {
				continue;
			}

			try {
				$replacements[ $placeholder ] = (string) $value;
			} catch ( \Throwable ) {
				continue;
			}
		}

		return \strtr( $message, $replacements );
	}

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
		return ! \class_exists( LoggingUtil::class ) || ! LoggingUtil::logging_is_enabled() || ( self::SEVERITIES[ LoggingUtil::get_level_threshold() ] ?? 0 ) > $severity;
	}

	// endregion
}
