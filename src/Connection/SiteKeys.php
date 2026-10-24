<?php
/**
 * Custody of the site's Site Keys.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Connection;

use GetQuick\Payments\Configuration;
use InvalidArgumentException;

/**
 * The active Site Key (enrolled) and a pending one (sent in a Connection Request,
 * not yet approved). A pending key replaces the active one only once the Payment
 * Service reports its enrollment connected; nothing here deletes the active key.
 *
 * When GQ_PAYMENTS_SITE_KEY is set, that key is used and no private key is ever
 * written to the database. Otherwise keys are generated into an option with
 * autoload off, which the admin screen flags as the weaker choice.
 */
final class SiteKeys {

	public const OPTION = 'gq_payments_site_keys';

	/**
	 * Whether GQ_PAYMENTS_SITE_KEY supplies the Site Key.
	 */
	public function managed(): bool {
		return '' !== Configuration::siteKey();
	}

	/**
	 * The enrolled key, if any.
	 */
	public function active(): ?SiteKey {
		return $this->load( 'active' );
	}

	/**
	 * The key of an outstanding Connection Request, if any.
	 */
	public function pending(): ?SiteKey {
		return $this->load( 'pending' );
	}

	/**
	 * The key a new Connection Request should carry: the managed key, or a fresh one.
	 *
	 * @throws InvalidArgumentException When GQ_PAYMENTS_SITE_KEY is malformed.
	 */
	public function next(): SiteKey {
		return $this->managed() ? new SiteKey( Configuration::siteKey() ) : SiteKey::generate();
	}

	/**
	 * Records the key of a Connection Request the Payment Service accepted.
	 *
	 * @param SiteKey $key Key with its assigned key ID.
	 */
	public function savePending( SiteKey $key ): void {
		$this->save( $this->active(), $key );
	}

	/**
	 * The Payment Service confirmed the pending key's enrollment: it becomes the active key.
	 */
	public function promotePending(): void {
		$pending = $this->pending();
		if ( null !== $pending ) {
			$this->save( $pending, null );
		}
	}

	/**
	 * Reads a stored key. A managed key's secret comes from the constant; an entry
	 * whose secret is unavailable or malformed is treated as absent.
	 *
	 * @param string $slot 'active' or 'pending'.
	 */
	private function load( string $slot ): ?SiteKey {
		$stored = get_option( self::OPTION, [] );
		$entry  = is_array( $stored ) && is_array( $stored[ $slot ] ?? null ) ? $stored[ $slot ] : null;
		if ( null === $entry ) {
			return null;
		}
		$secret = $this->managed() ? Configuration::siteKey() : (string) ( $entry['secret'] ?? '' );
		try {
			return new SiteKey( $secret, (string) ( $entry['key_id'] ?? '' ) );
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * Stores both slots, autoload off.
	 *
	 * @param SiteKey|null $active  Active key.
	 * @param SiteKey|null $pending Pending key.
	 */
	private function save( ?SiteKey $active, ?SiteKey $pending ): void {
		$withSecret = ! $this->managed();
		$value      = [
			'active'  => $active?->toStored( $withSecret ),
			'pending' => $pending?->toStored( $withSecret ),
		];
		if ( ! add_option( self::OPTION, $value, '', false ) ) {
			update_option( self::OPTION, $value, false );
		}
	}
}
