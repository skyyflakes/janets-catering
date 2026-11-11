<?php
/**
 * Client Records - Janet's Quality Catering System
 * One record per client, with every booking connected to it. A client is saved automatically when an
 * event is marked Completed. ADMIN and OWNER can search, view history and correct client details.
 */
$page_title = "Client Records | Janet's Quality Catering";
$current_page = 'clients';

require_once 'includes/auth_check.php';
require_once 'includes/booking_manager.php';
require_once 'includes/client_forecast.php';

$pdo = getDBConnection();
$viewer_role = jq_require_roles($pdo, ['ADMIN', 'OWNER'], 'Client Records');
$can_hide_money = $viewer_role === 'OWNER';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['client_id'] ?? 0);
    $name = jq_clean_name($_POST['fullname'] ?? '');
    $email = trim((string)($_POST['email'] ?? ''));
    $contact = jq_normalize_phone($_POST['contact'] ?? '');
    $postal = trim((string)($_POST['postal_code'] ?? ''));
    $problem = jq_name_error($name, 'Client name', true, 255);
    if ($problem === null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $problem = 'Enter a valid email address.';
    }
    if ($problem === null && $contact === false) {
        $problem = JQ_PHONE_MESSAGE;
    }
    if ($problem === null && $postal !== '') {
        $problem = jq_postal_error($postal);
    }
    if ($problem !== null) {
        setFlash($problem, 'danger');
    } else {
        try {
            $st = $pdo->prepare("UPDATE clients SET fullname = ?, email = ?, contact = ?, postal_code = ?, address = ?, notes = ? WHERE client_id = ?");
            $st->execute([$name, $email ?: null, $contact, $postal ?: null, mb_substr(trim((string)($_POST['address'] ?? '')), 0, 500) ?: null, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 2000) ?: null, $id]);
            setFlash('Client details updated. Past bookings keep the details they were made with.', 'success');
        } catch (PDOException $e) {
            error_log('clients.php: ' . $e->getMessage());
            setFlash(jq_is_duplicate($e) ? 'Another client already has this name and contact number.' : 'The client could not be saved. Nothing was changed.', 'danger');
        }
    }
    redirect('clients.php?id=' . $id);
}

$client_id = (int)($_GET['id'] ?? 0);
$client = null;
$history = [];
if ($client_id > 0) {
    $st = $pdo->prepare("SELECT * FROM clients WHERE client_id = ?");
    $st->execute([$client_id]);
    $client = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($client) {
        $st = $pdo->prepare("SELECT booking_id, booking_ref, event_name, event_type, event_date, headcount, grand_total, status, is_archived, package_name FROM event_bookings WHERE client_id = ? ORDER BY event_date DESC, booking_id DESC");
        $st->execute([$client_id]);
        $history = $st->fetchAll(PDO::FETCH_ASSOC);
        try {
            $forecast = jq_cf_for_client($pdo, $client_id);
        } catch (Throwable $e) {
            error_log('client forecast: ' . $e->getMessage());
            $forecast = null;
        }
    } else {
        setFlash('This client could not be found.', 'warning');
        redirect('clients.php');
    }
}
$clients = $pdo->query("SELECT c.*, COUNT(b.booking_id) AS booking_count, MAX(b.event_date) AS last_event
                          FROM clients c LEFT JOIN event_bookings b ON b.client_id = c.client_id
                         GROUP BY c.client_id ORDER BY c.fullname")->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>
<div id="jqPage">
    <div class="jq-page-header">
        <div>
            <h1 class="jq-title">Client Records</h1>
            <p class="jq-subtitle">Clients are saved when an event is completed. Returning clients keep one record with their full booking history.</p>
        </div>
        <?php if ($client): ?>
        <div class="jq-header-actions"><a class="jq-btn jq-btn-view" href="clients.php"><i class="bx bx-arrow-back"></i> All Clients</a></div>
        <?php endif; ?>
    </div>

    <?php if ($client): ?>
    <div class="jq-card" style="margin-bottom:20px;">
        <div class="jq-card-body">
            <div class="jq-section-title"><?= jq_e($client['client_code']) ?> details</div>
            <form method="POST">
                <?= jq_csrf_field() ?>
                <input type="hidden" name="client_id" value="<?= (int)$client['client_id'] ?>">
                <div class="jq-form-grid">
                    <div class="jq-field"><label class="jq-label" for="cName">Full name <span class="jq-req">*</span></label><input class="jq-input" id="cName" name="fullname" <?= jq_field_attrs('name') ?> maxlength="255" required value="<?= jq_e($client['fullname']) ?>"></div>
                    <div class="jq-field"><label class="jq-label" for="cEmail">Email</label><input class="jq-input" type="email" id="cEmail" name="email" maxlength="100" value="<?= jq_e($client['email']) ?>"></div>
                    <div class="jq-field"><label class="jq-label" for="cContact">Contact number</label><input class="jq-input" type="tel" id="cContact" name="contact" <?= jq_field_attrs('ph-mobile') ?> value="<?= jq_e($client['contact']) ?>"></div>
                    <div class="jq-field"><label class="jq-label" for="cPostal">Postal code</label><input class="jq-input" id="cPostal" name="postal_code" data-rule="postal" value="<?= jq_e($client['postal_code']) ?>"></div>
                    <div class="jq-field jq-span-all"><label class="jq-label" for="cAddress">Address</label><input class="jq-input" id="cAddress" name="address" maxlength="500" value="<?= jq_e($client['address']) ?>"></div>
                    <div class="jq-field jq-span-all"><label class="jq-label" for="cNotes">Notes</label><textarea class="jq-textarea" id="cNotes" name="notes" maxlength="2000"><?= jq_e($client['notes']) ?></textarea></div>
                </div>
                <button type="submit" class="jq-btn jq-btn-primary" style="margin-top:14px;"><i class="bx bx-save"></i> Save Client</button>
            </form>
        </div>
    </div>
    <?php if (!empty($forecast)): $fc = $forecast;
        $fcLabels = ['arima' => 'ARIMA forecast', 'aggregate' => 'Aggregate estimate (other clients)', 'none' => 'No estimate yet']; ?>
    <div class="jq-card" style="margin-bottom:20px;">
        <div class="jq-card-body">
            <div class="jq-section-title">Next expected booking</div>
            <div class="jq-form-grid">
                <div class="jq-field"><span class="jq-label">History status</span><strong><?= jq_e($fc['status']) ?></strong> <span class="jq-help">(<?= (int)$fc['count'] ?> completed event<?= $fc['count'] === 1 ? '' : 's' ?>)</span></div>
                <div class="jq-field"><span class="jq-label">Average gap between events</span><strong><?= $fc['avg_gap'] === null ? '—' : number_format(jq_cf_months($fc['avg_gap']), 1) . ' months' ?></strong></div>
                <div class="jq-field"><span class="jq-label">Expected next booking</span><strong><?= $fc['predicted'] ? jq_e(date('F Y', strtotime($fc['predicted']))) : '—' ?></strong><?php if ($fc['predicted'] && $fc['predicted'] < jq_cf_today()): ?> <span class="jq-help">(due now)</span><?php endif; ?></div>
                <div class="jq-field"><span class="jq-label">Forecast period</span><strong><?= $fc['predicted'] ? jq_e(date('M j, Y', strtotime($fc['low'])) . ' – ' . date('M j, Y', strtotime($fc['high']))) : '—' ?></strong></div>
                <div class="jq-field"><span class="jq-label">Estimate type</span><strong><?= jq_e($fcLabels[$fc['method']]) ?><?= $fc['model'] ? ' · ' . jq_e($fc['model']) : '' ?></strong></div>
                <div class="jq-field"><span class="jq-label">Recurring occasion</span><strong><?= $fc['recurring_month'] ? 'Every ' . jq_e($fc['recurring_month']) : '—' ?></strong></div>
            </div>
            <p class="jq-help" style="margin-top:12px;"><?= jq_e($fc['explanation']) ?> This is an estimate, not a guaranteed booking date. <a href="predictive_analytics.php?view=clients">All client forecasts</a></p>
        </div>
    </div>
    <?php endif; ?>
    <div class="jq-card">
        <div class="jq-card-body">
            <div class="jq-section-title">Booking history (<?= count($history) ?>)</div>            <?php if (!$history): ?>
            <p class="jq-help">No bookings are connected to this client yet.</p>
            <?php else: ?>
            <div class="jq-table-wrap"><table class="jq-table">
                <thead><tr><th>Booking Ref</th><th>Event</th><th>Event Type</th><th>Event Date</th><th>Guests</th><th>Package</th><th>Total
                    <?php if ($can_hide_money): ?><button type="button" class="jq-btn jq-btn-view" style="padding:2px 6px;" onclick="toggleClientMoney(this)" aria-label="Show or hide amounts" title="Show or hide amounts"><i class="bx bx-show"></i></button><?php endif; ?>
                </th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($history as $h): $amount = '₱' . number_format((float)$h['grand_total'], 2); ?>
                    <tr>
                        <td><strong><?= jq_e($h['booking_ref']) ?></strong></td>
                        <td><?= jq_e($h['event_name']) ?></td>
                        <td><?= jq_e($h['event_type']) ?></td>
                        <td style="white-space:nowrap;"><?= date('M d, Y', strtotime($h['event_date'])) ?></td>
                        <td><?= number_format((int)$h['headcount']) ?></td>
                        <td><?= jq_e($h['package_name']) ?></td>
                        <td style="white-space:nowrap;"><?php if ($can_hide_money): ?><span class="client-money" data-amount="<?= jq_e($amount) ?>">₱ ******</span><?php else: ?><?= jq_e($amount) ?><?php endif; ?></td>
                        <td><?= jq_e($h['status']) ?><?= (int)$h['is_archived'] === 1 ? ' (archived)' : '' ?></td>
                        <td><a class="jq-btn jq-btn-view" href="event_bookings.php?<?= (int)$h['is_archived'] === 1 ? 'view=archived&amp;' : '' ?>open=<?= (int)$h['booking_id'] ?>">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="jq-card">
        <div class="jq-card-body" style="border-bottom:1px solid var(--jq-border);">
            <div class="jq-search" style="max-width:420px;"><i class="bx bx-search"></i><input class="jq-input" type="search" id="clientFilter" placeholder="Name, code, number or email" aria-label="Search clients"></div>
        </div>
        <?php if (!$clients): ?>
        <div class="jq-card-body" style="text-align:center;padding:50px 20px;">
            <i class="bx bx-id-card" style="font-size:3rem;opacity:.5;"></i>
            <p style="margin-top:10px;">No clients yet. A client is saved here when an event is marked Completed in Event Bookings.</p>
        </div>
        <?php else: ?>
        <div class="jq-table-wrap"><table class="jq-table" id="clientTable">
            <thead><tr><th>Client Code</th><th>Name</th><th>Contact</th><th>Email</th><th>Bookings</th><th>Latest Event</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($clients as $c): ?>
                <tr data-search="<?= jq_e(mb_strtolower($c['client_code'] . ' ' . $c['fullname'] . ' ' . $c['contact'] . ' ' . $c['email'])) ?>">
                    <td><strong><?= jq_e($c['client_code']) ?></strong></td>
                    <td><?= jq_e($c['fullname']) ?></td>
                    <td style="white-space:nowrap;"><?= jq_e(jq_phone_display($c['contact'])) ?></td>
                    <td><?= jq_e($c['email']) ?></td>
                    <td><?= (int)$c['booking_count'] ?></td>
                    <td style="white-space:nowrap;"><?= $c['last_event'] ? date('M d, Y', strtotime($c['last_event'])) : '—' ?></td>
                    <td><a class="jq-btn jq-btn-view" href="clients.php?id=<?= (int)$c['client_id'] ?>">Open</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<script>
function toggleClientMoney(btn) {
    const show = !btn.classList.contains('revealed');
    btn.classList.toggle('revealed', show);
    document.querySelectorAll('.client-money').forEach(el => { el.textContent = show ? el.dataset.amount : '₱ ******'; });
}
(function () {
    const box = document.getElementById('clientFilter');
    if (!box) return;
    box.addEventListener('input', () => {
        const q = box.value.trim().toLowerCase();
        document.querySelectorAll('#clientTable tbody tr').forEach(tr => { tr.style.display = tr.dataset.search.includes(q) ? '' : 'none'; });
    });
})();
</script>
<?php include 'includes/footer.php'; ?>
