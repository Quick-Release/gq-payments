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
 * When GQ_PAYMENTS_SITE_KEY is set (constant mode), no private key is ever written
 * to the database. The option then records each slot's key ID, public key and
 * fingerprint only, and a slot is backed by whichever configured constant
 * (GQ_PAYMENTS_SITE_KEY or GQ_PAYMENTS_SITE_KEY_NEXT) has that public key. So the
 * active key is always the one the service confirmed, and changing the constants
 * never makes another key active before approval. A replacement Connection Request
 * carries GQ_PAYMENTS_SITE_KEY_NEXT.
 *
 * Otherwise keys are generated into the option with autoload off, which the admin
 * screen flags as the weaker choice.
 */
final class SiteKeys {

	public const OPTION = 'gq_payments_site_keys';

	/**
	 * Whether GQ_PAYMENTS_SITE_KEY supplies the Site Keys (constant mode).
	 */
	public function managed(): bool {
		return '' !== Configuration::siteKey();
	}

	/**
	 * The enrolled key, if this site can still sign with it.
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
	 * Whether a key was enrolled but no configured constant holds it any more, so the
	 * site cannot sign as its Site Enrollment until a new key is approved.
	 */
	public function enrolledKeyMissing(): bool {
		return null !== $this->entry( 'active' ) && null === $this->active();
	}

	/**
	 * The key a new Connection Request should carry. In constant mode: GQ_PAYMENTS_SITE_KEY
	 * for the first enrollment, GQ_PAYMENTS_SITE_KEY_NEXT (when set) to replace it. Otherwise
	 * a fresh key. Connection treats a key equal to the active one as already enrolled.
	 *
	 * @throws InvalidArgumentException When a configured Site Key constant is malformed.
	 */
	public function next(): SiteKey {
		if ( ! $this->managed() ) {
			return SiteKey::generate();
		}
		$current = new SiteKey( Configuration::siteKey() );
		$next    = '' === Configuration::siteKeyNext() ? null : new SiteKey( Configuration::siteKeyNext() );
		return null === $this->active() ? $current : ( $next ?? $current );
	}

	/**
	 * Records the key of a Connection Request the Payment Service accepted. The active slot
	 * is kept as stored, even when no configured constant currently backs it.
	 *
	 * @param SiteKey $key Key with its assigned key ID.
	 */
	public function savePending( SiteKey $key ): void {
		$this->save( $this->entry( 'active' ), $key->toStored( ! $this->managed() ) );
	}

	/**
	 * The Payment Service confirmed the pending key's enrollment: it becomes the active key.
	 */
	public function promotePending(): void {
		$pending = $this->entry( 'pending' );
		if ( null !== $pending ) {
			$this->save( $pending, null );
		}
	}

	/**
	 * Reads a stored key. In constant mode its secret comes from the configured constant with
	 * the same public key; an entry no constant (or no valid stored secret) backs is absent.
	 *
	 * @param string $slot 'active' or 'pending'.
	 */
	private function load( string $slot ): ?SiteKey {
		$entry = $this->entry( $slot );
		if ( null === $entry ) {
			return null;
		}
		$keyId = (string) ( $entry['key_id'] ?? '' );
		if ( ! $this->managed() ) {
			try {
				return new SiteKey( (string) ( $entry['secret'] ?? '' ), $keyId );
			} catch ( InvalidArgumentException ) {
				return null;
			}
		}
		foreach ( [ Configuration::siteKey(), Configuration::siteKeyNext() ] as $secret ) {
			try {
				$key = new SiteKey( $secret, $keyId );
			} catch ( InvalidArgumentException ) {
				continue;
			}
			if ( hash_equals( (string) ( $entry['public_key'] ?? '' ), $key->publicKey ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * A slot as stored.
	 *
	 * @param string $slot 'active' or 'pending'.
	 * @return array<string, mixed>|null
	 */
	private function entry( string $slot ): ?array {
		$stored = get_option( self::OPTION, [] );
		return is_array( $stored ) && is_array( $stored[ $slot ] ?? null ) ? $stored[ $slot ] : null;
	}

	/**
	 * Stores both slots, autoload off.
	 *
	 * @param array<string, mixed>|null $active  Active entry.
	 * @param array<string, mixed>|null $pending Pending entry.
	 */
	private function save( ?array $active, ?array $pending ): void {
		$value = [
			'active'  => $active,
			'pending' => $pending,
		];
		if ( ! add_option( self::OPTION, $value, '', false ) ) {
			update_option( self::OPTION, $value, false );
		}
	}
}
