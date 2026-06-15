<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Fixtures;

use DeepWebSolutions\Framework\WooCommerce\DescriptorBackedWCSettingsPage;

/**
 * A subclass deliberately never bound to a descriptor, to exercise the unbound-instantiation guard.
 */
final class UnboundWCSettingsPage extends DescriptorBackedWCSettingsPage {}
