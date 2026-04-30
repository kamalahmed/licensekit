<?php
/**
 * Activation repository.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Repositories;

use LicenseKit\Models\Activation;
use LicenseKit\Schema\Tables\Activations;

defined( 'ABSPATH' ) || exit;

class ActivationRepository extends Repository {

	protected function table_name_unprefixed(): string {
		return Activations::name();
	}

	protected function model_class(): string {
		return Activation::class;
	}

	/**
	 * @return Activation[]
	 */
	public function find_for_license( int $license_id, ?string $status = null ): array {
		if ( null === $status ) {
			return $this->find_many_where(
				'license_id = %d',
				[ $license_id ],
				'last_seen_at DESC, id DESC'
			);
		}
		return $this->find_many_where(
			'license_id = %d AND status = %s',
			[ $license_id, $status ],
			'last_seen_at DESC, id DESC'
		);
	}

	public function find_by_license_and_site(
		int $license_id,
		string $site_url_hash,
		string $status = Activation::STATUS_ACTIVE
	): ?Activation {
		/** @var Activation|null */
		return $this->find_one_where(
			'license_id = %d AND site_url_hash = %s AND status = %s',
			[ $license_id, $site_url_hash, $status ]
		);
	}

	/**
	 * Count active, non-local-environment activations for a license.
	 * Local/dev environments are excluded — those are exempt from activation_limit.
	 */
	public function count_billable_active_for_license( int $license_id ): int {
		return $this->count_where(
			'license_id = %d AND status = %s AND site_environment != %s',
			[ $license_id, Activation::STATUS_ACTIVE, Activation::ENV_LOCAL ]
		);
	}

	public function count_active_for_license( int $license_id ): int {
		return $this->count_where(
			'license_id = %d AND status = %s',
			[ $license_id, Activation::STATUS_ACTIVE ]
		);
	}

	/**
	 * Find activations not seen since the given UTC datetime — for staleness reporting.
	 *
	 * @return Activation[]
	 */
	public function find_stale( string $datetime_utc, int $limit = 100 ): array {
		return $this->find_many_where(
			'status = %s AND last_seen_at < %s',
			[ Activation::STATUS_ACTIVE, $datetime_utc ],
			'last_seen_at ASC',
			$limit
		);
	}
}
