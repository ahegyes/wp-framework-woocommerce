<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Backend;

use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsPage;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsSection;
use DeepWebSolutions\Framework\WooCommerce\Exceptions\UnboundSettingsPageException;

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
	private static array $descriptors = array();

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
	 * The framework places every section on WooCommerce's default section as a title/sectionend group,
	 * so only the default section carries fields.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $section_id Section being rendered.
	 *
	 * @return  list<array<string, mixed>>
	 */
	protected function get_settings_for_section_core( $section_id ): array {
		if ( '' !== $section_id ) {
			return array();
		}

		return ( new WCSettingsBuilder() )->build( $this->editable_page() );
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
	 */
	public static function bind( string $page_class, SettingsPage $page ): void {
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
	private function descriptor(): SettingsPage {
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
	private function editable_page(): SettingsPage {
		$descriptor = $this->descriptor();

		$sections = array();
		foreach ( $descriptor->sections as $section ) {
			$fields = array();
			foreach ( $section->fields as $field ) {
				if ( null === $field->capability || \current_user_can( $field->capability ) ) {
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

	// endregion
}
