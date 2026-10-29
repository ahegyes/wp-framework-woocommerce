<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Setting;

use DeepWebSolutions\Framework\Settings\Forms;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * Adds a WooCommerce settings tab whose sections hold framework field arrays, saved through WooCommerce's settings API.
 *
 * WooCommerce loads its settings page class only for the woocommerce_get_settings_pages filter, so the tab is constructed in that filter's callback, which registers it. The tab owns every field of its sections after WooCommerce's settings filter, including fields other plugins add there. A field's `render` callable takes effect only on a type WooCommerce does not draw, because WooCommerce draws its own types without firing the hook that prints it.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-import-type Field from Forms
 * @phpstan-type Tab array{label: string, sections: array<string, string>}
 */
final class SettingsTab extends \WC_Settings_Page {
	// region FIELDS AND CONSTANTS

	/**
	 * The labels of the tab's sections by id, with the default section under an empty id.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, string>
	 */
	protected array $sections;

	/**
	 * The fields of each section WooCommerce asked for, by section id.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, list<Field>>
	 */
	protected array $fields = array();

	/**
	 * The owner of the fields of the tab's sections.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     Fields
	 */
	protected Fields $owner;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string                           $id         The tab's id, which WooCommerce puts in the tab's URL.
	 * @param   Tab                              $tab        The tab's label and the labels of its sections by id.
	 * @param   SectionFieldsDefinitionInterface $definition The definition of the sections' fields, which the tab asks for once per section, when WooCommerce first asks for them.
	 *
	 * @throws  InvalidArgumentException Thrown when the id is not a valid key.
	 */
	public function __construct(
		string $id,
		array $tab,
		protected SectionFieldsDefinitionInterface $definition
	) {
		Forms::check_helper_id( $id );

		$this->id       = $id;
		$this->label    = $tab['label'];
		$this->sections = $tab['sections'];
		parent::__construct();

		$this->owner = new Fields( $this->sections );
		$this->owner->register_hooks( $id );
	}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  array<string, string>
	 */
	#[\Override]
	protected function get_own_sections(): array {
		return $this->sections;
	}

	/**
	 * Returns the fields of one of the tab's sections, built on first request, or those of a section another plugin registered on the tab.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $section_id The section's id.
	 *
	 * @return  array<mixed>
	 */
	#[\Override]
	protected function get_settings_for_section_core( $section_id ): array {
		$section_id = \is_string( $section_id ) ? $section_id : '';
		if ( ! isset( $this->sections[ $section_id ] ) ) {
			return parent::get_settings_for_section_core( $section_id );
		}

		return $this->fields[ $section_id ] ??= $this->definition->get_section_fields( $this->id, $section_id );
	}

	// endregion
}
