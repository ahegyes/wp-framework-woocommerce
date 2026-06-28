<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesFunction;
use PHPUnit\Framework\TestCase;

use function DeepWebSolutions\Framework\WooCommerce\to_yes_no;

#[CoversFunction( 'DeepWebSolutions\Framework\WooCommerce\to_yes_no' )]
#[UsesFunction( 'DeepWebSolutions\Framework\Settings\Schema\is_checkbox_checked' )]
final class FunctionsTest extends TestCase {
	#[DataProvider( 'yes_no_matrix' )]
	public function test_maps_a_value_to_the_wc_yes_no_string( mixed $value, string $expected ): void {
		self::assertSame( $expected, to_yes_no( $value ) );
	}

	/**
	 * Mirrors the canonical checkbox truth rule: only true, 1, '1', and 'yes' map to 'yes'; the
	 * previously-truthy 'no'/'off'/'false'/arbitrary strings now map to 'no'.
	 *
	 * @return array<string, array{mixed, string}>
	 */
	public static function yes_no_matrix(): array {
		return array(
			'bool true'        => array( true, 'yes' ),
			'int 1'            => array( 1, 'yes' ),
			'string 1'        => array( '1', 'yes' ),
			'string yes'      => array( 'yes', 'yes' ),
			'bool false'       => array( false, 'no' ),
			'int 0'            => array( 0, 'no' ),
			'string 0'        => array( '0', 'no' ),
			'string no'       => array( 'no', 'no' ),
			'string off'      => array( 'off', 'no' ),
			'string false'    => array( 'false', 'no' ),
			'string on'       => array( 'on', 'no' ),
			'arbitrary string' => array( 'anything', 'no' ),
			'empty string'     => array( '', 'no' ),
			'null'             => array( null, 'no' ),
			'array'            => array( array( 'yes' ), 'no' ),
			'int 2'            => array( 2, 'no' ),
		);
	}
}
