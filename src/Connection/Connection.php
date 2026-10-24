<?php
/**
 * Connection Request, connection health and Eligible Methods.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Connection;

use GetQuick\Payments\Transport\ServiceKeys;
use GetQuick\Payments\Transport\ServiceResponse;
use GetQuick\Payments\Transport\Transport;
use InvalidArgumentException;

/**
 * The site's side of its Site Enrollment. The Payment Service owns the enrollment,
 * its environment and the Eligible Methods with their toggles; WordPress keeps a
 * briefly cached copy of what the service last reported (ADR 0001, ADR 0002).
 *
 * Service calls (all signed with the Site Key):
 * - POST /v1/site/enrollments   { site_id, site_url, public_key, fingerprint } → { key_id, status }
 * - GET  /v1/site/status        → { status, environment, methods }
 * - POST /v1/site/configuration { methods: { id: bool } } → { methods }
 */
final class Connection implements Availability {

	public const STATUS_OPTION   = 'gq_payments_connection';
	public const FRESH_TRANSIENT = 'gq_payments_connection_fresh';
	public const CACHE_SECONDS   = 60;

	public const NOT_REQUESTED     = 'not_requested';
	public const AWAITING_APPROVAL = 'awaiting_approval';
	public const CONNECTED         = 'connected';
	public const DISCONNECTED      = 'disconnected';
	public const UNREACHABLE       = 'unreachable';

	/** Outcomes of administrator actions; each maps to a fixed message. */
	public const REQUESTED        = 'requested';
	public const ALREADY_ENROLLED = 'already_enrolled';
	public const INVALID_SITE_KEY = 'invalid_site_key';
	public const SERVICE_ERROR    = 'service_error';
	public const SAVED            = 'saved';
	public const NOT_CONNECTED    = 'not_connected';

	/**
	 * Builds the connection.
	 *
	 * @param SiteKeys $keys Site Key custody.
	 */
	public function __construct( private SiteKeys $keys ) {}

	/**
	 * The site's connection, for gateway availability and the admin screen.
	 */
	public static function fromWordPress(): self {
		return new self( new SiteKeys() );
	}

	/**
	 * Site Key custody.
	 */
	public function keys(): SiteKeys {
		return $this->keys;
	}

	/**
	 * Sends a Connection Request carrying the next Site Key. It becomes the pending
	 * key; the active key stays in use until the service confirms the new enrollment.
	 *
	 * @return string One of REQUESTED, ALREADY_ENROLLED, INVALID_SITE_KEY, UNREACHABLE, SERVICE_ERROR.
	 */
	public function request(): string {
		try {
			$key = $this->keys->next();
		} catch ( InvalidArgumentException ) {
			return self::INVALID_SITE_KEY;
		}
		if ( $this->keys->active()?->publicKey === $key->publicKey ) {
			return self::ALREADY_ENROLLED;
		}

		$siteId = $this->siteId( true );
		$reply  = Transport::client( $key->signer( $siteId ) )->post(
			'/v1/site/enrollments',
			[
				'site_id'     => $siteId,
				'site_url'    => home_url(),
				'public_key'  => $key->publicKey,
				'fingerprint' => $key->fingerprint(),
			]
		);
		$keyId  = $reply->json()['key_id'] ?? null;
		if ( ! $reply->ok() || ! is_string( $keyId ) || ! ServiceKeys::validId( $keyId ) ) {
			return self::outcome( $reply );
		}

		$this->keys->savePending( $key->withKeyId( $keyId ) );
		$this->refresh();
		return self::REQUESTED;
	}

	/**
	 * Cached status, refreshed from the Payment Service once it is older than CACHE_SECONDS.
	 *
	 * @return array{health: string, environment: string, methods: list<array{id: string, label: string, status: string, enabled: bool}>, connected: bool}
	 */
	public function status(): array {
		return false === get_transient( self::FRESH_TRANSIENT ) ? $this->refresh() : $this->stored();
	}

	/**
	 * Asks the Payment Service for the enrollment status now.
	 *
	 * A pending key reported connected is promoted to active. Health follows the
	 * active key, or the pending one before any key is enrolled.
	 *
	 * @return array{health: string, environment: string, methods: list<array{id: string, label: string, status: string, enabled: bool}>, connected: bool}
	 */
	public function refresh(): array {
		$previous = $this->stored();
		$siteId   = $this->siteId();
		$active   = $this->keys->active();
		$pending  = $this->keys->pending();
		$reply    = null;
		$asked    = false;

		if ( null !== $pending ) {
			$reply = $this->askStatus( $pending, $siteId );
			$asked = true;
			if ( self::CONNECTED === ( $reply['status'] ?? null ) ) {
				$this->keys->promotePending();
			} elseif ( null !== $active ) {
				$asked = false;
			}
		}
		if ( ! $asked && null !== $active ) {
			$reply = $this->askStatus( $active, $siteId );
			$asked = true;
		}

		if ( ! $asked ) {
			$status = [
				'health'      => self::NOT_REQUESTED,
				'environment' => $previous['environment'],
				'methods'     => [],
				'connected'   => false,
			];
		} elseif ( null === $reply ) {
			$status = [ 'health' => self::UNREACHABLE ] + $previous;
		} else {
			$health = match ( $reply['status'] ?? null ) {
				self::CONNECTED         => self::CONNECTED,
				self::AWAITING_APPROVAL => self::AWAITING_APPROVAL,
				default                 => self::DISCONNECTED,
			};
			$environment = in_array( $reply['environment'] ?? null, ServiceKeys::ENVIRONMENTS, true ) ? $reply['environment'] : $previous['environment'];
			if ( '' !== $environment ) {
				update_option( Transport::ENVIRONMENT_OPTION, $environment, false );
			}
			$status = [
				'health'      => $health,
				'environment' => $environment,
				'methods'     => self::methods( $reply['methods'] ?? [] ),
				'connected'   => self::CONNECTED === $health,
			];
		}

		$this->store( $status );
		return $status;
	}

	/**
	 * Switches an eligible method on or off through the signed site-configuration call.
	 * The service decides; the cached copy takes whatever it answers.
	 *
	 * @param string $methodId Method ID.
	 * @param bool   $enabled  Desired state.
	 * @return string One of SAVED, NOT_CONNECTED, UNREACHABLE, SERVICE_ERROR.
	 */
	public function setMethodEnabled( string $methodId, bool $enabled ): string {
		$active = $this->keys->active();
		if ( null === $active ) {
			return self::NOT_CONNECTED;
		}
		$reply = Transport::client( $active->signer( $this->siteId() ) )->post( '/v1/site/configuration', [ 'methods' => [ $methodId => $enabled ] ] );
		if ( ! $reply->ok() || ! is_array( $reply->json()['methods'] ?? null ) ) {
			return self::outcome( $reply );
		}
		$this->store( [ 'methods' => self::methods( $reply->json()['methods'] ) ] + $this->stored() );
		return self::SAVED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function isConnected(): bool {
		return $this->status()['connected'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $methodId Method ID.
	 */
	public function isMethodEligible( string $methodId ): bool {
		return in_array( $methodId, $this->eligibleMethodIds(), true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<string>
	 */
	public function eligibleMethodIds(): array {
		$status = $this->status();
		if ( ! $status['connected'] ) {
			return [];
		}
		$ids = [];
		foreach ( $status['methods'] as $method ) {
			if ( 'eligible' === $method['status'] && $method['enabled'] ) {
				$ids[] = $method['id'];
			}
		}
		return $ids;
	}

	/**
	 * This site's Site ID; kept after disconnect so Payment State for existing orders still verifies.
	 *
	 * @param bool $create Generate one when missing.
	 */
	public function siteId( bool $create = false ): string {
		$siteId = (string) get_option( Transport::SITE_ID_OPTION, '' );
		if ( '' === $siteId && $create ) {
			$siteId = wp_generate_uuid4();
			add_option( Transport::SITE_ID_OPTION, $siteId, '', false );
		}
		return $siteId;
	}

	/**
	 * Signed status call with one key. Null when there was no usable reply.
	 *
	 * @param SiteKey $key    Key to sign with.
	 * @param string  $siteId Site ID.
	 * @return array<mixed>|null
	 */
	private function askStatus( SiteKey $key, string $siteId ): ?array {
		$reply = Transport::client( $key->signer( $siteId ) )->get( '/v1/site/status' );
		if ( in_array( $reply->status, [ 401, 403, 404 ], true ) ) {
			return [ 'status' => self::DISCONNECTED ];
		}
		return $reply->ok() ? $reply->json() : null;
	}

	/**
	 * Masked outcome of a failed call.
	 *
	 * @param ServiceResponse $reply Reply.
	 */
	private static function outcome( ServiceResponse $reply ): string {
		return 0 === $reply->status || $reply->status >= 500 ? self::UNREACHABLE : self::SERVICE_ERROR;
	}

	/**
	 * Keeps only well-formed method entries, with bounded fields.
	 *
	 * @param mixed $methods Methods as reported.
	 * @return list<array{id: string, label: string, status: string, enabled: bool}>
	 */
	private static function methods( mixed $methods ): array {
		$clean = [];
		foreach ( is_array( $methods ) ? $methods : [] as $method ) {
			$id = is_array( $method ) && is_string( $method['id'] ?? null ) ? sanitize_key( $method['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}
			$clean[] = [
				'id'      => $id,
				'label'   => is_string( $method['label'] ?? null ) ? mb_substr( sanitize_text_field( $method['label'] ), 0, 100 ) : $id,
				'status'  => 'eligible' === ( $method['status'] ?? null ) ? 'eligible' : 'unavailable',
				'enabled' => true === ( $method['enabled'] ?? null ),
			];
		}
		return $clean;
	}

	/**
	 * Last stored status.
	 *
	 * @return array{health: string, environment: string, methods: list<array{id: string, label: string, status: string, enabled: bool}>, connected: bool}
	 */
	private function stored(): array {
		$stored = get_option( self::STATUS_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		return [
			'health'      => is_string( $stored['health'] ?? null ) ? $stored['health'] : self::NOT_REQUESTED,
			'environment' => is_string( $stored['environment'] ?? null ) ? $stored['environment'] : '',
			'methods'     => self::methods( $stored['methods'] ?? [] ),
			'connected'   => true === ( $stored['connected'] ?? null ),
		];
	}

	/**
	 * Stores the status (autoload off) and marks it fresh.
	 *
	 * @param array<string, mixed> $status Status.
	 */
	private function store( array $status ): void {
		if ( ! add_option( self::STATUS_OPTION, $status, '', false ) ) {
			update_option( self::STATUS_OPTION, $status, false );
		}
		set_transient( self::FRESH_TRANSIENT, 1, self::CACHE_SECONDS );
	}
}
