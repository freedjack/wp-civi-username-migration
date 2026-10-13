<?php
/**
 * WP-CLI commands: wp civi usernames migrate|rollback|verify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Civi_Username_CLI {

	/**
	 * Migrate users whose user_login is an email address to obfuscated usernames.
	 *
	 * Dry-run by default. Pass --apply to write changes.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Actually write changes. Without this flag the command only plans and writes a CSV.
	 *
	 * [--dry-run]
	 * : Explicit dry-run (default). Ignored when --apply is set.
	 *
	 * [--limit=<n>]
	 * : Process at most N users.
	 *
	 * [--batch-size=<n>]
	 * : Civi lookup / apply batch size. Default 100.
	 *
	 * [--exclude-roles=<roles>]
	 * : Comma-separated roles to skip (e.g. administrator).
	 *
	 * [--csv=<path>]
	 * : Path for the mapping CSV (contains PII — store outside webroot).
	 *
	 * ## EXAMPLES
	 *
	 *     wp civi usernames migrate
	 *     wp civi usernames migrate --limit=20
	 *     wp civi usernames migrate --apply --csv=/tmp/wp-civi-usernames.csv
	 *     wp civi usernames migrate --apply --exclude-roles=administrator
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function migrate( $args, $assoc_args ) {
		$apply = isset( $assoc_args['apply'] );

		$migrator = new WP_Civi_Username_Migrator();

		$csv = isset( $assoc_args['csv'] ) ? $assoc_args['csv'] : $migrator->default_csv_path( 'migrate' );

		WP_CLI::log( $apply ? 'Running APPLY (writes to database)...' : 'Running DRY-RUN (no database writes)...' );
		WP_CLI::log( 'CSV: ' . $csv );

		if ( ! $this->civi_status() ) {
			WP_CLI::warning( 'CiviCRM does not appear to be available. All users will use the email fallback.' );
		}

		try {
			$stats = $migrator->migrate(
				array(
					'apply'         => $apply,
					'limit'         => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0,
					'batch_size'    => isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 100,
					'csv'           => $csv,
					'exclude_roles' => isset( $assoc_args['exclude-roles'] ) ? $assoc_args['exclude-roles'] : '',
				)
			);
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Candidates (email logins): ' . $stats['total_candidates'] );
		WP_CLI::log( 'Planned renames:          ' . $stats['planned'] );
		WP_CLI::log( '  from Civi name:         ' . $stats['source_civi'] );
		WP_CLI::log( '  from email fallback:    ' . $stats['source_email'] );
		WP_CLI::log( 'Skipped:                  ' . $stats['skipped'] );

		if ( $apply ) {
			WP_CLI::log( 'Applied:                  ' . $stats['applied'] );
			WP_CLI::log( 'Errors:                   ' . $stats['errors'] );
		}

		WP_CLI::log( 'CSV written:              ' . $stats['csv'] );

		if ( ! empty( $stats['verification'] ) ) {
			$v = $stats['verification'];
			WP_CLI::log( '' );
			WP_CLI::log( 'Verification:' );
			WP_CLI::log( '  Remaining email logins: ' . $v['remaining_email_logins'] );
			WP_CLI::log( '  Duplicate logins:       ' . $v['duplicate_logins'] );
			WP_CLI::log( '  Duplicate nicenames:    ' . $v['duplicate_nicenames'] );

			if ( $apply && ( $v['duplicate_logins'] > 0 || $v['duplicate_nicenames'] > 0 ) ) {
				WP_CLI::warning( 'Duplicates detected after apply — investigate before continuing.' );
			}
		}

		if ( ! $apply ) {
			WP_CLI::success( 'Dry-run complete. Review the CSV, then re-run with --apply.' );
		} elseif ( $stats['errors'] > 0 ) {
			WP_CLI::warning( 'Completed with errors. Check the CSV and database.' );
		} else {
			WP_CLI::success( 'Migration applied. Keep the CSV for rollback. All sessions are now invalid.' );
		}
	}

	/**
	 * Restore usernames from a mapping CSV written by migrate.
	 *
	 * Dry-run by default. Pass --apply to write changes.
	 *
	 * ## OPTIONS
	 *
	 * --csv=<path>
	 * : Path to the mapping CSV from a previous migrate run.
	 *
	 * [--apply]
	 * : Actually write changes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp civi usernames rollback --csv=/tmp/wp-civi-usernames.csv
	 *     wp civi usernames rollback --csv=/tmp/wp-civi-usernames.csv --apply
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function rollback( $args, $assoc_args ) {
		if ( empty( $assoc_args['csv'] ) ) {
			WP_CLI::error( 'You must pass --csv=<path> pointing at a migrate mapping file.' );
		}

		$apply = isset( $assoc_args['apply'] );
		WP_CLI::log( $apply ? 'Running ROLLBACK APPLY...' : 'Running ROLLBACK DRY-RUN...' );

		$migrator = new WP_Civi_Username_Migrator();

		try {
			$stats = $migrator->rollback(
				array(
					'apply' => $apply,
					'csv'   => $assoc_args['csv'],
				)
			);
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		WP_CLI::log( 'Planned:  ' . $stats['planned'] );
		WP_CLI::log( 'Skipped:  ' . $stats['skipped'] );
		if ( $apply ) {
			WP_CLI::log( 'Applied:  ' . $stats['applied'] );
			WP_CLI::log( 'Errors:   ' . $stats['errors'] );
		}

		if ( ! $apply ) {
			WP_CLI::success( 'Rollback dry-run complete. Re-run with --apply to restore.' );
		} elseif ( $stats['errors'] > 0 ) {
			WP_CLI::warning( 'Rollback completed with errors.' );
		} else {
			WP_CLI::success( 'Rollback applied.' );
		}
	}

	/**
	 * Print verification counts (email logins remaining, duplicate logins/nicenames).
	 *
	 * ## EXAMPLES
	 *
	 *     wp civi usernames verify
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function verify( $args, $assoc_args ) {
		$migrator = new WP_Civi_Username_Migrator();
		$v        = $migrator->verify( false );

		WP_CLI::log( 'Remaining email logins: ' . $v['remaining_email_logins'] );
		WP_CLI::log( 'Duplicate logins:       ' . $v['duplicate_logins'] );
		WP_CLI::log( 'Duplicate nicenames:    ' . $v['duplicate_nicenames'] );

		if ( 0 === (int) $v['duplicate_logins'] && 0 === (int) $v['duplicate_nicenames'] ) {
			WP_CLI::success( 'No duplicates found.' );
		} else {
			WP_CLI::warning( 'Duplicates found — investigate.' );
		}
	}

	/**
	 * Report CiviCRM availability.
	 *
	 * @return bool
	 */
	private function civi_status() {
		$civi = new WP_Civi_Username_Civi();
		return $civi->is_available();
	}
}
