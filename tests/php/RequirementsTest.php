<?php
/**
 * Requirement decisions, tested without WordPress.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Tests;

use GetQuick\Payments\Requirements;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequirementsTest extends TestCase {

	/**
	 * @return iterable<string, array{?string, bool, list<string>}>
	 */
	public static function environments(): iterable {
		yield 'WooCommerce missing' => [ null, false, [ 'woocommerce' ] ];
		yield 'WooCommerce 10.8.2 too old' => [ '10.8.2', false, [ 'woocommerce' ] ];
		yield 'WooCommerce 10.9 minimum' => [ '10.9', false, [] ];
		yield 'WooCommerce 10.9.0 minimum' => [ '10.9.0', false, [] ];
		yield 'WooCommerce 11.2.1' => [ '11.2.1', false, [] ];
		yield 'network active on multisite' => [ '11.2.1', true, [ 'single-site' ] ];
		yield 'everything wrong' => [ null, true, [ 'woocommerce', 'single-site' ] ];
	}

	/**
	 * @param list<string> $expected
	 */
	#[DataProvider( 'environments' )]
	public function test_reports_unmet_requirements( ?string $woocommerce_version, bool $network_active, array $expected ): void {
		self::assertSame( $expected, Requirements::failures( $woocommerce_version, $network_active ) );
	}
}
