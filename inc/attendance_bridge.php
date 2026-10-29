<?php
// Add and change Attendance employees from the Staff page, through the attendance app's own code (its checks,
// locking and saving), so staff are added in one place. Needs the admin to be logged in (shared admin login).
declare(strict_types=1);

require_once __DIR__ . '/backup.php';

/** Call the attendance app as the logged-in admin: [status, data]. Status 0 = the attendance app is not installed. */
function attendance_call(string $method, string $path, array $body = []): array
{
    $root = APP_ROOT . '/attendance';
    $dir = attendance_data_dir();
    if ($dir === null || !is_file($root . '/includes/class-looma-app.php')) {
        return [0, ['error' => 'The attendance app is not installed.']];
    }
    if (!defined('LOOMA_ATT_CORE')) {
        define('LOOMA_ATT_CORE', true);
    }
    foreach (['includes/class-looma-time.php', 'includes/class-looma-calc.php', 'includes/class-looma-app.php', 'lib/class-looma-file-store.php'] as $f) {
        require_once $root . '/' . $f;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $seeds = [];
    if ((attendance_config()['seed'] ?? true) && is_readable($root . '/seed/looma-staff.php')) {
        $seeds[] = include $root . '/seed/looma-staff.php';
    }
    try {
        $app = new Looma_App(new Looma_File_Storage($dir), new Looma_File_Kv($dir), $seeds);
        [$status, $data] = $app->handle(['method' => $method, 'path' => $path, 'query' => [], 'body' => $body,
            'cookie' => '', 'device' => '', 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
        return [$status, $data];
    } catch (Throwable $e) {
        error_log('Looma attendance bridge: ' . $e->getMessage());
        return [500, ['error' => 'Attendance: ' . $e->getMessage()]];
    }
}

/** Attendance details posted from the Staff forms (fields named att[...]) → the attendance app's employee fields. */
function attendance_fields_from_post(string $name, bool $creating): array
{
    $a = (array)($_POST['att'] ?? []);
    $body = [
        'name' => $name,
        'position' => trim((string)($a['position'] ?? '')),
        'basicSalary' => trim((string)($a['basicSalary'] ?? '')) === '' ? 0 : str_replace(',', '', (string)$a['basicSalary']),
        'phone' => trim((string)($a['phone'] ?? '')),
        'email' => trim((string)($a['email'] ?? '')),
        'attendance' => !empty($a['attendance']),
        'incentive' => !empty($a['incentive']),
    ];
    if (!$creating) {
        $body['active'] = !empty($a['active']);
    }
    if (trim((string)($a['joinedOn'] ?? '')) !== '') {
        $body['joinedOn'] = trim((string)$a['joinedOn']);
    }
    if (trim((string)($a['pin'] ?? '')) !== '') {
        $body['pin'] = trim((string)$a['pin']);
    }
    return $body;
}

/** One attendance employee as the app shows it to the admin (no PIN), or null. */
function attendance_employee(string $id): ?array
{
    [$st, $list] = attendance_call('GET', '/api/admin/employees');
    foreach ($st === 200 && is_array($list) ? $list : [] as $e) {
        if (($e['id'] ?? '') === $id) {
            return $e;
        }
    }
    return null;
}

/** Remember that this account is this attendance person (Staff links). */
function save_staff_link(int $userId, string $personId): void
{
    $saved = json_decode((string)setting('staff_links', ''), true) ?: [];
    $saved[(string)$userId] = $personId;
    set_setting('staff_links', json_encode($saved));
}

/** The attendance form section used on Add staff and Edit staff. */
function attendance_form_fields(?array $e, bool $creating): string
{
    $v = fn(string $k, $d = '') => h((string)($_POST['att'][$k] ?? ($e[$k] ?? $d)));
    $chk = fn(string $k, bool $d) => (isset($_POST['att']) ? !empty($_POST['att'][$k]) : (bool)($e[$k] ?? $d)) ? 'checked' : '';
    return '<div class="att-fields grid">'
        . '<label class="field"><span class="lbl">Position</span><input name="att[position]" value="' . $v('position') . '" placeholder="e.g. Printer, Video editor"></label>'
        . '<label class="field"><span class="lbl">Basic salary (₹ a month)</span><input name="att[basicSalary]" type="number" min="0" step="1" inputmode="numeric" value="' . $v('basicSalary') . '" placeholder="e.g. 15000"></label>'
        . '<label class="field"><span class="lbl">Joined on</span><input name="att[joinedOn]" type="date" value="' . $v('joinedOn', $creating ? today() : '') . '"></label>'
        . '<label class="field"><span class="lbl">WhatsApp number <small class="muted">(payslips)</small></span><input name="att[phone]" inputmode="tel" value="' . $v('phone') . '" placeholder="10 digits"></label>'
        . '<label class="field"><span class="lbl">Email <small class="muted">(optional)</small></span><input name="att[email]" type="email" value="' . $v('email') . '"></label>'
        . '<label class="field"><span class="lbl">Clock-in PIN <small class="muted">(4–6 digits' . ($creating ? '' : ($e && !empty($e['hasPin']) ? ', leave empty to keep' : ', none yet')) . ')</small></span><input name="att[pin]" inputmode="numeric" pattern="\d{4,6}" maxlength="6" autocomplete="off"></label>'
        . '<label class="field check"><input type="checkbox" name="att[attendance]" value="1" ' . $chk('attendance', true) . '> Needs to clock in</label>'
        . '<label class="field check"><input type="checkbox" name="att[incentive]" value="1" ' . $chk('incentive', true) . '> Gets a share of the sales incentive</label>'
        . ($creating ? '' : '<label class="field check"><input type="checkbox" name="att[active]" value="1" ' . $chk('active', true) . '> Active in attendance</label>')
        . '</div>';
}
