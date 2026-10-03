<?php
/**
 * Security hardening that a theme can safely provide.
 *
 * - Browser security headers on every page.
 * - No WordPress version in the page source.
 * - XML-RPC off (a common target for password-guessing bots).
 * - Usernames are not listed to visitors (?author=1 and the REST users list).
 * - Per-visitor rate limit helper used by the enquiry form and the Design Studio.
 *
 * Logins, passwords, plugins and backups are handled in WordPress / Hostinger — see README.
 *
 * @package Looma_Apparels
 */

defined( 'ABSPATH' ) || exit;

/**
 * Security headers.
 */
function looma_security_headers() {
	if ( headers_sent() ) {
		return;
	}
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
}
add_action( 'send_headers', 'looma_security_headers' );
add_action( 'admin_init', 'looma_security_headers' );
add_action( 'login_init', 'looma_security_headers' );

// Do not advertise the WordPress version.
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

// XML-RPC is not used by this site.
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter(
	'wp_headers',
	function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);

/**
 * Stop ?author=N from revealing usernames to visitors.
 */
function looma_block_author_scan() {
	if ( ! is_admin() && ! is_user_logged_in() && isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'looma_block_author_scan', 1 );

/**
 * Hide the REST API user list from visitors (logged-in editors still have it).
 */
add_filter(
	'rest_endpoints',
	function ( $endpoints ) {
		if ( ! is_user_logged_in() ) {
			unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		}
		return $endpoints;
	}
);

/**
 * Count an action for this visitor and say whether they are over the limit.
 *
 * @param string $action Name of the action, e.g. 'enquiry'.
 * @param int    $limit  Allowed actions per window.
 * @param int    $window Window in seconds.
 * @return bool True when the visitor has used up the limit.
 */
function looma_rate_limited( $action, $limit, $window = HOUR_IN_SECONDS ) {
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key  = 'looma_rl_' . $action . '_' . md5( $ip );
	$hits = (int) get_transient( $key );
	if ( $hits >= $limit ) {
		return true;
	}
	set_transient( $key, $hits + 1, $window );
	return false;
}
