<?php
/**
 * DATABASE BACKUP & RESTORE MODULE
 * Janet's Quality Catering System
 *
 * ADMIN only: create, download, upload, verify (test-restore), restore, configure automatic backups.
 * The OWNER has no access. Every request is checked on the server.
 */

$page_title   = "Backup & Restore | Janet's Quality Catering";
$current_page = 'backups';

require_once 'includes/auth_check.php';
/** @var PDO|null $pdo */

if (!$pdo) {
    setFlash('The database is not available. Use cli/backup_cli.php for disaster recovery.', 'danger');
    redirect('dashboard.php');
}

require_once 'includes/account_security.php';

$role  = jq_require_roles($pdo, ['ADMIN'], 'Backup & Restore');
$actor = jq_actor($pdo);


/* =====================================================================
 *  POST actions
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = (string)($_POST['op'] ?? '');
    if (!jq_csrf_valid($_POST['csrf_token'] ?? null)) {
        setFlash('Your session security token expired. Please try again.', 'warning');
        redirect('backups.php');
    }
    $file = basename((string)($_POST['file'] ?? ''));

    switch ($op) {
        case 'create':
            $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 300);
            $res = jq_backup_create($pdo, 'manual', $actor, $notes);
            setFlash($res['message'] . (!empty($res['secondary']['configured']) && empty($res['secondary']['ok']) ? ' Warning: the copy to the secondary destination failed.' : ''), $res['success'] ? 'success' : 'danger');
            break;

        case 'download_now':
            $res = jq_backup_latest_or_create($pdo, $actor);
            if ($res['success']) {
                try {
                    jq_backup_send_download($pdo, $res['filename'], $actor, true); // exits on success
                } catch (Throwable $e) {
                    setFlash('Download failed: ' . $e->getMessage(), 'danger');
                }
            } else {
                setFlash($res['message'], 'danger');
            }
            break;

        case 'download':
            try {
                jq_backup_send_download($pdo, $file, $actor); // exits on success
            } catch (Throwable $e) {
                setFlash('Download failed: ' . $e->getMessage(), 'danger');
            }
            break;

        case 'verify':
            $res = jq_backup_verify($pdo, $file, $actor);
            $_SESSION['jq_backup_result'] = ['title' => 'Verification of ' . $file, 'ok' => $res['success'] && ($res['status'] ?? '') === 'success',
                                             'warn' => ($res['status'] ?? '') === 'warning', 'lines' => array_merge([$res['message']], $res['details'] ?? [])];
            setFlash($res['success'] ? 'Verification finished: ' . (($res['status'] ?? '') === 'success' ? 'the backup restores correctly.' : 'completed with differences.') : 'Verification failed: ' . $res['message'],
                     $res['success'] ? (($res['status'] ?? '') === 'success' ? 'success' : 'warning') : 'danger');
            break;

        case 'restore':
            $confirm  = trim((string)($_POST['confirm_text'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $ack      = !empty($_POST['acknowledge']);
            if ($confirm !== 'RESTORE' || !$ack) {
                setFlash('Restore cancelled: type RESTORE and tick the confirmation box to continue.', 'warning');
                break;
            }
            if (!jq_password_matches_account($pdo, (int)$_SESSION['user_id'], $password)) {
                logActivity($pdo, 'ACCESS_DENIED', 'Database Backup', 'Database restore of ' . $file . ' refused: password confirmation failed');
                setFlash('Restore cancelled: your password is incorrect.', 'danger');
                break;
            }
            $res = jq_backup_restore($pdo, $file, $actor);
            $_SESSION['jq_backup_result'] = ['title' => ($res['success'] ? 'Database restored from ' : 'Restore failed: ') . $file,
                                             'ok' => $res['success'] && empty($res['problems']), 'warn' => $res['success'] && !empty($res['problems']),
                                             'lines' => array_merge($res['steps'] ?? [], $res['success'] ? [] : [$res['message']])];
            setFlash($res['success'] ? $res['message'] . ' A safety backup of the previous state was saved as ' . $res['safety'] . '.' : $res['message'], $res['success'] ? 'success' : 'danger');
            break;

        case 'upload':
            $res = jq_backup_upload($pdo, $_FILES['backup_file'] ?? [], $actor);
            setFlash($res['message'], $res['success'] ? 'success' : 'danger');
            break;

        case 'settings':
            $old = jq_backup_settings($pdo);
            $new = [
                'backup_auto_enabled'     => !empty($_POST['auto_enabled']) ? '1' : '0',
                'backup_frequency'        => in_array($_POST['frequency'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $_POST['frequency'] : 'daily',
                'backup_time'             => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($_POST['time'] ?? '')) ? $_POST['time'] : '02:00',
                'backup_weekday'          => (string)max(1, min(7, (int)($_POST['weekday'] ?? 7))),
                'backup_monthday'         => (string)max(1, min(28, (int)($_POST['monthday'] ?? 1))),
                'backup_retention'        => (string)max(0, min(365, (int)($_POST['retention'] ?? 14))),
                'backup_secondary_dir'    => trim((string)($_POST['secondary_dir'] ?? '')),
            ];
            $errors = [];
            if ($new['backup_secondary_dir'] !== '') {
                $sd = $new['backup_secondary_dir'];
                if (mb_strlen($sd) > 400 || !preg_match('#^([A-Za-z]:[\\\\/]|\\\\\\\\|/)#', $sd)) {
                    $errors[] = 'The secondary destination must be an absolute folder path (e.g. D:\\Backups or \\\\server\\share).';
                } elseif (!is_dir($sd) || !is_writable($sd)) {
                    $errors[] = 'The secondary destination folder does not exist or is not writable by the web server.';
                } elseif (jq_path_is_public($sd)) {
                    $errors[] = 'The secondary destination must be outside the public web folder.';
                } elseif (jq_normalize_path($sd) === jq_normalize_path(jq_backup_dir())) {
                    $errors[] = 'The secondary destination must be different from the primary backup folder.';
                }
            }
            if ($errors) {
                setFlash(implode(' ', $errors), 'danger');
                break;
            }
            $changes = [];
            foreach ($new as $k => $v) {
                if ((string)$old[$k] !== $v) {
                    $changes[$k] = ['old' => $old[$k], 'new' => $v];
                    jq_setting_set($pdo, $k, $v, (int)$_SESSION['user_id']);
                }
            }
            if ($changes) {
                $oldVals = array_map(static fn($c) => $c['old'], $changes);
                $newVals = array_map(static fn($c) => $c['new'], $changes);
                logActivity($pdo, 'BACKUP_SETTINGS', 'Database Backup', 'Changed backup settings: ' . implode(', ', array_keys($changes)),
                    ['record_table' => 'system_settings', 'old_values' => $oldVals, 'new_values' => $newVals]);
                jq_backup_history($pdo, null, null, 'SETTINGS', 'success', 'Changed: ' . implode(', ', array_map(static fn($k, $c) => "$k {$c['old']} → {$c['new']}", array_keys($changes), $changes)), null, $actor);
                setFlash('Backup settings saved.', 'success');
            } else {
                setFlash('No settings were changed.', 'info');
            }
            break;

        default:
            setFlash('Unknown action.', 'warning');
    }
    redirect('backups.php');
}

/* =====================================================================
 *  PAGE DATA
 * ===================================================================== */
$tool_dump  = jq_find_binary('mysqldump');
$tool_mysql = jq_find_binary('mysql');
$dir_error  = null;
try {
    $backup_dir = jq_backup_dir();
} catch (Throwable $e) {
    $backup_dir = BACKUP_DIR;
    $dir_error  = $e->getMessage();
}
$dir_public = jq_path_is_public($backup_dir);
$backups    = $dir_error ? [] : jq_backup_list($pdo);
$settings   = jq_backup_settings($pdo);
$now        = jq_now();
$next_run   = jq_backup_next_run($settings, $now);
$total_size = array_sum(array_column($backups, 'size'));
$latest     = $backups[0] ?? null;
$latest_age = null;
if ($latest) {
    $diff = $now->getTimestamp() - (new DateTimeImmutable($latest['created_at'], jq_tz()))->getTimestamp();
    $latest_age = $diff < 3600 ? max(1, (int)floor($diff / 60)) . ' min ago' : ($diff < 86400 ? floor($diff / 3600) . ' h ago' : floor($diff / 86400) . ' day(s) ago');
}
$hist_page = max(1, (int)($_GET['hpage'] ?? 1));
$hist_total = (int)$pdo->query("SELECT COUNT(*) FROM backup_history")->fetchColumn();
$hist_pages = max(1, (int)ceil($hist_total / 15));
$hist_page = min($hist_page, $hist_pages);
$st = $pdo->prepare("SELECT * FROM backup_history ORDER BY id DESC LIMIT 15 OFFSET ?");
$st->bindValue(1, ($hist_page - 1) * 15, PDO::PARAM_INT);
$st->execute();
$history = $st->fetchAll(PDO::FETCH_ASSOC);
$result_box = $_SESSION['jq_backup_result'] ?? null;
unset($_SESSION['jq_backup_result']);
$weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$php_bin = PHP_BINARY && stripos(PHP_BINARY, 'httpd') === false ? PHP_BINARY : (DIRECTORY_SEPARATOR === '\\' ? 'C:\\xampp\\php\\php.exe' : 'php');
$cli_path = __DIR__ . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'backup_cli.php';
$type_tone = ['manual' => 'brand', 'scheduled' => 'edit', 'pre_restore' => 'archive', 'uploaded' => 'view'];
$hist_tone = ['CREATE' => 'brand', 'RESTORE' => 'restore', 'VERIFY' => 'edit', 'DOWNLOAD' => 'view', 'UPLOAD' => 'view', 'SETTINGS' => 'edit', 'RETENTION_REMOVE' => 'archive'];

require_once 'includes/header.php';
?>

<div id="jqPage">
    <div class="jq-page-header">
        <div>
            <h1 class="jq-title">Backup &amp; Restore <span class="jq-role-chip"><i class="bx bx-key"></i>Admin</span></h1>
            <p class="jq-subtitle">Complete copies of the database — structure, every record (old and new), triggers and relationships.</p>
        </div>
        <div class="jq-header-actions">
            <button type="button" class="jq-btn jq-btn-view" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="bx bx-upload"></i> Upload backup</button>
            <form method="POST" data-no-loading="1" class="d-inline">
                <?= jq_csrf_field() ?>
                <input type="hidden" name="op" value="download_now">
                <button type="submit" class="jq-btn jq-btn-edit" <?= (!$tool_dump || $dir_error) ? 'disabled' : '' ?>><i class="bx bx-download"></i> Download database backup</button>
            </form>
            <form method="POST" id="createForm" data-no-loading="1" class="d-inline">
                <?= jq_csrf_field() ?>
                <input type="hidden" name="op" value="create">
                <input type="hidden" name="notes" id="createNotes">
                <button type="button" class="jq-btn jq-btn-primary" onclick="confirmCreate()" <?= (!$tool_dump || $dir_error) ? 'disabled' : '' ?>><i class="bx bx-data"></i> Create backup now</button>
            </form>
        </div>
    </div>

    <?php if ($result_box): ?>
    <div class="jq-alert <?= $result_box['ok'] ? 'jq-alert-success' : ($result_box['warn'] ? 'jq-alert-warning' : 'jq-alert-danger') ?>" role="status">
        <i class="bx <?= $result_box['ok'] ? 'bx-check-circle' : ($result_box['warn'] ? 'bx-error' : 'bx-x-circle') ?>"></i>
        <div><p><strong><?= jq_e($result_box['title']) ?></strong></p>
            <ul style="margin:6px 0 0; padding-left:18px;"><?php foreach ($result_box['lines'] as $line): ?><li><?= jq_e($line) ?></li><?php endforeach; ?></ul></div>
    </div>
    <?php endif; ?>

    <?php if ($dir_error): ?>
    <div class="jq-alert jq-alert-danger"><i class="bx bx-error-circle"></i><p><strong>Backup folder problem:</strong> <?= jq_e($dir_error) ?> Check BACKUP_DIR in config/backup_config.php.</p></div>
    <?php endif; ?>
    <?php if (!$tool_dump || !$tool_mysql): ?>
    <div class="jq-alert jq-alert-danger"><i class="bx bx-error-circle"></i><p><strong>MySQL tools not found.</strong> <?= !$tool_dump ? 'mysqldump' : '' ?><?= !$tool_dump && !$tool_mysql ? ' and ' : '' ?><?= !$tool_mysql ? 'mysql' : '' ?> could not be located. Set MYSQL_BIN_DIR in config/backup_config.php.</p></div>
    <?php endif; ?>
    <?php if (!$dir_error && $dir_public): ?>
    <div class="jq-alert jq-alert-warning"><i class="bx bx-error"></i><p><strong>The backup folder is inside the public web folder.</strong> Access is blocked with .htaccess/web.config, but you should move BACKUP_DIR outside the web root.</p></div>
    <?php endif; ?>

    <!-- Status -->
    <div class="jq-stats">
        <div class="jq-stat">
            <div><span class="jq-stat-label">Latest backup</span>
                <div class="jq-stat-value" style="font-size:1.15rem;"><?= $latest ? jq_e((new DateTimeImmutable($latest['created_at']))->format('M d, h:i A')) : 'None yet' ?></div>
                <span class="jq-stat-hint"><?= $latest ? jq_e($latest_age . ' · ' . jq_backup_type_label($latest['type'])) : 'Create your first backup' ?></span></div>
            <div class="jq-stat-icon <?= $latest ? 'jq-tone-restore' : 'jq-tone-delete' ?>"><i class="bx bx-check-shield"></i></div>
        </div>
        <div class="jq-stat">
            <div><span class="jq-stat-label">Next automatic backup</span>
                <div class="jq-stat-value" style="font-size:1.15rem;"><?= $next_run ? ($next_run <= $now ? 'Due now' : jq_e($next_run->format('M d, h:i A'))) : 'Disabled' ?></div>
                <span class="jq-stat-hint"><?= $settings['backup_auto_enabled'] === '1' ? jq_e(ucfirst($settings['backup_frequency']) . ($settings['backup_last_auto_status'] && $settings['backup_last_auto_status'] !== 'success' ? ' · last run failed' : '')) : 'Turn on in the schedule settings' ?></span></div>
            <div class="jq-stat-icon <?= $settings['backup_auto_enabled'] === '1' ? 'jq-tone-edit' : 'jq-tone-view' ?>"><i class="bx bx-time-five"></i></div>
        </div>
        <div class="jq-stat">
            <div><span class="jq-stat-label">Backups stored</span>
                <div class="jq-stat-value"><?= count($backups) ?></div>
                <span class="jq-stat-hint"><?= jq_e(jq_human_size((int)$total_size)) ?> in total</span></div>
            <div class="jq-stat-icon jq-tone-brand"><i class="bx bx-archive"></i></div>
        </div>
        <div class="jq-stat">
            <div><span class="jq-stat-label">Off-site copy</span>
                <div class="jq-stat-value" style="font-size:1.15rem;"><?= $settings['backup_secondary_dir'] !== '' ? 'Configured' : 'Not configured' ?></div>
                <span class="jq-stat-hint"><?= $settings['backup_secondary_dir'] !== '' ? 'Each backup is copied & verified' : 'Recommended for disaster recovery' ?></span></div>
            <div class="jq-stat-icon <?= $settings['backup_secondary_dir'] !== '' ? 'jq-tone-restore' : 'jq-tone-archive' ?>"><i class="bx bx-cloud-upload"></i></div>
        </div>
    </div>

    <!-- Backups list -->
    <div class="jq-card">
        <div class="jq-card-header">
            <h2 class="jq-card-title"><i class="bx bx-data"></i> Available backups</h2>
            <span class="jq-muted jq-small"><i class="bx bx-folder"></i> Stored outside the website: <span class="jq-mono"><?= jq_e($backup_dir) ?></span></span>
        </div>
        <?php if (!$backups): ?>
        <div class="jq-empty"><i class="bx bx-data"></i><h4>No backups yet</h4><p>Click “Create backup now” to make a complete copy of the database.</p></div>
        <?php else: ?>
        <div class="jq-table-wrap">
            <table class="jq-table">
                <thead><tr><th>Backup file</th><th>Type</th><th>Date &amp; Time</th><th>Size</th><th>Contents</th><th>Integrity</th><th>Created by</th><th class="jq-col-actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($backups as $b):
                    $dt = new DateTimeImmutable($b['created_at']); ?>
                    <tr>
                        <td><span class="jq-filename"><?= jq_e($b['filename']) ?></span>
                            <?php if ($b['notes']): ?><span class="jq-sub"><?= jq_e(mb_strimwidth($b['notes'], 0, 60, '…')) ?></span><?php endif; ?></td>
                        <td><span class="jq-badge jq-badge-<?= $type_tone[$b['type']] ?? 'view' ?>"><?= jq_e(jq_backup_type_label($b['type'])) ?></span></td>
                        <td style="white-space:nowrap;"><strong><?= jq_e($dt->format('M d, Y')) ?></strong><span class="jq-sub"><?= jq_e($dt->format('h:i:s A')) ?></span></td>
                        <td style="white-space:nowrap;"><?= jq_e(jq_human_size($b['size'])) ?></td>
                        <td style="white-space:nowrap;"><?= $b['tables'] !== null ? (int)$b['tables'] . ' tables' : '—' ?><span class="jq-sub"><?= $b['rows'] ? number_format((int)$b['rows']) . ' rows' : '' ?></span></td>
                        <td>
                            <?php if ($b['verify_status'] === 'verified'): ?><span class="jq-badge jq-badge-restore" title="Last test-restore <?= jq_e($b['verified_at']) ?>"><i class="bx bx-check"></i> Verified</span>
                            <?php elseif ($b['verify_status'] === 'warning'): ?><span class="jq-badge jq-badge-archive"><i class="bx bx-error"></i> Differences</span>
                            <?php elseif ($b['verify_status'] === 'failed'): ?><span class="jq-badge jq-badge-delete"><i class="bx bx-x"></i> Failed</span>
                            <?php elseif ($b['has_manifest'] && $b['sha256']): ?><span class="jq-badge jq-badge-view" title="SHA-256 <?= jq_e($b['sha256']) ?>"><i class="bx bx-fingerprint"></i> Checksum</span>
                            <?php else: ?><span class="jq-badge jq-badge-archive"><i class="bx bx-help-circle"></i> Unverified</span><?php endif; ?>
                            <?php if ($b['secondary']): ?><span class="jq-sub"><i class="bx bx-copy"></i> Off-site copy</span><?php endif; ?>
                        </td>
                        <td style="min-width:110px;"><?= jq_e($b['created_by']) ?></td>
                        <td class="jq-col-actions">
                            <div class="jq-btn-group" style="justify-content:flex-end;">
                                <form method="POST" data-no-loading="1" class="d-inline">
                                    <?= jq_csrf_field() ?><input type="hidden" name="op" value="download"><input type="hidden" name="file" value="<?= jq_e($b['filename']) ?>">
                                    <button type="submit" class="jq-btn jq-btn-sm jq-btn-view" title="Download"><i class="bx bx-download"></i></button>
                                </form>
                                <button type="button" class="jq-btn jq-btn-sm jq-btn-edit" onclick="confirmVerify(<?= jq_e(json_encode($b['filename'])) ?>)" title="Test-restore into a temporary database"><i class="bx bx-check-double"></i> Verify</button>
                                <button type="button" class="jq-btn jq-btn-sm jq-btn-restore" onclick="openRestore(<?= jq_e(json_encode(['file' => $b['filename'], 'date' => $dt->format('F d, Y h:i:s A'), 'type' => jq_backup_type_label($b['type']), 'size' => jq_human_size($b['size']), 'rows' => $b['rows'] ? number_format((int)$b['rows']) : '—', 'tables' => $b['tables']])) ?>)"
                                        <?= !$tool_mysql ? 'disabled' : '' ?> title="Restore the database from this backup"><i class="bx bx-revision"></i> Restore</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <!-- Schedule settings -->
        <div class="col-xl-7">
            <form class="jq-card" method="POST" id="settingsForm" style="height:calc(100% - 20px);">
                <?= jq_csrf_field() ?>
                <input type="hidden" name="op" value="settings">
                <div class="jq-card-header"><h2 class="jq-card-title"><i class="bx bx-calendar-check"></i> Automatic backup schedule</h2></div>
                <div class="jq-card-body">
                    <label class="jq-check" style="margin-bottom:16px;"><input type="checkbox" name="auto_enabled" value="1" id="autoEnabled" <?= $settings['backup_auto_enabled'] === '1' ? 'checked' : '' ?>> <strong>Enable automatic backups</strong></label>
                    <div class="jq-form-grid" id="scheduleFields">
                        <div class="jq-field">
                            <label class="jq-label" for="frequency">Frequency</label>
                            <select class="jq-select" name="frequency" id="frequency">
                                <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $k => $v): ?><option value="<?= $k ?>" <?= $settings['backup_frequency'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="jq-field">
                            <label class="jq-label" for="btime">Time (Philippine time)</label>
                            <input class="jq-input" type="time" name="time" id="btime" value="<?= jq_e($settings['backup_time']) ?>" required>
                        </div>
                        <div class="jq-field" id="weekdayField">
                            <label class="jq-label" for="weekday">Day of week</label>
                            <select class="jq-select" name="weekday" id="weekday">
                                <?php foreach ($weekdays as $k => $v): ?><option value="<?= $k ?>" <?= (int)$settings['backup_weekday'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="jq-field" id="monthdayField">
                            <label class="jq-label" for="monthday">Day of month</label>
                            <select class="jq-select" name="monthday" id="monthday">
                                <?php for ($d = 1; $d <= 28; $d++): ?><option value="<?= $d ?>" <?= (int)$settings['backup_monthday'] === $d ? 'selected' : '' ?>><?= $d ?></option><?php endfor; ?>
                            </select>
                        </div>
                        <div class="jq-field">
                            <label class="jq-label" for="retention">Keep scheduled backups</label>
                            <input class="jq-input" type="number" name="retention" id="retention" min="0" max="365" value="<?= (int)$settings['backup_retention'] ?>">
                            <p class="jq-help">Newest N scheduled backups are kept (0 = keep all). Manual, uploaded and safety backups are never removed automatically.</p>
                        </div>
                        <div class="jq-field jq-span-all">
                            <label class="jq-label" for="secondaryDir">Secondary (off-site) destination</label>
                            <input class="jq-input" type="text" name="secondary_dir" id="secondaryDir" value="<?= jq_e($settings['backup_secondary_dir']) ?>" placeholder="e.g. E:\JanetsBackups  or  \\NAS\backups  or a cloud-synced folder">
                            <p class="jq-help">Every backup is also copied here and checked. Use an external drive, network share or a Google Drive / OneDrive synced folder so a server failure cannot destroy all copies.</p>
                        </div>
                    </div>
                </div>
                <div class="jq-table-footer">
                    <span class="jq-muted jq-small"><?= $settings['backup_last_auto_at'] ? 'Last automatic backup: ' . jq_e((new DateTimeImmutable($settings['backup_last_auto_at']))->format('M d, Y h:i A')) : 'No automatic backup has run yet.' ?></span>
                    <button type="submit" class="jq-btn jq-btn-primary"><i class="bx bx-save"></i> Save schedule</button>
                </div>
            </form>
        </div>

        <!-- Disaster recovery -->
        <div class="col-xl-5">
            <div class="jq-card" style="height:calc(100% - 20px);">
                <div class="jq-card-header"><h2 class="jq-card-title"><i class="bx bx-first-aid"></i> Reliability &amp; disaster recovery</h2></div>
                <div class="jq-card-body">
                    <p class="jq-muted jq-small" style="margin-top:0;"><strong>Run the schedule even when nobody is signed in.</strong> Backups also run automatically after a page is used, but a scheduled task is more reliable. Run once in an <em>administrator</em> Command Prompt:</p>
                    <div class="jq-alert jq-alert-info" style="margin-bottom:14px;"><i class="bx bx-terminal"></i>
                        <p><code>schtasks /Create /SC HOURLY /TN "JanetsCateringBackup" /TR "\"<?= jq_e($php_bin) ?>\" \"<?= jq_e($cli_path) ?>\" scheduled"</code></p>
                    </div>
                    <p class="jq-muted jq-small"><strong>If the website cannot start</strong> (corrupted database), restore from the command line:</p>
                    <div class="jq-alert jq-alert-info" style="margin-bottom:14px;"><i class="bx bx-terminal"></i>
                        <p><code>"<?= jq_e($php_bin) ?>" "<?= jq_e($cli_path) ?>" restore &lt;backup-file&gt; --yes</code></p>
                    </div>
                    <ul class="jq-muted jq-small" style="padding-left:18px; margin:0;">
                        <li><strong>Test your backups:</strong> “Verify” restores a backup into a temporary database and compares every table — your live data is not touched.</li>
                        <li><strong>Every restore is protected:</strong> a safety backup of the current data is created first, and the database is rolled back automatically if the import fails.</li>
                        <li><strong>Audit history is never rolled back:</strong> the activity log and backup history keep their latest state after a restore.</li>
                    </ul>
                </div>
            </div>
        </div>
        
    </div>

    <!-- History -->
    <div class="jq-card">
        <div class="jq-card-header"><h2 class="jq-card-title"><i class="bx bx-history"></i> Backup &amp; restore history</h2><span class="jq-muted jq-small">Append-only — entries cannot be changed or deleted</span></div>
        <?php if (!$history): ?>
        <div class="jq-empty"><i class="bx bx-history"></i><h4>No backup activity yet</h4><p>Backups, downloads, verifications and restores will be listed here.</p></div>
        <?php else: ?>
        <div class="jq-table-wrap">
            <table class="jq-table">
                <thead><tr><th>Date &amp; Time</th><th>Action</th><th>Status</th><th>Backup file</th><th>Details</th><th>Performed by</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): $hd = new DateTimeImmutable($h['created_at']); ?>
                    <tr>
                        <td style="white-space:nowrap;"><strong><?= jq_e($hd->format('M d, Y')) ?></strong><span class="jq-sub"><?= jq_e($hd->format('h:i:s A')) ?><?= $h['duration_ms'] ? ' · ' . number_format($h['duration_ms'] / 1000, 1) . 's' : '' ?></span></td>
                        <td><span class="jq-badge jq-badge-<?= $hist_tone[$h['action']] ?? 'view' ?>"><?= jq_e(jq_action_label($h['action'])) ?></span></td>
                        <td><span class="jq-badge jq-badge-<?= $h['status'] === 'success' ? 'restore' : ($h['status'] === 'warning' ? 'archive' : 'delete') ?>"><?= jq_e(ucfirst($h['status'])) ?></span></td>
                        <td><span class="jq-filename" style="font-weight:400;"><?= jq_e($h['filename'] ?: '—') ?></span></td>
                        <td><span class="jq-clamp" title="<?= jq_e($h['details']) ?>"><?= jq_e($h['details']) ?></span></td>
                        <td><strong><?= jq_e($h['performed_by_username'] ?: 'system') ?></strong><span class="jq-sub"><?= jq_e($h['performed_by_role']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="jq-table-footer"><span class="jq-muted jq-small"><?= number_format($hist_total) ?> entr<?= $hist_total === 1 ? 'y' : 'ies' ?></span><?= jq_pagination($hist_page, $hist_pages, 'hpage') ?></div>
        <?php endif; ?>
    </div>

    
    <!-- Restore modal -->
    <div class="modal fade" id="restoreModal" tabindex="-1" aria-labelledby="restoreTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form class="modal-content" method="POST" id="restoreForm" data-no-loading="1" autocomplete="off">
                <?= jq_csrf_field() ?>
                <input type="hidden" name="op" value="restore">
                <input type="hidden" name="file" id="restoreFile">
                <div class="modal-header">
                    <h5 class="modal-title" id="restoreTitle"><i class="bx bx-revision"></i> Restore database</h5>
                    <button type="button" class="jq-modal-close" data-bs-dismiss="modal" aria-label="Close"><i class="bx bx-x"></i></button>
                </div>
                <div class="modal-body">
                    <div class="jq-alert jq-alert-danger"><i class="bx bx-error-circle"></i>
                        <p><strong>This replaces the current data with the contents of the backup.</strong> Bookings, accounts, menus and other records created after the backup date will be replaced. Other users are paused while the restore runs.</p>
                    </div>
                    <dl class="jq-kv" style="margin-bottom:16px;">
                        <div><dt>Backup file</dt><dd class="jq-mono" id="rFile"></dd></div>
                        <div><dt>Created</dt><dd id="rDate"></dd></div>
                        <div><dt>Type</dt><dd id="rType"></dd></div>
                        <div><dt>Contents</dt><dd id="rRows"></dd></div>
                    </dl>
                    <div class="jq-alert jq-alert-success"><i class="bx bx-shield-quarter"></i>
                        <p>Before restoring, the file is validated (checksum, structure, safety scan) and a <strong>safety backup of the current database</strong> is created automatically. If the import fails, the database is rolled back. The activity log and backup history are kept up to date.</p>
                    </div>
                    <div class="jq-form-grid">
                        <div class="jq-field">
                            <label class="jq-label" for="confirmText">Type <strong>RESTORE</strong> to confirm <span class="jq-req">*</span></label>
                            <input class="jq-input" id="confirmText" name="confirm_text" required autocomplete="off" spellcheck="false">
                        </div>
                        <div class="jq-field">
                            <label class="jq-label" for="ownerPassword">Your password <span class="jq-req">*</span></label>
                            <input class="jq-input" type="password" id="ownerPassword" name="password" required autocomplete="current-password">
                        </div>
                        <div class="jq-field jq-span-all">
                            <label class="jq-check"><input type="checkbox" name="acknowledge" value="1" id="ackBox"> I understand that the current data will be replaced by this backup.</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="jq-btn jq-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="jq-btn jq-btn-restore" id="restoreSubmit" disabled><i class="bx bx-revision"></i> Restore database</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Upload modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" enctype="multipart/form-data" id="uploadForm" data-no-loading="1">
                <?= jq_csrf_field() ?>
                <input type="hidden" name="op" value="upload">
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadTitle"><i class="bx bx-upload"></i> Upload a backup file</h5>
                    <button type="button" class="jq-modal-close" data-bs-dismiss="modal" aria-label="Close"><i class="bx bx-x"></i></button>
                </div>
                <div class="modal-body">
                    <p class="jq-help" style="margin-bottom:12px;">Bring back a backup kept off-site (e.g. a downloaded copy). Accepted: <strong>.sql.gz</strong> or <strong>.sql</strong> made by this system / mysqldump, up to <?= (int)min(BACKUP_MAX_UPLOAD_MB, (int)ini_get('upload_max_filesize') ?: BACKUP_MAX_UPLOAD_MB) ?> MB. The file is validated and safety-scanned; nothing is restored until you choose “Restore”.</p>
                    <div class="jq-field">
                        <label class="jq-label" for="backupFile">Backup file <span class="jq-req">*</span></label>
                        <input class="jq-input" type="file" id="backupFile" name="backup_file" accept=".sql,.gz,.sql.gz,application/gzip,application/sql" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="jq-btn jq-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="jq-btn jq-btn-primary"><i class="bx bx-upload"></i> Upload &amp; validate</button>
                </div>
            </form>
        </div>
    </div>

    <form method="POST" id="verifyForm" data-no-loading="1" style="display:none;">
        <?= jq_csrf_field() ?><input type="hidden" name="op" value="verify"><input type="hidden" name="file" id="verifyFile">
    </form>
    
</div>

<script>
(function () {
    'use strict';
    window.confirmCreate = function () {
        Swal.fire({
            icon: 'question', title: 'Create a backup now?',
            html: '<p style="margin:0 0 10px;">A complete copy of the database (all tables, records, triggers and relationships) will be saved.</p>',
            input: 'text', inputLabel: 'Note (optional)', inputPlaceholder: 'e.g. Before updating the menu prices', inputAttributes: { maxlength: 300 },
            showCancelButton: true, reverseButtons: true, confirmButtonText: 'Create backup', cancelButtonText: 'Cancel',
            customClass: { confirmButton: 'jq-swal-primary', cancelButton: 'jq-swal-cancel' }
        }).then(r => {
            if (!r.isConfirmed) return;
            document.getElementById('createNotes').value = r.value || '';
            showLoading('Creating backup… please wait');
            document.getElementById('createForm').submit();
        });
    };

    const restoreForm = document.getElementById('restoreForm');
    if (!restoreForm) return;

    window.confirmVerify = function (file) {
        Swal.fire({
            icon: 'question', title: 'Verify this backup?',
            html: '<p style="margin:0;">The backup is restored into a <strong>temporary</strong> database and every table is compared with the original. Your live data is not changed.</p>',
            showCancelButton: true, reverseButtons: true, confirmButtonText: 'Verify', cancelButtonText: 'Cancel',
            customClass: { confirmButton: 'jq-swal-primary', cancelButton: 'jq-swal-cancel' }
        }).then(r => {
            if (!r.isConfirmed) return;
            document.getElementById('verifyFile').value = file;
            showLoading('Test-restoring backup… please wait');
            document.getElementById('verifyForm').submit();
        });
    };

    const confirmInput = document.getElementById('confirmText'), pw = document.getElementById('ownerPassword'), ack = document.getElementById('ackBox'), btn = document.getElementById('restoreSubmit');
    function syncRestoreBtn() { btn.disabled = !(confirmInput.value.trim() === 'RESTORE' && pw.value.length > 0 && ack.checked); }
    [confirmInput, pw, ack].forEach(el => el.addEventListener('input', syncRestoreBtn));
    ack.addEventListener('change', syncRestoreBtn);

    window.openRestore = function (b) {
        document.getElementById('restoreFile').value = b.file;
        document.getElementById('rFile').textContent = b.file;
        document.getElementById('rDate').textContent = b.date;
        document.getElementById('rType').textContent = b.type + ' · ' + b.size;
        document.getElementById('rRows').textContent = (b.tables ? b.tables + ' tables, ' : '') + b.rows + ' rows';
        confirmInput.value = ''; pw.value = ''; ack.checked = false; syncRestoreBtn();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('restoreModal')).show();
        setTimeout(() => confirmInput.focus(), 300);
    };
    restoreForm.addEventListener('submit', function (e) {
        if (btn.disabled) { e.preventDefault(); return; }
        btn.disabled = true;
        showLoading('Restoring the database… do not close this page');
    });

    document.getElementById('uploadForm').addEventListener('submit', function (e) {
        const f = document.getElementById('backupFile').files[0];
        if (!f) { e.preventDefault(); Swal.fire({ icon: 'warning', title: 'Choose a file', text: 'Select a .sql or .sql.gz backup file.' }); return; }
        if (!/\.(sql|sql\.gz|gz)$/i.test(f.name)) { e.preventDefault(); Swal.fire({ icon: 'warning', title: 'Unsupported file', text: 'Only .sql or .sql.gz backup files are accepted.' }); return; }
        showLoading('Uploading and validating…');
    });

    // schedule form: only show the day field that applies
    const freq = document.getElementById('frequency'), auto = document.getElementById('autoEnabled');
    function syncSchedule() {
        document.getElementById('weekdayField').style.display = freq.value === 'weekly' ? '' : 'none';
        document.getElementById('monthdayField').style.display = freq.value === 'monthly' ? '' : 'none';
        document.getElementById('scheduleFields').style.opacity = auto.checked ? '1' : '.6';
    }
    freq.addEventListener('change', syncSchedule); auto.addEventListener('change', syncSchedule); syncSchedule();
})();
</script>

<?php require_once 'includes/footer.php'; ?>
