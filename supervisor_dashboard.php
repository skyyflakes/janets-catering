<?php
/**
 * Event Bookings Dashboard for the Supervisor: event preparation and monitoring only.
 * Every query below names its columns. Prices, totals and payment amounts are never sent to this page:
 * the payment status is worked out on the server and only its name is shown, read only.
 * Do not use SELECT * on event_bookings or booking_items here.
 */
$page_title = "Event Bookings Dashboard | Janet's Quality Catering";
$current_page = 'supervisor_dashboard';

require_once 'includes/auth_check.php';
require_once 'includes/account_security.php';
require_once 'includes/booking_manager.php';

$pdo = getDBConnection();
jq_require_roles($pdo, ['SUPERVISOR'], 'Event Bookings Dashboard');

// The Supervisor has no Profile page, so the password is changed here.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $user_id = (int)$_SESSION['user_id'];
    if (!jq_password_matches_account($pdo, $user_id, $current)) {
        setFlash('Your current password is incorrect.', 'danger');
    } elseif ($new !== (string)($_POST['confirm_password'] ?? '')) {
        setFlash('The new password and confirmation do not match.', 'danger');
    } elseif (($password_error = jq_password_error($new)) !== null) {
        setFlash($password_error, 'danger');
    } else {
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_BCRYPT), $user_id]);
        setFlash('Your password was changed.', 'success');
    }
    redirect('supervisor_dashboard.php');
}

$today = date('Y-m-d');
$week_end = date('Y-m-d', strtotime('sunday this week'));
$live = "is_archived = 0 AND status IN ('Pending','Approved')";
$count = static fn(string $where, array $args = []) => (function () use ($pdo, $where, $args) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM event_bookings WHERE $where");
    $st->execute($args);
    return (int)$st->fetchColumn();
})();
$tiles = [
    ['Total Upcoming Events', $count("$live AND event_date >= ?", [$today]), 'bx-calendar', 'brand'],
    ['Events for Preparation', $count("$live AND event_date >= ? AND preparation_status IN ('Pending Preparation','Preparing')", [$today]), 'bx-task', 'archive'],
    ['Events Today', $count("$live AND event_date = ?", [$today]), 'bx-calendar-event', 'edit'],
    ['Events This Week', $count("$live AND event_date BETWEEN ? AND ?", [$today, $week_end]), 'bx-calendar-week', 'view'],
    ['Completed Events', $count("is_archived = 0 AND status = 'Completed'"), 'bx-badge-check', 'restore'],
];

// The only booking columns this page may read.
$st = $pdo->query("SELECT booking_id, booking_ref, fullname, contact, event_name, event_type, event_date, event_time, headcount, venue_address, region, province, city, barangay,
                          package_name, backdrop_name, backdrop_path, event_theme, event_color, notes, status, preparation_status, preparation_notes
                     FROM event_bookings
                    WHERE is_archived = 0 AND status IN ('Pending','Approved','Completed')
                    ORDER BY status = 'Completed', event_date ASC, event_time ASC");
$events = $st->fetchAll(PDO::FETCH_ASSOC);
$item_st = $pdo->prepare("SELECT i.ref_type, i.item_name, i.quantity, m.bundle_type
                            FROM booking_items i LEFT JOIN menu_bundles m ON i.ref_type = 'bundle' AND m.bundle_id = i.ref_id
                           WHERE i.booking_id = ? ORDER BY i.ref_type, i.id");
foreach ($events as &$ev) {
    $item_st->execute([$ev['booking_id']]);
    $ev['foods'] = [];
    $ev['addons'] = [];
    foreach ($item_st->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $label = $it['item_name'] . ((int)$it['quantity'] > 1 ? ' (x' . (int)$it['quantity'] . ')' : '');
        if ($it['ref_type'] === 'item' || $it['bundle_type'] === 'Food Bundle') {
            $ev['foods'][] = $label;
        } else {
            $ev['addons'][] = $label;
        }
    }
    $ev['inclusions'] = jq_booking_inclusions($pdo, (int)$ev['booking_id']);
    $ev['place'] = implode(', ', array_filter([$ev['barangay'], $ev['city'], $ev['province'], $ev['region']]));
    $ev['contact'] = jq_phone_display($ev['contact']);
}
unset($ev);

// Payment status names only. The amounts used to work them out stay on the server.
if ($events) {
    $ids = array_map('intval', array_column($events, 'booking_id'));
    $money = jq_payment_totals($pdo, $ids);
    $tot = $pdo->prepare('SELECT booking_id, grand_total FROM event_bookings WHERE booking_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $tot->execute($ids);
    $totals = $tot->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($events as &$ev) {
        $ev['payment_status'] = jq_payment_status((float)$totals[$ev['booking_id']], $money[(int)$ev['booking_id']], (string)$ev['status']);
    }
    unset($ev);
    unset($money, $totals);
}
$pay_class = ['Fully Paid' => 'restore', 'Settled' => 'restore', 'Partially Paid' => 'edit', 'Payment Pending Verification' => 'view', 'Awaiting Proof of Payment' => 'view', 'Unpaid' => 'archive'];

// Calendar of the chosen month
$month = preg_match('/^\d{4}-\d{2}$/D', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$first = strtotime($month . '-01');
$by_day = [];
foreach ($events as $ev) {
    if (strpos($ev['event_date'], $month) === 0) {
        $by_day[(int)substr($ev['event_date'], 8, 2)][] = $ev;
    }
}
$prep_class = ['Pending Preparation' => 'archive', 'Preparing' => 'edit', 'Ready for Event' => 'restore', 'Completed' => 'brand'];

require_once 'includes/header.php';
?>
<style>
#jqPage .sv-cal { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
#jqPage .sv-cal .h { font-size: .72rem; font-weight: 700; text-transform: uppercase; color: var(--jq-text-muted) !important; text-align: center; padding: 4px 0; }
#jqPage .sv-day { min-height: 76px; border: 1px solid var(--jq-border); border-radius: 8px; padding: 4px 6px; font-size: .78rem; overflow: hidden; }
#jqPage .sv-day.today { border: 2px solid var(--jq-brand); }
#jqPage .sv-day.blank { border: 0; }
#jqPage .sv-ev { display: block; width: 100%; border: 0; text-align: left; background: var(--jq-brand-badge-bg); color: var(--jq-brand-badge-text) !important; border-radius: 4px; padding: 1px 4px; margin-top: 2px; font-size: .7rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
#jqPage .sv-list { margin: 0; padding-left: 14px; font-size: .8rem; min-width: 112px; }
#jqPage .sv-list li { margin-bottom: 1px; }
#jqPage .sv-more { font-size: .78rem; color: var(--jq-text-muted) !important; }
#jqPage .sv-backdrop { max-width: 100%; max-height: 240px; border-radius: 8px; display: block; margin: 8px 0; }
#jqPage .sv-thumb-btn { display: block; border: 0; padding: 0; margin: 0 0 4px; background: none; cursor: pointer; }
#jqPage .sv-thumb { display: block; width: 64px; height: 44px; object-fit: cover; border-radius: 6px; }
#jqPage .sv-pay { display: block; margin-top: 6px; font-size: .66rem; font-weight: 700; text-transform: uppercase; color: var(--jq-text-muted) !important; }
#jqPage .sv-pay-badge { white-space: normal; text-align: left; line-height: 1.25; }
/* Twelve columns: compact, so the backdrop and View stay on screen without scrolling sideways */
#jqPage #svTable { font-size: .8rem; }
#jqPage #svTable th, #jqPage #svTable td { padding: 9px 5px; white-space: normal; }
#jqPage #svTable th { font-size: .66rem; letter-spacing: .02em; }
#jqPage #svTable td:nth-child(2) { width: 92px; max-width: 92px; overflow-wrap: anywhere; font-size: .72rem; }
#jqPage #svTable td:nth-child(5) { white-space: nowrap; }
#jqPage #svTable .sv-list { padding-left: 14px; margin: 0; }
#jqPage #svTable .jq-btn { padding: 5px 8px; font-size: .76rem; white-space: nowrap; }
#jqPage .sv-group { font-weight: 700; margin-top: 10px; }
@media (max-width: 640px) { #jqPage .sv-day { min-height: 54px; padding: 2px 3px; } #jqPage .sv-ev { font-size: .62rem; } }
</style>
<div id="jqPage">
    <div class="jq-page-header">
        <div>
            <h1 class="jq-title">Event Bookings Dashboard</h1>
            <p class="jq-subtitle">Upcoming events and what each one needs for preparation.</p>
        </div>
        <div class="jq-header-actions">
            <button type="button" class="jq-btn jq-btn-view" data-bs-toggle="modal" data-bs-target="#svPasswordModal"><i class="bx bx-lock-alt"></i> Change Password</button>
        </div>
    </div>

    <div class="jq-stats">
        <?php foreach ($tiles as [$label, $value, $icon, $tone]): ?>
        <div class="jq-stat">
            <div><span class="jq-stat-label"><?= $label ?></span><div class="jq-stat-value"><?= $value ?></div></div>
            <div class="jq-stat-icon jq-tone-<?= $tone ?>"><i class="bx <?= $icon ?>"></i></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="jq-card" style="margin-bottom:20px;">
        <div class="jq-card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2" style="margin-bottom:10px;">
                <div class="jq-section-title" style="margin:0;border:0;">Upcoming Event Calendar: <?= date('F Y', $first) ?></div>
                <div class="d-flex gap-2">
                    <a class="jq-btn jq-btn-view" href="?month=<?= date('Y-m', strtotime('-1 month', $first)) ?>" aria-label="Previous month"><i class="bx bx-chevron-left"></i></a>
                    <a class="jq-btn jq-btn-view" href="?month=<?= date('Y-m') ?>">This Month</a>
                    <a class="jq-btn jq-btn-view" href="?month=<?= date('Y-m', strtotime('+1 month', $first)) ?>" aria-label="Next month"><i class="bx bx-chevron-right"></i></a>
                </div>
            </div>
            <div class="sv-cal">
                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d): ?><div class="h"><?= $d ?></div><?php endforeach; ?>
                <?php for ($i = 0, $n = (int)date('w', $first); $i < $n; $i++): ?><div class="sv-day blank"></div><?php endfor; ?>
                <?php for ($day = 1, $last = (int)date('t', $first); $day <= $last; $day++): $is_today = $month . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT) === $today; ?>
                <div class="sv-day <?= $is_today ? 'today' : '' ?>"><strong><?= $day ?></strong>
                    <?php foreach ($by_day[$day] ?? [] as $ev): ?>
                    <button type="button" class="sv-ev" onclick="svOpen(<?= (int)$ev['booking_id'] ?>)" title="<?= jq_e($ev['event_name'] . ', ' . $ev['fullname']) ?>"><?= jq_e($ev['event_name']) ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <div class="jq-card">
        <div class="jq-card-body" style="border-bottom:1px solid var(--jq-border);">
            <div class="jq-filters">
                <div class="jq-field jq-field-wide"><label class="jq-label" for="svSearch">Search</label><div class="jq-search"><i class="bx bx-search"></i><input class="jq-input" type="search" id="svSearch" placeholder="Booking ref, client or event type"></div></div>
                <div class="jq-field"><label class="jq-label" for="svPrep">Preparation status</label>
                    <select class="jq-select" id="svPrep"><option value="__open">Needs preparation</option><option value="">All</option><?php foreach (JQ_PREPARATION_STATUSES as $p): ?><option value="<?= $p ?>"><?= $p ?></option><?php endforeach; ?></select>
                </div>
            </div>
        </div>
        <?php if (!$events): ?>
        <div class="jq-card-body" style="text-align:center;padding:50px 20px;"><i class="bx bx-calendar-x" style="font-size:3rem;opacity:.5;"></i><p style="margin-top:10px;">No events to prepare yet.</p></div>
        <?php else: ?>
        <div class="jq-table-wrap"><table class="jq-table" id="svTable">
            <thead><tr><th>#</th><th>Booking Ref</th><th>Client Name</th><th>Event Type</th><th>Event Date</th><th>Guests</th><th>Catering Package</th><th>Food Inclusions</th><th>Add-ons</th><th>Backdrop/Setup Details</th><th>Preparation Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($events as $i => $ev):
                    $pkg_inc = [];
                    foreach ($ev['inclusions'] as $src => $list) { if ($src === $ev['package_name']) { $pkg_inc = $list; } }
                    $food_preview = array_slice($pkg_inc ?: $ev['foods'], 0, 3);
                    $food_total = count($pkg_inc ?: $ev['foods']);
                ?>
                <tr data-prep="<?= jq_e($ev['preparation_status']) ?>" data-search="<?= jq_e(mb_strtolower($ev['booking_ref'] . ' ' . $ev['fullname'] . ' ' . $ev['event_type'] . ' ' . $ev['event_name'])) ?>">
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= jq_e($ev['booking_ref']) ?></strong></td>
                    <td><?= jq_e($ev['fullname']) ?></td>
                    <td><?= jq_e($ev['event_type']) ?></td>
                    <td style="white-space:nowrap;"><?= date('M d, Y', strtotime($ev['event_date'])) ?><?= $ev['event_time'] ? '<br><span class="sv-more">' . date('h:i A', strtotime($ev['event_time'])) . '</span>' : '' ?></td>
                    <td><?= number_format((int)$ev['headcount']) ?></td>
                    <td><?= jq_e($ev['package_name']) ?></td>
                    <td><ul class="sv-list"><?php foreach ($food_preview as $t): ?><li><?= jq_e($t) ?></li><?php endforeach; ?></ul><?php if ($food_total > 3): ?><span class="sv-more">and <?= $food_total - 3 ?> more</span><?php endif; ?></td>
                    <td><?php if ($ev['addons']): ?><ul class="sv-list"><?php foreach ($ev['addons'] as $t): ?><li><?= jq_e($t) ?></li><?php endforeach; ?></ul><?php else: ?><span class="sv-more">None</span><?php endif; ?></td>
                    <td>
                        <?php if ($ev['backdrop_path']): ?><button type="button" class="sv-thumb-btn" onclick="svOpen(<?= (int)$ev['booking_id'] ?>)" aria-label="View the backdrop for <?= jq_e($ev['booking_ref']) ?>"><img class="sv-thumb" src="<?= jq_e($ev['backdrop_path']) ?>" alt="<?= jq_e($ev['backdrop_name'] ?: 'Selected backdrop') ?>" loading="lazy" onerror="this.closest('button').remove()"></button><?php endif; ?>
                        <?= $ev['backdrop_name'] ? jq_e($ev['backdrop_name']) : '<span class="sv-more">Not recorded</span>' ?>
                    </td>
                    <td><span class="jq-badge jq-badge-<?= $prep_class[$ev['preparation_status']] ?? 'view' ?>"><?= jq_e($ev['preparation_status']) ?></span>
                        <span class="sv-pay">Payment:</span><span class="jq-badge jq-badge-<?= $pay_class[$ev['payment_status']] ?? 'view' ?> sv-pay-badge"><?= jq_e($ev['payment_status']) ?></span></td>
                    <td><button type="button" class="jq-btn jq-btn-view" onclick="svOpen(<?= (int)$ev['booking_id'] ?>)"><i class="bx bx-show"></i> View</button></td>
                </tr>
                <?php endforeach; ?>
                <tr id="svNone" style="display:none;"><td colspan="12" style="text-align:center;padding:24px;">No events match your filters.</td></tr>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <!-- Event details -->
    <div class="modal fade" id="svEventModal" tabindex="-1" aria-labelledby="svEventTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="svEventTitle">Event</h5><button type="button" class="jq-modal-close" data-bs-dismiss="modal" aria-label="Close"><i class="bx bx-x"></i></button></div>
                <div class="modal-body" id="svEventBody"></div>
                <div class="modal-footer"><button type="button" class="jq-btn jq-btn-view" onclick="svPrint()"><i class="bx bx-printer"></i> Print / Save as PDF</button><button type="button" class="jq-btn jq-btn-cancel" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>

    <!-- Password -->
    <div class="modal fade" id="svPasswordModal" tabindex="-1" aria-labelledby="svPasswordTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" data-password-form="1" autocomplete="off">
                <?= jq_csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="modal-header"><h5 class="modal-title" id="svPasswordTitle">Change Password</h5><button type="button" class="jq-modal-close" data-bs-dismiss="modal" aria-label="Close"><i class="bx bx-x"></i></button></div>
                <div class="modal-body">
                    <div class="jq-field" style="margin-bottom:12px;"><label class="jq-label" for="svCurrent">Current password</label><input class="jq-input" type="password" id="svCurrent" name="current_password" required autocomplete="current-password"></div>
                    <div class="jq-field" style="margin-bottom:12px;"><label class="jq-label" for="svNew">New password</label><input class="jq-input" type="password" id="svNew" name="new_password" required minlength="<?= JQ_PASSWORD_MIN_LENGTH ?>" maxlength="72" autocomplete="new-password"><p class="jq-help">At least <?= JQ_PASSWORD_MIN_LENGTH ?> characters.</p></div>
                    <div class="jq-field"><label class="jq-label" for="svConfirm">Confirm new password</label><input class="jq-input" type="password" id="svConfirm" name="confirm_password" required autocomplete="new-password"></div>
                </div>
                <div class="modal-footer"><button type="button" class="jq-btn jq-btn-cancel" data-bs-dismiss="modal">Cancel</button><button type="submit" class="jq-btn jq-btn-primary">Change Password</button></div>
            </form>
        </div>
    </div>
</div>
<script src="static/js/account-validation.js"></script>
<script>
const SV_EVENTS = <?= json_encode(array_column($events, null, 'booking_id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
let svCurrent = null;
function svOpen(id) {
    const e = SV_EVENTS[id];
    if (!e) return;
    svCurrent = e;
    const kv = rows => '<dl class="jq-kv">' + rows.filter(r => r[1]).map(r => '<div><dt>' + escapeHtml(r[0]) + '</dt><dd>' + escapeHtml(r[1]) + '</dd></div>').join('') + '</dl>';
    const list = items => items.length ? '<ul class="sv-list">' + items.map(t => '<li>' + escapeHtml(t) + '</li>').join('') + '</ul>' : '<p class="sv-more">None</p>';
    const groups = Object.keys(e.inclusions || {}).map(k => '<div class="sv-group">' + escapeHtml(k) + '</div>' + list(e.inclusions[k])).join('') || '<p class="sv-more">No inclusions are recorded.</p>';
    document.getElementById('svEventTitle').textContent = e.booking_ref + ': ' + e.event_name;
    document.getElementById('svEventBody').innerHTML =
        '<div class="jq-section-title">Event</div>' + kv([['Client', e.fullname], ['Contact', e.contact], ['Event type', e.event_type], ['Date', new Date(e.event_date + 'T00:00:00').toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })], ['Time', e.event_time ? new Date('2000-01-01T' + e.event_time).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }) : ''], ['Guests', Number(e.headcount).toLocaleString('en-PH')], ['Venue', e.venue_address], ['Location', e.place], ['Booking status', e.status], ['Preparation status', e.preparation_status], ['Payment status', e.payment_status]])
        + '<p class="sv-more" style="margin:4px 0 0;">Payments are recorded and verified by the Admin or Owner. This view is read only.</p>'
        + '<div class="jq-section-title">Backdrop and Setup</div>' + kv([['Backdrop', e.backdrop_name || 'Not recorded'], ['Theme', e.event_theme]]) + (e.backdrop_path ? '<img class="sv-backdrop" src="' + escapeHtml(e.backdrop_path) + '" alt="' + escapeHtml(e.backdrop_name || 'Backdrop') + '">' : '')
        + '<div class="jq-section-title">Catering Package and Inclusions</div>' + kv([['Package', e.package_name]]) + groups
        + '<div class="jq-section-title">Extra Food Items and Bundles</div>' + list(e.foods)
        + '<div class="jq-section-title">Add-ons and Services</div>' + list(e.addons)
        + '<div class="jq-section-title">Notes</div><p style="white-space:pre-wrap;margin:0 0 6px;"><strong>Client requests:</strong> ' + escapeHtml(e.notes || 'None') + '</p><p style="white-space:pre-wrap;margin:0;"><strong>Preparation notes:</strong> ' + escapeHtml(e.preparation_notes || 'None') + '</p>';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('svEventModal')).show();
}
// Prints what this window shows, which holds no prices. The dialog also offers Save as PDF.
function svPrint() {
    if (!svCurrent) return;
    const css = '*{box-sizing:border-box}html,body{margin:0;background:#fff}body{font-family:Arial,Helvetica,sans-serif;color:#1A1A2E;font-size:12.5px;line-height:1.45;padding:0 48px}'
        + 'header{display:flex;align-items:center;gap:14px;border-bottom:3px solid #7B5FC7;padding-bottom:14px;margin-bottom:6px}header img{width:62px;height:62px;border-radius:10px;object-fit:cover}.co{font-size:18px;font-weight:700}.sub{color:#5E5A78;font-size:12px}.meta{margin-left:auto;font-weight:700;font-size:13px}'
        + '.jq-section-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6A4FB5;margin:16px 0 6px;padding-bottom:4px;border-bottom:1px solid #E2D6F0;page-break-after:avoid}'
        + '.jq-kv{margin:0}.jq-kv div{display:flex;padding:3px 0;page-break-inside:avoid}.jq-kv dt{width:32%;font-weight:600;color:#5E5A78}.jq-kv dd{margin:0;flex:1;font-weight:500}.sv-backdrop{display:block;max-width:100%;max-height:240px;border-radius:8px;margin:10px 0 4px}'
        + '.sv-group{font-weight:700;margin-top:8px}.sv-list{margin:2px 0 6px;padding-left:18px}.sv-more{color:#5E5A78}p{margin:0}@page{size:A4;margin:12mm 0}@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}';
    const f = document.createElement('iframe');
    f.setAttribute('aria-hidden', 'true');
    f.style.cssText = 'position:fixed;left:-10000px;top:0;width:794px;height:1123px;border:0;visibility:hidden;';
    f.onload = () => {
        const waiting = [...f.contentDocument.images].filter(img => !img.complete)
            .map(img => new Promise(done => { img.addEventListener('load', done); img.addEventListener('error', done); }));
        Promise.race([Promise.all(waiting), new Promise(done => setTimeout(done, 8000))]).then(() => {
            f.contentWindow.focus();
            f.contentWindow.print();
            setTimeout(() => f.remove(), 60000);
        });
    };
    f.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escapeHtml('Booking ' + svCurrent.booking_ref) + '</title><style>' + css + '</style></head><body>'
        + '<header><img src="static/images/logo.png" alt=""><div><div class="co">Janet\'s Quality Catering Services</div><div class="sub">Event Booking Details</div></div><div class="meta">' + escapeHtml(svCurrent.booking_ref) + '</div></header>'
        + document.getElementById('svEventBody').innerHTML + '</body></html>';
    document.body.appendChild(f);
}
(function () {
    const search = document.getElementById('svSearch'), prep = document.getElementById('svPrep');
    const rows = [...document.querySelectorAll('#svTable tbody tr[data-prep]')], none = document.getElementById('svNone');
    function apply() {
        const q = search.value.trim().toLowerCase(); let shown = 0;
        rows.forEach(tr => {
            const p = prep.value === '__open' ? tr.dataset.prep !== 'Completed' : (!prep.value || tr.dataset.prep === prep.value);
            const ok = p && (!q || tr.dataset.search.includes(q));
            tr.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        if (none) none.style.display = shown ? 'none' : '';
    }
    search.addEventListener('input', apply);
    prep.addEventListener('change', apply);
    apply();
})();
</script>
<?php include 'includes/footer.php'; ?>
