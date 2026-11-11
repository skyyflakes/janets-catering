<?php
/**
 * NOTIFICATION HISTORY
 * Janet's Quality Catering System - OWNER and ADMIN
 *
 * Everything a user was notified about: what happened, who did it, when, and read / unread.
 * Rows are created automatically by the activity-log trigger, one per user per event.
 */

$page_title   = "Notifications | Janet's Quality Catering";
$current_page = 'notifications';

require_once 'includes/auth_check.php';
/** @var PDO|null $pdo */

if (!$pdo) {
    setFlash('The database is not available.', 'danger');
    redirect('dashboard.php');
}
jq_require_roles($pdo, ['ADMIN', 'OWNER'], 'Notifications');
$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!jq_csrf_valid($_POST['csrf_token'] ?? null)) {
        setFlash('Your session security token expired. Please try again.', 'warning');
    } elseif (($_POST['op'] ?? '') === 'mark_all') {
        $st = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $st->execute([$user_id]);
        setFlash($st->rowCount() . ' notification(s) marked as read.', 'success');
    } elseif (($_POST['op'] ?? '') === 'mark_one') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([(int)($_POST['id'] ?? 0), $user_id]);
    }
    $return = (string)($_POST['return'] ?? '');
    redirect(preg_match('/^notifications\.php(\?[^\r\n]*)?$/', $return) ? $return : 'notifications.php');
}

$f = [
    'q'     => trim((string)($_GET['q'] ?? '')),
    'state' => in_array($_GET['state'] ?? '', ['read', 'unread'], true) ? $_GET['state'] : '',
    'type'  => in_array($_GET['type'] ?? '', ['event', 'inventory', 'report', 'feedback', 'system'], true) ? $_GET['type'] : '',
    'from'  => trim((string)($_GET['from'] ?? '')),
    'to'    => trim((string)($_GET['to'] ?? '')),
];
foreach (['from', 'to'] as $k) {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $f[$k], jq_tz());
    if (!$d || $d->format('Y-m-d') !== $f[$k]) {
        $f[$k] = '';
    }
}
$where = ['n.user_id = ?'];
$params = [$user_id];
if ($f['q'] !== '') {
    $like = '%' . addcslashes($f['q'], '%_\\') . '%';
    $where[] = '(n.title LIKE ? OR n.message LIKE ? OR n.actor_username LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($f['state'] !== '') { $where[] = 'n.is_read = ?'; $params[] = $f['state'] === 'read' ? 1 : 0; }
if ($f['type'] !== '')  { $where[] = 'n.type = ?'; $params[] = $f['type']; }
if ($f['from'] !== '')  { $where[] = 'n.created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
if ($f['to'] !== '')    { $where[] = 'n.created_at < ?'; $params[] = (new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'; }
// Pages stay stable while new notifications arrive: one browse counts only up to this id, so Next
// never repeats a notification and Previous never skips one.
$st = $pdo->prepare('SELECT COUNT(*) FROM notifications n WHERE ' . implode(' AND ', $where));
$st->execute($params);
$all_total = (int)$st->fetchColumn();
$st = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?');
$st->execute([$user_id]);
$anchor = max(0, (int)($_GET['anchor'] ?? 0)) ?: (int)$st->fetchColumn();
$where[] = 'n.id <= ?';
$params[] = $anchor;
$w = implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) FROM notifications n WHERE $w");
$st->execute($params);
$total = (int)$st->fetchColumn();
$newer = max(0, $all_total - $total);
$per_page = 20;
$pages = max(1, (int)ceil($total / $per_page));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$_GET['anchor'] = $anchor; // carried by the Previous / Next links
$_GET['page'] = $page;

$st = $pdo->prepare("SELECT n.*, l.module AS log_module, l.action AS log_action, u.first_name, u.last_name
                       FROM notifications n
                       LEFT JOIN activity_logs l ON l.id = n.activity_log_id
                       LEFT JOIN users u ON u.id = n.actor_user_id
                      WHERE $w ORDER BY n.created_at DESC, n.id DESC LIMIT $per_page OFFSET " . (($page - 1) * $per_page));
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$st->execute([$user_id]);
$unread = (int)$st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$st->execute([$user_id]);
$all = (int)$st->fetchColumn();

$type_tone = ['event' => 'brand', 'inventory' => 'archive', 'report' => 'edit', 'feedback' => 'restore', 'system' => 'view'];
$type_icon = ['event' => 'bx-calendar', 'inventory' => 'bx-box', 'report' => 'bx-file', 'feedback' => 'bx-comment', 'system' => 'bx-cog'];
$has_filters = (bool)array_filter($f);

require_once 'includes/header.php';
?>

<div id="jqPage">
    <div class="jq-page-header">
        <div>
            <h1 class="jq-title">Notifications</h1>
            <p class="jq-subtitle">Recent and previous notifications: what happened, who did it, and when.</p>
        </div>
        <?php if ($unread > 0): ?>
        <div class="jq-header-actions">
            <form method="POST">
                <?= jq_csrf_field() ?>
                <input type="hidden" name="op" value="mark_all">
                <input type="hidden" name="return" value="<?= jq_e(jq_url()) ?>">
                <button type="submit" class="jq-btn jq-btn-primary"><i class="bx bx-check-double"></i> Mark all as read</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="jq-stats">
        <div class="jq-stat">
            <div><span class="jq-stat-label">Unread</span><div class="jq-stat-value"><?= number_format($unread) ?></div><span class="jq-stat-hint">Need your attention</span></div>
            <div class="jq-stat-icon <?= $unread ? 'jq-tone-archive' : 'jq-tone-view' ?>"><i class="bx bx-bell"></i></div>
        </div>
        <div class="jq-stat">
            <div><span class="jq-stat-label">All notifications</span><div class="jq-stat-value"><?= number_format($all) ?></div><span class="jq-stat-hint">Kept after you log out</span></div>
            <div class="jq-stat-icon jq-tone-brand"><i class="bx bx-history"></i></div>
        </div>
    </div>

    <div class="jq-card">
        <div class="jq-card-body" style="border-bottom:1px solid var(--jq-border);">
            <form method="GET" class="jq-filters" data-no-loading="1">
                <div class="jq-field jq-field-wide">
                    <label class="jq-label" for="nq">Search</label>
                    <div class="jq-search"><i class="bx bx-search"></i>
                        <input class="jq-input" type="search" id="nq" name="q" value="<?= jq_e($f['q']) ?>" placeholder="Title, details or user…">
                    </div>
                </div>
                <div class="jq-field">
                    <label class="jq-label" for="nstate">Status</label>
                    <select class="jq-select" id="nstate" name="state">
                        <option value="">All</option>
                        <option value="unread" <?= $f['state'] === 'unread' ? 'selected' : '' ?>>Unread</option>
                        <option value="read" <?= $f['state'] === 'read' ? 'selected' : '' ?>>Read</option>
                    </select>
                </div>
                <div class="jq-field">
                    <label class="jq-label" for="ntype">Type</label>
                    <select class="jq-select" id="ntype" name="type">
                        <option value="">All types</option>
                        <?php foreach (['event' => 'Bookings', 'inventory' => 'Menus & stocks', 'report' => 'Reports', 'feedback' => 'Surveys', 'system' => 'System & accounts'] as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= jq_e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="jq-field">
                    <label class="jq-label" for="nfrom">From</label>
                    <input class="jq-input" type="date" id="nfrom" name="from" value="<?= jq_e($f['from']) ?>">
                </div>
                <div class="jq-field">
                    <label class="jq-label" for="nto">To</label>
                    <input class="jq-input" type="date" id="nto" name="to" value="<?= jq_e($f['to']) ?>">
                </div>
                <div class="jq-filter-actions">
                    <button type="submit" class="jq-btn jq-btn-primary"><i class="bx bx-filter-alt"></i> Apply</button>
                    <?php if ($has_filters): ?><a class="jq-btn jq-btn-cancel" href="notifications.php"><i class="bx bx-x"></i> Clear</a><?php endif; ?>
                </div>
            </form>
        </div>

        <?php if (!$rows): ?>
        <div class="jq-empty"><i class="bx bx-bell-off"></i><h4><?= $has_filters ? 'No notifications match your filters' : 'No notifications yet' ?></h4>
            <p>Bookings, account changes, menu updates and other important activity will appear here.</p></div>
        <?php else: ?>
        <div class="jq-table-wrap">
            <table class="jq-table">
                <thead><tr><th>Date &amp; Time</th><th>Activity</th><th>Performed by</th><th>Status</th><th class="jq-col-actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $n):
                    $unreadRow = (int)$n['is_read'] === 0;
                    $actor = trim(($n['first_name'] ?? '') . ' ' . ($n['last_name'] ?? ''));
                    $when = new DateTimeImmutable($n['created_at']);
                ?>
                    <tr<?= $unreadRow ? ' class="is-selected"' : '' ?>>
                        <td style="white-space:nowrap;"><strong><?= jq_e($when->format('M d, Y')) ?></strong><span class="jq-sub"><?= jq_e($when->format('h:i:s A')) ?></span></td>
                        <td>
                            <span class="jq-badge jq-badge-<?= $type_tone[$n['type']] ?? 'view' ?>"><i class="bx <?= $type_icon[$n['type']] ?? 'bx-bell' ?>"></i> <?= jq_e($n['title']) ?></span>
                            <span class="jq-sub" style="margin-top:6px;white-space:pre-wrap;max-width:560px;"><?= jq_e($n['message']) ?></span>
                        </td>
                        <td><strong><?= jq_e($actor !== '' ? $actor : ($n['actor_username'] ?: 'System')) ?></strong>
                            <?php if ($actor !== '' && $n['actor_username']): ?><span class="jq-sub"><?= jq_e($n['actor_username']) ?></span><?php endif; ?></td>
                        <td><span class="jq-badge jq-badge-<?= $unreadRow ? 'archive' : 'view' ?>"><?= $unreadRow ? 'Unread' : 'Read' ?></span></td>
                        <td class="jq-col-actions">
                            <?php if ($unreadRow): ?>
                            <form method="POST" class="d-inline">
                                <?= jq_csrf_field() ?>
                                <input type="hidden" name="op" value="mark_one">
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                <input type="hidden" name="return" value="<?= jq_e(jq_url()) ?>">
                                <button type="submit" class="jq-btn jq-btn-sm jq-btn-view" title="Mark as read"><i class="bx bx-check"></i> Mark read</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="jq-table-footer">
            <span class="jq-muted jq-small">Showing <?= number_format(($page - 1) * $per_page + 1) ?>–<?= number_format(min($total, $page * $per_page)) ?> of <?= number_format($total) ?><?php if ($newer > 0): ?> · <a href="<?= jq_e(jq_url([], ['anchor', 'page'])) ?>"><?= number_format($newer) ?> newer: show latest</a><?php endif; ?></span>
            <?= jq_pagination($page, $pages) ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
