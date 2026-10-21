<?php
/**
 * Core migration / rollback logic for email usernames.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Civi_Username_Migrator {

	/**
	 * CSV column headers written by migrate / read by rollback.
	 *
	 * @var string[]
	 */
	const CSV_HEADERS = array(
		'wp_id',
		'civi_id',
		'old_login',
		'old_nicename',
		'old_display_name',
		'old_nickname',
		'new_login',
		'new_nicename',
		'new_display_name',
		'new_nickname',
		'source',
		'changed_display_name',
		'changed_nickname',
		'user_email',
		'roles',
	);

	/** @var WP_Civi_Username_Generator */
	private $generator;

	/** @var WP_Civi_Username_Civi */
	private $civi;

	/**
	 * Constructor.
	 *
	 * @param WP_Civi_Username_Generator|null $generator Generator.
	 * @param WP_Civi_Username_Civi|null      $civi      Civi helper.
	 */
	public function __construct( $generator = null, $civi = null ) {
		$this->generator = $generator ? $generator : new WP_Civi_Username_Generator();
		$this->civi      = $civi ? $civi : new WP_Civi_Username_Civi();
	}

	/**
	 * Run the migration.
	 *
	 * @param array<string, mixed> $args {
	 *     @type bool   $apply          When true, write changes. Default false (dry-run).
	 *     @type int    $limit          Max users to process (0 = all).
	 *     @type int    $batch_size     Batch size for Civi lookups / DB writes.
	 *     @type string $csv            Path to write the mapping CSV.
	 *     @type string $exclude_roles  Comma-separated roles to skip.
	 * }
	 * @return array<string, mixed> Summary stats.
	 */
	public function migrate( array $args = array() ) {
		$apply         = ! empty( $args['apply'] );
		$limit         = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;
		$batch_size    = isset( $args['batch_size'] ) ? max( 1, (int) $args['batch_size'] ) : 100;
		$csv_path      = ! empty( $args['csv'] ) ? (string) $args['csv'] : $this->default_csv_path( 'migrate' );
		$exclude_roles = array();
		if ( ! empty( $args['exclude_roles'] ) ) {
			$exclude_roles = array_filter( array_map( 'trim', explode( ',', (string) $args['exclude_roles'] ) ) );
		}

		$users = $this->get_email_login_users( $limit, $exclude_roles );

		$stats = array(
			'total_candidates' => count( $users ),
			'planned'          => 0,
			'applied'          => 0,
			'skipped'          => 0,
			'source_civi'      => 0,
			'source_email'     => 0,
			'collisions'       => 0,
			'errors'           => 0,
			'csv'              => $csv_path,
			'dry_run'          => ! $apply,
		);

		if ( empty( $users ) ) {
			return $stats;
		}

		$csv_handle = $this->open_csv( $csv_path, true );
		if ( ! $csv_handle ) {
			throw new RuntimeException( 'Unable to open CSV for writing: ' . $csv_path );
		}

		$this->generator->clear_reservations();

		// Reserve all non-email logins so generated names cannot collide with them.
		$this->reserve_existing_non_email_logins();

		$batches = array_chunk( $users, $batch_size );

		foreach ( $batches as $batch ) {
			$wp_ids   = wp_list_pluck( $batch, 'ID' );
			$contacts = $this->civi->get_contacts_for_users( $wp_ids );

			$rows_to_apply = array();

			foreach ( $batch as $user ) {
				$row = $this->plan_user( $user, $contacts );
				if ( ! $row ) {
					$stats['skipped']++;
					continue;
				}

				fputcsv( $csv_handle, $this->row_to_csv( $row ) );
				$stats['planned']++;
				if ( 'civi' === $row['source'] ) {
					$stats['source_civi']++;
				} else {
					$stats['source_email']++;
				}

				$rows_to_apply[] = $row;
			}

			if ( $apply && ! empty( $rows_to_apply ) ) {
				$applied = $this->apply_rows( $rows_to_apply );
				$stats['applied'] += $applied['ok'];
				$stats['errors']  += $applied['errors'];
			}
		}

		fclose( $csv_handle );

		if ( $apply ) {
			$this->flush_caches();
		}

		$stats['verification'] = $this->verify( $apply );

		return $stats;
	}

	/**
	 * Rollback from a previously written mapping CSV.
	 *
	 * @param array<string, mixed> $args {
	 *     @type string $csv   Path to mapping CSV (required).
	 *     @type bool   $apply When true, write changes. Default false.
	 * }
	 * @return array<string, mixed>
	 */
	public function rollback( array $args = array() ) {
		$apply    = ! empty( $args['apply'] );
		$csv_path = ! empty( $args['csv'] ) ? (string) $args['csv'] : '';

		if ( '' === $csv_path || ! is_readable( $csv_path ) ) {
			throw new RuntimeException( 'CSV path is missing or unreadable: ' . $csv_path );
		}

		$stats = array(
			'planned' => 0,
			'applied' => 0,
			'skipped' => 0,
			'errors'  => 0,
			'csv'     => $csv_path,
			'dry_run' => ! $apply,
		);

		$handle = fopen( $csv_path, 'r' );
		if ( ! $handle ) {
			throw new RuntimeException( 'Unable to open CSV: ' . $csv_path );
		}

		$headers = fgetcsv( $handle );
		if ( ! is_array( $headers ) ) {
			fclose( $handle );
			throw new RuntimeException( 'CSV appears empty: ' . $csv_path );
		}

		$index = array_flip( $headers );
		$required = array( 'wp_id', 'old_login', 'old_nicename', 'old_display_name', 'old_nickname', 'new_login' );
		foreach ( $required as $col ) {
			if ( ! isset( $index[ $col ] ) ) {
				fclose( $handle );
				throw new RuntimeException( 'CSV missing required column: ' . $col );
			}
		}

		$rows = array();
		while ( ( $data = fgetcsv( $handle ) ) !== false ) {
			if ( count( $data ) < count( $headers ) ) {
				continue;
			}
			$row = array();
			foreach ( $index as $col => $i ) {
				$row[ $col ] = isset( $data[ $i ] ) ? $data[ $i ] : '';
			}
			$rows[] = $row;
		}
		fclose( $handle );

		foreach ( $rows as $row ) {
			$wp_id = (int) $row['wp_id'];
			$user  = get_userdata( $wp_id );
			if ( ! $user ) {
				$stats['skipped']++;
				continue;
			}

			// Only roll back if the current login still matches what we set.
			if ( $user->user_login !== $row['new_login'] ) {
				$stats['skipped']++;
				continue;
			}

			$stats['planned']++;

			if ( ! $apply ) {
				continue;
			}

			$ok = $this->write_user_fields(
				$wp_id,
				array(
					'user_login'    => $row['old_login'],
					'user_nicename' => $row['old_nicename'],
					'display_name'  => $row['old_display_name'],
				),
				$row['old_nickname'],
				true
			);

			if ( $ok ) {
				$stats['applied']++;
			} else {
				$stats['errors']++;
			}
		}

		if ( $apply ) {
			$this->flush_caches();
		}

		return $stats;
	}

	/**
	 * Post-run verification checks.
	 *
	 * @param bool $applied Whether changes were written.
	 * @return array<string, mixed>
	 */
	public function verify( $applied = false ) {
		global $wpdb;

		$email_logins = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE '%@%'"
		);

		$dupes = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT user_login FROM {$wpdb->users}
				GROUP BY user_login
				HAVING COUNT(*) > 1
			) d"
		);

		$dupe_nicenames = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT user_nicename FROM {$wpdb->users}
				GROUP BY user_nicename
				HAVING COUNT(*) > 1
			) d"
		);

		return array(
			'remaining_email_logins' => $email_logins,
			'duplicate_logins'       => $dupes,
			'duplicate_nicenames'    => $dupe_nicenames,
			'applied'                => (bool) $applied,
		);
	}

	/**
	 * Fetch WP users whose user_login contains @.
	 *
	 * Role exclusion is applied first, then --limit, so excluded roles do not
	 * consume the limit quota.
	 *
	 * @param int      $limit         Max users (0 = all).
	 * @param string[] $exclude_roles Roles to skip.
	 * @return object[]
	 */
	private function get_email_login_users( $limit, array $exclude_roles ) {
		global $wpdb;

		$users = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, user_login, user_nicename, display_name, user_email
				FROM {$wpdb->users}
				WHERE user_login LIKE %s
				ORDER BY ID ASC",
				'%@%'
			)
		);

		if ( empty( $users ) ) {
			return array();
		}

		if ( ! empty( $exclude_roles ) ) {
			$filtered = array();
			foreach ( $users as $user ) {
				$wp_user = get_userdata( $user->ID );
				if ( ! $wp_user ) {
					continue;
				}
				$roles = (array) $wp_user->roles;
				if ( array_intersect( $exclude_roles, $roles ) ) {
					continue;
				}
				$filtered[] = $user;
			}
			$users = $filtered;
		}

		if ( $limit > 0 && count( $users ) > $limit ) {
			$users = array_slice( $users, 0, $limit );
		}

		return $users;
	}

	/**
	 * Reserve every existing non-email login so generated names cannot collide.
	 */
	private function reserve_existing_non_email_logins() {
		global $wpdb;
		$logins = $wpdb->get_col(
			"SELECT user_login FROM {$wpdb->users} WHERE user_login NOT LIKE '%@%'"
		);
		foreach ( (array) $logins as $login ) {
			$this->generator->reserve( $login );
		}
	}

	/**
	 * Plan a single user's rename (does not write).
	 *
	 * @param object              $user     WP user row.
	 * @param array<int, array>   $contacts Contact map keyed by WP user ID.
	 * @return array<string, mixed>|null
	 */
	private function plan_user( $user, array $contacts ) {
		$wp_id     = (int) $user->ID;
		$old_login = (string) $user->user_login;

		if ( ! WP_Civi_Username_Generator::looks_like_email( $old_login ) ) {
			return null;
		}

		$contact   = isset( $contacts[ $wp_id ] ) ? $contacts[ $wp_id ] : null;
		$civi_id   = $contact && ! empty( $contact['contact_id'] ) ? (int) $contact['contact_id'] : 0;
		$source    = 'email';
		$new_login = '';

		$wp_first_name = trim( (string) get_user_meta( $wp_id, 'first_name', true ) );
		$wp_last_name  = trim( (string) get_user_meta( $wp_id, 'last_name', true ) );

		if ( $civi_id && '' !== $wp_first_name && '' !== $wp_last_name ) {
			$built = $this->generator->build_from_civi(
				$wp_first_name,
				$wp_last_name,
				$civi_id,
				$wp_id
			);
			if ( $built ) {
				$new_login = $built;
				$source    = 'civi';
			}
		}

		if ( ! $new_login && $civi_id && $this->civi->has_usable_name( $contact ) ) {
			$built = $this->generator->build_from_civi(
				$contact['first_name'],
				$contact['last_name'],
				$civi_id,
				$wp_id
			);
			if ( $built ) {
				$new_login = $built;
				$source    = 'civi';
			}
		}

		if ( ! $new_login ) {
			$email_for_fallback = ! empty( $user->user_email ) ? $user->user_email : $old_login;
			$new_login          = $this->generator->build_from_email( $email_for_fallback, $wp_id, $wp_id );
			$source             = 'email';
		}

		$new_nicename = $this->generator->ensure_unique_nicename( $new_login, $wp_id );

		$old_nickname = (string) get_user_meta( $wp_id, 'nickname', true );
		$old_display  = (string) $user->display_name;
		$old_nicename = (string) $user->user_nicename;

		$change_display = $this->should_update_display_name( $old_display, $old_login );
		$change_nick    = $this->should_update_nickname( $old_nickname, $old_login );

		$wp_user = get_userdata( $wp_id );
		$roles   = $wp_user ? implode( ',', (array) $wp_user->roles ) : '';

		return array(
			'wp_id'                 => $wp_id,
			'civi_id'               => $civi_id,
			'old_login'             => $old_login,
			'old_nicename'          => $old_nicename,
			'old_display_name'      => $old_display,
			'old_nickname'          => $old_nickname,
			'new_login'             => $new_login,
			'new_nicename'          => $new_nicename,
			'new_display_name'      => $change_display ? $new_login : $old_display,
			'new_nickname'          => $change_nick ? $new_login : $old_nickname,
			'source'                => $source,
			'changed_display_name'  => $change_display ? '1' : '0',
			'changed_nickname'      => $change_nick ? '1' : '0',
			'user_email'            => (string) $user->user_email,
			'roles'                 => $roles,
		);
	}

	/**
	 * Whether display_name still leaks the email login.
	 *
	 * @param string $display_name Current display name.
	 * @param string $old_login    Current (email) login.
	 * @return bool
	 */
	private function should_update_display_name( $display_name, $old_login ) {
		$display_name = (string) $display_name;
		if ( '' === $display_name ) {
			return false;
		}
		if ( WP_Civi_Username_Generator::looks_like_email( $display_name ) ) {
			return true;
		}
		return strtolower( $display_name ) === strtolower( $old_login );
	}

	/**
	 * Whether nickname still matches the old email login (or is itself an email).
	 *
	 * @param string $nickname  Current nickname.
	 * @param string $old_login Current (email) login.
	 * @return bool
	 */
	private function should_update_nickname( $nickname, $old_login ) {
		$nickname = (string) $nickname;
		if ( '' === $nickname ) {
			return false;
		}
		if ( WP_Civi_Username_Generator::looks_like_email( $nickname ) ) {
			return true;
		}
		return strtolower( $nickname ) === strtolower( $old_login );
	}

	/**
	 * Apply a batch of planned rows via direct SQL (skips WP hooks).
	 *
	 * @param array<int, array<string, mixed>> $rows Planned rows.
	 * @return array{ok:int, errors:int}
	 */
	private function apply_rows( array $rows ) {
		$ok     = 0;
		$errors = 0;

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		foreach ( $rows as $row ) {
			$fields = array(
				'user_login'    => $row['new_login'],
				'user_nicename' => $row['new_nicename'],
			);
			if ( ! empty( $row['changed_display_name'] ) && '1' === (string) $row['changed_display_name'] ) {
				$fields['display_name'] = $row['new_display_name'];
			}

			$nickname = null;
			if ( ! empty( $row['changed_nickname'] ) && '1' === (string) $row['changed_nickname'] ) {
				$nickname = $row['new_nickname'];
			}

			$written = $this->write_user_fields( (int) $row['wp_id'], $fields, $nickname, false );
			if ( $written ) {
				$ok++;
			} else {
				$errors++;
			}
		}

		if ( $errors > 0 ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'ok' => 0, 'errors' => $errors );
		}

		$wpdb->query( 'COMMIT' );

		foreach ( $rows as $row ) {
			clean_user_cache( (int) $row['wp_id'] );
		}

		return array( 'ok' => $ok, 'errors' => 0 );
	}

	/**
	 * Write user_login / nicename / display_name and optionally nickname.
	 *
	 * Uses direct $wpdb updates so profile_update / Civi sync hooks do not fire.
	 *
	 * @param int               $user_id      WP user ID.
	 * @param array<string,string> $fields    Columns to set on wp_users.
	 * @param string|null       $nickname     Nickname to set, or null to leave alone.
	 * @param bool              $clean_cache  Whether to clean_user_cache immediately.
	 * @return bool
	 */
	private function write_user_fields( $user_id, array $fields, $nickname = null, $clean_cache = true ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id <= 0 || empty( $fields ) ) {
			return false;
		}

		$allowed = array( 'user_login', 'user_nicename', 'display_name' );
		$data    = array();
		$formats = array();
		foreach ( $fields as $col => $val ) {
			if ( ! in_array( $col, $allowed, true ) ) {
				continue;
			}
			$data[ $col ] = (string) $val;
			$formats[]    = '%s';
		}

		if ( empty( $data ) ) {
			return false;
		}

		$result = $wpdb->update(
			$wpdb->users,
			$data,
			array( 'ID' => $user_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		if ( null !== $nickname ) {
			update_user_meta( $user_id, 'nickname', (string) $nickname );
		}

		if ( $clean_cache ) {
			clean_user_cache( $user_id );
		}

		return true;
	}

	/**
	 * Flush object / page caches that may hold stale usernames.
	 */
	private function flush_caches() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
	}

	/**
	 * Default CSV path outside the webroot when possible, else uploads.
	 *
	 * @param string $prefix Filename prefix.
	 * @return string
	 */
	public function default_csv_path( $prefix = 'migrate' ) {
		$stamp = gmdate( 'Ymd-His' );
		$name  = 'wp-civi-username-' . $prefix . '-' . $stamp . '.csv';

		// Prefer a directory above ABSPATH (outside webroot).
		$above = dirname( ABSPATH ) . '/wp-civi-username-migrations';
		if ( ( is_dir( $above ) && is_writable( $above ) ) || ( ! is_dir( $above ) && is_writable( dirname( ABSPATH ) ) ) ) {
			if ( ! is_dir( $above ) ) {
				wp_mkdir_p( $above );
			}
			if ( is_dir( $above ) && is_writable( $above ) ) {
				return trailingslashit( $above ) . $name;
			}
		}

		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'wp-civi-username-migrations';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return trailingslashit( $dir ) . $name;
	}

	/**
	 * Open a CSV file for writing and optionally write headers.
	 *
	 * @param string $path          Path.
	 * @param bool   $write_headers Whether to write header row.
	 * @return resource|false
	 */
	private function open_csv( $path, $write_headers = true ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$handle = fopen( $path, 'w' );
		if ( ! $handle ) {
			return false;
		}

		if ( $write_headers ) {
			fputcsv( $handle, self::CSV_HEADERS );
		}

		return $handle;
	}

	/**
	 * Convert a planned row to a CSV column array matching CSV_HEADERS order.
	 *
	 * @param array<string, mixed> $row Planned row.
	 * @return string[]
	 */
	private function row_to_csv( array $row ) {
		$out = array();
		foreach ( self::CSV_HEADERS as $col ) {
			$out[] = isset( $row[ $col ] ) ? (string) $row[ $col ] : '';
		}
		return $out;
	}
}
