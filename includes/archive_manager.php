<?php
/**
 * ARCHIVE RETENTION - Janet's Quality Catering System
 *
 * Archived event bookings and reports are permanently deleted when their archive_expires_at passes,
 * 30 days after they were archived. cli/maintenance_cli.php runs jq_purge_expired_archives() from a
 * scheduled task, so deletion never depends on someone opening an archive page.
 * Accounts are never deleted by this process: see the account rules in accounts.php.
 */

if (defined('JQ_ARCHIVE_MANAGER_LOADED')) {
    return;
}
define('JQ_ARCHIVE_MANAGER_LOADED', true);

require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/booking_manager.php';

const JQ_ARCHIVE_RETENTION_DAYS = 30;
/** The task runs every hour, so a longer silence means it stopped. */
const JQ_RETENTION_STALE_HOURS = 26;

/**
 * Permanently deletes one archived booking in its own transaction.
 * A client's survey stays on record and keeps its own copy of the names; its link is cleared.
 * Line items, inclusions, payments and attachments are removed by their foreign keys, and the stored files with them.
 * @return string|null the booking reference, or null when the booking is not archived
 */
function jq_delete_archived_booking(PDO $pdo, int $bookingId): ?string
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT booking_ref FROM event_bookings WHERE booking_id = ? AND is_archived = 1 FOR UPDATE');
        $st->execute([$bookingId]);
        $ref = $st->fetchColumn();
        if ($ref === false) {
            $pdo->rollBack();
            return null;
        }
        $files = jq_booking_file_paths($pdo, $bookingId);
        $pdo->prepare('UPDATE client_surveys SET booking_id = NULL WHERE booking_id = ?')->execute([$bookingId]);
        $pdo->prepare('DELETE FROM event_bookings WHERE booking_id = ?')->execute([$bookingId]);
        $pdo->commit();
        jq_remove_private_files($files); // only after the rows are really gone
        return (string)$ref;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Finds and deletes every archived booking and report past its expiry date.
 * A record restored while this runs is left alone, because each delete re-checks it under a lock.
 * @return array{bookings:int, reports:int, errors:int, skipped:bool, dry_run:bool, due_bookings:int, due_reports:int}
 */
function jq_purge_expired_archives(PDO $pdo, bool $dryRun = false): array
{
    $result = ['bookings' => 0, 'reports' => 0, 'errors' => 0, 'skipped' => false, 'dry_run' => $dryRun, 'due_bookings' => 0, 'due_reports' => 0];

    if (!jq_db_lock($pdo, 'jq_archive_purge')) {
        $result['skipped'] = true; // another run is in progress
        return $result;
    }
    try {
        $bookingIds = $pdo->query('SELECT booking_id FROM event_bookings WHERE is_archived = 1 AND archive_expires_at <= NOW() ORDER BY booking_id')->fetchAll(PDO::FETCH_COLUMN);
        $reportIds  = $pdo->query('SELECT id FROM reports WHERE is_archived = 1 AND archive_expires_at <= NOW() ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $result['due_bookings'] = count($bookingIds);
        $result['due_reports']  = count($reportIds);
        if ($dryRun) {
            return $result;
        }

        foreach ($bookingIds as $id) {
            try {
                if (jq_delete_archived_booking($pdo, (int)$id) !== null) {
                    $result['bookings']++;
                }
            } catch (Throwable $e) {
                $result['errors']++;
                error_log('Archive purge, booking ' . $id . ': ' . $e->getMessage());
            }
        }

        $deleteReport = $pdo->prepare('DELETE FROM reports WHERE id = ? AND is_archived = 1 AND archive_expires_at <= NOW()');
        foreach ($reportIds as $id) {
            try {
                $deleteReport->execute([(int)$id]);
                $result['reports'] += $deleteReport->rowCount();
            } catch (Throwable $e) {
                $result['errors']++;
                error_log('Archive purge, report ' . $id . ': ' . $e->getMessage());
            }
        }

        $summary = "Deleted {$result['bookings']} expired booking(s) and {$result['reports']} expired report(s), {$result['errors']} error(s).";
        jq_setting_set($pdo, 'retention_last_run', date('Y-m-d H:i:s'));
        jq_setting_set($pdo, 'retention_last_result', $summary);
        if ($result['bookings'] + $result['reports'] + $result['errors'] > 0) {
            logActivity($pdo, 'RETENTION_PURGE', 'Archive Retention', 'Automatic archive clean-up: ' . $summary);
        }
        return $result;
    } finally {
        jq_db_unlock($pdo, 'jq_archive_purge');
    }
}

/** Whole days until a record's archive expiry, never below zero. */
function jq_archive_days_left(?string $expiresAt): int
{
    if (!$expiresAt) {
        return 0;
    }
    return max(0, (int)ceil((strtotime($expiresAt) - time()) / 86400));
}

/** @return array{ok: bool, message: string} whether the scheduled clean-up has been running */
function jq_retention_status(PDO $pdo): array
{
    $last = jq_setting_get($pdo, 'retention_last_run');
    if (!$last) {
        return ['ok' => false, 'message' => 'The automatic clean-up has not run yet. Expired records stay here until it does.'];
    }
    $when = date('M d, Y h:i A', strtotime($last));
    if (time() - strtotime($last) > JQ_RETENTION_STALE_HOURS * 3600) {
        return ['ok' => false, 'message' => 'The automatic clean-up last ran on ' . $when . '. Check that the scheduled task is enabled and that MySQL is running.'];
    }
    return ['ok' => true, 'message' => 'Automatic clean-up last ran on ' . $when . '.'];
}
