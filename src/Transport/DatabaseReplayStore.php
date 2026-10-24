<?php
/**
 * Replay store in a small custom table.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Transport;

/**
 * Claims request IDs with INSERT IGNORE on a (key_id, request_id) primary key,
 * which is atomic across concurrent requests (options and transients are not).
 */
final class DatabaseReplayStore implements ReplayStore {

	public const TABLE          = 'gq_payments_replay';
	public const SCHEMA_VERSION = '1';
	public const VERSION_OPTION = 'gq_payments_replay_schema';

	/**
	 * Expired rows deleted per claim.
	 */
	private const PURGE_LIMIT = 100;

	/**
	 * Full table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Creates or updates the table when the schema version changed.
	 */
	public static function install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
  key_id varchar(128) NOT NULL,
  request_id varchar(128) NOT NULL,
  expires_at bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (key_id,request_id),
  KEY expires_at (expires_at)
) {$wpdb->get_charset_collate()};"
		);
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Drops the table (uninstall).
	 */
	public static function uninstall(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $keyId     Key ID.
	 * @param string $requestId Request ID.
	 * @param int    $now       Epoch seconds.
	 */
	public function claim( string $keyId, string $requestId, int $now ): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic single-use claim; caching would defeat it.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %d LIMIT %d', $table, $now, self::PURGE_LIMIT ) );
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (key_id, request_id, expires_at) VALUES (%s, %s, %d)', $table, $keyId, $requestId, $now + self::TTL ) );
		// phpcs:enable
		return 1 === $inserted;
	}
}
