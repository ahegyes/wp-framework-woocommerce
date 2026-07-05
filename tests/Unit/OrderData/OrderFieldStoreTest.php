<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Unit\OrderData;

use DeepWebSolutions\Framework\Settings\MetaField\ObjectFieldForm;
use DeepWebSolutions\Framework\Settings\MetaField\ValueObjects\FieldGroup;
use DeepWebSolutions\Framework\Settings\Schema\Exceptions\InvalidSettingsFieldException;
use DeepWebSolutions\Framework\Settings\Schema\Field\FieldProcessor;
use DeepWebSolutions\Framework\Settings\Schema\Field\FieldRenderer;
use DeepWebSolutions\Framework\Settings\Schema\Options\OptionsResolver;
use DeepWebSolutions\Framework\Settings\Schema\ValueObjects\SettingsField;
use DeepWebSolutions\Framework\Storage\ObjectMeta\ObjectMetaRepositoryInterface;
use DeepWebSolutions\Framework\WooCommerce\OrderData\OrderFieldStore;
use DeepWebSolutions\Framework\WooCommerce\Tests\Fixtures\InMemoryObjectMetaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesFunction;
use PHPUnit\Framework\TestCase;

#[CoversClass( OrderFieldStore::class )]
#[UsesClass( ObjectFieldForm::class )]
#[UsesClass( FieldGroup::class )]
#[UsesClass( SettingsField::class )]
#[UsesClass( FieldRenderer::class )]
#[UsesClass( FieldProcessor::class )]
#[UsesClass( OptionsResolver::class )]
#[UsesFunction( 'DeepWebSolutions\Framework\Shared\Identifier\is_valid_identifier' )]
#[UsesFunction( 'DeepWebSolutions\Framework\Settings\Schema\is_checkbox_checked' )]
#[UsesFunction( 'DeepWebSolutions\Framework\Settings\Schema\normalize_checkbox_value' )]
#[UsesFunction( 'DeepWebSolutions\Framework\Settings\Schema\wordpress_field_type_sanitizers' )]
final class OrderFieldStoreTest extends TestCase {
	private ObjectMetaRepositoryInterface $repository;
	private OrderFieldStore $store;

	protected function setUp(): void {
		parent::setUp();

		$this->repository = new InMemoryObjectMetaRepository();
		$this->store      = new OrderFieldStore( repository: $this->repository );
	}

	public function test_a_value_round_trips_under_the_resolved_storage_key(): void {
		$group = $this->group( new SettingsField( id: 'note', type: 'text', label: 'Note' ) );

		$this->store->set( $group, 11, 'note', 'hello' );

		self::assertSame( 'hello', $this->store->get( $group, 11, 'note' ) );
		self::assertSame( 'hello', $this->repository->get( 11, 'note' ) );
		self::assertFalse( $this->store->has( $group, 12, 'note' ) );
	}

	public function test_a_meta_key_override_is_the_byte_exact_storage_key(): void {
		$group = $this->group( new SettingsField( id: 'note', type: 'text', label: 'Note', meta_key: '_dws_note' ) );

		$this->store->set( $group, 11, 'note', 'hello' );

		self::assertSame( 'hello', $this->repository->get( 11, '_dws_note' ) );
		self::assertFalse( $this->repository->has( 11, 'note' ) );
	}

	public function test_get_returns_the_caller_fallback_never_the_field_default_when_nothing_is_stored(): void {
		$group = $this->group( new SettingsField( id: 'note', type: 'text', label: 'Note', default_value: 'declared-default' ) );

		self::assertSame( 'fallback', $this->store->get( $group, 11, 'note', 'fallback' ) );
	}

	public function test_set_stores_a_checkbox_in_its_canonical_yes_no_form(): void {
		$group = $this->group( new SettingsField( id: 'flag', type: 'checkbox', label: 'Flag' ) );

		$this->store->set( $group, 11, 'flag', true );
		self::assertSame( 'yes', $this->repository->get( 11, 'flag' ) );

		$this->store->set( $group, 11, 'flag', false );
		self::assertSame( 'no', $this->repository->get( 11, 'flag' ) );
		self::assertTrue( $this->store->has( $group, 11, 'flag' ) );
	}

	public function test_set_revokes_the_key_for_a_value_a_form_save_would_not_store(): void {
		$group = $this->group( new SettingsField( id: 'note', type: 'text', label: 'Note' ) );

		$this->store->set( $group, 11, 'note', 'hello' );
		$this->store->set( $group, 11, 'note', '' );

		self::assertFalse( $this->store->has( $group, 11, 'note' ) );
	}

	public function test_delete_removes_a_stored_value_and_reports_a_missing_one(): void {
		$group = $this->group( new SettingsField( id: 'note', type: 'text', label: 'Note' ) );

		$this->store->set( $group, 11, 'note', 'hello' );

		self::assertTrue( $this->store->delete( $group, 11, 'note' ) );
		self::assertFalse( $this->store->has( $group, 11, 'note' ) );
		self::assertFalse( $this->store->delete( $group, 11, 'note' ) );
	}

	public function test_a_field_the_group_does_not_declare_is_rejected(): void {
		$this->expectException( InvalidSettingsFieldException::class );

		$this->store->get( $this->group( new SettingsField( id: 'note', type: 'text', label: 'Note' ) ), 11, 'missing' );
	}

	public function test_meta_keys_enumerates_the_resolved_storage_keys(): void {
		$group = $this->group(
			new SettingsField( id: 'note', type: 'text', label: 'Note' ),
			new SettingsField( id: 'ref', type: 'text', label: 'Ref', meta_key: '_dws_ref' ),
		);

		self::assertSame( array( 'note', '_dws_ref' ), $this->store->meta_keys( $group ) );
	}

	private function group( SettingsField ...$fields ): FieldGroup {
		$fields = \array_values( $fields );

		return new FieldGroup(
			id: 'dws_order_group',
			title: 'Group',
			fields_provider: static fn ( int $object_id ): array => $fields,
		);
	}
}
