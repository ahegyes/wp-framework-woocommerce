<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Backend\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractInvalidArgumentException;

/**
 * Thrown when a WooCommerce settings page declares a capability WooCommerce cannot enforce on save.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class UnsupportedSettingsPageCapabilityException extends AbstractInvalidArgumentException {}
