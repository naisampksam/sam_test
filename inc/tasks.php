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

/** Has the Tasks page (admin, people who give tasks, and staff the admin ticked it for — video editor, marketing …). */
function has_task_page(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && (cap('task_page', $u) || cap('tasks', $u));
}

/** Staff who are not on the orders team (video editor, marketing …): their home page is their tasks. */
function office_staff(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && !is_admin($u) && user_job($u) !== 'production' && has_task_page($u);
}

/** Link to the Tasks page (orders.loomaapparels.com/tasks), optionally with a query: tasks_url('id=5'). */
function tasks_url(string $query = ''): string
{
    return base_url('tasks') . ($query !== '' ? '?' . $query : '');
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
        $out[] = ['id' => (int)$t['id'], 'title' => $t['title'], 'kind' => $t['kind'], 'details' => (string)$t['details'], 'priority' => $t['priority'],
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

/** People a task can be given to: active staff with the Tasks page (the orders team get no tasks, only their work score). */
function task_staff(): array
{
    return array_filter(staff_list(), fn($u) => has_task_page(user_with_caps($u)));
}

/** A users row straight from the database, with caps / perms decoded like current_user(). */
function user_with_caps(array $u): array
{
    foreach (['caps', 'perms'] as $k) {
        if (!is_array($u[$k] ?? null)) {
            $u[$k] = json_decode((string)($u[$k] ?? '') ?: '{}', true) ?: [];
        }
    }
    return $u;
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
    foreach (q("SELECT o.created_by uid, COUNT(DISTINCT o.id) n, IFNULL(SUM(it.quantity), 0) pcs,
                IFNULL(SUM(CASE WHEN it.item_type IN ('print', 'plain') THEN it.quantity ELSE 0 END), 0) tees FROM orders o LEFT JOIN order_items it ON it.order_id = o.id
                WHERE o.deleted_at IS NULL AND o.created_at BETWEEN ? AND ? GROUP BY o.created_by", [$a, $b])->fetchAll() as $r) {
        $add($r['uid'], 'orders', $r['n']);
        $add($r['uid'], 'orders_pcs', $r['pcs']);
        $add($r['uid'], 'orders_tees', $r['tees']);
    }
    foreach (q("SELECT o.print_processed_by uid, COUNT(*) n, IFNULL(SUM((SELECT SUM(it.quantity) FROM order_items it WHERE it.order_id = o.id AND it.item_type IN ('print', 'plain'))), 0) tees
                FROM orders o WHERE o.deleted_at IS NULL AND o.print_processed_at BETWEEN ? AND ? GROUP BY o.print_processed_by", [$a, $b])->fetchAll() as $r) {
        $add($r['uid'], 'processed', $r['n']);
        $add($r['uid'], 'processed_tees', $r['tees']);
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
        $r['points'] = work_points($r)['total'];
    }
    unset($r);
    return $p;
}

// ---------------------------------------------------------------- work score

/** Points for each piece of work (the admin can change them on Your performance). Late work earns 'late' % of its points. */
const SCORE_DEFAULTS = ['order' => 1, 'order_pc' => 1, 'processed' => 1, 'processed_pc' => 0.5, 'printed' => 1, 'packed' => 1, 'shipped' => 0, 'task' => 10, 'late' => 50];
const SCORE_LABELS = ['order' => 'Order created (each order)', 'order_pc' => 'T-shirts in orders created (each T-shirt)', 'processed' => 'Order print-processed (each order)', 'processed_pc' => 'T-shirts print-processed (each T-shirt)',
    'printed' => 'T-shirt printed (each piece)', 'packed' => 'Order packed (each order)',
    'shipped' => 'Order shipped', 'task' => 'Task done'];

function score_rules(): array
{
    $saved = json_decode((string)setting('score_points', ''), true) ?: [];
    $out = [];
    foreach (SCORE_DEFAULTS as $k => $v) {
        $out[$k] = isset($saved[$k]) && is_numeric($saved[$k]) ? max(0, (float)$saved[$k]) : $v;
    }
    $out['late'] = min(100, $out['late']);
    return $out;
}

/** How one person's points add up: ['lines' => [key => [label, count, late, each, points]], 'total' => n]. */
function work_points(array $r): array
{
    $rules = score_rules();
    $n = fn(string $k) => (float)($r[$k] ?? 0);
    $work = ['order' => [$n('orders'), 0], 'order_pc' => [$n('orders_tees'), 0], 'processed' => [$n('processed'), 0], 'processed_pc' => [$n('processed_tees'), 0], 'printed' => [$n('printed'), $n('printed_late')],
        'packed' => [$n('packed'), $n('packed_late')], 'shipped' => [$n('shipped'), $n('shipped_late')], 'task' => [$n('done'), $n('done_late')]];
    $lines = [];
    $total = 0;
    foreach ($work as $k => [$all, $late]) {
        $pts = ($all - $late) * $rules[$k] + $late * $rules[$k] * $rules['late'] / 100;
        $lines[$k] = ['label' => SCORE_LABELS[$k], 'count' => (int)round($all), 'late' => (int)round($late), 'each' => $rules[$k], 'points' => $pts];
        $total += $pts;
    }
    return ['lines' => $lines, 'total' => (int)round($total)];
}

/** This month's points and place in the team for the top-right badge (worked out at most every 2 minutes). */
function my_month_score(): array
{
    $me = current_user();
    $c = $_SESSION['my_score'] ?? null;
    if (is_array($c) && $c['at'] > time() - 120 && $c['uid'] === (int)$me['id']) {
        return $c;
    }
    $perf = staff_performance(date('Y-m-01'), today());
    $mine = (int)($perf[(int)$me['id']]['points'] ?? 0);
    $team = array_keys(staff_list());
    $rank = 1 + count(array_filter($team, fn($id) => (int)($perf[$id]['points'] ?? 0) > $mine));
    return $_SESSION['my_score'] = ['at' => time(), 'uid' => (int)$me['id'], 'points' => $mine, 'rank' => $rank, 'of' => count($team)];
}

// ---------------------------------------------------------------- task pages (Notion-style blocks)

const BLOCK_TYPES = ['p' => 'Text', 'h1' => 'Heading 1', 'h2' => 'Heading 2', 'h3' => 'Heading 3', 'bullet' => 'Bulleted list',
    'number' => 'Numbered list', 'todo' => 'To-do', 'toggle' => 'Toggle', 'quote' => 'Quote', 'callout' => 'Callout', 'divider' => 'Divider', 'image' => 'Image'];
const BLOCK_COLORS = ['gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];
const KIND_ICONS = ['general' => '📄', 'video' => '🎬', 'marketing' => '📣', 'design' => '🎨', 'production' => '👕'];

/** Starting content for a new page: [type, text]. */
const PAGE_TEMPLATES = [
    '' => ['label' => '📄 Blank page', 'blocks' => []],
    'video' => ['label' => '🎬 Video script', 'blocks' => [['h2', 'Hook — first 3 seconds'], ['p', ''], ['h2', 'Script'], ['p', ''], ['p', ''],
        ['h2', 'Call to action'], ['p', ''], ['h2', 'Shots & footage needed'], ['todo', '']]],
    'marketing' => ['label' => '📣 Post / campaign', 'blocks' => [['h2', 'Goal'], ['p', ''], ['h2', 'Caption'], ['p', ''], ['h2', 'Hashtags'], ['p', ''],
        ['h2', 'Checklist'], ['todo', 'Creative ready'], ['todo', 'Caption approved'], ['todo', 'Posted']]],
    'design' => ['label' => '🎨 Design brief', 'blocks' => [['h2', 'What is needed'], ['p', ''], ['h2', 'Sizes / formats'], ['bullet', ''], ['h2', 'References'], ['p', '']]],
];

function task_icon(array $t): string
{
    return ($t['icon'] ?? '') !== '' ? $t['icon'] : (KIND_ICONS[$t['kind']] ?? '📄');
}

function new_block_uid(): string
{
    return 'b' . bin2hex(random_bytes(6));
}

/** Text typed in a block: only simple formatting is kept (bold, italic, underline, strike, code, highlight, links, line breaks). */
function block_html_clean(string $html): string
{
    $html = mb_substr($html, 0, 20000);
    if ($html === '' || !preg_match('/[<&]/', $html)) {
        return h($html);
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementsByTagName('div')->item(0);
    $out = $root ? block_html_walk($root) : '';
    return preg_replace('~(<br>)+$~', '', $out);
}

function block_html_walk(DOMNode $n): string
{
    $out = '';
    foreach ($n->childNodes as $c) {
        if ($c instanceof DOMText) {
            $out .= h($c->nodeValue);
            continue;
        }
        if (!$c instanceof DOMElement) {
            continue;
        }
        $tag = ['strong' => 'b', 'em' => 'i', 'strike' => 's', 'del' => 's'][strtolower($c->tagName)] ?? strtolower($c->tagName);
        if ($tag === 'br') {
            $out .= '<br>';
            continue;
        }
        $inner = block_html_walk($c);
        if (in_array($tag, ['b', 'i', 'u', 's', 'code', 'mark'], true)) {
            $out .= $inner === '' ? '' : "<$tag>$inner</$tag>";
        } elseif ($tag === 'a') {
            $href = trim($c->getAttribute('href'));
            $out .= preg_match('~^(https?://|mailto:|tel:)~i', $href) ? '<a href="' . h($href) . '" target="_blank" rel="noopener">' . $inner . '</a>' : $inner;
        } elseif (in_array($tag, ['div', 'p', 'li'], true)) {
            $out .= ($out !== '' && !str_ends_with($out, '<br>') ? '<br>' : '') . $inner;
        } else {
            $out .= $inner;
        }
    }
    return $out;
}

function block_text(string $html): string
{
    return trim(html_entity_decode(strip_tags(str_replace('<br>', "\n", $html)), ENT_QUOTES, 'UTF-8'));
}

/** Extras on a block: links, reference images, connected orders / tasks, timing, shot note, ✓ edited, colour, indent, toggle open. */
function block_props_clean($p): array
{
    $p = is_array($p) ? $p : [];
    $out = [];
    foreach (array_slice(is_array($p['links'] ?? null) ? $p['links'] : [], 0, 20) as $l) {
        $u = trim((string)($l['u'] ?? ''));
        if (preg_match('~^https?://\S+$~i', $u)) {
            $out['links'][] = ['u' => mb_substr($u, 0, 500), 't' => mb_substr(trim((string)($l['t'] ?? '')), 0, 120)];
        }
    }
    foreach (array_slice(is_array($p['imgs'] ?? null) ? $p['imgs'] : [], 0, 20) as $f) {
        if (is_string($f) && preg_match('~^\d{4}/\d{2}/[a-f0-9]{24}\.(jpg|png|webp|gif)$~', $f)) {
            $out['imgs'][] = $f;
        }
    }
    foreach (array_slice(is_array($p['conn'] ?? null) ? $p['conn'] : [], 0, 20) as $c) {
        if (in_array($c['k'] ?? '', ['order', 'task'], true) && (int)($c['id'] ?? 0) > 0) {
            $out['conn'][] = ['k' => $c['k'], 'id' => (int)$c['id']];
        }
    }
    if (is_numeric($p['sec'] ?? null) && $p['sec'] > 0) {
        $out['sec'] = min(3600, round((float)$p['sec'], 1));
    }
    if (trim((string)($p['shot'] ?? '')) !== '') {
        $out['shot'] = mb_substr(trim((string)$p['shot']), 0, 300);
    }
    if (!empty($p['done'])) {
        $out['done'] = true;
    }
    if (in_array($p['color'] ?? '', BLOCK_COLORS, true)) {
        $out['color'] = $p['color'];
    }
    if ((int)($p['ind'] ?? 0) > 0) {
        $out['ind'] = min(4, (int)$p['ind']);
    }
    if (!empty($p['open'])) {
        $out['open'] = true;
    }
    if (preg_match('/^\X$/u', (string)($p['emoji'] ?? ''))) {
        $out['emoji'] = $p['emoji'];
    }
    return $out;
}

/** A page's blocks for the editor. Old tasks: their details text becomes the first blocks (once). */
function task_blocks(array $t): array
{
    $tid = (int)$t['id'];
    $rows = q('SELECT * FROM task_blocks WHERE task_id = ? ORDER BY pos, id', [$tid])->fetchAll();
    if (!$rows && (int)$t['body_rev'] === 0 && trim((string)$t['details']) !== '') {
        insert_blocks($tid, details_blocks((string)$t['details']));
        q('UPDATE tasks SET body_rev = 1 WHERE id = ?', [$tid]);
        $rows = q('SELECT * FROM task_blocks WHERE task_id = ? ORDER BY pos, id', [$tid])->fetchAll();
    }
    return array_map(fn($r) => ['id' => $r['uid'], 'type' => $r['type'], 'html' => (string)$r['html'], 'checked' => (bool)$r['checked'],
        'props' => json_decode((string)$r['props'], true) ?: (object)[], 'by' => (int)$r['updated_by'], 'at' => (string)$r['updated_at']], $rows);
}

/** Plain text (one line each) as blocks: "- " bullets, "# " headings, "[ ] " to-dos. */
function details_blocks(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/u', trim($text)) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $type = 'p';
        foreach (['/^#{1,3}\s+/' => 'h2', '/^[-•*]\s+/' => 'bullet', '/^\[\s?\]\s*/' => 'todo'] as $re => $ty) {
            if (preg_match($re, $line)) {
                $type = $ty;
                $line = preg_replace($re, '', $line);
                break;
            }
        }
        $out[] = ['type' => $type, 'html' => h($line)];
    }
    return $out;
}

/** Add blocks at the end of a page: [['type' =>, 'html' =>, 'checked' =>, 'props' =>], …]. */
function insert_blocks(int $taskId, array $blocks): void
{
    $pos = (int)q('SELECT IFNULL(MAX(pos), -1) FROM task_blocks WHERE task_id = ?', [$taskId])->fetchColumn();
    $me = current_user()['id'] ?? null;
    foreach ($blocks as $b) {
        q('INSERT INTO task_blocks (task_id, uid, pos, type, html, checked, props, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$taskId, new_block_uid(), ++$pos, $b['type'], $b['html'] ?? '', (int)!empty($b['checked']), json_encode($b['props'] ?? (object)[], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $me, now()]);
    }
}

/** Copy a page's content to a new task ("Give again" / quick add): to-dos unticked, ✓ edited cleared. */
function copy_task_blocks(int $fromId, int $toId): void
{
    $blocks = [];
    foreach (q('SELECT * FROM task_blocks WHERE task_id = ? ORDER BY pos, id', [$fromId])->fetchAll() as $r) {
        $props = json_decode((string)$r['props'], true) ?: [];
        unset($props['done']);
        $blocks[] = ['type' => $r['type'], 'html' => $r['html'], 'props' => $props ?: (object)[]];
        foreach ($props['imgs'] ?? [] as $f) {
            q('INSERT INTO task_files (task_id, filename, original_name, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?)', [$toId, $f, '', current_user()['id'], now()]);
        }
    }
    insert_blocks($toId, $blocks);
}

/**
 * Save editor changes: blocks changed (added or edited), blocks deleted, and the order of the blocks.
 * Changes by different people to different blocks are all kept; blocks someone else added meanwhile stay where they were.
 */
function save_task_blocks(int $taskId, array $changed, array $deleted, array $order, int $baseRev): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $rev = (int)q('SELECT body_rev FROM tasks WHERE id = ? FOR UPDATE', [$taskId])->fetchColumn();
        $files = array_flip(q('SELECT filename FROM task_files WHERE task_id = ?', [$taskId])->fetchAll(PDO::FETCH_COLUMN));
        $ok = fn($uid) => is_string($uid) && preg_match('/^[A-Za-z0-9_-]{4,24}$/', $uid);
        $up = $pdo->prepare('INSERT INTO task_blocks (task_id, uid, pos, type, html, checked, props, updated_by, updated_at) VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE type = VALUES(type), html = VALUES(html), checked = VALUES(checked), props = VALUES(props), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
        foreach (array_slice($changed, 0, 3000) as $b) {
            if (!is_array($b) || !$ok($b['id'] ?? null)) {
                continue;
            }
            $props = block_props_clean($b['props'] ?? []);
            if (isset($props['imgs'])) {
                $props['imgs'] = array_values(array_filter($props['imgs'], fn($f) => isset($files[$f])));
                if (!$props['imgs']) {
                    unset($props['imgs']);
                }
            }
            $up->execute([$taskId, $b['id'], isset(BLOCK_TYPES[$b['type'] ?? '']) ? $b['type'] : 'p', block_html_clean((string)($b['html'] ?? '')),
                (int)!empty($b['checked']), json_encode($props ?: (object)[], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), current_user()['id'], now()]);
        }
        $del = array_values(array_filter($deleted, $ok));
        if ($del) {
            q('DELETE FROM task_blocks WHERE task_id = ? AND uid IN (' . rtrim(str_repeat('?,', count($del)), ',') . ')', array_merge([$taskId], $del));
        }
        // Order: the editor's order; blocks it did not know about stay after the block they followed.
        $server = q('SELECT uid, pos FROM task_blocks WHERE task_id = ? ORDER BY pos, id', [$taskId])->fetchAll(PDO::FETCH_KEY_PAIR);
        $final = array_values(array_unique(array_filter($order, fn($u) => is_string($u) && isset($server[$u]))));
        $prev = null;
        foreach (array_keys($server) as $u) {
            if (!in_array($u, $final, true)) {
                $i = $prev === null ? false : array_search($prev, $final, true);
                array_splice($final, $i === false ? ($prev === null ? 0 : count($final)) : $i + 1, 0, [$u]);
            }
            $prev = $u;
        }
        $setPos = $pdo->prepare('UPDATE task_blocks SET pos = ? WHERE task_id = ? AND uid = ?');
        foreach ($final as $i => $u) {
            if ((int)$server[$u] !== $i) {
                $setPos->execute([$i, $taskId, $u]);
            }
        }
        q('UPDATE tasks SET body_rev = ?, updated_at = ? WHERE id = ?', [$rev + 1, now(), $taskId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['rev' => $rev + 1, 'others' => $rev !== $baseRev];
}

/** Names and links for connected orders / tasks in these blocks: ['order:5' => ['label' =>, 'url' =>], …]. */
function block_connections(array $blocks): array
{
    $ids = ['order' => [], 'task' => []];
    foreach ($blocks as $b) {
        foreach (((array)$b['props'])['conn'] ?? [] as $c) {
            $ids[$c['k']][] = (int)$c['id'];
        }
    }
    $out = [];
    if ($ids['order']) {
        foreach (q('SELECT id, customer_name, ship_name, deleted_at FROM orders WHERE id IN (' . implode(',', array_unique($ids['order'])) . ')')->fetchAll() as $o) {
            $out['order:' . $o['id']] = ['label' => order_no($o['id']) . (($o['customer_name'] ?: $o['ship_name']) !== '' ? ' · ' . ($o['customer_name'] ?: $o['ship_name']) : '')
                . ($o['deleted_at'] ? ' (deleted)' : ''), 'url' => base_url('order.php?id=' . $o['id'])];
        }
    }
    if ($ids['task']) {
        foreach (q('SELECT * FROM tasks WHERE id IN (' . implode(',', array_unique($ids['task'])) . ')')->fetchAll() as $t) {
            $out['task:' . $t['id']] = task_visible($t) ? ['label' => task_icon($t) . ' ' . $t['title'], 'url' => tasks_url('id=' . $t['id'])] : ['label' => '🔒 Private page', 'url' => ''];
        }
    }
    return $out;
}

/** Script / checklist progress per task: [id => ['todo' => n, 'ticked' => n, 'lines' => n, 'edited' => n]]. */
function task_progress(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (q("SELECT task_id, SUM(type = 'todo') todo, SUM(type = 'todo' AND checked = 1) ticked,
                SUM(type IN ('p', 'bullet', 'number', 'quote', 'callout') AND html <> '') line_n, SUM(props LIKE '%\"done\":true%') edited
                FROM task_blocks WHERE task_id IN (" . implode(',', array_map('intval', $ids)) . ') GROUP BY task_id')->fetchAll() as $r) {
        $out[(int)$r['task_id']] = ['todo' => (int)$r['todo'], 'ticked' => (int)$r['ticked'], 'lines' => (int)$r['line_n'], 'edited' => (int)$r['edited']];
    }
    return $out;
}

/** Delete a task with its page, comments and the images only it used. */
function delete_task(int $tid): void
{
    $files = q('SELECT filename FROM task_files WHERE task_id = ?', [$tid])->fetchAll(PDO::FETCH_COLUMN);
    q('DELETE FROM task_notes WHERE task_id = ?', [$tid]);
    q('DELETE FROM task_blocks WHERE task_id = ?', [$tid]);
    q('DELETE FROM task_files WHERE task_id = ?', [$tid]);
    q('DELETE FROM tasks WHERE id = ?', [$tid]);
    foreach (array_unique($files) as $f) {
        unlink_if_unused($f);
    }
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
function task_card(array $t, bool $showWho, ?array $progress = null): string
{
    $extra = is_array($t['extra']) ? $t['extra'] : (json_decode((string)$t['extra'], true) ?: []);
    $late = task_late($t);
    $bits = [];
    if ($t['kind'] === 'video') {
        $bits = array_filter([$extra['platform'] ?? '', ($extra['length'] ?? '') !== '' ? $extra['length'] : '']);
    }
    $due = $t['due_date'] ? ($late && $t['status'] !== 'done' ? '⚠ was due ' : 'due ') . fmt_date($t['due_date']) : '';
    $pg = $progress ?? null;
    $prog = '';
    if ($pg && $pg['lines'] && $pg['edited']) {
        $prog = '✎ ' . $pg['edited'] . '/' . $pg['lines'] . ' lines edited';
    } elseif ($pg && $pg['todo']) {
        $prog = '☑ ' . $pg['ticked'] . '/' . $pg['todo'];
    }
    return '<a class="task-card prio-' . h($t['priority']) . ($late ? ' is-late' : '') . '" href="' . h(tasks_url('id=' . (int)$t['id'])) . '" draggable="true" data-task="' . (int)$t['id'] . '">'
        . '<span class="tc-kind">' . h(TASK_KINDS[$t['kind']] ?? $t['kind']) . ($t['priority'] !== 'normal' ? ' · <b class="prio">' . h(TASK_PRIORITY[$t['priority']] ?? '') . '</b>' : '') . '</span>'
        . '<b class="tc-title"><span class="tc-icon">' . h(task_icon($t)) . '</span> ' . h($t['title']) . '</b>'
        . ($bits ? '<span class="tc-meta">' . h(implode(' · ', $bits)) . '</span>' : '')
        . ($prog !== '' ? '<span class="tc-prog">' . h($prog) . '</span>' : '')
        . '<span class="tc-foot">' . ($showWho ? '<span>👤 ' . h(user_name($t['assigned_to'])) . '</span>' : '<span></span>')
        . '<span class="' . ($late ? 'err-text' : 'muted') . '">' . h($t['status'] === 'done' && $t['done_at'] ? '✓ ' . fmt_date($t['done_at']) . ($late ? ' (late)' : '') : $due) . '</span></span>'
        . ($t['result_link'] !== '' ? '<span class="tc-link">🔗 Result attached</span>' : '')
        . '</a>';
}
