<?php
// Staff links (admin): which Attendance person each Orders / Tasks account is. Matched by name; change any link here.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/tasks.php';
require __DIR__ . '/../inc/staff_links.php';

require_admin();
$users = staff_list();
$people = attendance_people();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $saved = [];
    foreach ($users as $uid => $u) {
        $v = (string)($_POST['link'][$uid] ?? 'auto');
        if ($v !== 'auto') {
            $saved[(string)$uid] = isset($people[$v]) ? $v : ''; // '' = not this app's person
        }
    }
    // An attendance person can belong to one account only.
    $used = array_filter(array_count_values(array_filter($saved)), fn($n) => $n > 1);
    if ($used) {
        flash('One attendance person is linked to two accounts: ' . implode(', ', array_map(fn($id) => $people[$id]['name'], array_keys($used))) . '. Pick a different person for one of them.', 'err');
    } else {
        set_setting('staff_links', json_encode($saved));
        flash('Staff links saved.');
    }
    redirect('admin/staff_links.php');
}

$saved = json_decode((string)setting('staff_links', ''), true) ?: [];
$staffLinks = staff_links($users);
$auto = auto_staff_links($users, $people);
$linkedPeople = array_flip(array_filter(array_column($staffLinks, 'id')));
$month = [date('Y-m-01'), today()];
$pageTitle = 'Staff links';
$active = 'stafflinks';
require __DIR__ . '/../inc/header.php';
?>
<div class="page-head">
  <div><a class="back" href="<?= h(base_url('admin/users.php')) ?>">← Staff</a><h1>Staff links</h1>
    <p class="muted small">Which person in Attendance each Orders &amp; Tasks account is. Matched by name; change any link and save.</p></div>
</div>

<?php if (!$people): ?>
  <section class="panel"><p class="muted">No attendance staff found. The attendance app keeps its data in <code>attendance/data/</code>; add employees there first.</p></section>
<?php else: ?>
<form method="post" class="panel">
  <?= csrf_field() ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Orders &amp; Tasks account</th><th>Attendance person</th><th>How it was matched</th><th class="num">This month</th></tr></thead>
    <tbody>
    <?php foreach ($users as $uid => $u): $l = $staffLinks[$uid]; $sum = $l['id'] ? attendance_summary($l['id'], ...$month) : null; ?>
      <tr>
        <td><b><?= h($u['name'] ?: $u['username']) ?></b><br><small class="muted">@<?= h($u['username']) ?> · <?= h(($u['job_title'] ?? '') ?: JOBS[user_job($u)]) ?></small></td>
        <td><select name="link[<?= $uid ?>]" aria-label="Attendance person for <?= h($u['name'] ?: $u['username']) ?>">
            <option value="auto" <?= !array_key_exists((string)$uid, $saved) ? 'selected' : '' ?>>Automatic<?= isset($auto[$uid]) ? ' (' . h($people[$auto[$uid]['id']]['name']) . ')' : ' (no match)' ?></option>
            <option value="" <?= array_key_exists((string)$uid, $saved) && $saved[(string)$uid] === '' ? 'selected' : '' ?>>Not in attendance</option>
            <?php foreach ($people as $pid => $p): ?>
              <option value="<?= h($pid) ?>" <?= array_key_exists((string)$uid, $saved) && $saved[(string)$uid] === $pid ? 'selected' : '' ?>><?= h($p['name']) ?><?= $p['active'] ? '' : ' (inactive)' ?></option>
            <?php endforeach; ?>
          </select></td>
        <td><?php if (!$l['id']): ?><span class="muted">Not linked</span>
          <?php else: ?><b><?= h($people[$l['id']]['name']) ?></b> <span class="badge <?= $l['how'] === 'similar' ? 'pending' : 'shipped' ?>"><?= h(['agreed' => 'confirmed by you', 'name' => 'same name', 'first name' => 'same first name', 'username' => 'username = first name', 'similar' => 'similar spelling — check', 'set by admin' => 'set by you'][$l['how']] ?? $l['how']) ?></span><?php endif; ?></td>
        <td class="num nowrap"><?= $sum ? $sum['days'] . ' days · ' . round($sum['minutes'] / 60, 1) . ' h' : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="status-btns" style="margin-top:12px"><button class="btn primary">💾 Save links</button></div>
  <?php $free = array_diff_key($people, $linkedPeople); if ($free): ?>
    <p class="hint">In Attendance but not linked to an account: <?= h(implode(', ', array_column($free, 'name'))) ?>.</p>
  <?php endif; ?>
  <p class="hint">Matching: same full name → same first name → username is the first name → similar spelling of the first name (shown as “check”). Each attendance person is linked to one account at most. A linked person’s attendance shows on Staff performance.</p>
</form>
<?php endif; ?>
<?php require __DIR__ . '/../inc/footer.php'; ?>
