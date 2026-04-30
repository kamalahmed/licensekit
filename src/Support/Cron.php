<?php
/**
 * Daily cron handler — log pruning + future periodic chores.
 *
 * Schedule is set up in `Activator::schedule_cron()`; this class wires the
 * action callback. Default retention is 90 days, filterable via
 * `licensekit_log_retention_days`.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Support;

use LicenseKit\Repositories\LogRepository;

defined( 'ABSPATH' ) || exit;

final class Cron {

	public function register(): void {
		add_action( 'licensekit_daily_cron', [ $this, 'run_daily' ] );
	}

	public function run_daily(): void {
		$days   = (int) apply_filters( 'licensekit_log_retention_days', 90 );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		( new LogRepository() )->prune_older_than( $cutoff );
	}
}
