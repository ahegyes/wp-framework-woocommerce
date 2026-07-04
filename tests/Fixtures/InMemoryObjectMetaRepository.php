<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Fixtures;

use DeepWebSolutions\Framework\Settings\MetaField\ObjectMetaRepositoryInterface;

/**
 * In-memory object-meta repository for unit tests: per-object key/value maps, presence tracked with
 * array_key_exists so a stored null stays distinct from an absent key.
 */
final class InMemoryObjectMetaRepository implements ObjectMetaRepositoryInterface {
	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $data = array();

	public function get( int $object_id, string $meta_key, mixed $default_value = null ): mixed {
		return \array_key_exists( $meta_key, $this->data[ $object_id ] ?? array() )
			? $this->data[ $object_id ][ $meta_key ]
			: $default_value;
	}

	public function set( int $object_id, string $meta_key, mixed $value ): void {
		$this->data[ $object_id ][ $meta_key ] = $value;
	}

	public function has( int $object_id, string $meta_key ): bool {
		return \array_key_exists( $meta_key, $this->data[ $object_id ] ?? array() );
	}

	public function delete( int $object_id, string $meta_key ): bool {
		if ( ! $this->has( $object_id, $meta_key ) ) {
			return false;
		}
		unset( $this->data[ $object_id ][ $meta_key ] );

		return true;
	}

	public function apply( int $object_id, array $sets, array $deletes ): void {
		foreach ( $sets as $meta_key => $value ) {
			$this->set( $object_id, (string) $meta_key, $value );
		}
		foreach ( $deletes as $meta_key ) {
			$this->delete( $object_id, $meta_key );
		}
	}
}
