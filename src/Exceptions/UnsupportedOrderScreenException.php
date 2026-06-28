<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown when an order field store is asked to register on a screen other than the WooCommerce order screen.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class UnsupportedOrderScreenException extends AbstractRuntimeException {}
