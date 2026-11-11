<?php
/**
 * Reports Module - Janet's Quality Catering System
 * ADMIN and OWNER: create, view, update, archive, restore and monitor all reports.
 * Archived reports are permanently deleted after 30 days by cli/maintenance_cli.php.
 */

$page_title = "Reports | Janet's Quality Catering";
$current_page = 'reports';

require_once 'includes/auth_check.php';
require_once 'includes/archive_manager.php';

/** @var array{id: ?int, username: string, role: string, profile_photo: ?string, first_name: string, last_name: string} $current_user */

$pdo = getDBConnection();
if (!$pdo) {
    setFlash('The database is not available. Reports cannot be displayed.', 'danger');
    redirect('dashboard.php');
}
jq_require_roles($pdo, ['ADMIN', 'OWNER'], 'Reports');

const JQ_REPORT_STATUSES = ['Generated', 'Draft', 'Pending', 'Reviewed', 'Resolved'];
const JQ_REPORT_TYPES = ['general' => 'General', 'inventory' => 'Inventory', 'feedback' => 'Feedback', 'complaint' => 'Complaint', 'suggestion' => 'Suggestion', 'inquiry' => 'Inquiry'];

$action = $_GET['action'] ?? '';
$report_id = (int)($_GET['id'] ?? 0);
$view_archived = ($_GET['view'] ?? '') === 'archived';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_action = $_POST['form_action'] ?? '';
    $post_id = (int)($_POST['report_id'] ?? 0);
    $back = 'reports.php';

    try {
        switch ($form_action) {
            case 'add_report':
            case 'edit_report':
                $title = sanitize($_POST['title'] ?? '');
                $content = sanitize($_POST['content'] ?? '');
                $status = (string)($_POST['status'] ?? '');
                $type = (string)($_POST['report_type'] ?? '');
                $problem = null;
                if ($title === '' || mb_strlen($title) > 255) {
                    $problem = 'Report title is required (255 characters at most).';
                } elseif ($content === '' || mb_strlen($content) > 60000) {
                    $problem = 'Report content is required (60,000 characters at most).';
                } elseif (!in_array($status, JQ_REPORT_STATUSES, true)) {
                    $problem = 'Choose a valid report status.';
                } elseif (!isset(JQ_REPORT_TYPES[$type])) {
                    $problem = 'Choose a valid report type.';
                }
                if ($problem !== null) {
                    setFlash($problem, 'danger');
                    redirect($form_action === 'add_report' ? 'reports.php?action=add' : 'reports.php?action=edit&id=' . $post_id);
                }
                if ($form_action === 'add_report') {
                    $pdo->prepare("INSERT INTO reports (user_id, title, content, report_type, created_by, status) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([(int)$_SESSION['user_id'], $title, $content, $type, $current_user['username'], $status]);
                    setFlash('Report created successfully!', 'success');
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE id = ? AND is_archived = 0");
                    $stmt->execute([$post_id]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        setFlash('Only an active report can be edited.', 'danger');
                        break;
                    }
                    $pdo->prepare("UPDATE reports SET title = ?, content = ?, report_type = ?, status = ? WHERE id = ? AND is_archived = 0")
                        ->execute([$title, $content, $type, $status, $post_id]);
                    setFlash('Report updated successfully!', 'success');
                }
                break;

            case 'archive_report':
                $stmt = $pdo->prepare("UPDATE reports SET is_archived = 1, archived_at = NOW(), archive_expires_at = DATE_ADD(NOW(), INTERVAL " . JQ_ARCHIVE_RETENTION_DAYS . " DAY), archived_by = ? WHERE id = ? AND is_archived = 0");
                $stmt->execute([(int)$_SESSION['user_id'], $post_id]);
                setFlash($stmt->rowCount() > 0
                    ? 'Report archived. It will be permanently deleted after ' . JQ_ARCHIVE_RETENTION_DAYS . ' days unless you restore it.'
                    : 'This report is already archived.', $stmt->rowCount() > 0 ? 'success' : 'warning');
                break;

            case 'restore_report':
                $stmt = $pdo->prepare("UPDATE reports SET is_archived = 0, archived_at = NULL, archive_expires_at = NULL, archived_by = NULL WHERE id = ? AND is_archived = 1");
                $stmt->execute([$post_id]);
                setFlash($stmt->rowCount() > 0 ? 'Report restored.' : 'This report is not in the archive.', $stmt->rowCount() > 0 ? 'success' : 'warning');
                $back = 'reports.php?view=archived';
                break;

            default:
                setFlash('Unknown action.', 'warning');
        }
    } catch (PDOException $e) {
        error_log('reports.php ' . $form_action . ': ' . $e->getMessage());
        setFlash('The report could not be saved. Nothing was changed.', 'danger');
    }
    redirect($back);
}

$edit_report = null;
if ($action === 'edit') {
    $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = ? AND is_archived = 0");
    $stmt->execute([$report_id]);
    $edit_report = $stmt->fetch();
    if (!$edit_report) {
        setFlash('This report cannot be edited. It may be archived or deleted.', 'warning');
        redirect('reports.php');
    }
}

$stmt = $pdo->prepare("SELECT r.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS author_name
                         FROM reports r LEFT JOIN users u ON u.id = r.user_id
                        WHERE r.is_archived = ? ORDER BY " . ($view_archived ? 'r.archived_at DESC' : 'r.created_at DESC'));
$stmt->execute([$view_archived ? 1 : 0]);
$reports = $stmt->fetchAll();

$totals = array_fill_keys(JQ_REPORT_STATUSES, 0);
foreach ($pdo->query("SELECT status, COUNT(*) FROM reports WHERE is_archived = 0 GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
    $totals[$k] = (int)$v;
}
$active_total = array_sum($totals);
$archived_total = (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE is_archived = 1")->fetchColumn();
$retention = jq_retention_status($pdo);

require_once 'includes/header.php';
?>

<style>
    .reports-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 15px; }
    .reports-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .report-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .report-chip { padding: 6px 12px; border-radius: 8px; font-size: .8rem; font-weight: 600; background: var(--card-bg); border: 1px solid var(--border-color); color: var(--body-color); }
    .report-chip strong { color: var(--heading-color); }
    .report-filter { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
    .report-filter input { flex: 1 1 240px; min-width: 0; }
    .report-filter select { flex: 0 1 200px; }
    .report-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; margin-bottom: 20px; overflow: hidden; transition: .3s; }
    .report-card:hover, .report-card.is-highlighted { border-color: var(--bs-primary); }
    .report-card-header { padding: 20px 25px; display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; border-bottom: 1px solid var(--border-color); }
    .report-title { font-weight: 800; font-size: 1.1rem; margin-bottom: 5px; color: var(--heading-color); overflow-wrap: anywhere; }
    .report-meta { display: flex; flex-wrap: wrap; gap: 6px 20px; color: var(--body-color); font-size: .85rem; }
    .report-meta i { margin-right: 5px; color: var(--bs-primary); }
    .report-card-body { padding: 20px 25px; }
    .report-content { color: var(--body-color); line-height: 1.7; margin: 0; overflow-wrap: anywhere; }
    .report-card-footer { padding: 15px 25px; background: rgba(147, 112, 219, .03); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
    .report-form { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 30px; margin-bottom: 30px; }
    .empty-reports { text-align: center; padding: 60px 20px; }
    .empty-reports i { font-size: 4rem; color: var(--bs-secondary); opacity: .4; }
    .status-badge { padding: 6px 12px; border-radius: 8px; font-size: .72rem; font-weight: 700; text-transform: uppercase; white-space: nowrap; background: rgba(147, 112, 219, .14); color: var(--jq-brand-text) !important; }
    .report-expiry { font-size: .82rem; color: var(--jq-delete-text) !important; font-weight: 600; }
    .report-view-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px 20px; margin: 0 0 16px; }
    .report-view-meta dt { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--bs-secondary); font-weight: 600; }
    .report-view-meta dd { margin: 2px 0 0; color: var(--heading-color); overflow-wrap: anywhere; }
    #viewReportContent { white-space: pre-wrap; line-height: 1.8; overflow-wrap: anywhere; color: var(--body-color); }
    @media (max-width: 575px) { .report-card-header, .report-card-body { padding: 16px; } .report-card-footer { padding: 12px 16px; } .report-form { padding: 18px; } }
</style>

<?php if ($action === 'add' || $action === 'edit'): ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="page-title mb-0"><?php echo $action === 'edit' ? 'Edit Report' : 'Create New Report'; ?></h2>
    <a href="reports.php" class="btn btn-label-primary"><i class="bx bx-arrow-back me-1"></i>Back to Reports</a>
</div>

<div class="report-form">
    <form method="POST" action="reports.php">
        <?php echo jq_csrf_field(); ?>
        <input type="hidden" name="form_action" value="<?php echo $action === 'edit' ? 'edit_report' : 'add_report'; ?>">
        <?php if ($edit_report): ?>
        <input type="hidden" name="report_id" value="<?php echo (int)$edit_report['id']; ?>">
        <?php endif; ?>

        <div class="mb-3">
            <label class="form-label" for="reportTitle">Report Title <span class="text-danger">*</span></label>
            <input type="text" id="reportTitle" name="title" class="form-control" required maxlength="255"
                   value="<?php echo htmlspecialchars($edit_report['title'] ?? ''); ?>"
                   placeholder="e.g., Monthly Inventory Audit - April">
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label" for="reportType">Report Type</label>
                <select id="reportType" name="report_type" class="form-select">
                    <?php foreach (JQ_REPORT_TYPES as $value => $label): ?>
                    <option value="<?php echo $value; ?>" <?php echo ($edit_report['report_type'] ?? 'general') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="reportStatus">Status</label>
                <select id="reportStatus" name="status" class="form-select">
                    <?php foreach (JQ_REPORT_STATUSES as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo ($edit_report['status'] ?? 'Generated') === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label" for="reportContent">Report Content <span class="text-danger">*</span></label>
            <textarea id="reportContent" name="content" class="form-control" rows="10" required maxlength="60000"
                      placeholder="Enter report details..."><?php echo htmlspecialchars($edit_report['content'] ?? ''); ?></textarea>
        </div>

        <div class="d-flex gap-3 flex-wrap">
            <button type="submit" class="btn btn-primary"><i class="bx bx-save me-2"></i><?php echo $action === 'edit' ? 'Update Report' : 'Save Report'; ?></button>
            <a href="reports.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php else: ?>

<div class="reports-header">
    <div>
        <h2 class="page-title mb-1"><?php echo $view_archived ? 'Archived Reports' : 'Reports Management'; ?></h2>
        <p class="text-muted mb-0"><?php echo $view_archived
            ? 'Archived reports are permanently deleted ' . JQ_ARCHIVE_RETENTION_DAYS . ' days after they are archived. Restore one to keep it.'
            : 'Create, update, archive and monitor every report.'; ?></p>
    </div>
    <div class="reports-actions">
        <?php if ($view_archived): ?>
        <a href="reports.php" class="btn btn-label-primary"><i class="bx bx-arrow-back me-1"></i>Back to Reports</a>
        <?php else: ?>
        <a href="reports.php?view=archived" class="btn btn-label-primary"><i class="bx bx-archive me-1"></i>Archive (<?php echo $archived_total; ?>)</a>
        <a href="reports.php?action=add" class="btn btn-primary"><i class="bx bx-plus me-1"></i>Create Report</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($view_archived): ?>
<div class="alert <?php echo $retention['ok'] ? 'alert-info' : 'alert-warning'; ?> mb-4"><i class="bx <?php echo $retention['ok'] ? 'bx-time-five' : 'bx-error'; ?> me-1"></i><?php echo jq_e($retention['message']); ?></div>
<?php else: ?>
<div class="report-chips">
    <span class="report-chip">Active: <strong><?php echo $active_total; ?></strong></span>
    <?php foreach ($totals as $status_name => $n): ?>
    <span class="report-chip"><?php echo $status_name; ?>: <strong><?php echo $n; ?></strong></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($reports)): ?>
<div class="card">
    <div class="empty-reports">
        <i class="bx <?php echo $view_archived ? 'bx-archive' : 'bx-file'; ?> d-block mb-3"></i>
        <h4><?php echo $view_archived ? 'No Archived Reports' : 'No Reports Found'; ?></h4>
        <p class="text-muted"><?php echo $view_archived ? 'Reports you archive will appear here.' : 'Start by creating your first report.'; ?></p>
        <?php if (!$view_archived): ?>
        <a href="reports.php?action=add" class="btn btn-primary mt-3"><i class="bx bx-plus me-2"></i>Create First Report</a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<div class="report-filter">
    <input type="search" class="form-control" id="reportSearch" placeholder="Search by title, author or content" aria-label="Search reports">
    <select class="form-select" id="reportStatusFilter" aria-label="Filter by status">
        <option value="">All statuses</option>
        <?php foreach (JQ_REPORT_STATUSES as $s): ?><option value="<?php echo $s; ?>"><?php echo $s; ?></option><?php endforeach; ?>
    </select>
</div>
<div class="alert alert-info" id="reportNoMatch" style="display:none;">No reports match your search.</div>

<?php foreach ($reports as $report):
    $author = $report['author_name'] !== '' && $report['author_name'] !== null ? $report['author_name'] : ($report['created_by'] ?: 'System');
    $payload = [
        'id'         => (int)$report['id'],
        'title'      => $report['title'],
        'content'    => (string)$report['content'],
        'type'       => JQ_REPORT_TYPES[$report['report_type']] ?? 'General',
        'status'     => $report['status'],
        'author'     => $author,
        'created_at' => date('F d, Y h:i A', strtotime($report['created_at'])),
        'updated_at' => date('F d, Y h:i A', strtotime($report['updated_at'])),
        'response'   => (string)$report['admin_response'],
        'archived'   => $report['archived_at'] ? date('F d, Y h:i A', strtotime($report['archived_at'])) : '',
        'expires'    => $report['archive_expires_at'] ? date('F d, Y h:i A', strtotime($report['archive_expires_at'])) : '',
    ];
    $days_left = jq_archive_days_left($report['archive_expires_at']);
    $search_text = mb_strtolower($report['title'] . ' ' . $author . ' ' . $report['content']);
?>
<div class="report-card" id="report-<?php echo (int)$report['id']; ?>" data-search="<?php echo htmlspecialchars($search_text); ?>" data-status="<?php echo htmlspecialchars($report['status']); ?>">
    <div class="report-card-header">
        <div>
            <div class="report-title"><?php echo htmlspecialchars($report['title']); ?></div>
            <div class="report-meta">
                <span><i class="bx bx-calendar"></i><?php echo date('F d, Y', strtotime($report['created_at'])); ?></span>
                <span><i class="bx bx-user"></i><?php echo htmlspecialchars($author); ?></span>
                <span><i class="bx bx-purchase-tag"></i><?php echo htmlspecialchars($payload['type']); ?></span>
            </div>
        </div>
        <span class="status-badge"><?php echo htmlspecialchars($report['status']); ?></span>
    </div>
    <div class="report-card-body">
        <p class="report-content"><?php echo htmlspecialchars(mb_strimwidth((string)$report['content'], 0, 303, '...')); ?></p>
    </div>
    <div class="report-card-footer">
        <?php if ($view_archived): ?>
        <span class="report-expiry"><i class="bx bx-time-five me-1"></i>Deletes on <?php echo date('M d, Y', strtotime($report['archive_expires_at'])); ?> (<?php echo $days_left; ?> day<?php echo $days_left === 1 ? '' : 's'; ?> left)</span>
        <?php else: ?>
        <small class="text-muted">Report ID: #<?php echo (int)$report['id']; ?></small>
        <?php endif; ?>
        <div class="reports-actions">
            <button type="button" class="btn btn-sm btn-label-primary" data-report="<?php echo htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>" onclick="viewReport(JSON.parse(this.dataset.report))"><i class="bx bx-show me-1"></i>View</button>
            <?php if ($view_archived): ?>
            <button type="button" class="btn btn-sm btn-primary" data-title="<?php echo htmlspecialchars($report['title'], ENT_QUOTES); ?>" onclick="confirmReportAction('restore_report', <?php echo (int)$report['id']; ?>, this.dataset.title)"><i class="bx bx-archive-out me-1"></i>Restore</button>
            <?php else: ?>
            <a href="reports.php?action=edit&amp;id=<?php echo (int)$report['id']; ?>" class="btn btn-sm btn-label-primary"><i class="bx bx-edit me-1"></i>Edit</a>
            <button type="button" class="btn btn-sm btn-label-danger" data-title="<?php echo htmlspecialchars($report['title'], ENT_QUOTES); ?>" onclick="confirmReportAction('archive_report', <?php echo (int)$report['id']; ?>, this.dataset.title)"><i class="bx bx-archive-in me-1"></i>Archive</button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<div class="modal fade" id="viewReportModal" tabindex="-1" aria-labelledby="viewReportTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewReportTitle">Report Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="report-view-meta" id="viewReportMeta"></dl>
                <hr style="border-color: var(--border-color);">
                <div id="viewReportContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-label-primary" onclick="printReport()"><i class="bx bx-printer me-1"></i>Print</button>
                <button type="button" class="btn btn-primary" id="reportPdfBtn" onclick="saveReportPdf()"><i class="bx bxs-file-pdf me-1"></i>Save as PDF</button>
            </div>
        </div>
    </div>
</div>

<form id="reportActionForm" method="POST" action="reports.php" style="display:none;">
    <?php echo jq_csrf_field(); ?>
    <input type="hidden" name="form_action" id="reportActionName">
    <input type="hidden" name="report_id" id="reportActionId">
</form>

<script src="static/js/vendor/jspdf.umd.min.js"></script>
<script>
let currentReport = null;

function reportFacts(r) {
    const facts = [['Report ID', '#' + r.id], ['Type', r.type], ['Status', r.status], ['Created by', r.author], ['Created on', r.created_at], ['Last updated', r.updated_at]];
    if (r.archived) { facts.push(['Archived on', r.archived], ['Scheduled for deletion', r.expires]); }
    return facts;
}

function viewReport(r) {
    currentReport = r;
    document.getElementById('viewReportTitle').textContent = r.title;
    document.getElementById('viewReportMeta').innerHTML = reportFacts(r).map(f => '<div><dt>' + escapeHtml(f[0]) + '</dt><dd>' + escapeHtml(f[1]) + '</dd></div>').join('');
    document.getElementById('viewReportContent').textContent = r.content + (r.response ? '\n\nResponse:\n' + r.response : '');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('viewReportModal')).show();
}

function confirmReportAction(action, id, title) {
    const archive = action === 'archive_report';
    Swal.fire({
        icon: archive ? 'warning' : 'question',
        title: archive ? 'Archive Report?' : 'Restore Report?',
        html: archive
            ? '<strong>' + escapeHtml(title) + '</strong> will move to the archive and be <strong>permanently deleted after 30 days</strong> unless you restore it.'
            : '<strong>' + escapeHtml(title) + '</strong> will return to the active reports.',
        showCancelButton: true,
        reverseButtons: true,
        focusCancel: archive,
        confirmButtonColor: '#9370DB',
        cancelButtonColor: '#8592a3',
        confirmButtonText: archive ? 'Yes, Archive' : 'Yes, Restore',
        cancelButtonText: 'Cancel',
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('reportActionName').value = action;
            document.getElementById('reportActionId').value = id;
            showLoading('Processing...');
            document.getElementById('reportActionForm').submit();
        }
    });
}

/* Printed through a hidden frame with fixed light colours, so dark mode never reaches the paper. */
function printReport() {
    const r = currentReport;
    if (!r) return;
    const rows = reportFacts(r).map(f => '<tr><th>' + escapeHtml(f[0]) + '</th><td>' + escapeHtml(f[1]) + '</td></tr>').join('');
    const html = '<!doctype html><html><head><meta charset="utf-8"><title>' + escapeHtml(r.title) + '</title><style>'
        + '@page{margin:18mm}body{font-family:Arial,Helvetica,sans-serif;color:#222;line-height:1.6;margin:0}'
        + '.brand{color:#6A4FB5;font-weight:700;letter-spacing:.04em;font-size:12px;text-transform:uppercase}'
        + 'h1{font-size:22px;margin:4px 0 14px;color:#2D2449}table{border-collapse:collapse;width:100%;margin-bottom:18px;font-size:13px}'
        + 'th{text-align:left;width:30%;color:#555;font-weight:600}th,td{padding:5px 8px;border-bottom:1px solid #ddd;vertical-align:top}'
        + 'h2{font-size:15px;margin:0 0 8px;color:#2D2449}.content{white-space:pre-wrap;overflow-wrap:anywhere;font-size:14px}'
        + '</style></head><body><div class="brand">Janet\'s Quality Catering</div><h1>' + escapeHtml(r.title) + '</h1><table>' + rows + '</table>'
        + '<h2>Report</h2><div class="content">' + escapeHtml(r.content) + '</div>'
        + (r.response ? '<h2 style="margin-top:18px">Response</h2><div class="content">' + escapeHtml(r.response) + '</div>' : '')
        + '</body></html>';
    let frame = document.getElementById('reportPrintFrame');
    if (frame) frame.remove();
    frame = document.createElement('iframe');
    frame.id = 'reportPrintFrame';
    frame.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden;';
    document.body.appendChild(frame);
    frame.contentDocument.open();
    frame.contentDocument.write(html);
    frame.contentDocument.close();
    frame.contentWindow.focus();
    frame.contentWindow.print();
}

/* jsPDF's built-in fonts cover Western European letters. Other symbols get a plain equivalent. */
function pdfText(value) {
    return String(value == null ? '' : value)
        .replace(/₱/g, 'PHP ').replace(/[‘’]/g, "'").replace(/[“”]/g, '"')
        .replace(/[–—]/g, '-').replace(/…/g, '...').replace(/•/g, '-')
        .replace(/\r\n/g, '\n').replace(/[^\n\t\x20-\x7E -ÿ]/g, '?');
}

function buildReportPdf(r) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'pt', format: 'a4' });
    const W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight(), M = 50, width = W - M * 2;
    let y = M;
    const room = (needed) => { if (y + needed > H - M) { doc.addPage(); y = M; } };
    const paragraph = (text, size, style, color, leading) => {
        doc.setFont('helvetica', style); doc.setFontSize(size); doc.setTextColor(color[0], color[1], color[2]);
        doc.splitTextToSize(pdfText(text), width).forEach(line => { room(leading); doc.text(line, M, y); y += leading; });
    };

    paragraph("JANET'S QUALITY CATERING", 10, 'bold', [106, 79, 181], 14);
    y += 4;
    paragraph(r.title, 18, 'bold', [45, 36, 73], 23);
    y += 6;
    reportFacts(r).forEach(f => {
        room(16);
        doc.setFont('helvetica', 'bold'); doc.setFontSize(10); doc.setTextColor(85, 85, 85);
        doc.text(pdfText(f[0]), M, y);
        doc.setFont('helvetica', 'normal'); doc.setTextColor(34, 34, 34);
        doc.text(doc.splitTextToSize(pdfText(f[1]), width - 140)[0], M + 140, y);
        y += 16;
    });
    y += 4;
    doc.setDrawColor(200, 184, 232); doc.line(M, y, W - M, y);
    y += 20;
    paragraph('Report', 13, 'bold', [45, 36, 73], 18);
    y += 2;
    paragraph(r.content, 11, 'normal', [34, 34, 34], 16);
    if (r.response) {
        y += 12;
        paragraph('Response', 13, 'bold', [45, 36, 73], 18);
        y += 2;
        paragraph(r.response, 11, 'normal', [34, 34, 34], 16);
    }

    const pages = doc.internal.getNumberOfPages();
    for (let p = 1; p <= pages; p++) {
        doc.setPage(p);
        doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(119, 119, 119);
        doc.text('Report #' + r.id + ' - generated ' + new Date().toLocaleString('en-PH'), M, H - 24);
        doc.text('Page ' + p + ' of ' + pages, W - M, H - 24, { align: 'right' });
    }
    return doc;
}

function saveReportPdf() {
    if (!currentReport) return;
    const name = 'Report_' + currentReport.id + '_' + (currentReport.title.replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60) || 'Details') + '.pdf';
    buildReportPdf(currentReport).save(name);
}

(function () {
    const search = document.getElementById('reportSearch');
    if (!search) return;
    const filter = document.getElementById('reportStatusFilter');
    const cards = Array.from(document.querySelectorAll('.report-card'));
    const none = document.getElementById('reportNoMatch');
    function apply() {
        const term = search.value.trim().toLowerCase();
        let shown = 0;
        cards.forEach(c => {
            const ok = (!term || c.dataset.search.includes(term)) && (!filter.value || c.dataset.status === filter.value);
            c.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        none.style.display = shown ? 'none' : '';
    }
    search.addEventListener('input', apply);
    filter.addEventListener('change', apply);
    const highlight = new URLSearchParams(location.search).get('highlight');
    const target = highlight && /^\d+$/.test(highlight) ? document.getElementById('report-' + highlight) : null;
    if (target) { target.classList.add('is-highlighted'); target.scrollIntoView({ block: 'center' }); }
})();
</script>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
