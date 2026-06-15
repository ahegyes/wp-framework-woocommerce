<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce;

use DeepWebSolutions\Framework\Settings\Exceptions\DuplicateSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Exceptions\InvalidSettingsFieldException;
use DeepWebSolutions\Framework\Settings\SettingsBackendInterface;
use DeepWebSolutions\Framework\Settings\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\ValueObjects\SettingsPage;

/**
 * WooCommerce-backed settings backend for a single page.
 *
 * Registers the page as a native WooCommerce settings tab — WooCommerce owns its rendering and saving —
 * and persists each field in its own prefixed wp_options row ({slug}_{field}), the option-per-field shape
 * WooCommerce's save path and REST API expect. Values round-trip as WooCommerce stores them (an off
 * checkbox is the string 'no'), never the boolean false WordPress cannot keep distinct from an absent
 * option. Each field's descriptor sanitizer, and its per-field capability gate, are bridged onto
 * WooCommerce's per-option sanitize filter. The tab is realized through a consumer-declared
 * DescriptorBackedWCSettingsPage subclass, bound here so WooCommerce can rebuild it by class name across
 * requests.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class WooCommerceSettingsBackend implements SettingsBackendInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The registered page; null until register_page() runs.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     ?SettingsPage
	 */
	private ?SettingsPage $page = null;

	/**
	 * Registered fields keyed by id, for option-key routing and validation.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, SettingsField>
	 */
	private array $fields = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   class-string<DescriptorBackedWCSettingsPage> $page_class Consumer subclass that renders the page as a WooCommerce tab.
	 */
	public function __construct(
		private string $page_class,
	) {}

	// endregion

	// region METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  DuplicateSettingsFieldException If two fields on the page share an id.
	 */
	public function register_page( SettingsPage $page ): void {
		$this->page   = $page;
		$this->fields = $this->map_fields( $page );

		// Bind and instantiate inside the filter, not here: WooCommerce loads WC_Settings_Page (the page
		// subclass's parent) only when it builds its settings pages, just before applying this filter.
		// Touching the subclass at registration time (plugins_loaded) would fatal on the missing parent.
		\add_filter(
			'woocommerce_get_settings_pages',
			function ( array $pages ) use ( $page ): array {
				DescriptorBackedWCSettingsPage::bind( $this->page_class, $page );
				$pages[] = new $this->page_class();

				return $pages;
			},
		);

		$this->register_field_filters( $page );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function get( string $field_id, mixed $default_value = null ): mixed {
		return \get_option( $this->option_key( $field_id ), $default_value );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function set( string $field_id, mixed $value ): void {
		\update_option( $this->option_key( $field_id ), $value );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function has( string $field_id ): bool {
		$sentinel = new \stdClass();

		return \get_option( $this->option_key( $field_id ), $sentinel ) !== $sentinel;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function delete( string $field_id ): bool {
		return \delete_option( $this->option_key( $field_id ) );
	}

	// endregion

	// region HELPERS

	/**
	 * Builds the field-id to field map, rejecting a page-duplicate field id that would collide on its option key.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsPage $page Page whose fields to map.
	 *
	 * @throws  DuplicateSettingsFieldException If two fields on the page share an id.
	 *
	 * @return  array<string, SettingsField>
	 */
	private function map_fields( SettingsPage $page ): array {
		$map = array();
		foreach ( $page->sections as $section ) {
			foreach ( $section->fields as $field ) {
				if ( \array_key_exists( $field->id, $map ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
					throw new DuplicateSettingsFieldException( "Duplicate settings field id on page: '$field->id'" );
				}
				$map[ $field->id ] = $field;
			}
		}

		return $map;
	}

	/**
	 * Bridges each field's sanitizer and capability gate onto WooCommerce's per-option sanitize filter.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsPage $page Page whose fields' filters to wire.
	 */
	private function register_field_filters( SettingsPage $page ): void {
		foreach ( $this->fields as $field_id => $field ) {
			if ( null === $field->sanitize && null === $field->capability ) {
				continue;
			}

			$key        = $page->slug . '_' . $field_id;
			$sanitize   = $field->sanitize;
			$capability = $field->capability;
			\add_filter(
				'woocommerce_admin_settings_sanitize_option_' . $key,
				static function ( mixed $value ) use ( $key, $sanitize, $capability ): mixed {
					// A user without the field's capability cannot change it: keep the stored value, or skip
					// the write (null, which WooCommerce honors) when none is stored, so a protected field
					// the user cannot edit is never created.
					if ( null !== $capability && ! \current_user_can( $capability ) ) {
						$sentinel = new \stdClass();
						$stored   = \get_option( $key, $sentinel );

						return $sentinel === $stored ? null : $stored;
					}

					return null !== $sanitize ? $sanitize( $value ) : $value;
				},
			);
		}
	}

	/**
	 * Resolves a field's WooCommerce option key, rejecting a field not registered on the page.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $field_id Field to resolve a key for.
	 *
	 * @throws  InvalidSettingsFieldException If the field is not registered on this page.
	 *
	 * @return  string
	 */
	private function option_key( string $field_id ): string {
		if ( null === $this->page || ! \array_key_exists( $field_id, $this->fields ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			throw new InvalidSettingsFieldException( "Settings field '$field_id' is not registered on this page." );
		}

		return $this->page->slug . '_' . $field_id;
	}

	// endregion
}
