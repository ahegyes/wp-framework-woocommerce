# wp-framework-woocommerce

WooCommerce settings backend, product-data and order-data fields, version and database-version conditionals, and a PSR-3 logger for plugins built on the DWS framework.

Part of the [DWS WordPress framework](https://github.com/ahegyes/wordpress-framework) — see the monorepo for architecture, contributing, and the rest of the package set.

## Installation

```bash
composer require ahegyes/wp-framework-woocommerce
```

## Usage

### Add a product-data tab

Describe the tab with the settings descriptors and register it on a `ProductDataFieldSurface` — one surface drives one tab:

```php
use DeepWebSolutions\Framework\Settings\Schema\Field\FieldType;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsSection;
use DeepWebSolutions\Framework\WooCommerce\ProductData\ProductDataFieldSurface;
use DeepWebSolutions\Framework\WooCommerce\ProductData\ProductDataTab;

$surface = new ProductDataFieldSurface();
$surface->register_tab(
	new ProductDataTab(
		slug: 'my_plugin',
		label: 'My Plugin',
		meta_key_prefix: '_my_plugin_',
		sections: array(
			new SettingsSection(
				id: 'general',
				title: 'General',
				fields: array(
					new SettingsField( id: 'lead_time', type: FieldType::Number->value, label: 'Lead time (days)', default_value: 0 ),
				),
			),
		),
	),
);

// Field-addressed reads and writes outside the product screen:
$days = $surface->get( 'general', $product_id, 'lead_time' );
```

`meta_keys()` enumerates the tab's exact product-meta key set for the consumer installer's uninstall cleanup.

## Lineage

Successor to [`deep-web-solutions/wp-framework-woocommerce`](https://github.com/deep-web-solutions/wordpress-framework-woocommerce) (archived).
