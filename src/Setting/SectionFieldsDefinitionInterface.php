<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Setting;

use DeepWebSolutions\Framework\Settings\Forms;

\defined( 'ABSPATH' ) || exit;

/**
 * Defines the fields of WooCommerce settings sections, which a settings tab or section asks for once per section, when WooCommerce first asks for them.
 *
 * WooCommerce builds every settings page on each admin and REST request, so a section's fields wait until its own screen or save needs them.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-import-type Field from Forms
 */
interface SectionFieldsDefinitionInterface {
	/**
	 * Returns the fields of the section, which must not throw.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $tab_id     The id of the tab that holds the section.
	 * @param   string $section_id The section's id, which is empty for a tab's default section.
	 *
	 * @return  list<Field>
	 */
	public function get_section_fields( string $tab_id, string $section_id ): array;
}
