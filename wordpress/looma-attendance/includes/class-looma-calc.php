<?php
/**
 * Hours, leave and salary calculations. Mirrors lib/calc.js in the Node.js
 * version of the app; keep the two in step.
 */

if ( ! defined( 'LOOMA_ATT_CORE' ) ) {
	exit;
}

class Looma_Calc {

	/**
	 * Manual entries made on the clock-in page wait for the admin's approval
	 * (status 'pending'); until approved, and if rejected, they do not count.
	 */
	public static function counts( $s ) {
		return empty( $s['status'] ) || 'approved' === $s['status'];
	}

	public static function not_rejected( $s ) {
		return ( $s['status'] ?? '' ) !== 'rejected';
	}

	/**
	 * Minutes in one in/out session. An open session (no out time yet) counts
	 * up to $now_time when given (live view for today), otherwise 0.
	 */
	public static function session_minutes( $s, $now_time = null ) {
		$end = ! empty( $s['out'] ) ? $s['out'] : $now_time;
		if ( ! $end ) {
			return 0;
		}
		return max( 0, Looma_Time::to_minutes( $end ) - Looma_Time::to_minutes( $s['in'] ) );
	}

	/** Holiday dates (YYYY-MM-DD => true) from the admin's holiday list. */
	public static function holiday_dates( $db ) {
		$out = array();
		foreach ( ( isset( $db['holidays'] ) && is_array( $db['holidays'] ) ) ? $db['holidays'] : array() as $h ) {
			$out[ $h['date'] ] = true;
		}
		return $out;
	}

	/** Every day of the month except the weekly off days and holidays. */
	public static function default_working_days( $month, $weekly_offs, $holidays = array() ) {
		$n = 0;
		foreach ( Looma_Time::month_dates( $month ) as $d ) {
			if ( ! in_array( Looma_Time::weekday( $d ), $weekly_offs, false ) && ! isset( $holidays[ $d ] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/** Same as the JavaScript version: Math.round((n + EPSILON) * 100) / 100 */
	public static function round2( $n ) {
		return floor( ( $n + PHP_FLOAT_EPSILON ) * 100 + 0.5 ) / 100;
	}

	private static function by_in( $a, $b ) {
		return strcmp( $a['in'], $b['in'] );
	}

	/**
	 * Per-employee, per-day attendance for a month. Only closed sessions count
	 * towards totals; open sessions from past days are flagged as missing a
	 * clock-out.
	 *
	 * A working day on which someone was present but worked `halfDayShortHours`
	 * (default 2) or more below the required hours counts as a half-day leave,
	 * and more than `fullDayShortHours` (default 5, i.e. under 4 h of 9) below as a full-day leave,
	 * unless a leave is already recorded for that day. The hours they did work
	 * still count, so they also add to extra hours for the incentive.
	 */
	public static function attendance_summary( $db, $month, $today = null ) {
		$dates      = Looma_Time::month_dates( $month );
		$last_date  = end( $dates );
		$prefix     = $month . '-';
		$settings   = $db['settings'];
		$work_start = isset( $settings['workStart'] ) ? $settings['workStart'] : null;
		$hpd        = (float) $settings['hoursPerDay'];
		$offs       = isset( $settings['weeklyOffs'] ) ? $settings['weeklyOffs'] : array();
		$holidays   = self::holiday_dates( $db );

		$short          = isset( $settings['halfDayShortHours'] ) ? (float) $settings['halfDayShortHours'] : 2;
		$half_day_below = $short > 0 ? ( $hpd - $short ) * 60 : null;
		$full_short     = isset( $settings['fullDayShortHours'] ) ? (float) $settings['fullDayShortHours'] : 5;
		$full_day_below = $full_short > 0 ? ( $hpd - $full_short ) * 60 : null;
		// absent working days count as unplanned leave from this date (empty = off)
		$sick_from = ! empty( $settings['sickLeaveFrom'] ) ? $settings['sickLeaveFrom'] : null;

		$in_month = function ( $d ) use ( $prefix ) {
			return 0 === strpos( $d, $prefix );
		};

		$employees = array();
		foreach ( $db['employees'] as $e ) {
			$sessions = array();
			foreach ( $db['sessions'] as $s ) {
				if ( $s['employeeId'] === $e['id'] && $in_month( $s['date'] ) && self::counts( $s ) ) {
					$sessions[] = $s;
				}
			}
			// skip people who were inactive, or had not joined yet, in this month
			if ( ! $sessions ) {
				$joined = isset( $e['joinedOn'] ) ? $e['joinedOn'] : '';
				if ( empty( $e['active'] ) || ( $joined && $joined > $last_date ) ) {
					continue;
				}
			}
			$leaves = array();
			foreach ( $db['leaves'] as $l ) {
				if ( $l['employeeId'] === $e['id'] && $in_month( $l['date'] ) ) {
					$leaves[] = $l;
				}
			}

			// days with any attendance (approved or still waiting) or a leave request waiting for approval
			$attended = array();
			foreach ( $db['sessions'] as $s ) {
				if ( $s['employeeId'] === $e['id'] && $in_month( $s['date'] ) && self::not_rejected( $s ) ) {
					$attended[ $s['date'] ] = true;
				}
			}
			$requested = array();
			foreach ( ( isset( $db['leaveRequests'] ) ? $db['leaveRequests'] : array() ) as $r ) {
				if ( $r['employeeId'] === $e['id'] && 'pending' === $r['status'] ) {
					foreach ( ( isset( $r['dates'] ) ? $r['dates'] : array() ) as $d ) {
						$requested[ $d ] = true;
					}
				}
			}
			$joined_on  = isset( $e['joinedOn'] ) ? $e['joinedOn'] : '';
			$sick_start = $sick_from && $joined_on && $joined_on > $sick_from ? $joined_on : $sick_from;
			$sick_days  = 0;

			$days          = array();
			$total_minutes = 0;
			$days_present  = 0;
			$open_sessions = 0;
			$auto_half     = 0;
			$auto_full     = 0;

			foreach ( $dates as $date ) {
				$day_sessions = array();
				foreach ( $sessions as $s ) {
					if ( $s['date'] === $date ) {
						$day_sessions[] = $s;
					}
				}
				usort( $day_sessions, array( __CLASS__, 'by_in' ) );
				$leave = null;
				foreach ( $leaves as $l ) {
					if ( $l['date'] === $date ) {
						$leave = $l;
						break;
					}
				}
				if ( ! $day_sessions && ! $leave ) {
					$sick = ! empty( $e['active'] ) && $sick_start && $today && $date >= $sick_start && $date < $today
						&& ! isset( $attended[ $date ] ) && ! isset( $requested[ $date ] )
						&& ! in_array( Looma_Time::weekday( $date ), $offs, false ) && ! isset( $holidays[ $date ] );
					if ( $sick ) {
						$days[ $date ] = array(
							'minutes'     => 0,
							'sessions'    => array(),
							'leave'       => null,
							'firstIn'     => null,
							'lastOut'     => null,
							'late'        => false,
							'open'        => 0,
							'running'     => 0,
							'autoFullDay' => false,
							'autoHalfDay' => false,
							'sick'        => true,
						);
						$sick_days++;
					}
					continue;
				}

				$minutes    = 0;
				$open_count = 0;
				$outs       = array();
				foreach ( $day_sessions as $s ) {
					$minutes += self::session_minutes( $s );
					if ( empty( $s['out'] ) ) {
						$open_count++;
					} else {
						$outs[] = $s['out'];
					}
				}
				sort( $outs );
				// an open session today just means the person is still in
				$running  = ( $date === $today ) ? $open_count : 0;
				$open     = $open_count - $running;
				$first_in = $day_sessions ? $day_sessions[0]['in'] : null;

				$eligible = ! $leave && $day_sessions && ! $open && ! $running
					&& ( ! $today || $date < $today ) && ! in_array( Looma_Time::weekday( $date ), $offs, false ) && ! isset( $holidays[ $date ] );
				$full     = $eligible && null !== $full_day_below && $minutes < $full_day_below;
				$half     = $eligible && ! $full && null !== $half_day_below && $minutes <= $half_day_below;

				$days[ $date ] = array(
					'minutes'     => $minutes,
					'sessions'    => $day_sessions,
					'leave'       => $leave,
					'firstIn'     => $first_in,
					'lastOut'     => $outs ? end( $outs ) : null,
					'late'        => (bool) ( $first_in && $work_start && $first_in > $work_start ),
					'open'        => $open,
					'running'     => $running,
					'autoFullDay' => (bool) $full,
					'autoHalfDay' => (bool) $half,
					'sick'        => false,
				);
				$total_minutes += $minutes;
				$open_sessions += $open;
				if ( $day_sessions ) {
					$days_present++;
				}
				if ( $full ) {
					$auto_full++;
				}
				if ( $half ) {
					$auto_half++;
				}
			}

			$recorded = 0;
			foreach ( $leaves as $l ) {
				$recorded += isset( $l['portion'] ) ? (float) $l['portion'] : 0;
			}

			$employees[] = array(
				'id'                => $e['id'],
				'name'              => $e['name'],
				'position'          => isset( $e['position'] ) ? $e['position'] : '',
				'active'            => ! empty( $e['active'] ),
				'basicSalary'       => isset( $e['basicSalary'] ) ? $e['basicSalary'] : 0,
				'incentive'         => ! isset( $e['incentive'] ) || false !== $e['incentive'],
				'totalMinutes'      => $total_minutes,
				'daysPresent'       => $days_present,
				'leaveDays'         => $recorded + $auto_half * 0.5 + $auto_full + $sick_days,
				'recordedLeaveDays' => $recorded,
				'autoHalfDays'      => $auto_half,
				'autoFullDays'      => $auto_full,
				'sickDays'          => $sick_days,
				'openSessions'      => $open_sessions,
				'days'              => $days,
			);
		}

		return array(
			'month'     => $month,
			'dates'     => $dates,
			'employees' => $employees,
		);
	}

	/**
	 * Salary + incentive for a month.
	 *
	 *  Monthly salary  = basic - (basic / workingDays) * leaveDays
	 *  Required hours  = (workingDays - leaveDays) * hoursPerDay
	 *  Extra hours     = max(0, workedHours - requiredHours)
	 *
	 *  Incentive pool  = totalSales * incentivePercent%            (default 1%)
	 *  Shared by target achievement: each person's score is
	 *      workedHours * (workedHours / requiredHours)
	 *  so working beyond the requirement raises the share faster than hours
	 *  alone, and falling short lowers it. Share = own score / everyone's score.
	 * Staff with the incentive switched off get none and are left out.
	 */
	public static function compute_salary( $rows, $working_days, $total_sales, $settings ) {
		$hpd        = (float) $settings['hoursPerDay'];
		$pool       = (float) $total_sales * (float) $settings['incentivePercent'] / 100;

		$base = array();
		foreach ( $rows as $r ) {
			$basic                 = (float) $r['basicSalary'];
			$worked                = $r['totalMinutes'] / 60;
			$leave_days            = min( $r['leaveDays'], $working_days );
			$required              = max( 0, $working_days - $leave_days ) * $hpd;
			$per_day               = $working_days > 0 ? $basic / $working_days : 0;
			$r['basicSalary']      = $basic;
			$r['workedHours']      = $worked;
			$r['leaveDays']        = $leave_days;
			$r['requiredHours']    = $required;
			$r['extraHours']       = max( 0, $worked - $required );
			$r['shortHours']       = max( 0, $required - $worked );
			$r['perDay']           = $per_day;
			$r['leaveDeduction']   = min( $basic, $per_day * $leave_days );
			$r['incentive']        = ! isset( $r['incentive'] ) || false !== $r['incentive'];
			// share of the target reached (1 = exactly the required hours)
			$r['achievement']      = $required > 0 ? $worked / $required : ( $worked > 0 ? 1 : 0 );
			$r['score']            = $r['incentive'] ? $worked * $r['achievement'] : 0;
			$base[]                = $r;
		}

		$total_score = 0;
		foreach ( $base as $r ) {
			$total_score += $r['score'];
		}

		$out = array();
		foreach ( $base as $r ) {
			$inc   = $total_score > 0 ? $pool * $r['score'] / $total_score : 0;
			$after = $r['basicSalary'] - $r['leaveDeduction'];
			$out[]     = array(
				'id'               => $r['id'],
				'name'             => $r['name'],
				'position'         => $r['position'],
				'basicSalary'      => self::round2( $r['basicSalary'] ),
				'daysPresent'      => $r['daysPresent'],
				'leaveDays'        => $r['leaveDays'],
				'workedHours'      => self::round2( $r['workedHours'] ),
				'requiredHours'    => self::round2( $r['requiredHours'] ),
				'extraHours'       => self::round2( $r['extraHours'] ),
				'shortHours'       => self::round2( $r['shortHours'] ),
				'incentive'        => $r['incentive'],
				'achievement'      => self::round2( 100 * $r['achievement'] ),
				'score'            => self::round2( $r['score'] ),
				'incentiveShare'   => $total_score > 0 ? self::round2( 100 * $r['score'] / $total_score ) : 0,
				'perDay'           => self::round2( $r['perDay'] ),
				'leaveDeduction'   => self::round2( $r['leaveDeduction'] ),
				'salaryAfterLeave' => self::round2( $after ),
				'totalIncentive'   => self::round2( $inc ),
				'netPay'           => self::round2( $after + $inc ),
				'openSessions'     => $r['openSessions'],
				// leave breakdown for the payslip
				'recordedLeaveDays' => $r['recordedLeaveDays'] ?? 0,
				'autoHalfDays'      => $r['autoHalfDays'] ?? 0,
				'autoFullDays'      => $r['autoFullDays'] ?? 0,
				'sickDays'          => $r['sickDays'] ?? 0,
			);
		}

		$sum = function ( $k ) use ( $out ) {
			$t = 0;
			foreach ( $out as $r ) {
				$t += $r[ $k ];
			}
			return Looma_Calc::round2( $t );
		};

		$totals = array();
		foreach ( array( 'basicSalary', 'leaveDeduction', 'salaryAfterLeave', 'workedHours', 'extraHours', 'totalIncentive', 'netPay' ) as $k ) {
			$totals[ $k ] = $sum( $k );
		}

		return array(
			'workingDays'   => $working_days,
			'totalSales'    => (float) $total_sales,
			'pool'          => self::round2( $pool ),
			'undistributed' => self::round2( $total_score > 0 ? 0 : $pool ),
			'rows'          => $out,
			'totals'        => $totals,
		);
	}
}
