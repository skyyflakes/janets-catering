<?php
/**
 * ACTIVITY LOG MODULE
 * Janet's Quality Catering System
 *
 * ADMIN  – monitor & track: view all active logs, search / filter, view details (previous vs updated values).
 *          Read-only: cannot edit, archive, restore or view archived logs.
 * OWNER  – everything above, plus: archive, restore archived records, view archived logs and the
 *          management audit trail. Entries are recorded automatically and are never edited or added by hand.
 *
 * Records are never deleted. Every management action is copied by the database into the append-only
 * activity_log_audit table (see includes/activity_logger.php).
 */

$page_title   = "Activity Log | Janet's Quality Catering";
$current_page = 'activitylog';

require_once 'includes/auth_check.php';
/** @var PDO|null $pdo */
/** @var array $current_user */

if (!$pdo) {
    setFlash('The database is not available. Activity logs cannot be displayed.', 'danger');
    redirect('dashboard.php');
}

$role     = jq_require_roles($pdo, ['ADMIN', 'OWNER'], 'Activity Log');
$is_owner = ($role === 'OWNER');

const AL_CHANGE_ACTIONS = ['CREATE', 'UPDATE', 'DELETE', 'ARCHIVE', 'RESTORE', 'STATUS_CHANGE'];

/** Owner-only guard for management endpoints (server-side, independent of the UI). */
function al_require_owner(PDO $pdo, bool $is_owner, string $what): void
{
    if ($is_owner) {
        return;
    }
    logActivity($pdo, 'ACCESS_DENIED', 'Activity Log', 'Blocked unauthorized attempt to ' . $what . ' (role ' . jq_verified_role($pdo) . ')');
    if (jq_is_ajax()) {
        jq_json_response(['success' => false, 'error' => 'Only the OWNER can ' . $what . '.'], 403);
    }
    setFlash('Only the OWNER can ' . $what . '.', 'danger');
    redirect('activitylog.php');
}

function al_fmt_dt(?string $ts, string $format = 'M d, Y h:i:s A'): string
{
    if (!$ts) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($ts, jq_tz()))->format($format);
    } catch (Throwable $e) {
        return $ts;
    }
}

/** Changes between two activity_log_audit snapshots, as "field: old → new" rows. */
function al_snapshot_changes(?string $old, ?string $new): array
{
    $rows = [];
    foreach (jq_diff_values($old, $new) as $d) {
        if ($d['changed'] && $d['field'] !== 'edit_count') {
            $rows[] = $d;
        }
    }
    return $rows;
}

/* =====================================================================
 *  AJAX: log details
 * ===================================================================== */
if (($_GET['ajax'] ?? '') === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("
        SELECT l.*, u.first_name, u.last_name,
               au.username AS archived_by_name, ru.username AS restored_by_name
          FROM activity_logs l
          LEFT JOIN users u  ON u.id  = l.user_id
                    LEFT JOIN users au ON au.id = l.archived_by
          LEFT JOIN users ru ON ru.id = l.restored_by
         WHERE l.id = ?");
    $st->execute([$id]);
    $log = $st->fetch(PDO::FETCH_ASSOC);
    if (!$log) {
        jq_json_response(['success' => false, 'error' => 'Activity log record not found.'], 404);
    }
    if ((int)$log['is_archived'] === 1 && !$is_owner) {
        al_require_owner($pdo, false, 'view archived activity logs');
    }

    $history = [];
    if ($is_owner) {
        $h = $pdo->prepare("SELECT * FROM activity_log_audit WHERE log_id = ? ORDER BY id ASC");
        $h->execute([$id]);
        foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $history[] = [
                'operation' => $a['operation'],
                'by'        => $a['performed_by_username'] ?: 'unknown',
                'role'      => $a['performed_by_role'],
                'at'        => al_fmt_dt($a['created_at']),
                'reason'    => $a['reason'],
                'changes'   => al_snapshot_changes($a['old_snapshot'], $a['new_snapshot']),
            ];
        }
    }
    $name = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
    jq_json_response([
        'success'    => true,
        'can_manage' => $is_owner,
        'log' => [
            'id'           => (int)$log['id'],
            'datetime'     => al_fmt_dt($log['created_at'], 'l, F d, Y'),
            'time'         => al_fmt_dt($log['created_at'], 'h:i:s A') . '.' . substr((string)$log['created_at'], 20, 3),
            'username'     => $log['username'] ?: 'system',
            'full_name'    => $name,
            'user_exists'  => $log['user_id'] !== null,
            'role'         => $log['user_role'],
            'action'       => $log['action'],
            'action_label' => jq_action_label((string)$log['action']),
            'tone'         => jq_action_tone((string)$log['action']),
            'module'       => $log['module'],
            'record_table' => $log['record_table'],
            'record_id'    => $log['record_id'],
            'record_label' => jq_plain($log['record_label']),
            'description'  => jq_plain($log['description']),
            'notes'        => $log['notes'],
            'ip'           => $log['ip_address'],
            'user_agent'   => $log['user_agent'],
            'page'         => $log['page'],
            'source'       => $log['source'],
            'is_archived'  => (int)$log['is_archived'] === 1,
            'archived'     => $log['archived_at'] ? al_fmt_dt($log['archived_at']) . ' by ' . ($log['archived_by_name'] ?: 'unknown') : null,
            'restored'     => $log['restored_at'] ? al_fmt_dt($log['restored_at']) . ' by ' . ($log['restored_by_name'] ?: 'unknown') : null,
                        ],
        'diff'    => jq_diff_values($log['old_values'], $log['new_values']),
        'history' => $history,
    ]);
}

/* =====================================================================
 *  AJAX: management audit-trail entry details (OWNER)
 * ===================================================================== */
if (($_GET['ajax'] ?? '') === 'audit_detail') {
    al_require_owner($pdo, $is_owner, 'view the activity log audit trail');
    $st = $pdo->prepare("SELECT * FROM activity_log_audit WHERE id = ?");
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) {
        jq_json_response(['success' => false, 'error' => 'Audit entry not found.'], 404);
    }
    jq_json_response(['success' => true, 'entry' => [
        'id' => (int)$a['id'], 'log_id' => (int)$a['log_id'], 'operation' => $a['operation'], 'reason' => $a['reason'],
        'by' => $a['performed_by_username'], 'role' => $a['performed_by_role'], 'ip' => $a['ip_address'], 'at' => al_fmt_dt($a['created_at']),
    ], 'diff' => jq_diff_values($a['old_snapshot'], $a['new_snapshot'])]);
}

/* =====================================================================
 *  POST: management actions (OWNER only)
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = (string)($_POST['op'] ?? '');
    $return = (string)($_POST['return'] ?? 'activitylog.php');
    if (!preg_match('/^activitylog\.php(\?[^\r\n]*)?$/', $return)) {
        $return = 'activitylog.php';
    }
    al_require_owner($pdo, $is_owner, 'manage activity logs');
    if (!jq_csrf_valid($_POST['csrf_token'] ?? null)) {
        setFlash('Your session security token expired. Please try again.', 'warning');
        redirect($return);
    }

    try {
        switch ($op) {
case 'archive':
            case 'restore': {
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), static fn($v) => $v > 0)));
                $reason = trim((string)($_POST['reason'] ?? ''));
                if (!$ids) {
                    setFlash('Select at least one activity log record.', 'warning');
                    break;
                }
                if (count($ids) > 500) {
                    setFlash('You can ' . $op . ' at most 500 records at a time.', 'warning');
                    break;
                }
                if (mb_strlen($reason) > 500) {
                    $reason = mb_substr($reason, 0, 500);
                }
                $toArchive = $op === 'archive';
                $reason = $reason !== '' ? $reason : ($toArchive ? 'Archived via the Activity Log module' : 'Restored via the Activity Log module');
                $in = implode(',', array_fill(0, count($ids), '?'));
                $pdo->beginTransaction();
                jq_set_audit_vars($pdo, ['log_mgmt' => 1, 'log_reason' => $reason]);
                $st = $pdo->prepare("UPDATE activity_logs SET is_archived = ? WHERE id IN ($in) AND is_archived = ?");
                $st->execute(array_merge([$toArchive ? 1 : 0], $ids, [$toArchive ? 0 : 1]));
                $affected = $st->rowCount();
                jq_set_audit_vars($pdo, ['log_mgmt' => 0, 'log_reason' => null]);
                $pdo->commit();
                $list = '#' . implode(', #', array_slice($ids, 0, 20)) . (count($ids) > 20 ? ' …' : '');
                logActivity($pdo, $toArchive ? 'LOG_ARCHIVE' : 'LOG_RESTORE', 'Activity Log',
                    ($toArchive ? 'Archived ' : 'Restored ') . $affected . ' activity log record(s): ' . $list . '. Reason: ' . $reason,
                    ['record_table' => 'activity_logs', 'record_id' => count($ids) === 1 ? $ids[0] : null]);
                setFlash($affected . ' record(s) ' . ($toArchive ? 'archived. They remain stored and can be restored at any time.' : 'restored to the active log.'),
                    $affected ? 'success' : 'warning');
                break;
            }

            case 'resync': {
                $r = jq_ensure_audit_schema($pdo, true);
                logActivity($pdo, 'AUDIT_RESYNC', 'Activity Log', 'Re-synchronized automatic audit triggers. ' . implode(' ', $r['messages']));
                setFlash($r['ok'] ? 'Automatic change tracking is up to date. ' . implode(' ', $r['messages'])
                                  : 'Some tracking triggers could not be installed: ' . implode(' ', $r['messages']), $r['ok'] ? 'success' : 'danger');
                break;
            }

            default:
                setFlash('Unknown action.', 'warning');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        try { jq_set_audit_vars($pdo, ['log_mgmt' => 0, 'log_reason' => null]); } catch (Throwable $e2) {}
        error_log('activitylog.php ' . $op . ': ' . $e->getMessage());
        setFlash('The action could not be completed: ' . preg_replace('/^SQLSTATE\[\w+\]: [^:]*: \d+ /', '', $e->getMessage()), 'danger');
    }
    redirect($return);
}

/* =====================================================================
 *  LIST VIEW
 * ===================================================================== */
$view = (string)($_GET['view'] ?? 'active');
if (!in_array($view, ['active', 'archived', 'audit'], true)) {
    $view = 'active';
}
if ($view !== 'active' && !$is_owner) {
    al_require_owner($pdo, false, $view === 'archived' ? 'view archived activity logs' : 'view the activity log audit trail');
}

$f = [
    'q'      => trim((string)($_GET['q'] ?? '')),
    'user'   => trim((string)($_GET['user'] ?? '')),
    'action' => trim((string)($_GET['action'] ?? '')),
    'module' => trim((string)($_GET['module'] ?? '')),
    'source' => trim((string)($_GET['source'] ?? '')),
    'from'   => trim((string)($_GET['from'] ?? '')),
    'to'     => trim((string)($_GET['to'] ?? '')),
];
foreach (['from', 'to'] as $k) {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $f[$k], jq_tz());
    if (!$d || $d->format('Y-m-d') !== $f[$k]) {
        $f[$k] = '';
    }
}
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$like     = static fn(string $s) => '%' . addcslashes($s, '%_\\') . '%';
$has_filters = (bool)array_filter($f);
// Pages stay stable while new records arrive: every page of one browse counts only up to this id.
// Without it a new record shifts the list, so Next would repeat a record or Previous would skip one.
$anchor = max(0, (int)($_GET['anchor'] ?? 0));
unset($_GET['per_page']);

// Stats (active logs)
$stats = ['today' => 0, 'changes' => 0, 'logins' => 0, 'failed' => 0];
try {
    $in = implode(',', array_fill(0, count(AL_CHANGE_ACTIONS), '?'));
    $todayStart = date('Y-m-d 00:00:00');
    $weekStart = date('Y-m-d 00:00:00', strtotime('-6 days'));
    $st = $pdo->prepare("
        SELECT SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS today,
               SUM(CASE WHEN created_at >= ? AND action IN ($in) THEN 1 ELSE 0 END) AS changes,
               SUM(CASE WHEN created_at >= ? AND action = 'LOGIN' THEN 1 ELSE 0 END) AS logins,
               SUM(CASE WHEN action = 'LOGIN_FAILED' THEN 1 ELSE 0 END) AS failed
          FROM activity_logs WHERE is_archived = 0 AND created_at >= ?");
    $st->execute(array_merge([$todayStart, $todayStart], AL_CHANGE_ACTIONS, [$todayStart, $weekStart]));
    $stats = array_map('intval', $st->fetch(PDO::FETCH_ASSOC) ?: $stats);
} catch (Throwable $e) {
    error_log('activitylog stats: ' . $e->getMessage());
}
$archived_count = $is_owner ? (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE is_archived = 1")->fetchColumn() : 0;
$audit_count    = $is_owner ? (int)$pdo->query("SELECT COUNT(*) FROM activity_log_audit")->fetchColumn() : 0;

$rows = [];
$total = 0;
if ($view === 'audit') {
    $where = ['1=1'];
    $params = [];
    if ($f['q'] !== '') {
        $where[] = "(a.reason LIKE ? OR a.performed_by_username LIKE ? OR a.log_id = ?)";
        array_push($params, $like($f['q']), $like($f['q']), ctype_digit($f['q']) ? (int)$f['q'] : -1);
    }
    if ($f['action'] !== '') { $where[] = 'a.operation = ?'; $params[] = $f['action']; }
    if ($f['from'] !== '')   { $where[] = 'a.created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
    if ($f['to'] !== '')     { $where[] = 'a.created_at < ?'; $params[] = (new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'; }
    $st = $pdo->prepare('SELECT COUNT(*) FROM activity_log_audit a WHERE ' . implode(' AND ', $where));
    $st->execute($params);
    $all_total = (int)$st->fetchColumn();
    $anchor = $anchor ?: (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM activity_log_audit')->fetchColumn();
    $where[] = 'a.id <= ?';
    $params[] = $anchor;
    $w = implode(' AND ', $where);
    $st = $pdo->prepare("SELECT COUNT(*) FROM activity_log_audit a WHERE $w");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / $per_page));
    $page  = min($page, $pages);
    $st = $pdo->prepare("SELECT a.*, l.action AS log_action, l.module AS log_module, l.description AS log_description
                           FROM activity_log_audit a LEFT JOIN activity_logs l ON l.id = a.log_id
                          WHERE $w ORDER BY a.id DESC LIMIT ? OFFSET ?");
    foreach ($params as $i => $p) {
        $st->bindValue($i + 1, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->bindValue(count($params) + 1, $per_page, PDO::PARAM_INT);
    $st->bindValue(count($params) + 2, ($page - 1) * $per_page, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $action_options = ['CREATE', 'ARCHIVE', 'RESTORE'];
} else {
    $where = ['l.is_archived = ?'];
    $params = [$view === 'archived' ? 1 : 0];
    if ($f['q'] !== '') {
        $where[] = "(l.description LIKE ? OR l.username LIKE ? OR l.record_label LIKE ? OR l.record_id = ? OR l.action LIKE ? OR l.module LIKE ? OR l.ip_address LIKE ? OR l.notes LIKE ?)";
        array_push($params, $like($f['q']), $like($f['q']), $like($f['q']), $f['q'], $like($f['q']), $like($f['q']), $like($f['q']), $like($f['q']));
    }
    if ($f['user'] !== '')   { $where[] = 'l.username = ?'; $params[] = $f['user']; }
    if ($f['action'] !== '') { $where[] = 'l.action = ?';   $params[] = $f['action']; }
    if ($f['module'] !== '') { $where[] = 'l.module = ?';   $params[] = $f['module']; }
    if ($f['source'] !== '') { $where[] = 'l.source = ?';   $params[] = $f['source']; }
    if ($f['from'] !== '')   { $where[] = 'l.created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
    if ($f['to'] !== '')     { $where[] = 'l.created_at < ?';  $params[] = (new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'; }
    $st = $pdo->prepare('SELECT COUNT(*) FROM activity_logs l WHERE ' . implode(' AND ', $where));
    $st->execute($params);
    $all_total = (int)$st->fetchColumn();
    $anchor = $anchor ?: (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM activity_logs')->fetchColumn();
    $where[] = 'l.id <= ?';
    $params[] = $anchor;
    $w = implode(' AND ', $where);
    $st = $pdo->prepare("SELECT COUNT(*) FROM activity_logs l WHERE $w");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / $per_page));
    $page  = min($page, $pages);
    $st = $pdo->prepare("SELECT l.id, l.user_id, l.username, l.user_role, l.action, l.module, l.record_table, l.record_id, l.record_label,
                                l.description, l.ip_address, l.source, l.created_at, l.is_archived, l.archived_at,
                                (l.old_values IS NOT NULL OR l.new_values IS NOT NULL) AS has_diff,
                                u.first_name, u.last_name
                           FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                          WHERE $w ORDER BY l.created_at DESC, l.id DESC LIMIT ? OFFSET ?");
    foreach ($params as $i => $p) {
        $st->bindValue($i + 1, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->bindValue(count($params) + 1, $per_page, PDO::PARAM_INT);
    $st->bindValue(count($params) + 2, ($page - 1) * $per_page, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Filter options (admins only see values from active records)
    $scope = $is_owner ? '' : 'WHERE is_archived = 0';
    $user_options   = $pdo->query("SELECT DISTINCT username FROM activity_logs $scope ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
    $action_options = $pdo->query("SELECT DISTINCT action FROM activity_logs $scope ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
    $module_options = $pdo->query("SELECT DISTINCT module FROM activity_logs $scope ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
}
$_GET['anchor'] = $anchor; // carried by the Previous / Next links
$_GET['page'] = $page;
$newer = max(0, $all_total - $total);
$pages = max(1, (int)ceil($total / $per_page));
$first = $total ? (($page - 1) * $per_page + 1) : 0;
$last  = min($total, $page * $per_page);
$source_labels = ['app' => 'Application', 'system' => 'System event', 'manual' => 'Manual entry', 'direct_db' => 'Direct database change'];

require_once 'includes/header.php';
?>

<div id="jqPage">
    <!-- Header -->
    <div class="jq-page-header">
        <div>
            <h1 class="jq-title">Activity Log <span class="jq-role-chip"><i class="bx <?= $is_owner ? 'bx-shield-quarter' : 'bx-show' ?>"></i><?= $is_owner ? 'Owner · Manage' : 'Admin · Monitor' ?></span></h1>
            <p class="jq-subtitle">Every sign-in, change and archive across the system — who did it, when, and what changed.</p>
        </div>
        <?php if ($is_owner): ?>
        <div class="jq-header-actions">
            <form method="POST" class="d-inline" data-no-loading="1" id="resyncForm">
                <?= jq_csrf_field() ?>
                <input type="hidden" name="op" value="resync">
                <input type="hidden" name="return" value="<?= jq_e(jq_url()) ?>">
                <button type="button" class="jq-btn jq-btn-view" onclick="confirmResync()" title="Re-install the database triggers that record changes automatically">
                    <i class="bx bx-refresh"></i> Re-sync tracking
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="jq-stats">
        <div class="jq-stat">
            <div><span class="jq-stat-label">Activities today</span><div class="jq-stat-value"><?= number_format($stats['today']) ?></div><span class="jq-stat-hint">All recorded events</span></div>
            <div class="jq-stat-icon jq-tone-brand"><i class="bx bx-pulse"></i></div>
        </div>
        <div class="jq-stat">
            <div><span class="jq-stat-label">Record changes today</span><div class="jq-stat-value"><?= number_format($stats['changes']) ?></div><span class="jq-stat-hint">Created, updated, archived…</span></div>
            <div class="jq-stat-icon jq-tone-edit"><i class="bx bx-edit-alt"></i></div>
        </div>
        <div class="jq-stat">
            <div><span class="jq-stat-label">Sign-ins today</span><div class="jq-stat-value"><?= number_format($stats['logins']) ?></div><span class="jq-stat-hint">Successful logins</span></div>
            <div class="jq-stat-icon jq-tone-restore"><i class="bx bx-log-in-circle"></i></div>
        </div>
        <div class="jq-stat">
            <div><span class="jq-stat-label">Failed sign-ins</span><div class="jq-stat-value"><?= number_format($stats['failed']) ?></div><span class="jq-stat-hint">Last 7 days</span></div>
            <div class="jq-stat-icon <?= $stats['failed'] > 0 ? 'jq-tone-delete' : 'jq-tone-view' ?>"><i class="bx bx-error-circle"></i></div>
        </div>
    </div>

    <div class="jq-card">
        <!-- Tabs -->
        <div class="jq-tabs" role="tablist">
            <a class="jq-tab <?= $view === 'active' ? 'is-active' : '' ?>" href="activitylog.php"><i class="bx bx-list-ul"></i> Activity</a>
            <?php if ($is_owner): ?>
            <a class="jq-tab <?= $view === 'archived' ? 'is-active' : '' ?>" href="activitylog.php?view=archived"><i class="bx bx-archive"></i> Archived <span class="jq-count"><?= number_format($archived_count) ?></span></a>
            <a class="jq-tab <?= $view === 'audit' ? 'is-active' : '' ?>" href="activitylog.php?view=audit"><i class="bx bx-shield-quarter"></i> Management Trail <span class="jq-count"><?= number_format($audit_count) ?></span></a>
            <?php endif; ?>
        </div>

        <!-- Filters -->
        <div class="jq-card-body" style="border-bottom:1px solid var(--jq-border);">
            <form method="GET" class="jq-filters" data-no-loading="1" id="filterForm">
                <?php if ($view !== 'active'): ?><input type="hidden" name="view" value="<?= jq_e($view) ?>"><?php endif; ?>
                <div class="jq-field jq-field-wide">
                    <label class="jq-label" for="fq">Search</label>
                    <div class="jq-search"><i class="bx bx-search"></i>
                        <input class="jq-input" type="search" id="fq" name="q" value="<?= jq_e($f['q']) ?>"
                               placeholder="<?= $view === 'audit' ? 'Reason, user or log #' : 'Description, user, record, IP…' ?>">
                    </div>
                </div>
                <?php if ($view !== 'audit'): ?>
                <div class="jq-field">
                    <label class="jq-label" for="fuser">User</label>
                    <select class="jq-select" id="fuser" name="user">
                        <option value="">All users</option>
                        <?php foreach ($user_options as $o): if ($o === null) continue; ?>
                        <option value="<?= jq_e($o) ?>" <?= $f['user'] === $o ? 'selected' : '' ?>><?= jq_e($o) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="jq-field">
                    <label class="jq-label" for="faction"><?= $view === 'audit' ? 'Operation' : 'Action' ?></label>
                    <select class="jq-select" id="faction" name="action">
                        <option value="">All</option>
                        <?php foreach ($action_options as $o): if ($o === null) continue; ?>
                        <option value="<?= jq_e($o) ?>" <?= $f['action'] === $o ? 'selected' : '' ?>><?= jq_e(jq_action_label($o)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($view !== 'audit'): ?>
                <div class="jq-field">
                    <label class="jq-label" for="fmodule">Module</label>
                    <select class="jq-select" id="fmodule" name="module">
                        <option value="">All modules</option>
                        <?php foreach ($module_options as $o): if ($o === null) continue; ?>
                        <option value="<?= jq_e($o) ?>" <?= $f['module'] === $o ? 'selected' : '' ?>><?= jq_e($o) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="jq-field">
                    <label class="jq-label" for="fsource">Source</label>
                    <select class="jq-select" id="fsource" name="source">
                        <option value="">All sources</option>
                        <?php foreach (array_diff_key($source_labels, ['manual' => true]) as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= $f['source'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="jq-field">
                    <label class="jq-label" for="ffrom">From</label>
                    <input class="jq-input" type="date" id="ffrom" name="from" value="<?= jq_e($f['from']) ?>" max="<?= jq_now()->format('Y-m-d') ?>">
                </div>
                <div class="jq-field">
                    <label class="jq-label" for="fto">To</label>
                    <input class="jq-input" type="date" id="fto" name="to" value="<?= jq_e($f['to']) ?>" max="<?= jq_now()->format('Y-m-d') ?>">
                </div>
                <div class="jq-filter-actions">
                    <button type="submit" class="jq-btn jq-btn-primary"><i class="bx bx-filter-alt"></i> Apply</button>
                    <?php if ($has_filters): ?>
                    <a class="jq-btn jq-btn-cancel" href="<?= jq_e('activitylog.php' . ($view !== 'active' ? '?view=' . $view : '')) ?>"><i class="bx bx-x"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($view === 'archived'): ?>
        <div class="jq-card-body" style="padding-bottom:0;">
            <div class="jq-alert jq-alert-warning"><i class="bx bx-archive"></i>
                <p><strong>Archived records are hidden from the active log but never deleted.</strong> Restore them at any time; every archive and restore is recorded in the Management Trail.</p>
            </div>
        </div>
        <?php elseif ($view === 'audit'): ?>
        <div class="jq-card-body" style="padding-bottom:0;">
            <div class="jq-alert jq-alert-info"><i class="bx bx-lock-alt"></i>
                <p><strong>Append-only history of activity-log management.</strong> Every archive and restore is copied here automatically by the database with the original values. These entries cannot be changed or deleted by anyone.</p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($view !== 'audit' && $is_owner): ?>
        <!-- Bulk actions -->
        <div class="jq-bulkbar" id="bulkBar">
            <span><strong id="bulkCount">0</strong> selected</span>
            <div class="jq-btn-group">
                <?php if ($view === 'active'): ?>
                <button type="button" class="jq-btn jq-btn-sm jq-btn-archive" onclick="bulkAction('archive')"><i class="bx bx-archive-in"></i> Archive selected</button>
                <?php else: ?>
                <button type="button" class="jq-btn jq-btn-sm jq-btn-restore" onclick="bulkAction('restore')"><i class="bx bx-archive-out"></i> Restore selected</button>
                <?php endif; ?>
                <button type="button" class="jq-btn jq-btn-sm jq-btn-cancel" onclick="clearSelection()">Cancel</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$rows): ?>
        <div class="jq-empty">
            <i class="bx <?= $has_filters ? 'bx-search-alt' : 'bx-history' ?>"></i>
            <h4><?= $has_filters ? 'No records match your filters' : ($view === 'archived' ? 'No archived records' : ($view === 'audit' ? 'No management actions yet' : 'No activity recorded yet')) ?></h4>
            <p><?= $has_filters ? 'Try a different search term or date range.' : 'Records will appear here automatically as people use the system.' ?></p>
        </div>
        <?php elseif ($view === 'audit'): ?>
        <!-- Management audit trail -->
        <div class="jq-table-wrap">
            <table class="jq-table">
                <thead><tr><th>Date &amp; Time</th><th>Operation</th><th>Log record</th><th>Performed by</th><th>Reason</th><th>IP</th><th class="jq-col-actions">Details</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $a): ?>
                    <tr>
                        <td><strong><?= jq_e(al_fmt_dt($a['created_at'], 'M d, Y')) ?></strong><span class="jq-sub"><?= jq_e(al_fmt_dt($a['created_at'], 'h:i:s A')) ?></span></td>
                        <td><span class="jq-badge jq-badge-<?= jq_action_tone('LOG_' . $a['operation']) ?>"><?= jq_e(jq_action_label($a['operation'])) ?></span></td>
                        <td><strong>#<?= (int)$a['log_id'] ?></strong><span class="jq-sub"><?= jq_e(jq_action_label((string)$a['log_action'])) ?> · <?= jq_e($a['log_module']) ?></span></td>
                        <td><div class="jq-user"><span class="jq-avatar"><?= jq_e(strtoupper(mb_substr((string)$a['performed_by_username'], 0, 1)) ?: '?') ?></span>
                            <div><strong><?= jq_e($a['performed_by_username'] ?: 'unknown') ?></strong><span class="jq-sub"><?= jq_e($a['performed_by_role']) ?></span></div></div></td>
                        <td><span class="jq-clamp"><?= jq_e($a['reason'] ?: '—') ?></span></td>
                        <td><span class="jq-mono"><?= jq_e($a['ip_address']) ?></span></td>
                        <td class="jq-col-actions">
                            <button type="button" class="jq-btn jq-btn-sm jq-btn-view" onclick="openAuditDetail(<?= (int)$a['id'] ?>)"><i class="bx bx-show"></i> View</button>
                            <button type="button" class="jq-btn jq-btn-sm jq-btn-view" onclick="openDetail(<?= (int)$a['log_id'] ?>)" title="Open the log record"><i class="bx bx-link-external"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <!-- Activity records -->
        <div class="jq-table-wrap">
            <table class="jq-table" id="logTable">
                <thead>
                    <tr>
                        <?php if ($is_owner): ?><th class="jq-col-check"><input type="checkbox" class="jq-check" id="selectAll" aria-label="Select all on this page" style="width:17px;height:17px;accent-color:var(--jq-brand);"></th><?php endif; ?>
                        <th>Date &amp; Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Module / Record</th>
                        <th>Description</th>
                        <th class="jq-col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                    $uname = $r['username'] ?: 'system';
                ?>
                    <tr data-id="<?= (int)$r['id'] ?>">
                        <?php if ($is_owner): ?><td><input type="checkbox" class="row-check" value="<?= (int)$r['id'] ?>" aria-label="Select record <?= (int)$r['id'] ?>" style="width:17px;height:17px;accent-color:var(--jq-brand);"></td><?php endif; ?>
                        <td style="white-space:nowrap;">
                            <strong><?= jq_e(al_fmt_dt($r['created_at'], 'M d, Y')) ?></strong>
                            <span class="jq-sub"><?= jq_e(al_fmt_dt($r['created_at'], 'h:i:s A')) ?></span>
                            <span class="jq-sub jq-mono" title="IP address"><i class="bx bx-globe"></i> <?= jq_e($r['ip_address'] ?: '—') ?></span>
                        </td>
                        <td>
                            <div class="jq-user">
                                <span class="jq-avatar"><?= jq_e(strtoupper(mb_substr($uname, 0, 1))) ?></span>
                                <div><strong><?= jq_e($name !== '' ? $name : $uname) ?></strong>
                                    <span class="jq-sub"><?= jq_e($name !== '' ? $uname . ' · ' : '') ?><?= jq_e($r['user_role'] ?: '—') ?></span></div>
                            </div>
                        </td>
                        <td>
                            <span class="jq-badge jq-badge-<?= jq_action_tone((string)$r['action']) ?>"><?= jq_e(jq_action_label((string)$r['action'])) ?></span>
                            <?php if ($r['source'] === 'manual'): ?><span class="jq-sub"><i class="bx bx-pencil"></i> Manual entry</span>
                            <?php elseif ($r['source'] === 'direct_db'): ?><span class="jq-sub" title="Changed outside the application (e.g. phpMyAdmin)"><i class="bx bx-error"></i> Direct DB change</span><?php endif; ?>
                        </td>
                        <td style="max-width:230px;">
                            <strong><?= jq_e($r['module'] ?: '—') ?></strong>
                            <?php if ($r['record_table'] || $r['record_label']): ?>
                            <span class="jq-sub" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= jq_e($r['record_table'] ?: '') ?><?= $r['record_id'] !== null && $r['record_id'] !== '' ? ' #' . jq_e($r['record_id']) : '' ?><?= $r['record_label'] ? ' · ' . jq_e(mb_strimwidth(jq_plain($r['record_label']), 0, 40, '…')) : '' ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="jq-clamp" title="<?= jq_e(jq_plain($r['description'])) ?>"><?= jq_e(jq_plain($r['description'])) ?></span></td>
                        <td class="jq-col-actions">
                            <div class="jq-btn-group" style="justify-content:flex-end;">
                                <button type="button" class="jq-btn jq-btn-sm jq-btn-view" onclick="openDetail(<?= (int)$r['id'] ?>)" title="View details<?= $r['has_diff'] ? ' and changed values' : '' ?>"><i class="bx bx-show"></i> View</button>
                                <?php if ($is_owner && $view === 'active'): ?>
                                
                                <button type="button" class="jq-btn jq-btn-sm jq-btn-archive" onclick="singleAction('archive', <?= (int)$r['id'] ?>)" title="Archive"><i class="bx bx-archive-in"></i></button>
                                <?php elseif ($is_owner && $view === 'archived'): ?>
                                <button type="button" class="jq-btn jq-btn-sm jq-btn-restore" onclick="singleAction('restore', <?= (int)$r['id'] ?>)" title="Restore"><i class="bx bx-archive-out"></i> Restore</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($rows): ?>
        <div class="jq-table-footer">
            <span class="jq-muted jq-small">Showing <?= number_format($first) ?>–<?= number_format($last) ?> of <?= number_format($total) ?> record(s)<?php if ($newer > 0): ?> · <a href="<?= jq_e(jq_url([], ['anchor', 'page'])) ?>"><?= number_format($newer) ?> newer: show latest</a><?php endif; ?></span>
            <?= jq_pagination($page, $pages) ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$is_owner): ?>
    <p class="jq-help"><i class="bx bx-lock-alt"></i> Activity records are read-only and cannot be modified.</p>
    <?php endif; ?>

    <!-- ============ Detail modal ============ -->
    <div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detailTitle"><i class="bx bx-detail"></i> Activity details</h5>
                    <button type="button" class="jq-modal-close" data-bs-dismiss="modal" aria-label="Close"><i class="bx bx-x"></i></button>
                </div>
                <div class="modal-body" id="detailBody"><div class="jq-loading"><span class="jq-spinner"></span> Loading…</div></div>
                <div class="modal-footer" id="detailFooter">
                    <button type="button" class="jq-btn jq-btn-cancel" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($is_owner): ?>

    <!-- Hidden form for archive / restore -->
    <form method="POST" id="manageForm" style="display:none;">
        <?= jq_csrf_field() ?>
        <input type="hidden" name="op" id="manageOp">
        <input type="hidden" name="reason" id="manageReason">
        <input type="hidden" name="return" value="<?= jq_e(jq_url()) ?>">
        <div id="manageIds"></div>
    </form>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';
    const IS_OWNER = <?= $is_owner ? 'true' : 'false' ?>;
    const VIEW = <?= json_encode($view) ?>;

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function modal(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }
    function getJSON(url) {
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(r => r.json().then(d => { if (!r.ok || !d.success) throw new Error(d.error || 'Request failed'); return d; }));
    }
    const sourceLabels = <?= json_encode($source_labels) ?>;

    function diffTable(rows, mode) {
        if (!rows || !rows.length) return '<p class="jq-help">No field values were recorded for this event.</p>';
        const showOld = mode !== 'CREATE', showNew = mode !== 'DELETE';
        let h = '<div class="jq-table-wrap"><table class="jq-diff"><thead><tr><th>Field</th>' +
            (showOld ? '<th>Previous value</th>' : '') + (showNew ? '<th>Updated value</th>' : '') + '</tr></thead><tbody>';
        rows.forEach(r => {
            const cell = (v, cls) => '<td class="' + cls + '">' + (v === null ? '<span class="jq-null">empty</span>' : esc(v)) + '</td>';
            h += '<tr class="' + (r.changed && showOld && showNew ? 'is-changed' : '') + '"><td class="jq-diff-field">' + esc(r.field) + '</td>' +
                (showOld ? cell(r.old, 'jq-diff-old') : '') + (showNew ? cell(r.new, 'jq-diff-new') : '') + '</tr>';
        });
        return h + '</tbody></table></div>';
    }

    window.openDetail = function (id) {
        const body = document.getElementById('detailBody'), foot = document.getElementById('detailFooter');
        body.innerHTML = '<div class="jq-loading"><span class="jq-spinner"></span> Loading…</div>';
        foot.innerHTML = '<button type="button" class="jq-btn jq-btn-cancel" data-bs-dismiss="modal">Close</button>';
        document.getElementById('detailTitle').innerHTML = '<i class="bx bx-detail"></i> Activity record #' + esc(id);
        modal('detailModal').show();
        getJSON('activitylog.php?ajax=detail&id=' + encodeURIComponent(id)).then(d => {
            const l = d.log;
            let h = '<div class="jq-section-title">Summary</div><dl class="jq-kv">' +
                '<div><dt>Date</dt><dd>' + esc(l.datetime) + '</dd></div>' +
                '<div><dt>Exact time</dt><dd>' + esc(l.time) + '</dd></div>' +
                '<div><dt>Performed by</dt><dd>' + esc(l.full_name || l.username) + (l.full_name ? ' <span class="jq-sub">' + esc(l.username) + '</span>' : '') +
                    (l.role ? ' <span class="jq-badge jq-badge-view">' + esc(l.role) + '</span>' : '') + (!l.user_exists && l.username !== 'system' ? ' <span class="jq-sub">(account no longer exists)</span>' : '') + '</dd></div>' +
                '<div><dt>Action</dt><dd><span class="jq-badge jq-badge-' + esc(l.tone) + '">' + esc(l.action_label) + '</span></dd></div>' +
                '<div><dt>Module</dt><dd>' + esc(l.module || '—') + '</dd></div>' +
                '<div><dt>Affected record</dt><dd>' + (l.record_table ? esc(l.record_table) + (l.record_id ? ' #' + esc(l.record_id) : '') : '—') +
                    (l.record_label ? '<span class="jq-sub">' + esc(l.record_label) + '</span>' : '') + '</dd></div>' +
                '</dl>' +
                '<div class="jq-section-title">Description</div><p style="white-space:pre-wrap;margin:0;color:var(--jq-text)!important;">' + esc(l.description) + '</p>';
            if (l.notes) h += '<div class="jq-section-title">Owner notes</div><p style="white-space:pre-wrap;margin:0;">' + esc(l.notes) + '</p>';
            if (d.diff && d.diff.length) h += '<div class="jq-section-title">' + (l.action === 'CREATE' ? 'Recorded values' : (l.action === 'DELETE' ? 'Values before deletion' : 'Previous vs updated values')) + '</div>' + diffTable(d.diff, l.action);
            h += '<div class="jq-section-title">Context</div><dl class="jq-kv">' +
                '<div><dt>IP address</dt><dd class="jq-mono">' + esc(l.ip || '—') + '</dd></div>' +
                '<div><dt>Source</dt><dd>' + esc(sourceLabels[l.source] || l.source) + '</dd></div>' +
                '<div><dt>Page</dt><dd class="jq-mono">' + esc(l.page || '—') + '</dd></div>' +
                '<div style="grid-column:1/-1;"><dt>Device / browser</dt><dd class="jq-mono">' + esc(l.user_agent || '—') + '</dd></div></dl>';
            if (l.archived || l.restored) {
                h += '<div class="jq-section-title">Record management</div><dl class="jq-kv">' +
                    (l.archived ? '<div><dt>' + (l.is_archived ? 'Archived' : 'Last archived') + '</dt><dd>' + esc(l.archived) + '</dd></div>' : '') +
                    (l.restored ? '<div><dt>Last restored</dt><dd>' + esc(l.restored) + '</dd></div>' : '') + '</dl>';
            }
            if (IS_OWNER && d.history.length) {
                h += '<div class="jq-section-title">Management history</div><ul class="jq-timeline">';
                d.history.forEach(e => {
                    h += '<li><div class="jq-tl-head"><strong>' + esc(e.operation) + '</strong> by ' + esc(e.by) + '</div>' +
                        '<div class="jq-tl-meta">' + esc(e.at) + (e.reason ? ' · Reason: ' + esc(e.reason) : '') + '</div>';
                    if (e.changes.length) {
                        h += '<div class="jq-tl-meta" style="margin-top:4px;">' + e.changes.map(c =>
                            '<div><strong>' + esc(c.field) + ':</strong> ' + esc(c.old === null ? 'empty' : c.old) + ' → ' + esc(c.new === null ? 'empty' : c.new) + '</div>').join('') + '</div>';
                    }
                    h += '</li>';
                });
                h += '</ul>';
            }
            body.innerHTML = h;
            if (d.can_manage) {
                let b = '<button type="button" class="jq-btn jq-btn-cancel" data-bs-dismiss="modal">Close</button>';
                if (!l.is_archived) {
                    b = '<button type="button" class="jq-btn jq-btn-archive" onclick="singleAction(\'archive\',' + l.id + ')"><i class="bx bx-archive-in"></i> Archive</button>' + b;
                } else {
                    b = '<button type="button" class="jq-btn jq-btn-restore" onclick="singleAction(\'restore\',' + l.id + ')"><i class="bx bx-archive-out"></i> Restore</button>' + b;
                }
                foot.innerHTML = b;
            }
        }).catch(err => {
            body.innerHTML = '<div class="jq-alert jq-alert-danger"><i class="bx bx-error-circle"></i><p>' + esc(err.message) + '</p></div>';
        });
    };

    window.openAuditDetail = function (id) {
        const body = document.getElementById('detailBody');
        document.getElementById('detailTitle').innerHTML = '<i class="bx bx-shield-quarter"></i> Management action #' + esc(id);
        document.getElementById('detailFooter').innerHTML = '<button type="button" class="jq-btn jq-btn-cancel" data-bs-dismiss="modal">Close</button>';
        body.innerHTML = '<div class="jq-loading"><span class="jq-spinner"></span> Loading…</div>';
        modal('detailModal').show();
        getJSON('activitylog.php?ajax=audit_detail&id=' + encodeURIComponent(id)).then(d => {
            const e = d.entry;
            body.innerHTML = '<dl class="jq-kv">' +
                '<div><dt>Operation</dt><dd><strong>' + esc(e.operation) + '</strong></dd></div>' +
                '<div><dt>Log record</dt><dd>#' + esc(e.log_id) + '</dd></div>' +
                '<div><dt>Performed by</dt><dd>' + esc(e.by) + ' <span class="jq-badge jq-badge-view">' + esc(e.role || '—') + '</span></dd></div>' +
                '<div><dt>When</dt><dd>' + esc(e.at) + '</dd></div>' +
                '<div><dt>IP address</dt><dd class="jq-mono">' + esc(e.ip || '—') + '</dd></div>' +
                '<div style="grid-column:1/-1;"><dt>Reason</dt><dd>' + esc(e.reason || '—') + '</dd></div></dl>' +
                '<div class="jq-section-title">Record before vs after</div>' + diffTable(d.diff, e.operation === 'CREATE' ? 'CREATE' : 'UPDATE');
        }).catch(err => { body.innerHTML = '<div class="jq-alert jq-alert-danger"><i class="bx bx-error-circle"></i><p>' + esc(err.message) + '</p></div>'; });
    };

    if (!IS_OWNER) return;

    function submitManage(op, ids, reason) {
        document.getElementById('manageOp').value = op;
        document.getElementById('manageReason').value = reason || '';
        document.getElementById('manageIds').innerHTML = ids.map(i => '<input type="hidden" name="ids[]" value="' + parseInt(i, 10) + '">').join('');
        showLoading(op === 'archive' ? 'Archiving…' : 'Restoring…');
        document.getElementById('manageForm').submit();
    }
    function confirmManage(op, ids) {
        const archive = op === 'archive';
        const n = ids.length;
        Swal.fire({
            icon: 'question',
            title: (archive ? 'Archive ' : 'Restore ') + (n === 1 ? 'record #' + ids[0] : n + ' records') + '?',
            html: archive
                ? '<p style="margin:0 0 10px;">Archived records are hidden from the active log but <strong>remain stored</strong> and can be restored at any time.</p>'
                : '<p style="margin:0 0 10px;">The record' + (n === 1 ? '' : 's') + ' will return to the active activity log.</p>',
            input: 'text',
            inputLabel: 'Reason (optional)',
            inputPlaceholder: archive ? 'e.g. Duplicate test entries' : 'e.g. Archived by mistake',
            inputAttributes: { maxlength: 500 },
            showCancelButton: true,
            reverseButtons: true,
            confirmButtonText: archive ? 'Archive' : 'Restore',
            cancelButtonText: 'Cancel',
            customClass: { confirmButton: archive ? 'jq-swal-archive' : 'jq-swal-restore', cancelButton: 'jq-swal-cancel' }
        }).then(res => { if (res.isConfirmed) submitManage(op, ids, res.value); });
    }
    window.singleAction = function (op, id) {
        const dm = bootstrap.Modal.getInstance(document.getElementById('detailModal'));
        if (dm) dm.hide();
        confirmManage(op, [id]);
    };
    window.bulkAction = function (op) {
        const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(c => c.value);
        if (ids.length) confirmManage(op, ids);
    };

    const selectAll = document.getElementById('selectAll');
    const bar = document.getElementById('bulkBar');
    function syncBulk() {
        const checks = Array.from(document.querySelectorAll('.row-check'));
        const sel = checks.filter(c => c.checked);
        checks.forEach(c => c.closest('tr').classList.toggle('is-selected', c.checked));
        if (bar) { bar.classList.toggle('is-visible', sel.length > 0); document.getElementById('bulkCount').textContent = sel.length; }
        if (selectAll) { selectAll.checked = sel.length > 0 && sel.length === checks.length; selectAll.indeterminate = sel.length > 0 && sel.length < checks.length; }
    }
    window.clearSelection = function () { document.querySelectorAll('.row-check').forEach(c => c.checked = false); syncBulk(); };
    document.querySelectorAll('.row-check').forEach(c => c.addEventListener('change', syncBulk));
    if (selectAll) selectAll.addEventListener('change', () => { document.querySelectorAll('.row-check').forEach(c => c.checked = selectAll.checked); syncBulk(); });

    window.confirmResync = function () {
        Swal.fire({
            icon: 'question', title: 'Re-sync change tracking?',
            text: 'Re-installs the database triggers that record every create, update, archive and delete. Use this after the database structure changed.',
            showCancelButton: true, reverseButtons: true, confirmButtonText: 'Re-sync', cancelButtonText: 'Cancel',
            customClass: { confirmButton: 'jq-swal-primary', cancelButton: 'jq-swal-cancel' }
        }).then(r => { if (r.isConfirmed) { showLoading('Synchronizing…'); document.getElementById('resyncForm').submit(); } });
    };
})();
</script>

<?php require_once 'includes/footer.php'; ?>
