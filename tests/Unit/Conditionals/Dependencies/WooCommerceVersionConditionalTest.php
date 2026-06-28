<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Unit\Conditionals\Dependencies;

use DeepWebSolutions\Framework\Shared\Version\Version;
use DeepWebSolutions\Framework\WooCommerce\Conditionals\Dependencies\WooCommerceVersionConditional;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
