<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Fixtures;

use DeepWebSolutions\Framework\WooCommerce\Backend\DescriptorBackedWCSettingsPage;

/**
 * A subclass bound by no other test, used to prove register_page() defers binding until the filter fires.
 */
final class LazyBindWCSettingsPage extends DescriptorBackedWCSettingsPage {}
