<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Unit\Conditionals\Dependencies;

use DeepWebSolutions\Framework\Shared\Version\Version;
use DeepWebSolutions\Framework\WooCommerce\Conditionals\Dependencies\WooCommerceVersionConditional;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( WooCommerceVersionConditional::class )]
#[UsesClass( Version::class )]
final class WooCommerceVersionConditionalTest extends TestCase {
	public function test_is_unmet_when_woocommerce_is_not_loaded(): void {
		if ( \defined( 'WC_VERSION' ) ) {
			self::markTestSkipped( 'WC_VERSION is defined in this process, so the unloaded branch cannot be exercised here.' );
		}

		self::assertFalse( ( new WooCommerceVersionConditional( Version::from_string( '1.0.0' ) ) )->is_met() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_is_met_when_active_version_exceeds_minimum(): void {
		\define( 'WC_VERSION', '9.5.0' );

		self::assertTrue( ( new WooCommerceVersionConditional( Version::from_string( '9.0.0' ) ) )->is_met() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_is_met_when_active_version_equals_minimum(): void {
		\define( 'WC_VERSION', '9.0.0' );

		self::assertTrue( ( new WooCommerceVersionConditional( Version::from_string( '9.0.0' ) ) )->is_met() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_is_unmet_when_active_version_is_below_minimum(): void {
		\define( 'WC_VERSION', '8.9.0' );

		self::assertFalse( ( new WooCommerceVersionConditional( Version::from_string( '9.0.0' ) ) )->is_met() );
	}
}
