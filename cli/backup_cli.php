<?php
/**
 * Command-line backup tool – Janet's Quality Catering System
 *
 * Usage (from the project folder):
 *   php cli/backup_cli.php scheduled            Run the scheduled backup if it is due (use with Task Scheduler / cron)
 *   php cli/backup_cli.php backup               Create a manual backup now
 *   php cli/backup_cli.php list                 List available backups
 *   php cli/backup_cli.php verify  <file>       Test-restore a backup into a temporary database
 *   php cli/backup_cli.php restore <file> --yes Disaster recovery: restore a backup (creates a safety backup first)
 *
 * Windows Task Scheduler (runs every hour; the configured daily/weekly/monthly schedule decides when a backup is made):
 *   schtasks /Create /SC HOURLY /TN "JanetsCateringBackup" /TR "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\VERANO\cli\backup_cli.php\" scheduled"
 * Linux cron:
 *   0 * * * * php /var/www/html/VERANO/cli/backup_cli.php scheduled >> /var/log/janets_backup.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../includes/backup_manager.php';

$cmd  = $argv[1] ?? 'help';
$arg  = $argv[2] ?? null;
$yes  = in_array('--yes', $argv, true);
$actor = ['id' => null, 'username' => 'system (CLI)', 'role' => 'SYSTEM'];

$out = static function (string $msg): void {
    fwrite(STDOUT, '[' . jq_now()->format('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
};

$pdo = getDBConnection();
if (!$pdo && $cmd !== 'help') {
    fwrite(STDERR, "Cannot connect to the database (check config/database.php and that MySQL is running).\n");
    exit(2);
}
if ($pdo) {
    jq_ensure_audit_schema($pdo);
}

switch ($cmd) {
    case 'scheduled':
        $res = jq_backup_run_scheduled($pdo, 'cli');
        if ($res === null) {
            $next = jq_backup_next_run(jq_backup_settings($pdo), jq_now());
            $out('No backup due. Next scheduled run: ' . ($next ? $next->format('Y-m-d H:i') : 'automatic backups are disabled'));
            exit(0);
        }
        $out($res['message'] . (isset($res['removed']) && $res['removed'] ? " ({$res['removed']} old scheduled backup(s) removed by retention)" : ''));
        exit($res['success'] ? 0 : 1);

    case 'backup':
        $res = jq_backup_create($pdo, 'manual', $actor, 'Created from the command line');
        $out($res['message']);
        exit($res['success'] ? 0 : 1);

    case 'list':
        foreach (jq_backup_list($pdo) as $b) {
            fwrite(STDOUT, sprintf("%-60s %-12s %s %10s\n", $b['filename'], $b['type'], $b['created_at'], jq_human_size($b['size'])));
        }
        exit(0);

    case 'verify':
        if (!$arg) {
            fwrite(STDERR, "Usage: php cli/backup_cli.php verify <file>\n");
            exit(64);
        }
        $res = jq_backup_verify($pdo, basename($arg), $actor);
        $out(($res['success'] ? 'OK: ' : 'FAILED: ') . $res['message']);
        exit($res['success'] ? 0 : 1);

    case 'restore':
        if (!$arg) {
            fwrite(STDERR, "Usage: php cli/backup_cli.php restore <file> --yes\n");
            exit(64);
        }
        if (!$yes) {
            fwrite(STDERR, "This REPLACES the current database with " . basename($arg) . ".\n"
                . "A safety backup is created first. Re-run with --yes to continue.\n");
            exit(64);
        }
        $res = jq_backup_restore($pdo, basename($arg), $actor);
        $out(($res['success'] ? 'RESTORED: ' : 'FAILED: ') . $res['message']);
        foreach ($res['steps'] ?? [] as $s) {
            $out('  - ' . $s);
        }
        exit($res['success'] ? 0 : 1);

    default:
        fwrite(STDOUT, "Janet's Quality Catering – backup tool\n\n"
            . "  php cli/backup_cli.php scheduled            Run the scheduled backup if due\n"
            . "  php cli/backup_cli.php backup               Create a backup now\n"
            . "  php cli/backup_cli.php list                 List backups\n"
            . "  php cli/backup_cli.php verify  <file>       Test-restore into a temporary database\n"
            . "  php cli/backup_cli.php restore <file> --yes Restore (safety backup is made first)\n");
        exit(0);
}
