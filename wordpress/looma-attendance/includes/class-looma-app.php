<?php
/**
 * The attendance API: the same endpoints and behaviour as server.js in the
 * Node.js version, so the same web pages work with either.
 *
 * Storage and short-lived values (admin sessions, failed-login counters) are
 * passed in, so this class does not depend on WordPress.
 */

if ( ! defined( 'LOOMA_ATT_CORE' ) ) {
	exit;
}

class Looma_Http_Error extends Exception {
	public $status;
	public $code_name;

	public function __construct( $status, $message, $code_name = null ) {
		parent::__construct( $message );
		$this->status    = $status;
		$this->code_name = $code_name;
	}
}

/**
 * Where the data document lives.
 */
interface Looma_Storage {
	/** @return array|null the saved document, or null if nothing is saved yet */
	public function load();

	public function save( array $db );

	/** Save a named copy; returns the name. */
	public function backup( $name, array $db );

	/** Serialise writers (e.g. two people clocking in at the same moment). */
	public function lock();

	public function unlock();
}

/**
 * Short-lived key/value store with expiry (WordPress transients in the plugin).
 */
interface Looma_Kv {
	public function get( $key );

	public function set( $key, $value, $ttl );

	public function delete( $key );
}

class Looma_App {

	const SESSION_TTL = 43200; // 12 hours
	const COOKIE      = 'looma_admin';
	const DEVICE      = 'looma_device';
	const DEVICE_TTL  = 34560000; // 400 days, the longest browsers keep a cookie; renewed on use

	/** Endpoints used by the staff clock-in page (limited to approved computers when switched on). */
	// Planned leave must be requested this many days ahead; a day off at shorter
	// notice without leave counts as unplanned leave.
	const LEAVE_NOTICE_DAYS = 2;

	const KIOSK_ROUTES = array( '/api/public/status', '/api/punch', '/api/manual', '/api/my', '/api/my/leave', '/api/leave-requests', '/api/leave-requests/:id/cancel', '/api/my/expenses', '/api/expenses', '/api/expenses/:id/cancel' );

	private $storage;
	private $kv;
	private $seeds;
	private $can_setup;
	private $setup_info;
	private $db;
	private $dirty = false;
	private $req;
	private $routes = array();

	public static function default_settings() {
		return array(
			'companyName'       => 'Looma Apparels',
			'timezone'          => 'Asia/Kolkata',
			'currency'          => '₹',
			'workStart'         => '09:00',
			'workEnd'           => '18:00',
			'hoursPerDay'       => 9,
			'weeklyOffs'        => array( 0 ), // 0 = Sunday
			'incentivePercent'  => 1,
			'halfDayShortHours' => 2,
			'fullDayShortHours' => 5,
			'restrictDevices'   => false,
			'adminPasswordHash' => null,
		);
	}

	public static function empty_db() {
		return array(
			'settings'      => self::default_settings(),
			'employees'     => array(),
			'sessions'      => array(),
			'leaves'        => array(),
			'leaveRequests' => array(),
			'months'        => array(),
			'devices'       => array(),
			'holidays'      => array(),
			'expenses'      => array(),
			'salaryPayments' => array(),
		);
	}

	/**
	 * @param array         $seeds     documents ({employees, sessions, leaves, months})
	 *                                 merged into the data the first time the app runs
	 * @param callable|null $can_setup  called with the request body; returns an error
	 *                                  message when the first admin password may not
	 *                                  be set by this visitor, or null to allow it
	 * @param array         $setup_info extra fields for /api/admin/state while no
	 *                                  password is set (e.g. setupCodeRequired)
	 */
	public function __construct( Looma_Storage $storage, Looma_Kv $kv, array $seeds = array(), $can_setup = null, array $setup_info = array() ) {
		$this->storage    = $storage;
		$this->kv         = $kv;
		$this->seeds      = $seeds;
		$this->can_setup  = $can_setup;
		$this->setup_info = $setup_info;
		$this->register_routes();
	}

	// ---------------------------------------------------------------- data

	private function load() {
		$raw = $this->storage->load();
		if ( null === $raw ) {
			$this->db = self::empty_db();
			$this->apply_seeds();
			$this->storage->save( $this->db );
			return;
		}
		$db             = array_merge( self::empty_db(), $raw );
		$db['settings'] = array_merge( self::default_settings(), is_array( $raw['settings'] ?? null ) ? $raw['settings'] : array() );
		foreach ( array( 'employees', 'sessions', 'leaves', 'leaveRequests', 'months', 'devices', 'holidays', 'expenses', 'salaryPayments' ) as $k ) {
			if ( ! is_array( $db[ $k ] ) ) {
				$db[ $k ] = array();
			}
		}
		$this->db = $db;
	}

	private function apply_seeds() {
		foreach ( $this->seeds as $seed ) {
			foreach ( array( 'employees', 'sessions', 'leaves' ) as $k ) {
				if ( ! empty( $seed[ $k ] ) ) {
					$this->db[ $k ] = array_merge( $this->db[ $k ], $seed[ $k ] );
				}
			}
			if ( ! empty( $seed['months'] ) ) {
				$this->db['months'] = array_merge( $this->db['months'], $seed['months'] );
			}
		}
	}

	private function save() {
		$this->dirty = true;
	}

	// ---------------------------------------------------------------- request

	/**
	 * Handle one API request.
	 *
	 * @param array $req method, path (e.g. /api/punch), query, body, cookie (admin
	 *                   session), device (approved-computer key), ip
	 * @return array [status, data, cookies] where cookies is a list of [name, value, max_age]
	 */
	public function handle( array $req ) {
		$this->req     = $req;
		$this->dirty   = false;
		$this->cookies = array();
		$write         = 'GET' !== $req['method'];

		try {
			$route  = null;
			$params = array();
			foreach ( $this->routes as $r ) {
				if ( $r['method'] === $req['method'] && preg_match( $r['re'], $req['path'], $m ) ) {
					$route = $r;
					foreach ( $r['keys'] as $i => $k ) {
						$params[ $k ] = rawurldecode( $m[ $i + 1 ] );
					}
					break;
				}
			}
			if ( ! $route ) {
				throw new Looma_Http_Error( 404, 'Not found' );
			}
			if ( $write ) {
				$this->storage->lock();
			}
			try {
				$this->load();
				// Absent days count as unplanned leave from the day this version first runs
				// (never for earlier months); the admin can change the date in Settings.
				if ( ! array_key_exists( 'sickLeaveFrom', $this->db['settings'] ) ) {
					$this->db['settings']['sickLeaveFrom'] = $this->now()['date'];
					if ( $write ) {
						$this->save();
					}
				}
				if ( $route['admin'] && ! $this->is_admin() ) {
					throw new Looma_Http_Error( 401, 'Admin login required' );
				}
				if ( $route['kiosk'] ) {
					$this->check_device( $write );
				}
				$body = is_array( $req['body'] ?? null ) ? $req['body'] : array();
				$data = call_user_func( $route['handler'], $params, $body, $req['query'] ?? array() );
				if ( is_array( $data ) && array_key_exists( '_cookie', $data ) ) {
					$this->cookies[] = array( self::COOKIE, $data['_cookie'][0], $data['_cookie'][1] );
					unset( $data['_cookie'] );
				}
				if ( $this->dirty ) {
					$this->storage->save( $this->db );
				}
			} finally {
				if ( $write ) {
					$this->storage->unlock();
				}
			}
			return array( 200, $data, $this->cookies );
		} catch ( Looma_Http_Error $e ) {
			$err = array( 'error' => $e->getMessage() );
			if ( $e->code_name ) {
				$err['code'] = $e->code_name;
			}
			return array( $e->status, $err, array() );
		}
	}

	private function route( $method, $pattern, $handler, $admin = false ) {
		$keys = array();
		$re   = preg_replace_callback(
			'/:(\w+)/',
			function ( $m ) use ( &$keys ) {
				$keys[] = $m[1];
				return '([^/]+)';
			},
			$pattern
		);
		$this->routes[] = array(
			'method'  => $method,
			're'      => '#^' . $re . '$#',
			'keys'    => $keys,
			'handler' => $handler,
			'admin'   => $admin,
			'kiosk'   => in_array( $pattern, self::KIOSK_ROUTES, true ),
		);
	}

	// ---------------------------------------------------------------- approved computers

	private $cookies = array();

	private function device_index() {
		$token = (string) ( $this->req['device'] ?? '' );
		if ( '' === $token ) {
			return null;
		}
		$hash = hash( 'sha256', $token );
		foreach ( $this->db['devices'] as $i => $d ) {
			if ( hash_equals( $d['tokenHash'], $hash ) ) {
				return $i;
			}
		}
		return null;
	}

	/** When only approved computers may use the staff page, refuse everyone else. */
	private function check_device( $write ) {
		$i = $this->device_index();
		if ( null !== $i ) {
			// keep the browser's key from expiring, and note when it was last used
			$this->cookies[] = array( self::DEVICE, $this->req['device'], self::DEVICE_TTL );
			if ( $write ) {
				$this->db['devices'][ $i ]['lastSeen'] = Looma_Time::iso_now();
				$this->save();
			}
			return;
		}
		if ( ! empty( $this->db['settings']['restrictDevices'] ) ) {
			throw new Looma_Http_Error( 403, 'This computer is not approved for staff clock-in.', 'device_not_approved' );
		}
	}

	private function device_view( $d, $current ) {
		return array(
			'id'        => $d['id'],
			'name'      => $d['name'],
			'createdAt' => $d['createdAt'],
			'lastSeen'  => $d['lastSeen'] ?? null,
			'current'   => $current,
		);
	}

	private function devices_state() {
		$cur  = $this->device_index();
		$list = array();
		foreach ( $this->db['devices'] as $i => $d ) {
			$list[] = $this->device_view( $d, $i === $cur );
		}
		return array(
			'restrict'        => ! empty( $this->db['settings']['restrictDevices'] ),
			'currentApproved' => null !== $cur,
			'devices'         => $list,
		);
	}

	// ---------------------------------------------------------------- helpers

	private static function bad( $msg ) {
		return new Looma_Http_Error( 400, $msg );
	}

	public static function uuid() {
		$b    = random_bytes( 16 );
		$b[6] = chr( ord( $b[6] ) & 0x0f | 0x40 );
		$b[8] = chr( ord( $b[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
	}

	private static function hash_secret( $s ) {
		return password_hash( (string) $s, PASSWORD_DEFAULT );
	}

	private static function verify_secret( $s, $stored ) {
		return $stored && is_string( $s ) && password_verify( $s, $stored );
	}

	private static function clean( $v, $max = 80 ) {
		if ( null === $v || is_array( $v ) ) {
			return '';
		}
		return mb_substr( trim( (string) $v ), 0, $max );
	}

	private static function num( $v ) {
		return is_numeric( $v ) ? (float) $v : null;
	}

	private function now() {
		return Looma_Time::now_parts( $this->db['settings']['timezone'] );
	}

	private function is_admin() {
		$token = $this->req['cookie'] ?? '';
		return $token && $this->kv->get( 'as_' . hash( 'sha256', $token ) );
	}

	private function new_admin_session() {
		$token = bin2hex( random_bytes( 32 ) );
		$this->kv->set( 'as_' . hash( 'sha256', $token ), 1, self::SESSION_TTL );
		return array( $token, self::SESSION_TTL );
	}

	// Basic brute-force protection for password / PIN checks.
	private function check_lock( $key ) {
		$f = $this->kv->get( 'fail_' . md5( $key ) );
		if ( is_array( $f ) && $f['until'] > time() ) {
			throw new Looma_Http_Error( 429, 'Too many wrong attempts. Try again in a few minutes.' );
		}
	}

	private function record_failure( $key ) {
		$k = 'fail_' . md5( $key );
		$f = $this->kv->get( $k );
		if ( ! is_array( $f ) ) {
			$f = array( 'count' => 0, 'until' => 0 );
		}
		$f['count']++;
		if ( $f['count'] >= 5 ) {
			$f['until'] = time() + 300;
			$f['count'] = 0;
		}
		$this->kv->set( $k, $f, 900 );
	}

	private function &find_employee( $id ) {
		foreach ( $this->db['employees'] as $i => $e ) {
			if ( $e['id'] === $id ) {
				return $this->db['employees'][ $i ];
			}
		}
		throw new Looma_Http_Error( 404, 'Employee not found' );
	}

	public static function public_employee( $e ) {
		return array(
			'id'          => $e['id'],
			'name'        => $e['name'],
			'position'    => $e['position'] ?? '',
			'basicSalary' => $e['basicSalary'] ?? 0,
			'incentive'   => ! isset( $e['incentive'] ) || false !== $e['incentive'],
			'active'      => ! empty( $e['active'] ),
			'hasPin'      => ! empty( $e['pinHash'] ),
			'joinedOn'    => $e['joinedOn'] ?? null,
			'phone'       => $e['phone'] ?? '',
			'email'       => $e['email'] ?? '',
		);
	}

	private function check_pin( $emp, $pin ) {
		if ( empty( $emp['pinHash'] ) ) {
			return;
		}
		$key = 'pin:' . $emp['id'];
		$this->check_lock( $key );
		if ( ! self::verify_secret( (string) $pin, $emp['pinHash'] ) ) {
			$this->record_failure( $key );
			throw new Looma_Http_Error( 401, 'Wrong PIN' );
		}
		$this->kv->delete( 'fail_' . md5( $key ) );
	}

	private function validate_session( $employee_id, $date, $tin, $tout, $ignore_id = null ) {
		if ( ! Looma_Time::is_date( $date ) ) {
			throw self::bad( 'Invalid date' );
		}
		if ( ! Looma_Time::is_time( $tin ) ) {
			throw self::bad( 'Invalid in time (use HH:MM)' );
		}
		if ( null !== $tout && '' !== $tout && ! Looma_Time::is_time( $tout ) ) {
			throw self::bad( 'Invalid out time (use HH:MM)' );
		}
		if ( $tout && $tout <= $tin ) {
			throw self::bad( 'Out time must be after in time' );
		}
		if ( $date > $this->now()['date'] ) {
			throw self::bad( 'Date cannot be in the future' );
		}
		$a1 = Looma_Time::to_minutes( $tin );
		$a2 = $tout ? Looma_Time::to_minutes( $tout ) : 1440;
		foreach ( $this->db['sessions'] as $s ) {
			if ( $s['employeeId'] !== $employee_id || $s['date'] !== $date || $s['id'] === $ignore_id || ! Looma_Calc::not_rejected( $s ) ) {
				continue;
			}
			$b1 = Looma_Time::to_minutes( $s['in'] );
			$b2 = ! empty( $s['out'] ) ? Looma_Time::to_minutes( $s['out'] ) : 1440;
			if ( $a1 < $b2 && $b1 < $a2 ) {
				throw self::bad( sprintf( 'Overlaps an existing entry (%s – %s)', $s['in'], ! empty( $s['out'] ) ? $s['out'] : 'still in' ) );
			}
		}
	}

	private function month_config( $month ) {
		$cfg    = isset( $this->db['months'][ $month ] ) && is_array( $this->db['months'][ $month ] ) ? $this->db['months'][ $month ] : array();
		$custom = isset( $cfg['workingDays'] );
		return array(
			'workingDays' => $custom ? $cfg['workingDays'] : Looma_Calc::default_working_days( $month, $this->db['settings']['weeklyOffs'], Looma_Calc::holiday_dates( $this->db ) ),
			'totalSales'  => isset( $cfg['totalSales'] ) ? $cfg['totalSales'] : 0,
			'custom'      => $custom,
		);
	}

	private function holiday_on( $date ) {
		foreach ( $this->db['holidays'] as $h ) {
			if ( $h['date'] === $date ) {
				return $h;
			}
		}
		return null;
	}

	private function month_holidays( $month ) {
		$list = array_values(
			array_filter(
				$this->db['holidays'],
				function ( $h ) use ( $month ) {
					return 0 === strpos( $h['date'], $month . '-' );
				}
			)
		);
		usort(
			$list,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);
		return $list;
	}

	/** Weekly off days and holidays: no work expected. */
	private function is_day_off( $date ) {
		return in_array( Looma_Time::weekday( $date ), $this->db['settings']['weeklyOffs'], false ) || null !== $this->holiday_on( $date );
	}

	private function employee_today( $e, $date, $time ) {
		$sessions = array();
		$stale    = array();
		foreach ( $this->db['sessions'] as $s ) {
			if ( $s['employeeId'] !== $e['id'] || ! Looma_Calc::not_rejected( $s ) ) {
				continue;
			}
			if ( $s['date'] === $date ) {
				$sessions[] = $s;
			} elseif ( empty( $s['out'] ) && $s['date'] < $date && Looma_Calc::counts( $s ) ) {
				$stale[ $s['date'] ] = true;
			}
		}
		$stale = array_keys( $stale );
		rsort( $stale );
		usort(
			$sessions,
			function ( $a, $b ) {
				return strcmp( $a['in'], $b['in'] );
			}
		);
		$open    = null;
		$minutes = 0;
		foreach ( $sessions as $s ) {
			if ( ! Looma_Calc::counts( $s ) ) {
				continue;
			}
			if ( ! $open && empty( $s['out'] ) ) {
				$open = $s;
			}
			$minutes += Looma_Calc::session_minutes( $s, $time );
		}
		$on_leave = false;
		foreach ( $this->db['leaves'] as $l ) {
			if ( $l['employeeId'] === $e['id'] && $l['date'] === $date ) {
				$on_leave = true;
				break;
			}
		}
		$pub = self::public_employee( $e );
		unset( $pub['basicSalary'], $pub['incentive'], $pub['phone'], $pub['email'] );
		return array_merge(
			$pub,
			array(
				'status'       => $open ? 'in' : 'out',
				'since'        => $open ? $open['in'] : null,
				'todayMinutes' => $minutes,
				'sessions'     => array_map(
					function ( $s ) {
						return array(
							'in'     => $s['in'],
							'out'    => $s['out'] ?? null,
							'source'  => $s['source'] ?? '',
							'pending' => 'pending' === ( $s['status'] ?? '' ),
						);
					},
					$sessions
				),
				'onLeave'      => $on_leave,
				'staleOpen'    => count( $stale ) > 0,
				'staleDates'   => $stale,
			)
		);
	}

	private function active_employees() {
		return array_values(
			array_filter(
				$this->db['employees'],
				function ( $e ) {
					return ! empty( $e['active'] );
				}
			)
		);
	}

	public static function request_view( $r ) {
		return array(
			'id'         => $r['id'],
			'employeeId' => $r['employeeId'],
			'dates'      => $r['dates'],
			'portion'    => $r['portion'],
			'reason'     => $r['reason'],
			'status'     => $r['status'],
			'createdAt'  => $r['createdAt'],
			'decidedAt'  => $r['decidedAt'] ?? null,
			'adminNote'  => $r['adminNote'] ?? '',
		);
	}

	/** Dates already taken for an employee: recorded leave, or pending/approved requests. */
	private function taken_dates( $employee_id ) {
		$taken = array();
		foreach ( $this->db['leaves'] as $l ) {
			if ( $l['employeeId'] === $employee_id ) {
				$taken[ $l['date'] ] = 'leave';
			}
		}
		foreach ( $this->db['leaveRequests'] as $r ) {
			if ( $r['employeeId'] === $employee_id && in_array( $r['status'], array( 'pending', 'approved' ), true ) ) {
				foreach ( $r['dates'] as $d ) {
					if ( ! isset( $taken[ $d ] ) ) {
						$taken[ $d ] = $r['status'];
					}
				}
			}
		}
		return $taken;
	}

	public static function manual_view( $s ) {
		return array(
			'id'         => $s['id'],
			'employeeId' => $s['employeeId'],
			'date'       => $s['date'],
			'in'         => $s['in'],
			'out'        => $s['out'] ?? null,
			'note'       => $s['note'] ?? '',
			'status'     => $s['status'] ?? 'approved',
			'createdAt'  => $s['createdAt'] ?? null,
			'decidedAt'  => $s['decidedAt'] ?? null,
			'adminNote'  => $s['adminNote'] ?? '',
		);
	}

	/** An employee's manual entries in a month that went through approval. */
	private function manual_list( $employee_id, $month ) {
		$list = array_values(
			array_filter(
				$this->db['sessions'],
				function ( $s ) use ( $employee_id, $month ) {
					return $s['employeeId'] === $employee_id && 'manual' === ( $s['source'] ?? '' )
						&& ! empty( $s['status'] ) && 0 === strpos( $s['date'], $month . '-' );
				}
			)
		);
		usort(
			$list,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] ) ?: strcmp( $a['in'], $b['in'] );
			}
		);
		return array_map( array( __CLASS__, 'manual_view' ), $list );
	}

	private function settings_view() {
		$s = $this->db['settings'];
		unset( $s['adminPasswordHash'] );
		return $s;
	}

	private static function expense_view( $x ) {
		return array(
			'id'          => $x['id'],
			'employeeId'  => $x['employeeId'],
			'date'        => $x['date'],
			'description' => $x['description'],
			'amount'      => $x['amount'],
			'status'      => $x['status'],
			'createdAt'   => $x['createdAt'],
			'decidedAt'   => $x['decidedAt'] ?? null,
			'adminNote'   => $x['adminNote'] ?? '',
			'source'      => $x['source'] ?? 'staff',
		);
	}

	private static function by_expense_date( $a, $b ) {
		return strcmp( $a['date'], $b['date'] ) ?: strcmp( $a['createdAt'], $b['createdAt'] );
	}

	/** Approved expenses are paid back with the salary; "paid" is the admin's tick. */
	private function with_expenses_and_payments( $month, $result ) {
		$prefix = $month . '-';
		foreach ( $result['rows'] as $i => $r ) {
			$items = array();
			foreach ( $this->db['expenses'] as $x ) {
				if ( $x['employeeId'] === $r['id'] && 'approved' === $x['status'] && 0 === strpos( $x['date'], $prefix ) ) {
					$items[] = $x;
				}
			}
			usort( $items, array( __CLASS__, 'by_expense_date' ) );
			$total = 0;
			foreach ( $items as $x ) {
				$total += $x['amount'];
			}
			$paid = null;
			foreach ( $this->db['salaryPayments'] as $x ) {
				if ( $x['month'] === $month && $x['employeeId'] === $r['id'] ) {
					$paid = $x['paidAt'];
				}
			}
			$emp = array();
			foreach ( $this->db['employees'] as $e ) {
				if ( $e['id'] === $r['id'] ) {
					$emp = $e;
				}
			}
			$total                   = Looma_Calc::round2( $total );
			$result['rows'][ $i ]    = array_merge(
				$r,
				array(
					'expenses'     => $total,
					'expenseItems' => array_map( array( __CLASS__, 'expense_view' ), $items ),
					'payable'      => Looma_Calc::round2( $r['netPay'] + $total ),
					'paidAt'       => $paid,
					'phone'        => $emp['phone'] ?? '',
					'email'        => $emp['email'] ?? '',
				)
			);
		}
		foreach ( array( 'expenses', 'payable' ) as $k ) {
			$t = 0;
			foreach ( $result['rows'] as $r ) {
				$t += $r[ $k ];
			}
			$result['totals'][ $k ] = Looma_Calc::round2( $t );
		}
		return $result;
	}

	private function new_expense( $emp, $body, $source ) {
		$date = $body['date'] ?? '';
		if ( ! Looma_Time::is_date( $date ) ) {
			throw self::bad( 'Pick the date of the expense' );
		}
		if ( $date > $this->now()['date'] ) {
			throw self::bad( "The date can't be in the future" );
		}
		$description = self::clean( $body['description'] ?? '', 200 );
		if ( '' === $description ) {
			throw self::bad( 'Please say what the expense was for' );
		}
		$amount = self::num( $body['amount'] ?? null );
		$amount = null === $amount ? null : Looma_Calc::round2( $amount );
		if ( null === $amount || $amount <= 0 || $amount > 1e7 ) {
			throw self::bad( 'Enter the amount spent' );
		}
		$x = array(
			'id'          => self::uuid(),
			'employeeId'  => $emp['id'],
			'date'        => $date,
			'description' => $description,
			'amount'      => $amount,
			'status'      => 'admin' === $source ? 'approved' : 'pending',
			'source'      => $source,
			'createdAt'   => Looma_Time::iso_now(),
		);
		if ( 'admin' === $source ) {
			$x['decidedAt'] = $x['createdAt'];
		}
		$this->db['expenses'][] = $x;
		$this->save();
		return self::expense_view( $x );
	}

	private function month_param( $v ) {
		return Looma_Time::is_month( $v ) ? $v : substr( $this->now()['date'], 0, 7 );
	}

	/** JSON objects that may be empty must not turn into [] */
	private static function obj( $a ) {
		return $a ? $a : new stdClass();
	}

	private function summary_for_json( $summary ) {
		foreach ( $summary['employees'] as &$e ) {
			$e['days'] = self::obj( $e['days'] );
		}
		return $summary;
	}

	// ---------------------------------------------------------------- routes

	private function register_routes() {
		$self = $this;

		// ---- public (kiosk)

		$this->route(
			'GET',
			'/api/public/status',
			function () use ( $self ) {
				$n = $self->now();
				$s = $self->db['settings'];
				return array(
					'companyName' => $s['companyName'],
					'timezone'    => $s['timezone'],
					'hoursPerDay' => $s['hoursPerDay'],
					'workStart'   => $s['workStart'],
					'workEnd'     => $s['workEnd'],
					'today'       => $n['date'],
					'now'         => $n['time'],
					'holiday'     => $self->holiday_on( $n['date'] ),
					'employees'   => array_map(
						function ( $e ) use ( $self, $n ) {
							return $self->employee_today( $e, $n['date'], $n['time'] );
						},
						$self->active_employees()
					),
				);
			}
		);

		$this->route(
			'POST',
			'/api/punch',
			function ( $p, $body ) use ( $self ) {
				$emp = $self->find_employee( $body['employeeId'] ?? '' );
				if ( empty( $emp['active'] ) ) {
					throw self::bad( 'Employee is inactive' );
				}
				$self->check_pin( $emp, $body['pin'] ?? '' );
				$n        = $self->now();
				$open_ix  = null;
				$covering = null;
				// Clock-in/out is always the current time. Only counted entries (button
				// or approved) matter; entries waiting for approval or rejected never move it.
				foreach ( $self->db['sessions'] as $i => $s ) {
					if ( $s['employeeId'] !== $emp['id'] || $s['date'] !== $n['date'] || ! Looma_Calc::counts( $s ) ) {
						continue;
					}
					if ( empty( $s['out'] ) ) {
						if ( null === $open_ix ) {
							$open_ix = $i;
						}
					} elseif ( $s['in'] <= $n['time'] && $n['time'] < $s['out'] ) {
						$covering = $s;
					}
				}
				$action = $body['action'] ?? '';
				if ( 'in' === $action ) {
					if ( null !== $open_ix ) {
						throw self::bad( sprintf( '%s is already clocked in since %s', $emp['name'], $self->db['sessions'][ $open_ix ]['in'] ) );
					}
					if ( $covering ) {
						throw self::bad( sprintf( '%s already has an entry from %s to %s that covers now', $emp['name'], $covering['in'], $covering['out'] ) );
					}
					$self->db['sessions'][] = array(
						'id'         => self::uuid(),
						'employeeId' => $emp['id'],
						'date'       => $n['date'],
						'in'         => $n['time'],
						'out'        => null,
						'source'     => 'button',
					);
				} elseif ( 'out' === $action ) {
					if ( null === $open_ix ) {
						throw self::bad( sprintf( '%s is not clocked in', $emp['name'] ) );
					}
					$in                                     = $self->db['sessions'][ $open_ix ]['in'];
					$self->db['sessions'][ $open_ix ]['out'] = $n['time'] > $in ? $n['time'] : $in;
				} else {
					throw self::bad( 'Unknown action' );
				}
				$self->save();
				return $self->employee_today( $emp, $n['date'], $n['time'] );
			}
		);

		$this->route(
			'POST',
			'/api/manual',
			function ( $p, $body ) use ( $self ) {
				$emp = $self->find_employee( $body['employeeId'] ?? '' );
				if ( empty( $emp['active'] ) ) {
					throw self::bad( 'Employee is inactive' );
				}
				$self->check_pin( $emp, $body['pin'] ?? '' );
				if ( empty( $body['out'] ) ) {
					throw self::bad( 'Please enter both in and out time' );
				}
				$self->validate_session( $emp['id'], $body['date'] ?? '', $body['in'] ?? '', $body['out'] );
				$self->db['sessions'][] = array(
					'id'         => self::uuid(),
					'employeeId' => $emp['id'],
					'date'       => $body['date'],
					'in'         => $body['in'],
					'out'        => $body['out'],
					'source'     => 'manual',
					'note'       => self::clean( $body['note'] ?? '', 200 ),
					'status'     => 'pending',
					'createdAt'  => Looma_Time::iso_now(),
				);
				$self->save();
				return array(
					'ok'      => true,
					'pending' => true,
				);
			}
		);

		// An employee's own monthly summary (PIN protected if a PIN is set)
		$this->route(
			'POST',
			'/api/my',
			function ( $p, $body ) use ( $self ) {
				$emp = $self->find_employee( $body['employeeId'] ?? '' );
				$self->check_pin( $emp, $body['pin'] ?? '' );
				$month   = $self->month_param( $body['month'] ?? '' );
				$summary = null;
				foreach ( Looma_Calc::attendance_summary( $self->db, $month, $self->now()['date'] )['employees'] as $e ) {
					if ( $e['id'] === $emp['id'] ) {
						$summary = $e;
					}
				}
				$cfg   = $self->month_config( $month );
				$leave = $summary ? $summary['leaveDays'] : 0;
				$hpd   = $self->db['settings']['hoursPerDay'];
				$days  = array();
				if ( $summary ) {
					foreach ( $summary['days'] as $date => $d ) {
						$half   = ! $d['autoFullDay'] && ( $d['autoHalfDay'] || ( $d['leave'] && 0.5 == $d['leave']['portion'] ) );
						$days[] = array(
							'date'     => $date,
							'minutes'  => $d['minutes'],
							'firstIn'  => $d['firstIn'],
							'lastOut'  => $d['lastOut'],
							'open'     => $d['open'],
							'leave'    => (bool) $d['leave'] || $d['autoFullDay'],
							'halfDay'  => $half,
							'sick'     => ! empty( $d['sick'] ),
							'sessions' => array_map(
								function ( $s ) {
									return array(
										'in'     => $s['in'],
										'out'    => $s['out'] ?? null,
										'source' => $s['source'] ?? '',
									);
								},
								$d['sessions']
							),
						);
					}
				}
				return array(
					'month'           => $month,
					'name'            => $emp['name'],
					'workingDays'     => $cfg['workingDays'],
					'hoursPerDay'     => $hpd,
					'requiredMinutes' => max( 0, $cfg['workingDays'] - $leave ) * $hpd * 60,
					'totalMinutes'    => $summary ? $summary['totalMinutes'] : 0,
					'daysPresent'     => $summary ? $summary['daysPresent'] : 0,
					'leaveDays'       => $leave,
					'days'            => $days,
					'manual'          => $self->manual_list( $emp['id'], $month ),
				);
			}
		);

		// ---- planned leave requests (staff ask, admin approves)

		$this->route(
			'POST',
			'/api/my/leave',
			function ( $p, $body ) use ( $self ) {
				$emp = $self->find_employee( $body['employeeId'] ?? '' );
				$self->check_pin( $emp, $body['pin'] ?? '' );
				$today = $self->now()['date'];
				$month = Looma_Time::is_month( $body['month'] ?? '' ) ? $body['month'] : substr( $today, 0, 7 );
				$taken = $self->taken_dates( $emp['id'] );
				$days  = array();
				foreach ( Looma_Time::month_dates( $month ) as $d ) {
					if ( isset( $taken[ $d ] ) ) {
						$days[ $d ] = $taken[ $d ];
					}
				}
				$mine = array_values(
					array_filter(
						$self->db['leaveRequests'],
						function ( $r ) use ( $emp ) {
							return $r['employeeId'] === $emp['id'];
						}
					)
				);
				usort(
					$mine,
					function ( $a, $b ) {
						return strcmp( $b['createdAt'], $a['createdAt'] );
					}
				);
				return array(
					'month'      => $month,
					'today'      => $today,
					'earliest'   => Looma_Time::add_days( $today, self::LEAVE_NOTICE_DAYS ),
					'noticeDays' => self::LEAVE_NOTICE_DAYS,
					'weeklyOffs' => $self->db['settings']['weeklyOffs'],
					'holidays'   => array_map(
						function ( $h ) {
							return array(
								'date' => $h['date'],
								'name' => $h['name'],
							);
						},
						$self->month_holidays( $month )
					),
					'days'       => self::obj( $days ),
					'requests'   => array_map( array( __CLASS__, 'request_view' ), array_slice( $mine, 0, 20 ) ),
				);
			}
		);

		$this->route(
			'POST',
			'/api/leave-requests',
			function ( $p, $body ) use ( $self ) {
				$emp = $self->find_employee( $body['employeeId'] ?? '' );
				if ( empty( $emp['active'] ) ) {
					throw self::bad( 'Employee is inactive' );
				}
				$self->check_pin( $emp, $body['pin'] ?? '' );
				$dates = isset( $body['dates'] ) && is_array( $body['dates'] ) ? array_values( array_unique( array_map( 'strval', $body['dates'] ) ) ) : array();
				sort( $dates );
				if ( ! $dates ) {
					throw self::bad( 'Pick at least one day' );
				}
				if ( count( $dates ) > 31 ) {
					throw self::bad( 'Too many days' );
				}
				foreach ( $dates as $d ) {
					if ( ! Looma_Time::is_date( $d ) ) {
						throw self::bad( 'Invalid date' );
					}
				}
				if ( count( array_unique( array_map( function ( $d ) { return substr( $d, 0, 7 ); }, $dates ) ) ) > 1 ) {
					throw self::bad( 'All days must be in the same month' );
				}
				$earliest = Looma_Time::add_days( $self->now()['date'], self::LEAVE_NOTICE_DAYS );
				if ( $dates[0] < $earliest ) {
					throw self::bad( 'Planned leave must be booked at least ' . self::LEAVE_NOTICE_DAYS . " days before (from $earliest). Without approved leave, a day off counts as unplanned leave." );
				}
				foreach ( $dates as $d ) {
					if ( $self->is_day_off( $d ) ) {
						$h = $self->holiday_on( $d );
						throw self::bad( $h ? "$d is a holiday ({$h['name']})" : "$d is a weekly off day" );
					}
				}
				$taken = $self->taken_dates( $emp['id'] );
				foreach ( $dates as $d ) {
					if ( isset( $taken[ $d ] ) ) {
						throw self::bad( "$d already has leave or a request" );
					}
				}
				$reason = self::clean( $body['reason'] ?? '', 300 );
				if ( '' === $reason ) {
					throw self::bad( 'Please give a reason' );
				}
				$r                           = array(
					'id'         => self::uuid(),
					'employeeId' => $emp['id'],
					'dates'      => $dates,
					'portion'    => ( 0.5 == ( $body['portion'] ?? 1 ) ) ? 0.5 : 1,
					'reason'     => $reason,
					'status'     => 'pending',
					'createdAt'  => Looma_Time::iso_now(),
				);
				$self->db['leaveRequests'][] = $r;
				$self->save();
				return self::request_view( $r );
			}
		);

		$this->route(
			'POST',
			'/api/leave-requests/:id/cancel',
			function ( $p, $body ) use ( $self ) {
				foreach ( $self->db['leaveRequests'] as $i => $r ) {
					if ( $r['id'] !== $p['id'] ) {
						continue;
					}
					if ( $r['employeeId'] !== ( $body['employeeId'] ?? '' ) ) {
						break;
					}
					$self->check_pin( $self->find_employee( $r['employeeId'] ), $body['pin'] ?? '' );
					if ( 'pending' !== $r['status'] ) {
						throw self::bad( 'Only pending requests can be cancelled' );
					}
					$self->db['leaveRequests'][ $i ]['status']    = 'cancelled';
					$self->db['leaveRequests'][ $i ]['decidedAt'] = Looma_Time::iso_now();
					$self->save();
					return self::request_view( $self->db['leaveRequests'][ $i ] );
				}
				throw new Looma_Http_Error( 404, 'Request not found' );
			}
		);

		// ---- admin auth

		$this->route(
			'GET',
			'/api/admin/state',
			function () use ( $self ) {
				$setup = empty( $self->db['settings']['adminPasswordHash'] );
				return array_merge(
					array(
						'setupRequired' => $setup,
						'loggedIn'      => (bool) $self->is_admin(),
						'companyName'   => $self->db['settings']['companyName'],
					),
					$setup ? $self->setup_info : array()
				);
			}
		);

		$this->route(
			'POST',
			'/api/admin/setup',
			function ( $p, $body ) use ( $self ) {
				if ( ! empty( $self->db['settings']['adminPasswordHash'] ) ) {
					throw new Looma_Http_Error( 403, 'Admin password is already set' );
				}
				$key = 'setup:' . ( $self->req['ip'] ?? '' );
				$self->check_lock( $key );
				$refuse = $self->can_setup ? call_user_func( $self->can_setup, $body ) : null;
				if ( $refuse ) {
					$self->record_failure( $key );
					throw new Looma_Http_Error( 403, $refuse );
				}
				$pw = (string) ( $body['password'] ?? '' );
				if ( strlen( $pw ) < 6 ) {
					throw self::bad( 'Password must be at least 6 characters' );
				}
				$self->db['settings']['adminPasswordHash'] = self::hash_secret( $pw );
				$self->save();
				return array(
					'ok'      => true,
					'_cookie' => $self->new_admin_session(),
				);
			}
		);

		$this->route(
			'POST',
			'/api/admin/login',
			function ( $p, $body ) use ( $self ) {
				$key = 'admin:' . ( $self->req['ip'] ?? '' );
				$self->check_lock( $key );
				if ( ! self::verify_secret( (string) ( $body['password'] ?? '' ), $self->db['settings']['adminPasswordHash'] ) ) {
					$self->record_failure( $key );
					throw new Looma_Http_Error( 401, 'Wrong password' );
				}
				$self->kv->delete( 'fail_' . md5( $key ) );
				return array(
					'ok'      => true,
					'_cookie' => $self->new_admin_session(),
				);
			}
		);

		$this->route(
			'POST',
			'/api/admin/logout',
			function () use ( $self ) {
				$token = $self->req['cookie'] ?? '';
				if ( $token ) {
					$self->kv->delete( 'as_' . hash( 'sha256', $token ) );
				}
				return array(
					'ok'      => true,
					'_cookie' => array( '', 0 ),
				);
			}
		);

		// Remove all attendance, leaves and monthly figures; keep employees and settings.
		$this->route(
			'POST',
			'/api/admin/clear-data',
			function ( $p, $body ) use ( $self ) {
				if ( ! self::verify_secret( (string) ( $body['password'] ?? '' ), $self->db['settings']['adminPasswordHash'] ) ) {
					throw new Looma_Http_Error( 401, 'Wrong admin password' );
				}
				$name    = 'db-before-clear-' . gmdate( 'Y-m-d\TH-i-s' );
				$backup  = $self->storage->backup( $name, $self->db );
				$removed = array(
					'sessions' => count( $self->db['sessions'] ),
					'leaves'   => count( $self->db['leaves'] ),
				);
				$self->db['sessions']      = array();
				$self->db['leaves']        = array();
				$self->db['months']        = array();
				$self->db['leaveRequests'] = array();
				$self->db['expenses']      = array();
				$self->db['salaryPayments'] = array();
				$self->save();
				return array(
					'ok'      => true,
					'removed' => $removed,
					'backup'  => $backup,
				);
			},
			true
		);

		$this->route(
			'POST',
			'/api/admin/password',
			function ( $p, $body ) use ( $self ) {
				if ( ! self::verify_secret( (string) ( $body['current'] ?? '' ), $self->db['settings']['adminPasswordHash'] ) ) {
					throw new Looma_Http_Error( 401, 'Current password is wrong' );
				}
				$pw = (string) ( $body['password'] ?? '' );
				if ( strlen( $pw ) < 6 ) {
					throw self::bad( 'New password must be at least 6 characters' );
				}
				$self->db['settings']['adminPasswordHash'] = self::hash_secret( $pw );
				$self->save();
				return array( 'ok' => true );
			},
			true
		);

		// ---- approved computers for the staff page

		$this->route(
			'GET',
			'/api/admin/devices',
			function () use ( $self ) {
				return $self->devices_state();
			},
			true
		);

		// Approve the computer this request comes from.
		$this->route(
			'POST',
			'/api/admin/devices',
			function ( $p, $body ) use ( $self ) {
				$name = self::clean( $body['name'] ?? '', 60 );
				if ( '' === $name ) {
					throw self::bad( 'Give this computer a name, e.g. Front desk' );
				}
				$i = $self->device_index();
				if ( null !== $i ) {
					$self->db['devices'][ $i ]['name'] = $name;
				} else {
					if ( count( $self->db['devices'] ) >= 20 ) {
						throw self::bad( 'Too many approved computers; remove some first' );
					}
					$token                 = bin2hex( random_bytes( 32 ) );
					$self->db['devices'][] = array(
						'id'        => self::uuid(),
						'name'      => $name,
						'tokenHash' => hash( 'sha256', $token ),
						'createdAt' => Looma_Time::iso_now(),
						'lastSeen'  => null,
					);
					$self->req['device']   = $token;
					$self->cookies[]       = array( self::DEVICE, $token, self::DEVICE_TTL );
				}
				$self->save();
				return $self->devices_state();
			},
			true
		);

		$this->route(
			'DELETE',
			'/api/admin/devices/:id',
			function ( $p ) use ( $self ) {
				$before              = count( $self->db['devices'] );
				$self->db['devices'] = array_values(
					array_filter(
						$self->db['devices'],
						function ( $d ) use ( $p ) {
							return $d['id'] !== $p['id'];
						}
					)
				);
				if ( count( $self->db['devices'] ) === $before ) {
					throw new Looma_Http_Error( 404, 'Computer not found' );
				}
				$self->save();
				return $self->devices_state();
			},
			true
		);

		// ---- settings

		$this->route(
			'GET',
			'/api/admin/settings',
			function () use ( $self ) {
				return array_merge( $self->settings_view(), array( 'today' => $self->now()['date'] ) );
			},
			true
		);

		$this->route(
			'PUT',
			'/api/admin/settings',
			function ( $p, $body ) use ( $self ) {
				$s    = $self->db['settings'];
				$next = $s;
				if ( isset( $body['companyName'] ) ) {
					$next['companyName'] = self::clean( $body['companyName'] ) ?: $s['companyName'];
				}
				if ( isset( $body['currency'] ) ) {
					$next['currency'] = self::clean( $body['currency'], 5 );
				}
				if ( isset( $body['timezone'] ) ) {
					if ( ! Looma_Time::is_valid_timezone( $body['timezone'] ) ) {
						throw self::bad( 'Unknown timezone' );
					}
					$next['timezone'] = $body['timezone'];
				}
				if ( isset( $body['workStart'] ) ) {
					if ( ! Looma_Time::is_time( $body['workStart'] ) ) {
						throw self::bad( 'Invalid start time' );
					}
					$next['workStart'] = $body['workStart'];
				}
				if ( isset( $body['workEnd'] ) ) {
					if ( ! Looma_Time::is_time( $body['workEnd'] ) ) {
						throw self::bad( 'Invalid end time' );
					}
					$next['workEnd'] = $body['workEnd'];
				}
				$num = function ( $key, $name, $min, $max ) use ( $body, &$next ) {
					if ( ! isset( $body[ $key ] ) ) {
						return;
					}
					$n = self::num( $body[ $key ] );
					if ( null === $n || $n < $min || $n > $max ) {
						throw self::bad( "$name must be between $min and $max" );
					}
					$next[ $key ] = $n;
				};
				$num( 'hoursPerDay', 'Hours per day', 1, 24 );
				$num( 'incentivePercent', 'Incentive %', 0, 100 );
				$num( 'fullDayShortHours', 'Full-day rule hours', 0, 24 );
				$num( 'halfDayShortHours', 'Half-day rule hours', 0, 24 );
				if ( isset( $body['restrictDevices'] ) ) {
					$next['restrictDevices'] = (bool) $body['restrictDevices'];
				}
				if ( isset( $body['sickLeaveFrom'] ) ) {
					if ( '' !== $body['sickLeaveFrom'] && ! Looma_Time::is_date( $body['sickLeaveFrom'] ) ) {
						throw self::bad( 'Invalid unplanned leave start date' );
					}
					$next['sickLeaveFrom'] = $body['sickLeaveFrom'];
				}
				if ( isset( $body['weeklyOffs'] ) && is_array( $body['weeklyOffs'] ) ) {
					$offs = array();
					foreach ( $body['weeklyOffs'] as $d ) {
						$d = (int) $d;
						if ( $d >= 0 && $d <= 6 && ! in_array( $d, $offs, true ) ) {
							$offs[] = $d;
						}
					}
					$next['weeklyOffs'] = $offs;
				}
				$self->db['settings'] = $next;
				$self->save();
				return $self->settings_view();
			},
			true
		);

		// ---- employees

		$this->route(
			'GET',
			'/api/admin/employees',
			function () use ( $self ) {
				return array_map( array( __CLASS__, 'public_employee' ), $self->db['employees'] );
			},
			true
		);

		$apply = function ( &$e, $body, $creating ) {
			if ( $creating || isset( $body['name'] ) ) {
				$name = self::clean( $body['name'] ?? '' );
				if ( '' === $name ) {
					throw self::bad( 'Name is required' );
				}
				$e['name'] = $name;
			}
			if ( $creating || isset( $body['position'] ) ) {
				$e['position'] = self::clean( $body['position'] ?? '' );
			}
			if ( $creating || isset( $body['basicSalary'] ) ) {
				$sal = self::num( $body['basicSalary'] ?? null );
				if ( null === $sal || $sal < 0 ) {
					throw self::bad( 'Basic salary must be a positive number' );
				}
				$e['basicSalary'] = $sal;
			}
			if ( isset( $body['joinedOn'] ) && '' !== $body['joinedOn'] ) {
				if ( ! Looma_Time::is_date( $body['joinedOn'] ) ) {
					throw self::bad( 'Invalid joining date' );
				}
				$e['joinedOn'] = $body['joinedOn'];
			}
			if ( isset( $body['active'] ) ) {
				$e['active'] = (bool) $body['active'];
			}
			if ( isset( $body['incentive'] ) ) {
				$e['incentive'] = (bool) $body['incentive'];
			}
			if ( isset( $body['phone'] ) ) {
				// WhatsApp number: digits with an optional + and spaces/dashes
				$phone  = trim( (string) $body['phone'] );
				$digits = preg_replace( '/\D/', '', $phone );
				if ( '' !== $phone && ( ! preg_match( '/^\+?[\d\s-]+$/', $phone ) || strlen( $digits ) < 10 || strlen( $digits ) > 15 ) ) {
					throw self::bad( 'Enter a valid phone number (10 digits, or with country code)' );
				}
				$e['phone'] = $phone;
			}
			if ( isset( $body['email'] ) ) {
				$email = trim( (string) $body['email'] );
				if ( '' !== $email && ( strlen( $email ) > 120 || ! preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email ) ) ) {
					throw self::bad( 'Enter a valid email address' );
				}
				$e['email'] = $email;
			}
			if ( ! empty( $body['removePin'] ) ) {
				$e['pinHash'] = null;
			}
			if ( ! empty( $body['pin'] ) ) {
				if ( ! preg_match( '/^\d{4,6}$/', (string) $body['pin'] ) ) {
					throw self::bad( 'PIN must be 4–6 digits' );
				}
				$e['pinHash'] = self::hash_secret( (string) $body['pin'] );
			}
		};

		$this->route(
			'POST',
			'/api/admin/employees',
			function ( $p, $body ) use ( $self, $apply ) {
				$e = array(
					'id'       => self::uuid(),
					'active'   => true,
					'pinHash'  => null,
					'joinedOn' => $self->now()['date'],
				);
				$apply( $e, $body, true );
				$self->db['employees'][] = $e;
				$self->save();
				return self::public_employee( $e );
			},
			true
		);

		$this->route(
			'PUT',
			'/api/admin/employees/:id',
			function ( $p, $body ) use ( $self, $apply ) {
				$e    = &$self->find_employee( $p['id'] );
				$copy = $e;
				$apply( $copy, $body, false );
				$e = $copy;
				$self->save();
				return self::public_employee( $e );
			},
			true
		);

		$this->route(
			'DELETE',
			'/api/admin/employees/:id',
			function ( $p ) use ( $self ) {
				$e   = $self->find_employee( $p['id'] );
				$not = function ( $x ) use ( $e ) {
					return ( $x['employeeId'] ?? $x['id'] ) !== $e['id'];
				};
				$self->db['employees'] = array_values(
					array_filter(
						$self->db['employees'],
						function ( $x ) use ( $e ) {
							return $x['id'] !== $e['id'];
						}
					)
				);
				$self->db['sessions']  = array_values( array_filter( $self->db['sessions'], $not ) );
				$self->db['leaves']    = array_values( array_filter( $self->db['leaves'], $not ) );
				$self->db['expenses']  = array_values( array_filter( $self->db['expenses'], $not ) );
				$self->db['salaryPayments'] = array_values( array_filter( $self->db['salaryPayments'], $not ) );
				$self->save();
				return array( 'ok' => true );
			},
			true
		);

		// ---- attendance

		$this->route(
			'GET',
			'/api/admin/today',
			function () use ( $self ) {
				$n = $self->now();
				return array(
					'today'     => $n['date'],
					'now'       => $n['time'],
					'employees' => array_map(
						function ( $e ) use ( $self, $n ) {
							return $self->employee_today( $e, $n['date'], $n['time'] );
						},
						$self->active_employees()
					),
				);
			},
			true
		);

		$this->route(
			'GET',
			'/api/admin/attendance',
			function ( $p, $b, $q ) use ( $self ) {
				$month   = $self->month_param( $q['month'] ?? '' );
				$today   = $self->now()['date'];
				$summary = Looma_Calc::attendance_summary( $self->db, $month, $today );
				$cfg     = $self->month_config( $month );
				$hpd     = $self->db['settings']['hoursPerDay'];
				foreach ( $summary['employees'] as &$e ) {
					$e['requiredMinutes'] = max( 0, $cfg['workingDays'] - $e['leaveDays'] ) * $hpd * 60;
				}
				unset( $e );
				return array_merge(
					$self->summary_for_json( $summary ),
					$cfg,
					array(
						'hoursPerDay' => $hpd,
						'workStart'   => $self->db['settings']['workStart'],
						'today'       => $today,
						'holidays'    => $self->month_holidays( $month ),
					)
				);
			},
			true
		);

		$this->route(
			'POST',
			'/api/admin/sessions',
			function ( $p, $body ) use ( $self ) {
				$emp = $self->find_employee( $body['employeeId'] ?? '' );
				$out = ! empty( $body['out'] ) ? $body['out'] : null;
				$self->validate_session( $emp['id'], $body['date'] ?? '', $body['in'] ?? '', $out );
				$s                      = array(
					'id'         => self::uuid(),
					'employeeId' => $emp['id'],
					'date'       => $body['date'],
					'in'         => $body['in'],
					'out'        => $out,
					'source'     => 'admin',
					'note'       => self::clean( $body['note'] ?? '', 200 ),
				);
				$self->db['sessions'][] = $s;
				$self->save();
				return $s;
			},
			true
		);

		$this->route(
			'PUT',
			'/api/admin/sessions/:id',
			function ( $p, $body ) use ( $self ) {
				foreach ( $self->db['sessions'] as $i => $s ) {
					if ( $s['id'] !== $p['id'] ) {
						continue;
					}
					$next = $s;
					if ( isset( $body['in'] ) ) {
						$next['in'] = $body['in'];
					}
					if ( array_key_exists( 'out', $body ) ) {
						$next['out'] = ( '' === $body['out'] ) ? null : $body['out'];
					}
					if ( isset( $body['date'] ) ) {
						$next['date'] = $body['date'];
					}
					$self->validate_session( $s['employeeId'], $next['date'], $next['in'], $next['out'], $s['id'] );
					$next['edited']             = true;
					$self->db['sessions'][ $i ] = $next;
					$self->save();
					return $next;
				}
				throw new Looma_Http_Error( 404, 'Entry not found' );
			},
			true
		);

		// Manual entries waiting for approval (and the most recent decisions)
		$this->route(
			'GET',
			'/api/admin/manual-entries',
			function () use ( $self ) {
				$pending = array();
				$decided = array();
				foreach ( $self->db['sessions'] as $s ) {
					if ( 'manual' !== ( $s['source'] ?? '' ) || empty( $s['status'] ) ) {
						continue;
					}
					if ( 'pending' === $s['status'] ) {
						$pending[] = $s;
					} elseif ( ! empty( $s['decidedAt'] ) ) {
						$decided[] = $s;
					}
				}
				usort(
					$pending,
					function ( $a, $b ) {
						return strcmp( $a['date'], $b['date'] ) ?: strcmp( $a['in'], $b['in'] );
					}
				);
				usort(
					$decided,
					function ( $a, $b ) {
						return strcmp( $b['decidedAt'], $a['decidedAt'] );
					}
				);
				return array(
					'pending' => array_map( array( __CLASS__, 'manual_view' ), $pending ),
					'decided' => array_map( array( __CLASS__, 'manual_view' ), array_slice( $decided, 0, 20 ) ),
				);
			},
			true
		);

		$this->route(
			'POST',
			'/api/admin/sessions/:id/:decision',
			function ( $p, $body ) use ( $self ) {
				foreach ( $self->db['sessions'] as $i => $s ) {
					if ( $s['id'] !== $p['id'] ) {
						continue;
					}
					if ( empty( $s['status'] ) ) {
						break;
					}
					if ( 'pending' !== $s['status'] ) {
						throw self::bad( 'This entry is already ' . $s['status'] );
					}
					if ( 'approve' === $p['decision'] ) {
						// never count the same time twice (e.g. the person also clocked in during it)
						try {
							$self->validate_session( $s['employeeId'], $s['date'], $s['in'], $s['out'] ?? null, $s['id'] );
						} catch ( Looma_Http_Error $e ) {
							throw self::bad( "Can't approve: " . preg_replace( '/^Overlaps/', 'it overlaps', $e->getMessage() ) . '. Edit or delete one of the two entries first, or reject this one.' );
						}
						$s['status'] = 'approved';
					} elseif ( 'reject' === $p['decision'] ) {
						$s['status'] = 'rejected';
					} else {
						throw new Looma_Http_Error( 404, 'Not found' );
					}
					$s['adminNote']             = self::clean( $body['note'] ?? '', 200 );
					$s['decidedAt']             = Looma_Time::iso_now();
					$self->db['sessions'][ $i ] = $s;
					$self->save();
					return self::manual_view( $s );
				}
				throw new Looma_Http_Error( 404, 'Entry not found' );
			},
			true
		);

		$this->route(
			'DELETE',
			'/api/admin/sessions/:id',
			function ( $p ) use ( $self ) {
				$before               = count( $self->db['sessions'] );
				$self->db['sessions'] = array_values(
					array_filter(
						$self->db['sessions'],
						function ( $x ) use ( $p ) {
							return $x['id'] !== $p['id'];
						}
					)
				);
				if ( count( $self->db['sessions'] ) === $before ) {
					throw new Looma_Http_Error( 404, 'Entry not found' );
				}
				$self->save();
				return array( 'ok' => true );
			},
			true
		);

		// ---- leaves

		$this->route(
			'GET',
			'/api/admin/leaves',
			function ( $p, $b, $q ) use ( $self ) {
				$prefix = $self->month_param( $q['month'] ?? '' ) . '-';
				$list   = array_values(
					array_filter(
						$self->db['leaves'],
						function ( $l ) use ( $prefix ) {
							return 0 === strpos( $l['date'], $prefix );
						}
					)
				);
				usort(
					$list,
					function ( $a, $b ) {
						return strcmp( $a['date'], $b['date'] );
					}
				);
				return $list;
			},
			true
		);

		$this->route(
			'POST',
			'/api/admin/leaves',
			function ( $p, $body ) use ( $self ) {
				$emp  = $self->find_employee( $body['employeeId'] ?? '' );
				$from = $body['date'] ?? '';
				$to   = ! empty( $body['toDate'] ) ? $body['toDate'] : $from;
				if ( ! Looma_Time::is_date( $from ) || ! Looma_Time::is_date( $to ) || $to < $from ) {
					throw self::bad( 'Invalid date range' );
				}
				$portion = ( 0.5 == ( $body['portion'] ?? 1 ) ) ? 0.5 : 1;
				$added   = array();
				for ( $i = 0; $i < 62; $i++ ) {
					$date = Looma_Time::add_days( $from, $i );
					if ( $date > $to ) {
						break;
					}
					if ( empty( $body['includeOffDays'] ) && $self->is_day_off( $date ) ) {
						continue;
					}
					foreach ( $self->db['leaves'] as $l ) {
						if ( $l['employeeId'] === $emp['id'] && $l['date'] === $date ) {
							continue 2;
						}
					}
					$l                    = array(
						'id'         => self::uuid(),
						'employeeId' => $emp['id'],
						'date'       => $date,
						'portion'    => $portion,
						'note'       => self::clean( $body['note'] ?? '', 200 ),
					);
					$self->db['leaves'][] = $l;
					$added[]              = $l;
				}
				if ( ! $added ) {
					throw self::bad( 'No new leave days added (already recorded, weekly off or holiday)' );
				}
				$self->save();
				return $added;
			},
			true
		);

		$this->route(
			'GET',
			'/api/admin/leave-requests',
			function () use ( $self ) {
				$list = $self->db['leaveRequests'];
				usort(
					$list,
					function ( $a, $b ) {
						$pa = 'pending' === $a['status'] ? 1 : 0;
						$pb = 'pending' === $b['status'] ? 1 : 0;
						return ( $pb - $pa ) ?: strcmp( $b['createdAt'], $a['createdAt'] );
					}
				);
				return array_map( array( __CLASS__, 'request_view' ), array_slice( $list, 0, 100 ) );
			},
			true
		);

		$this->route(
			'POST',
			'/api/admin/leave-requests/:id/:decision',
			function ( $p, $body ) use ( $self ) {
				foreach ( $self->db['leaveRequests'] as $i => $r ) {
					if ( $r['id'] !== $p['id'] ) {
						continue;
					}
					if ( 'pending' !== $r['status'] ) {
						throw self::bad( 'This request is already ' . $r['status'] );
					}
					if ( 'approve' === $p['decision'] ) {
						$existing = array();
						foreach ( $self->db['leaves'] as $l ) {
							if ( $l['employeeId'] === $r['employeeId'] ) {
								$existing[ $l['date'] ] = true;
							}
						}
						foreach ( $r['dates'] as $date ) {
							if ( isset( $existing[ $date ] ) ) {
								continue;
							}
							$self->db['leaves'][] = array(
								'id'         => self::uuid(),
								'employeeId' => $r['employeeId'],
								'date'       => $date,
								'portion'    => $r['portion'],
								'note'       => 'Planned: ' . $r['reason'],
								'requestId'  => $r['id'],
							);
						}
						$r['status'] = 'approved';
					} elseif ( 'reject' === $p['decision'] ) {
						$r['status'] = 'rejected';
					} else {
						throw new Looma_Http_Error( 404, 'Not found' );
					}
					$r['adminNote']                  = self::clean( $body['note'] ?? '', 200 );
					$r['decidedAt']                  = Looma_Time::iso_now();
					$self->db['leaveRequests'][ $i ] = $r;
					$self->save();
					return self::request_view( $r );
				}
				throw new Looma_Http_Error( 404, 'Request not found' );
			},
			true
		);

		$this->route(
			'DELETE',
			'/api/admin/leaves/:id',
			function ( $p ) use ( $self ) {
				$before             = count( $self->db['leaves'] );
				$self->db['leaves'] = array_values(
					array_filter(
						$self->db['leaves'],
						function ( $x ) use ( $p ) {
							return $x['id'] !== $p['id'];
						}
					)
				);
				if ( count( $self->db['leaves'] ) === $before ) {
					throw new Looma_Http_Error( 404, 'Leave not found' );
				}
				$self->save();
				return array( 'ok' => true );
			},
			true
		);

		// ---- holidays

		$this->route(
			'GET',
			'/api/admin/holidays',
			function ( $p, $b, $q ) use ( $self ) {
				$month = $self->month_param( $q['month'] ?? '' );
				$cfg   = $self->month_config( $month );
				return array(
					'month'              => $month,
					'holidays'           => $self->month_holidays( $month ),
					'workingDays'        => $cfg['workingDays'],
					'customWorkingDays'  => $cfg['custom'],
					'defaultWorkingDays' => Looma_Calc::default_working_days( $month, $self->db['settings']['weeklyOffs'], Looma_Calc::holiday_dates( $self->db ) ),
				);
			},
			true
		);

		$this->route(
			'POST',
			'/api/admin/holidays',
			function ( $p, $body ) use ( $self ) {
				$date = (string) ( $body['date'] ?? '' );
				if ( ! Looma_Time::is_date( $date ) ) {
					throw self::bad( 'Pick a date' );
				}
				$name = self::clean( $body['name'] ?? '', 60 );
				if ( '' === $name ) {
					throw self::bad( 'Give the holiday a name, e.g. Onam' );
				}
				if ( $self->holiday_on( $date ) ) {
					throw self::bad( "$date is already a holiday" );
				}
				$h                      = array(
					'id'   => self::uuid(),
					'date' => $date,
					'name' => $name,
				);
				$self->db['holidays'][] = $h;
				$self->save();
				return $h;
			},
			true
		);

		$this->route(
			'DELETE',
			'/api/admin/holidays/:id',
			function ( $p ) use ( $self ) {
				$before               = count( $self->db['holidays'] );
				$self->db['holidays'] = array_values(
					array_filter(
						$self->db['holidays'],
						function ( $h ) use ( $p ) {
							return $h['id'] !== $p['id'];
						}
					)
				);
				if ( count( $self->db['holidays'] ) === $before ) {
					throw new Looma_Http_Error( 404, 'Holiday not found' );
				}
				$self->save();
				return array( 'ok' => true );
			},
			true
		);

		// ---- company expenses staff paid themselves (paid back with the salary once approved)

		$this->route(
			'POST',
			'/api/admin/salary/:month/:employeeId/paid',
			function ( $p, $body ) use ( $self ) {
				if ( ! Looma_Time::is_month( $p['month'] ) ) {
					throw self::bad( 'Invalid month' );
				}
				$emp = $self->find_employee( $p['employeeId'] );
				$self->db['salaryPayments'] = array_values(
					array_filter(
						$self->db['salaryPayments'],
						function ( $x ) use ( $p, $emp ) {
							return ! ( $x['month'] === $p['month'] && $x['employeeId'] === $emp['id'] );
						}
					)
				);
				$paid_at = ( isset( $body['paid'] ) && false === $body['paid'] ) ? null : Looma_Time::iso_now();
				if ( $paid_at ) {
					$self->db['salaryPayments'][] = array( 'month' => $p['month'], 'employeeId' => $emp['id'], 'paidAt' => $paid_at );
				}
				$self->save();
				return array( 'month' => $p['month'], 'employeeId' => $emp['id'], 'paidAt' => $paid_at );
			},
			true
		);

		$this->route(
			'POST',
			'/api/my/expenses',
			function ( $p, $body ) use ( $self ) {
				$emp = $self->find_employee( $body['employeeId'] ?? '' );
				$self->check_pin( $emp, $body['pin'] ?? '' );
				$month    = $self->month_param( $body['month'] ?? '' );
				$items    = array();
				$approved = 0;
				$pending  = 0;
				foreach ( $self->db['expenses'] as $x ) {
					if ( $x['employeeId'] === $emp['id'] && 0 === strpos( $x['date'], $month . '-' ) && 'cancelled' !== $x['status'] ) {
						$items[] = $x;
						if ( 'approved' === $x['status'] ) {
							$approved += $x['amount'];
						} elseif ( 'pending' === $x['status'] ) {
							$pending += $x['amount'];
						}
					}
				}
				usort( $items, array( __CLASS__, 'by_expense_date' ) );
				return array(
					'month'         => $month,
					'name'          => $emp['name'],
					'currency'      => $self->db['settings']['currency'],
					'today'         => $self->now()['date'],
					'items'         => array_map( array( __CLASS__, 'expense_view' ), $items ),
					'approvedTotal' => Looma_Calc::round2( $approved ),
					'pendingTotal'  => Looma_Calc::round2( $pending ),
				);
			}
		);

		$this->route(
			'POST',
			'/api/expenses',
			function ( $p, $body ) use ( $self ) {
				$emp = $self->find_employee( $body['employeeId'] ?? '' );
				if ( empty( $emp['active'] ) ) {
					throw self::bad( 'Employee is inactive' );
				}
				$self->check_pin( $emp, $body['pin'] ?? '' );
				return $self->new_expense( $emp, $body, 'staff' );
			}
		);

		$this->route(
			'POST',
			'/api/expenses/:id/cancel',
			function ( $p, $body ) use ( $self ) {
				foreach ( $self->db['expenses'] as $i => $x ) {
					if ( $x['id'] !== $p['id'] || $x['employeeId'] !== ( $body['employeeId'] ?? '' ) ) {
						continue;
					}
					$self->check_pin( $self->find_employee( $x['employeeId'] ), $body['pin'] ?? '' );
					if ( 'pending' !== $x['status'] ) {
						throw self::bad( 'Only expenses waiting for approval can be removed' );
					}
					array_splice( $self->db['expenses'], $i, 1 );
					$self->save();
					return array( 'ok' => true );
				}
				throw new Looma_Http_Error( 404, 'Expense not found' );
			}
		);

		$this->route(
			'GET',
			'/api/admin/expenses',
			function ( $p, $b, $q ) use ( $self ) {
				$month   = $self->month_param( $q['month'] ?? '' );
				$pending = array();
				$items   = array();
				foreach ( $self->db['expenses'] as $x ) {
					if ( 'pending' === $x['status'] ) {
						$pending[] = $x;
					} elseif ( 0 === strpos( $x['date'], $month . '-' ) ) {
						$items[] = $x;
					}
				}
				usort( $pending, array( __CLASS__, 'by_expense_date' ) );
				usort( $items, array( __CLASS__, 'by_expense_date' ) );
				return array(
					'month'   => $month,
					'pending' => array_map( array( __CLASS__, 'expense_view' ), $pending ),
					'items'   => array_map( array( __CLASS__, 'expense_view' ), $items ),
				);
			},
			true
		);

		$this->route(
			'POST',
			'/api/admin/expenses',
			function ( $p, $body ) use ( $self ) {
				return $self->new_expense( $self->find_employee( $body['employeeId'] ?? '' ), $body, 'admin' );
			},
			true
		);

		$this->route(
			'POST',
			'/api/admin/expenses/:id/:decision',
			function ( $p, $body ) use ( $self ) {
				if ( ! in_array( $p['decision'], array( 'approve', 'reject' ), true ) ) {
					throw new Looma_Http_Error( 404, 'Not found' );
				}
				foreach ( $self->db['expenses'] as $i => $x ) {
					if ( $x['id'] !== $p['id'] ) {
						continue;
					}
					if ( 'pending' !== $x['status'] ) {
						throw self::bad( 'This expense is already ' . $x['status'] );
					}
					$self->db['expenses'][ $i ]['status']    = 'approve' === $p['decision'] ? 'approved' : 'rejected';
					$self->db['expenses'][ $i ]['decidedAt'] = Looma_Time::iso_now();
					$self->db['expenses'][ $i ]['adminNote'] = self::clean( $body['note'] ?? '', 200 );
					$self->save();
					return self::expense_view( $self->db['expenses'][ $i ] );
				}
				throw new Looma_Http_Error( 404, 'Expense not found' );
			},
			true
		);

		$this->route(
			'DELETE',
			'/api/admin/expenses/:id',
			function ( $p ) use ( $self ) {
				foreach ( $self->db['expenses'] as $i => $x ) {
					if ( $x['id'] === $p['id'] ) {
						array_splice( $self->db['expenses'], $i, 1 );
						$self->save();
						return array( 'ok' => true );
					}
				}
				throw new Looma_Http_Error( 404, 'Expense not found' );
			},
			true
		);

		// ---- salary

		$this->route(
			'GET',
			'/api/admin/salary',
			function ( $p, $b, $q ) use ( $self ) {
				$month   = $self->month_param( $q['month'] ?? '' );
				$cfg     = $self->month_config( $month );
				$summary = Looma_Calc::attendance_summary( $self->db, $month, $self->now()['date'] );
				$s       = $self->db['settings'];
				$result  = $self->with_expenses_and_payments( $month, Looma_Calc::compute_salary( $summary['employees'], $cfg['workingDays'], $cfg['totalSales'], $s ) );
				return array_merge(
					array(
						'month'              => $month,
						'customWorkingDays'  => $cfg['custom'],
						'defaultWorkingDays' => Looma_Calc::default_working_days( $month, $s['weeklyOffs'], Looma_Calc::holiday_dates( $self->db ) ),
						'holidays'           => $self->month_holidays( $month ),
						'hoursPerDay'        => $s['hoursPerDay'],
						'incentivePercent'   => $s['incentivePercent'],
						'currency'           => $s['currency'],
						'companyName'        => $s['companyName'],
					),
					$result
				);
			},
			true
		);

		$this->route(
			'PUT',
			'/api/admin/months/:month',
			function ( $p, $body ) use ( $self ) {
				$month = $p['month'];
				if ( ! Looma_Time::is_month( $month ) ) {
					throw self::bad( 'Invalid month' );
				}
				$cur = isset( $self->db['months'][ $month ] ) && is_array( $self->db['months'][ $month ] ) ? $self->db['months'][ $month ] : array();
				if ( array_key_exists( 'workingDays', $body ) && ( null === $body['workingDays'] || '' === $body['workingDays'] ) ) {
					unset( $cur['workingDays'] );
				} elseif ( isset( $body['workingDays'] ) ) {
					$wd = self::num( $body['workingDays'] );
					if ( null === $wd || $wd < 0 || $wd > 31 ) {
						throw self::bad( 'Working days must be 0–31' );
					}
					$cur['workingDays'] = $wd;
				}
				if ( isset( $body['totalSales'] ) ) {
					$ts = self::num( $body['totalSales'] );
					if ( null === $ts || $ts < 0 ) {
						throw self::bad( 'Total sales must be a positive number' );
					}
					$cur['totalSales'] = $ts;
				}
				$self->db['months'][ $month ] = $cur;
				$self->save();
				return $self->month_config( $month );
			},
			true
		);
	}

}
