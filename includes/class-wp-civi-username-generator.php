<?php
/**
 * Username generation and uniqueness helpers.
 *
 * Format (Civi contact): first initial + first 3 letters of surname + contact ID
 *   e.g. Jack Freedman, contact 4821 -> jfre4821
 *
 * Fallback (no usable Civi name): email-derived fragment + w + WP user ID
 *   e.g. jack.freedman@example.com, user 42 -> jfrew42
 *
 * Registration (no WP/Civi ID yet): fragment + short random token.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Civi_Username_Generator {

	/**
	 * Fragments that should not appear as the 3-letter surname slice.
	 *
	 * @var string[]
	 */
	const DENYLIST = array(
		'ass',
		'sex',
		'fag',
		'cum',
		'tit',
		'dik',
		'dic',
		'coc',
		'cok',
		'fuk',
		'fuc',
		'nig',
		'gay',
		'god',
		'hel',
		'jew',
		'jap',
		'wog',
		'poo',
		'pee',
		'anus',
	);

	/**
	 * Logins reserved / claimed during the current run (lowercase).
	 *
	 * @var array<string, true>
	 */
	private $reserved = array();

	/**
	 * Whether a string looks like an email address.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	public static function looks_like_email( $value ) {
		$value = (string) $value;
		return ( false !== strpos( $value, '@' ) );
	}

	/**
	 * Reserve a login for the current run so uniqueness checks see it.
	 *
	 * @param string $login Login to reserve.
	 */
	public function reserve( $login ) {
		$this->reserved[ strtolower( (string) $login ) ] = true;
	}

	/**
	 * Clear the in-run reservation set.
	 */
	public function clear_reservations() {
		$this->reserved = array();
	}

	/**
	 * Build username from Civi contact data.
	 *
	 * @param string   $first_name First name.
	 * @param string   $last_name  Last name.
	 * @param int      $contact_id CiviCRM contact ID.
	 * @param int|null $exclude_user_id WP user ID to ignore in uniqueness checks.
	 * @return string|false Username or false if names are unusable.
	 */
	public function build_from_civi( $first_name, $last_name, $contact_id, $exclude_user_id = null ) {
		$contact_id = (int) $contact_id;
		if ( $contact_id <= 0 ) {
			return false;
		}

		$fragment = $this->name_fragment( $first_name, $last_name );
		if ( ! $fragment ) {
			return false;
		}

		$base = $fragment . $contact_id;
		return $this->ensure_unique( $base, $exclude_user_id );
	}

	/**
	 * Build username from email when Civi data is unavailable.
	 *
	 * @param string   $email           Email address.
	 * @param int      $wp_user_id      WordPress user ID (used as uniqueness suffix).
	 * @param int|null $exclude_user_id WP user ID to ignore in uniqueness checks.
	 * @return string
	 */
	public function build_from_email( $email, $wp_user_id, $exclude_user_id = null ) {
		$wp_user_id = (int) $wp_user_id;
		$fragment   = $this->email_fragment( $email );

		if ( ! $fragment ) {
			$fragment = 'u';
		}

		// Prefix the WP ID with 'w' so email-fallback names cannot collide with Civi-ID names.
		$base = $fragment . 'w' . max( 1, $wp_user_id );
		return $this->ensure_unique( $base, $exclude_user_id );
	}

	/**
	 * Build a username for a brand-new registration (no WP/Civi ID yet).
	 *
	 * Prefers first/last name when available, otherwise email local-part.
	 * Appends a short random token for uniqueness.
	 *
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 * @param string $email      Email address.
	 * @return string
	 */
	public function build_for_registration( $first_name, $last_name, $email ) {
		$fragment = $this->name_fragment( $first_name, $last_name );
		if ( ! $fragment ) {
			$fragment = $this->email_fragment( $email );
		}
		if ( ! $fragment ) {
			$fragment = 'u';
		}

		$token = strtolower( wp_generate_password( 4, false, false ) );
		$base  = $fragment . $token;

		return $this->ensure_unique( $base, null );
	}

	/**
	 * Build the name fragment: first initial + up to 3 letters of surname.
	 *
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 * @return string|false Fragment or false if unusable.
	 */
	public function name_fragment( $first_name, $last_name ) {
		$first = $this->normalize_alpha( $first_name );
		$last  = $this->normalize_alpha( $last_name );

		if ( '' === $first || '' === $last ) {
			return false;
		}

		// Reject names that look like emails slipped into the name fields.
		if ( self::looks_like_email( $first_name ) || self::looks_like_email( $last_name ) ) {
			return false;
		}

		$initial  = substr( $first, 0, 1 );
		$surname  = substr( $last, 0, 3 );
		$fragment = $initial . $surname;

		if ( $this->is_denied( $surname ) || $this->is_denied( $fragment ) ) {
			// Shift the surname window by one character when possible.
			if ( strlen( $last ) > 3 ) {
				$surname  = substr( $last, 1, 3 );
				$fragment = $initial . $surname;
			}
			if ( $this->is_denied( $surname ) || $this->is_denied( $fragment ) ) {
				$fragment = $initial . 'x' . substr( $surname, 0, 2 );
			}
		}

		return $fragment;
	}

	/**
	 * Build a fragment from an email local-part.
	 *
	 * With two or more parts (split on . _ -): initial of first + 3 letters of last.
	 * With one part: first 3 characters.
	 *
	 * @param string $email Email address.
	 * @return string|false
	 */
	public function email_fragment( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || false === strpos( $email, '@' ) ) {
			// Treat as a bare local-part if no @.
			$local = $email;
		} else {
			$local = strstr( $email, '@', true );
		}

		if ( ! is_string( $local ) || '' === $local ) {
			return false;
		}

		// Drop plus-addressing tags.
		if ( false !== strpos( $local, '+' ) ) {
			$local = strstr( $local, '+', true );
		}

		$local = $this->normalize_alpha_keep_separators( $local );
		$parts = preg_split( '/[._\-]+/', $local, -1, PREG_SPLIT_NO_EMPTY );

		if ( empty( $parts ) ) {
			return false;
		}

		if ( count( $parts ) >= 2 ) {
			$first = $this->normalize_alpha( $parts[0] );
			$last  = $this->normalize_alpha( $parts[ count( $parts ) - 1 ] );
			if ( '' === $first || '' === $last ) {
				$joined = $this->normalize_alpha( implode( '', $parts ) );
				return $joined ? substr( $joined, 0, 3 ) : false;
			}
			$fragment = substr( $first, 0, 1 ) . substr( $last, 0, 3 );
		} else {
			$only     = $this->normalize_alpha( $parts[0] );
			$fragment = $only ? substr( $only, 0, 3 ) : '';
		}

		if ( '' === $fragment ) {
			return false;
		}

		if ( $this->is_denied( $fragment ) ) {
			$fragment = 'x' . $fragment;
		}

		return $fragment;
	}

	/**
	 * Ensure a candidate login is unique against wp_users and this run's reservations.
	 *
	 * Appends a numeric counter on collision. Case-insensitive.
	 *
	 * @param string   $base            Base login.
	 * @param int|null $exclude_user_id WP user ID to ignore (the user being renamed).
	 * @return string
	 */
	public function ensure_unique( $base, $exclude_user_id = null ) {
		$base = $this->sanitize_login( $base );
		if ( '' === $base ) {
			$base = 'u' . wp_generate_password( 6, false, false );
			$base = $this->sanitize_login( $base );
		}

		// Enforce WP 60-char limit with room for a collision suffix.
		$base = substr( $base, 0, 55 );

		$candidate = $base;
		$n         = 0;

		while ( $this->is_taken( $candidate, $exclude_user_id ) ) {
			$n++;
			$candidate = substr( $base, 0, 55 ) . $n;
			if ( $n > 10000 ) {
				$candidate = 'u' . wp_generate_password( 10, false, false );
				$candidate = $this->sanitize_login( $candidate );
				break;
			}
		}

		$this->reserve( $candidate );
		return $candidate;
	}

	/**
	 * Ensure a nicename is unique (wp_users.user_nicename has a non-unique key too).
	 *
	 * @param string   $nicename        Desired nicename.
	 * @param int|null $exclude_user_id WP user ID to ignore.
	 * @return string
	 */
	public function ensure_unique_nicename( $nicename, $exclude_user_id = null ) {
		global $wpdb;

		$nicename = sanitize_title( $nicename );
		$nicename = substr( $nicename, 0, 50 );
		if ( '' === $nicename ) {
			$nicename = 'user';
		}

		$candidate = $nicename;
		$n         = 0;

		while ( true ) {
			$sql = "SELECT ID FROM {$wpdb->users} WHERE user_nicename = %s";
			$args = array( $candidate );
			if ( $exclude_user_id ) {
				$sql   .= ' AND ID != %d';
				$args[] = (int) $exclude_user_id;
			}
			$sql .= ' LIMIT 1';

			$exists = $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $exists ) {
				break;
			}
			$n++;
			$candidate = substr( $nicename, 0, 45 ) . '-' . $n;
			if ( $n > 10000 ) {
				$candidate = 'user-' . wp_generate_password( 8, false, false );
				break;
			}
		}

		return $candidate;
	}

	/**
	 * Whether a login is already taken (DB or reserved this run).
	 *
	 * @param string   $login           Candidate login.
	 * @param int|null $exclude_user_id WP user ID to ignore.
	 * @return bool
	 */
	public function is_taken( $login, $exclude_user_id = null ) {
		$login_l = strtolower( (string) $login );

		if ( isset( $this->reserved[ $login_l ] ) ) {
			return true;
		}

		$existing_id = username_exists( $login );
		if ( $existing_id && (int) $existing_id !== (int) $exclude_user_id ) {
			return true;
		}

		// username_exists is case-sensitive in some WP versions; double-check via SQL.
		global $wpdb;
		$sql  = "SELECT ID FROM {$wpdb->users} WHERE user_login = %s";
		$args = array( $login );
		if ( $exclude_user_id ) {
			$sql   .= ' AND ID != %d';
			$args[] = (int) $exclude_user_id;
		}
		$sql .= ' LIMIT 1';

		$found = $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return ! empty( $found );
	}

	/**
	 * Normalize a string to lowercase a-z0-9 only (accents stripped).
	 *
	 * @param string $value Input.
	 * @return string
	 */
	public function normalize_alpha( $value ) {
		$value = (string) $value;
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		}
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]/', '', $value );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Normalize keeping separators for email local-part splitting.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	private function normalize_alpha_keep_separators( $value ) {
		$value = (string) $value;
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		}
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9._\-]/', '', $value );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Sanitize to a valid WP login (strict).
	 *
	 * @param string $login Candidate.
	 * @return string
	 */
	public function sanitize_login( $login ) {
		$login = strtolower( (string) $login );
		if ( function_exists( 'remove_accents' ) ) {
			$login = remove_accents( $login );
		}
		// WP sanitize_user with strict=true keeps a-z0-9 _ - . @ but we never want @.
		$login = sanitize_user( $login, true );
		$login = str_replace( '@', '', $login );
		$login = preg_replace( '/[^a-z0-9_\-\.]/', '', $login );
		return is_string( $login ) ? $login : '';
	}

	/**
	 * Check denylist.
	 *
	 * @param string $fragment Fragment.
	 * @return bool
	 */
	private function is_denied( $fragment ) {
		return in_array( strtolower( (string) $fragment ), self::DENYLIST, true );
	}
}
