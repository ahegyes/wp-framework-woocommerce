<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Conditionals\Dependencies;

use DeepWebSolutions\Framework\Shared\Version\Version;
use DeepWebSolutions\Framework\WooCommerce\Conditionals\Dependencies\WooCommerceVersionConditional;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( WooCommerceVersionConditional::class )]
#[UsesClass( Version::class )]
final class WooCommerceVersionConditionalTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		if ( ! \defined( 'WC_VERSION' ) ) {
			self::markTestSkipped( 'WooCommerce is not loaded in this environment.' );
		}
	}

	public function test_is_met_for_a_minimum_at_or_below_the_active_version(): void {
		self::assertTrue( ( new WooCommerceVersionConditional( Version::from_string( '1.0.0' ) ) )->is_met() );
	}

	public function test_is_unmet_for_a_minimum_above_the_active_version(): void {
		self::assertFalse( ( new WooCommerceVersionConditional( Version::from_string( '999.0.0' ) ) )->is_met() );
	}

	public function test_is_met_for_a_minimum_equal_to_the_active_version(): void {
		self::assertTrue( ( new WooCommerceVersionConditional( Version::from_string( \WC_VERSION ) ) )->is_met() );
	}
}
