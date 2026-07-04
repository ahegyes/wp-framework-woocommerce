<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Backend;

use DeepWebSolutions\Framework\Settings\Schema\Exceptions\DuplicateSettingsSectionException;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\InvalidSettingsSectionException;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsPage;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsSection;
use DeepWebSolutions\Framework\WooCommerce\Backend\Exceptions\UnboundSettingsPageException;

use function DeepWebSolutions\Framework\Settings\Schema\is_field_editable_by_current_user;

/**
 * Base for a per-page WooCommerce settings tab backed by a framework settings descriptor.
 *
 * A consumer declares one empty final subclass per page; the backend binds that subclass to its
 * SettingsPage descriptor. WooCommerce rebuilds settings-page objects on each request and recovers
 * them by class name on its save path, so the descriptor lives in a static map keyed by the concrete
 * subclass. A distinct class per page is what keeps two plugins' pages from colliding on that map.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
abstract class DescriptorBackedWCSettingsPage extends \WC_Settings_Page {
	// region FIELDS AND CONSTANTS

	/**
	 * Descriptors keyed by the concrete subclass that renders them.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<class-string<self>, SettingsPage>
	 */
	protected static array $descriptors = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  UnboundSettingsPageException If no descriptor is bound to the concrete subclass.
	 */
	final public function __construct() {
		$descriptor = $this->descriptor();

		// WooCommerce wires its hooks from $this->id and routes the settings screen by
		// sanitize_title($_GET['tab']), so the tab id must be slug-stable; set it before the parent reads it.
		$this->id    = \sanitize_title( $descriptor->location ?? $descriptor->slug );
		$this->label = $descriptor->menu_title;

		parent::__construct();
	}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * WooCommerce treats the empty section id as the page's default section; the descriptor's first
	 * editable section maps there, and each later section maps to its native WooCommerce section id.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $section_id Section being rendered.
	 *
	 * @return  list<array<string, mixed>>
	 */
	#[\Override]
	protected function get_settings_for_section_core( $section_id ): array {
		$page    = $this->editable_page();
		$section = $this->section_for_woocommerce_section( $page, (string) $section_id );

		if ( null === $section ) {
			return array();
		}

		return ( new WCSettingsBuilder() )->build_section( $page, $section );
	}

	/**
	 * {@inheritDoc}
	 *
	 * WooCommerce requires one default section keyed by an empty string; use the descriptor's first editable
	 * section for that default and expose the rest as sub-tabs keyed by WooCommerce's sanitized section id.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  array<string, string>
	 */
	#[\Override]
	protected function get_own_sections(): array {
		$sections = array();
		foreach ( $this->editable_page()->sections as $index => $section ) {
			$sections[ 0 === $index ? '' : \sanitize_title( $section->id ) ] = $section->title;
		}

		return $sections;
	}

	// endregion

	// region METHODS

	/**
	 * Binds a descriptor to a concrete page subclass.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   class-string<self> $page_class Concrete subclass that renders the page.
	 * @param   SettingsPage       $page       Descriptor to recover when that subclass is instantiated.
	 *
	 * @throws  InvalidSettingsSectionException If a non-default section's id sanitizes to WooCommerce's empty default-section token.
	 * @throws  DuplicateSettingsSectionException If two non-default sections collide on WooCommerce's sanitized section id.
	 */
	public static function bind( string $page_class, SettingsPage $page ): void {
		self::assert_distinct_woocommerce_section_ids( $page );
		self::$descriptors[ $page_class ] = $page;
	}

	// endregion

	// region HELPERS

	/**
	 * Recovers the descriptor bound to the concrete subclass.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  UnboundSettingsPageException If no descriptor is bound to the concrete subclass.
	 *
	 * @return  SettingsPage
	 */
	protected function descriptor(): SettingsPage {
		return self::$descriptors[ static::class ]
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			?? throw new UnboundSettingsPageException( 'No settings descriptor is bound to ' . static::class . '.' );
	}

	/**
	 * Returns the descriptor reduced to the fields the current user may edit.
	 *
	 * A field gated by a capability the current user lacks is dropped before rendering, so WooCommerce
	 * neither shows nor saves it; a section left with no editable fields is dropped whole.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  SettingsPage
	 */
	protected function editable_page(): SettingsPage {
		$descriptor = $this->descriptor();

		$sections = array();
		foreach ( $descriptor->sections as $section ) {
			$fields = array();
			foreach ( $section->fields as $field ) {
				if ( is_field_editable_by_current_user( $field ) ) {
					$fields[] = $field;
				}
			}

			if ( array() !== $fields ) {
				$sections[] = new SettingsSection( $section->id, $section->title, $fields );
			}
		}

		return new SettingsPage(
			slug: $descriptor->slug,
			page_title: $descriptor->page_title,
			menu_title: $descriptor->menu_title,
			capability: $descriptor->capability,
			location: $descriptor->location,
			sections: $sections,
		);
	}

	/**
	 * Resolves WooCommerce's native section id back to the descriptor section it represents.
	 *
	 * The first (default) section answers only to WooCommerce's empty-string default token —
	 * never to its own sanitized id — so it can never shadow a later section; later sections
	 * match their sanitized id.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsPage $page       Editable page descriptor.
	 * @param   string       $section_id WooCommerce section id.
	 *
	 * @return  SettingsSection|null
	 */
	protected function section_for_woocommerce_section( SettingsPage $page, string $section_id ): ?SettingsSection {
		foreach ( $page->sections as $index => $section ) {
			if ( 0 === $index ) {
				if ( '' === $section_id ) {
					return $section;
				}

				continue;
			}

			if ( \sanitize_title( $section->id ) === $section_id ) {
				return $section;
			}
		}

		return null;
	}

	/**
	 * Rejects section ids WooCommerce's sanitized routing cannot address distinctly.
	 *
	 * WooCommerce addresses a section by sanitize_title() of its id, and the first (default)
	 * section only by the empty string. A later section whose sanitized id is empty or collides
	 * with a sibling's would silently render and save the wrong fields, so both fail at binding.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   SettingsPage $page Descriptor whose sections to validate.
	 *
	 * @throws  InvalidSettingsSectionException If a non-default section's id sanitizes to WooCommerce's empty default-section token.
	 * @throws  DuplicateSettingsSectionException If two non-default sections collide on WooCommerce's sanitized section id.
	 */
	protected static function assert_distinct_woocommerce_section_ids( SettingsPage $page ): void {
		$seen = array();
		foreach ( \array_slice( $page->sections, 1 ) as $section ) {
			$woocommerce_id = \sanitize_title( $section->id );

			if ( '' === $woocommerce_id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
				throw new InvalidSettingsSectionException( "Settings section id sanitizes to WooCommerce's default-section token on page '$page->slug': '$section->id'" );
			}

			if ( isset( $seen[ $woocommerce_id ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
				throw new DuplicateSettingsSectionException( "Settings sections collide on WooCommerce's sanitized section id '$woocommerce_id' on page '$page->slug': '$section->id'" );
			}

			$seen[ $woocommerce_id ] = true;
		}
	}

	// endregion
}
