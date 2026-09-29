<?php
/**
 * Looma Attendance — standalone PHP site (no WordPress needed).
 *
 * Upload the contents of this folder to the folder of a (sub)domain, e.g.
 * attendance.loomaapparels.com. Staff use the main address; the admin panel
 * is at /admin. Data is kept in the data/ folder.
 */

define( 'LOOMA_ATT_CORE', true );
define( 'LOOMA_ATT_VERSION', '1.0.0' );

$root = __DIR__;
$cfg  = is_file( $root . '/config.php' ) ? (array) include $root . '/config.php' : array();
$data = isset( $cfg['data_dir'] ) ? rtrim( $cfg['data_dir'], '/' ) : $root . '/data';

require $root . '/includes/class-looma-time.php';
require $root . '/includes/class-looma-calc.php';
require $root . '/includes/class-looma-app.php';
require $root . '/lib/class-looma-file-store.php';

// ---------------------------------------------------------------- setup code

/**
 * Until the admin password is chosen, setting it needs a code that is saved in
 * data/setup-code.php, which only the site owner can open (hosting File
 * Manager). Creating a file named "reset-password" in the data folder removes
 * a forgotten admin password.
 */
function looma_site_prepare( $data ) {
	if ( ! is_dir( $data ) && ! @mkdir( $data, 0700, true ) ) {
		looma_site_fail( 'The data folder could not be created. Create a folder named "data" next to index.php and make it writable.' );
	}
	if ( ! is_writable( $data ) ) {
		looma_site_fail( 'The data folder is not writable. In your hosting File Manager, set its permissions to 755 (or 775).' );
	}
	if ( ! is_file( $data . '/.htaccess' ) ) {
		@file_put_contents( $data . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
	}
	if ( ! is_file( $data . '/index.html' ) ) {
		@file_put_contents( $data . '/index.html', '' );
	}

	if ( is_file( $data . '/reset-password' ) || is_file( $data . '/reset-password.txt' ) ) {
		$store = new Looma_File_Storage( $data );
		$store->lock();
		$db = $store->load();
		if ( $db ) {
			$db['settings']['adminPasswordHash'] = null;
			$store->save( $db );
		}
		$store->unlock();
		@unlink( $data . '/reset-password' );
		@unlink( $data . '/reset-password.txt' );
		@unlink( $data . '/setup-code.php' );
	}
}

function looma_site_setup_code( $data ) {
	$file = $data . '/setup-code.php';
	if ( is_file( $file ) && preg_match( '/Setup code: ([A-Z0-9-]+)/', file_get_contents( $file ), $m ) ) {
		return $m[1];
	}
	$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	$code  = '';
	for ( $i = 0; $i < 8; $i++ ) {
		$code .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	}
	$code = substr( $code, 0, 4 ) . '-' . substr( $code, 4 );
	file_put_contents(
		$file,
		"<?php exit; ?>\n\nLooma Attendance\nSetup code: $code\n\nEnter this code on the admin page to choose the admin password.\nThis file is deleted automatically once the password is set.\n"
	);
	@chmod( $file, 0600 );
	return $code;
}

function looma_site_fail( $msg ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "Looma Attendance: $msg\n";
	exit;
}

// ---------------------------------------------------------------- request

looma_site_prepare( $data );

$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url( $uri, PHP_URL_PATH );
$base = rtrim( str_replace( '\\', '/', dirname( $_SERVER['SCRIPT_NAME'] ?? '/' ) ), '/' );
$rel  = substr( $path, strlen( $base ) );
if ( '' === $rel || false === $rel || '/index.php' === $rel ) {
	$rel = '/';
}

// Without URL rewriting: ?page=admin and ?api=/api/...
if ( isset( $_GET['api'] ) ) {
	$target = (string) $_GET['api'];
	$query  = array();
	parse_str( (string) parse_url( $target, PHP_URL_QUERY ), $query );
	looma_site_api( (string) parse_url( $target, PHP_URL_PATH ), $query, $data, $base );
}
if ( isset( $_GET['page'] ) ) {
	looma_site_page( 'admin' === $_GET['page'] ? 'admin' : 'kiosk', $base, $root, true );
}

if ( '/' === $rel ) {
	looma_site_page( 'kiosk', $base, $root );
}
if ( '/admin' === $rel || '/admin/' === $rel ) {
	looma_site_page( 'admin', $base, $root );
}
if ( 0 === strpos( $rel, '/api/' ) ) {
	looma_site_api( $rel, $_GET, $data, $base );
}

http_response_code( 404 );
header( 'Content-Type: text/plain; charset=utf-8' );
echo "Not found\n";
exit;

// ---------------------------------------------------------------- pages & API

function looma_site_no_cache() {
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'Pragma: no-cache' );
	header( 'X-LiteSpeed-Cache-Control: no-cache' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: same-origin' );
}

/**
 * @param bool $no_rewrite link with ?page= / ?api= (for hosts without URL rewriting)
 */
function looma_site_page( $which, $base, $root, $no_rewrite = false ) {
	$file = $root . '/assets/' . ( 'admin' === $which ? 'admin.html' : 'index.html' );
	$html = file_get_contents( $file );
	$ver  = LOOMA_ATT_VERSION . '.' . filemtime( $file );
	$a    = $base . '/assets/';
	$h    = function ( $s ) {
		return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	};

	$api    = $no_rewrite ? $base . '/index.php?api=' : $base;
	$admin  = $no_rewrite ? $base . '/index.php?page=admin' : $base . '/admin';
	$kiosk  = $no_rewrite ? $base . '/index.php?page=kiosk' : $base . '/';
	$config = '<script>window.LOOMA=' . json_encode( array( 'apiBase' => $api ) ) . ';</script>';
	$html   = strtr(
		$html,
		array(
			'href="/logo.svg"'          => 'href="' . $h( $a . 'logo.svg' ) . '"',
			'src="/logo.svg"'           => 'src="' . $h( $a . 'logo.svg' ) . '"',
			'href="/app.css"'           => 'href="' . $h( $a . 'app.css?ver=' . $ver ) . '"',
			'<script src="/common.js">' => $config . '<script src="' . $h( $a . 'common.js?ver=' . $ver ) . '">',
			'src="/kiosk.js"'           => 'src="' . $h( $a . 'kiosk.js?ver=' . $ver ) . '"',
			'src="/admin.js"'           => 'src="' . $h( $a . 'admin.js?ver=' . $ver ) . '"',
			'href="/admin"'             => 'href="' . $h( $admin ) . '"',
			'href="/"'                  => 'href="' . $h( $kiosk ) . '"',
		)
	);
	looma_site_no_cache();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	echo $html;
	exit;
}

function looma_site_is_https() {
	return ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
		|| ( $_SERVER['SERVER_PORT'] ?? '' ) === '443'
		|| strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https';
}

function looma_site_api( $path, array $query, $data, $base ) {
	looma_site_no_cache();
	header( 'Content-Type: application/json; charset=utf-8' );

	$raw = file_get_contents( 'php://input' );
	if ( strlen( $raw ) > 100 * 1024 ) {
		http_response_code( 413 );
		echo json_encode( array( 'error' => 'Request too large' ) );
		exit;
	}
	$body = '' === $raw ? array() : json_decode( $raw, true );
	if ( '' !== $raw && ! is_array( $body ) ) {
		http_response_code( 400 );
		echo json_encode( array( 'error' => 'Invalid JSON' ) );
		exit;
	}

	global $cfg;
	$seeds = array();
	$seed  = __DIR__ . '/seed/looma-staff.php';
	if ( ( $cfg['seed'] ?? true ) && is_readable( $seed ) ) {
		$seeds[] = include $seed;
	}

	$code_hint = 'Find the setup code in your hosting File Manager, in the file data/setup-code.php of this site.';
	$can_setup = function ( $body ) use ( $data, $cfg ) {
		if ( ! empty( $cfg['open_setup'] ) ) {
			return null; // testing only
		}
		$given = strtoupper( trim( (string) ( $body['setupCode'] ?? '' ) ) );
		if ( '' === $given ) {
			return 'Enter the setup code.';
		}
		if ( ! hash_equals( looma_site_setup_code( $data ), $given ) ) {
			return 'Wrong setup code.';
		}
		return null;
	};

	try {
		$storage = new Looma_File_Storage( $data );
		$app     = new Looma_App(
			$storage,
			new Looma_File_Kv( $data ),
			$seeds,
			$can_setup,
			array(
				'setupCodeRequired' => true,
				'setupCodeHint'     => $code_hint,
			)
		);
		// make sure the setup code file exists while no password is set
		$state = $storage->load();
		if ( ! $state || empty( $state['settings']['adminPasswordHash'] ) ) {
			looma_site_setup_code( $data );
		}

		list( $status, $out, $cookie ) = $app->handle(
			array(
				'method' => strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ),
				'path'   => $path,
				'query'  => $query,
				'body'   => $body,
				'cookie' => (string) ( $_COOKIE[ Looma_App::COOKIE ] ?? '' ),
				'ip'     => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
			)
		);

		// once the password is chosen the setup code is no longer needed
		if ( '/api/admin/setup' === $path && 200 === $status ) {
			@unlink( $data . '/setup-code.php' );
		}
	} catch ( Throwable $e ) {
		error_log( 'Looma Attendance: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
		$status = 500;
		$out    = array( 'error' => 'Server error: ' . ( $e instanceof RuntimeException ? $e->getMessage() : 'see the PHP error log' ) );
		$cookie = null;
	}

	if ( $cookie ) {
		list( $value, $max_age ) = $cookie;
		setcookie(
			Looma_App::COOKIE,
			$value,
			array(
				'expires'  => $max_age ? time() + $max_age : time() - 3600,
				'path'     => ( '' === $base ? '/' : $base . '/' ),
				'secure'   => looma_site_is_https(),
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}
	http_response_code( $status );
	echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	exit;
}
