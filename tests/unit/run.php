<?php
// Unit tests: the app's calculations and rules on their own (no pages, no browser).
// Run from the app folder with a TEST database in config.php:  php tests/unit/run.php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'localhost';
$root = dirname(__DIR__, 2);
require $root . '/inc/bootstrap.php';
require $root . '/inc/orders.php';
require $root . '/inc/billing.php';
require $root . '/inc/label.php';
require $root . '/inc/tasks.php';
require $root . '/inc/staff_links.php';
define('LOOMA_ATT_CORE', true);
require $root . '/attendance/includes/class-looma-time.php';
require $root . '/attendance/includes/class-looma-calc.php';

$fails = 0;
$count = 0;
function t(string $name, bool $ok, $got = null): void
{
    global $fails, $count;
    $count++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $got === null ? '' : '  → got ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) {
        $fails++;
    }
}
$near = fn($a, $b) => abs((float)$a - (float)$b) < 0.011;

// ---------------------------------------------------------------- money: words, bills, labels
t('amount in words: 1250', amount_in_words(1250) === 'One Thousand Two Hundred Fifty Rupees Only', amount_in_words(1250));
t('amount in words: zero', amount_in_words(0) === 'Zero Rupees Only');
t('amount in words: crore / lakh', amount_in_words(12345678) === 'One Crore Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight Rupees Only', amount_in_words(12345678));
t('amount in words: rounds paise', amount_in_words(99.6) === 'One Hundred Rupees Only', amount_in_words(99.6));
$cod = cod_line(['payment_type' => 'cod', 'cod_amount' => 1178]);
t('COD line: amount in figures and words', str_starts_with($cod, 'COD - 1178 (') && str_ends_with($cod, ' Rupees Only)'), $cod);
t('COD line: none for prepaid', cod_line(['payment_type' => 'prepaid', 'cod_amount' => 500]) === '');
t('COD line: none for zero', cod_line(['payment_type' => 'cod', 'cod_amount' => 0]) === '');

[$lines, $b] = compute_bill([['qty' => 10, 'rate' => 100, 'gst_rate' => 5]], 0, 100, false, 5);
t('bill: taxable before GST', $near($b['taxable'], 1000), $b);
t('bill: 5% GST on goods + 5% on shipping', $near($b['tax_total'], 55), $b['tax_total']);
t('bill: CGST + SGST split in half (same state)', $near($b['cgst'], 27.5) && $near($b['sgst'], 27.5) && $near($b['igst'], 0));
t('bill: total = taxable + tax + shipping, rounded', $b['total'] == 1155, $b['total']);
[, $b] = compute_bill([['qty' => 10, 'rate' => 100, 'gst_rate' => 5]], 0, 100, true, 5);
t('bill: other state → IGST only', $near($b['igst'], 55) && $near($b['cgst'], 0), $b);
[, $b] = compute_bill([['qty' => 10, 'rate' => 100, 'gst_rate' => 5]], 100, 0, false, 5);
t('bill: discount lowers the taxable value', $near($b['taxable'], 900) && $near($b['tax_total'], 45), $b);
[, $b] = compute_bill([['qty' => 1, 'rate' => 100, 'gst_rate' => 5]], 500, 0, false, 5);
t('bill: discount never above the subtotal', $near($b['discount'], 100) && $b['total'] == 0, $b);
[, $b] = compute_bill([['qty' => 2, 'rate' => 50, 'gst_rate' => 5], ['qty' => 1, 'rate' => 1000, 'gst_rate' => 12]], 0, 0, false, 5);
t('bill: tax grouped by rate', count($b['by_rate']) === 2 && $near($b['tax_total'], 5 + 120), $b['by_rate']);
[, $b] = compute_bill([['qty' => 1, 'rate' => 100, 'gst_rate' => 5]], 0, 100, false, 0);
t('bill: old bills without GST on shipping keep their total', $near($b['ship_tax'], 0) && $b['total'] == 205, $b['total']);
t('neck label: ₹10 each for 1–9 pieces', neck_label_price(9) == 10, neck_label_price(9));
t('neck label: ₹5 each for 10 or more', neck_label_price(10) == 5 && neck_label_price(50) == 5);
t('order number format', order_no(12) === 'LA00012', order_no(12));

// ---------------------------------------------------------------- work score
$s = work_points(['orders' => 1, 'orders_tees' => 10, 'processed' => 1, 'processed_tees' => 10, 'printed' => 10, 'printed_late' => 2,
    'packed' => 1, 'shipped' => 1, 'done_given' => 1]);
t('points: order created = 5 + 1 per T-shirt (10 T-shirts → 15)', $near($s['lines']['order']['points'] + $s['lines']['order_pc']['points'], 15));
t('points: print processed = 1 + 0.5 per T-shirt (→ 6)', $near($s['lines']['processed']['points'] + $s['lines']['processed_pc']['points'], 6));
t('points: printed 1 per piece, late work half (8 + 2×0.5 = 9)', $near($s['lines']['printed']['points'], 9), $s['lines']['printed']);
t('points: packed 1, shipped 0', $near($s['lines']['packed']['points'], 1) && $near($s['lines']['shipped']['points'], 0));
t('points: task given by someone else = 10', $near($s['lines']['task']['points'], 10));
t('points: total', $s['total'] === 41, $s['total']);
t('points: nothing done = 0', work_points([])['total'] === 0);
$s2 = work_points(['done' => 3, 'done_given' => 0]);
t('points: own tasks earn nothing', $s2['total'] === 0, $s2['total']);

// ---------------------------------------------------------------- task pages: cleaning what is typed
$c = block_html_clean('<script>alert(1)</script><b onclick="x()">hi</b> <a href="javascript:alert(1)">bad</a> <a href="https://looma.example/x">ok</a>');
t('page text: scripts removed with what is inside them', !str_contains($c, '<script') && !str_contains($c, 'alert(1)') && !str_contains($c, 'onclick'), $c);
t('page text: styles and frames dropped', block_html_clean('a<style>body{}</style><iframe src="x">y</iframe>b') === 'ab', block_html_clean('a<style>body{}</style><iframe src="x">y</iframe>b'));
t('page text: javascript: links become plain text', str_contains($c, 'bad') && !str_contains($c, 'javascript:'), $c);
t('page text: https links kept, opened in a new tab', str_contains($c, '<a href="https://looma.example/x" target="_blank" rel="noopener">ok</a>'), $c);
t('page text: bold kept, attributes dropped', str_contains($c, '<b>hi</b>'), $c);
t('page text: plain text escaped', block_html_clean('a < b & c') === 'a &lt; b &amp; c', block_html_clean('a < b & c'));
t('page text: line breaks kept', block_html_clean('one<br>two<div>three</div>') === 'one<br>two<br>three', block_html_clean('one<br>two<div>three</div>'));
$p = block_props_clean(['links' => [['u' => 'ftp://x', 't' => 'no'], ['u' => 'https://ok.example', 't' => 'yes']], 'imgs' => ['../../etc/passwd', '2026/10/' . str_repeat('a', 24) . '.jpg'],
    'ind' => 9, 'sec' => 99999, 'color' => 'neon', 'done' => 1, 'conn' => [['k' => 'order', 'id' => 5], ['k' => 'user', 'id' => 1]]]);
t('line extras: only http(s) links', count($p['links']) === 1 && $p['links'][0]['u'] === 'https://ok.example', $p['links'] ?? null);
t('line extras: only image files of the app (no paths outside)', $p['imgs'] === ['2026/10/' . str_repeat('a', 24) . '.jpg'], $p['imgs'] ?? null);
t('line extras: indent max 4, timing max 1 hour, colour from the list', $p['ind'] === 4 && $p['sec'] == 3600 && !isset($p['color']), $p);
t('line extras: connect only orders and tasks', count($p['conn']) === 1 && $p['conn'][0]['k'] === 'order', $p['conn'] ?? null);
$d = details_blocks("# Title\n- one\n[] buy fabric\nplain line\n\n");
t('typed details become page lines', array_column($d, 'type') === ['h2', 'bullet', 'todo', 'p'] && $d[2]['html'] === 'buy fabric', $d);

// ---------------------------------------------------------------- teams and access
t('team: production job → Production team', task_team(['job' => 'production', 'role' => 'staff', 'caps' => []]) === 'production');
foreach (['video_editor', 'marketing', 'design', 'other'] as $j) {
    t("team: $j → Creative team", task_team(['job' => $j, 'role' => 'staff', 'caps' => []]) === 'creative');
}
t('creative staff get the task workspace', task_page_cap(['job' => 'video_editor', 'role' => 'staff', 'caps' => []]));
t('production staff get the production task screen', !task_page_cap(['job' => 'production', 'role' => 'staff', 'caps' => []]));
t('admin is never tracked', !perf_tracked(['role' => 'admin', 'track_perf' => 1]));
t('staff tracked unless switched off', perf_tracked(['role' => 'staff', 'track_perf' => 1]) && !perf_tracked(['role' => 'staff', 'track_perf' => 0]));
$_COOKIE['looma_theme'] = 'dark';
t('theme: dark from the cookie', theme_choice() === 'dark' && theme_attr() === ' data-theme="dark"');
$_COOKIE['looma_theme'] = '"><script>';
t('theme: anything else means Automatic', theme_choice() === 'auto' && theme_attr() === '');
unset($_COOKIE['looma_theme']);

// ---------------------------------------------------------------- staff links (attendance ↔ orders by name)
$users = [1 => ['name' => 'Faiz', 'username' => 'faiz'], 2 => ['name' => 'Rahul Kumar', 'username' => 'rahul'], 3 => ['name' => '', 'username' => 'nadeem'],
    4 => ['name' => 'Anu', 'username' => 'anu'], 5 => ['name' => 'Vyshnav', 'username' => 'vy']];
$people = ['p1' => ['id' => 'p1', 'name' => 'Fayis'], 'p2' => ['id' => 'p2', 'name' => 'Rahul  Kumar.'], 'p3' => ['id' => 'p3', 'name' => 'Nadeem Anshin'],
    'p4' => ['id' => 'p4', 'name' => 'Anu A'], 'p5' => ['id' => 'p5', 'name' => 'Anu B'], 'p6' => ['id' => 'p6', 'name' => 'VYSHNAV']];
$m = auto_staff_links($users, $people);
t('links: same full name (spacing / dots / case ignored)', ($m[2]['id'] ?? '') === 'p2' && $m[2]['how'] === 'name', $m[2] ?? null);
t('links: account without a name matched by its username', ($m[3]['id'] ?? '') === 'p3', $m[3] ?? null);
$m2 = auto_staff_links([7 => ['name' => 'Naz', 'username' => 'fayis']], ['p1' => ['id' => 'p1', 'name' => 'Fayis']]);
t('links: username is the first name', ($m2[7]['id'] ?? '') === 'p1' && $m2[7]['how'] === 'username', $m2[7] ?? null);
t('links: similar spelling flagged to check (Faiz / Fayis)', ($m[1]['id'] ?? '') === 'p1' && $m[1]['how'] === 'similar', $m[1] ?? null);
t('links: two people with the same first name → no guess', !isset($m[4]), $m[4] ?? null);
t('links: capitals do not matter', ($m[5]['id'] ?? '') === 'p6', $m[5] ?? null);
t('links: each attendance person used once', count(array_unique(array_column($m, 'id'))) === count($m));

// ---------------------------------------------------------------- attendance: salary and incentive
$settings = ['hoursPerDay' => 9, 'incentivePercent' => 1];
$row = fn($id, $basic, $leave, $hours) => ['id' => $id, 'name' => $id, 'position' => '', 'basicSalary' => $basic, 'leaveDays' => $leave, 'totalMinutes' => $hours * 60,
    'daysPresent' => 0, 'openSessions' => 0, 'incentive' => true, 'attendance' => true];
$r = Looma_Calc::compute_salary([$row('A', 26000, 1, 225), $row('B', 13000, 0, 234)], 26, 100000, $settings);
$A = $r['rows'][0];
$B = $r['rows'][1];
t('salary: pool = 1% of total sales', $near($r['pool'], 1000), $r['pool']);
t('salary: leave cut = basic ÷ working days × leave days', $near($A['leaveDeduction'], 1000) && $near($A['salaryAfterLeave'], 25000), $A);
t('salary: required hours = (working days − leave) × hours a day', $near($A['requiredHours'], 225) && $near($B['requiredHours'], 234));
t('salary: incentive shares add up to the pool', $near($A['totalIncentive'] + $B['totalIncentive'], 1000), [$A['totalIncentive'], $B['totalIncentive']]);
t('salary: more hours worked → bigger share', $B['totalIncentive'] > $A['totalIncentive']);
t('salary: net pay = salary after leave + incentive', $near($A['netPay'], $A['salaryAfterLeave'] + $A['totalIncentive']) && $near($B['netPay'], $B['salaryAfterLeave'] + $B['totalIncentive']));
$r0 = Looma_Calc::compute_salary([$row('A', 26000, 0, 0)], 26, 100000, $settings);
t('salary: nobody worked → incentive not shared out', $near($r0['undistributed'], 1000) && $near($r0['rows'][0]['totalIncentive'], 0));
$off = $row('C', 20000, 0, 0);
$off['incentive'] = false;
$r1 = Looma_Calc::compute_salary([$row('A', 26000, 0, 234), $off], 26, 50000, $settings);
t('salary: incentive switched off → no share', $near($r1['rows'][1]['totalIncentive'], 0) && $near($r1['rows'][0]['totalIncentive'], 500));
t('salary: leave days never more than working days', Looma_Calc::compute_salary([$row('A', 26000, 40, 0)], 26, 0, $settings)['rows'][0]['salaryAfterLeave'] == 0);

echo "---\n" . ($fails ? "$fails of $count FAILED\n" : "ALL PASSED ($count checks)\n");
exit($fails ? 1 : 0);
