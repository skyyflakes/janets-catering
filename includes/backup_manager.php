<?php
/**
 * DATABASE BACKUP & RESTORATION ENGINE
 * Janet's Quality Catering System
 *
 *  - Complete, restorable copies made with mysqldump (structure + all data + triggers + routines +
 *    events, consistent InnoDB snapshot via --single-transaction), gzip-compressed, SHA-256 checksummed
 *    and described by a JSON manifest stored next to the file.
 *  - Files live OUTSIDE the web root (config/backup_config.php) and are only served through
 *    backups.php after an ADMIN permission check.
 *  - Restore = validate -> automatic safety backup -> import -> re-apply current audit history ->
 *    re-sync audit triggers -> integrity check. On import failure the safety backup is re-imported.
 *  - "Verify" test-restores a backup into a temporary database and compares it with the manifest.
 *  - Scheduled backups (daily / weekly / monthly) run from Windows Task Scheduler / cron through
 *    cli/backup_cli.php, with a web fallback that runs a due backup after a page has been served.
 */

if (defined('JQ_BACKUP_MANAGER_LOADED')) {
    return;
}
define('JQ_BACKUP_MANAGER_LOADED', true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/backup_config.php';
require_once __DIR__ . '/activity_logger.php';

/** Audit / backup bookkeeping tables: kept at their CURRENT state when an older backup is restored. */
const JQ_BACKUP_PRESERVED_TABLES = ['activity_logs', 'activity_log_audit', 'database_backups', 'backup_history', 'system_settings'];
const JQ_BACKUP_NAME_RE = '/^[A-Za-z0-9][A-Za-z0-9_\-\.]{0,200}\.sql\.gz$/';

class JqBackupException extends RuntimeException
{
}

function jq_backup_type_label(string $type): string
{
    return [
        'manual'      => 'Manual',
        'scheduled'   => 'Scheduled',
        'pre_restore' => 'Safety (pre-restore)',
        'uploaded'    => 'Uploaded',
    ][$type] ?? ucfirst($type);
}

/* =====================================================================
 *  SETTINGS & PERMISSIONS
 * ===================================================================== */

function jq_backup_default_settings(): array
{
    return [
        'backup_auto_enabled'        => '0',
        'backup_frequency'           => 'daily',   // daily | weekly | monthly
        'backup_time'                => '02:00',
        'backup_weekday'             => '7',       // ISO-8601: 1 = Monday … 7 = Sunday
        'backup_monthday'            => '1',       // 1 – 28
        'backup_retention'           => '14',      // scheduled backups kept (0 = keep all)
        'backup_secondary_dir'       => '',
        'backup_last_auto_at'        => '',
        'backup_last_auto_attempt'   => '',
        'backup_last_auto_status'    => '',
    ];
}

function jq_backup_settings(?PDO $pdo): array
{
    $s = jq_backup_default_settings();
    if (!$pdo) {
        return $s;
    }
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'backup\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $k => $v) {
            if (array_key_exists($k, $s)) {
                $s[$k] = (string)$v;
            }
        }
    } catch (Throwable $e) {
        // table not installed yet – defaults
    }
    return $s;
}

/** Backup and restore belong to the ADMIN role only; the OWNER has no access. */
function jq_backup_is_admin(string $role): bool
{
    return $role === 'ADMIN';
}

/* =====================================================================
 *  FILESYSTEM HELPERS
 * ===================================================================== */

function jq_backup_dir(): string
{
    $dir = BACKUP_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new JqBackupException('The backup folder could not be created: ' . $dir);
    }
    if (!is_writable($dir)) {
        throw new JqBackupException('The backup folder is not writable: ' . $dir);
    }
    // Defence in depth, in case the folder is ever moved inside a public directory.
    $guards = [
        '.htaccess'  => "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n",
        'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
        'index.html' => '',
    ];
    foreach ($guards as $file => $content) {
        $p = $dir . DIRECTORY_SEPARATOR . $file;
        if (!file_exists($p)) {
            @file_put_contents($p, $content);
        }
    }
    return realpath($dir) ?: $dir;
}

function jq_normalize_path(string $p): string
{
    $real = realpath($p) ?: $p;
    $n = rtrim(str_replace('\\', '/', $real), '/') . '/';
    return DIRECTORY_SEPARATOR === '\\' ? strtolower($n) : $n;
}

/** True when $dir is inside the web server's document root (i.e. potentially downloadable). */
function jq_path_is_public(string $dir): bool
{
    $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($root === '') {
        $root = dirname(__DIR__, 2); // CLI: the folder that contains this application (e.g. htdocs)
    }
    return strpos(jq_normalize_path($dir), jq_normalize_path($root)) === 0;
}

/** Resolves a backup file name safely (no path traversal) or throws. */
function jq_backup_path(string $filename): string
{
    if (!preg_match(JQ_BACKUP_NAME_RE, $filename)) {
        throw new JqBackupException('Invalid backup file name.');
    }
    $dir  = jq_backup_dir();
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    $real = realpath($path);
    if ($real === false || !is_file($real)) {
        throw new JqBackupException('Backup file not found: ' . $filename);
    }
    if (strpos(jq_normalize_path(dirname($real)), jq_normalize_path($dir)) !== 0) {
        throw new JqBackupException('Backup file is outside the backup folder.');
    }
    return $real;
}

function jq_backup_manifest(string $path): ?array
{
    $m = $path . '.json';
    if (!is_file($m)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($m), true);
    return is_array($data) ? $data : null;
}

function jq_backup_write_manifest(string $path, array $manifest): void
{
    file_put_contents($path . '.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function jq_tempfile(string $prefix): string
{
    $f = tempnam(sys_get_temp_dir(), $prefix);
    if ($f === false) {
        throw new JqBackupException('Could not create a temporary file.');
    }
    return $f;
}

function jq_unlink(?string ...$files): void
{
    foreach ($files as $f) {
        if ($f && is_file($f)) {
            @unlink($f);
        }
    }
}

function jq_gzip_file(string $src, string $dst): void
{
    $in  = fopen($src, 'rb');
    $out = gzopen($dst, 'wb6');
    if (!$in || !$out) {
        throw new JqBackupException('Could not compress the backup file.');
    }
    while (!feof($in)) {
        $chunk = fread($in, 1048576);
        if ($chunk === false || ($chunk !== '' && gzwrite($out, $chunk) === false)) {
            fclose($in);
            gzclose($out);
            throw new JqBackupException('Writing the compressed backup failed (disk full?).');
        }
    }
    fclose($in);
    gzclose($out);
}

function jq_gunzip_file(string $src, string $dst): void
{
    $in  = @gzopen($src, 'rb');
    $out = fopen($dst, 'wb');
    if (!$in || !$out) {
        throw new JqBackupException('The backup file could not be opened as a gzip archive.');
    }
    while (!gzeof($in)) {
        $chunk = gzread($in, 1048576);
        if ($chunk === false) {
            gzclose($in);
            fclose($out);
            throw new JqBackupException('The backup archive is corrupted (decompression failed).');
        }
        fwrite($out, $chunk);
    }
    gzclose($in);
    fclose($out);
}

/* =====================================================================
 *  MYSQL CLIENT TOOLS
 * ===================================================================== */

function jq_find_binary(string $name): ?string
{
    $exe = DIRECTORY_SEPARATOR === '\\' ? $name . '.exe' : $name;
    $dirs = [];
    if (MYSQL_BIN_DIR !== '') {
        $dirs[] = MYSQL_BIN_DIR;
    }
    $dirs = array_merge($dirs, [
        dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin', // XAMPP layout
        'C:\\xampp\\mysql\\bin',
        '/usr/bin', '/usr/local/bin', '/usr/local/mysql/bin', '/opt/lampp/bin',
        '/Applications/XAMPP/xamppfiles/bin', '/Applications/MAMP/Library/bin',
    ], explode(PATH_SEPARATOR, (string)getenv('PATH')));
    foreach ($dirs as $d) {
        if ($d === '') {
            continue;
        }
        $p = rtrim($d, '\\/') . DIRECTORY_SEPARATOR . $exe;
        if (@is_file($p)) {
            return $p;
        }
    }
    return null;
}

function jq_require_binary(string $name): string
{
    $bin = jq_find_binary($name);
    if (!$bin) {
        throw new JqBackupException("The '$name' program was not found. Set MYSQL_BIN_DIR in config/backup_config.php.");
    }
    return $bin;
}

/** Temporary option file so the password never appears on the command line / process list. */
function jq_mysql_defaults_file(): string
{
    $q = static fn($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$v) . '"';
    $path = jq_tempfile('jqcnf');
    $content = "[client]\nuser=" . $q(DB_USER) . "\npassword=" . $q(DB_PASS) . "\nhost=" . $q(DB_HOST) . "\n";
    if (defined('DB_PORT')) {
        $content .= 'port=' . (int)DB_PORT . "\n";
    }
    file_put_contents($path, $content);
    @chmod($path, 0600);
    return $path;
}

/**
 * Runs an external program without a shell.
 * @return array{exit: int, stderr: string}
 */
function jq_run_process(array $cmd, ?string $stdinFile, string $stdoutFile, int $timeout = BACKUP_PROCESS_TIMEOUT): array
{
    $errFile = jq_tempfile('jqerr');
    $desc = [
        0 => $stdinFile ? ['file', $stdinFile, 'r'] : ['pipe', 'r'],
        1 => ['file', $stdoutFile, 'w'],
        2 => ['file', $errFile, 'w'],
    ];
    $proc = proc_open($cmd, $desc, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        jq_unlink($errFile);
        throw new JqBackupException('Could not start ' . basename($cmd[0]) . '.');
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $start = time();
    $exit = -1;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $exit = (int)$st['exitcode'];
            break;
        }
        if (time() - $start > $timeout) {
            proc_terminate($proc);
            proc_close($proc);
            jq_unlink($errFile);
            throw new JqBackupException(basename($cmd[0]) . ' timed out after ' . $timeout . ' seconds.');
        }
        usleep(100000);
    }
    proc_close($proc);
    $stderr = trim((string)@file_get_contents($errFile));
    jq_unlink($errFile);
    return ['exit' => $exit, 'stderr' => $stderr];
}

/** Removes noise and anything that looks like a credential from tool error output. */
function jq_clean_tool_error(string $stderr): string
{
    $lines = array_filter(array_map('trim', preg_split('/\R/', $stderr)), static function ($l) {
        return $l !== '' && stripos($l, 'Using a password') === false;
    });
    $msg = implode(' ', array_slice($lines, 0, 3));
    $msg = preg_replace('/password\s*[=:]\s*\S+/i', 'password=***', $msg);
    return $msg !== '' ? $msg : 'unknown error';
}

/** Imports an uncompressed SQL file into a database with the mysql client. */
function jq_mysql_import(string $sqlFile, string $database): array
{
    $mysql = jq_require_binary('mysql');
    $cnf = jq_mysql_defaults_file();
    $out = jq_tempfile('jqout');
    try {
        // --binary-mode: client commands such as \! (shell) or source are NOT executed from the file.
        $run = jq_run_process([$mysql, "--defaults-extra-file=$cnf", '--default-character-set=utf8mb4', '--binary-mode', $database], $sqlFile, $out);
    } finally {
        jq_unlink($cnf, $out);
    }
    return ['ok' => $run['exit'] === 0, 'error' => $run['exit'] === 0 ? '' : jq_clean_tool_error($run['stderr'])];
}

/** mysqldump of the whole database (or only $tables) into an uncompressed SQL file. */
function jq_mysqldump(string $outFile, array $tables = []): void
{
    $dump = jq_require_binary('mysqldump');
    $cnf = jq_mysql_defaults_file();
    try {
        $cmd = [$dump, "--defaults-extra-file=$cnf", '--single-transaction', '--quick', '--routines', '--triggers',
                '--events', '--hex-blob', '--add-drop-table', '--default-character-set=utf8mb4', DB_NAME];
        foreach ($tables as $t) {
            $cmd[] = $t;
        }
        $run = jq_run_process($cmd, null, $outFile);
    } finally {
        jq_unlink($cnf);
    }
    if ($run['exit'] !== 0) {
        throw new JqBackupException('mysqldump failed: ' . jq_clean_tool_error($run['stderr']));
    }
}

/* =====================================================================
 *  DUMP VALIDATION
 * ===================================================================== */

/** Header ("-- MariaDB dump" / "-- MySQL dump") and footer ("-- Dump completed") check. */
function jq_sql_envelope_check(string $sqlPath): array
{
    $errors = [];
    $size = filesize($sqlPath);
    if (!$size) {
        return ['ok' => false, 'errors' => ['The backup is empty.']];
    }
    $fh = fopen($sqlPath, 'rb');
    $head = (string)fread($fh, 4096);
    fseek($fh, max(0, $size - 1024));
    $tail = (string)fread($fh, 1024);
    fclose($fh);
    if (!preg_match('/^--\s+(MariaDB|MySQL) dump/mi', $head)) {
        $errors[] = 'The file is not a MySQL/MariaDB dump (missing dump header).';
    }
    if (strpos($tail, '-- Dump completed') === false) {
        $errors[] = 'The dump is incomplete (missing "Dump completed" footer) – it may be truncated or corrupted.';
    }
    return ['ok' => !$errors, 'errors' => $errors];
}

/**
 * Rejects statements that must never appear in a database backup of this application
 * (switching / creating / dropping databases, user & privilege management, file access).
 * @return array{ok: bool, errors: string[], tables: string[]}
 */
function jq_sql_safety_scan(string $sqlPath): array
{
    $danger = '(?:USE\s|CREATE\s+(?:DATABASE|SCHEMA|USER|SERVER)\b|DROP\s+(?:DATABASE|SCHEMA|USER|SERVER)\b|ALTER\s+(?:USER|DATABASE|SCHEMA)\b|RENAME\s+USER\b|GRANT\s|REVOKE\s|SET\s+PASSWORD\b|INSTALL\s+(?:PLUGIN|SONAME)\b|UNINSTALL\s|SHUTDOWN\b|LOAD\s+(?:DATA|XML)\b|SYSTEM\s|SOURCE\s|\\\\[!.])';
    $lineStart = '/^\s*(?:\/\*!\d*\s*)?' . $danger . '/i';
    $midLine   = '/;\s*(?:\/\*!\d*\s*)?' . $danger . '/i';
    $errors = [];
    $tables = [];
    $fh = fopen($sqlPath, 'rb');
    $n = 0;
    while (($line = fgets($fh)) !== false) {
        $n++;
        if (strncmp($line, 'INSERT INTO ', 12) === 0) {
            continue; // data rows – values may legitimately contain any text
        }
        if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) {
            $tables[] = $m[1];
        }
        if (preg_match($lineStart, $line) || preg_match($midLine, $line)
            || preg_match('/\bINTO\s+(?:OUT|DUMP)FILE\b|\bLOAD_FILE\s*\(/i', $line)) {
            $errors[] = 'Line ' . $n . ': forbidden statement "' . mb_substr(trim($line), 0, 80) . '"';
            if (count($errors) >= 5) {
                break;
            }
        }
    }
    fclose($fh);
    if (!$tables && !$errors) {
        $errors[] = 'The backup does not contain any table definitions.';
    }
    return ['ok' => !$errors, 'errors' => $errors, 'tables' => $tables];
}

/**
 * Full validation of a stored backup. With $keepSql the decompressed SQL path is returned (caller deletes it).
 * @return array{ok: bool, errors: string[], warnings: string[], sql_path: ?string, manifest: ?array, tables: string[], sha256: ?string, path: ?string}
 */
function jq_backup_validate(?PDO $pdo, string $filename, bool $keepSql = false): array
{
    $res = ['ok' => false, 'errors' => [], 'warnings' => [], 'sql_path' => null, 'manifest' => null, 'tables' => [], 'sha256' => null, 'path' => null];
    try {
        $path = jq_backup_path($filename);
        $res['path'] = $path;
        $res['manifest'] = $manifest = jq_backup_manifest($path);
        $sha = hash_file('sha256', $path);
        $res['sha256'] = $sha;

        $expected = $manifest['sha256'] ?? null;
        if ($pdo) {
            try {
                $st = $pdo->prepare("SELECT checksum_sha256 FROM database_backups WHERE filename = ?");
                $st->execute([$filename]);
                $dbSha = $st->fetchColumn();
                if ($dbSha && $expected && !hash_equals($dbSha, $expected)) {
                    $res['errors'][] = 'The manifest checksum does not match the checksum recorded in the database.';
                }
                $expected = $expected ?: ($dbSha ?: null);
            } catch (Throwable $e) {
                // bookkeeping table unavailable (e.g. during disaster recovery) – rely on manifest
            }
        }
        if ($expected === null) {
            $res['warnings'][] = 'No recorded checksum – file integrity could not be compared with the original.';
        } elseif (!hash_equals($expected, $sha)) {
            $res['errors'][] = 'Checksum mismatch: the file has been modified or corrupted since it was created.';
        }

        $sql = jq_tempfile('jqsql');
        jq_gunzip_file($path, $sql);
        $env = jq_sql_envelope_check($sql);
        $scan = jq_sql_safety_scan($sql);
        $res['errors'] = array_merge($res['errors'], $env['errors'], $scan['errors']);
        $res['tables'] = $scan['tables'];
        foreach (['users', 'event_bookings'] as $core) {
            if (!in_array($core, $scan['tables'], true)) {
                $res['warnings'][] = "The backup does not contain the '$core' table.";
            }
        }
        $res['ok'] = !$res['errors'];
        if ($keepSql && $res['ok']) {
            $res['sql_path'] = $sql;
        } else {
            jq_unlink($sql);
        }
    } catch (Throwable $e) {
        $res['errors'][] = $e->getMessage();
        $res['ok'] = false;
    }
    return $res;
}

/* =====================================================================
 *  BOOKKEEPING
 * ===================================================================== */

function jq_db_table_counts(PDO $pdo, ?string $schema = null): array
{
    $schemaSql = $schema === null ? 'DATABASE()' : '?';
    $st = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = $schemaSql AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
    $st->execute($schema === null ? [] : [$schema]);
    $counts = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $q = ($schema === null ? '' : '`' . str_replace('`', '``', $schema) . '`.') . '`' . str_replace('`', '``', $t) . '`';
        $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $q")->fetchColumn();
    }
    return $counts;
}

function jq_backup_history(?PDO $pdo, ?int $backupId, ?string $filename, string $action, string $status, string $details, ?int $durationMs, array $actor): void
{
    try {
        $pdo = $pdo ?: getDBConnection();
        if (!$pdo) {
            return;
        }
        $st = $pdo->prepare("INSERT INTO backup_history (backup_id, filename, action, status, details, duration_ms, performed_by, performed_by_username, performed_by_role, ip_address)
                             VALUES ((SELECT b.id FROM database_backups b WHERE b.id = ?), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([$backupId, $filename, $action, $status, $details, $durationMs, $actor['id'] ?? null,
                      mb_substr((string)($actor['username'] ?? ''), 0, 100), $actor['role'] ?? null, jq_client_ip()]);
    } catch (Throwable $e) {
        error_log('backup_history insert failed: ' . $e->getMessage());
    }
}

function jq_backup_log(?PDO $pdo, string $action, string $description, array $actor, array $extra = []): void
{
    logActivity($pdo, $action, 'Database Backup', $description, $extra + [
        'user_id'  => $actor['id'] ?? null,
        'username' => $actor['username'] ?? 'system',
        'role'     => $actor['role'] ?? null,
    ]);
}

/* =====================================================================
 *  LOCKING & MAINTENANCE MODE
 * ===================================================================== */

/** Re-entrant exclusive lock so two backups / restores never run at the same time. */
function jq_backup_lock(): bool
{
    if (!empty($GLOBALS['jq_backup_lock_depth'])) {
        $GLOBALS['jq_backup_lock_depth']++;
        return true;
    }
    $fh = @fopen(jq_backup_dir() . DIRECTORY_SEPARATOR . '.backup.lock', 'c');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        if ($fh) {
            fclose($fh);
        }
        return false;
    }
    $GLOBALS['jq_backup_lock_fh'] = $fh;
    $GLOBALS['jq_backup_lock_depth'] = 1;
    return true;
}

function jq_backup_unlock(): void
{
    if (empty($GLOBALS['jq_backup_lock_depth'])) {
        return;
    }
    if (--$GLOBALS['jq_backup_lock_depth'] === 0 && !empty($GLOBALS['jq_backup_lock_fh'])) {
        flock($GLOBALS['jq_backup_lock_fh'], LOCK_UN);
        fclose($GLOBALS['jq_backup_lock_fh']);
        $GLOBALS['jq_backup_lock_fh'] = null;
    }
}

function jq_maintenance_file(): string
{
    return rtrim(BACKUP_DIR, '\\/') . DIRECTORY_SEPARATOR . 'maintenance.lock';
}

function jq_maintenance_on(array $actor, string $reason): void
{
    @file_put_contents(jq_maintenance_file(), json_encode(['user_id' => $actor['id'] ?? null, 'username' => $actor['username'] ?? '', 'reason' => $reason, 'since' => time()]));
}

function jq_maintenance_off(): void
{
    jq_unlink(jq_maintenance_file());
}

/** Active maintenance info, or null. Stale locks (> 2 h) are ignored. */
function jq_maintenance_active(): ?array
{
    $f = jq_maintenance_file();
    if (!is_file($f) || filemtime($f) < time() - 7200) {
        return null;
    }
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : ['reason' => 'maintenance'];
}

/** Called from auth_check.php: while a restore runs, everybody except the operator sees a 503 page. */
function jq_maintenance_guard(): void
{
    $m = jq_maintenance_active();
    if (!$m || PHP_SAPI === 'cli') {
        return;
    }
    if (!empty($m['user_id']) && (int)($m['user_id']) === (int)($_SESSION['user_id'] ?? 0)) {
        return;
    }
    http_response_code(503);
    header('Retry-After: 120');
    if (jq_is_ajax()) {
        jq_json_response(['success' => false, 'error' => 'The system is being restored from a backup. Please try again in a few minutes.'], 503);
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Maintenance | Janet\'s Quality Catering</title><meta http-equiv="refresh" content="60"></head>'
        . '<body style="font-family:Public Sans,Segoe UI,sans-serif;background:#F0E6FA;color:#1A1A2E;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:16px;">'
        . '<div style="background:#fff;border:1px solid #D8C8E8;border-radius:12px;padding:32px;max-width:460px;text-align:center;">'
        . '<h2 style="margin:0 0 8px;">Maintenance in progress</h2><p style="color:#5E5A78;margin:0;">The database is being restored from a backup. '
        . 'This page will refresh automatically. Please try again in a few minutes.</p></div></body></html>';
    exit;
}

/* =====================================================================
 *  CREATE
 * ===================================================================== */

/**
 * Creates a complete backup of the database.
 * @param string $type manual | scheduled | pre_restore
 */
function jq_backup_create(PDO $pdo, string $type, array $actor, string $notes = ''): array
{
    $t0 = microtime(true);
    $filename = null;
    $tmpSql = null;
    if (!jq_backup_lock()) {
        return ['success' => false, 'message' => 'Another backup or restore is currently running. Please try again in a moment.'];
    }
    @set_time_limit(0);
    try {
        $dir = jq_backup_dir();
        $stamp = jq_now()->format('Ymd_His');
        $base = DB_NAME . '_' . $stamp . '_' . $type;
        $filename = $base . '.sql.gz';
        for ($i = 2; file_exists($dir . DIRECTORY_SEPARATOR . $filename); $i++) {
            $filename = $base . '_' . $i . '.sql.gz';
        }

        $counts = jq_db_table_counts($pdo);          // taken immediately before the snapshot
        $tmpSql = jq_tempfile('jqdump');
        jq_mysqldump($tmpSql);

        $env = jq_sql_envelope_check($tmpSql);
        if (!$env['ok']) {
            throw new JqBackupException(implode(' ', $env['errors']));
        }
        $triggerCount = 0;
        $fh = fopen($tmpSql, 'rb');
        while (($line = fgets($fh)) !== false) {
            if (strncmp($line, 'INSERT INTO ', 12) !== 0 && strpos($line, ' TRIGGER ') !== false && stripos($line, 'CREATE') !== false) {
                $triggerCount++;
            }
        }
        fclose($fh);

        $final = $dir . DIRECTORY_SEPARATOR . $filename;
        jq_gzip_file($tmpSql, $final . '.part');
        if (!rename($final . '.part', $final)) {
            throw new JqBackupException('Could not finalize the backup file.');
        }
        $sha  = hash_file('sha256', $final);
        $size = (int)filesize($final);
        $serverVersion = (string)$pdo->query("SELECT VERSION()")->fetchColumn();

        $manifest = [
            'app'            => "Janet's Quality Catering",
            'format'         => 1,
            'filename'       => $filename,
            'type'           => $type,
            'database'       => DB_NAME,
            'server_version' => $serverVersion,
            'created_at'     => jq_now()->format('Y-m-d H:i:s'),
            'timezone'       => 'Asia/Manila',
            'created_by'     => $actor['username'] ?? 'system',
            'sha256'         => $sha,
            'size_bytes'     => $size,
            'uncompressed_bytes' => (int)filesize($tmpSql),
            'table_count'    => count($counts),
            'row_count'      => array_sum($counts),
            'trigger_count'  => $triggerCount,
            'tables'         => $counts,
            'notes'          => $notes,
        ];
        jq_backup_write_manifest($final, $manifest);

        $secondary = jq_backup_copy_secondary($pdo, $final);

        $st = $pdo->prepare("INSERT INTO database_backups
            (filename, backup_type, file_size, checksum_sha256, table_count, row_count, db_name, server_version,
             secondary_copy, secondary_path, status, notes, created_by, created_by_username)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', ?, (SELECT u.id FROM users u WHERE u.id = ?), ?)");
        $st->execute([$filename, $type, $size, $sha, count($counts), array_sum($counts), DB_NAME, $serverVersion,
                      $secondary['ok'] ? 1 : 0, $secondary['ok'] ? $secondary['path'] : null, mb_substr($notes, 0, 500),
                      $actor['id'] ?? null, mb_substr((string)($actor['username'] ?? 'system'), 0, 100)]);
        $backupId = (int)$pdo->lastInsertId();

        $ms = (int)round((microtime(true) - $t0) * 1000);
        $summary = sprintf('%s backup: %d tables, %s rows, %d triggers, %s.', jq_backup_type_label($type), count($counts),
            number_format(array_sum($counts)), $triggerCount, jq_human_size($size));
        if ($secondary['configured']) {
            $summary .= $secondary['ok'] ? ' Copied to secondary destination.' : ' Secondary copy FAILED: ' . $secondary['error'];
        }
        jq_backup_history($pdo, $backupId, $filename, 'CREATE', $secondary['configured'] && !$secondary['ok'] ? 'warning' : 'success', $summary, $ms, $actor);
        jq_backup_log($pdo, 'BACKUP_CREATE', 'Created ' . strtolower(jq_backup_type_label($type)) . ' database backup ' . $filename . ' – ' . $summary, $actor,
            ['record_table' => 'database_backups', 'record_id' => $backupId, 'record_label' => $filename]);

        return ['success' => true, 'message' => 'Backup created: ' . $filename . ' (' . jq_human_size($size) . ').', 'filename' => $filename,
                'id' => $backupId, 'size' => $size, 'secondary' => $secondary, 'summary' => $summary];
    } catch (Throwable $e) {
        if ($filename) {
            jq_unlink(jq_backup_dir() . DIRECTORY_SEPARATOR . $filename . '.part');
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        jq_backup_history($pdo, null, $filename, 'CREATE', 'failed', $e->getMessage(), $ms, $actor);
        jq_backup_log($pdo, 'BACKUP_FAILED', 'Database backup failed: ' . $e->getMessage(), $actor);
        return ['success' => false, 'message' => 'Backup failed: ' . $e->getMessage()];
    } finally {
        jq_unlink($tmpSql);
        jq_backup_unlock();
    }
}

/** Copies a backup (and its manifest) to the optional secondary destination. */
function jq_backup_copy_secondary(PDO $pdo, string $path): array
{
    $dir = trim(jq_backup_settings($pdo)['backup_secondary_dir']);
    if ($dir === '') {
        return ['configured' => false, 'ok' => false, 'path' => null, 'error' => null];
    }
    try {
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new JqBackupException('folder not found or not writable: ' . $dir);
        }
        $dest = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . basename($path);
        if (!@copy($path, $dest) || hash_file('sha256', $dest) !== hash_file('sha256', $path)) {
            throw new JqBackupException('copy could not be verified');
        }
        if (is_file($path . '.json')) {
            @copy($path . '.json', $dest . '.json');
        }
        return ['configured' => true, 'ok' => true, 'path' => $dest, 'error' => null];
    } catch (Throwable $e) {
        return ['configured' => true, 'ok' => false, 'path' => null, 'error' => $e->getMessage()];
    }
}

/* =====================================================================
 *  LIST
 * ===================================================================== */

/** Backups found on disk (source of truth – works even if the bookkeeping table is lost). */
function jq_backup_list(?PDO $pdo): array
{
    $dir = jq_backup_dir();
    $db = [];
    if ($pdo) {
        try {
            foreach ($pdo->query("SELECT * FROM database_backups") as $r) {
                $db[$r['filename']] = $r;
            }
        } catch (Throwable $e) {
        }
    }
    $items = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.sql.gz') ?: [] as $path) {
        $name = basename($path);
        if (!preg_match(JQ_BACKUP_NAME_RE, $name)) {
            continue;
        }
        $m = jq_backup_manifest($path) ?? [];
        $row = $db[$name] ?? [];
        $type = $m['type'] ?? ($row['backup_type'] ?? (preg_match('/_(manual|scheduled|pre_restore|uploaded)(?:_\d+)?\.sql\.gz$/', $name, $mm) ? $mm[1] : 'manual'));
        $created = $m['created_at'] ?? ($row['created_at'] ?? date('Y-m-d H:i:s', filemtime($path)));
        $items[] = [
            'filename'     => $name,
            'type'         => $type,
            'created_at'   => substr((string)$created, 0, 19),
            'size'         => (int)filesize($path),
            'tables'       => $m['table_count'] ?? ($row['table_count'] ?? null),
            'rows'         => $m['row_count'] ?? ($row['row_count'] ?? null),
            'created_by'   => $m['created_by'] ?? ($row['created_by_username'] ?? '—'),
            'has_manifest' => !empty($m),
            'sha256'       => $m['sha256'] ?? ($row['checksum_sha256'] ?? null),
            'verify_status'=> $m['verify_status'] ?? ($row['verify_status'] ?? null),
            'verified_at'  => $m['verified_at'] ?? ($row['last_verified_at'] ?? null),
            'secondary'    => !empty($row['secondary_copy']),
            'notes'        => $m['notes'] ?? ($row['notes'] ?? ''),
            'id'           => isset($row['id']) ? (int)$row['id'] : null,
        ];
    }
    usort($items, static fn($a, $b) => strcmp($b['created_at'] . $b['filename'], $a['created_at'] . $a['filename']));
    return $items;
}

/* =====================================================================
 *  VERIFY (test restore into a temporary database)
 * ===================================================================== */

function jq_backup_verify(PDO $pdo, string $filename, array $actor): array
{
    $t0 = microtime(true);
    @set_time_limit(0);
    $v = jq_backup_validate($pdo, $filename, true);
    $testDb = null;
    $result = ['success' => false, 'message' => '', 'details' => []];
    try {
        if (!$v['ok']) {
            throw new JqBackupException('Validation failed: ' . implode(' ', $v['errors']));
        }
        $testDb = substr(DB_NAME, 0, 30) . '_verify_' . jq_now()->format('YmdHis') . '_' . bin2hex(random_bytes(2));
        $pdo->exec("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $imp = jq_mysql_import($v['sql_path'], $testDb);
        if (!$imp['ok']) {
            throw new JqBackupException('Test restore failed: ' . $imp['error']);
        }
        $restored = jq_db_table_counts($pdo, $testDb);
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?");
        $st->execute([$testDb]);
        $triggers = (int)$st->fetchColumn();

        $problems = [];
        $expected = $v['manifest']['tables'] ?? null;
        if (is_array($expected)) {
            foreach ($expected as $table => $rows) {
                if (!array_key_exists($table, $restored)) {
                    $problems[] = "missing table $table";
                } elseif ((int)$restored[$table] !== (int)$rows) {
                    $problems[] = "$table: expected " . number_format((int)$rows) . ' rows, restored ' . number_format($restored[$table]);
                }
            }
        } else {
            foreach ($v['tables'] as $table) {
                if (!array_key_exists($table, $restored)) {
                    $problems[] = "missing table $table";
                }
            }
        }
        $summary = sprintf('Test restore OK: %d tables, %s rows, %d triggers restored into a temporary database.',
            count($restored), number_format(array_sum($restored)), $triggers);
        $status = $problems ? 'warning' : 'success';
        if ($problems) {
            $summary .= ' Differences: ' . implode('; ', array_slice($problems, 0, 8));
        }
        if ($v['warnings']) {
            $summary .= ' Notes: ' . implode(' ', $v['warnings']);
        }

        $m = $v['manifest'] ?? [];
        if ($m) {
            $m['verify_status'] = $status === 'success' ? 'verified' : 'warning';
            $m['verified_at'] = jq_now()->format('Y-m-d H:i:s');
            jq_backup_write_manifest($v['path'], $m);
        }
        try {
            $pdo->prepare("UPDATE database_backups SET verify_status = ?, last_verified_at = NOW() WHERE filename = ?")
                ->execute([$status === 'success' ? 'verified' : 'warning', $filename]);
        } catch (Throwable $e) {
        }
        $result = ['success' => true, 'status' => $status, 'message' => $summary, 'details' => $problems];
    } catch (Throwable $e) {
        $result = ['success' => false, 'status' => 'failed', 'message' => $e->getMessage(), 'details' => []];
        try {
            $pdo->prepare("UPDATE database_backups SET verify_status = 'failed', last_verified_at = NOW() WHERE filename = ?")->execute([$filename]);
        } catch (Throwable $e2) {
        }
    } finally {
        if ($testDb) {
            try {
                $pdo->exec("DROP DATABASE IF EXISTS `$testDb`");
            } catch (Throwable $e) {
                error_log('Could not drop verification database ' . $testDb . ': ' . $e->getMessage());
            }
        }
        jq_unlink($v['sql_path'] ?? null);
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);
    jq_backup_history($pdo, null, $filename, 'VERIFY', $result['status'], $result['message'], $ms, $actor);
    jq_backup_log($pdo, $result['success'] ? 'BACKUP_VERIFY' : 'BACKUP_FAILED', 'Verified backup ' . $filename . ': ' . $result['message'], $actor,
        ['record_table' => 'database_backups', 'record_label' => $filename]);
    return $result;
}

/* =====================================================================
 *  RESTORE
 * ===================================================================== */

function jq_backup_restore(PDO $pdo, string $filename, array $actor): array
{
    $t0 = microtime(true);
    @set_time_limit(0);
    ignore_user_abort(true);
    if (!jq_backup_lock()) {
        return ['success' => false, 'message' => 'Another backup or restore is currently running. Please try again in a moment.'];
    }
    $preservedSql = null;
    $safetySql = null;
    $v = ['sql_path' => null];
    $steps = [];
    try {
        $v = jq_backup_validate($pdo, $filename, true);
        if (!$v['ok']) {
            throw new JqBackupException('The backup failed validation and was NOT restored: ' . implode(' ', $v['errors']));
        }
        $steps[] = 'Backup validated (checksum, structure, safety scan).';

        jq_maintenance_on($actor, 'Restoring ' . $filename);

        // 1) Safety backup of the current database
        $safety = jq_backup_create($pdo, 'pre_restore', $actor, 'Automatic safety backup before restoring ' . $filename);
        if (!$safety['success']) {
            throw new JqBackupException('The safety backup of the current database failed, so the restore was cancelled: ' . $safety['message']);
        }
        $steps[] = 'Safety backup created: ' . $safety['filename'] . '.';

        // 2) Keep the CURRENT audit history and backup bookkeeping (never roll the audit trail back)
        $preserve = array_values(array_filter(JQ_BACKUP_PRESERVED_TABLES, static fn($t) => jq_table_exists($pdo, $t)));
        $preservedSql = jq_tempfile('jqkeep');
        jq_mysqldump($preservedSql, $preserve);

        // 3) Import the selected backup
        $imp = jq_mysql_import($v['sql_path'], DB_NAME);
        if (!$imp['ok']) {
            // automatic rollback to the safety backup
            $safetySql = jq_tempfile('jqsafe');
            jq_gunzip_file(jq_backup_path($safety['filename']), $safetySql);
            $rb = jq_mysql_import($safetySql, DB_NAME);
            if ($rb['ok']) {
                jq_mysql_import($preservedSql, DB_NAME);
            }
            throw new JqBackupException('Import failed (' . $imp['error'] . '). ' . ($rb['ok']
                ? 'The database was automatically rolled back to the safety backup ' . $safety['filename'] . '.'
                : 'AUTOMATIC ROLLBACK ALSO FAILED (' . $rb['error'] . '). Restore the safety backup ' . $safety['filename'] . ' manually with cli/backup_cli.php.'));
        }
        $steps[] = 'Database structure and records imported from ' . $filename . '.';

        // 4) Re-apply current audit tables
        $keep = jq_mysql_import($preservedSql, DB_NAME);
        if (!$keep['ok']) {
            $steps[] = 'WARNING: current audit history could not be re-applied (' . $keep['error'] . '); it is contained in safety backup ' . $safety['filename'] . '.';
        } else {
            $steps[] = 'Current activity log, audit trail and backup history preserved.';
        }

        // 5) Fresh connection, re-sync audit triggers for the restored table layout
        $pdo2 = getDBConnection() ?: $pdo;
        $schema = jq_ensure_audit_schema($pdo2, true);
        $steps[] = $schema['ok'] ? 'Audit triggers re-synchronized.' : 'WARNING: audit triggers: ' . implode(' ', $schema['messages']);

        // 6) Integrity check against the manifest
        $now = jq_db_table_counts($pdo2);
        $problems = [];
        foreach (($v['manifest']['tables'] ?? []) as $table => $rows) {
            if (in_array($table, JQ_BACKUP_PRESERVED_TABLES, true)) {
                continue;
            }
            if (!array_key_exists($table, $now)) {
                $problems[] = "missing table $table";
            } elseif ((int)$now[$table] !== (int)$rows) {
                $problems[] = "$table: expected " . number_format((int)$rows) . ', found ' . number_format($now[$table]);
            }
        }
        $steps[] = $problems ? 'Integrity check differences: ' . implode('; ', array_slice($problems, 0, 8)) : 'Integrity check passed (all table row counts match the backup).';

        $ms = (int)round((microtime(true) - $t0) * 1000);
        $details = implode(' ', $steps);
        jq_backup_history($pdo2, null, $filename, 'RESTORE', $problems ? 'warning' : 'success', $details, $ms, $actor);
        jq_backup_log($pdo2, 'BACKUP_RESTORE', 'Restored the database from backup ' . $filename . '. ' . $details, $actor,
            ['record_table' => 'database_backups', 'record_label' => $filename]);
        return ['success' => true, 'message' => 'Database restored from ' . $filename . '.', 'steps' => $steps, 'safety' => $safety['filename'], 'problems' => $problems];
    } catch (Throwable $e) {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $pdo3 = getDBConnection() ?: $pdo;
        jq_backup_history($pdo3, null, $filename, 'RESTORE', 'failed', $e->getMessage() . ' ' . implode(' ', $steps), $ms, $actor);
        jq_backup_log($pdo3, 'BACKUP_FAILED', 'Database restore from ' . $filename . ' failed: ' . $e->getMessage(), $actor);
        return ['success' => false, 'message' => $e->getMessage(), 'steps' => $steps];
    } finally {
        jq_unlink($v['sql_path'] ?? null, $preservedSql, $safetySql);
        jq_maintenance_off();
        jq_backup_unlock();
    }
}

/* =====================================================================
 *  UPLOAD (bring an off-site copy back for disaster recovery)
 * ===================================================================== */

function jq_backup_upload(PDO $pdo, array $file, array $actor): array
{
    $t0 = microtime(true);
    $tmpSql = null;
    try {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $codes = [UPLOAD_ERR_INI_SIZE => 'the file exceeds upload_max_filesize in php.ini', UPLOAD_ERR_FORM_SIZE => 'the file is too large',
                      UPLOAD_ERR_PARTIAL => 'the upload was interrupted', UPLOAD_ERR_NO_FILE => 'no file was selected'];
            throw new JqBackupException('Upload failed: ' . ($codes[$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'error code ' . (int)$file['error']) . '.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new JqBackupException('Invalid upload.');
        }
        if ($file['size'] > BACKUP_MAX_UPLOAD_MB * 1048576) {
            throw new JqBackupException('The file is larger than ' . BACKUP_MAX_UPLOAD_MB . ' MB.');
        }
        $original = (string)$file['name'];
        if (!preg_match('/\.(sql|sql\.gz|gz)$/i', $original)) {
            throw new JqBackupException('Only .sql or .sql.gz backup files are accepted.');
        }
        $fh = fopen($file['tmp_name'], 'rb');
        $magic = fread($fh, 2);
        fclose($fh);
        $tmpSql = jq_tempfile('jqup');
        if ($magic === "\x1f\x8b") {
            jq_gunzip_file($file['tmp_name'], $tmpSql);
        } elseif (!copy($file['tmp_name'], $tmpSql)) {
            throw new JqBackupException('Could not read the uploaded file.');
        }
        $env = jq_sql_envelope_check($tmpSql);
        $scan = jq_sql_safety_scan($tmpSql);
        $errors = array_merge($env['errors'], $scan['errors']);
        if ($errors) {
            throw new JqBackupException('The uploaded file was rejected: ' . implode(' ', $errors));
        }

        $dir = jq_backup_dir();
        $base = DB_NAME . '_' . jq_now()->format('Ymd_His') . '_uploaded';
        $filename = $base . '.sql.gz';
        for ($i = 2; file_exists($dir . DIRECTORY_SEPARATOR . $filename); $i++) {
            $filename = $base . '_' . $i . '.sql.gz';
        }
        $final = $dir . DIRECTORY_SEPARATOR . $filename;
        jq_gzip_file($tmpSql, $final);
        $sha = hash_file('sha256', $final);
        $size = (int)filesize($final);
        $safeOriginal = preg_replace('/[^A-Za-z0-9_.\- ]/', '_', mb_substr($original, 0, 120));
        jq_backup_write_manifest($final, [
            'app' => "Janet's Quality Catering", 'format' => 1, 'filename' => $filename, 'type' => 'uploaded',
            'database' => DB_NAME, 'created_at' => jq_now()->format('Y-m-d H:i:s'), 'timezone' => 'Asia/Manila',
            'created_by' => $actor['username'] ?? '', 'sha256' => $sha, 'size_bytes' => $size,
            'uncompressed_bytes' => (int)filesize($tmpSql), 'table_count' => count($scan['tables']), 'row_count' => null,
            'table_names' => $scan['tables'], 'notes' => 'Uploaded from ' . $safeOriginal,
        ]);
        $st = $pdo->prepare("INSERT INTO database_backups (filename, backup_type, file_size, checksum_sha256, table_count, row_count, db_name, status, notes, created_by, created_by_username)
                             VALUES (?, 'uploaded', ?, ?, ?, 0, ?, 'available', ?, (SELECT u.id FROM users u WHERE u.id = ?), ?)");
        $st->execute([$filename, $size, $sha, count($scan['tables']), DB_NAME, 'Uploaded from ' . $safeOriginal, $actor['id'] ?? null, $actor['username'] ?? '']);
        $id = (int)$pdo->lastInsertId();
        $ms = (int)round((microtime(true) - $t0) * 1000);
        jq_backup_history($pdo, $id, $filename, 'UPLOAD', 'success', 'Uploaded "' . $safeOriginal . '" (' . count($scan['tables']) . ' tables, ' . jq_human_size($size) . ').', $ms, $actor);
        jq_backup_log($pdo, 'BACKUP_UPLOAD', 'Uploaded backup file "' . $safeOriginal . '" as ' . $filename, $actor,
            ['record_table' => 'database_backups', 'record_id' => $id, 'record_label' => $filename]);
        return ['success' => true, 'message' => 'Backup uploaded and validated as ' . $filename . '. Use "Verify" to test-restore it before restoring.', 'filename' => $filename];
    } catch (Throwable $e) {
        jq_backup_history($pdo, null, null, 'UPLOAD', 'failed', $e->getMessage(), null, $actor);
        jq_backup_log($pdo, 'BACKUP_FAILED', 'Backup upload rejected: ' . $e->getMessage(), $actor);
        return ['success' => false, 'message' => $e->getMessage()];
    } finally {
        jq_unlink($tmpSql);
    }
}

/* =====================================================================
 *  DOWNLOAD
 * ===================================================================== */

function jq_backup_send_download(PDO $pdo, string $filename, array $actor, bool $plainSql = false): void
{
    $path = jq_backup_path($filename);
    jq_backup_history($pdo, null, $filename, 'DOWNLOAD', 'success', 'Backup file downloaded (' . jq_human_size((int)filesize($path)) . ').', null, $actor);
    jq_backup_log($pdo, 'BACKUP_DOWNLOAD', 'Downloaded database backup ' . $filename, $actor, ['record_table' => 'database_backups', 'record_label' => $filename]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    if ($plainSql) {
        // Decompressed on the fly: a plain .sql file, also accepted by Upload backup
        $gz = gzopen($path, 'rb');
        if (!$gz) {
            throw new JqBackupException('The backup file could not be opened.');
        }
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . substr($filename, 0, -3) . '"');
        while (!gzeof($gz)) {
            echo gzread($gz, 1048576);
        }
        gzclose($gz);
        exit;
    }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/**
 * The newest manual backup if it is under 5 minutes old, otherwise a new one, so repeated
 * clicks on "Download database backup" do not pile up identical files.
 */
function jq_backup_latest_or_create(PDO $pdo, array $actor): array
{
    foreach (jq_backup_list($pdo) as $b) {
        if ($b['type'] === 'manual' && jq_now()->getTimestamp() - (new DateTimeImmutable($b['created_at'], jq_tz()))->getTimestamp() < 300) {
            return ['success' => true, 'filename' => $b['filename'], 'message' => ''];
        }
    }
    return jq_backup_create($pdo, 'manual', $actor, 'Created by Download database backup');
}

/* =====================================================================
 *  RETENTION
 * ===================================================================== */

/** Keeps only the newest $keep SCHEDULED backups (manual, uploaded and safety backups are never removed). */
function jq_backup_apply_retention(PDO $pdo, int $keep, array $actor): int
{
    if ($keep <= 0) {
        return 0;
    }
    $scheduled = array_values(array_filter(jq_backup_list($pdo), static fn($b) => $b['type'] === 'scheduled'));
    $removed = 0;
    foreach (array_slice($scheduled, $keep) as $b) {
        $path = jq_backup_dir() . DIRECTORY_SEPARATOR . $b['filename'];
        if (@unlink($path)) {
            jq_unlink($path . '.json');
            $removed++;
            try {
                $pdo->prepare("UPDATE database_backups SET status = 'removed' WHERE filename = ?")->execute([$b['filename']]);
            } catch (Throwable $e) {
            }
            jq_backup_history($pdo, $b['id'], $b['filename'], 'RETENTION_REMOVE', 'success', "Removed by retention policy (keep newest $keep scheduled backups).", null, $actor);
        }
    }
    return $removed;
}

/* =====================================================================
 *  SCHEDULING
 * ===================================================================== */

/** Most recent scheduled moment that is <= $now. */
function jq_backup_last_slot(array $s, DateTimeImmutable $now): DateTimeImmutable
{
    [$h, $m] = array_map('intval', explode(':', preg_match('/^\d{1,2}:\d{2}$/', $s['backup_time']) ? $s['backup_time'] : '02:00'));
    switch ($s['backup_frequency']) {
        case 'weekly':
            $wd = max(1, min(7, (int)$s['backup_weekday']));
            $slot = $now->setISODate((int)$now->format('o'), (int)$now->format('W'), $wd)->setTime($h, $m);
            return $slot > $now ? $slot->modify('-7 days') : $slot;
        case 'monthly':
            $md = max(1, min(28, (int)$s['backup_monthday']));
            $slot = $now->setDate((int)$now->format('Y'), (int)$now->format('n'), $md)->setTime($h, $m);
            return $slot > $now ? $slot->modify('-1 month') : $slot;
        default:
            $slot = $now->setTime($h, $m);
            return $slot > $now ? $slot->modify('-1 day') : $slot;
    }
}

function jq_backup_next_run(array $s, DateTimeImmutable $now): ?DateTimeImmutable
{
    if ($s['backup_auto_enabled'] !== '1') {
        return null;
    }
    if (jq_backup_is_due($s, $now)) {
        return $now;
    }
    $slot = jq_backup_last_slot($s, $now);
    $step = ['weekly' => '+7 days', 'monthly' => '+1 month'][$s['backup_frequency']] ?? '+1 day';
    return $slot->modify($step);
}

function jq_backup_is_due(array $s, DateTimeImmutable $now): bool
{
    if ($s['backup_auto_enabled'] !== '1') {
        return false;
    }
    $slot = jq_backup_last_slot($s, $now);
    $last = $s['backup_last_auto_at'] !== '' ? new DateTimeImmutable($s['backup_last_auto_at'], jq_tz()) : null;
    if ($last && $last >= $slot) {
        return false;
    }
    // after a failed attempt, retry at most every 30 minutes
    $attempt = $s['backup_last_auto_attempt'] !== '' ? new DateTimeImmutable($s['backup_last_auto_attempt'], jq_tz()) : null;
    return !($attempt && $attempt > $now->modify('-30 minutes') && $attempt >= $slot);
}

/** Runs the scheduled backup when due. Returns the result, or null when nothing was due. */
function jq_backup_run_scheduled(PDO $pdo, string $trigger = 'web'): ?array
{
    $s = jq_backup_settings($pdo);
    $now = jq_now();
    if (!jq_backup_is_due($s, $now)) {
        return null;
    }
    jq_setting_set($pdo, 'backup_last_auto_attempt', $now->format('Y-m-d H:i:s'));
    $actor = ['id' => null, 'username' => 'system (scheduler/' . $trigger . ')', 'role' => 'SYSTEM'];
    $res = jq_backup_create($pdo, 'scheduled', $actor, ucfirst($s['backup_frequency']) . ' scheduled backup (' . $trigger . ')');
    if ($res['success']) {
        jq_setting_set($pdo, 'backup_last_auto_at', $now->format('Y-m-d H:i:s'));
        jq_setting_set($pdo, 'backup_last_auto_status', 'success');
        $removed = jq_backup_apply_retention($pdo, (int)$s['backup_retention'], $actor);
        $res['removed'] = $removed;
    } else {
        jq_setting_set($pdo, 'backup_last_auto_status', 'failed: ' . mb_substr($res['message'], 0, 200));
    }
    return $res;
}

/**
 * Web fallback for the scheduler: after the page has been delivered, run a due backup.
 * (Windows Task Scheduler / cron with cli/backup_cli.php is the primary trigger.)
 */
function jq_backup_register_scheduler(?PDO $pdo): void
{
    if (!$pdo || PHP_SAPI === 'cli') {
        return;
    }
    if (jq_is_hosted()) {
        // Hosted: Supabase keeps the database backups, and there is no scheduled task, so the archive clean-up
        // runs from page visits at most once an hour.
        $last = jq_setting_get($pdo, 'retention_last_run');
        if (!$last || time() - strtotime($last) >= 3600) {
            try {
                require_once __DIR__ . '/archive_manager.php';
                jq_purge_expired_archives($pdo);
            } catch (Throwable $e) {
                error_log('Archive clean-up (hosted) failed: ' . $e->getMessage());
            }
        }
        return;
    }
    register_shutdown_function(static function () use ($pdo) {
        try {
            if (!jq_backup_is_due(jq_backup_settings($pdo), jq_now())) {
                return;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            ignore_user_abort(true);
            @set_time_limit(900);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                @flush();
            }
            jq_backup_run_scheduled($pdo, 'web');
        } catch (Throwable $e) {
            error_log('Scheduled backup (web trigger) failed: ' . $e->getMessage());
        }
    });
}
