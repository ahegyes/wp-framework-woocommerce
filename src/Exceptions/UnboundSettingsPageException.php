<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown when a descriptor-backed WooCommerce settings page is instantiated before its descriptor is bound.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class UnboundSettingsPageException extends AbstractRuntimeException {}
