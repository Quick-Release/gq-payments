<?php
/**
 * Constant-then-environment configuration.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Tests;

use GetQuick\Payments\Configuration;
use GetQuick\Payments\Environment;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase {

	protected function tearDown(): void {
		putenv( 'GQ_PAYMENTS_SERVICE_URL' );
		putenv( 'GQ_PAYMENTS_SITE_KEY' );
	}

	public function test_without_overrides_the_service_url_is_the_release_default(): void {
		$this->assertSame( Configuration::DEFAULT_SERVICE_URL, Configuration::serviceUrl() );
	}

	public function test_the_environment_variable_overrides_the_service_url(): void {
		putenv( 'GQ_PAYMENTS_SERVICE_URL= https://payments.test/ ' );

		$this->assertSame( 'https://payments.test', Configuration::serviceUrl() );
	}

	public function test_the_site_key_is_read_from_the_environment(): void {
		$this->assertSame( '', Configuration::siteKey() );

		putenv( 'GQ_PAYMENTS_SITE_KEY=c2VjcmV0' );

		$this->assertSame( 'c2VjcmV0', Configuration::siteKey() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_constant_wins_over_the_environment(): void {
		putenv( 'GQ_PAYMENTS_SERVICE_URL=https://from-env.test' );
		putenv( 'GQ_PAYMENTS_SITE_KEY=from-env' );
		define( 'GQ_PAYMENTS_SERVICE_URL', 'https://from-constant.test' );
		define( 'GQ_PAYMENTS_SITE_KEY', ' from-constant ' );

		$this->assertSame( 'https://from-constant.test', Configuration::serviceUrl() );
		$this->assertSame( 'from-constant', Configuration::siteKey() );
		$this->assertSame( 'from-constant', Environment::value( 'GQ_PAYMENTS_SITE_KEY' ) );
	}

	public function test_unset_values_read_as_empty(): void {
		$this->assertSame( '', Environment::value( 'GQ_PAYMENTS_NOT_SET_ANYWHERE' ) );
	}
}
