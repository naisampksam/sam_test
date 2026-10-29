<?php
// Integration tests: the app's code together with the database and files (task pages, sales, points, shared admin
// login, backups, staff links). Uses the TEST database in config.php; everything it adds is removed at the end.
// Run from the app folder:  php tests/integration/run.php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'localhost';
$root = dirname(__DIR__, 2);
require $root . '/inc/bootstrap.php';
require $root . '/inc/orders.php';
require $root . '/inc/billing.php';
require $root . '/inc/tasks.php';
require $root . '/inc/staff_links.php';
require $root . '/inc/month_sales.php';
require $root . '/inc/sso.php';

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

ensure_schema();
// Act as the admin (the task-page code records who changed what).
$admin = q("SELECT * FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1")->fetch();
start_session();
$_SESSION['uid'] = (int)$admin['id'];
$_SESSION['sv'] = (int)$admin['session_version'];
t('logged in as the admin for the run', current_user() && is_admin(), $admin['username'] ?? null);
$cleanup = [];

// ---------------------------------------------------------------- task pages: saving, two editors, copying, deleting
q("INSERT INTO tasks (title, kind, assigned_to, created_by, status, body_rev, created_at) VALUES ('IT page test', 'video', ?, ?, 'todo', 1, NOW())", [$admin['id'], $admin['id']]);
$tid = (int)db()->lastInsertId();
$cleanup[] = fn() => delete_task($tid);
$r = save_task_blocks($tid, [['id' => 'bA1x', 'type' => 'h2', 'html' => 'Hook'], ['id' => 'bB2x', 'type' => 'p', 'html' => '<b>Hello</b><script>x</script>'], ['id' => 'bC3x', 'type' => 'todo', 'html' => 'Shoot', 'checked' => true]],
    [], ['bA1x', 'bB2x', 'bC3x'], 1);
$rows = q('SELECT uid, type, html, checked FROM task_blocks WHERE task_id = ? ORDER BY pos', [$tid])->fetchAll();
t('page saved in order', array_column($rows, 'uid') === ['bA1x', 'bB2x', 'bC3x'], array_column($rows, 'uid'));
t('page text cleaned on save', $rows[1]['html'] === '<b>Hello</b>', $rows[1]['html']);
t('ticked to-do saved', (int)$rows[2]['checked'] === 1);
t('first save: nobody else changed it', $r['others'] === false && $r['rev'] === 2, $r);
// someone else (still on revision 1) adds a line after the heading and edits another line
$r2 = save_task_blocks($tid, [['id' => 'bD4x', 'type' => 'p', 'html' => 'Added by the editor'], ['id' => 'bC3x', 'type' => 'todo', 'html' => 'Shoot the back', 'checked' => false]],
    [], ['bA1x', 'bD4x', 'bC3x'], 1);
t('second editor is told someone else saved meanwhile', $r2['others'] === true, $r2);
// the first person (revision 2) changes the hello line only
save_task_blocks($tid, [['id' => 'bB2x', 'type' => 'p', 'html' => 'Hello there']], [], ['bA1x', 'bB2x', 'bC3x'], 2);
$rows = q('SELECT uid, html FROM task_blocks WHERE task_id = ? ORDER BY pos', [$tid])->fetchAll(PDO::FETCH_KEY_PAIR);
t('both people’s changes kept', ($rows['bB2x'] ?? '') === 'Hello there' && ($rows['bC3x'] ?? '') === 'Shoot the back' && isset($rows['bD4x']), $rows);
t('a line someone else added stays after the line it followed', array_keys($rows) === ['bA1x', 'bD4x', 'bB2x', 'bC3x'], array_keys($rows));
save_task_blocks($tid, [], ['bD4x'], ['bA1x', 'bB2x', 'bC3x'], 3);
t('deleting a line', !q('SELECT 1 FROM task_blocks WHERE task_id = ? AND uid = ?', [$tid, 'bD4x'])->fetchColumn());
q("INSERT INTO tasks (title, kind, assigned_to, created_by, status, body_rev, created_at) VALUES ('IT page copy', 'video', ?, ?, 'todo', 1, NOW())", [$admin['id'], $admin['id']]);
$cid = (int)db()->lastInsertId();
$cleanup[] = fn() => delete_task($cid);
q("UPDATE task_blocks SET props = '{\"done\":true,\"links\":[{\"u\":\"https://x.example\",\"t\":\"x\"}]}' WHERE task_id = ? AND uid = 'bB2x'", [$tid]);
copy_task_blocks($tid, $cid);
$copied = q('SELECT html, props FROM task_blocks WHERE task_id = ? ORDER BY pos', [$cid])->fetchAll();
t('Give again copies the page', array_column($copied, 'html') === ['Hook', 'Hello there', 'Shoot the back'], array_column($copied, 'html'));
t('copy keeps links but clears ✓ edited', str_contains($copied[1]['props'], 'x.example') && !str_contains($copied[1]['props'], 'done'), $copied[1]['props']);
$prog = task_progress([$tid]);
t('script progress counts lines and to-dos', ($prog[$tid]['todo'] ?? 0) === 1 && ($prog[$tid]['lines'] ?? 0) >= 1, $prog[$tid] ?? null);
$found = q("SELECT id FROM tasks WHERE id IN (SELECT task_id FROM task_blocks WHERE html LIKE ?)", ['%Shoot the back%'])->fetchAll(PDO::FETCH_COLUMN);
t('search finds words inside a page', in_array($tid, array_map('intval', $found), true));

// ---------------------------------------------------------------- sales for the attendance incentive
$month = date('Y-m');
$sql = (float)q("SELECT IFNULL(SUM(taxable), 0) FROM bills WHERE status = 'final' AND converted_to IS NULL AND bill_date BETWEEN ? AND LAST_DAY(?)", ["$month-01", "$month-01"])->fetchColumn();
t('month sales (attendance) = bills sales report', $near(looma_orders_month_sales($month), $sql) && $near(month_sales($month), $sql), [looma_orders_month_sales($month), $sql]);
$till = date('Y-m-15');
$sqlTill = (float)q("SELECT IFNULL(SUM(taxable), 0) FROM bills WHERE status = 'final' AND converted_to IS NULL AND bill_date BETWEEN ? AND ?", ["$month-01", $till])->fetchColumn();
t('sales till a date', $near(looma_orders_sales_between("$month-01", $till), $sqlTill));
t('bad month refused', looma_orders_month_sales('2026-13; DROP') === null);

// ---------------------------------------------------------------- points from real order work
$perf = staff_performance(date('Y-m-01'), today());
$ok = true;
foreach ($perf as $uid => $row) {
    $ok = $ok && $row['points'] === work_points($row)['total'];
}
t('everyone’s points = their work × the points rules', $ok);
$any = q("SELECT created_by, COUNT(*) n FROM orders WHERE deleted_at IS NULL AND created_at >= ? AND created_by IS NOT NULL GROUP BY created_by ORDER BY n DESC LIMIT 1", [date('Y-m-01 00:00:00')])->fetch();
if ($any) {
    t('orders created counted per person', (int)round($perf[(int)$any['created_by']]['orders'] ?? 0) === (int)$any['n'], [$perf[(int)$any['created_by']]['orders'] ?? null, $any['n']]);
}

// ---------------------------------------------------------------- one admin login for attendance
$sid = 'it' . bin2hex(random_bytes(14));
$sessFile = $root . '/sessions/sess_' . $sid;
@mkdir($root . '/sessions', 0700, true);
file_put_contents($sessFile, 'csrf|s:4:"abcd";uid|i:' . (int)$admin['id'] . ';sv|i:' . (int)$admin['session_version'] . ';');
$cleanup[] = fn() => @unlink($sessFile);
$_COOKIE['LOOMASESS'] = $sid;
$who = looma_orders_admin();
t('attendance sees the logged-in orders admin', $who && $who['id'] === (int)$admin['id'], $who);
file_put_contents($sessFile, 'uid|i:' . (int)$admin['id'] . ';sv|i:' . ((int)$admin['session_version'] + 5) . ';');
t('not after “log out everywhere” (old session)', looma_orders_admin() === null);
$staff = q("SELECT * FROM users WHERE role <> 'admin' AND active = 1 LIMIT 1")->fetch();
if ($staff) {
    file_put_contents($sessFile, 'uid|i:' . (int)$staff['id'] . ';sv|i:' . (int)$staff['session_version'] . ';');
    t('staff logins are not attendance admins', looma_orders_admin() === null);
}
$_COOKIE['LOOMASESS'] = '../../config.php';
t('a crafted session cookie is refused', looma_orders_admin() === null);
unset($_COOKIE['LOOMASESS']);

// ---------------------------------------------------------------- staff links saved by the admin win over the automatic match
$users = staff_list();
$people = attendance_people();
if ($users && $people) {
    $u1 = array_key_first($users);
    $p1 = array_key_first($people);
    $before = setting('staff_links', '');
    set_setting('staff_links', json_encode([(string)$u1 => $p1]));
    t('saved link used', staff_links($users)[$u1]['id'] === $p1 && staff_links($users)[$u1]['how'] === 'set by admin');
    set_setting('staff_links', json_encode([(string)$u1 => '']));
    t('“not in attendance” saved', staff_links($users)[$u1]['id'] === '');
    set_setting('staff_links', $before);
    $sum = attendance_summary($p1, date('Y-m-01'), today());
    t('attendance summary has days and minutes', isset($sum['days'], $sum['minutes']));
}

// ---------------------------------------------------------------- performance: only tracked people, per team
$all = perf_staff();
t('admin sees every tracked person', count($all) === count(array_filter(staff_list(), fn($u) => (int)($u['track_perf'] ?? 1) === 1)));
$prod = perf_staff('production');
$cre = perf_staff('creative');
t('teams do not mix', !array_intersect_key($prod, $cre) && count($prod) + count($cre) === count($all));
if ($staff) {
    q('UPDATE users SET track_perf = 0 WHERE id = ?', [$staff['id']]);
    t('people switched off are left out', !isset(perf_staff()[(int)$staff['id']]));
    q('UPDATE users SET track_perf = 1 WHERE id = ?', [$staff['id']]);
}

// ---------------------------------------------------------------- backups hold everything
require_once $root . '/inc/backup.php';
$bk = backup_create(false, 'it-test');
$cleanup[] = fn() => @unlink($bk['path']);
$zip = new ZipArchive();
$zip->open($bk['path']);
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = $zip->getNameIndex($i);
}
$dump = (string)$zip->getFromName('database.sql');
$zip->close();
t('backup has the database and manifest', in_array('database.sql', $names, true) && in_array('manifest.json', $names, true), $names);
t('backup has the attendance data', !attendance_data_file() || in_array('attendance/db.php', $names, true));
foreach (['tasks', 'task_blocks', 'task_files', 'task_notes', 'orders', 'order_items', 'bills', 'users', 'settings'] as $tbl) {
    t("backup includes table $tbl", str_contains($dump, "CREATE TABLE `$tbl`"));
}
t('backup includes this run’s task page', str_contains($dump, 'Shoot the back'));
t('backup passes the restore check', (bool)backup_check($bk['path']));

// ---------------------------------------------------------------- clean up
foreach (array_reverse($cleanup) as $fn) {
    $fn();
}
t('test rows removed', !q("SELECT 1 FROM tasks WHERE title IN ('IT page test', 'IT page copy')")->fetchColumn() && !q('SELECT 1 FROM task_blocks WHERE task_id IN (?, ?)', [$tid, $cid])->fetchColumn());

echo "---\n" . ($fails ? "$fails of $count FAILED\n" : "ALL PASSED ($count checks)\n");
exit($fails ? 1 : 0);
