<?php
/**
 * File storage for the standalone PHP site.
 *
 * Files are named *.php and start with "<?php exit; ?>", so even if the web
 * server ignored data/.htaccess, opening one in a browser shows nothing.
 */

if ( ! defined( 'LOOMA_ATT_CORE' ) ) {
	exit;
}

class Looma_File_Io {
	const GUARD = "<?php exit; ?>\n";

	public static function read( $file ) {
		if ( ! is_file( $file ) ) {
			return null;
		}
		$raw = file_get_contents( $file );
		if ( 0 === strpos( $raw, self::GUARD ) ) {
			$raw = substr( $raw, strlen( self::GUARD ) );
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/** Write atomically (temp file + rename). */
	public static function write( $file, $data ) {
		$tmp  = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		if ( false === $json || false === file_put_contents( $tmp, self::GUARD . $json, LOCK_EX ) ) {
			throw new RuntimeException( 'Could not write ' . basename( $file ) . ' (is the data folder writable?)' );
		}
		@chmod( $tmp, 0600 );
		if ( ! rename( $tmp, $file ) ) {
			@unlink( $tmp );
			throw new RuntimeException( 'Could not save ' . basename( $file ) );
		}
	}

	/** Run $fn while holding an exclusive lock on $lock_file. */
	public static function with_lock( $lock_file, $fn ) {
		$h = fopen( $lock_file, 'c' );
		if ( ! $h ) {
			throw new RuntimeException( 'Could not open lock file (is the data folder writable?)' );
		}
		flock( $h, LOCK_EX );
		try {
			return $fn();
		} finally {
			flock( $h, LOCK_UN );
			fclose( $h );
		}
	}
}

class Looma_File_Storage implements Looma_Storage {
	private $dir;
	private $lock;

	public function __construct( $dir ) {
		$this->dir = rtrim( $dir, '/' );
	}

	public function load() {
		return Looma_File_Io::read( $this->dir . '/db.php' );
	}

	public function save( array $db ) {
		$file = $this->dir . '/db.php';
		if ( is_file( $file ) ) {
			@copy( $file, $this->dir . '/db-previous.php' );
		}
		Looma_File_Io::write( $file, $db );
	}

	public function backup( $name, array $db ) {
		$name = preg_replace( '/[^A-Za-z0-9_.-]/', '-', $name ) . '.php';
		Looma_File_Io::write( $this->dir . '/' . $name, $db );
		return $name;
	}

	public function lock() {
		$this->lock = fopen( $this->dir . '/.lock', 'c' );
		if ( $this->lock ) {
			flock( $this->lock, LOCK_EX );
		}
	}

	public function unlock() {
		if ( $this->lock ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock );
			$this->lock = null;
		}
	}
}

/** Admin sessions and failed-login counters, with expiry. */
class Looma_File_Kv implements Looma_Kv {
	private $file;

	public function __construct( $dir ) {
		$this->file = rtrim( $dir, '/' ) . '/sessions.php';
	}

	private function update( $fn ) {
		$file = $this->file;
		return Looma_File_Io::with_lock(
			$file . '.lock',
			function () use ( $file, $fn ) {
				$all = Looma_File_Io::read( $file ) ?: array();
				$now = time();
				foreach ( $all as $k => $v ) {
					if ( $v[1] < $now ) {
						unset( $all[ $k ] );
					}
				}
				$result = $fn( $all );
				Looma_File_Io::write( $file, $all );
				return $result;
			}
		);
	}

	public function get( $key ) {
		$all = Looma_File_Io::read( $this->file ) ?: array();
		return ( isset( $all[ $key ] ) && $all[ $key ][1] >= time() ) ? $all[ $key ][0] : false;
	}

	public function set( $key, $value, $ttl ) {
		$this->update(
			function ( &$all ) use ( $key, $value, $ttl ) {
				$all[ $key ] = array( $value, time() + $ttl );
			}
		);
	}

	public function delete( $key ) {
		$this->update(
			function ( &$all ) use ( $key ) {
				unset( $all[ $key ] );
			}
		);
	}
}
