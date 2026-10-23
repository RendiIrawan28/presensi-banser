<?php

/** Safe explicit V7 schema migration. NEVER use with --apply before backup review. */

declare(strict_types=1);
require dirname(__DIR__) . '/src/v7.php';
$arg = $argv[1] ?? '--status';
if (!in_array($arg, ['--status', '--dry-run', '--apply'], true)) {
    fwrite(STDERR, "Gunakan: php scripts/migrate.php --status|--dry-run|--apply\n");
    exit(2);
}
$file = dirname(__DIR__) . '/data/absensi.sqlite';
if (!is_file($file)) {
    fwrite(STDERR, "Database legacy tidak ditemukan: $file. Instal dan gunakan V6 VPS lebih dahulu.\n");
    exit(1);
}
$db = new PDO('sqlite:' . $file);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout=10000');
$count = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='app_state'")->fetchColumn();
if (!$count) {
    fwrite(STDERR, "Tidak ada app_state legacy. Migrasi dibatalkan.\n");
    exit(1);
}
$state = $db->query('SELECT state_json FROM app_state WHERE id=1')->fetchColumn();
$s = json_decode((string)$state, true);
$attendance = (int)$db->query('SELECT COUNT(*) FROM attendance')->fetchColumn();
$ready = v7Ready($db);
echo 'V7: ' . ($ready ? 'sudah ada' : 'belum ada') . "\nLegacy: " . count($s['people'] ?? []) . ' peserta, ' . count($s['sessions'] ?? []) . " sesi, $attendance kehadiran\n";
if ($ready) {
    echo 'Kegiatan: ' . $db->query('SELECT COUNT(*) FROM v7_events')->fetchColumn() . "\n";
}
if ($arg === '--status' || $arg === '--dry-run') {
    echo ($arg === '--dry-run' ? "DRY RUN tanpa perubahan.\n" : "");
    exit(0);
}
$dir = dirname(__DIR__) . '/storage/backups';
if (!is_dir($dir)) mkdir($dir, 0700, true);
$backup = $dir . '/pre-v7-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
// VACUUM INTO makes a consistent backup even when SQLite is in WAL mode.
$db->exec('VACUUM INTO ' . $db->quote($backup));
@chmod($backup, 0600);
$check = new PDO('sqlite:' . $backup);
if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('Backup tidak valid, migrasi dibatalkan.');
unset($check);
$db->exec('BEGIN IMMEDIATE');
try {
    v7Migrate($db);
    $db->exec('COMMIT');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->exec('ROLLBACK');
    fwrite(STDERR, "MIGRASI GAGAL: " . $e->getMessage() . "\nBackup: $backup\n");
    exit(1);
}
if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
    fwrite(STDERR, "Integritas DB bermasalah. Jangan lanjutkan deploy! Backup: $backup\n");
    exit(1);
}
echo "V7 migrasi sukses. Backup aman: $backup\n";
