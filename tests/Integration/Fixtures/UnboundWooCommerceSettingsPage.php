<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Fixtures;

use DeepWebSolutions\Framework\WooCommerce\Backend\DescriptorBackedWooCommerceSettingsPage;

/**
 * A subclass deliberately never bound to a descriptor, to exercise the unbound-instantiation guard.
 */
final class UnboundWooCommerceSettingsPage extends DescriptorBackedWooCommerceSettingsPage {}
