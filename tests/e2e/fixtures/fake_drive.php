<?php
// Stand-in for Google OAuth + Drive v3 (just what the backup code uses). Files kept in ./fakedrive.
$store = __DIR__ . '/fakedrive';
if (!is_dir($store)) {
    mkdir($store);
}
$dbf = "$store/db.json";
$db = is_file($dbf) ? json_decode(file_get_contents($dbf), true) : ['files' => [], 'sessions' => [], 'n' => 0];
$save = function () use (&$db, $dbf) { file_put_contents($dbf, json_encode($db)); };
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = $_SERVER['REQUEST_METHOD'];
$json = function ($code, $data) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); exit; };
$auth = function () use ($json) { if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer TOKEN123') $json(401, ['error' => ['message' => 'bad token']]); };
if ($path === '/auth') { header('Location: ' . $_GET['redirect_uri'] . '?code=GOODCODE&state=' . urlencode($_GET['state'])); exit; }
if ($path === '/token') {
    if (($_POST['grant_type'] ?? '') === 'authorization_code') {
        $_POST['code'] === 'GOODCODE' && $_POST['client_secret'] === 'SECRET' ? $json(200, ['access_token' => 'TOKEN123', 'refresh_token' => 'REFRESH1']) : $json(400, ['error' => 'invalid_grant']);
    }
    ($_POST['refresh_token'] ?? '') === 'REFRESH1' ? $json(200, ['access_token' => 'TOKEN123']) : $json(400, ['error' => 'invalid_grant']);
}
if ($path === '/drive/v3/files' && $m === 'POST') { $auth(); $b = json_decode(file_get_contents('php://input'), true); $id = 'f' . (++$db['n']);
    $db['files'][$id] = ['id' => $id, 'name' => $b['name'], 'mime' => $b['mimeType'] ?? '', 'parents' => $b['parents'] ?? [], 'createdTime' => gmdate('Y-m-d\TH:i:s.000\Z'), 'size' => 0]; $save(); $json(200, ['id' => $id]); }
if ($path === '/upload/drive/v3/files' && $m === 'POST') { $auth(); $b = json_decode(file_get_contents('php://input'), true); $sid = 's' . (++$db['n']);
    $db['sessions'][$sid] = ['meta' => $b, 'total' => (int)$_SERVER['HTTP_X_UPLOAD_CONTENT_LENGTH'], 'got' => 0, 'chunks' => 0]; @unlink("$store/$sid.part"); $save();
    header('Location: http://' . $_SERVER['HTTP_HOST'] . '/upload/session/' . $sid); http_response_code(200); exit; }
if (preg_match('#^/upload/session/(s\d+)$#', $path, $mm) && $m === 'PUT') { $sid = $mm[1]; $s = &$db['sessions'][$sid];
    $data = file_get_contents('php://input'); preg_match('#bytes (\d+)-(\d+)/(\d+)#', $_SERVER['HTTP_CONTENT_RANGE'] ?? '', $r);
    if ((int)$r[1] !== $s['got']) $json(400, ['error' => ['message' => 'bad range']]);
    file_put_contents("$store/$sid.part", $data, FILE_APPEND); $s['got'] += strlen($data); $s['chunks']++;
    if ($s['got'] < $s['total']) { $save(); http_response_code(308); exit; }
    $id = 'f' . (++$db['n']); rename("$store/$sid.part", "$store/$id.bin");
    $db['files'][$id] = ['id' => $id, 'name' => $s['meta']['name'], 'parents' => $s['meta']['parents'], 'createdTime' => gmdate('Y-m-d\TH:i:s.') . sprintf('%03d', $db['n']) . 'Z', 'size' => (string)$s['total'], 'chunks' => $s['chunks']];
    unset($db['sessions'][$sid]); $save(); $json(200, ['id' => $id]); }
if ($path === '/drive/v3/files' && $m === 'GET') { $auth(); preg_match("#'([^']+)' in parents#", $_GET['q'], $q);
    $l = array_values(array_filter($db['files'], fn($f) => in_array($q[1], $f['parents'] ?? [], true)));
    usort($l, fn($a, $b) => strcmp($b['createdTime'], $a['createdTime']));
    $json(200, ['files' => array_map(fn($f) => ['id' => $f['id'], 'name' => $f['name'], 'size' => $f['size'], 'createdTime' => $f['createdTime'], 'webViewLink' => 'http://drive.example/' . $f['id']], $l)]); }
if (preg_match('#^/drive/v3/files/(f\d+)$#', $path, $mm)) { $auth(); $id = $mm[1];
    if ($m === 'DELETE') { unset($db['files'][$id]); @unlink("$store/$id.bin"); $save(); http_response_code(204); exit; }
    if (($_GET['alt'] ?? '') === 'media') { readfile("$store/$id.bin"); exit; } }
$json(404, ['error' => ['message' => "no route $m $path"]]);
