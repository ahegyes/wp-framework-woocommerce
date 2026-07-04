<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Unit;

use DeepWebSolutions\Framework\Settings\Schema\Field\FieldType;
use DeepWebSolutions\Framework\Settings\Schema\Options\OptionsResolver;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\WooCommerce\ProductData\ProductDataFieldRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesFunction;
use PHPUnit\Framework\TestCase;

#[CoversClass( ProductDataFieldRenderer::class )]
#[UsesClass( SettingsField::class )]
#[UsesClass( OptionsResolver::class )]
#[UsesClass( FieldType::class )]
#[UsesFunction( 'DeepWebSolutions\Framework\Settings\Schema\filter_field_attributes' )]
#[UsesFunction( 'DeepWebSolutions\Framework\Settings\Schema\is_checkbox_checked' )]
#[UsesFunction( 'DeepWebSolutions\Framework\Settings\Schema\is_valid_identifier' )]
#[UsesFunction( 'DeepWebSolutions\Framework\Settings\Schema\stringify_for_output' )]
#[UsesFunction( 'DeepWebSolutions\Framework\WooCommerce\to_yes_no' )]
final class ProductDataFieldRendererTest extends TestCase {
	public function test_text_args_carry_id_name_label_value_and_type(): void {
		$field = new SettingsField( id: 'store', type: 'text', label: 'Store' );

		$args = ( new ProductDataFieldRenderer() )->args( $field, FieldType::Text, 'Acme', '_p_general_store' );

		self::assertSame( '_p_general_store', $args['id'] );
		self::assertSame( '_p_general_store', $args['name'] );
		self::assertSame( 'Store', $args['label'] );
		self::assertSame( 'Acme', $args['value'] );
		self::assertSame( 'text', $args['type'] );
	}

	public function test_number_email_and_url_carry_their_input_type(): void {
		$renderer = new ProductDataFieldRenderer();

		self::assertSame( 'number', $renderer->args( new SettingsField( id: 'n', type: 'number', label: 'N' ), FieldType::Number, 1, 'n' )['type'] );
		self::assertSame( 'email', $renderer->args( new SettingsField( id: 'e', type: 'email', label: 'E' ), FieldType::Email, '', 'e' )['type'] );
		self::assertSame( 'url', $renderer->args( new SettingsField( id: 'u', type: 'url', label: 'U' ), FieldType::Url, '', 'u' )['type'] );
	}

	public function test_a_description_maps_to_a_tooltip(): void {
		$field = new SettingsField( id: 'x', type: 'text', label: 'X', description: 'Helpful.' );

		$args = ( new ProductDataFieldRenderer() )->args( $field, FieldType::Text, '', 'x' );

		self::assertSame( 'Helpful.', $args['description'] );
		self::assertTrue( $args['desc_tip'] );
	}

	public function test_no_description_omits_the_description_and_desc_tip_keys(): void {
		$args = ( new ProductDataFieldRenderer() )->args( new SettingsField( id: 'x', type: 'text', label: 'X' ), FieldType::Text, '', 'x' );

		self::assertArrayNotHasKey( 'description', $args );
		self::assertArrayNotHasKey( 'desc_tip', $args );
	}

	public function test_a_truthy_checkbox_value_maps_to_yes(): void {
		$renderer = new ProductDataFieldRenderer();
		$field    = new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag' );

		self::assertSame( 'yes', $renderer->args( $field, FieldType::Checkbox, true, 'flag' )['value'] );
		self::assertSame( 'yes', $renderer->args( $field, FieldType::Checkbox, 'yes', 'flag' )['value'] );
		self::assertSame( 'yes', $renderer->args( $field, FieldType::Checkbox, '1', 'flag' )['value'] );
		self::assertSame( 'yes', $renderer->args( $field, FieldType::Checkbox, 1, 'flag' )['value'] );
	}

	public function test_a_falsy_checkbox_value_maps_to_no(): void {
		$renderer = new ProductDataFieldRenderer();
		$field    = new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag' );

		self::assertSame( 'no', $renderer->args( $field, FieldType::Checkbox, false, 'flag' )['value'] );
		self::assertSame( 'no', $renderer->args( $field, FieldType::Checkbox, 'no', 'flag' )['value'] );
		self::assertSame( 'no', $renderer->args( $field, FieldType::Checkbox, '', 'flag' )['value'] );
	}

	public function test_a_select_carries_resolved_stringified_options(): void {
		$field = new SettingsField(
			id: 'gw',
			type: 'select',
			label: 'Gateway',
			options: array(
				'stripe' => 'Stripe',
				1        => 100,
			)
		);

		$args = ( new ProductDataFieldRenderer() )->args( $field, FieldType::Select, 'stripe', 'gw' );

		self::assertSame(
			array(
				'stripe' => 'Stripe',
				1        => '100',
			),
			$args['options']
		);
		self::assertSame( 'stripe', $args['value'] );
	}

	public function test_a_select_with_closure_options_is_resolved(): void {
		$field = new SettingsField( id: 'r', type: 'select', label: 'R', options: static fn (): array => array( 'a' => 'A' ) );

		$args = ( new ProductDataFieldRenderer() )->args( $field, FieldType::Select, 'a', 'r' );

		self::assertSame( array( 'a' => 'A' ), $args['options'] );
	}

	public function test_a_multiselect_appends_brackets_to_the_name_and_marks_it_multiple(): void {
		$field = new SettingsField(
			id: 'tags',
			type: 'multiselect',
			label: 'Tags',
			options: array(
				'a' => 'A',
				'b' => 'B',
			)
		);

		$args = ( new ProductDataFieldRenderer() )->args( $field, FieldType::Multiselect, array( 'a', 'b' ), '_p_tags' );

		self::assertSame( '_p_tags[]', $args['name'] );
		self::assertSame( array( 'a', 'b' ), $args['value'] );
		self::assertSame(
			array(
				'a' => 'A',
				'b' => 'B',
			),
			$args['options']
		);
		self::assertSame( 'multiple', $args['custom_attributes']['multiple'] );
	}

	public function test_a_non_array_multiselect_value_becomes_an_empty_selection(): void {
		$field = new SettingsField( id: 'tags', type: 'multiselect', label: 'Tags', options: array( 'a' => 'A' ) );

		$args = ( new ProductDataFieldRenderer() )->args( $field, FieldType::Multiselect, 'oops', '_p_tags' );

		self::assertSame( array(), $args['value'] );
	}

	public function test_a_radio_carries_options_and_value(): void {
		$field = new SettingsField(
			id: 'size',
			type: 'radio',
			label: 'Size',
			options: array(
				's' => 'Small',
				'l' => 'Large',
			)
		);

		$args = ( new ProductDataFieldRenderer() )->args( $field, FieldType::Radio, 'l', 'size' );

		self::assertSame(
			array(
				's' => 'Small',
				'l' => 'Large',
			),
			$args['options']
		);
		self::assertSame( 'l', $args['value'] );
	}

	public function test_safe_custom_attributes_pass_through(): void {
		$field = new SettingsField(
			id: 'qty',
			type: 'number',
			label: 'Qty',
			attributes: array(
				'min'  => '0',
				'step' => '1',
			)
		);

		$args = ( new ProductDataFieldRenderer() )->args( $field, FieldType::Number, 1, 'qty' );

		self::assertSame(
			array(
				'min'  => '0',
				'step' => '1',
			),
			$args['custom_attributes']
		);
	}

	public function test_event_handler_attributes_are_filtered_out(): void {
		$field = new SettingsField(
			id: 'x',
			type: 'text',
			label: 'X',
			attributes: array(
				'onclick' => 'evil()',
				'1bad'    => 'x',
				'data-ok' => 'y',
			)
		);

		$args = ( new ProductDataFieldRenderer() )->args( $field, FieldType::Text, '', 'x' );

		self::assertSame( array( 'data-ok' => 'y' ), $args['custom_attributes'] );
	}

	public function test_a_mixed_case_attribute_name_is_kept(): void {
		$field = new SettingsField( id: 'x', type: 'text', label: 'X', attributes: array( 'data-Foo' => 'bar' ) );

		$args = ( new ProductDataFieldRenderer() )->args( $field, FieldType::Text, '', 'x' );

		self::assertSame( array( 'data-Foo' => 'bar' ), $args['custom_attributes'] );
	}

	public function test_custom_attributes_are_omitted_when_empty(): void {
		$args = ( new ProductDataFieldRenderer() )->args( new SettingsField( id: 'x', type: 'text', label: 'X' ), FieldType::Text, '', 'x' );

		self::assertArrayNotHasKey( 'custom_attributes', $args );
	}
}
