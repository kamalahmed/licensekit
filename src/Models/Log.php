<?php
/**
 * Audit log entry DTO — append-only record of system actions.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Models;

use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class Log {

	public const ACTOR_SYSTEM  = 'system';
	public const ACTOR_USER    = 'user';
	public const ACTOR_LICENSE = 'license';
	public const ACTOR_TOKEN   = 'token';

	public ?int $id              = null;
	public string $actor_type    = self::ACTOR_SYSTEM;
	public ?int $actor_id        = null;
	public string $action        = '';
	public ?string $subject_type = null;
	public ?int $subject_id      = null;
	public ?string $ip           = null;
	public array $context        = [];
	public ?string $created_at   = null;

	public static function from_row( array $row ): self {
		$l               = new self();
		$l->id           = isset( $row['id'] ) ? (int) $row['id'] : null;
		$l->actor_type   = (string) ( $row['actor_type'] ?? self::ACTOR_SYSTEM );
		$l->actor_id     = isset( $row['actor_id'] ) ? (int) $row['actor_id'] : null;
		$l->action       = (string) ( $row['action'] ?? '' );
		$l->subject_type = isset( $row['subject_type'] ) ? (string) $row['subject_type'] : null;
		$l->subject_id   = isset( $row['subject_id'] ) ? (int) $row['subject_id'] : null;
		$l->ip           = isset( $row['ip'] ) ? (string) $row['ip'] : null;
		$l->context      = Helpers::decode_json_column( $row['context'] ?? null );
		$l->created_at   = isset( $row['created_at'] ) ? (string) $row['created_at'] : null;
		return $l;
	}

	public function to_array(): array {
		return [
			'id'           => $this->id,
			'actor_type'   => $this->actor_type,
			'actor_id'     => $this->actor_id,
			'action'       => $this->action,
			'subject_type' => $this->subject_type,
			'subject_id'   => $this->subject_id,
			'ip'           => $this->ip,
			'context'      => Helpers::encode_json_column( $this->context ),
			'created_at'   => $this->created_at,
		];
	}
}
