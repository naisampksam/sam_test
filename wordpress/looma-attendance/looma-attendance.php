<?php
/**
 * Plugin Name:       Looma Attendance
 * Description:       Staff attendance (clock in/out), planned leave requests, salary and sales incentive for Looma Apparels. Adds a clock-in page at /attendance and a password-protected admin panel at /attendance/admin.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Looma Apparels
 * License:           See LICENSE in the source repository
 * Text Domain:       looma-attendance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LOOMA_ATT_VERSION', '1.0.0' );
define( 'LOOMA_ATT_CORE', true );
define( 'LOOMA_ATT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LOOMA_ATT_URL', plugin_dir_url( __FILE__ ) );

require_once LOOMA_ATT_DIR . 'includes/class-looma-time.php';
require_once LOOMA_ATT_DIR . 'includes/class-looma-calc.php';
require_once LOOMA_ATT_DIR . 'includes/class-looma-app.php';

// ---------------------------------------------------------------- storage

/**
 * The whole data document lives in one row of a small table of our own
 * (plus any backups), so it is never cached or autoloaded by WordPress.
 */
class Looma_WP_Storage implements Looma_Storage {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'looma_attendance';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
			k varchar(191) NOT NULL,
			data longtext NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (k)
			) $charset;"
		);
		update_option( 'looma_att_db_version', LOOMA_ATT_VERSION, false );
	}

	public function load() {
		global $wpdb;
		if ( get_option( 'looma_att_db_version' ) !== LOOMA_ATT_VERSION ) {
			self::install();
		}
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM ' . self::table() . ' WHERE k = %s', 'main' ) ); // phpcs:ignore WordPress.DB
		if ( null === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	private function write( $key, $db ) {
		global $wpdb;
		$wpdb->replace(
			self::table(),
			array(
				'k'          => $key,
				'data'       => wp_json_encode( $db ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
	}

	public function save( array $db ) {
		$this->write( 'main', $db );
	}

	public function backup( $name, array $db ) {
		$this->write( $name, $db );
		return $name;
	}

	private function lock_name() {
		global $wpdb;
		return 'looma_att_' . $wpdb->prefix;
	}

	public function lock() {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $this->lock_name() ) );
	}

	public function unlock() {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name() ) );
	}
}

/** Admin sessions and failed-login counters, kept as transients. */
class Looma_WP_Kv implements Looma_Kv {
	public function get( $key ) {
		return get_transient( 'looma_att_' . $key );
	}

	public function set( $key, $value, $ttl ) {
		set_transient( 'looma_att_' . $key, $value, $ttl );
	}

	public function delete( $key ) {
		delete_transient( 'looma_att_' . $key );
	}
}

function looma_att_app() {
	$seeds = array();
	$file  = defined( 'LOOMA_ATT_SEED_FILE' ) ? LOOMA_ATT_SEED_FILE : LOOMA_ATT_DIR . 'seed/looma-staff.php';
	if ( $file && is_readable( $file ) ) {
		$seeds[] = include $file;
	}
	// On a public website, only a WordPress administrator may choose the first
	// attendance admin password (otherwise any visitor could claim it).
	// (define LOOMA_ATT_OPEN_SETUP in wp-config.php to allow it without logging in).
	$can_setup = function () {
		if ( defined( 'LOOMA_ATT_OPEN_SETUP' ) && LOOMA_ATT_OPEN_SETUP ) {
			return null;
		}
		return current_user_can( 'manage_options' ) ? null
			: 'For security, log in to WordPress as an administrator in this browser first, then set the password here.';
	};
	return new Looma_App( new Looma_WP_Storage(), new Looma_WP_Kv(), $seeds, $can_setup );
}

register_activation_hook(
	__FILE__,
	function () {
		Looma_WP_Storage::install();
		add_option( 'looma_att_slug', 'attendance' );
	}
);

// ---------------------------------------------------------------- addresses

function looma_att_slug() {
	$slug = trim( (string) get_option( 'looma_att_slug', 'attendance' ), '/' );
	return '' === $slug ? 'attendance' : $slug;
}

function looma_att_pretty() {
	return (bool) get_option( 'permalink_structure' );
}

/** Links to the two pages, and the base the pages use to call the API. */
function looma_att_urls() {
	$slug = looma_att_slug();
	if ( looma_att_pretty() ) {
		$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		return array(
			'kiosk' => home_url( "/$slug/" ),
			'admin' => home_url( "/$slug/admin" ),
			'api'   => "$home/$slug",
		);
	}
	$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	return array(
		'kiosk' => add_query_arg( 'looma_att', 'kiosk', home_url( '/' ) ),
		'admin' => add_query_arg( 'looma_att', 'admin', home_url( '/' ) ),
		'api'   => "$home/?looma_att_api=",
	);
}

// ---------------------------------------------------------------- routing

add_action( 'init', 'looma_att_route', 1 );

function looma_att_route() {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	$base = $home . '/' . looma_att_slug();

	// Plain permalinks: ?looma_att=kiosk|admin and ?looma_att_api=/api/...
	if ( isset( $_GET['looma_att_api'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$target = (string) wp_unslash( $_GET['looma_att_api'] ); // phpcs:ignore WordPress.Security
		$query  = array();
		$qs     = wp_parse_url( $target, PHP_URL_QUERY );
		if ( $qs ) {
			parse_str( $qs, $query );
		}
		looma_att_api( (string) wp_parse_url( $target, PHP_URL_PATH ), $query );
	}
	if ( isset( $_GET['looma_att'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		looma_att_page( 'admin' === $_GET['looma_att'] ? 'admin' : 'kiosk' ); // phpcs:ignore WordPress.Security
	}

	if ( $path === $base || $path === $base . '/' ) {
		looma_att_page( 'kiosk' );
	}
	if ( $path === $base . '/admin' || $path === $base . '/admin/' ) {
		looma_att_page( 'admin' );
	}
	if ( 0 === strpos( $path, $base . '/api/' ) ) {
		$query = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		looma_att_api( substr( $path, strlen( $base ) ), is_array( $query ) ? $query : array() );
	}
}

function looma_att_no_cache() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'looma attendance' );
	nocache_headers();
	header( 'X-LiteSpeed-Cache-Control: no-cache' );
}

/** Serve the clock-in page or the admin panel (full pages, not inside the theme). */
function looma_att_page( $which ) {
	$file = LOOMA_ATT_DIR . 'assets/' . ( 'admin' === $which ? 'admin.html' : 'index.html' );
	$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$urls = looma_att_urls();
	$ver  = LOOMA_ATT_VERSION . '.' . filemtime( $file );
	$a    = LOOMA_ATT_URL . 'assets/';

	$config = '<script>window.LOOMA=' . wp_json_encode( array( 'apiBase' => $urls['api'] ) ) . ';</script>';
	$html   = strtr(
		$html,
		array(
			'href="/logo.svg"'            => 'href="' . esc_url( $a . 'logo.svg' ) . '"',
			'src="/logo.svg"'             => 'src="' . esc_url( $a . 'logo.svg' ) . '"',
			'href="/app.css"'             => 'href="' . esc_url( $a . 'app.css?ver=' . $ver ) . '"',
			'<script src="/common.js">'   => $config . '<script src="' . esc_url( $a . 'common.js?ver=' . $ver ) . '">',
			'src="/kiosk.js"'             => 'src="' . esc_url( $a . 'kiosk.js?ver=' . $ver ) . '"',
			'src="/admin.js"'             => 'src="' . esc_url( $a . 'admin.js?ver=' . $ver ) . '"',
			'href="/admin"'               => 'href="' . esc_url( $urls['admin'] ) . '"',
			'href="/"'                    => 'href="' . esc_url( $urls['kiosk'] ) . '"',
		)
	);

	looma_att_no_cache();
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

function looma_att_api( $path, array $query ) {
	looma_att_no_cache();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
	$raw    = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( strlen( $raw ) > 100 * 1024 ) {
		status_header( 413 );
		echo wp_json_encode( array( 'error' => 'Request too large' ) );
		exit;
	}
	$body = '' === $raw ? array() : json_decode( $raw, true );
	if ( '' !== $raw && ! is_array( $body ) ) {
		status_header( 400 );
		echo wp_json_encode( array( 'error' => 'Invalid JSON' ) );
		exit;
	}

	try {
		list( $status, $data, $cookies ) = looma_att_app()->handle(
			array(
				'method' => $method,
				'path'   => $path,
				'query'  => $query,
				'body'   => $body,
				'cookie' => isset( $_COOKIE[ Looma_App::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Looma_App::COOKIE ] ) ) : '',
				'device' => isset( $_COOKIE[ Looma_App::DEVICE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Looma_App::DEVICE ] ) ) : '',
				'ip'     => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			)
		);
	} catch ( Throwable $e ) {
		error_log( 'Looma Attendance: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		$status = 500;
		$data   = array( 'error' => 'Server error' );
		$cookies = array();
	}

	foreach ( $cookies as $c ) {
		list( $name, $value, $max_age ) = $c;
		setcookie(
			$name,
			$value,
			array(
				'expires'  => $max_age ? time() + $max_age : time() - 3600,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}
	status_header( $status );
	echo wp_json_encode( $data );
	exit;
}

// ---------------------------------------------------------------- WordPress dashboard page

add_action(
	'admin_menu',
	function () {
		add_menu_page( 'Looma Attendance', 'Looma Attendance', 'manage_options', 'looma-attendance', 'looma_att_settings_page', 'dashicons-clock', 30 );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=looma-attendance' ) ) . '">Open</a>' );
		return $links;
	}
);

function looma_att_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$notice = '';
	if ( isset( $_POST['looma_att_action'] ) && check_admin_referer( 'looma_att_settings' ) ) {
		$action = sanitize_key( $_POST['looma_att_action'] );
		if ( 'slug' === $action ) {
			$slug = sanitize_title( wp_unslash( $_POST['looma_att_slug'] ?? '' ) );
			update_option( 'looma_att_slug', $slug ? $slug : 'attendance' );
			$notice = 'Address saved.';
		} elseif ( 'reset_password' === $action ) {
			$storage = new Looma_WP_Storage();
			$storage->lock();
			$db = $storage->load();
			if ( $db ) {
				$db['settings']['adminPasswordHash'] = null;
				$storage->save( $db );
			}
			$storage->unlock();
			$notice = 'The attendance admin password was removed. Open the admin panel to choose a new one.';
		}
	}
	$urls = looma_att_urls();
	?>
	<div class="wrap">
		<h1>Looma Attendance</h1>
		<?php if ( $notice ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<h2>Pages</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Clock-in page (staff)</th>
				<td><a href="<?php echo esc_url( $urls['kiosk'] ); ?>" target="_blank"><?php echo esc_html( $urls['kiosk'] ); ?></a>
				<p class="description">Open this on the office computer or tablet, or share it with staff. Give each person a PIN so nobody can clock in for someone else.</p></td>
			</tr>
			<tr>
				<th scope="row">Admin panel</th>
				<td><a href="<?php echo esc_url( $urls['admin'] ); ?>" target="_blank"><?php echo esc_html( $urls['admin'] ); ?></a>
				<p class="description">Attendance, leave requests, salary and incentive. Protected by its own password, chosen on the first visit.</p></td>
			</tr>
		</table>

		<?php if ( looma_att_pretty() ) : ?>
		<h2>Address</h2>
		<form method="post">
			<?php wp_nonce_field( 'looma_att_settings' ); ?>
			<input type="hidden" name="looma_att_action" value="slug">
			<p><code><?php echo esc_html( home_url( '/' ) ); ?></code>
				<input type="text" name="looma_att_slug" value="<?php echo esc_attr( looma_att_slug() ); ?>" class="regular-text" style="width:14em">
				<?php submit_button( 'Save address', 'secondary', 'submit', false ); ?></p>
			<p class="description">Must not be the same as an existing page's address.</p>
		</form>
		<?php else : ?>
		<p><em>Tip:</em> with <a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">pretty permalinks</a> turned on, the pages get a short address like <code><?php echo esc_html( home_url( '/attendance/' ) ); ?></code>.</p>
		<?php endif; ?>

		<h2>Forgot the admin password?</h2>
		<form method="post" onsubmit="return confirm('Remove the attendance admin password? The next visit to the admin panel will ask for a new one.');">
			<?php wp_nonce_field( 'looma_att_settings' ); ?>
			<input type="hidden" name="looma_att_action" value="reset_password">
			<p class="description">This only removes the password; no attendance data is changed.</p>
			<?php submit_button( 'Reset admin password', 'delete', 'submit', false ); ?>
		</form>
	</div>
	<?php
}
