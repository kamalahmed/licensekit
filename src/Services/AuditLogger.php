<?php
/**
 * Audit logger — thin wrapper around `LogRepository` that fills in actor, IP,
 * and timestamp from request context. Append-only: never updates or deletes
 * (except via the daily-cron pruner in `LogRepository::prune_older_than()`).
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Services;

use LicenseKit\Models\Log;
use LicenseKit\Repositories\LogRepository;

defined( 'ABSPATH' ) || exit;

class AuditLogger {

	private LogRepository $repo;

	public function __construct( LogRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Record an event. Returns the new log id (or 0 on failure — never throws).
	 *
	 * @param string               $action       Event identifier (e.g. `license.activated`).
	 * @param array<string, mixed> $context      Arbitrary JSON-serializable details.
	 * @param string|null          $subject_type Optional: model name being acted on.
	 * @param int|null             $subject_id   Optional: id of that model.
	 * @param string|null          $actor_type   Override actor (default: detect from request).
	 * @param int|null             $actor_id     Override actor id.
	 */
	public function record(
		string $action,
		array $context = [],
		?string $subject_type = null,
		?int $subject_id = null,
		?string $actor_type = null,
		?int $actor_id = null
	): int {
		$log               = new Log();
		$log->action       = $action;
		$log->context      = $context;
		$log->subject_type = $subject_type;
		$log->subject_id   = $subject_id;
		$log->created_at   = gmdate( 'Y-m-d H:i:s' );

		if ( null !== $actor_type ) {
			$log->actor_type = $actor_type;
			$log->actor_id   = $actor_id;
		} elseif ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$log->actor_type = Log::ACTOR_USER;
			$log->actor_id   = get_current_user_id();
		} else {
			$log->actor_type = Log::ACTOR_SYSTEM;
		}

		$log->ip = self::pack_ip( self::client_ip() );

		return $this->repo->insert( $log );
	}

	private static function client_ip(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		return (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ); // phpcs:ignore WordPress.Security
	}

	/**
	 * Convert IP string to packed binary (4 or 16 bytes) for `varbinary(16)` storage.
	 */
	private static function pack_ip( string $ip ): ?string {
		if ( '' === $ip ) {
			return null;
		}
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $packed ? null : $packed;
	}
}
