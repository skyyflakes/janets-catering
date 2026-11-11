<?php
/**
 * Scheduled maintenance - Janet's Quality Catering System
 *
 *   php cli/maintenance_cli.php purge             Delete archived bookings and reports past their 30 days
 *   php cli/maintenance_cli.php purge --dry-run   Only report what is due
 *
 * Windows Task Scheduler: run cli/register_scheduled_task.bat once.
 * Linux cron:  0 * * * * php /var/www/html/VERANO/cli/maintenance_cli.php purge
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/archive_manager.php';

$cmd = $argv[1] ?? 'help';
if ($cmd !== 'purge') {
    fwrite(STDOUT, "Usage: php cli/maintenance_cli.php purge [--dry-run]\n");
    exit($cmd === 'help' ? 0 : 64);
}

$pdo = getDBConnection();
if (!$pdo) {
    fwrite(STDERR, "Cannot connect to the database. Check that MySQL is running.\n");
    exit(2);
}
jq_ensure_audit_schema($pdo);

$r = jq_purge_expired_archives($pdo, in_array('--dry-run', $argv, true));
if ($r['skipped']) {
    fwrite(STDOUT, "Another clean-up is already running.\n");
    exit(0);
}
if ($r['dry_run']) {
    fwrite(STDOUT, "Due now: {$r['due_bookings']} booking(s), {$r['due_reports']} report(s). Nothing was deleted.\n");
    exit(0);
}
fwrite(STDOUT, "Deleted {$r['bookings']} booking(s) and {$r['reports']} report(s), {$r['errors']} error(s).\n");
exit($r['errors'] > 0 ? 1 : 0);
