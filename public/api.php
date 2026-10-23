<?php

declare(strict_types=1);

/** Absensi QR Code VPS API — PHP 8.2+ and PDO_SQLITE, same-origin only. */
require_once dirname(__DIR__) . '/src/v7.php';
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

function response(array $body, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}
function failure(string $message, int $code = 400): never
{
    response(['error' => $message], $code);
}
function sameOrigin(): void
{
    // SameSite=Strict cookies and custom CSRF headers already protect write requests.
    // Also reject explicit cross-origin attempts.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $url = parse_url($origin);
        $originHost = strtolower((string)($url['host'] ?? ''));
        $originPort = $url['port'] ?? null;
        $hostHeader = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $originHostPort = $originHost . ($originPort ? ':' . $originPort : '');
        if ($originHostPort !== $hostHeader) failure('Permintaan lintas domain ditolak.', 403);
    }
}
function jsonInput(): array
{
    $size = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($size > 12 * 1024 * 1024) failure('Ukuran permintaan terlalu besar.', 413);
    $body = file_get_contents('php://input', false, null, 0, 12 * 1024 * 1024 + 1);
    if ($body === false || strlen($body) > 12 * 1024 * 1024) failure('Ukuran permintaan terlalu besar.', 413);
    try {
        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        failure('Format JSON tidak valid.');
    }
    if (!is_array($data)) failure('Format permintaan tidak valid.');
    return $data;
}
function db(): PDO
{
    if (!extension_loaded('pdo_sqlite')) failure('PHP pdo_sqlite belum aktif di server.', 503);
    $location = dirname(__DIR__) . '/data/absensi.sqlite';
    if (!is_dir(dirname($location)) || !is_writable(dirname($location))) {
        failure('Folder data belum siap. Hubungi administrator.', 503);
    }
    $db = new PDO('sqlite:' . $location, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $db->exec('PRAGMA busy_timeout=10000');
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS app_state (
        id INTEGER PRIMARY KEY CHECK(id=1), revision INTEGER NOT NULL, state_json TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS attendance (
        sid TEXT NOT NULL, pid TEXT NOT NULL, t INTEGER NOT NULL, m TEXT NOT NULL,
        PRIMARY KEY (sid,pid)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS attendance_time_idx ON attendance(t)');
    $db->exec('CREATE TABLE IF NOT EXISTS login_failures (
        ip_key TEXT NOT NULL, username TEXT NOT NULL, t INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS login_failures_idx ON login_failures(ip_key,username,t)');
    $default = [
        'ev' => '',
        'active' => null,
        'cert' => (object)[],
        'theme' => null,
        'mute' => false,
        'people' => [],
        'sessions' => [],
        'approved' => (object)[],
        'grades' => (object)[]
    ];
    $stmt = $db->prepare('INSERT OR IGNORE INTO app_state(id,revision,state_json) VALUES(1,0,?)');
    $stmt->execute([json_encode($default, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    return $db;
}
function state(PDO $db): array
{
    $row = $db->query('SELECT revision,state_json FROM app_state WHERE id=1')->fetch();
    $d = json_decode((string)$row['state_json'], true, 64, JSON_THROW_ON_ERROR);
    // Objects used as maps must remain JSON objects when empty, not [] on the client.
    foreach (['approved', 'grades', 'cert'] as $mapKey) {
        if (isset($d[$mapKey]) && $d[$mapKey] === []) $d[$mapKey] = (object)[];
    }
    $d['logs'] = $db->query('SELECT sid,pid,t,m FROM attendance ORDER BY t ASC')->fetchAll();
    $d['logs'] = array_map(static fn(array $r) => [
        'sid' => $r['sid'],
        'pid' => $r['pid'],
        't' => (int)$r['t'],
        'm' => $r['m']
    ], $d['logs']);
    $d['revision'] = (int)$row['revision'];
    return $d;
}
function validId(mixed $v): bool
{
    return is_string($v) && (bool)preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $v);
}
function validText(mixed $v, int $max): bool
{
    return is_string($v) && strlen($v) <= $max;
}
function validateState(mixed $d): void
{
    if (
        !is_array($d) || !isset($d['people'], $d['sessions'], $d['logs']) ||
        !is_array($d['people']) || !is_array($d['sessions']) || !is_array($d['logs']) ||
        count($d['people']) > 10000 || count($d['sessions']) > 1000 || count($d['logs']) > 100000
    ) {
        failure('Data peserta, sesi, atau kehadiran tidak valid.');
    }
    $people = [];
    $sessions = [];
    $att = [];
    foreach ($d['people'] as $p) {
        if (
            !is_array($p) || !validId($p['id'] ?? null) || !validText($p['name'] ?? null, 200) ||
            trim($p['name']) === '' || !validText($p['unit'] ?? '', 300) || isset($people[$p['id']])
        ) {
            failure('Peserta memiliki ID atau nama tidak valid/duplikat.');
        }
        $people[$p['id']] = true;
    }
    foreach ($d['sessions'] as $s) {
        if (
            !is_array($s) || !validId($s['id'] ?? null) || !validText($s['name'] ?? null, 300) ||
            trim($s['name']) === '' || !validText($s['start'] ?? '', 24) ||
            !validText($s['end'] ?? '', 24) || isset($sessions[$s['id']])
        ) {
            failure('Sesi memiliki ID atau nama tidak valid/duplikat.');
        }
        $sessions[$s['id']] = true;
    }
    if (($d['active'] ?? null) !== null && (!validId($d['active']) || !isset($sessions[$d['active']]))) failure('Sesi aktif tidak ditemukan.');
    foreach ($d['logs'] as $l) {
        if (
            !is_array($l) || !validId($l['pid'] ?? null) || !validId($l['sid'] ?? null) ||
            !isset($people[$l['pid']], $sessions[$l['sid']]) ||
            !is_numeric($l['t'] ?? null) || (float)$l['t'] < 946684800000 || (float)$l['t'] > 4102444800000 ||
            !validText($l['m'] ?? 'scan', 30)
        ) failure('Terdapat baris kehadiran tidak valid.');
        $key = $l['sid'] . '|' . $l['pid'];
        if (isset($att[$key])) failure('Kehadiran ganda tidak diizinkan.');
        $att[$key] = true;
    }
    if (!validText($d['ev'] ?? '', 300)) failure('Nama kegiatan terlalu panjang.');
    foreach (['approved', 'grades', 'cert'] as $field) {
        if (isset($d[$field]) && (!is_array($d[$field]) || array_is_list($d[$field]) && count($d[$field]) > 0)) {
            failure('Format ' . $field . ' tidak valid.');
        }
    }
}
function loginThrottle(PDO $db, string $ip, string $user): void
{
    $db->prepare('DELETE FROM login_failures WHERE t < ?')->execute([time() - 86400]);
    $stmt = $db->prepare('SELECT COUNT(*) FROM login_failures WHERE ip_key=? AND username=? AND t>=?');
    $stmt->execute([$ip, $user, time() - 900]);
    if ((int)$stmt->fetchColumn() >= 8) failure('Terlalu banyak percobaan login. Coba lagi 15 menit.', 429);
}
function assertAuth(): void
{
    if (empty($_SESSION['auth']) || time() - (int)($_SESSION['seen'] ?? 0) > 14400) {
        unset($_SESSION['auth']);
        failure('Sesi login berakhir. Muat ulang dan login kembali.', 401);
    }
    $_SESSION['seen'] = time();
    if (!empty($_SESSION['user_id']) && $_SESSION['user_id'] !== 'legacy-admin') {
        $check = db()->prepare('SELECT enabled,role FROM v7_users WHERE id=?');
        $check->execute([$_SESSION['user_id']]);
        $record=$check->fetch();
        if (!$record || !$record['enabled']) { unset($_SESSION['auth']); failure('Akun telah dinonaktifkan.',401); }
        $_SESSION['role'] = $record['role'];
    }
}
function assertCsrf(): void
{
    $got = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!$got || !hash_equals((string)($_SESSION['csrf'] ?? ''), $got)) failure('Token keamanan tidak sesuai.', 403);
}
function writeCommit(PDO $db, array $payload): array
{
    $d = $payload['state'] ?? null;
    validateState($d);
    $expected = $payload['revision'] ?? null;
    if (!is_int($expected) && !ctype_digit((string)$expected)) failure('Revisi tidak valid.');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $revision = (int)$db->query('SELECT revision FROM app_state WHERE id=1')->fetchColumn();
        if ((int)$expected !== $revision) {
            $present = state($db);
            $db->exec('ROLLBACK');
            return ['conflict' => true, 'state' => $present];
        }
        $stmt = $db->prepare('DELETE FROM attendance');
        $stmt->execute();
        $ins = $db->prepare('INSERT INTO attendance(sid,pid,t,m) VALUES(?,?,?,?)');
        foreach ($d['logs'] as $l) $ins->execute([$l['sid'], $l['pid'], (int)$l['t'], (string)($l['m'] ?? 'scan')]);
        unset($d['logs'], $d['revision']);
        foreach (['approved', 'grades', 'cert'] as $mapKey) {
            if (isset($d[$mapKey]) && $d[$mapKey] === []) $d[$mapKey] = (object)[];
        }
        $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $db->prepare('UPDATE app_state SET state_json=?, revision=? WHERE id=1');
        $stmt->execute([$json, $revision + 1]);
        $db->exec('COMMIT');
        return ['ok' => true, 'revision' => $revision + 1];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
}
function writeScan(PDO $db, array $payload): array
{
    $sid = $payload['sid'] ?? null;
    $pid = $payload['pid'] ?? null;
    if (!validId($sid) || !validId($pid)) failure('ID peserta/sesi tidak valid.');
    $m = (string)($payload['m'] ?? 'scan');
    if (!validText($m, 30)) failure('Metode scan tidak valid.');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $row = $db->query('SELECT revision,state_json FROM app_state WHERE id=1')->fetch();
        $d = json_decode((string)$row['state_json'], true, 64, JSON_THROW_ON_ERROR);
        $d['revision'] = (int)$row['revision'];
        if (($d['active'] ?? null) !== $sid) failureWithRollback($db, 'Sesi aktif berubah. Muat ulang aplikasi.', 409);
        if (!in_array($pid, array_column($d['people'], 'id'), true)) failureWithRollback($db, 'Peserta tidak terdaftar. Muat ulang aplikasi.', 404);
        $stmt = $db->prepare('SELECT t FROM attendance WHERE sid=? AND pid=?');
        $stmt->execute([$sid, $pid]);
        $old = $stmt->fetchColumn();
        if ($old !== false) {
            $db->exec('COMMIT');
            return ['st' => 'dup', 't' => (int)$old, 'revision' => $d['revision']];
        }
        $ts = (int)round(microtime(true) * 1000);
        $db->prepare('INSERT INTO attendance(sid,pid,t,m) VALUES(?,?,?,?)')->execute([$sid, $pid, $ts, $m]);
        $db->prepare('UPDATE app_state SET revision=revision+1 WHERE id=1')->execute();
        $db->exec('COMMIT');
        return ['st' => 'ok', 't' => $ts, 'revision' => $d['revision'] + 1];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
}
function failureWithRollback(PDO $db, string $message, int $code): never
{
    if ($db->inTransaction()) $db->exec('ROLLBACK');
    failure($message, $code);
}
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') failure('Gunakan POST.', 405);
    sameOrigin();
    $configPath = dirname(__DIR__) . '/private/config.php';
    if (!is_file($configPath)) failure('Konfigurasi server belum dibuat.', 503);
    $config = require $configPath;
    if (!is_array($config) || empty($config['username']) || empty($config['password_hash'])) failure('Konfigurasi login belum lengkap.', 503);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('ABSENSI_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
    if (str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''),'multipart/form-data')) {
        assertAuth();assertCsrf();
        if (!v7Ready(db())) failure('Migrasi V7 belum dijalankan.',503);
        if (($_POST['action'] ?? '') !== 'v7.materials.upload') failure('Aksi upload tidak dikenal.',404);
        response(v7Upload(db()));
    }
    $request = jsonInput();
    $action = (string)($request['action'] ?? '');
    $payload = $request['payload'] ?? [];
    if (!is_array($payload)) failure('Payload tidak valid.');
    if ($action === 'session') {
        $logged = !empty($_SESSION['auth']) && time() - (int)($_SESSION['seen'] ?? 0) <= 14400;
        response(['authenticated' => $logged, 'csrf' => $logged ? ($_SESSION['csrf'] ?? null) : null, 'role' => $logged ? ($_SESSION['role'] ?? 'admin') : null, 'v7' => v7Ready(db())]);
    }
    if ($action === 'login') {
        $username = (string)($payload['username'] ?? '');
        $password = (string)($payload['password'] ?? '');
        if (strlen($username) > 100 || strlen($password) > 1024) failure('Input login terlalu panjang.');
        $db = db();
        $ip = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        loginThrottle($db, $ip, strtolower($username));
        $found = false;
        $uid = 'legacy-admin'; $role = 'admin';
        if (v7Ready($db)) {
            $row=$db->prepare('SELECT id,role,password_hash,enabled FROM v7_users WHERE username=?');
            $row->execute([$username]); $user=$row->fetch();
            if ($user && (int)$user['enabled']===1 && password_verify($password,$user['password_hash'])) {
                $found=true;$uid=$user['id'];$role=$user['role'];
            }
        }
        if (!$found && hash_equals((string)$config['username'],$username) && password_verify($password,(string)$config['password_hash'])) {
            $found=true;$uid='legacy-admin';$role='admin';
        }
        if (!$found) {
            $db->prepare('INSERT INTO login_failures(ip_key,username,t) VALUES(?,?,?)')->execute([$ip, strtolower($username), time()]);
            failure('Username atau password salah.', 401);
        }
        $db->prepare('DELETE FROM login_failures WHERE ip_key=? AND username=?')->execute([$ip, strtolower($username)]);
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['user_id'] = $uid;
        $_SESSION['user'] = $username;
        $_SESSION['role'] = $role;
        unset($_SESSION['event_id']);
        $_SESSION['seen'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        response(['ok' => true, 'csrf' => $_SESSION['csrf']]);
    }
    assertAuth();
    if ($action === 'load') response(v7Ready(db()) ? v7State(db()) : state(db()));
    assertCsrf();
    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        response(['ok' => true]);
    }
    if (str_starts_with($action, 'v7.')) {
        if (!v7Ready(db())) failure('Migrasi V7 belum dijalankan.', 503);
        response(v7Action(db(), $action, $payload));
    }
    if ($action === 'commit') response(v7Ready(db()) ? v7Commit(db(),$payload) : writeCommit(db(), $payload));
    if ($action === 'scan') response(v7Ready(db()) ? v7Scan(db(),$payload) : writeScan(db(), $payload));
    failure('Aksi tidak dikenal.', 404);
} catch (PDOException $e) {
    error_log('Absensi DB: ' . $e->getMessage());
    failure('Database sedang bermasalah. Coba lagi.', 503);
} catch (Throwable $e) {
    error_log('Absensi error: ' . $e->getMessage());
    failure('Terjadi kesalahan server.', 500);
}
