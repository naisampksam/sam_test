<?php
// Links each Orders / Tasks account to the same person in the Attendance app (they keep separate staff lists).
// Matched by name automatically; the admin can change any link on Setup → Staff links (saved in setting staff_links).
declare(strict_types=1);

require_once __DIR__ . '/backup.php'; // attendance_data_file()

/** Attendance staff: [id => ['id', 'name', 'position', 'active']] (empty without the attendance app). */
function attendance_people(): array
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $out = [];
    $db = attendance_db();
    foreach ($db['employees'] ?? [] as $e) {
        if (!empty($e['id'])) {
            $out[(string)$e['id']] = ['id' => (string)$e['id'], 'name' => (string)($e['name'] ?? ''), 'position' => (string)($e['position'] ?? ''), 'active' => ($e['active'] ?? true) !== false];
        }
    }
    return $out;
}

/** The attendance app's data (read only). */
function attendance_db(): array
{
    static $db = null;
    if ($db === null) {
        $file = attendance_data_file();
        $raw = $file ? (string)@file_get_contents($file) : '';
        $raw = preg_replace('/^<\?php exit; \?>\n/', '', $raw);
        $db = json_decode($raw, true) ?: [];
    }
    return $db;
}

/** "Nadeem  Anshin." → "nadeem anshin" */
function name_key(string $s): string
{
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N} ]+/u', ' ', mb_strtolower($s))));
}

/** Pairs the owner confirmed (attendance name ↔ account name, either way round); matched before anything else. */
const STAFF_LINK_AGREED = [['nadeem anshin', 'nadeem anshin'], ['fayis', 'fayiz'], ['vyshnav', 'vyshnav']];

/**
 * Best attendance person for each account, by name: same full name, then same first name (only one such person),
 * then the username is their first name, then a close spelling of the first name (to check: Faiz / Fayis).
 * Returns [user_id => ['id' => attendance id, 'how' => 'name' | 'first name' | 'username' | 'similar']]; each person once.
 */
function auto_staff_links(array $users, array $people): array
{
    $first = fn(string $k) => explode(' ', $k)[0] ?? '';
    $taken = [];
    $out = [];
    $agreed = function ($u, $p) {
        $a = name_key($u['name'] ?: $u['username']);
        $b = name_key($p['name']);
        foreach (STAFF_LINK_AGREED as [$x, $y]) {
            if (($a === $x || $a === $y) && ($b === $x || $b === $y)) {
                return true;
            }
        }
        return false;
    };
    $rules = [
        'agreed' => $agreed,
        'name' => fn($u, $p) => name_key($u['name'] ?: $u['username']) === name_key($p['name']),
        'first name' => fn($u, $p) => $first(name_key($u['name'] ?: $u['username'])) === $first(name_key($p['name'])),
        'username' => fn($u, $p) => name_key($u['username']) === $first(name_key($p['name'])),
        'similar' => function ($u, $p) use ($first) {
            $a = $first(name_key($u['name'] ?: $u['username']));
            $b = $first(name_key($p['name']));
            return mb_strlen($a) >= 4 && mb_strlen($b) >= 4 && $a[0] === $b[0] && levenshtein($a, $b) <= 2;
        },
    ];
    foreach ($rules as $how => $test) {
        foreach ($users as $uid => $u) {
            if (isset($out[$uid])) {
                continue;
            }
            $hits = array_values(array_filter($people, fn($p) => !isset($taken[$p['id']]) && $test($u, $p)));
            if (count($hits) === 1) { // only a clear match
                $out[$uid] = ['id' => $hits[0]['id'], 'how' => $how];
                $taken[$hits[0]['id']] = true;
            }
        }
    }
    return $out;
}

/** Links in use: what the admin saved, else the automatic match. [user_id => ['id' => attendance id or '', 'how' => …]] */
function staff_links(array $users): array
{
    $saved = json_decode((string)setting('staff_links', ''), true) ?: [];
    $people = attendance_people();
    $auto = auto_staff_links($users, $people);
    $out = [];
    foreach ($users as $uid => $u) {
        if (array_key_exists((string)$uid, $saved)) {
            $id = (string)$saved[(string)$uid];
            $out[$uid] = ['id' => isset($people[$id]) ? $id : '', 'how' => 'set by admin'];
        } else {
            $out[$uid] = $auto[$uid] ?? ['id' => '', 'how' => ''];
        }
    }
    return $out;
}

/** One person's attendance between two dates: ['days' => days with a clock-in, 'minutes' => worked]. */
function attendance_summary(string $personId, string $from, string $to): array
{
    $days = [];
    $min = 0;
    foreach (attendance_db()['sessions'] ?? [] as $s) {
        if (($s['employeeId'] ?? '') !== $personId || ($s['status'] ?? '') === 'rejected' || ($s['date'] ?? '') < $from || ($s['date'] ?? '') > $to) {
            continue;
        }
        $days[$s['date']] = true;
        if (!empty($s['in']) && !empty($s['out'])) {
            [$ih, $im] = array_map('intval', explode(':', (string)$s['in']));
            [$oh, $om] = array_map('intval', explode(':', (string)$s['out']));
            $min += max(0, ($oh * 60 + $om) - ($ih * 60 + $im));
        }
    }
    return ['days' => count($days), 'minutes' => $min];
}
