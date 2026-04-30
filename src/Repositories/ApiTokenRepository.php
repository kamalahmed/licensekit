<?php
/**
 * API token repository.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Repositories;

use LicenseKit\Models\ApiToken;
use LicenseKit\Schema\Tables\ApiTokens;

defined( 'ABSPATH' ) || exit;

class ApiTokenRepository extends Repository {

	protected function table_name_unprefixed(): string {
		return ApiTokens::name();
	}

	protected function model_class(): string {
		return ApiToken::class;
	}

	public function find_by_token_hash( string $token_hash ): ?ApiToken {
		/** @var ApiToken|null */
		return $this->find_one_where( 'token_hash = %s', [ $token_hash ] );
	}

	/**
	 * @return ApiToken[]
	 */
	public function find_for_user( int $user_id ): array {
		return $this->find_many_where(
			'user_id = %d',
			[ $user_id ],
			'created_at DESC, id DESC'
		);
	}

	public function touch_last_used( int $id, string $datetime_utc ): bool {
		return $this->update( $id, [ 'last_used_at' => $datetime_utc ] );
	}
}
