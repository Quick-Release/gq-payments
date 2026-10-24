<?php
/**
 * Signed path of inbound REST requests, shared with the Node fake.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Tests\Transport;

use GetQuick\Payments\Transport\Wire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InboundPathTest extends TestCase {

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function paths(): iterable {
		foreach ( Vectors::all()['inbound_paths'] as $vector ) {
			yield $vector['name'] => [ $vector ];
		}
	}

	/**
	 * @param array<string, mixed> $vector Shared vector.
	 */
	#[DataProvider( 'paths' )]
	public function test_route_and_query_normalise_to_the_signed_path( array $vector ): void {
		$this->assertSame( $vector['path'], Wire::inboundPath( $vector['route'], $vector['query'] ) );
	}
}
