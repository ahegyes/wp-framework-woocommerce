<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Conditionals\Dependencies;

use DeepWebSolutions\Framework\Shared\Version\Version;
use DeepWebSolutions\Framework\WooCommerce\Conditionals\Dependencies\WooCommerceDbVersionConditional;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( WooCommerceDbVersionConditional::class )]
#[UsesClass( Version::class )]
final class WooCommerceDbVersionConditionalTest extends TestCase {
	private const OPTION = 'woocommerce_db_version';

	private mixed $original;

	protected function setUp(): void {
		parent::setUp();
		$this->original = \get_option( self::OPTION );
	}

	protected function tearDown(): void {
		if ( false === $this->original ) {
			\delete_option( self::OPTION );
		} else {
			\update_option( self::OPTION, $this->original );
		}
		parent::tearDown();
	}

	public function test_is_met_for_a_minimum_at_or_below_the_stored_db_version(): void {
		\update_option( self::OPTION, '9.5.0' );

		self::assertTrue( ( new WooCommerceDbVersionConditional( Version::from_string( '9.0.0' ) ) )->is_met() );
	}

	public function test_is_met_for_a_minimum_equal_to_the_stored_db_version(): void {
		\update_option( self::OPTION, '9.5.0' );

		self::assertTrue( ( new WooCommerceDbVersionConditional( Version::from_string( '9.5.0' ) ) )->is_met() );
	}

	public function test_is_unmet_for_a_minimum_above_the_stored_db_version(): void {
		\update_option( self::OPTION, '9.5.0' );

		self::assertFalse( ( new WooCommerceDbVersionConditional( Version::from_string( '10.0.0' ) ) )->is_met() );
	}

	public function test_is_unmet_when_the_db_version_option_is_absent(): void {
		\delete_option( self::OPTION );

		self::assertFalse( ( new WooCommerceDbVersionConditional( Version::from_string( '1.0.0' ) ) )->is_met() );
	}
}
