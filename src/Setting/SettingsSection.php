<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Setting;

use Automattic\WooCommerce\Admin\Settings\SettingsSection as WooCommerceSettingsSection;
use DeepWebSolutions\Framework\Settings\Forms;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * Adds a section of framework field arrays under an existing WooCommerce settings tab, saved through WooCommerce's settings API.
 *
 * The section is constructed in a woocommerce_settings_sections_registration callback and registered with the registry it receives, which refuses an id the tab already holds. It owns its fields after WooCommerce's settings filter, including fields other plugins add there, once WooCommerce first asks it for them.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-import-type Field from Forms
 * @phpstan-type Section array{tab: string, label: string, fields: \Closure(): list<Field>}
 */
final class SettingsSection extends WooCommerceSettingsSection {
	// region FIELDS AND CONSTANTS

	/**
	 * The section's definition, evaluated on first use.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     Section|null
	 */
	protected ?array $section = null;

	/**
	 * The section's fields, built when WooCommerce first asks for them.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<Field>|null
	 */
	protected ?array $fields = null;

	/**
	 * The owner of the section's fields.
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
	 * @param   string              $id         The section's id, which WooCommerce puts in the section's URL.
	 * @param   \Closure(): Section $definition The definition, which is evaluated on first use and must not throw.
	 *
	 * @throws  InvalidArgumentException Thrown when the id is not a valid key.
	 */
	public function __construct(
		protected string $id,
		protected \Closure $definition
	) {
		Forms::check_helper_id( $id );
		$this->owner = new Fields( array( $id => true ) );
	}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function get_parent_page_id(): string {
		return $this->get_section()['tab'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function get_label(): string {
		return $this->get_section()['label'];
	}

	/**
	 * Returns the section's fields, built on first request, and hooks the section to own and save them.
	 *
	 * WooCommerce asks only the section its registry accepted, so a section refused for a taken id never claims the other section's fields.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \WC_Settings_Page $parent_page The tab the section belongs to.
	 *
	 * @return  list<Field>
	 */
	#[\Override]
	public function get_settings( \WC_Settings_Page $parent_page ): array {
		$this->owner->register_hooks( $this->get_parent_page_id() );

		return $this->fields ??= ( $this->get_section()['fields'] )();
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the section's definition, evaluating it on first use.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  Section
	 */
	protected function get_section(): array {
		return $this->section ??= ( $this->definition )();
	}

	// endregion
}
