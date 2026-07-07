<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Backend;

use DeepWebSolutions\Framework\Settings\Backend\SettingsBackendInterface;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\DuplicateSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\DuplicateSettingsSectionException;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\InvalidSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\InvalidSettingsPageException;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsPage;
use DeepWebSolutions\Framework\WooCommerce\Backend\Exceptions\UnsupportedSettingsPageCapabilityException;
use Psr\Log\LoggerInterface;

use function DeepWebSolutions\Framework\Settings\Schema\assert_unique_section_and_field_ids;
use function DeepWebSolutions\Framework\Settings\Schema\is_field_editable_by_current_user;

/**
 * WooCommerce-backed settings backend for a single page.
 *
 * Registers the page as a native WooCommerce settings tab — WooCommerce owns its rendering and saving —
 * and persists each field in its own prefixed wp_options row ({slug}_{field}), the option-per-field shape
 * WooCommerce's save path and REST API expect. Values round-trip as WooCommerce stores them (an off
 * checkbox is the string 'no'), never the boolean false WordPress cannot keep distinct from an absent
 * option. Each field's descriptor sanitizer, and its per-field capability gate, are bridged onto
 * WooCommerce's per-option sanitize filter. The tab is realized through a consumer-declared
 * DescriptorBackedWooCommerceSettingsPage subclass, bound here so WooCommerce can rebuild it by class name across
 * requests.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class WooCommerceSettingsBackend implements SettingsBackendInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * Capability WooCommerce uses for saving settings pages.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	protected const SETTINGS_CAPABILITY = 'manage_woocommerce';

	/**
	 * The registered page; null until register_page() runs.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     ?SettingsPage
	 */
	protected ?SettingsPage $page = null;

	/**
	 * Registered fields keyed by id, for option-key routing and validation.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, SettingsField>
	 */
	protected array $fields = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   class-string<DescriptorBackedWooCommerceSettingsPage> $page_class Consumer subclass that renders the page as a WooCommerce tab.
	 * @param   ?LoggerInterface                                      $logger     Logger for late-registration diagnostics; null silences them.
	 */
	public function __construct(
		protected string $page_class,
		protected ?LoggerInterface $logger = null,
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  InvalidSettingsPageException If the page declares no sections.
	 * @throws  DuplicateSettingsSectionException If two sections on the page share an id.
	 * @throws  DuplicateSettingsFieldException If two fields on the page share an id.
	 * @throws  UnsupportedSettingsPageCapabilityException If the page capability differs from WooCommerce's settings capability.
	 */
	#[\Override]
	public function register_page( SettingsPage $page ): void {
		$this->assert_supported_page_capability( $page );

		// The authoring-time seam: a registered page with zero sections is a permanently blank settings
		// tab, while the render-time capability projection may legitimately empty a page per user.
		if ( array() === $page->sections ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			throw new InvalidSettingsPageException( "Settings page '$page->slug' declares no sections; a registered page must carry at least one section to render." );
		}

		$this->page   = $page;
		$this->fields = $this->map_fields( $page );

		if ( \did_filter( 'woocommerce_get_settings_pages' ) > 0 ) {
			$this->logger?->warning(
				'Settings page registered after woocommerce_get_settings_pages fired; its tab will not appear.',
				array( 'slug' => $page->slug ),
			);
		}

		// Bind and instantiate inside the filter, not here: WooCommerce loads WC_Settings_Page (the page
		// subclass's parent) only when it builds its settings pages, just before applying this filter.
		// Touching the subclass at registration time (plugins_loaded) would fatal on the missing parent.
		\add_filter(
			'woocommerce_get_settings_pages',
			function ( array $pages ) use ( $page ): array {
				DescriptorBackedWooCommerceSettingsPage::bind( $this->page_class, $page );
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
	#[\Override]
	public function get( string $field_id, mixed $default_value = null ): mixed {
		return \get_option( $this->option_key( $field_id ), $default_value );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function set( string $field_id, mixed $value ): void {
		\update_option( $this->option_key( $field_id ), $value, $this->fields[ $field_id ]->autoload );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
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
	#[\Override]
	public function delete( string $field_id ): bool {
		return \delete_option( $this->option_key( $field_id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function option_keys( SettingsPage $page ): array {
		$keys = array();
		foreach ( $page->sections as $section ) {
			foreach ( $section->fields as $field ) {
				$keys[] = $page->slug . '_' . $field->id;
			}
		}

		return $keys;
	}

	// endregion

	// region HELPERS

	/**
	 * Builds the field-id to field map, rejecting a page-duplicate section or field id that would collide on a section anchor or option key.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsPage $page Page whose fields to map.
	 *
	 * @throws  DuplicateSettingsSectionException If two sections on the page share an id.
	 * @throws  DuplicateSettingsFieldException If two fields on the page share an id.
	 *
	 * @return  array<string, SettingsField>
	 */
	protected function map_fields( SettingsPage $page ): array {
		assert_unique_section_and_field_ids( $page );

		$map         = array();
		$section_ids = array();
		foreach ( $page->sections as $section ) {
			$section_ids[ $section->id ] = true;
		}

		foreach ( $page->sections as $section ) {
			foreach ( $section->fields as $field ) {
				if ( \array_key_exists( $field->id, $section_ids ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
					throw new DuplicateSettingsFieldException( "Settings field id collides with a section id on WooCommerce settings page '$page->slug': '$field->id'" );
				}
				$map[ $field->id ] = $field;
			}
		}

		return $map;
	}

	/**
	 * Rejects a page capability WooCommerce cannot honor on save.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsPage $page Page to validate.
	 *
	 * @throws  UnsupportedSettingsPageCapabilityException If the page capability differs from WooCommerce's settings capability.
	 */
	protected function assert_supported_page_capability( SettingsPage $page ): void {
		if ( self::SETTINGS_CAPABILITY === $page->capability ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
		throw new UnsupportedSettingsPageCapabilityException( "WooCommerce settings pages must use the 'manage_woocommerce' capability; page '$page->slug' declares '$page->capability'." );
	}

	/**
	 * Bridges each field's sanitizer and capability gate onto WooCommerce's per-option sanitize filter.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsPage $page Page whose fields' filters to wire.
	 */
	protected function register_field_filters( SettingsPage $page ): void {
		foreach ( $this->fields as $field_id => $field ) {
			if ( null === $field->sanitize && null === $field->capability ) {
				continue;
			}

			$key      = $page->slug . '_' . $field_id;
			$sanitize = $field->sanitize;
			\add_filter(
				'woocommerce_admin_settings_sanitize_option_' . $key,
				static function ( mixed $value ) use ( $key, $sanitize, $field ): mixed {
					// A user without the field's capability cannot change it: keep the stored value, or skip
					// the write (null, which WooCommerce honors) when none is stored, so a protected field
					// the user cannot edit is never created.
					if ( ! is_field_editable_by_current_user( $field ) ) {
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
	protected function option_key( string $field_id ): string {
		if ( null === $this->page || ! \array_key_exists( $field_id, $this->fields ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			throw new InvalidSettingsFieldException( "Settings field '$field_id' is not registered on this page." );
		}

		return $this->page->slug . '_' . $field_id;
	}

	// endregion
}
