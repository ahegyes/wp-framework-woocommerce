<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\WooCommerce\Tests\Integration\Logging;

use DeepWebSolutions\Framework\WooCommerce\Logging\WooCommerceLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;

#[CoversClass( WooCommerceLogger::class )]
final class WooCommerceLoggerTest extends TestCase {
	public function test_forwards_a_record_to_the_backing_logger_at_the_matching_level(): void {
		$backing = new RecordingWCLogger();
		$logger  = new WooCommerceLogger( 'plugin/framework', $backing );

		$logger->error( 'Something failed' );

		self::assertCount( 1, $backing->records );
		self::assertSame( 'error', $backing->records[0]['level'] );
		self::assertSame( 'Something failed', $backing->records[0]['message'] );
	}

	public function test_stamps_the_source_channel_onto_the_context(): void {
		$backing = new RecordingWCLogger();
		$logger  = new WooCommerceLogger( 'plugin/framework', $backing );

		$logger->info( 'hello', array( 'order_id' => 7 ) );

		self::assertSame( 'plugin/framework', $backing->records[0]['context']['source'] );
		self::assertSame( 7, $backing->records[0]['context']['order_id'] );
	}

	public function test_a_record_cannot_redirect_its_own_channel(): void {
		$backing = new RecordingWCLogger();
		$logger  = new WooCommerceLogger( 'plugin/framework', $backing );

		$logger->error( 'nice try', array( 'source' => 'somewhere-else' ) );

		self::assertSame( 'plugin/framework', $backing->records[0]['context']['source'] );
	}

	public function test_resolves_the_woocommerce_logger_when_none_is_supplied(): void {
		// The lazy default resolves wc_get_logger() and logs without error.
		( new WooCommerceLogger( 'plugin/framework' ) )->error( 'boot failure' );

		$this->expectNotToPerformAssertions();
	}

	public function test_an_unknown_level_is_rejected(): void {
		$logger = new WooCommerceLogger( 'plugin/framework', new RecordingWCLogger() );

		$this->expectException( InvalidArgumentException::class );

		$logger->log( 'verbose', 'nope' );
	}

	public function test_a_non_string_level_is_rejected(): void {
		$logger = new WooCommerceLogger( 'plugin/framework', new RecordingWCLogger() );

		$this->expectException( InvalidArgumentException::class );

		$logger->log( new \stdClass(), 'nope' );
	}
}

/**
 * Recording stand-in for WooCommerce's logger: it captures every log() call so the bridge's forwarding
 * and source-stamping can be asserted without writing to WooCommerce's log files. It extends WC_Logger so
 * the unused level methods come for free.
 */
final class RecordingWCLogger extends \WC_Logger {
	/**
	 * @var list<array{level: mixed, message: string, context: array<array-key, mixed>}>
	 */
	public array $records = array();

	/**
	 * @param array<array-key, mixed> $context
	 */
	public function log( mixed $level, mixed $message, mixed $context = array() ): void {
		$this->records[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => \is_array( $context ) ? $context : array(),
		);
	}
}
