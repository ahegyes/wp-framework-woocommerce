<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown when a product-data tab descriptor is malformed — a tab slug outside the slug charset, which
 * reaches the product-data tab's element id and CSS class unescaped.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class InvalidProductDataTabException extends AbstractRuntimeException {}
