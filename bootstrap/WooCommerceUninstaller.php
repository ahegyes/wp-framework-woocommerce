<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Bootstrap;

\defined( 'ABSPATH' ) || exit;

/**
 * Returns the uninstall handlers that remove a plugin's WooCommerce order meta and order item meta, with WooCommerce loaded or not.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class WooCommerceUninstaller {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	protected function __construct() {}

	// endregion

	// region METHODS

	/**
	 * Returns the handlers for the order_meta, order_meta_prefixes and order_item_meta footprint keys, keyed by footprint key.
	 *
	 * Each handler acts on site passes only, because order data belongs to a site, and logs and skips a value that is not a list of non-empty strings, because an empty prefix would match every row. None needs WooCommerce, because plugins are often deleted after WooCommerce is deactivated.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  array<string, \Closure(mixed, array<string, mixed>): void>
	 */
	public static function get_handlers(): array {
		return array(
			'order_meta'          => static function ( $keys, array $context ): void {
				$keys = self::read_values( 'order_meta', $keys, $context );
				if ( null !== $keys ) {
					self::delete_order_meta( $keys );
				}
			},
			'order_meta_prefixes' => static function ( $prefixes, array $context ): void {
				$prefixes = self::read_values( 'order_meta_prefixes', $prefixes, $context );
				if ( null !== $prefixes ) {
					self::delete_order_meta_prefixes( $prefixes );
				}
			},
			'order_item_meta'     => static function ( $keys, array $context ): void {
				$keys = self::read_values( 'order_item_meta', $keys, $context );
				if ( null !== $keys ) {
					self::delete_order_item_meta( $keys );
				}
			},
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Deletes every row of each order meta key from post meta and, where WooCommerce created it, from the order meta table.
	 *
	 * Rows match the key byte for byte, because the tables' collation would also match another plugin's key that differs only in letter case. Post meta rows go one by one through WordPress, which clears each post's meta cache.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<string> $keys The order meta keys.
	 *
	 * @throws  \RuntimeException Thrown when a read or a delete fails.
	 */
	protected static function delete_order_meta( array $keys ): void {
		$wpdb       = self::get_wpdb();
		$hpos_table = $wpdb->prefix . 'wc_orders_meta';
		$has_hpos   = self::table_exists( $hpos_table );
		foreach ( $keys as $key ) {
			$wpdb->flush();
			// Identifier placeholders need WordPress 6.2, and WordPress 5.3 only formalized the variadic arguments that older versions already took.
			// @phpstan-ignore argument.type, WPCompat.parameterNotAvailable.wpdbprepare.args
			$meta_ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM `$wpdb->postmeta` WHERE meta_key = %s AND CAST( meta_key AS BINARY ) = CAST( %s AS BINARY )", $key, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress lists no meta rows by exact key.
			self::confirm_query( "the post meta rows of key $key" );
			foreach ( $meta_ids as $meta_id ) {
				if ( false === \delete_metadata_by_mid( 'post', (int) $meta_id ) ) {
					throw new \RuntimeException( "Post meta $meta_id of key $key was not deleted." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
				}
			}

			if ( $has_hpos ) {
				self::delete_rows( $hpos_table, $key );
			}
		}

		self::flush_cache_groups( array( 'orders', 'order_objects', 'orders_data', 'orders_meta' ) );
	}

	/**
	 * Deletes every order meta key that starts with one of the prefixes, from post meta and the order meta table.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<string> $prefixes The order meta key prefixes.
	 *
	 * @throws  \RuntimeException Thrown when a read or a delete fails.
	 */
	protected static function delete_order_meta_prefixes( array $prefixes ): void {
		$wpdb   = self::get_wpdb();
		$tables = array( $wpdb->postmeta );
		if ( self::table_exists( $wpdb->prefix . 'wc_orders_meta' ) ) {
			$tables[] = $wpdb->prefix . 'wc_orders_meta';
		}

		$keys = array();
		foreach ( $prefixes as $prefix ) {
			foreach ( $tables as $table ) {
				$keys = \array_merge( $keys, self::get_keys_by_prefix( $table, $prefix ) );
			}
		}

		self::delete_order_meta( \array_values( \array_unique( $keys ) ) );
	}

	/**
	 * Deletes every row of each order item meta key, where WooCommerce created its table.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<string> $keys The order item meta keys.
	 *
	 * @throws  \RuntimeException Thrown when a read or a delete fails.
	 */
	protected static function delete_order_item_meta( array $keys ): void {
		$table = self::get_wpdb()->prefix . 'woocommerce_order_itemmeta';
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		foreach ( $keys as $key ) {
			self::delete_rows( $table, $key );
		}

		self::flush_cache_groups( array( 'order-items', 'order_item_meta' ) );
	}

	/**
	 * Returns the footprint value as a list of non-empty strings on a site pass, or null on the network pass or when the value is anything else, which it logs.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string       $key     The footprint key.
	 * @param   mixed        $values  The footprint value.
	 * @param   array<mixed> $context The pass's context.
	 *
	 * @return  list<string>|null
	 */
	protected static function read_values( string $key, $values, array $context ): ?array {
		if ( 'site' !== ( $context['scope'] ?? 'site' ) ) {
			return null;
		}

		$strings = null;
		if ( \is_array( $values ) ) {
			$strings = array();
			foreach ( $values as $value ) {
				if ( ! \is_string( $value ) || '' === $value ) {
					$strings = null;
					break;
				}

				$strings[] = $value;
			}
		}

		if ( null === $strings ) {
			$plugin_file = $context['plugin_file'] ?? '';
			\error_log( '[' . \plugin_basename( \is_string( $plugin_file ) ? $plugin_file : '' ) . "] Uninstall skipped the footprint keys it cannot read: $key" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- No logger exists while a plugin is uninstalled.
		}

		return $strings;
	}

	/**
	 * Deletes the table's rows whose meta key is exactly the key.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $table The table.
	 * @param   string $key   The meta key, matched byte for byte.
	 *
	 * @throws  \RuntimeException Thrown when the delete fails.
	 */
	protected static function delete_rows( string $table, string $key ): void {
		$wpdb = self::get_wpdb();

		$wpdb->flush();
		// Identifier placeholders need WordPress 6.2, and WordPress 5.3 only formalized the variadic arguments that older versions already took.
		// @phpstan-ignore argument.type, argument.type, WPCompat.parameterNotAvailable.wpdbprepare.args
		$wpdb->query( $wpdb->prepare( "DELETE FROM `$table` WHERE meta_key = %s AND CAST( meta_key AS BINARY ) = CAST( %s AS BINARY )", $key, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's order tables have no WordPress meta API while WooCommerce is unloaded.
		self::confirm_query( "the $table rows of key $key" );
	}

	/**
	 * Returns the table's distinct meta keys that start with the prefix.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $table  The table.
	 * @param   string $prefix The prefix, matched byte for byte.
	 *
	 * @throws  \RuntimeException Thrown when the read fails.
	 *
	 * @return  list<string>
	 */
	protected static function get_keys_by_prefix( string $table, string $prefix ): array {
		$wpdb = self::get_wpdb();

		$wpdb->flush();
		// Identifier placeholders need WordPress 6.2, and WordPress 5.3 only formalized the variadic arguments that older versions already took.
		// @phpstan-ignore argument.type, WPCompat.parameterNotAvailable.wpdbprepare.args
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT CAST( meta_key AS BINARY ) FROM `$table` WHERE meta_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress lists no meta key by prefix.
		self::confirm_query( "the keys under $prefix in $table" );

		// The collation also matches keys that differ from the prefix in letter case or accents, and would fold such twins into one distinct key, hence the bytes.
		return \array_values( \array_filter( $keys, static fn ( $key ): bool => \is_string( $key ) && 0 === \strpos( $key, $prefix ) ) );
	}

	/**
	 * Returns whether the current site's database holds the table.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $table The table.
	 *
	 * @throws  \RuntimeException Thrown when the read fails.
	 *
	 * @return  bool
	 */
	protected static function table_exists( string $table ): bool {
		$wpdb = self::get_wpdb();

		$wpdb->flush();
		// WordPress 5.3 only formalized the variadic arguments that older versions already took.
		// @phpstan-ignore WPCompat.parameterNotAvailable.wpdbprepare.args
		$name = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A schema read.
		self::confirm_query( "the table $table" );

		return $table === $name;
	}

	/**
	 * Flushes the cache groups where the object cache can flush a single group, and otherwise leaves their entries to expire.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<string> $groups The cache groups.
	 */
	protected static function flush_cache_groups( array $groups ): void {
		// WordPress flushes a single group from 6.1.
		if ( ! \function_exists( 'wp_cache_supports' ) || ! \function_exists( 'wp_cache_flush_group' ) || ! \wp_cache_supports( 'flush_group' ) ) {
			return;
		}

		foreach ( $groups as $group ) {
			\wp_cache_flush_group( $group );
		}
	}

	/**
	 * Throws when the last database query failed.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $subject What the query read or deleted, for the exception's message.
	 *
	 * @throws  \RuntimeException Thrown when the last query failed.
	 */
	protected static function confirm_query( string $subject ): void {
		$wpdb = self::get_wpdb();
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( "Reading or deleting $subject failed: $wpdb->last_error" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	/**
	 * Returns WordPress's database connection.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  \LogicException Thrown when WordPress has none.
	 *
	 * @return  \wpdb
	 */
	protected static function get_wpdb(): \wpdb {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! $wpdb instanceof \wpdb ) {
			throw new \LogicException( 'The WooCommerce uninstall handlers need a wpdb connection.' );
		}

		return $wpdb;
	}

	// endregion
}
