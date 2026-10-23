<?php

/** Absensi PKD V7: additive event storage, roles, scoring, and audit. No public file access. */

declare(strict_types=1);

function v7Ready(PDO $db): bool
{
    return (bool)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='v7_events'")->fetchColumn();
}
function v7Id(string $prefix): string
{
    return $prefix . bin2hex(random_bytes(10));
}
function v7Now(): int
{
    return (int)round(microtime(true) * 1000);
}
function v7Defaults(): array
{
    return [
        'ev' => '',
        'active' => null,
        'cert' => ['thr' => 75, 'subj' => '', 'num' => '{n}/PKD/2026', 'place' => '', 'date' => ''],
        'theme' => null,
        'mute' => false,
        'people' => [],
        'sessions' => [],
        'approved' => [],
        'grades' => [],
        'sg' => [],
        'mt' => [],
        'aliases' => [],
        'id_config' => ['prefix' => 'PKD', 'suffix' => 'NGK']
    ];
}
function v7Audit(PDO $db, string $action, string $eventId, array $details = []): void
{
    $st = $db->prepare('INSERT INTO v7_audit(id,at_ms,actor,role,event_id,action,details_json) VALUES(?,?,?,?,?,?,?)');
    $st->execute([v7Id('a_'), v7Now(), (string)($_SESSION['user'] ?? 'migration'), (string)($_SESSION['role'] ?? 'system'), $eventId, $action, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
}
function v7Migrate(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS v7_events(id TEXT PRIMARY KEY,name TEXT NOT NULL,archived INTEGER NOT NULL DEFAULT 0,created_ms INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS v7_state(event_id TEXT PRIMARY KEY REFERENCES v7_events(id),revision INTEGER NOT NULL,state_json TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS v7_attendance(event_id TEXT NOT NULL REFERENCES v7_events(id),sid TEXT NOT NULL,pid TEXT NOT NULL,t INTEGER NOT NULL,m TEXT NOT NULL,PRIMARY KEY(event_id,sid,pid))');
    $db->exec('CREATE INDEX IF NOT EXISTS v7_att_idx ON v7_attendance(event_id,t)');
    $db->exec('CREATE TABLE IF NOT EXISTS v7_users(id TEXT PRIMARY KEY,username TEXT NOT NULL UNIQUE,name TEXT NOT NULL,role TEXT NOT NULL CHECK(role IN (\'admin\',\'scanner\',\'assessor\')),password_hash TEXT NOT NULL,enabled INTEGER NOT NULL DEFAULT 1)');
    $db->exec('CREATE TABLE IF NOT EXISTS v7_user_events(user_id TEXT NOT NULL,event_id TEXT NOT NULL,PRIMARY KEY(user_id,event_id))');
    $db->exec('CREATE TABLE IF NOT EXISTS v7_materials(id TEXT PRIMARY KEY,event_id TEXT NOT NULL,sid TEXT NOT NULL,title TEXT NOT NULL,kind TEXT NOT NULL CHECK(kind IN (\'link\',\'file\')),uri TEXT,path TEXT,mime TEXT,created_ms INTEGER NOT NULL,actor TEXT NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS v7_mat_idx ON v7_materials(event_id,sid)');
    $db->exec('CREATE TABLE IF NOT EXISTS v7_audit(id TEXT PRIMARY KEY,at_ms INTEGER NOT NULL,actor TEXT NOT NULL,role TEXT NOT NULL,event_id TEXT NOT NULL,action TEXT NOT NULL,details_json TEXT NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS v7_audit_idx ON v7_audit(event_id,at_ms)');
    $db->exec('CREATE TABLE IF NOT EXISTS v7_imports(hash TEXT PRIMARY KEY,event_id TEXT NOT NULL,at_ms INTEGER NOT NULL,actor TEXT NOT NULL)');
    $legacy = $db->query('SELECT revision,state_json FROM app_state WHERE id=1')->fetch();
    if (!$legacy) throw new RuntimeException('app_state legacy belum tersedia; jangan membuat V7 tanpa baseline.');
    $count = (int)$db->query('SELECT COUNT(*) FROM v7_events')->fetchColumn();
    if ($count === 0) {
        $data = json_decode((string)$legacy['state_json'], true, 64, JSON_THROW_ON_ERROR);
        $data = array_replace(v7Defaults(), $data);
        if (isset($data['cert']['pin'])) unset($data['cert']['pin']); // Legacy plaintext PIN no longer authoritative.
        $id = 'legacy_1';
        $db->prepare('INSERT INTO v7_events(id,name,created_ms) VALUES(?,?,?)')->execute([$id, trim((string)($data['ev'] ?? '')) ?: 'Kegiatan Lama', v7Now()]);
        unset($data['logs'], $data['revision']);
        $db->prepare('INSERT INTO v7_state(event_id,revision,state_json) VALUES(?,?,?)')->execute([$id, (int)$legacy['revision'], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $old = $db->query('SELECT sid,pid,t,m FROM attendance')->fetchAll(PDO::FETCH_ASSOC);
        $st = $db->prepare('INSERT OR IGNORE INTO v7_attendance(event_id,sid,pid,t,m) VALUES(?,?,?,?,?)');
        foreach ($old as $l) $st->execute([$id, $l['sid'], $l['pid'], (int)$l['t'], $l['m']]);
        v7Audit($db, 'migration.legacy', $id, ['people' => count($data['people'] ?? []), 'sessions' => count($data['sessions'] ?? []), 'logs' => count($old)]);
    }
}
function v7Role(): string
{
    return (string)($_SESSION['role'] ?? 'admin');
}
function v7Require(array $roles): void
{
    if (!in_array(v7Role(), $roles, true)) failure('Akses tidak diperbolehkan untuk peran ini.', 403);
}
function v7Event(PDO $db): string
{
    $id = (string)($_SESSION['event_id'] ?? 'legacy_1');
    $st = $db->prepare('SELECT archived FROM v7_events WHERE id=?');
    $st->execute([$id]);
    $found = $st->fetchColumn();
    if ($found === false || (int)$found === 1) {
        $id = (string)$db->query('SELECT id FROM v7_events WHERE archived=0 ORDER BY created_ms LIMIT 1')->fetchColumn();
        if ($id === '') failure('Belum ada kegiatan aktif.', 404);
        $_SESSION['event_id'] = $id;
    }
    if (v7Role() !== 'admin') {
        $st = $db->prepare('SELECT 1 FROM v7_user_events WHERE user_id=? AND event_id=?');
        $st->execute([$_SESSION['user_id'] ?? '', $id]);
        if (!$st->fetchColumn()) {
            // An operator may be assigned to a newly created event, not legacy_1.
            $st = $db->prepare('SELECT e.id FROM v7_events e JOIN v7_user_events ue ON ue.event_id=e.id WHERE ue.user_id=? AND e.archived=0 ORDER BY e.created_ms LIMIT 1');
            $st->execute([$_SESSION['user_id'] ?? '']);
            $alt = $st->fetchColumn();
            if (!$alt) failure('Akun belum diberi akses ke kegiatan. Hubungi Admin.', 403);
            $id = (string)$alt;
            $_SESSION['event_id'] = $id;
        }
    }
    return $id;
}
function v7StateRaw(PDO $db, string $id): array
{
    $st = $db->prepare('SELECT revision,state_json FROM v7_state WHERE event_id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) failure('Data kegiatan tidak ditemukan.', 404);
    $data = array_replace(v7Defaults(), json_decode($row['state_json'], true, 64, JSON_THROW_ON_ERROR));
    $data['revision'] = (int)$row['revision'];
    return $data;
}
function v7Logs(PDO $db, string $id): array
{
    $s = $db->prepare('SELECT sid,pid,t,m FROM v7_attendance WHERE event_id=? ORDER BY t');
    $s->execute([$id]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['t'] = (int)$r['t'];
    unset($r);
    return $rows;
}
function v7State(PDO $db): array
{
    $id = v7Event($db);
    $d = v7StateRaw($db, $id);
    $d['logs'] = v7Logs($db, $id);
    foreach (['approved', 'grades', 'sg', 'mt', 'cert'] as $key) if (($d[$key] ?? null) === []) $d[$key] = (object)[];
    $d['event_id'] = $id;
    $d['role'] = v7Role();
    // Existing certificate template reads grades[participantId][sessionLabel]. Keep historical grades untouched when no per-session score exists.
    $grades = (array)($d['grades'] ?? []);
    foreach ((array)($d['sg'] ?? []) as $sid => $pScores) {
        $session = null;
        foreach ($d['sessions'] as $ss) {
            if (($ss['id'] ?? '') === $sid) {
                $session = $ss;
                break;
            }
        }
        if (!$session) continue;
        $label = explode(' – ', (string)$session['name']);
        $label = count($label) > 1 ? implode(' – ', array_slice($label, 1)) : $label[0];
        foreach ((array)$pScores as $pid => $score) {
            if (!is_numeric($score) || $score < 0 || $score > 100) continue;
            $grades[$pid][$label] = round((float)$score, 2);
        }
    }
    $d['grades'] = (object)$grades;
    return $d;
}
function v7SaveState(PDO $db, string $id, array $d): int
{
    unset($d['logs'], $d['revision'], $d['event_id'], $d['role']);
    $s = $db->prepare('UPDATE v7_state SET state_json=?,revision=revision+1 WHERE event_id=?');
    $s->execute([json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $id]);
    $s = $db->prepare('SELECT revision FROM v7_state WHERE event_id=?');
    $s->execute([$id]);
    return (int)$s->fetchColumn();
}
function v7SameLogset(array $a, array $b): bool
{
    $key = static fn($l) => (string)($l['sid'] ?? '') . '|' . (string)($l['pid'] ?? '') . '|' . (string)($l['t'] ?? '');
    $x = array_map($key, $a);
    $y = array_map($key, $b);
    sort($x);
    sort($y);
    return $x === $y;
}
function v7Commit(PDO $db, array $p): array
{
    v7Require(['admin']);
    $id = v7Event($db);
    $incoming = $p['state'] ?? null;
    if (!is_array($incoming)) failure('Data tidak valid.', 422);
    validateState($incoming);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $now = v7StateRaw($db, $id);
        $rev = (int)($p['revision'] ?? -1);
        if ($rev !== $now['revision']) {
            $remote = v7State($db);
            $db->exec('ROLLBACK');
            return ['conflict' => true, 'state' => $remote];
        }
        // Logs are server-controlled: old browser snapshots may never replace/remove attendance.
        if (!v7SameLogset($incoming['logs'], v7Logs($db, $id))) failureWithRollback($db, 'Kehadiran hanya boleh diubah melalui API admin.', 409);
        $existingPeople = array_column($now['people'], 'id');
        $newPeople = array_column($incoming['people'], 'id');
        $existingSessions = array_column($now['sessions'], 'id');
        $newSessions = array_column($incoming['sessions'], 'id');
        if (array_diff($existingPeople, $newPeople) || array_diff($existingSessions, $newSessions)) failureWithRollback($db, 'Untuk menjaga data, penghapusan peserta/sesi tidak diizinkan melalui simpan biasa. Gunakan arsip/migrasi.', 409);
        foreach (['sg', 'mt'] as $key) if (($incoming[$key] ?? []) !== ($now[$key] ?? [])) failureWithRollback($db, 'Perubahan ' . $key . ' harus melalui modul V7.', 403);
        foreach (['people', 'sessions', 'active', 'ev', 'cert', 'approved', 'grades', 'theme', 'mute'] as $key) if (array_key_exists($key, $incoming)) $now[$key] = $incoming[$key];
        $revision = v7SaveState($db, $id, $now);
        $db->prepare('UPDATE v7_events SET name=? WHERE id=?')->execute([trim((string)$now['ev']) ?: 'Kegiatan', $id]);
        v7Audit($db, 'legacy.commit', $id, ['revision' => $revision]);
        $db->exec('COMMIT');
        return ['ok' => true, 'revision' => $revision];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
}
function v7Scan(PDO $db, array $p): array
{
    v7Require(['admin', 'scanner']);
    $id = v7Event($db);
    $sid = $p['sid'] ?? null;
    $pid = $p['pid'] ?? null;
    if (!validId($sid) || !validId($pid)) failure('ID peserta/sesi tidak valid.', 422);
    $m = (string)($p['m'] ?? 'scan');
    if (!in_array($m, ['scan', 'manual', 'usb', 'foto', 'camera', 'qr', 'image', 'file'], true)) failure('Metode scan tidak dikenal.', 422);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $state = v7StateRaw($db, $id);
        if (($state['active'] ?? null) !== $sid) failureWithRollback($db, 'Sesi aktif telah berubah.', 409);
        if (!in_array($sid, array_column($state['sessions'], 'id'), true)) failureWithRollback($db, 'Sesi tidak dikenal.', 404);
        $pid = (string)($state['aliases'][$pid] ?? $pid);
        if (!in_array($pid, array_column($state['people'], 'id'), true)) failureWithRollback($db, 'Peserta tidak terdaftar di kegiatan ini.', 404);
        $st = $db->prepare('SELECT t FROM v7_attendance WHERE event_id=? AND sid=? AND pid=?');
        $st->execute([$id, $sid, $pid]);
        $old = $st->fetchColumn();
        if ($old !== false) {
            $db->exec('COMMIT');
            return ['st' => 'dup', 't' => (int)$old, 'revision' => $state['revision']];
        }
        $t = v7Now();
        $db->prepare('INSERT INTO v7_attendance(event_id,sid,pid,t,m) VALUES(?,?,?,?,?)')->execute([$id, $sid, $pid, $t, $m]);
        $db->prepare('UPDATE v7_state SET revision=revision+1 WHERE event_id=?')->execute([$id]);
        v7Audit($db, 'attendance.scan', $id, ['sid' => $sid, 'pid' => $pid, 'method' => $m]);
        $db->exec('COMMIT');
        return ['st' => 'ok', 't' => $t, 'revision' => $state['revision'] + 1];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
}
function v7String(mixed $value, int $max = 200): string
{
    if (!is_string($value)) failure('Teks tidak valid.', 422);
    $value = trim($value);
    if ($value === '' || mb_strlen($value) > $max) failure('Teks wajib diisi dan tidak boleh terlalu panjang.', 422);
    return $value;
}
function v7Refresh(PDO $db, string $id): int
{
    $db->prepare('UPDATE v7_state SET revision=revision+1 WHERE event_id=?')->execute([$id]);
    $st = $db->prepare('SELECT revision FROM v7_state WHERE event_id=?');
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}
function v7Action(PDO $db, string $action, array $p): array
{
    if ($action === 'v7.events.list') {
        $rows = $db->query('SELECT id,name,archived,created_ms FROM v7_events ORDER BY created_ms DESC')->fetchAll();
        if (v7Role() !== 'admin') {
            $st = $db->prepare('SELECT e.id,e.name,e.archived,e.created_ms FROM v7_events e JOIN v7_user_events ue ON ue.event_id=e.id WHERE ue.user_id=? ORDER BY e.created_ms DESC');
            $st->execute([$_SESSION['user_id'] ?? '']);
            $rows = $st->fetchAll();
        }
        return ['events' => $rows, 'current' => v7Event($db), 'role' => v7Role(), 'user' => $_SESSION['user'] ?? ''];
    }
    if ($action === 'v7.events.switch') {
        $id = v7String($p['id'] ?? null, 100);
        $st = $db->prepare('SELECT archived FROM v7_events WHERE id=?');
        $st->execute([$id]);
        $a = $st->fetchColumn();
        if ($a === false || (int)$a === 1) failure('Kegiatan tidak tersedia.', 404);
        if (v7Role() !== 'admin') {
            $st = $db->prepare('SELECT 1 FROM v7_user_events WHERE user_id=? AND event_id=?');
            $st->execute([$_SESSION['user_id'] ?? '', $id]);
            if (!$st->fetchColumn()) failure('Tidak ada akses ke kegiatan.', 403);
        }
        $_SESSION['event_id'] = $id;
        return ['ok' => true, 'event_id' => $id];
    }
    if ($action === 'v7.events.create') {
        v7Require(['admin']);
        $name = v7String($p['name'] ?? null, 300);
        $id = v7Id('ev_');
        $d = v7Defaults();
        $d['ev'] = $name;
        $prefix = strtoupper(trim((string)($p['prefix'] ?? 'PKD')));
        $suffix = strtoupper(trim((string)($p['suffix'] ?? 'NGK')));
        if (!preg_match('/^[A-Z0-9]{2,8}$/D', $prefix) || !preg_match('/^[A-Z0-9]{2,8}$/D', $suffix)) failure('Prefix/suffix ID harus 2–8 karakter alfanumerik.', 422);
        $d['id_config'] = ['prefix' => $prefix, 'suffix' => $suffix];
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->prepare('INSERT INTO v7_events(id,name,created_ms) VALUES(?,?,?)')->execute([$id, $name, v7Now()]);
            $db->prepare('INSERT INTO v7_state(event_id,revision,state_json) VALUES(?,0,?)')->execute([$id, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            v7Audit($db, 'event.create', $id, ['name' => $name]);
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->exec('ROLLBACK');
            throw $e;
        }
        return ['ok' => true, 'id' => $id];
    }
    if ($action === 'v7.events.rename' || $action === 'v7.events.archive') {
        v7Require(['admin']);
        $id = v7String($p['id'] ?? null, 100);
        $db->exec('BEGIN IMMEDIATE');
        try {
            if ($action === 'v7.events.rename') {
                $name = v7String($p['name'] ?? null, 300);
                $st = $db->prepare('UPDATE v7_events SET name=? WHERE id=? AND archived=0');
                $st->execute([$name, $id]);
                $d = v7StateRaw($db, $id);
                $d['ev'] = $name;
                v7SaveState($db, $id, $d);
            } else {
                if ((int)$db->query('SELECT COUNT(*) FROM v7_events WHERE archived=0')->fetchColumn() < 2) failureWithRollback($db, 'Setidaknya satu kegiatan harus tetap aktif.', 409);
                $st = $db->prepare('UPDATE v7_events SET archived=1 WHERE id=? AND archived=0');
                $st->execute([$id]);
            }
            if (!$st->rowCount()) failureWithRollback($db, 'Kegiatan tidak ditemukan.', 404);
            v7Audit($db, $action, $id, $p);
            $db->exec('COMMIT');
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->exec('ROLLBACK');
            throw $e;
        }
    }
    if ($action === 'v7.users.list') {
        v7Require(['admin']);
        return ['users' => $db->query('SELECT id,username,name,role,enabled FROM v7_users ORDER BY username')->fetchAll(), 'assignments' => $db->query('SELECT user_id,event_id FROM v7_user_events')->fetchAll()];
    }
    if ($action === 'v7.users.create') {
        v7Require(['admin']);
        $username = v7String($p['username'] ?? null, 100);
        $name = v7String($p['name'] ?? null, 200);
        $password = (string)($p['password'] ?? '');
        $role = (string)($p['role'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._-]{3,100}$/D', $username) || strlen($password) < 12 || strlen($password) > 256 || !in_array($role, ['admin', 'scanner', 'assessor'], true)) failure('Username, role, atau password minimal 12 karakter tidak valid.', 422);
        $id = v7Id('u_');
        $db->prepare('INSERT INTO v7_users(id,username,name,role,password_hash,enabled) VALUES(?,?,?,?,?,1)')->execute([$id, $username, $name, $role, password_hash($password, PASSWORD_DEFAULT)]);
        v7Audit($db, 'users.create', '', ['username' => $username, 'role' => $role]);
        return ['ok' => true, 'id' => $id];
    }
    if ($action === 'v7.users.assign' || $action === 'v7.users.disable') {
        v7Require(['admin']);
        $uid = v7String($p['user_id'] ?? null, 100);
        if ($action === 'v7.users.disable') {
            $db->prepare('UPDATE v7_users SET enabled=0 WHERE id=?')->execute([$uid]);
        } else {
            $event = v7String($p['event_id'] ?? null, 100);
            $st = $db->prepare('SELECT 1 FROM v7_events WHERE id=?');
            $st->execute([$event]);
            if (!$st->fetchColumn()) failure('Kegiatan tidak ditemukan.', 404);
            $db->prepare('INSERT OR IGNORE INTO v7_user_events(user_id,event_id) VALUES(?,?)')->execute([$uid, $event]);
        }
        v7Audit($db, $action, '', $p);
        return ['ok' => true];
    }
    $id = v7Event($db);
    if ($action === 'v7.participants.add') {
        v7Require(['admin']);
        $name = v7String($p['name'] ?? null, 200);
        $unit = trim((string)($p['unit'] ?? ''));
        if (strlen($unit) > 300) failure('Asal/PAC terlalu panjang.', 422);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $d = v7StateRaw($db, $id);
            $config = $d['id_config'] ?? [];
            $prefix = strtoupper((string)($config['prefix'] ?? 'PKD'));
            $suffix = strtoupper((string)($config['suffix'] ?? 'NGK'));
            if (!preg_match('/^[A-Z0-9]{2,8}$/D', $prefix) || !preg_match('/^[A-Z0-9]{2,8}$/D', $suffix)) failureWithRollback($db, 'Konfigurasi ID tidak valid.', 422);
            $year = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('y');
            $n = 1;
            $used = array_column($d['people'], 'id');
            $used = array_merge($used, array_keys((array)($d['aliases'] ?? [])));
            do {
                $code = $prefix . str_pad((string)$n, 2, '0', STR_PAD_LEFT) . $year . $suffix;
                $n++;
                if ($n > 99999) failureWithRollback($db, 'Nomor peserta habis.', 409);
            } while (in_array($code, $used, true));
            $d['people'][] = ['id' => $code, 'name' => $name, 'unit' => $unit];
            $rev = v7SaveState($db, $id, $d);
            v7Audit($db, 'participant.add', $id, ['id' => $code, 'name' => $name]);
            $db->exec('COMMIT');
            return ['ok' => true, 'id' => $code, 'revision' => $rev];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->exec('ROLLBACK');
            throw $e;
        }
    }
    if ($action === 'v7.participants.rename_id') {
        v7Require(['admin']);
        $old = v7String($p['old_id'] ?? null, 80);
        $new = v7String($p['new_id'] ?? null, 80);
        if (!validId($new)) failure('ID baru tidak valid.', 422);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $d = v7StateRaw($db, $id);
            $idx = array_search($old, array_column($d['people'], 'id'), true);
            if ($idx === false || in_array($new, array_column($d['people'], 'id'), true) || isset($d['aliases'][$new])) failureWithRollback($db, 'ID tidak ditemukan atau sudah digunakan.', 409);
            $d['people'][$idx]['id'] = $new;
            $d['aliases'][$old] = $new;
            foreach (['approved', 'grades'] as $field) if (isset($d[$field][$old])) {
                $d[$field][$new] = $d[$field][$old];
                unset($d[$field][$old]);
            }
            foreach ((array)($d['sg'] ?? []) as $sid => $_) if (isset($d['sg'][$sid][$old])) {
                $d['sg'][$sid][$new] = $d['sg'][$sid][$old];
                unset($d['sg'][$sid][$old]);
            }
            $st = $db->prepare('SELECT sid FROM v7_attendance WHERE event_id=? AND pid=?');
            $st->execute([$id, $new]);
            if ($st->fetchColumn()) failureWithRollback($db, 'Ada konflik presensi untuk ID tujuan.', 409);
            $db->prepare('UPDATE v7_attendance SET pid=? WHERE event_id=? AND pid=?')->execute([$new, $id, $old]);
            // Update chained legacy aliases as well.
            foreach ($d['aliases'] as $alias => $target) if ($target === $old) $d['aliases'][$alias] = $new;
            $rev = v7SaveState($db, $id, $d);
            v7Audit($db, 'participant.rename_id', $id, ['old' => $old, 'new' => $new]);
            $db->exec('COMMIT');
            return ['ok' => true, 'revision' => $rev];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->exec('ROLLBACK');
            throw $e;
        }
    }
    if ($action === 'v7.attendance.delete') {
        v7Require(['admin']);
        $scope = (string)($p['scope'] ?? '');
        $reason = v7String($p['reason'] ?? null, 300);
        if (mb_strlen($reason) < 8) failure('Alasan minimal 8 karakter.', 422);
        if (!in_array($scope, ['one', 'session', 'event'], true)) failure('Cakupan penghapusan tidak valid.', 422);
        $sid = (string)($p['sid'] ?? '');
        $pid = (string)($p['pid'] ?? '');
        if ($scope === 'event' && (string)($p['confirm'] ?? '') !== 'HAPUS') failure('Ketik HAPUS untuk menghapus semua kehadiran kegiatan.', 422);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $sql = 'DELETE FROM v7_attendance WHERE event_id=?';
            $params = [$id];
            if ($scope !== 'event') {
                $sql .= ' AND sid=?';
                $params[] = $sid;
            }
            if ($scope === 'one') {
                $sql .= ' AND pid=?';
                $params[] = $pid;
            }
            $q = $db->prepare('SELECT sid,pid,t,m FROM v7_attendance WHERE event_id=?' . ($scope !== 'event' ? ' AND sid=?' : '') . ($scope === 'one' ? ' AND pid=?' : ''));
            $q->execute($params);
            $before = $q->fetchAll();
            $st = $db->prepare($sql);
            $st->execute($params);
            $count = $st->rowCount();
            v7Refresh($db, $id);
            v7Audit($db, 'attendance.delete.' . $scope, $id, ['reason' => $reason, 'count' => $count, 'records' => $before]);
            $db->exec('COMMIT');
            return ['ok' => true, 'count' => $count];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->exec('ROLLBACK');
            throw $e;
        }
    }
    if ($action === 'v7.scores.save') {
        v7Require(['admin', 'assessor']);
        $sid = (string)($p['sid'] ?? '');
        $pid = (string)($p['pid'] ?? '');
        $score = $p['score'] ?? null;
        $db->exec('BEGIN IMMEDIATE');
        try {
            $d = v7StateRaw($db, $id);
            if (!in_array($sid, array_column($d['sessions'], 'id'), true) || !in_array($pid, array_column($d['people'], 'id'), true)) failureWithRollback($db, 'Peserta atau sesi tidak dikenal.', 404);
            if ($score !== null && ($score === '' || !is_numeric($score) || $score < 0 || $score > 100)) failureWithRollback($db, 'Nilai harus berada antara 0–100.', 422);
            if ($score === null) unset($d['sg'][$sid][$pid]);
            else $d['sg'][$sid][$pid] = round((float)$score, 2);
            $rev = v7SaveState($db, $id, $d);
            v7Audit($db, 'score.save', $id, ['sid' => $sid, 'pid' => $pid, 'score' => $score]);
            $db->exec('COMMIT');
            return ['ok' => true, 'revision' => $rev];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->exec('ROLLBACK');
            throw $e;
        }
    }
    if ($action === 'v7.materials.list') {
        $st = $db->prepare('SELECT id,sid,title,kind,uri,mime,created_ms FROM v7_materials WHERE event_id=? ORDER BY created_ms DESC');
        $st->execute([$id]);
        return ['materials' => $st->fetchAll()];
    }
    if ($action === 'v7.materials.link') {
        v7Require(['admin', 'assessor']);
        $sid = v7String($p['sid'] ?? null, 100);
        $title = v7String($p['title'] ?? null, 200);
        $url = v7String($p['url'] ?? null, 2000);
        $u = parse_url($url);
        if (!$u || strtolower((string)($u['scheme'] ?? '')) !== 'https' || empty($u['host']) || isset($u['user']) || isset($u['pass'])) failure('Tautan materi wajib HTTPS.', 422);
        $d = v7StateRaw($db, $id);
        if (!in_array($sid, array_column($d['sessions'], 'id'), true)) failure('Sesi tidak ditemukan.', 404);
        $db->prepare('INSERT INTO v7_materials(id,event_id,sid,title,kind,uri,created_ms,actor) VALUES(?,?,?,?,\'link\',?,?,?)')->execute([v7Id('mat_'), $id, $sid, $title, $url, v7Now(), $_SESSION['user'] ?? '']);
        v7Audit($db, 'material.link', $id, ['sid' => $sid, 'title' => $title]);
        return ['ok' => true];
    }
    if ($action === 'v7.audit') {
        v7Require(['admin']);
        $st = $db->prepare('SELECT at_ms,actor,role,action,details_json FROM v7_audit WHERE event_id=? ORDER BY at_ms DESC LIMIT 150');
        $st->execute([$id]);
        return ['audit' => $st->fetchAll()];
    }
    if ($action === 'v7.diagnostics') {
        $st = $db->prepare('SELECT COUNT(*) FROM v7_attendance WHERE event_id=?');
        $st->execute([$id]);
        $count = (int)$st->fetchColumn();
        $state = v7StateRaw($db, $id);
        return ['event_id' => $id, 'revision' => $state['revision'], 'people' => count($state['people']), 'sessions' => count($state['sessions']), 'attendance' => $count, 'role' => v7Role(), 'sqlite_integrity' => $db->query('PRAGMA integrity_check')->fetchColumn()];
    }
    if ($action === 'v7.poll') {
        $d = v7StateRaw($db, $id);
        return ['event_id' => $id, 'revision' => $d['revision'], 'changed' => $d['revision'] !== (int)($p['revision'] ?? -1)];
    }
    if ($action === 'v7.import.preview' || $action === 'v7.import.apply') {
        v7Require(['admin']);
        $d = $p['data'] ?? null;
        validateState($d);
        $hash = hash('sha256', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $st = $db->prepare('SELECT event_id FROM v7_imports WHERE hash=?');
        $st->execute([$hash]);
        $existing = $st->fetchColumn();
        $preview = ['people' => count($d['people']), 'sessions' => count($d['sessions']), 'logs' => count($d['logs']), 'scores' => count((array)($d['sg'] ?? [])), 'existing' => $existing ?: null, 'note' => 'File lokal IndexedDB tidak terikut dalam backup JSON; unggah ulang secara manual.'];
        if ($action === 'v7.import.preview') return $preview;
        if ($existing) return ['ok' => true, 'existing' => true, 'event_id' => $existing, 'preview' => $preview];
        $db->exec('BEGIN IMMEDIATE');
        try {
            $eventId = v7Id('imp_');
            $name = trim((string)($d['ev'] ?? '')) ?: 'Impor PKD V6';
            $db->prepare('INSERT INTO v7_events(id,name,created_ms) VALUES(?,?,?)')->execute([$eventId, $name, v7Now()]);
            $logs = $d['logs'];
            $safe = [];
            foreach (['ev', 'active', 'cert', 'theme', 'mute', 'people', 'sessions', 'approved', 'grades', 'sg', 'mt'] as $field) if (array_key_exists($field, $d)) $safe[$field] = $d[$field];
            $d = array_replace(v7Defaults(), $safe);
            if (isset($d['cert']['pin'])) unset($d['cert']['pin']);
            $db->prepare('INSERT INTO v7_state(event_id,revision,state_json) VALUES(?,0,?)')->execute([$eventId, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $st = $db->prepare('INSERT INTO v7_attendance(event_id,sid,pid,t,m) VALUES(?,?,?,?,?)');
            foreach ($logs as $l) $st->execute([$eventId, $l['sid'], $l['pid'], (int)$l['t'], (string)($l['m'] ?? 'scan')]);
            // Preserve V6 material links when provided; never trust executable/file paths supplied in JSON.
            foreach ((array)($d['mt'] ?? []) as $sid => $list) {
                if (!in_array($sid, array_column($d['sessions'], 'id'), true)) continue;
                foreach ((array)$list as $entry) {
                    $url = (string)($entry['u'] ?? $entry['url'] ?? '');
                    $parsed = parse_url($url);
                    if (!is_array($entry) || ($entry['k'] ?? '') !== 'link' || !$parsed || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host'])) continue;
                    $db->prepare('INSERT INTO v7_materials(id,event_id,sid,title,kind,uri,created_ms,actor) VALUES(?,?,?,?,\'link\',?,?,?)')->execute([v7Id('mat_'), $eventId, $sid, substr((string)($entry['t'] ?? $entry['title'] ?? 'Materi V6'), 0, 200), $url, v7Now(), $_SESSION['user'] ?? '']);
                }
            }
            $db->prepare('INSERT INTO v7_imports(hash,event_id,at_ms,actor) VALUES(?,?,?,?)')->execute([$hash, $eventId, v7Now(), $_SESSION['user'] ?? '']);
            v7Audit($db, 'import.apply', $eventId, $preview);
            $db->exec('COMMIT');
            return ['ok' => true, 'event_id' => $eventId, 'preview' => $preview];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->exec('ROLLBACK');
            throw $e;
        }
    }
    failure('Aksi V7 tidak dikenal.', 404);
}
function v7Upload(PDO $db): array
{
    v7Require(['admin', 'assessor']);
    $id = v7Event($db);
    $sid = (string)($_POST['sid'] ?? '');
    $title = v7String($_POST['title'] ?? null, 200);
    $d = v7StateRaw($db, $id);
    if (!in_array($sid, array_column($d['sessions'], 'id'), true)) failure('Sesi tidak dikenal.', 404);
    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) failure('Upload gagal.', 422);
    if ((int)$file['size'] > 8 * 1024 * 1024 || (int)$file['size'] === 0) failure('Ukuran file maksimal 8 MB.', 413);
    $allow = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allow[$mime])) failure('Jenis file tidak diperbolehkan.', 422);
    $filename = v7Id('file_') . '.' . $allow[$mime];
    $dir = dirname(__DIR__) . '/storage/materials';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) failure('Tidak dapat menyimpan file.', 503);
    chmod($path, 0640);
    try {
        $mid = v7Id('mat_');
        $db->prepare('INSERT INTO v7_materials(id,event_id,sid,title,kind,path,mime,created_ms,actor) VALUES(?,?,?,?,\'file\',?,?,?,?)')->execute([$mid, $id, $sid, $title, $filename, $mime, v7Now(), $_SESSION['user'] ?? '']);
        v7Audit($db, 'material.upload', $id, ['sid' => $sid, 'material' => $mid]);
        return ['ok' => true, 'id' => $mid];
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }
}
