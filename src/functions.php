<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce;

use function DeepWebSolutions\Framework\Settings\Schema\is_checkbox_checked;

/**
 * Maps a value to WooCommerce's yes/no checkbox string via the canonical checkbox truth rule: a checked
 * value becomes 'yes', everything else 'no'. WooCommerce string-compares a checkbox against the literal
 * 'yes', so its render and save round-trip through this representation.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @param   mixed $value Value to coerce.
 *
 * @return  string
 */
function to_yes_no( mixed $value ): string {
	return is_checkbox_checked( $value ) ? 'yes' : 'no';
}
