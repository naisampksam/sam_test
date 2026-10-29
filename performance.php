<?php
// Your performance: what each person did today / this week / this month — order work and tasks — with the team next to it.
// Everyone sees their own page and the team table; the admin (or whoever may assign tasks) can open anyone's page.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/tasks.php';

$me = require_login();
$boss = can_assign_tasks();
// Admin: points for each piece of work.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    csrf_check();
    $rules = [];
    foreach (SCORE_DEFAULTS as $k => $def) {
        $v = str_replace(',', '.', trim((string)($_POST['rule'][$k] ?? '')));
        $rules[$k] = is_numeric($v) ? max(0, min($k === 'late' ? 100 : 1000, round((float)$v, 2))) : $def;
    }
    set_setting('score_points', json_encode($rules));
    unset($_SESSION['my_score']);
    flash('Score points saved.');
    redirect('performance.php?' . http_build_query(array_intersect_key($_GET, ['p' => 1, 'user' => 1])));
}
// Tracked staff only (admins are never monitored); staff see their own team, the admin sees every team.
if (!is_admin() && !perf_tracked($me)) {
    $pageTitle = 'Your performance';
    $active = 'perf';
    require __DIR__ . '/inc/header.php';
    echo '<div class="page-head"><div><h1>Your performance</h1></div></div><section class="panel"><p class="muted">Your work is not tracked with points. Your tasks are on the Tasks page.</p></section>';
    require __DIR__ . '/inc/footer.php';
    exit;
}
$staff = perf_staff();
if (is_admin() && !$staff) {
    flash('No staff are tracked yet. Tick “Track performance” on a staff member.', 'err');
    redirect('admin/users.php');
}
$uid = $boss && isset($staff[(int)($_GET['user'] ?? 0)]) ? (int)$_GET['user'] : (is_admin() ? (int)array_key_first($staff) : (int)$me['id']);
$person = $staff[$uid] ?? $me;
$teamName = task_team($person) === 'production' ? 'Production team' : 'Creative team';
$teamStaff = perf_staff(task_team($person)); // ranking and the team table: this person's team only
$isMe = $uid === (int)$me['id'];

$period = in_array($_GET['p'] ?? '', ['today', 'week', 'month', 'last'], true) ? $_GET['p'] : 'month';
[$from, $to, $periodLabel] = match ($period) {
    'today' => [today(), today(), 'Today'],
    'week' => [date('Y-m-d', strtotime('monday this week')), today(), 'This week'],
    'last' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month')), date('F Y', strtotime('first day of last month'))],
    default => [date('Y-m-01'), today(), 'This month'],
};
$perf = staff_performance($from, $to);
$mine = $perf[$uid] ?? [];
$v = fn(string $k) => (int)round($mine[$k] ?? 0);
$daily = staff_daily($uid, 30);
$open = q("SELECT * FROM tasks WHERE assigned_to = ? AND status <> 'done' ORDER BY due_date IS NULL, due_date, FIELD(priority, 'urgent', 'high', 'normal', 'low')", [$uid])->fetchAll();
$doneList = q("SELECT * FROM tasks WHERE assigned_to = ? AND status = 'done' AND done_at BETWEEN ? AND ? ORDER BY done_at DESC LIMIT 50", [$uid, "$from 00:00:00", "$to 23:59:59"])->fetchAll();
$job = user_job($person);
$personTasks = task_page_cap(user_with_caps($person)) || $v('done') + $v('pending') > 0;
$score = work_points($mine);
// Team ranking by points (highest first).
$ranked = $teamStaff;
uasort($ranked, fn($x, $y) => ($perf[(int)$y['id']]['points'] ?? 0) <=> ($perf[(int)$x['id']]['points'] ?? 0));
$rank = 1 + count(array_filter(array_keys($teamStaff), fn($sid) => ($perf[$sid]['points'] ?? 0) > $score['total']));
$topPoints = max(1, ...array_map(fn($sid) => (int)($perf[$sid]['points'] ?? 0), array_keys($teamStaff) ?: [0]));
$showOrders = $job === 'production' || $person['role'] === 'admin' || $v('orders') + $v('printed') + $v('packed') + $v('shipped') > 0;
// Admin: everything late right now, by person.
$lateTasks = $boss ? q("SELECT * FROM tasks WHERE status <> 'done' AND due_date < ? AND " . task_privacy_sql() . " ORDER BY due_date", [today()])->fetchAll() : [];
$lateOrders = $boss ? q("SELECT o.*, (SELECT IFNULL(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) pcs FROM orders o
                         WHERE o.deleted_at IS NULL AND o.shipped = 0 AND o.due_date < ? ORDER BY o.due_date LIMIT 50", [today()])->fetchAll() : [];

$pageTitle = $isMe ? 'Your performance' : user_name($uid) . ' · performance';
$active = 'perf';
require __DIR__ . '/inc/header.php';
$pq = fn(array $o) => 'performance.php?' . http_build_query(array_merge(['p' => $period] + ($isMe ? [] : ['user' => $uid]), $o));
$stat = fn(string $name, string $num, string $sub = '', string $cls = '') => '<div class="stat ' . $cls . '"><span class="stat-label">' . $name . '</span><span class="stat-num">' . $num . '</span><span class="stat-sub">' . $sub . '</span></div>';
?>
<div class="page-head">
  <div><h1><?= $isMe ? 'Your performance' : h(user_name($uid)) ?></h1>
    <p class="muted small"><?= h($person['role'] === 'admin' ? 'Admin' : (($person['job_title'] ?? '') ?: JOBS[$job])) ?> · <?= h($periodLabel) ?><?= $period !== 'today' ? ' (' . h(fmt_date($from)) . ' – ' . h(fmt_date($to)) . ')' : '' ?></p></div>
  <div class="actions">
    <?php if ($boss): ?>
      <form method="get"><input type="hidden" name="p" value="<?= h($period) ?>"><select name="user" onchange="this.form.submit()">
        <?php foreach (['production' => 'Production team', 'creative' => 'Creative team'] as $tk => $tl): $grp = array_filter($staff, fn($su) => task_team($su) === $tk); if (!$grp) continue; ?>
          <optgroup label="<?= h($tl) ?>"><?php foreach ($grp as $sid => $su): ?><option value="<?= $sid ?>" <?= $sid === $uid ? 'selected' : '' ?>><?= h($su['name'] ?: $su['username']) ?><?= $sid === (int)$me['id'] ? ' (you)' : '' ?></option><?php endforeach; ?></optgroup>
        <?php endforeach; ?>
      </select></form>
    <?php endif; ?>
    <a class="btn" href="<?= h(tasks_url($isMe ? '' : 'who=' . $uid)) ?>">📋 Tasks</a>
  </div>
</div>

<nav class="seg-links perf-periods">
  <?php foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'last' => 'Last month'] as $k => $l): ?>
    <a class="<?= $period === $k ? 'on' : '' ?>" href="<?= h($pq(['p' => $k])) ?>"><?= $l ?></a>
  <?php endforeach; ?>
</nav>

<section class="panel score-panel">
  <div class="score-hero">
    <div><span class="stat-label">⭐ Work score · <?= h(strtolower($periodLabel)) ?></span><div class="score-big"><?= $score['total'] ?> <small class="muted" style="font-size:1rem">points</small></div></div>
    <?php if (count($teamStaff) > 1): ?><span class="score-rank">#<?= $rank ?> of <?= count($teamStaff) ?> in the <?= h(strtolower($teamName)) ?></span><?php endif; ?>
  </div>
  <details class="score-how" <?= $score['total'] ? '' : 'open' ?>>
    <summary class="muted small">How the points add up</summary>
    <div class="table-wrap"><table class="table compact">
      <thead><tr><th>Work marked</th><th class="num">Count</th><th class="num">Points each</th><th class="num">Points</th></tr></thead>
      <tbody>
      <?php foreach ($score['lines'] as $k => $ln): if (!$ln['count'] && (!$personTasks && $k === 'task')) continue; ?>
        <tr class="<?= $ln['count'] ? '' : 'muted' ?>"><td><?= h($ln['label']) ?><?= $ln['late'] ? ' <small class="err-text">' . $ln['late'] . ' late (' . (float)score_rules()['late'] . '% points)</small>' : '' ?></td>
          <td class="num"><?= $ln['count'] ?></td><td class="num"><?= (float)$ln['each'] ?></td><td class="num"><b><?= round($ln['points'], 1) ?></b></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="3"><b>Total</b></td><td class="num"><b><?= $score['total'] ?></b></td></tr></tfoot>
    </table></div>
    <p class="hint">Points come from work marked in the app: orders created, print processed, pieces printed, orders packed and shipped<?= $personTasks ? ', tasks done' : '' ?>.
      Work done after the order’s dispatch date (or a task after its due date) earns <?= (float)score_rules()['late'] ?>% of the points.</p>
  </details>
</section>

<?php
// Attendance of this person (linked on Setup → Staff links).
require_once __DIR__ . '/inc/staff_links.php';
$attLink = staff_links([$uid => $person])[$uid]['id'] ?? '';
$att = $attLink ? attendance_summary($attLink, $from, $to) : null;
?>
<div class="stats">
  <?php
  $pct = $mine['on_time_pct'] ?? null;
  echo $stat('On time', $pct === null ? (($mine['on_time_all'] ?? 0) ? '<small>' . on_time_text($mine) . '</small>' : '—') : $pct . '%',
      $pct === null && ($mine['on_time_all'] ?? 0) ? '% shows after ' . ON_TIME_MIN . ' tasks / order jobs' : 'tasks &amp; order work done by their date', $pct !== null && $pct < 80 ? 'stat-warn' : '');
  if ($personTasks) {
      echo $stat('Tasks done', (string)$v('done'), $v('done') ? $v('done_on_time') . ' on time' . ($v('done_late') ? ', <b class="err-text">' . $v('done_late') . ' late</b>' : '') : 'in ' . strtolower(h($periodLabel)));
      echo $stat('Pending tasks', (string)$v('pending'), $v('review') ? $v('review') . ' waiting for review' : 'open now');
      echo $stat('Late tasks', (string)$v('overdue'), 'past due, not done', $v('overdue') ? 'stat-alert' : '');
  }
  if ($showOrders) {
      echo $stat('Orders created', (string)$v('orders'), $v('orders_pcs') . ' pcs');
      echo $stat('Printed', $v('printed') . ' <small>pcs</small>', $v('printed_late') ? '<b class="err-text">' . $v('printed_late') . ' pcs after dispatch date</b>' : ($v('processed') ? $v('processed') . ' orders print-processed' : ''));
      echo $stat('Packed', (string)$v('packed'), $v('packed_pcs') . ' pcs');
      echo $stat('Shipped', (string)$v('shipped'), $v('shipped_late') ? '<b class="err-text">' . $v('shipped_late') . ' after dispatch date</b>' : $v('shipped_pcs') . ' pcs');
  }
  if ($v('videos')) {
      echo $stat('Videos delivered', (string)$v('videos'), 'edited &amp; done');
  }
  if ($att) {
      echo $stat('Attendance', $att['days'] . ' <small>days</small>', round($att['minutes'] / 60, 1) . ' h clocked in · ' . h(attendance_people()[$attLink]['name']));
  }
  ?>
</div>

<section class="panel">
  <h2>Last 30 days <small class="muted"><?= $showOrders ? 'pieces printed / packed / shipped and tasks done per day' : 'tasks done per day' ?></small></h2>
  <?php $maxD = max(1, max(array_map(fn($d) => $d['pcs'] + $d['tasks'] * 10, $daily))); ?>
  <div class="perf-chart">
    <?php foreach ($daily as $d => $x): $hPcs = $x['pcs'] / $maxD * 100; $hT = $x['tasks'] * 10 / $maxD * 100; ?>
      <div class="pc-day <?= $d === today() ? 'today' : '' ?>" title="<?= h(fmt_date($d)) ?>: <?= $x['pcs'] ?> pcs, <?= $x['orders'] ?> orders created, <?= $x['tasks'] ?> tasks done">
        <div class="pc-bars"><?php if ($x['tasks']): ?><span class="pc-t" style="height:<?= round($hT, 1) ?>%"></span><?php endif; ?><?php if ($x['pcs']): ?><span class="pc-p" style="height:<?= round($hPcs, 1) ?>%"></span><?php endif; ?></div>
        <small><?= (int)substr($d, 8) ?></small>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="muted small"><span class="key pc-p"></span> pieces <span class="key pc-t"></span> tasks done (each task shown as 10)</p>
</section>

<?php if ($personTasks): ?>
<div class="two-col">
  <section class="panel">
    <h2>Open tasks <small class="muted"><?= count($open) ?></small></h2>
    <?php if (!$open): ?><p class="muted">No open tasks. 🎉</p><?php else: ?>
      <div class="task-list"><?php foreach ($open as $t): ?><?= task_card($t, false) ?><?php endforeach; ?></div>
    <?php endif; ?>
  </section>
  <section class="panel">
    <h2>Done · <?= h(strtolower($periodLabel)) ?> <small class="muted"><?= count($doneList) ?></small></h2>
    <?php if (!$doneList): ?><p class="muted">No tasks finished in this period.</p><?php else: ?>
      <div class="task-list"><?php foreach ($doneList as $t): ?><?= task_card($t, false) ?><?php endforeach; ?></div>
    <?php endif; ?>
  </section>
</div>
<?php endif; ?>

<section class="panel">
  <h2><?= h($teamName) ?> · <?= h(strtolower($periodLabel)) ?> <small class="muted">tracked people only</small></h2>
  <div class="table-wrap"><table class="table compact team-table">
    <thead><tr><th class="num">#</th><th>Staff</th><th>Points</th><th class="num">Orders</th><th class="num">Printed pcs</th><th class="num">Packed</th><th class="num">Shipped</th><th class="num">Tasks done</th><th class="num">Pending</th><th class="num">Late</th><th class="num">On time</th></tr></thead>
    <tbody>
    <?php $place = 0; foreach ($ranked as $sid => $su): $r = $perf[$sid] ?? []; $g = fn(string $k) => (int)round($r[$k] ?? 0); $place++; ?>
      <tr class="<?= $sid === $uid ? 'row-hl' : '' ?>">
        <td class="num muted"><?= $g('points') ? $place : '·' ?></td>
        <td><?= $boss ? '<a href="' . h($pq(['user' => $sid])) . '">' : '' ?><b><?= h($su['name'] ?: $su['username']) ?></b><?= $boss ? '</a>' : '' ?><?= $sid === (int)$me['id'] ? ' <small class="muted">(you)</small>' : '' ?>
          <br><small class="muted"><?= h($su['role'] === 'admin' ? 'Admin' : (($su['job_title'] ?? '') ?: JOBS[user_job($su)])) ?></small></td>
        <td><b>⭐ <?= $g('points') ?></b><div class="lb-bar"><i style="width:<?= round($g('points') / $topPoints * 100) ?>%"></i></div></td>
        <td class="num"><?= $g('orders') ?: '·' ?></td><td class="num"><?= $g('printed') ?: '·' ?></td><td class="num"><?= $g('packed') ?: '·' ?></td><td class="num"><?= $g('shipped') ?: '·' ?></td>
        <td class="num"><b><?= $g('done') ?: '·' ?></b></td><td class="num"><?= $g('pending') ?: '·' ?></td>
        <td class="num"><?= $g('overdue') + $g('done_late') + $g('printed_late') + $g('shipped_late') ? '<b class="err-text">' . ($g('overdue') + $g('done_late')) . ($g('printed_late') + $g('shipped_late') ? ' <small>+' . ($g('printed_late') + $g('shipped_late')) . ' order</small>' : '') . '</b>' : '·' ?></td>
        <td class="num"><?= $r ? on_time_text($r) : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="hint">Points: work marked in the app (see “How the points add up” above). Late = tasks past their due date (open or finished late) + order work done after the order’s dispatch date. On time counts tasks and printed / shipped work.</p>
</section>

<?php if (is_admin()): $rules = score_rules(); ?>
<section class="panel">
  <details>
    <summary class="btn">⚙ Points for each piece of work</summary>
    <form method="post" style="margin-top:12px">
      <?= csrf_field() ?>
      <div class="score-rules">
        <?php foreach (SCORE_LABELS as $k => $l): ?>
          <label class="field"><span class="lbl"><?= h($l) ?></span><input name="rule[<?= $k ?>]" type="number" step="0.5" min="0" value="<?= (float)$rules[$k] ?>" inputmode="decimal"></label>
        <?php endforeach; ?>
        <label class="field"><span class="lbl">Late work earns (%)</span><input name="rule[late]" type="number" step="5" min="0" max="100" value="<?= (float)$rules['late'] ?>"></label>
      </div>
      <p class="hint">T-shirt lines are per piece (creating an order of 10 T-shirts = 10 + 1 for the order; print-processing it = 5 + 1); the others are per order or task. Only T-shirts count for orders created (print-only and DTF lines don’t). Changes apply to every period shown (points are worked out from the work, not stored).</p>
      <button class="btn primary">💾 Save points</button>
    </form>
  </details>
</section>
<?php endif; ?>

<?php if ($boss && ($lateTasks || $lateOrders)): ?>
<section class="panel panel-alert">
  <h2>⚠ Delayed right now</h2>
  <div class="two-col">
    <div>
      <h3 class="perm-group">Late tasks <?= count($lateTasks) ?></h3>
      <?php if (!$lateTasks): ?><p class="muted small">None.</p><?php else: ?>
      <div class="table-wrap"><table class="table compact"><thead><tr><th>Task</th><th>Who</th><th>Due</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($lateTasks as $t): ?><tr><td><a href="<?= h(tasks_url('id=' . (int)$t['id'])) ?>"><?= h($t['title']) ?></a></td><td><?= h(user_name($t['assigned_to'])) ?></td>
          <td class="err-text nowrap"><?= h(fmt_date($t['due_date'])) ?></td><td><?= h(TASK_STATUS[$t['status']]) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <div>
      <h3 class="perm-group">Late orders <?= count($lateOrders) ?></h3>
      <?php if (!$lateOrders): ?><p class="muted small">None.</p><?php else: ?>
      <div class="table-wrap"><table class="table compact"><thead><tr><th>Order</th><th>Stuck at</th><th>Due</th><th>Created by</th></tr></thead><tbody>
        <?php foreach ($lateOrders as $o): $st = order_status($o); ?><tr><td><a href="order.php?id=<?= (int)$o['id'] ?>"><?= h(order_no($o['id'])) ?></a> <small class="muted"><?= (int)$o['pcs'] ?> pcs</small></td>
          <td><?= h($st['label']) ?></td><td class="err-text nowrap"><?= h(fmt_date($o['due_date'])) ?></td><td><?= h(user_name($o['created_by'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
