<?php
// Helper for php-parity.test.js: runs the WordPress plugin's calculations on
// the cases in the JSON file given as the first argument.
define( 'LOOMA_ATT_CORE', true );
$inc = __DIR__ . '/../wordpress/looma-attendance/includes/';
require $inc . 'class-looma-time.php';
require $inc . 'class-looma-calc.php';

$out = array();
foreach ( json_decode( file_get_contents( $argv[1] ), true ) as $c ) {
	$s     = Looma_Calc::attendance_summary( $c['db'], $c['month'], $c['today'] );
	$out[] = array(
		'workingDays' => Looma_Calc::default_working_days( $c['month'], $c['db']['settings']['weeklyOffs'], Looma_Calc::holiday_dates( $c['db'] ) ),
		'summary' => $s['employees'],
		'salary'  => Looma_Calc::compute_salary( $s['employees'], $c['workingDays'], $c['totalSales'], $c['db']['settings'] ),
	);
}
echo json_encode( $out );
