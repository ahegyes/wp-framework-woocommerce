# wp-framework-woocommerce

The framework's settings, edit-screen and logging helpers carried onto WooCommerce's own screens and APIs, for plugins that extend WooCommerce.

## Requirements

It builds on wp-framework-settings, which brings wp-framework-core, wp-framework-bootstrap and wp-framework-shared. Its `composer.json` holds its PHP floor under `require.php`, its WordPress floor under `extra.requires-wp`, its WooCommerce floor under `extra.requires-wc` and WooCommerce under `extra.requires-plugins`, which a plugin's `Requires PHP`, `Requires at least`, `WC requires at least` and `Requires Plugins` headers meet. `WooCommerceRequirement` checks the plugin's own `WC requires at least` header at runtime.

## Installation

The packages are not on Packagist, and Composer reads repositories only from the plugin's own `composer.json`, so a plugin lists one `vcs` repository for each package of the stack. It requires the package for development only, because it ships a prefixed copy instead:

```json
{
  "require-dev": {
    "ahegyes/wp-framework-woocommerce": "^2.0"
  },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/ahegyes/wp-framework-bootstrap" },
    { "type": "vcs", "url": "https://github.com/ahegyes/wp-framework-shared" },
    { "type": "vcs", "url": "https://github.com/ahegyes/wp-framework-core" },
    { "type": "vcs", "url": "https://github.com/ahegyes/wp-framework-settings" },
    { "type": "vcs", "url": "https://github.com/ahegyes/wp-framework-woocommerce" }
  ],
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

`prefer-stable` installs the newest release that satisfies `^2.0`, and `minimum-stability` lets Composer take the development branch where no release does.

## Prefixing

WordPress loads every plugin into one PHP process, so each plugin ships the framework prefixed under its own namespace. Two plugins that ship the packages unprefixed fatal with "Cannot redeclare class …" once both load bootstrap's files, and their autoloaded classes resolve to one plugin's copy, chosen by the order the plugins load in, so the other plugin silently runs it. WordPress, WooCommerce (`WC_*`, `wc_*`, `WC()`, `Automattic\WooCommerce\*`) and Action Scheduler (`as_*`, `ActionScheduler*`) symbols stay global, and `Psr\*` is prefixed with the framework, so the plugin's container and logger implement the same prefixed interfaces.

[wordpress-configs](https://github.com/ahegyes/wordpress-configs) prefixes the packages with php-scoper into `vendor-prefixed/` after every development install, and a later `composer install --no-dev` keeps that output, so a build runs both, in that order. It also replaces the framework's text domain with the plugin's `extra.text-domain`, failing the build if any of the framework's survives. WooCommerce's stubs tell it which symbols WooCommerce defines. The plugin adds to its `composer.json`:

```json
{
  "require-dev": {
    "ahegyes/wordpress-configs": "dev-trunk",
    "humbug/php-scoper": "^0.18",
    "php-stubs/woocommerce-stubs": "^11.3"
  },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/ahegyes/wordpress-configs" }
  ],
  "autoload": {
    "psr-4": { "Acme\\PersonaWooCommerce\\": "src/" },
    "files": [ "vendor-prefixed/scoper-autoload.php" ]
  },
  "scripts": {
    "post-autoload-dump": [ "DeepWebSolutions\\Config\\Composer\\ScopePhpDependencies::postAutoloadDump" ]
  },
  "extra": {
    "scoping-prefix": "Acme\\PersonaWooCommerce\\Scoped",
    "text-domain": "acme-persona-woocommerce"
  }
}
```

Its `scoper.inc.php` names the packages to prefix:

```php
return ( require __DIR__ . '/vendor/ahegyes/wordpress-configs/php/php-scoper/scoper-base.inc.php' )( __DIR__, 'ahegyes/wp-framework-*' );
```

## Usage

`Logging/` extends core's logging and `EditScreen/` settings' object forms, while `ProductData/` and `Setting/` are WooCommerce screens built on settings' `Forms`. A `SettingsTab` adds a WooCommerce settings tab, and a `SettingsSection`, constructed in a `woocommerce_settings_sections_registration` callback, adds a section to an existing one. An `OrderMetaBox` hosts an object form on the order edit screens and stores through `OrderMetaStore`, and `ProductMeta::get_value()` reads a product field's stored value, or a fallback only where the meta is absent.

The plugin's main file requires `load.php` from bootstrap's prefixed copy, then its autoloader once the floor check passes:

```php
use Acme\PersonaWooCommerce\Scoped\DeepWebSolutions\Framework\Bootstrap\Requirements;

defined( 'ABSPATH' ) || exit;

define( 'ACME_PW_FILE', __FILE__ );

$acme_pw_bootstrap = __DIR__ . '/vendor-prefixed/ahegyes/wp-framework-bootstrap/load.php';
if ( ! is_readable( $acme_pw_bootstrap ) ) { // The floor gate lives in the bootstrap package, so nothing built may load without it.
	return;
}

require_once $acme_pw_bootstrap;
if ( ! Requirements::check_floors( __FILE__ ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/functions.php';
```

The helpers need no kernel. On `plugins_loaded` at priority 15, after WooCommerce has loaded, the plugin checks WooCommerce against its floor, registers a product data tab and adds a settings tab in WooCommerce's filter, where `WC_Settings_Page` exists:

```php
use Acme\PersonaWooCommerce\Packing;
use Acme\PersonaWooCommerce\Scoped\DeepWebSolutions\Framework\WooCommerce\Logging\WooCommerceLogger;
use Acme\PersonaWooCommerce\Scoped\DeepWebSolutions\Framework\WooCommerce\ProductData\ProductDataTab;
use Acme\PersonaWooCommerce\Scoped\DeepWebSolutions\Framework\WooCommerce\Setting\SettingsTab;
use Acme\PersonaWooCommerce\Scoped\DeepWebSolutions\Framework\WooCommerce\WooCommerceRequirement;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/options.php';

add_action( 'plugins_loaded', 'acme_pw_register_hooks', 15 );

// ...

function acme_pw_register_hooks(): void {
	if ( ! WooCommerceRequirement::check( ACME_PW_FILE ) ) {
		return;
	}

	new ProductDataTab( Packing::PREFIX, new Packing() )->register_hooks();
	add_filter( 'woocommerce_get_settings_pages', 'acme_pw_add_settings_tab' );
	add_action( 'woocommerce_update_options_' . Packing::PREFIX, 'acme_pw_log_settings_save' );
}

// ...

function acme_pw_add_settings_tab( array $pages ): array {
	$pages[] = new SettingsTab(
		Packing::PREFIX,
		array(
			'label'    => __( 'Packing', 'acme-persona-woocommerce' ),
			'sections' => array( '' => __( 'General', 'acme-persona-woocommerce' ) ),
		),
		new Packing()
	);

	return $pages;
}

// ...

function acme_pw_log_settings_save(): void {
	new WooCommerceLogger( 'acme-persona-woocommerce', 'info' )->info( 'The packing settings were saved.' );
}
```

A definition object declares both tabs' fields as data, which WooCommerce renders, validates and saves:

```php
final class Packing implements ProductDataTabDefinitionInterface, SectionFieldsDefinitionInterface {
	// ...

	#[\Override]
	public function get_section_fields( string $tab_id, string $section_id ): array {
		return array(
			array(
				'type'  => 'text',
				'id'    => self::PREFIX . '_default_box',
				'title' => __( 'Default box', 'acme-persona-woocommerce' ),
			),
			array(
				'type'  => 'checkbox',
				'id'    => ACME_PW_OPTION_DELETE_DATA,
				'title' => __( 'Data on uninstall', 'acme-persona-woocommerce' ),
				'desc'  => __( 'Delete the packing data when the plugin is uninstalled', 'acme-persona-woocommerce' ),
			),
		);
	}

	// ...

	#[\Override]
	public function get_product_data_tab( string $tab_id, \WC_Product $product ): array {
		return array(
			'label'  => __( 'Packing', 'acme-persona-woocommerce' ),
			'groups' => array(
				array(
					'fields' => array(
						array(
							'type'  => 'text',
							'id'    => ACME_PW_META_BOX,
							'label' => __( 'Box', 'acme-persona-woocommerce' ),
						),
					),
				),
			),
		);
	}

	// ...
}
```

The plugin's `uninstall.php` runs core's `Uninstaller` as core's README shows, and passes `MetaTypes::DEFINITIONS`, so that a footprint can name the `order` and `order_item` meta kinds, whose WooCommerce tables the definitions name, so the uninstall finds them while WooCommerce is inactive:

```php
Uninstaller::run(
	$acme_pw_file,
	$acme_pw_footprint,
	array(
		'consent'    => array( 'option' => $acme_pw_consent ),
		'meta_types' => $acme_pw_meta_types,
	)
);
```

## Contract and versioning

The five packages release together under one version, which the [changelog](https://github.com/ahegyes/wordpress-framework/blob/trunk/CHANGELOG.md) records.

The 2.x contract is every public class, interface, enum, method, property and constant, the protected members of non-final classes, and the hook and priority each public callback method is registered on, unless tagged `@internal`. A `protected(set)` property is contract for reading, by its name and declared type. `@internal`, `private` and protected-on-final members are not contract, and an `@internal` symbol serves only its own package, because a caret edge installs a package beside an older or newer release of another. Hook names and their arguments, option and meta keys, REST routes and the path of bootstrap's `load.php` are contract too.

`SettingsSection` follows WooCommerce's experimental settings API, so the methods it inherits, `get_settings_ui_page()`, `get_script_handles()` and `get_save_adapter()`, follow WooCommerce and may change in a minor release.

Releases follow SemVer on this contract from the first tag: a break of it ships as the next major, and internals change in any release. Plugins on different majors run side by side, because each ships its own prefixed copy.

This package is developed in the [wordpress-framework](https://github.com/ahegyes/wordpress-framework) monorepo, which holds its issues and pull requests.
