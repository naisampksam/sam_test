<?php
// Tasks for staff (orders team, video editor, digital marketing …) and each person's performance:
// order work (created / print processed / printed / packed / shipped) plus tasks (done, on time, late, pending).
declare(strict_types=1);

/** What a staff member mainly does. Production staff work on orders; the others start on their tasks. */
const JOBS = ['production' => 'Production (orders)', 'video_editor' => 'Video editor', 'marketing' => 'Digital marketing', 'design' => 'Designer', 'other' => 'Other'];

const TASK_KINDS = ['general' => '📌 General', 'video' => '🎬 Video editing', 'marketing' => '📣 Marketing', 'design' => '🎨 Design', 'production' => '👕 Production'];
const TASK_STATUS = ['todo' => 'To do', 'doing' => 'Working on it', 'review' => 'For review', 'done' => 'Done'];
const TASK_PRIORITY = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
/** Fewest counted items (tasks + printed / shipped work) before an on-time % is shown. */
const ON_TIME_MIN = 5;

/** "92%" with enough work, else "2 of 3 on time" (or "—"). Coloured when it is a %. */
function on_time_text(array $r, bool $html = true): string
{
    $all = (int)($r['on_time_all'] ?? 0);
    if (!$all) {
        return '—';
    }
    if (($r['on_time_pct'] ?? null) === null) {
        return (int)$r['on_time_good'] . ' of ' . $all . ' on time';
    }
    return $html ? '<b class="' . ($r['on_time_pct'] < 80 ? 'err-text' : 'ok-text') . '">' . $r['on_time_pct'] . '%</b>' : $r['on_time_pct'] . '%';
}

const VIDEO_PLATFORMS = ['Instagram Reel', 'Instagram Post / Story', 'YouTube video', 'YouTube Short', 'Ad / Promo', 'Product video', 'Other'];

/** The job a task kind suits (new tasks for a video editor start as video tasks …). */
function kind_for_job(string $job): string
{
    return ['video_editor' => 'video', 'marketing' => 'marketing', 'design' => 'design', 'production' => 'general'][$job] ?? 'general';
}

function user_job(?array $u = null): string
{
    $u = $u ?? current_user();
    return isset(JOBS[$u['job'] ?? '']) ? $u['job'] : 'production';
}

/** Staff who are not on the orders team (video editor, marketing …): their home page is their tasks. */
function office_staff(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && !is_admin($u) && user_job($u) !== 'production';
}

/** May give tasks to other people and see everyone's tasks. */
function can_assign_tasks(): bool
{
    return is_admin() || cap('tasks');
}

/** Ids of admin accounts. Their own tasks are private: only admins see them. */
function admin_ids(): array
{
    static $ids = null;
    return $ids ??= array_map('intval', q("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN));
}

/** SQL condition hiding admins' tasks from people who are not admins ('1=1' for admins). */
function task_privacy_sql(): string
{
    return is_admin() || !admin_ids() ? '1=1' : 'assigned_to NOT IN (' . implode(',', admin_ids()) . ')';
}

function task_visible(array $t): bool
{
    $me = (int)current_user()['id'];
    if (!is_admin() && in_array((int)$t['assigned_to'], admin_ids(), true)) {
        return false; // an admin's own task
    }
    return can_assign_tasks() || (int)$t['assigned_to'] === $me || (int)$t['created_by'] === $me;
}

/**
 * Earlier tasks to add again quickly: one per title (the latest), most recent first.
 * Only from tasks this person may see (so admins' private tasks never show up for staff).
 */
function task_templates(int $limit = 60): array
{
    $me = (int)current_user()['id'];
    $where = task_privacy_sql() . (can_assign_tasks() ? '' : ' AND (assigned_to = ' . $me . ' OR created_by = ' . $me . ')');
    $ids = q("SELECT MAX(id) id, COUNT(*) n FROM tasks WHERE $where GROUP BY title ORDER BY MAX(id) DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (q('SELECT * FROM tasks WHERE id IN (' . implode(',', array_map('intval', array_keys($ids))) . ') ORDER BY id DESC')->fetchAll() as $t) {
        $x = json_decode((string)$t['extra'], true) ?: [];
        $out[] = ['title' => $t['title'], 'kind' => $t['kind'], 'details' => (string)$t['details'], 'priority' => $t['priority'],
            'brief_link' => $t['brief_link'], 'platform' => $x['platform'] ?? '', 'length' => $x['length'] ?? '', 'to' => (int)$t['assigned_to'], 'n' => (int)$ids[$t['id']]];
    }
    return $out;
}

/** The person the task is for may move it along; so may whoever gave it and the admin. */
function task_editable(array $t): bool
{
    return task_visible($t);
}

/** May approve a task sent for review: whoever gave it, an admin, or a task manager (not on their own task). */
function task_approver(array $t): bool
{
    $me = (int)current_user()['id'];
    return is_admin() || (int)$t['created_by'] === $me || (can_assign_tasks() && (int)$t['assigned_to'] !== $me);
}

/** The person doing the task must send it for approval instead of closing it. */
function task_needs_approval(array $t): bool
{
    return !empty($t['needs_review']) && !task_approver($t);
}

function get_task(int $id): ?array
{
    $t = q('SELECT * FROM tasks WHERE id = ?', [$id])->fetch();
    if ($t) {
        $t['extra'] = json_decode((string)$t['extra'], true) ?: [];
    }
    return $t ?: null;
}

/** Late = done after its due date, or still open past it. */
function task_late(array $t): bool
{
    if (!$t['due_date']) {
        return false;
    }
    return $t['status'] === 'done' ? substr((string)$t['done_at'], 0, 10) > $t['due_date'] : $t['due_date'] < today();
}

function task_note(int $taskId, string $note, string $status = ''): void
{
    q('INSERT INTO task_notes (task_id, user_id, note, status, created_at) VALUES (?, ?, ?, ?, ?)',
        [$taskId, current_user()['id'], $note !== '' ? mb_substr($note, 0, 4000) : null, $status, now()]);
}

function task_set_status(array $t, string $status, string $note = ''): void
{
    if (!isset(TASK_STATUS[$status])) {
        return;
    }
    $set = ['status' => $status, 'updated_at' => now()];
    if ($status !== 'todo' && !$t['started_at']) {
        $set['started_at'] = now();
    }
    $set['done_at'] = $status === 'done' ? ($t['status'] === 'done' ? $t['done_at'] : now()) : null;
    q('UPDATE tasks SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?', array_merge(array_values($set), [$t['id']]));
    if ($status !== $t['status'] || $note !== '') {
        task_note((int)$t['id'], $note, $status !== $t['status'] ? $status : '');
    }
}

/** Active staff for pickers and the team table: [id => row]. Admins are listed only for admins (their work is private). */
function staff_list(bool $withInactive = false): array
{
    $out = [];
    $where = array_filter([$withInactive ? '' : 'active = 1', is_admin() ? '' : "role <> 'admin'"]);
    foreach (q('SELECT * FROM users' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY role DESC, name')->fetchAll() as $u) {
        $out[(int)$u['id']] = $u;
    }
    return $out;
}

/**
 * Work done by each person between $from and $to (dates), and their open tasks now.
 * [uid => ['orders' => n, 'orders_pcs', 'processed', 'printed', 'printed_late', 'packed', 'packed_pcs', 'shipped', 'shipped_pcs',
 *          'shipped_late', 'edits', 'done', 'done_on_time', 'done_late', 'pending', 'overdue', 'review', 'videos', 'on_time_pct']]
 */
function staff_performance(string $from, string $to): array
{
    $a = "$from 00:00:00";
    $b = "$to 23:59:59";
    $p = [];
    $add = function ($uid, string $k, $v) use (&$p) {
        if ($uid) {
            $p[(int)$uid][$k] = ($p[(int)$uid][$k] ?? 0) + (float)$v;
        }
    };
    foreach (q('SELECT o.created_by uid, COUNT(DISTINCT o.id) n, IFNULL(SUM(it.quantity), 0) pcs FROM orders o LEFT JOIN order_items it ON it.order_id = o.id
                WHERE o.deleted_at IS NULL AND o.created_at BETWEEN ? AND ? GROUP BY o.created_by', [$a, $b])->fetchAll() as $r) {
        $add($r['uid'], 'orders', $r['n']);
        $add($r['uid'], 'orders_pcs', $r['pcs']);
    }
    foreach (q('SELECT print_processed_by uid, COUNT(*) n FROM orders WHERE deleted_at IS NULL AND print_processed_at BETWEEN ? AND ? GROUP BY print_processed_by', [$a, $b])->fetchAll() as $r) {
        $add($r['uid'], 'processed', $r['n']);
    }
    foreach (q('SELECT it.printed_by uid, SUM(it.quantity) pcs, SUM(CASE WHEN o.due_date IS NOT NULL AND DATE(it.printed_at) > o.due_date THEN it.quantity ELSE 0 END) late
                FROM order_items it JOIN orders o ON o.id = it.order_id WHERE o.deleted_at IS NULL AND it.printed_at BETWEEN ? AND ? GROUP BY it.printed_by', [$a, $b])->fetchAll() as $r) {
        $add($r['uid'], 'printed', $r['pcs']);
        $add($r['uid'], 'printed_late', $r['late']);
    }
    foreach (['packed', 'shipped'] as $st) {
        foreach (q("SELECT o.{$st}_by uid, COUNT(DISTINCT o.id) n, IFNULL(SUM(it.quantity), 0) pcs,
                    COUNT(DISTINCT CASE WHEN o.due_date IS NOT NULL AND DATE(o.{$st}_at) > o.due_date THEN o.id END) late
                    FROM orders o LEFT JOIN order_items it ON it.order_id = o.id WHERE o.deleted_at IS NULL AND o.{$st}_at BETWEEN ? AND ? GROUP BY o.{$st}_by", [$a, $b])->fetchAll() as $r) {
            $add($r['uid'], $st, $r['n']);
            $add($r['uid'], $st . '_pcs', $r['pcs']);
            $add($r['uid'], $st . '_late', $r['late']);
        }
    }
    foreach (q('SELECT user_id uid, COUNT(*) n FROM order_log WHERE created_at BETWEEN ? AND ? GROUP BY user_id', [$a, $b])->fetchAll() as $r) {
        $add($r['uid'], 'edits', $r['n']);
    }
    foreach (q("SELECT assigned_to uid, COUNT(*) n, SUM(due_date IS NULL OR DATE(done_at) <= due_date) ontime, SUM(kind = 'video') videos
                FROM tasks WHERE status = 'done' AND done_at BETWEEN ? AND ? GROUP BY assigned_to", [$a, $b])->fetchAll() as $r) {
        $add($r['uid'], 'done', $r['n']);
        $add($r['uid'], 'done_on_time', $r['ontime']);
        $add($r['uid'], 'done_late', $r['n'] - $r['ontime']);
        $add($r['uid'], 'videos', $r['videos']);
    }
    foreach (q("SELECT assigned_to uid, COUNT(*) n, SUM(due_date IS NOT NULL AND due_date < ?) overdue, SUM(status = 'review') review
                FROM tasks WHERE status <> 'done' GROUP BY assigned_to", [today()])->fetchAll() as $r) {
        $add($r['uid'], 'pending', $r['n']);
        $add($r['uid'], 'overdue', $r['overdue']);
        $add($r['uid'], 'review', $r['review']);
    }
    foreach ($p as &$r) {
        // On time: tasks done by their date and order work done by the order's dispatch date, out of everything counted.
        $orderWork = ($r['printed'] ?? 0) + ($r['shipped'] ?? 0);
        $orderLate = ($r['printed_late'] ?? 0) + ($r['shipped_late'] ?? 0);
        $all = $orderWork + ($r['done'] ?? 0) + ($r['overdue'] ?? 0);
        $good = $orderWork - $orderLate + ($r['done_on_time'] ?? 0);
        $r['on_time_good'] = max(0, $good);
        $r['on_time_all'] = $all;
        // A % only means something with enough work; below that show "x of y on time".
        $r['on_time_pct'] = $all >= ON_TIME_MIN ? round($good / $all * 100) : null;
    }
    unset($r);
    return $p;
}

/** One person's work per day for the last $days days: ['2026-10-01' => ['pcs' => printed+packed+shipped pcs, 'tasks' => done], …]. */
function staff_daily(int $uid, int $days = 30): array
{
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $out = [];
    for ($d = $from; $d <= today(); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $out[$d] = ['pcs' => 0, 'orders' => 0, 'tasks' => 0];
    }
    $a = "$from 00:00:00";
    foreach (q('SELECT DATE(it.printed_at) d, SUM(it.quantity) n FROM order_items it WHERE it.printed_by = ? AND it.printed_at >= ? GROUP BY d', [$uid, $a])->fetchAll() as $r) {
        $out[$r['d']]['pcs'] += (int)$r['n'];
    }
    foreach (['packed', 'shipped'] as $st) {
        foreach (q("SELECT DATE(o.{$st}_at) d, SUM(it.quantity) n FROM orders o JOIN order_items it ON it.order_id = o.id WHERE o.{$st}_by = ? AND o.{$st}_at >= ? AND o.deleted_at IS NULL GROUP BY d", [$uid, $a])->fetchAll() as $r) {
            $out[$r['d']]['pcs'] += (int)$r['n'];
        }
    }
    foreach (q('SELECT DATE(created_at) d, COUNT(*) n FROM orders WHERE created_by = ? AND created_at >= ? AND deleted_at IS NULL GROUP BY d', [$uid, $a])->fetchAll() as $r) {
        $out[$r['d']]['orders'] += (int)$r['n'];
    }
    foreach (q("SELECT DATE(done_at) d, COUNT(*) n FROM tasks WHERE assigned_to = ? AND status = 'done' AND done_at >= ? GROUP BY d", [$uid, $a])->fetchAll() as $r) {
        $out[$r['d']]['tasks'] += (int)$r['n'];
    }
    return $out;
}

/** Task card used on the board and lists. */
function task_card(array $t, bool $showWho): string
{
    $extra = is_array($t['extra']) ? $t['extra'] : (json_decode((string)$t['extra'], true) ?: []);
    $late = task_late($t);
    $bits = [];
    if ($t['kind'] === 'video') {
        $bits = array_filter([$extra['platform'] ?? '', ($extra['length'] ?? '') !== '' ? $extra['length'] : '']);
    }
    $due = $t['due_date'] ? ($late && $t['status'] !== 'done' ? '⚠ was due ' : 'due ') . fmt_date($t['due_date']) : '';
    return '<a class="task-card prio-' . h($t['priority']) . ($late ? ' is-late' : '') . '" href="tasks.php?id=' . (int)$t['id'] . '">'
        . '<span class="tc-kind">' . h(TASK_KINDS[$t['kind']] ?? $t['kind']) . ($t['priority'] !== 'normal' ? ' · <b class="prio">' . h(TASK_PRIORITY[$t['priority']] ?? '') . '</b>' : '') . '</span>'
        . '<b class="tc-title">' . h($t['title']) . '</b>'
        . ($bits ? '<span class="tc-meta">' . h(implode(' · ', $bits)) . '</span>' : '')
        . '<span class="tc-foot">' . ($showWho ? '<span>👤 ' . h(user_name($t['assigned_to'])) . '</span>' : '<span></span>')
        . '<span class="' . ($late ? 'err-text' : 'muted') . '">' . h($t['status'] === 'done' && $t['done_at'] ? '✓ ' . fmt_date($t['done_at']) . ($late ? ' (late)' : '') : $due) . '</span></span>'
        . ($t['result_link'] !== '' ? '<span class="tc-link">🔗 Result attached</span>' : '')
        . '</a>';
}
