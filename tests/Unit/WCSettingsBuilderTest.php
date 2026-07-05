<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Unit;

use DeepWebSolutions\Framework\Settings\Schema\Options\OptionsResolver;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsPage;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsSection;
use DeepWebSolutions\Framework\WooCommerce\Backend\WCSettingsBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesFunction;
use PHPUnit\Framework\TestCase;

#[CoversClass( WCSettingsBuilder::class )]
#[UsesClass( OptionsResolver::class )]
#[UsesClass( SettingsField::class )]
#[UsesClass( SettingsPage::class )]
#[UsesClass( SettingsSection::class )]
#[UsesFunction( 'DeepWebSolutions\Framework\Settings\Schema\filter_field_attributes' )]
#[UsesFunction( 'DeepWebSolutions\Framework\Settings\Schema\is_checkbox_checked' )]
#[UsesFunction( 'DeepWebSolutions\Framework\Shared\Identifier\is_valid_identifier' )]
#[UsesFunction( 'DeepWebSolutions\Framework\Settings\Schema\stringify_for_output' )]
#[UsesFunction( 'DeepWebSolutions\Framework\WooCommerce\to_yes_no' )]
final class WCSettingsBuilderTest extends TestCase {
	public function test_emits_a_title_fields_sectionend_sequence_per_section(): void {
		$built = ( new WCSettingsBuilder() )->build( $this->page() );

		$shape = \array_map(
			static fn ( array $row ): array => array( $row['type'], $row['id'] ?? null ),
			$built,
		);

		self::assertSame(
			array(
				array( 'title', 'dws-shop_general' ),
				array( 'text', 'dws-shop_store_name' ),
				array( 'select', 'dws-shop_gateway' ),
				array( 'sectionend', 'dws-shop_general' ),
				array( 'title', 'dws-shop_advanced' ),
				array( 'checkbox', 'dws-shop_debug' ),
				array( 'sectionend', 'dws-shop_advanced' ),
			),
			$shape,
		);
	}

	public function test_a_section_title_row_carries_the_section_title(): void {
		$built = ( new WCSettingsBuilder() )->build( $this->page() );

		self::assertSame( 'General', $built[0]['title'] );
		self::assertSame( 'Advanced', $built[4]['title'] );
	}

	public function test_build_section_emits_only_that_sections_title_fields_and_sectionend(): void {
		$page  = $this->page();
		$built = ( new WCSettingsBuilder() )->build_section( $page, $page->sections[1] );

		$shape = \array_map(
			static fn ( array $row ): array => array( $row['type'], $row['id'] ?? null ),
			$built,
		);

		self::assertSame(
			array(
				array( 'title', 'dws-shop_advanced' ),
				array( 'checkbox', 'dws-shop_debug' ),
				array( 'sectionend', 'dws-shop_advanced' ),
			),
			$shape,
		);
	}

	public function test_a_field_row_carries_type_label_and_default(): void {
		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $this->page() ), 'dws-shop_store_name' );

		self::assertSame( 'text', $row['type'] );
		self::assertSame( 'Store Name', $row['title'] );
		self::assertSame( 'Acme', $row['default'] );
	}

	public function test_a_null_default_is_omitted(): void {
		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $this->page() ), 'dws-shop_debug' );

		self::assertArrayNotHasKey( 'default', $row );
	}

	public function test_a_field_description_is_emitted_as_the_woocommerce_desc(): void {
		$page = $this->page_with_field(
			new SettingsField( id: 'tagline', type: 'text', label: 'Tagline', description: 'Shown under the field.' ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_tagline' );

		self::assertSame( 'Shown under the field.', $row['desc'] );
	}

	public function test_a_field_row_carries_an_explicit_off_autoload_flag_by_default(): void {
		// WooCommerce defaults a settings option to autoloaded when the entry omits 'autoload', so the
		// builder always emits it to keep framework settings out of alloptions unless a field opts in.
		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $this->page() ), 'dws-shop_store_name' );

		self::assertFalse( $row['autoload'] );
	}

	public function test_a_field_opting_into_autoload_emits_a_truthy_autoload_flag(): void {
		$page = $this->page_with_field(
			new SettingsField( id: 'cache', type: 'text', label: 'Cache', autoload: true ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_cache' );

		self::assertTrue( $row['autoload'] );
	}

	public function test_resolved_options_are_included_for_a_choice_field(): void {
		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $this->page() ), 'dws-shop_gateway' );

		self::assertSame(
			array(
				'stripe' => 'Stripe',
				'paypal' => 'PayPal',
			),
			$row['options']
		);
	}

	public function test_options_are_omitted_for_a_field_without_any(): void {
		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $this->page() ), 'dws-shop_store_name' );

		self::assertArrayNotHasKey( 'options', $row );
	}

	public function test_options_from_a_closure_are_resolved(): void {
		$page = $this->page_with_field(
			new SettingsField(
				id: 'role',
				type: 'select',
				label: 'Role',
				options: static fn (): array => array( 'admin' => 'Admin' ),
			),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_role' );

		self::assertSame( array( 'admin' => 'Admin' ), $row['options'] );
	}

	public function test_custom_attributes_are_included_when_present(): void {
		$page = $this->page_with_field(
			new SettingsField(
				id: 'qty',
				type: 'number',
				label: 'Quantity',
				attributes: array(
					'min'  => '0',
					'step' => '1',
				),
			),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_qty' );

		self::assertSame(
			array(
				'min'  => '0',
				'step' => '1',
			),
			$row['custom_attributes']
		);
	}

	public function test_custom_attributes_are_omitted_when_empty(): void {
		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $this->page() ), 'dws-shop_store_name' );

		self::assertArrayNotHasKey( 'custom_attributes', $row );
	}

	public function test_a_truthy_checkbox_default_maps_to_the_wc_yes_string(): void {
		$page = $this->page_with_field(
			new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag', default_value: true ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_flag' );

		self::assertSame( 'yes', $row['default'] );
	}

	public function test_a_falsy_checkbox_default_maps_to_the_wc_no_string(): void {
		$page = $this->page_with_field(
			new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag', default_value: false ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_flag' );

		self::assertSame( 'no', $row['default'] );
	}

	public function test_a_truthy_non_boolean_checkbox_default_maps_to_the_wc_yes_string(): void {
		$page = $this->page_with_field(
			new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag', default_value: 1 ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_flag' );

		self::assertSame( 'yes', $row['default'] );
	}

	public function test_a_string_yes_checkbox_default_maps_to_the_wc_yes_string(): void {
		$page = $this->page_with_field(
			new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag', default_value: 'yes' ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_flag' );

		self::assertSame( 'yes', $row['default'] );
	}

	public function test_a_string_no_checkbox_default_maps_to_the_wc_no_string(): void {
		// Canonical rule: 'no' is unchecked. The superseded (bool) cast mapped the truthy 'no' to 'yes'.
		$page = $this->page_with_field(
			new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag', default_value: 'no' ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_flag' );

		self::assertSame( 'no', $row['default'] );
	}

	public function test_an_arbitrary_string_checkbox_default_maps_to_the_wc_no_string(): void {
		// Canonical rule: any non-canonical string is unchecked. The superseded (bool) cast mapped it to 'yes'.
		$page = $this->page_with_field(
			new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag', default_value: 'anything' ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_flag' );

		self::assertSame( 'no', $row['default'] );
	}

	public function test_non_string_option_labels_are_stringified(): void {
		$page = $this->page_with_field(
			new SettingsField(
				id: 'amount',
				type: 'select',
				label: 'Amount',
				options: array(
					1 => 100,
					2 => 'Two',
				)
			),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_amount' );

		self::assertSame(
			array(
				1 => '100',
				2 => 'Two',
			),
			$row['options']
		);
	}

	public function test_a_non_scalar_option_label_becomes_an_empty_string(): void {
		$page = $this->page_with_field(
			new SettingsField( id: 'choice', type: 'select', label: 'Choice', options: array( 'a' => array( 'nested' ) ) ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_choice' );

		self::assertSame( array( 'a' => '' ), $row['options'] );
	}

	public function test_invalid_and_event_handler_attribute_names_are_filtered_out(): void {
		// The invalid names precede the valid one so a skip that fell through to break would drop it too.
		$page = $this->page_with_field(
			new SettingsField(
				id: 'qty',
				type: 'number',
				label: 'Quantity',
				attributes: array(
					'onclick' => 'evil()',
					'1bad'    => 'x',
					'min'     => '0',
				),
			),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_qty' );

		self::assertSame( array( 'min' => '0' ), $row['custom_attributes'] );
	}

	public function test_a_mixed_case_attribute_name_is_kept(): void {
		$page = $this->page_with_field(
			new SettingsField( id: 'x', type: 'text', label: 'X', attributes: array( 'data-Foo' => 'bar' ) ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_x' );

		self::assertSame( array( 'data-Foo' => 'bar' ), $row['custom_attributes'] );
	}

	public function test_custom_attributes_are_omitted_when_every_attribute_is_filtered_out(): void {
		$page = $this->page_with_field(
			new SettingsField( id: 'x', type: 'text', label: 'X', attributes: array( 'onmouseover' => 'evil()' ) ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_x' );

		self::assertArrayNotHasKey( 'custom_attributes', $row );
	}

	public function test_a_choice_field_with_no_options_still_emits_an_empty_options_array(): void {
		$page = $this->page_with_field( new SettingsField( id: 'sel', type: 'select', label: 'Sel' ) );

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_sel' );

		self::assertArrayHasKey( 'options', $row );
		self::assertSame( array(), $row['options'] );
	}

	public function test_options_are_not_emitted_for_a_non_choice_field(): void {
		$page = $this->page_with_field(
			new SettingsField( id: 'n', type: 'number', label: 'N', options: array( 'a' => 'A' ) ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_n' );

		self::assertArrayNotHasKey( 'options', $row );
	}

	public function test_multiselect_default_values_are_stringified(): void {
		$page = $this->page_with_field(
			new SettingsField(
				id: 'tags',
				type: 'multiselect',
				label: 'Tags',
				default_value: array( 1, 2 ),
				options: array(
					1 => 'One',
					2 => 'Two',
				)
			),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_tags' );

		self::assertSame( array( '1', '2' ), $row['default'] );
	}

	public function test_a_non_array_multiselect_default_becomes_an_empty_array(): void {
		$page = $this->page_with_field(
			new SettingsField( id: 'tags', type: 'multiselect', label: 'Tags', default_value: 'oops', options: array( 'a' => 'A' ) ),
		);

		$row = $this->row_by_id( ( new WCSettingsBuilder() )->build( $page ), 'dws-shop_tags' );

		self::assertSame( array(), $row['default'] );
	}

	public function test_a_page_without_sections_builds_an_empty_array(): void {
		$page = new SettingsPage(
			slug: 'dws-shop',
			page_title: 'DWS Shop',
			menu_title: 'DWS Shop',
			capability: 'manage_woocommerce',
		);

		self::assertSame( array(), ( new WCSettingsBuilder() )->build( $page ) );
	}

	private function page(): SettingsPage {
		return new SettingsPage(
			slug: 'dws-shop',
			page_title: 'DWS Shop',
			menu_title: 'DWS Shop',
			capability: 'manage_woocommerce',
			location: 'dws_shop',
			sections: array(
				new SettingsSection(
					'general',
					'General',
					array(
						new SettingsField( id: 'store_name', type: 'text', label: 'Store Name', default_value: 'Acme' ),
						new SettingsField(
							id: 'gateway',
							type: 'select',
							label: 'Gateway',
							options: array(
								'stripe' => 'Stripe',
								'paypal' => 'PayPal',
							)
						),
					),
				),
				new SettingsSection(
					'advanced',
					'Advanced',
					array(
						new SettingsField( id: 'debug', type: 'checkbox', label: 'Debug' ),
					),
				),
			),
		);
	}

	private function page_with_field( SettingsField $field ): SettingsPage {
		return new SettingsPage(
			slug: 'dws-shop',
			page_title: 'DWS Shop',
			menu_title: 'DWS Shop',
			capability: 'manage_woocommerce',
			sections: array( new SettingsSection( 'general', 'General', array( $field ) ) ),
		);
	}

	/**
	 * @param  list<array<string, mixed>> $built
	 * @return array<string, mixed>
	 */
	private function row_by_id( array $built, string $id ): array {
		foreach ( $built as $row ) {
			if ( ( $row['id'] ?? null ) === $id ) {
				return $row;
			}
		}

		self::fail( "No built row with id '$id'." );
	}
}
