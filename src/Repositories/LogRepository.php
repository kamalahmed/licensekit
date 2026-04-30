<?php
/**
 * Audit log repository.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Repositories;

use LicenseKit\Models\Log;
use LicenseKit\Schema\Tables\Logs;

defined( 'ABSPATH' ) || exit;

class LogRepository extends Repository {

	protected function table_name_unprefixed(): string {
		return Logs::name();
	}

	protected function model_class(): string {
		return Log::class;
	}

	/**
	 * @return Log[]
	 */
	public function find_for_subject( string $subject_type, int $subject_id, int $limit = 50 ): array {
		return $this->find_many_where(
			'subject_type = %s AND subject_id = %d',
			[ $subject_type, $subject_id ],
			'created_at DESC, id DESC',
			$limit
		);
	}

	/**
	 * @return Log[]
	 */
	public function find_recent( int $limit = 100 ): array {
		return $this->find_many_where(
			'1 = %d',
			[ 1 ],
			'created_at DESC, id DESC',
			$limit
		);
	}

	/**
	 * Delete log rows older than the given UTC datetime.
	 * Returns rows deleted (0+). Called by the daily cron.
	 */
	public function prune_older_than( string $datetime_utc ): int {
		return $this->delete_where( 'created_at < %s', [ $datetime_utc ] );
	}
}
