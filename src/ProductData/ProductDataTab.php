<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\ProductData;

use DeepWebSolutions\Framework\Settings\ValueObjects\SettingsSection;
use DeepWebSolutions\Framework\WooCommerce\Exceptions\InvalidProductDataTabException;

/**
 * Declarative description of a WooCommerce product-data settings tab — a custom panel in the product
 * editor's Product data meta box.
 *
 * Its sections (reusing the settings SettingsSection) group SettingsField controls persisted as product
 * meta under a shared key prefix; the product-type gate, the tab's CSS classes, and renderers for field
 * types outside the framework taxonomy are optional composition seams.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class ProductDataTab {
	// region FIELDS AND CONSTANTS

	/**
	 * Slug charset: a lowercase letter then lowercase letters, digits, underscores, or hyphens.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	private const SLUG_PATTERN = '/\A[a-z][a-z0-9_-]*\z/';

	/**
	 * Product-type gate deciding whether the tab applies to a product; null applies it to every product
	 * WooCommerce recognizes. Signature `(int $product_id): bool`.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     ?\Closure
	 */
	public ?\Closure $supports_product;

	/**
	 * Renderers for field types outside the framework taxonomy, keyed by type token. Signature
	 * `(SettingsField $field, mixed $value, string $meta_key): void`.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, \Closure>
	 */
	public array $custom_renderers;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $slug Tab slug; the tab key and the basis of its element id and CSS class.
	 * @param   string $label Tab label shown on the product-data tab.
	 * @param   string $meta_key_prefix Prefix for every field's derived product-meta key.
	 * @param   list<SettingsSection> $sections Sections grouping the tab's fields, in display order.
	 * @param   list<string>|\Closure $classes Extra tab CSS classes, or a `(int $product_id): list<string>` closure for product-type-dependent classes.
	 * @param   int $priority Tab position among the product-data tabs.
	 * @param   ?callable $supports_product Product-type gate; stored as a Closure. Null applies the tab to every recognized product.
	 * @param   array<string, callable> $custom_renderers Renderers for non-taxonomy field types, keyed by type token; stored as Closures.
	 *
	 * @throws  InvalidProductDataTabException If $slug does not match the slug charset.
	 */
	public function __construct(
		public string $slug,
		public string $label,
		public string $meta_key_prefix,
		public array $sections,
		public array|\Closure $classes = array(),
		public int $priority = 65,
		?callable $supports_product = null,
		array $custom_renderers = array(),
	) {
		if ( 1 !== \preg_match( self::SLUG_PATTERN, $slug ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			throw new InvalidProductDataTabException( "Invalid product-data tab slug: '$slug'" );
		}

		$this->supports_product = null !== $supports_product ? \Closure::fromCallable( $supports_product ) : null;
		$this->custom_renderers = \array_map(
			static fn ( callable $renderer ): \Closure => \Closure::fromCallable( $renderer ),
			$custom_renderers,
		);
	}

	// endregion
}
