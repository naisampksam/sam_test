<?php
// Tasks: the admin (or whoever may assign) gives work to staff — video editing, marketing, design, production …;
// staff move their tasks along (To do → Working on it → For review → Done), add notes and the finished link.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/tasks.php';

$me = require_login();
$myId = (int)$me['id'];
$assign = can_assign_tasks();
$id = (int)($_GET['id'] ?? 0);

/** Task fields from the form. */
function task_from_post(bool $assign, int $myId): array
{
    $kind = isset(TASK_KINDS[$_POST['kind'] ?? '']) ? $_POST['kind'] : 'general';
    $extra = [];
    if ($kind === 'video') {
        $extra = ['platform' => in_array($_POST['platform'] ?? '', VIDEO_PLATFORMS, true) ? $_POST['platform'] : '',
            'length' => mb_substr(trim((string)($_POST['length'] ?? '')), 0, 40), 'footage' => mb_substr(trim((string)($_POST['footage'] ?? '')), 0, 500)];
    }
    $to = (int)($_POST['assigned_to'] ?? 0);
    return [
        'title' => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200),
        'details' => mb_substr(trim((string)($_POST['details'] ?? '')), 0, 8000),
        'kind' => $kind,
        'assigned_to' => $assign && $to ? $to : $myId, // staff can only make tasks for themselves
        'priority' => isset(TASK_PRIORITY[$_POST['priority'] ?? '']) ? $_POST['priority'] : 'normal',
        'due_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['due_date'] ?? '') ? $_POST['due_date'] : null,
        'brief_link' => mb_substr(trim((string)($_POST['brief_link'] ?? '')), 0, 500),
        'extra' => json_encode($extra, JSON_UNESCAPED_UNICODE),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'create') {
        $row = task_from_post($assign, $myId);
        if ($row['title'] === '') {
            flash('Give the task a title.', 'err');
            redirect('tasks.php?new=1');
        }
        $row += ['created_by' => $myId, 'status' => 'todo', 'created_at' => now(), 'updated_at' => now()];
        q('INSERT INTO tasks (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
        $newId = (int)db()->lastInsertId();
        task_note($newId, '', 'todo');
        flash('Task given to ' . user_name($row['assigned_to']) . '.');
        redirect(!empty($_POST['another']) ? 'tasks.php?new=1&to=' . $row['assigned_to'] . '&kind=' . $row['kind'] : 'tasks.php?id=' . $newId);
    }
    $t = get_task((int)($_POST['task'] ?? 0));
    if (!$t || !task_editable($t)) {
        http_response_code(403);
        exit('Not allowed.');
    }
    $tid = (int)$t['id'];
    if ($do === 'status') {
        task_set_status($t, (string)($_POST['status'] ?? ''), trim((string)($_POST['note'] ?? '')));
        if (isset($_POST['result_link'])) {
            q('UPDATE tasks SET result_link = ? WHERE id = ?', [mb_substr(trim((string)$_POST['result_link']), 0, 500), $tid]);
        }
        flash(($_POST['status'] ?? '') === 'done' ? '✓ Marked as done.' : 'Task updated.');
    } elseif ($do === 'note') {
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note !== '') {
            task_note($tid, $note);
        }
        if (isset($_POST['result_link']) && trim((string)$_POST['result_link']) !== $t['result_link']) {
            q('UPDATE tasks SET result_link = ?, updated_at = ? WHERE id = ?', [mb_substr(trim((string)$_POST['result_link']), 0, 500), now(), $tid]);
            task_note($tid, 'Result link: ' . trim((string)$_POST['result_link']));
        }
    } elseif ($do === 'edit' && ($assign || (int)$t['created_by'] === $myId)) {
        $row = task_from_post($assign, $myId);
        if (!$assign) {
            $row['assigned_to'] = (int)$t['assigned_to'];
        }
        if ($row['title'] !== '') {
            q('UPDATE tasks SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($row))) . ', updated_at = ? WHERE id = ?', array_merge(array_values($row), [now(), $tid]));
            if ((int)$row['assigned_to'] !== (int)$t['assigned_to']) {
                task_note($tid, 'Given to ' . user_name($row['assigned_to']) . ' (was ' . user_name($t['assigned_to']) . ')');
            }
        }
        flash('Task saved.');
    } elseif ($do === 'delete' && ($assign || (int)$t['created_by'] === $myId)) {
        q('DELETE FROM task_notes WHERE task_id = ?', [$tid]);
        q('DELETE FROM tasks WHERE id = ?', [$tid]);
        flash('Task deleted.');
        redirect('tasks.php');
    }
    redirect('tasks.php?id=' . $tid);
}

$staff = staff_list();
$pageTitle = 'Tasks';
$active = 'tasks';

// ------------------------------------------------------------------ one task
if ($id) {
    $t = get_task($id);
    if (!$t || !task_visible($t)) {
        http_response_code(404);
        exit('Task not found. <a href="tasks.php">Back to tasks</a>');
    }
    $notes = q('SELECT * FROM task_notes WHERE task_id = ? ORDER BY id', [$id])->fetchAll();
    $canEditFields = $assign || (int)$t['created_by'] === $myId;
    $late = task_late($t);
    $pageTitle = $t['title'];
    require __DIR__ . '/inc/header.php';
    ?>
<div class="page-head">
  <div><a class="back" href="tasks.php">← Tasks</a>
    <h1><?= h($t['title']) ?> <span class="badge <?= ['todo' => 'pending', 'doing' => 'printing', 'review' => 'packed', 'done' => 'shipped'][$t['status']] ?>"><?= h(TASK_STATUS[$t['status']]) ?></span>
      <?php if ($late): ?><span class="badge delayed">⚠ Late</span><?php endif; ?></h1>
    <p class="muted small"><?= h(TASK_KINDS[$t['kind']] ?? $t['kind']) ?> · for <b><?= h(user_name($t['assigned_to'])) ?></b> · given by <?= h(user_name($t['created_by'])) ?> on <?= h(fmt_date($t['created_at'], true)) ?>
      <?= $t['due_date'] ? ' · due <b class="' . ($late ? 'err-text' : '') . '">' . h(fmt_date($t['due_date'])) . '</b>' : '' ?> · priority <?= h(TASK_PRIORITY[$t['priority']] ?? '') ?></p></div>
</div>

<div class="two-col">
  <section class="panel">
    <h2>Task</h2>
    <?php if ($t['details'] !== ''): ?><div class="task-details"><?= nl2br(h($t['details'])) ?></div><?php else: ?><p class="muted">No details.</p><?php endif; ?>
    <?php if ($t['kind'] === 'video'): $x = $t['extra']; ?>
      <table class="table compact" style="margin-top:10px"><tbody>
        <tr><td>Platform</td><td><b><?= h(($x['platform'] ?? '') ?: '—') ?></b></td></tr>
        <tr><td>Length</td><td><?= h(($x['length'] ?? '') ?: '—') ?></td></tr>
        <tr><td>Raw footage</td><td><?= ($x['footage'] ?? '') !== '' ? '<a href="' . h($x['footage']) . '" target="_blank" rel="noopener">Open footage ↗</a>' : '—' ?></td></tr>
      </tbody></table>
    <?php endif; ?>
    <?php if ($t['brief_link'] !== ''): ?><p>📎 <a href="<?= h($t['brief_link']) ?>" target="_blank" rel="noopener">Brief / reference ↗</a></p><?php endif; ?>
    <?php if ($t['result_link'] !== ''): ?><p class="task-result">🔗 Result: <a href="<?= h($t['result_link']) ?>" target="_blank" rel="noopener"><?= h($t['result_link']) ?></a></p><?php endif; ?>

    <h3 class="perm-group">Move it along</h3>
    <form method="post" class="task-status-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="task" value="<?= $id ?>">
      <label class="field"><span class="lbl">Result link <small class="muted">(final video, post, drive file …)</small></span>
        <input type="url" name="result_link" value="<?= h($t['result_link']) ?>" placeholder="https://"></label>
      <label class="field"><span class="lbl">Note <small class="muted">(optional)</small></span><textarea name="note" rows="2" placeholder="What was done, what is left…"></textarea></label>
      <div class="status-btns">
        <?php foreach (TASK_STATUS as $sk => $sl): if ($sk === $t['status']) continue; ?>
          <button class="btn <?= $sk === 'done' ? 'primary' : '' ?>" name="status" value="<?= $sk ?>"><?= ['todo' => '↺ ', 'doing' => '▶ ', 'review' => '👀 ', 'done' => '✓ '][$sk] . h($sk === 'done' ? 'Mark as done' : $sl) ?></button>
        <?php endforeach; ?>
      </div>
    </form>
  </section>

  <section class="panel">
    <h2>Updates</h2>
    <ul class="history task-notes">
      <?php foreach ($notes as $n): ?>
        <li><span class="muted"><?= h(fmt_date($n['created_at'], true)) ?></span> · <b><?= h(user_name($n['user_id'])) ?></b>
          <?php if ($n['status'] !== ''): ?> <span class="badge pending"><?= h($n['status'] === 'todo' && $n === $notes[0] ? 'Task given' : '→ ' . (TASK_STATUS[$n['status']] ?? $n['status'])) ?></span><?php endif; ?>
          <?php if ($n['note'] !== null && $n['note'] !== ''): ?><div><?= nl2br(h($n['note'])) ?></div><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="note"><input type="hidden" name="task" value="<?= $id ?>">
      <label class="field"><span class="lbl">Add an update</span><textarea name="note" rows="2" placeholder="Progress, question, feedback…"></textarea></label>
      <button class="btn">💬 Add update</button>
    </form>
    <?php if ($t['started_at'] || $t['done_at']): ?>
      <p class="muted small">Started <?= h($t['started_at'] ? fmt_date($t['started_at'], true) : '—') ?><?= $t['done_at'] ? ' · done ' . h(fmt_date($t['done_at'], true)) . ' (' . h(duration_text(strtotime($t['done_at']) - strtotime($t['created_at']))) . ' after it was given)' : '' ?></p>
    <?php endif; ?>
  </section>
</div>

<section class="panel">
  <a class="btn" href="tasks.php?new=1&amp;from=<?= $id ?>">↻ Give again</a> <span class="muted small">a new task with the same details<?= $assign ? '' : ' (for you)' ?></span>
</section>
<?php if ($canEditFields): ?>
<section class="panel">
  <details>
    <summary class="btn">✎ Edit task</summary>
    <?php task_form($t, $staff, $assign); ?>
  </details>
  <form method="post" onsubmit="return confirm('Delete this task?')" style="margin-top:10px; display:inline-block"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="task" value="<?= $id ?>"><button class="btn danger small">Delete task</button></form>
</section>
<?php endif; ?>
<?php
    require __DIR__ . '/inc/footer.php';
    exit;
}

/** New / edit task form. */
function task_form(?array $t, array $staff, bool $assign): void
{
    $new = $t === null;
    $t = $t ?? ['title' => '', 'details' => '', 'kind' => $_GET['kind'] ?? '', 'assigned_to' => (int)($_GET['to'] ?? 0), 'priority' => 'normal',
        'due_date' => '', 'brief_link' => '', 'extra' => [], 'id' => 0];
    // "Give again": start from an earlier task (not its dates, status or result).
    if ($new && !empty($_GET['from']) && ($src = get_task((int)$_GET['from'])) && task_visible($src)) {
        $t = array_merge($t, ['title' => $src['title'], 'details' => (string)$src['details'], 'kind' => $src['kind'], 'priority' => $src['priority'],
            'brief_link' => $src['brief_link'], 'extra' => array_intersect_key($src['extra'], ['platform' => 1, 'length' => 1]),
            'assigned_to' => $assign ? (int)$src['assigned_to'] : (int)current_user()['id']]);
    }
    $templates = $new ? task_templates() : [];
    $x = is_array($t['extra']) ? $t['extra'] : [];
    if (!isset(TASK_KINDS[$t['kind']])) {
        $who = $staff[(int)$t['assigned_to']] ?? current_user();
        $t['kind'] = kind_for_job(user_job($who));
    }
    ?>
    <?php if ($templates): ?>
      <div class="quick-tasks">
        <span class="lbl">⚡ Quick add — tap an earlier task</span>
        <div class="chips">
          <?php foreach (array_slice($templates, 0, 14) as $i => $tp): ?>
            <button type="button" class="chip" data-tpl="<?= $i ?>" title="<?= h(TASK_KINDS[$tp['kind']] ?? '') ?><?= $tp['n'] > 1 ? ' · given ' . $tp['n'] . ' times' : '' ?>"><?= h(mb_strimwidth($tp['title'], 0, 48, '…')) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <datalist id="taskTitles"><?php foreach ($templates as $tp): ?><option value="<?= h($tp['title']) ?>"><?php endforeach; ?></datalist>
    <?php endif; ?>
    <form method="post" class="grid task-form" style="margin-top:12px" data-templates="<?= h(json_encode($templates, JSON_UNESCAPED_UNICODE)) ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="<?= $t['id'] ? 'edit' : 'create' ?>"><input type="hidden" name="task" value="<?= (int)$t['id'] ?>">
      <label class="field full"><span class="lbl">Task <i class="req">*</i></span><input name="title" value="<?= h($t['title']) ?>" required maxlength="200" placeholder="Type, or pick an earlier task" <?= $templates ? 'list="taskTitles" data-tpl-title' : '' ?> autocomplete="off"></label>
      <?php if ($assign): ?>
        <label class="field"><span class="lbl">Give to</span><select name="assigned_to" data-task-to>
          <?php foreach ($staff as $uid => $u): ?><option value="<?= $uid ?>" data-kind="<?= h(kind_for_job(user_job($u))) ?>" <?= (int)$t['assigned_to'] === $uid ? 'selected' : '' ?>><?= h(($u['name'] ?: $u['username']) . ' · ' . ($u['role'] === 'admin' ? 'Admin' : (($u['job_title'] ?? '') ?: JOBS[user_job($u)]))) ?></option><?php endforeach; ?>
        </select></label>
      <?php endif; ?>
      <label class="field"><span class="lbl">Type</span><select name="kind" data-task-kind>
        <?php foreach (TASK_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $t['kind'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
      </select></label>
      <label class="field"><span class="lbl">Due date</span><input type="date" name="due_date" value="<?= h((string)$t['due_date']) ?>"></label>
      <label class="field"><span class="lbl">Priority</span><select name="priority"><?php foreach (TASK_PRIORITY as $k => $l): ?><option value="<?= $k ?>" <?= $t['priority'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <div class="field full video-only" data-video-fields <?= $t['kind'] === 'video' ? '' : 'hidden' ?>>
        <div class="grid">
          <label class="field"><span class="lbl">Platform</span><select name="platform"><option value="">—</option><?php foreach (VIDEO_PLATFORMS as $pl): ?><option <?= ($x['platform'] ?? '') === $pl ? 'selected' : '' ?>><?= h($pl) ?></option><?php endforeach; ?></select></label>
          <label class="field"><span class="lbl">Length</span><input name="length" value="<?= h($x['length'] ?? '') ?>" placeholder="e.g. 30 sec"></label>
          <label class="field full"><span class="lbl">Raw footage link</span><input type="url" name="footage" value="<?= h($x['footage'] ?? '') ?>" placeholder="Google Drive / folder link"></label>
        </div>
      </div>
      <label class="field full"><span class="lbl">Details</span><textarea name="details" rows="3" placeholder="What exactly to do, style, captions, references…"><?= h($t['details']) ?></textarea></label>
      <label class="field full"><span class="lbl">Brief / reference link</span><input type="url" name="brief_link" value="<?= h($t['brief_link']) ?>" placeholder="https://"></label>
      <div class="field full"><div class="status-btns">
        <button class="btn primary"><?= $t['id'] ? '💾 Save task' : '＋ Give task' ?></button>
        <?php if (!$t['id']): ?><button class="btn" name="another" value="1">＋ Give &amp; add another</button><?php endif; ?>
      </div></div>
    </form>
    <script>
    (function () {
      var f = document.currentScript.previousElementSibling, k = f.querySelector('[data-task-kind]'), to = f.querySelector('[data-task-to]');
      function sync() { f.querySelector('[data-video-fields]').hidden = k.value !== 'video'; }
      // Quick add: fill the form from an earlier task (chip or a title picked from the list).
      var tpls = JSON.parse(f.dataset.templates || '[]');
      function use(tp) {
        var set = function (n, v) { var el = f.querySelector('[name="' + n + '"]'); if (el && v !== undefined && v !== null) el.value = v; };
        set('title', tp.title); set('details', tp.details); set('priority', tp.priority); set('brief_link', tp.brief_link);
        set('kind', tp.kind); set('platform', tp.platform); set('length', tp.length);
        if (to && tp.to && to.querySelector('option[value="' + tp.to + '"]') && !to.dataset.picked) to.value = tp.to;
        sync();
        var due = f.querySelector('[name=due_date]'); if (due && !due.value) due.focus();
      }
      if (to) to.addEventListener('change', function () { to.dataset.picked = '1'; });
      var box = f.previousElementSibling && f.previousElementSibling.previousElementSibling;
      if (box && box.classList.contains('quick-tasks')) box.addEventListener('click', function (e) {
        var c = e.target.closest('[data-tpl]'); if (!c) return;
        use(tpls[+c.dataset.tpl]);
        box.querySelectorAll('.chip').forEach(function (x) { x.classList.toggle('on', x === c); });
      });
      var ti = f.querySelector('[data-tpl-title]');
      if (ti) ti.addEventListener('change', function () { var tp = tpls.find(function (x) { return x.title === ti.value; }); if (tp) use(tp); });
      k.addEventListener('change', sync);
      if (to && !<?= $t['id'] ? 'true' : 'false' ?>) to.addEventListener('change', function () { k.value = to.selectedOptions[0].dataset.kind || k.value; sync(); });
    })();
    </script>
    <?php
}

function duration_text(int $sec): string
{
    $h = $sec / 3600;
    return $h < 1 ? max(1, (int)round($sec / 60)) . ' min' : ($h < 48 ? round($h, 1) . ' h' : round($h / 24, 1) . ' days');
}

// ------------------------------------------------------------------ board
$who = $assign ? (string)($_GET['who'] ?? 'all') : 'me';
if (!$assign && ($_GET['who'] ?? '') === 'given') {
    $who = 'given';
}
$kind = isset(TASK_KINDS[$_GET['kind'] ?? '']) ? $_GET['kind'] : '';
if ($kind === '' && !isset($_GET['kind']) && user_job() === 'video_editor') {
    $kind = 'video';
}
$s = trim((string)($_GET['q'] ?? ''));
$where = [task_privacy_sql()];
$args = [];
if ($who === 'me') {
    $where[] = 'assigned_to = ?';
    $args[] = $myId;
} elseif ($who === 'given') {
    $where[] = 'created_by = ?';
    $args[] = $myId;
} elseif (ctype_digit($who)) {
    $where[] = 'assigned_to = ?';
    $args[] = (int)$who;
}
if ($kind !== '') {
    $where[] = 'kind = ?';
    $args[] = $kind;
}
if ($s !== '') {
    $where[] = '(title LIKE ? OR details LIKE ?)';
    array_push($args, "%$s%", "%$s%");
}
$w = implode(' AND ', $where);
$open = q("SELECT * FROM tasks WHERE $w AND status <> 'done' ORDER BY FIELD(priority, 'urgent', 'high', 'normal', 'low'), due_date IS NULL, due_date, id", $args)->fetchAll();
$done = q("SELECT * FROM tasks WHERE $w AND status = 'done' AND done_at >= ? ORDER BY done_at DESC LIMIT 60", array_merge($args, [date('Y-m-d', strtotime('-30 days'))]))->fetchAll();
$cols = ['todo' => [], 'doing' => [], 'review' => [], 'done' => $done];
foreach ($open as $t) {
    $cols[$t['status']][] = $t;
}
$overdue = count(array_filter($open, 'task_late'));
$showWho = $who !== 'me';
$qs = fn(array $over) => 'tasks.php?' . http_build_query(array_filter(array_merge(['who' => $who, 'kind' => $kind, 'q' => $s], $over), fn($v) => $v !== '' && $v !== null));
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <div><h1><?= $kind === 'video' ? '🎬 Video editing' : 'Tasks' ?></h1>
    <p class="muted small"><?= count($open) ?> open<?= $overdue ? ' · <b class="err-text">' . $overdue . ' late</b>' : '' ?> · <?= count($done) ?> done in the last 30 days</p></div>
  <div class="actions"><a class="btn primary" href="tasks.php?new=1<?= $kind ? '&kind=' . h($kind) : '' ?><?= ctype_digit($who) ? '&to=' . h($who) : '' ?>">＋ <?= $assign ? 'Give a task' : 'Add my task' ?></a>
    <a class="btn" href="performance.php">📈 Your performance</a></div>
</div>

<?php if (!empty($_GET['new'])): ?>
<section class="panel">
  <h2><?= $assign ? 'Give a task' : 'Add a task for yourself' ?></h2>
  <?php task_form(null, $staff, $assign); ?>
</section>
<?php endif; ?>

<form class="filters" method="get">
  <?php if ($assign): ?>
    <select name="who" onchange="this.form.submit()">
      <option value="all" <?= $who === 'all' ? 'selected' : '' ?>>Everyone</option>
      <option value="me" <?= $who === 'me' ? 'selected' : '' ?>>My tasks</option>
      <option value="given" <?= $who === 'given' ? 'selected' : '' ?>>Given by me</option>
      <?php foreach ($staff as $uid => $u): ?><option value="<?= $uid ?>" <?= $who === (string)$uid ? 'selected' : '' ?>><?= h($u['name'] ?: $u['username']) ?></option><?php endforeach; ?>
    </select>
  <?php else: ?>
    <span class="seg-links"><a class="<?= $who === 'me' ? 'on' : '' ?>" href="<?= h($qs(['who' => 'me'])) ?>">My tasks</a><a class="<?= $who === 'given' ? 'on' : '' ?>" href="<?= h($qs(['who' => 'given'])) ?>">Added by me</a></span>
  <?php endif; ?>
  <select name="kind" onchange="this.form.submit()">
    <option value="">All types</option>
    <?php foreach (TASK_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
  </select>
  <input type="search" name="q" value="<?= h($s) ?>" placeholder="Search tasks">
  <button class="btn">Search</button>
</form>

<div class="task-board">
  <?php foreach ($cols as $st => $list): ?>
    <section class="task-col col-<?= $st ?>">
      <h3><?= h(TASK_STATUS[$st]) ?> <span class="count"><?= count($list) ?></span><?= $st === 'done' ? ' <small class="muted">30 days</small>' : '' ?></h3>
      <?php foreach ($list as $t): ?><?= task_card($t, $showWho) ?><?php endforeach; ?>
      <?php if (!$list): ?><p class="muted small">Nothing here.</p><?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>

<?php if ($kind === 'video'):
    $vids = q("SELECT * FROM tasks WHERE $w AND status = 'done' ORDER BY done_at DESC LIMIT 100", $args)->fetchAll(); ?>
<section class="panel">
  <h2>🎬 Videos delivered <small class="muted">latest 100</small></h2>
  <?php if (!$vids): ?><p class="muted">No finished videos yet.</p><?php else: ?>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Video</th><th>Platform</th><th>Length</th><th>Editor</th><th>Given</th><th>Delivered</th><th>Time taken</th><th>Final</th></tr></thead>
    <tbody>
    <?php foreach ($vids as $v): $vx = json_decode((string)$v['extra'], true) ?: []; ?>
      <tr><td><a href="tasks.php?id=<?= (int)$v['id'] ?>"><?= h($v['title']) ?></a></td><td><?= h($vx['platform'] ?? '') ?></td><td><?= h($vx['length'] ?? '') ?></td>
        <td><?= h(user_name($v['assigned_to'])) ?></td><td class="nowrap"><?= h(fmt_date($v['created_at'])) ?></td>
        <td class="nowrap <?= task_late($v) ? 'err-text' : '' ?>"><?= h(fmt_date($v['done_at'])) ?><?= task_late($v) ? ' (late)' : '' ?></td>
        <td class="nowrap"><?= h(duration_text(strtotime($v['done_at']) - strtotime($v['created_at']))) ?></td>
        <td><?= $v['result_link'] !== '' ? '<a href="' . h($v['result_link']) . '" target="_blank" rel="noopener">Open ↗</a>' : '<span class="muted">—</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
