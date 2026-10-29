<?php
// Tasks workspace (orders.loomaapparels.com/tasks) for staff the admin gives the Tasks page (video editor, marketing …).
// Board / table / calendar of tasks; each task is a Notion-style page: a script or brief written in blocks, where every
// paragraph can carry links, reference images, connected orders / tasks, timing, a shot note, comments and a ✓ edited tick.
// Tasks still move To do → Working on it → For review → Done (with approval) and count in Your performance.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/tasks.php';

$me = require_login();
$myId = (int)$me['id'];
$assign = can_assign_tasks();
$id = (int)($_GET['id'] ?? 0);

function json_reply(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    exit(json_encode($data, JSON_UNESCAPED_UNICODE));
}

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
        // Staff make tasks for themselves; the admin only gives tasks (never to an admin).
        'assigned_to' => $assign && isset(task_staff()[$to]) ? $to : (is_admin() ? 0 : $myId),
        'priority' => isset(TASK_PRIORITY[$_POST['priority'] ?? '']) ? $_POST['priority'] : 'normal',
        'due_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['due_date'] ?? '') ? $_POST['due_date'] : null,
        'brief_link' => mb_substr(trim((string)($_POST['brief_link'] ?? '')), 0, 500),
        'needs_review' => $assign && !empty($_POST['needs_review']) ? 1 : 0,
        'extra' => json_encode($extra, JSON_UNESCAPED_UNICODE),
    ];
}

/** Move a task to a status, with the approval rules. Returns the status it ended at and the message. */
function move_task(array $t, string $want, string $note, bool $sendBack = false): array
{
    if ($want === 'done' && task_needs_approval($t)) {
        $want = 'review'; // needs approval: goes to the person who gave it
        $note = trim('Sent for approval. ' . $note);
    }
    if ($sendBack && task_approver($t)) {
        $want = 'doing';
        $note = trim('↩ Sent back: ' . $note);
    }
    if (!isset(TASK_STATUS[$want])) {
        return [$t['status'], ''];
    }
    task_set_status($t, $want, $note);
    if ($want === 'done' && !empty($t['needs_review']) && $t['status'] !== 'done') {
        task_note((int)$t['id'], '✓ Approved by ' . user_name(current_user()['id']));
    }
    return [$want, ['done' => '✓ Marked as done.', 'review' => '👀 Sent for approval.', 'doing' => 'Task updated.'][$want] ?? 'Task updated.'];
}

/** Comments on one block (newest last). */
function block_comments(int $tid, string $uid): array
{
    return array_map(fn($n) => ['who' => user_name($n['user_id']), 'at' => fmt_date($n['created_at'], true), 'note' => (string)$n['note'], 'mine' => (int)$n['user_id'] === (int)current_user()['id']],
        q("SELECT * FROM task_notes WHERE task_id = ? AND block_uid = ? AND note IS NOT NULL ORDER BY id", [$tid, $uid])->fetchAll());
}

function comment_counts(int $tid): array
{
    return array_map('intval', q("SELECT block_uid, COUNT(*) FROM task_notes WHERE task_id = ? AND block_uid <> '' GROUP BY block_uid", [$tid])->fetchAll(PDO::FETCH_KEY_PAIR));
}

// ------------------------------------------------------------------ connect search (orders / other pages)
if (isset($_GET['find'])) {
    $s = trim((string)$_GET['find']);
    $out = [];
    if (can_view('customer_id') || can_view('customer_name')) {
        $num = (int)preg_replace('/\D/', '', $s);
        $rows = $s === '' ? q('SELECT id, customer_name, ship_name FROM orders WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 6')->fetchAll()
            : q('SELECT id, customer_name, ship_name FROM orders WHERE deleted_at IS NULL AND (id = ? OR customer_name LIKE ? OR ship_name LIKE ? OR customer_id LIKE ?) ORDER BY id DESC LIMIT 8',
                [$num, "%$s%", "%$s%", "%$s%"])->fetchAll();
        foreach ($rows as $o) {
            $out[] = ['k' => 'order', 'id' => (int)$o['id'], 'label' => order_no($o['id']) . (($o['customer_name'] ?: $o['ship_name']) !== '' ? ' · ' . ($o['customer_name'] ?: $o['ship_name']) : '')];
        }
    }
    $where = task_privacy_sql() . ($assign ? '' : " AND (assigned_to = $myId OR created_by = $myId)");
    foreach (q("SELECT * FROM tasks WHERE $where AND id <> ? AND title LIKE ? ORDER BY updated_at DESC, id DESC LIMIT 8", [$id, "%$s%"])->fetchAll() as $t) {
        $out[] = ['k' => 'task', 'id' => (int)$t['id'], 'label' => task_icon($t) . ' ' . $t['title']];
    }
    json_reply(['results' => $out]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $ajax = !empty($_POST['ajax']);
    if ($do === 'create' && !$assign && !task_page_cap()) {
        http_response_code(403);
        exit('Only the admin gives you tasks.');
    }
    if ($do === 'create') {
        $row = task_from_post($assign, $myId);
        if ($row['title'] === '' || !$row['assigned_to']) {
            flash($row['title'] === '' ? 'Give the task a title.' : 'Pick who the task is for.', 'err');
            redirect(tasks_path() . '?new=1');
        }
        $row += ['created_by' => $myId, 'status' => 'todo', 'body_rev' => 1, 'created_at' => now(), 'updated_at' => now()];
        q('INSERT INTO tasks (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
        $newId = (int)db()->lastInsertId();
        task_note($newId, '', 'todo');
        // Page content: an earlier task's page (Give again / quick add), else the details typed and the template.
        $src = !empty($_POST['copy_from']) ? get_task((int)$_POST['copy_from']) : null;
        if ($src && task_visible($src) && q('SELECT 1 FROM task_blocks WHERE task_id = ? LIMIT 1', [$src['id']])->fetchColumn()) {
            copy_task_blocks((int)$src['id'], $newId);
            q('UPDATE tasks SET icon = ? WHERE id = ?', [$src['icon'] ?? '', $newId]);
        } else {
            $tpl = PAGE_TEMPLATES[(string)($_POST['template'] ?? '')] ?? PAGE_TEMPLATES[''];
            insert_blocks($newId, array_merge(details_blocks($row['details']), array_map(fn($b) => ['type' => $b[0], 'html' => h($b[1])], $tpl['blocks'])));
        }
        // The same task for more people (each gets their own copy to work on and their own points).
        $more = array_diff(array_unique(array_map('intval', (array)($_POST['also_to'] ?? []))), [(int)$row['assigned_to']]);
        $more = $assign ? array_values(array_filter($more, fn($u) => isset(task_staff()[$u]))) : [];
        foreach ($more as $u) {
            $copy = array_merge($row, ['assigned_to' => $u]);
            q('INSERT INTO tasks (' . implode(',', array_keys($copy)) . ') VALUES (' . rtrim(str_repeat('?,', count($copy)), ',') . ')', array_values($copy));
            $cid = (int)db()->lastInsertId();
            task_note($cid, '', 'todo');
            copy_task_blocks($newId, $cid);
            q('UPDATE tasks SET icon = (SELECT icon FROM (SELECT icon FROM tasks WHERE id = ?) x) WHERE id = ?', [$newId, $cid]);
        }
        flash('Task given to ' . implode(', ', array_map('user_name', array_merge([$row['assigned_to']], $more))) . '.');
        redirect(tasks_path() . (!empty($_POST['another']) ? '?new=1&to=' . $row['assigned_to'] . '&kind=' . $row['kind'] : '?id=' . $newId));
    }
    $t = get_task((int)($_POST['task'] ?? 0));
    if (!$t || !task_editable($t)) {
        if ($ajax) {
            json_reply(['error' => 'Not allowed.'], 403);
        }
        http_response_code(403);
        exit('Not allowed.');
    }
    $tid = (int)$t['id'];
    $canEditFields = $assign || (int)$t['created_by'] === $myId;
    if ($do === 'blocks') {
        $d = json_decode((string)($_POST['data'] ?? ''), true);
        if (!is_array($d)) {
            json_reply(['error' => 'Bad data.'], 400);
        }
        $res = save_task_blocks($tid, (array)($d['changed'] ?? []), (array)($d['deleted'] ?? []), (array)($d['order'] ?? []), (int)($_POST['rev'] ?? 0));
        if ($res['others']) {
            $blocks = task_blocks(get_task($tid));
            $res += ['blocks' => $blocks, 'conns' => block_connections($blocks)];
        }
        json_reply(['ok' => true] + $res);
    }
    if ($do === 'upload') {
        [$file, $err] = store_upload($_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        if (!$file) {
            json_reply(['error' => $err ?: 'No image.'], 400);
        }
        q('INSERT INTO task_files (task_id, filename, original_name, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?)',
            [$tid, $file, mb_substr((string)$_FILES['image']['name'], 0, 250), $myId, now()]);
        json_reply(['file' => $file]);
    }
    if ($do === 'comment') {
        $uid = (string)($_POST['block'] ?? '');
        $note = trim((string)($_POST['note'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{4,24}$/', $uid) && $note !== '') {
            q('INSERT INTO task_notes (task_id, block_uid, user_id, note, status, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$tid, $uid, $myId, mb_substr($note, 0, 4000), '', now()]);
        }
        json_reply(['comments' => block_comments($tid, $uid)]);
    }
    if ($do === 'prop') {
        $k = (string)($_POST['key'] ?? '');
        $v = trim((string)($_POST['value'] ?? ''));
        $set = [];
        $x = $t['extra'];
        if ($k === 'result_link') {
            $set['result_link'] = mb_substr($v, 0, 500);
            if ($v !== $t['result_link'] && $v !== '') {
                task_note($tid, 'Result link: ' . $v);
            }
        } elseif ($canEditFields) {
            switch ($k) {
                case 'title':
                    $v !== '' && $set['title'] = mb_substr($v, 0, 200);
                    break;
                case 'icon':
                    $set['icon'] = preg_match('/^\X$/u', $v) ? $v : '';
                    break;
                case 'assigned_to':
                    if ($assign && isset(task_staff()[(int)$v]) && (int)$v !== (int)$t['assigned_to']) {
                        $set['assigned_to'] = (int)$v;
                        task_note($tid, 'Given to ' . user_name((int)$v) . ' (was ' . user_name($t['assigned_to']) . ')');
                    }
                    break;
                case 'due_date':
                    $set['due_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
                    break;
                case 'priority':
                    isset(TASK_PRIORITY[$v]) && $set['priority'] = $v;
                    break;
                case 'kind':
                    isset(TASK_KINDS[$v]) && $set['kind'] = $v;
                    break;
                case 'brief_link':
                    $set['brief_link'] = mb_substr($v, 0, 500);
                    break;
                case 'needs_review':
                    $assign && $set['needs_review'] = $v === '1' ? 1 : 0;
                    break;
                case 'platform':
                case 'length':
                case 'footage':
                    $x[$k] = $k === 'platform' ? (in_array($v, VIDEO_PLATFORMS, true) ? $v : '') : mb_substr($v, 0, $k === 'length' ? 40 : 500);
                    $set['extra'] = json_encode($x, JSON_UNESCAPED_UNICODE);
                    break;
            }
        }
        if (!$set) {
            json_reply(['error' => 'Not allowed.'], 403);
        }
        q('UPDATE tasks SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . ', updated_at = ? WHERE id = ?', array_merge(array_values($set), [now(), $tid]));
        json_reply(['ok' => true]);
    }
    if ($do === 'move') {
        [$st, $msg] = move_task($t, (string)($_POST['status'] ?? ''), '');
        json_reply(['status' => $st, 'message' => $msg]);
    }
    if ($do === 'status') {
        [, $msg] = move_task($t, (string)($_POST['status'] ?? ''), trim((string)($_POST['note'] ?? '')), ($_POST['approve'] ?? '') === 'back');
        if (isset($_POST['result_link'])) {
            q('UPDATE tasks SET result_link = ? WHERE id = ?', [mb_substr(trim((string)$_POST['result_link']), 0, 500), $tid]);
        }
        flash($msg ?: 'Task updated.');
        if (!empty($_POST['back'])) {
            redirect(tasks_path());
        }
    } elseif ($do === 'note') {
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note !== '') {
            task_note($tid, $note);
        }
    } elseif ($do === 'edit' && $canEditFields) {
        $row = task_from_post($assign, $myId);
        unset($row['details']);
        if (!$assign || !$row['assigned_to']) {
            $row['assigned_to'] = (int)$t['assigned_to'];
        }
        if ($row['title'] !== '') {
            q('UPDATE tasks SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($row))) . ', updated_at = ? WHERE id = ?', array_merge(array_values($row), [now(), $tid]));
        }
        flash('Task saved.');
    } elseif ($do === 'delete' && $canEditFields) {
        delete_task($tid);
        flash('Task deleted.');
        redirect(tasks_path());
    }
    redirect(tasks_path() . '?id=' . $tid);
}

// ------------------------------------------------------------------ block comments (for the side panel)
if ($id && isset($_GET['comments'])) {
    $t = get_task($id);
    if (!$t || !task_visible($t)) {
        json_reply(['error' => 'Not found.'], 404);
    }
    json_reply(['comments' => block_comments($id, (string)$_GET['comments'])]);
}

$staff = staff_list();
$pageTitle = 'Tasks';
$active = 'tasks';

// ------------------------------------------------------------------ one task = one page
if ($id) {
    $t = get_task($id);
    if (!$t || !task_visible($t)) {
        http_response_code(404);
        exit('Task not found. <a href="' . h(tasks_url()) . '">Back to tasks</a>');
    }
    $blocks = task_blocks($t);
    $t = get_task($id); // body_rev may have changed
    if (isset($_GET['pull'])) {
        json_reply(['rev' => (int)$t['body_rev'], 'blocks' => $blocks, 'conns' => block_connections($blocks), 'comments' => comment_counts($id)]);
    }
    $notes = q("SELECT * FROM task_notes WHERE task_id = ? AND block_uid = '' ORDER BY id", [$id])->fetchAll();
    $blockNotes = q("SELECT * FROM task_notes WHERE task_id = ? AND block_uid <> '' ORDER BY id DESC LIMIT 30", [$id])->fetchAll();
    $canEditFields = $assign || (int)$t['created_by'] === $myId;
    $late = task_late($t);
    $x = $t['extra'];
    $pageTitle = $t['title'];
    $taskStaff = task_staff();
    $data = ['task' => $id, 'rev' => (int)$t['body_rev'], 'blocks' => $blocks, 'conns' => block_connections($blocks), 'comments' => comment_counts($id),
        'video' => $t['kind'] === 'video', 'canFind' => true, 'names' => user_names(), 'me' => $myId];
    $taskApp = true;
    require __DIR__ . '/inc/header.php';
    $prop = function (string $label, string $html, string $icon = '') { return '<div class="np-prop"><span class="np-pk">' . $icon . ' ' . h($label) . '</span><div class="np-pv">' . $html . '</div></div>'; };
    $sel = fn(string $key, array $opts, string $cur) => '<select data-prop="' . $key . '">' . implode('', array_map(fn($k, $l) => '<option value="' . h($k) . '"' . ((string)$k === $cur ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($opts), $opts)) . '</select>';
    $statusCls = ['todo' => 'pending', 'doing' => 'printing', 'review' => 'packed', 'done' => 'shipped'][$t['status']];
    ?>
<div class="np" id="np" data-csrf="<?= h(csrf_token()) ?>">
  <div class="np-top">
    <a class="back" href="<?= h(tasks_url()) ?>">← Tasks</a>
    <span class="np-crumb"><?= h(task_icon($t)) ?> <?= h(mb_strimwidth($t['title'], 0, 40, '…')) ?></span>
    <span class="np-save" id="npSave" aria-live="polite"></span>
    <span class="np-top-actions">
      <a class="btn small" href="<?= h(tasks_url('new=1&from=' . $id)) ?>">↻ Give again</a>
      <details class="np-more"><summary class="btn small" aria-label="More">⋯</summary>
        <div class="menu">
          <button type="button" data-np="copylink">🔗 Copy link</button>
          <button type="button" data-np="print">🖨 Print / PDF</button>
          <?php if ($canEditFields): ?>
            <form method="post" onsubmit="return window.confirm('Delete this task and its page?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="task" value="<?= $id ?>"><button class="danger-text">🗑 Delete task</button></form>
          <?php endif; ?>
        </div>
      </details>
    </span>
  </div>

  <article class="np-page">
    <button type="button" class="np-icon" <?= $canEditFields ? 'data-np="icon"' : 'disabled' ?> title="Change icon"><?= h(task_icon($t)) ?></button>
    <h1 class="np-title" <?= $canEditFields ? 'contenteditable="plaintext-only" data-prop-title spellcheck="true"' : '' ?>><?= h($t['title']) ?></h1>

    <div class="np-props">
      <?= $prop('Status', '<span class="badge ' . $statusCls . '">' . h(TASK_STATUS[$t['status']]) . '</span>' . ($late ? ' <span class="badge delayed">⚠ Late</span>' : ''), '◉') ?>
      <?= $prop('Assignee', $canEditFields && $assign ? $sel('assigned_to', array_map(fn($u) => $u['name'] ?: $u['username'], $taskStaff + [(int)$t['assigned_to'] => ['name' => user_name($t['assigned_to']), 'username' => '']]), (string)$t['assigned_to']) : '<b>' . h(user_name($t['assigned_to'])) . '</b>', '👤') ?>
      <?= $prop('Due', $canEditFields ? '<input type="date" data-prop="due_date" value="' . h((string)$t['due_date']) . '" class="' . ($late ? 'err-text' : '') . '">' : h($t['due_date'] ? fmt_date($t['due_date']) : '—'), '📅') ?>
      <?= $prop('Priority', $canEditFields ? $sel('priority', TASK_PRIORITY, $t['priority']) : h(TASK_PRIORITY[$t['priority']] ?? ''), '⚑') ?>
      <?= $prop('Type', $canEditFields ? $sel('kind', TASK_KINDS, $t['kind']) : h(TASK_KINDS[$t['kind']] ?? ''), '▤') ?>
      <?php if ($t['kind'] === 'video'): ?>
        <?= $prop('Platform', $canEditFields ? $sel('platform', ['' => '—'] + array_combine(VIDEO_PLATFORMS, VIDEO_PLATFORMS), (string)($x['platform'] ?? '')) : h(($x['platform'] ?? '') ?: '—'), '▶') ?>
        <?= $prop('Length', $canEditFields ? '<input data-prop="length" value="' . h($x['length'] ?? '') . '" placeholder="e.g. 30 sec">' : h(($x['length'] ?? '') ?: '—'), '⏱') ?>
        <?= $prop('Raw footage', $canEditFields ? '<input type="url" data-prop="footage" value="' . h($x['footage'] ?? '') . '" placeholder="Drive / folder link">' . (($x['footage'] ?? '') !== '' ? ' <a href="' . h($x['footage']) . '" target="_blank" rel="noopener">Open ↗</a>' : '')
            : (($x['footage'] ?? '') !== '' ? '<a href="' . h($x['footage']) . '" target="_blank" rel="noopener">Open footage ↗</a>' : '—'), '🎞') ?>
      <?php endif; ?>
      <?= $prop('Brief / reference', $canEditFields ? '<input type="url" data-prop="brief_link" value="' . h($t['brief_link']) . '" placeholder="https://">' . ($t['brief_link'] !== '' ? ' <a href="' . h($t['brief_link']) . '" target="_blank" rel="noopener">Open ↗</a>' : '')
          : ($t['brief_link'] !== '' ? '<a href="' . h($t['brief_link']) . '" target="_blank" rel="noopener">Open ↗</a>' : '—'), '📎') ?>
      <?= $prop('Result', '<input type="url" data-prop="result_link" value="' . h($t['result_link']) . '" placeholder="Final video / post link">' . ($t['result_link'] !== '' ? ' <a href="' . h($t['result_link']) . '" target="_blank" rel="noopener">Open ↗</a>' : ''), '🔗') ?>
      <?php if ($assign && $canEditFields): ?>
        <?= $prop('Approval', '<label class="check"><input type="checkbox" data-prop="needs_review" ' . (!empty($t['needs_review']) ? 'checked' : '') . '> Needs my approval before Done</label>', '👀') ?>
      <?php endif; ?>
      <?= $prop('Given by', h(user_name($t['created_by'])) . ' · <span class="muted">' . h(fmt_date($t['created_at'], true)) . '</span>', '✎') ?>
    </div>

    <form method="post" class="task-status-form np-status">
      <?= csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="task" value="<?= $id ?>">
      <?php $approve = $t['status'] === 'review' && !empty($t['needs_review']) && task_approver($t); $waiting = $t['status'] === 'review' && task_needs_approval($t); ?>
      <?php if ($approve): ?>
        <p class="approve-note">👀 <b><?= h(user_name($t['assigned_to'])) ?></b> sent this for your approval.</p>
      <?php elseif ($waiting): ?>
        <p class="approve-note">⏳ Waiting for <?= h(user_name($t['created_by'])) ?> to approve. You can still change the page and add comments.</p>
      <?php endif; ?>
      <div class="np-status-row">
        <input name="note" placeholder="<?= $approve ? 'What needs to change? (for sending back)' : 'Note with the update (optional)' ?>" aria-label="Note">
        <div class="status-btns">
          <?php if ($approve): ?>
            <button class="btn primary" name="status" value="done">✓ Approve — done</button>
            <button class="btn" name="approve" value="back" onclick="if (!this.form.note.value.trim()) { alert('Write what needs to change in the note.'); this.form.note.focus(); return false; }">↩ Send back with note</button>
          <?php elseif (!$waiting): ?>
            <?php foreach (TASK_STATUS as $sk => $sl): if ($sk === $t['status'] || ($sk === 'review' && task_needs_approval($t))) continue; ?>
              <button class="btn small <?= $sk === 'done' ? 'primary' : '' ?>" name="status" value="<?= $sk ?>"><?= ['todo' => '↺ ', 'doing' => '▶ ', 'review' => '👀 ', 'done' => '✓ '][$sk] . h($sk === 'done' ? (task_needs_approval($t) ? 'Done — send for approval' : 'Mark as done') : $sl) ?></button>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </form>

    <div class="np-stats" id="npStats"></div>
    <div class="np-editor" id="npEditor" aria-label="Page content"></div>
    <button type="button" class="np-add-end" id="npAddEnd">＋ Add a block <span class="muted">— or type <kbd>/</kbd> in any line</span></button>
    <script type="application/json" id="npData"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <section class="np-activity">
      <h2>Comments &amp; activity</h2>
      <ul class="history task-notes">
        <?php foreach ($notes as $n): ?>
          <li><span class="muted"><?= h(fmt_date($n['created_at'], true)) ?></span> · <b><?= h(user_name($n['user_id'])) ?></b>
            <?php if ($n['status'] !== ''): ?> <span class="badge pending"><?= h($n['status'] === 'todo' && $n === $notes[0] ? 'Task given' : '→ ' . (TASK_STATUS[$n['status']] ?? $n['status'])) ?></span><?php endif; ?>
            <?php if ($n['note'] !== null && $n['note'] !== ''): ?><div><?= nl2br(h($n['note'])) ?></div><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
      <form method="post" class="np-comment-form">
        <?= csrf_field() ?><input type="hidden" name="do" value="note"><input type="hidden" name="task" value="<?= $id ?>">
        <textarea name="note" rows="2" placeholder="Comment on the whole page — progress, question, feedback…"></textarea>
        <button class="btn">💬 Comment</button>
      </form>
      <?php if ($blockNotes): ?>
        <h3 class="perm-group">On parts of the page</h3>
        <ul class="history task-notes">
          <?php foreach ($blockNotes as $n): ?>
            <li><span class="muted"><?= h(fmt_date($n['created_at'], true)) ?></span> · <b><?= h(user_name($n['user_id'])) ?></b>
              <a href="#" data-goto-block="<?= h($n['block_uid']) ?>">on a line ↗</a><div><?= nl2br(h((string)$n['note'])) ?></div></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($t['started_at'] || $t['done_at']): ?>
        <p class="muted small">Started <?= h($t['started_at'] ? fmt_date($t['started_at'], true) : '—') ?><?= $t['done_at'] ? ' · done ' . h(fmt_date($t['done_at'], true)) . ' (' . h(duration_text(strtotime($t['done_at']) - strtotime($t['created_at']))) . ' after it was given)' : '' ?></p>
      <?php endif; ?>
    </section>
  </article>
</div>
<script src="<?= h(asset('assets/tasks.js')) ?>" defer></script>
<?php
    require __DIR__ . '/inc/footer.php';
    exit;
}

/** New task form. */
function task_form(array $staff, bool $assign): void
{
    $t = ['title' => '', 'details' => '', 'kind' => $_GET['kind'] ?? '', 'assigned_to' => (int)($_GET['to'] ?? 0), 'priority' => 'normal',
        'due_date' => '', 'brief_link' => '', 'extra' => [], 'id' => 0];
    $copyFrom = 0;
    // "Give again": start from an earlier task (not its dates, status or result); its page is copied too.
    if (!empty($_GET['from']) && ($src = get_task((int)$_GET['from'])) && task_visible($src)) {
        $t = array_merge($t, ['title' => $src['title'], 'details' => '', 'kind' => $src['kind'], 'priority' => $src['priority'],
            'brief_link' => $src['brief_link'], 'extra' => array_intersect_key($src['extra'], ['platform' => 1, 'length' => 1]),
            'assigned_to' => $assign ? (int)$src['assigned_to'] : (int)current_user()['id']]);
        $copyFrom = (int)$src['id'];
    }
    $templates = task_templates();
    $x = $t['extra'];
    if (!isset(TASK_KINDS[$t['kind']])) {
        $who = $staff[(int)$t['assigned_to']] ?? current_user();
        $t['kind'] = kind_for_job(user_job($who));
    }
    ?>
    <?php if ($templates): ?>
      <div class="quick-tasks">
        <span class="lbl">⚡ Quick add — tap an earlier task (its page comes too)</span>
        <div class="chips">
          <?php foreach (array_slice($templates, 0, 14) as $i => $tp): ?>
            <button type="button" class="chip" data-tpl="<?= $i ?>" title="<?= h(TASK_KINDS[$tp['kind']] ?? '') ?><?= $tp['n'] > 1 ? ' · given ' . $tp['n'] . ' times' : '' ?>"><?= h(mb_strimwidth($tp['title'], 0, 48, '…')) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <datalist id="taskTitles"><?php foreach ($templates as $tp): ?><option value="<?= h($tp['title']) ?>"><?php endforeach; ?></datalist>
    <?php endif; ?>
    <form method="post" action="<?= h(tasks_url()) ?>" class="grid task-form" style="margin-top:12px" data-templates="<?= h(json_encode($templates, JSON_UNESCAPED_UNICODE)) ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="create"><input type="hidden" name="copy_from" value="<?= $copyFrom ?: '' ?>">
      <label class="field full"><span class="lbl">Task <i class="req">*</i></span><input name="title" value="<?= h($t['title']) ?>" required maxlength="200" placeholder="Type, or pick an earlier task" <?= $templates ? 'list="taskTitles" data-tpl-title' : '' ?> autocomplete="off"></label>
      <?php if ($assign): ?>
        <label class="field"><span class="lbl">Give to <i class="req">*</i></span><select name="assigned_to" data-task-to required>
          <option value="">— Pick a person —</option>
          <?php foreach ($staff as $uid => $u): ?><option value="<?= $uid ?>" data-kind="<?= h(kind_for_job(user_job($u))) ?>" <?= (int)$t['assigned_to'] === $uid ? 'selected' : '' ?>><?= h(($u['name'] ?: $u['username']) . ' · ' . ($u['role'] === 'admin' ? 'Admin' : (($u['job_title'] ?? '') ?: JOBS[user_job($u)]))) ?></option><?php endforeach; ?>
        </select></label>
        <details class="field full also-to">
          <summary class="muted small">＋ Give the same task to more people <span data-also-n></span></summary>
          <div class="chips">
            <?php foreach ($staff as $uid => $u): ?><label class="chip"><input type="checkbox" name="also_to[]" value="<?= $uid ?>"> <?= h($u['name'] ?: $u['username']) ?></label><?php endforeach; ?>
          </div>
          <p class="hint">Each person gets their own copy of the task and page, and their own points when they finish it.</p>
        </details>
      <?php endif; ?>
      <label class="field"><span class="lbl">Type</span><select name="kind" data-task-kind>
        <?php foreach (TASK_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $t['kind'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
      </select></label>
      <label class="field"><span class="lbl">Page template</span><select name="template" data-task-template <?= $copyFrom ? 'disabled title="The earlier page is copied"' : '' ?>>
        <?php foreach (PAGE_TEMPLATES as $k => $tp): ?><option value="<?= h($k) ?>" <?= $k === ($t['kind'] === 'general' ? '' : $t['kind']) ? 'selected' : '' ?>><?= h($tp['label']) ?></option><?php endforeach; ?>
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
      <label class="field full"><span class="lbl">First lines <small class="muted">(optional — write the full script / brief on the page after)</small></span><textarea name="details" rows="3" placeholder="What exactly to do, style, captions, references…"><?= h($t['details']) ?></textarea></label>
      <?php if ($assign): ?>
        <label class="field check full"><input type="checkbox" name="needs_review" value="1" <?= is_admin() || ((int)$t['assigned_to'] && (int)$t['assigned_to'] !== (int)current_user()['id']) ? 'checked' : '' ?>> 👀 Needs my approval — the task comes back to me before it is Done</label>
      <?php endif; ?>
      <label class="field full"><span class="lbl">Brief / reference link</span><input type="url" name="brief_link" value="<?= h($t['brief_link']) ?>" placeholder="https://"></label>
      <div class="field full"><div class="status-btns">
        <button class="btn primary">＋ <?= $assign ? 'Give task' : 'Add task' ?> &amp; open page</button>
        <button class="btn" name="another" value="1">＋ Add &amp; add another</button>
      </div></div>
    </form>
    <script>
    (function () {
      var f = document.currentScript.previousElementSibling, k = f.querySelector('[data-task-kind]'), to = f.querySelector('[data-task-to]'), tpl = f.querySelector('[data-task-template]');
      var rev = f.querySelector('[name=needs_review]'), me = '<?= (int)current_user()['id'] ?>';
      function sync() { f.querySelector('[data-video-fields]').hidden = k.value !== 'video'; if (tpl && !tpl.dataset.picked && !tpl.disabled) tpl.value = tpl.querySelector('option[value="' + k.value + '"]') ? k.value : ''; }
      if (tpl) tpl.addEventListener('change', function () { tpl.dataset.picked = '1'; });
      // Quick add: fill the form from an earlier task (chip or a title picked from the list); its page is copied.
      var tpls = JSON.parse(f.dataset.templates || '[]');
      function use(tp, focusDue) {
        var set = function (n, v) { var el = f.querySelector('[name="' + n + '"]'); if (el && v !== undefined && v !== null) el.value = v; };
        set('title', tp.title); set('details', tp.details); set('priority', tp.priority); set('brief_link', tp.brief_link);
        set('kind', tp.kind); set('platform', tp.platform); set('length', tp.length); set('copy_from', tp.id);
        if (to && tp.to && to.querySelector('option[value="' + tp.to + '"]') && !to.dataset.picked) { to.value = tp.to; if (rev) rev.checked = to.value !== me; }
        sync();
        var due = f.querySelector('[name=due_date]'); if (focusDue && due && !due.value) due.focus();
      }
      if (to) to.addEventListener('change', function () { to.dataset.picked = '1'; if (rev) rev.checked = !!to.value && to.value !== me; });
      var box = f.previousElementSibling && f.previousElementSibling.previousElementSibling;
      if (box && box.classList.contains('quick-tasks')) box.addEventListener('click', function (e) {
        var c = e.target.closest('[data-tpl]'); if (!c) return;
        use(tpls[+c.dataset.tpl], true);
        box.querySelectorAll('.chip').forEach(function (x) { x.classList.toggle('on', x === c); });
      });
      var ti = f.querySelector('[data-tpl-title]');
      // A title picked from the suggestions fills the rest once (typing your own title changes nothing).
      if (ti) ti.addEventListener('input', function (e) {
        if (e.inputType && e.inputType !== 'insertReplacementText') return;
        var tp = tpls.find(function (x) { return x.title === ti.value; }); if (tp) use(tp, false);
      });
      k.addEventListener('change', sync);
      if (to) to.addEventListener('change', function () { if (to.value) { k.value = to.selectedOptions[0].dataset.kind || k.value; sync(); } });
      sync();
    })();
    </script>
    <?php
}

function duration_text(int $sec): string
{
    $h = $sec / 3600;
    return $h < 1 ? max(1, (int)round($sec / 60)) . ' min' : ($h < 48 ? round($h, 1) . ' h' : round($h / 24, 1) . ' days');
}

// ------------------------------------------------------------------ Production team: their tasks, done work and points
if (!$assign && !task_page_cap()) {
    $mineOpen = q("SELECT * FROM tasks WHERE assigned_to = ? AND status <> 'done' ORDER BY FIELD(status, 'review', 'doing', 'todo'), FIELD(priority, 'urgent', 'high', 'normal', 'low'), due_date IS NULL, due_date, id", [$myId])->fetchAll();
    $mineDone = q("SELECT * FROM tasks WHERE assigned_to = ? AND status = 'done' ORDER BY done_at DESC LIMIT 30", [$myId])->fetchAll();
    $month = work_points(staff_performance(date('Y-m-01'), today())[$myId] ?? []);
    $today = work_points(staff_performance(today(), today())[$myId] ?? []);
    $rank = my_month_score();
    $doneMonth = count(array_filter($mineDone, fn($t) => $t['done_at'] >= date('Y-m-01')));
    $taskApp = true;
    require __DIR__ . '/inc/header.php';
    ?>
<div class="page-head">
  <div><h1>Task Manager</h1><p class="muted small">Production team · <?= h($me['name'] ?: $me['username']) ?></p></div>
  <div class="actions"><a class="btn" href="performance.php">📈 Full performance</a></div>
</div>

<div class="stats">
  <div class="stat"><span class="stat-label">⭐ Points this month</span><span class="stat-num"><?= $month['total'] ?></span><span class="stat-sub"><?= $rank['of'] > 1 ? '#' . $rank['rank'] . ' of ' . $rank['of'] . ' in the team' : '' ?></span></div>
  <div class="stat"><span class="stat-label">Points today</span><span class="stat-num"><?= $today['total'] ?></span><span class="stat-sub">from your work today</span></div>
  <div class="stat <?= count(array_filter($mineOpen, 'task_late')) ? 'stat-alert' : '' ?>"><span class="stat-label">Open tasks</span><span class="stat-num"><?= count($mineOpen) ?></span><span class="stat-sub"><?= count(array_filter($mineOpen, 'task_late')) ? count(array_filter($mineOpen, 'task_late')) . ' late' : 'from the admin' ?></span></div>
  <div class="stat"><span class="stat-label">Tasks done</span><span class="stat-num"><?= $doneMonth ?></span><span class="stat-sub">this month</span></div>
</div>

<section class="panel pt-tasks">
  <h2>📌 Your tasks <small class="muted">given by the admin</small></h2>
  <?php if (!$mineOpen): ?><p class="muted">No open tasks. 🎉 New tasks from the admin show up here.</p><?php endif; ?>
  <?php foreach ($mineOpen as $t): $lt = task_late($t); ?>
    <div class="pt-row <?= $lt ? 'is-late' : '' ?>">
      <a class="pt-main" href="<?= h(tasks_url('id=' . (int)$t['id'])) ?>">
        <b><?= h(task_icon($t)) ?> <?= h($t['title']) ?></b>
        <span class="muted small"><span class="badge <?= ['todo' => 'pending', 'doing' => 'printing', 'review' => 'packed'][$t['status']] ?>"><?= $t['status'] === 'todo' ? 'New' : h(TASK_STATUS[$t['status']]) ?></span>
          <?= $t['priority'] !== 'normal' ? ' · <b class="' . (in_array($t['priority'], ['high', 'urgent'], true) ? 'err-text' : '') . '">' . h(TASK_PRIORITY[$t['priority']]) . '</b>' : '' ?>
          <?= $t['due_date'] ? ' · <span class="' . ($lt ? 'err-text' : '') . '">' . ($lt ? '⚠ was due ' : 'due ') . h(fmt_date($t['due_date'])) . '</span>' : '' ?>
          · from <?= h(user_name($t['created_by'])) ?></span>
      </a>
      <form method="post" class="pt-act"><?= csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="task" value="<?= (int)$t['id'] ?>"><input type="hidden" name="back" value="1">
        <?php if ($t['status'] === 'review'): ?>
          <span class="muted small">⏳ Waiting for approval</span>
        <?php else: ?>
          <?php if ($t['status'] === 'todo'): ?><button class="btn small" name="status" value="doing">▶ Start</button><?php endif; ?>
          <button class="btn small primary" name="status" value="done">✓ Complete</button>
        <?php endif; ?>
      </form>
    </div>
  <?php endforeach; ?>
</section>

<div class="two-col">
  <section class="panel">
    <h2>⚙ Your work this month <small class="muted">counted automatically</small></h2>
    <div class="table-wrap"><table class="table compact">
      <thead><tr><th>Work</th><th class="num">Today</th><th class="num">Month</th><th class="num">Points</th></tr></thead>
      <tbody>
      <?php foreach ($month['lines'] as $k => $ln): ?>
        <tr class="<?= $ln['count'] ? '' : 'muted' ?>"><td><?= h($ln['label']) ?><?= $ln['late'] ? ' <small class="err-text">' . $ln['late'] . ' late</small>' : '' ?></td>
          <td class="num"><?= $today['lines'][$k]['count'] ?: '·' ?></td><td class="num"><?= $ln['count'] ?: '·' ?></td><td class="num"><b><?= round($ln['points'], 1) ?></b></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td><b>Total</b></td><td class="num"><b><?= $today['total'] ?></b></td><td></td><td class="num"><b><?= $month['total'] ?></b></td></tr></tfoot>
    </table></div>
    <p class="hint">Orders you create, print-process, print and pack are counted from the orders app as you mark them — nothing to enter here.</p>
  </section>
  <section class="panel">
    <h2>✅ Done tasks <small class="muted">latest 30</small></h2>
    <?php if (!$mineDone): ?><p class="muted">No finished tasks yet.</p><?php else: ?>
      <ul class="pt-done">
        <?php foreach ($mineDone as $t): ?>
          <li><a href="<?= h(tasks_url('id=' . (int)$t['id'])) ?>"><?= h(task_icon($t)) ?> <?= h($t['title']) ?></a>
            <span class="muted small">✓ <?= h(fmt_date($t['done_at'])) ?><?= task_late($t) ? ' <span class="err-text">(late)</span>' : '' ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
<?php
    require __DIR__ . '/inc/footer.php';
    exit;
}

// ------------------------------------------------------------------ workspace: board / table / calendar
$view = in_array($_GET['view'] ?? '', ['board', 'table', 'calendar'], true) ? $_GET['view'] : 'board';
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
    $where[] = '(title LIKE ? OR details LIKE ? OR id IN (SELECT task_id FROM task_blocks WHERE html LIKE ?))';
    array_push($args, "%$s%", "%$s%", '%' . h($s) . '%');
}
$w = implode(' AND ', $where);
$open = q("SELECT * FROM tasks WHERE $w AND status <> 'done' ORDER BY FIELD(priority, 'urgent', 'high', 'normal', 'low'), due_date IS NULL, due_date, id", $args)->fetchAll();
$done = q("SELECT * FROM tasks WHERE $w AND status = 'done' AND done_at >= ? ORDER BY done_at DESC LIMIT 60", array_merge($args, [date('Y-m-d', strtotime('-30 days'))]))->fetchAll();
$cols = ['todo' => [], 'doing' => [], 'review' => [], 'done' => $done];
foreach ($open as $t) {
    $cols[$t['status']][] = $t;
}
$progress = task_progress(array_column(array_merge($open, $done), 'id'));
$overdue = count(array_filter($open, 'task_late'));
$showWho = $who !== 'me';
$qs = fn(array $over) => tasks_url(http_build_query(array_filter(array_merge(['view' => $view, 'who' => $who, 'kind' => $kind, 'q' => $s], $over), fn($v) => $v !== '' && $v !== null && $v !== 'board')));
$taskApp = true;
    require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <div><h1>Task Manager</h1>
    <p class="muted small"><?= h(['board' => 'Board', 'table' => 'Table', 'calendar' => 'Calendar'][$view]) ?><?= $kind === 'video' ? ' · 🎬 video editing' : '' ?> · <?= count($open) ?> open<?= $overdue ? ' · <b class="err-text">' . $overdue . ' late</b>' : '' ?> · <?= count($done) ?> done in the last 30 days</p></div>
  <div class="actions"><?php if ($assign || task_page_cap()): ?><a class="btn primary" href="<?= h(tasks_url('new=1' . ($kind ? '&kind=' . $kind : '') . (ctype_digit($who) ? '&to=' . $who : ''))) ?>">＋ <?= $assign ? 'New task' : 'Add my task' ?></a><?php endif; ?>
</div>
</div>

<?php if (!empty($_GET['new']) && ($assign || task_page_cap())): ?>
<section class="panel">
  <h2><?= $assign ? 'New task' : 'Add a task for yourself' ?> <small class="muted">— it opens as a page where you write the script / brief</small></h2>
  <?php task_form(task_staff(), $assign); ?>
</section>
<?php endif; ?>

<div class="np-views">
  <form class="filters" method="get" action="<?= h(tasks_url()) ?>">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <?php if ($assign): ?>
      <select name="who" onchange="this.form.submit()">
        <option value="all" <?= $who === 'all' ? 'selected' : '' ?>>Everyone</option>
        <?php if (!is_admin()): ?><option value="me" <?= $who === 'me' ? 'selected' : '' ?>>My tasks</option><?php endif; ?>
        <option value="given" <?= $who === 'given' ? 'selected' : '' ?>>Given by me</option>
        <?php foreach (task_staff() as $uid => $u): ?><option value="<?= $uid ?>" <?= $who === (string)$uid ? 'selected' : '' ?>><?= h($u['name'] ?: $u['username']) ?></option><?php endforeach; ?>
      </select>
    <?php else: ?>
      <span class="seg-links"><a class="<?= $who === 'me' ? 'on' : '' ?>" href="<?= h($qs(['who' => 'me'])) ?>">My tasks</a><a class="<?= $who === 'given' ? 'on' : '' ?>" href="<?= h($qs(['who' => 'given'])) ?>">Added by me</a></span>
    <?php endif; ?>
    <select name="kind" onchange="this.form.submit()">
      <option value="">All types</option>
      <?php foreach (TASK_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= h($s) ?>" placeholder="Search titles & pages">
    <button class="btn">Search</button>
  </form>
</div>

<?php if ($view === 'board'): ?>
<div class="task-board" data-csrf="<?= h(csrf_token()) ?>">
  <?php foreach ($cols as $st => $list): ?>
    <section class="task-col col-<?= $st ?>" data-status="<?= $st ?>">
      <h3><?= h(TASK_STATUS[$st]) ?> <span class="count"><?= count($list) ?></span><?= $st === 'done' ? ' <small class="muted">30 days</small>' : '' ?></h3>
      <?php foreach ($list as $t): ?><?= task_card($t, $showWho, $progress[(int)$t['id']] ?? null) ?><?php endforeach; ?>
      <?php if (!$list): ?><p class="muted small">Nothing here.</p><?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>
<p class="hint">Drag a card to another column to change its status, or open it to write the script.</p>

<?php elseif ($view === 'table'): ?>
<section class="panel">
  <div class="table-wrap"><table class="table compact np-table">
    <thead><tr><th>Task</th><th>Status</th><?= $showWho ? '<th>Assignee</th>' : '' ?><th>Type</th><th>Due</th><th>Priority</th><th>Progress</th><th>Updated</th></tr></thead>
    <tbody>
    <?php foreach (array_merge($open, $done) as $t): $pg = $progress[(int)$t['id']] ?? null; $lt = task_late($t); ?>
      <tr onclick="location=this.querySelector('a').href">
        <td><a href="<?= h(tasks_url('id=' . (int)$t['id'])) ?>"><?= h(task_icon($t)) ?> <b><?= h($t['title']) ?></b></a></td>
        <td><span class="badge <?= ['todo' => 'pending', 'doing' => 'printing', 'review' => 'packed', 'done' => 'shipped'][$t['status']] ?>"><?= h(TASK_STATUS[$t['status']]) ?></span></td>
        <?= $showWho ? '<td>' . h(user_name($t['assigned_to'])) . '</td>' : '' ?>
        <td><?= h(TASK_KINDS[$t['kind']] ?? '') ?></td>
        <td class="nowrap <?= $lt ? 'err-text' : '' ?>"><?= h($t['due_date'] ? fmt_date($t['due_date']) : '—') ?><?= $lt ? ' ⚠' : '' ?></td>
        <td><?= h(TASK_PRIORITY[$t['priority']] ?? '') ?></td>
        <td class="nowrap muted"><?= $pg && $pg['lines'] && $pg['edited'] ? '✎ ' . $pg['edited'] . '/' . $pg['lines'] : ($pg && $pg['todo'] ? '☑ ' . $pg['ticked'] . '/' . $pg['todo'] : '') ?></td>
        <td class="nowrap muted"><?= h(fmt_date($t['updated_at'] ?: $t['created_at'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$open && !$done): ?><tr><td colspan="8" class="muted">No tasks.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<?php else:
    $m = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
    $first = strtotime($m . '-01');
    $cal = q("SELECT * FROM tasks WHERE $w AND due_date BETWEEN ? AND ? ORDER BY FIELD(priority, 'urgent', 'high', 'normal', 'low'), id", array_merge($args, [date('Y-m-01', $first), date('Y-m-t', $first)]))->fetchAll();
    $byDay = [];
    foreach ($cal as $t) {
        $byDay[$t['due_date']][] = $t;
    }
    $start = strtotime('-' . ((int)date('N', $first) - 1) . ' days', $first); ?>
<section class="panel">
  <div class="np-cal-head"><a class="btn small" href="<?= h($qs(['m' => date('Y-m', strtotime('-1 month', $first))])) ?>">‹</a>
    <b><?= h(date('F Y', $first)) ?></b><a class="btn small" href="<?= h($qs(['m' => date('Y-m', strtotime('+1 month', $first))])) ?>">›</a>
    <a class="btn small" href="<?= h($qs(['m' => null])) ?>">Today</a></div>
  <div class="np-cal">
    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dn): ?><div class="np-cal-dn"><?= $dn ?></div><?php endforeach; ?>
    <?php for ($i = 0; $i < 42; $i++): $d = date('Y-m-d', strtotime("+$i days", $start)); if ($i >= 35 && substr($d, 0, 7) !== $m) break; ?>
      <div class="np-cal-day <?= substr($d, 0, 7) !== $m ? 'other' : '' ?> <?= $d === today() ? 'today' : '' ?>">
        <span class="np-cal-n"><?= (int)substr($d, 8) ?></span>
        <?php foreach ($byDay[$d] ?? [] as $t): ?>
          <a class="np-cal-task st-<?= h($t['status']) ?> <?= task_late($t) ? 'late' : '' ?>" href="<?= h(tasks_url('id=' . (int)$t['id'])) ?>" title="<?= h($t['title'] . ' · ' . TASK_STATUS[$t['status']] . ($showWho ? ' · ' . user_name($t['assigned_to']) : '')) ?>"><?= h(task_icon($t)) ?> <?= h($t['title']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endfor; ?>
  </div>
  <p class="hint">Tasks on their due date. Tasks without a due date are on the Board.</p>
</section>
<?php endif; ?>

<?php if ($kind === 'video'):
    $vids = q("SELECT * FROM tasks WHERE $w AND status = 'done' ORDER BY done_at DESC LIMIT 100", $args)->fetchAll(); ?>
<section class="panel">
  <h2>🎬 Videos delivered <small class="muted">latest 100</small></h2>
  <?php if (!$vids): ?><p class="muted">No finished videos yet.</p><?php else: ?>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Video</th><th>Platform</th><th>Length</th><th>Editor</th><th>Given</th><th>Delivered</th><th>Time taken</th><th>Final</th></tr></thead>
    <tbody>
    <?php foreach ($vids as $v): $vx = json_decode((string)$v['extra'], true) ?: []; ?>
      <tr><td><a href="<?= h(tasks_url('id=' . (int)$v['id'])) ?>"><?= h($v['title']) ?></a></td><td><?= h($vx['platform'] ?? '') ?></td><td><?= h($vx['length'] ?? '') ?></td>
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
<script src="<?= h(asset('assets/tasks.js')) ?>" defer></script>
<?php require __DIR__ . '/inc/footer.php'; ?>
