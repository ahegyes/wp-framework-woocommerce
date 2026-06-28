<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\ProductData\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractInvalidArgumentException;

/**
 * Thrown when a product-data tab descriptor is malformed or incomplete — a tab slug outside the slug charset
 * (which reaches the tab's element id and CSS class), or a custom field type the tab cannot render or sanitize
 * because it declares no matching renderer or no sanitize callback.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class InvalidProductDataTabException extends AbstractInvalidArgumentException {}
