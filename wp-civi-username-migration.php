<?php
/**
 * Plugin Name: WP Civi Username Migration
 * Description: Migrate email-based WordPress usernames to obfuscated unique usernames, and stop creating email usernames for new users.
 * Version: 1.0.0
 * Author: SCL
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_CIVI_USERNAME_MIGRATION_VERSION', '1.0.0' );
define( 'WP_CIVI_USERNAME_MIGRATION_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_CIVI_USERNAME_MIGRATION_FILE', __FILE__ );

require_once WP_CIVI_USERNAME_MIGRATION_PATH . 'includes/class-wp-civi-username-generator.php';
require_once WP_CIVI_USERNAME_MIGRATION_PATH . 'includes/class-wp-civi-username-civi.php';
require_once WP_CIVI_USERNAME_MIGRATION_PATH . 'includes/class-wp-civi-username-migrator.php';

/**
 * Prevent new users from being created with an email address as user_login.
 *
 * Applies to every wp_insert_user() path (WooCommerce, CiviCRM, WP All Import, etc.).
 * Only rewrites when the proposed login looks like an email; existing non-email logins
 * and update operations are left alone.
 *
 * @param string $user_login Sanitized username after pre_user_login.
 * @return string
 */
function wp_civi_username_migration_pre_user_login( $user_login ) {
	$user_login = (string) $user_login;

	if ( ! WP_Civi_Username_Generator::looks_like_email( $user_login ) ) {
		return $user_login;
	}

	// Existing email-login users (profile updates) already exist — leave them alone.
	// The migrator rewrites those via direct SQL. Only rewrite brand-new registrations.
	if ( username_exists( $user_login ) ) {
		return $user_login;
	}

	$first_name = '';
	$last_name  = '';
	$email      = $user_login;

	// Prefer values posted during registration / checkout when available.
	if ( ! empty( $_POST['billing_first_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$first_name = sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	} elseif ( ! empty( $_POST['first_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	if ( ! empty( $_POST['billing_last_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$last_name = sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	} elseif ( ! empty( $_POST['last_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$last_name = sanitize_text_field( wp_unslash( $_POST['last_name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	if ( ! empty( $_POST['billing_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$email = sanitize_email( wp_unslash( $_POST['billing_email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	} elseif ( ! empty( $_POST['user_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$email = sanitize_email( wp_unslash( $_POST['user_email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	} elseif ( ! empty( $_POST['email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$email = sanitize_email( wp_unslash( $_POST['email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	$generator = new WP_Civi_Username_Generator();
	$built     = $generator->build_for_registration( $first_name, $last_name, $email );

	return $built ? $built : $user_login;
}
add_filter( 'pre_user_login', 'wp_civi_username_migration_pre_user_login', 20 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WP_CIVI_USERNAME_MIGRATION_PATH . 'includes/class-wp-civi-username-cli.php';
	WP_CLI::add_command( 'civi usernames', 'WP_Civi_Username_CLI' );
}
