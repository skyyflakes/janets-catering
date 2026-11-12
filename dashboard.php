<?php

$page_title = "Dashboard | Janet's Quality Catering";
$current_page = 'dashboard';

require_once 'includes/auth_check.php';

/** @var array{id: ?int, username: string, role: string, profile_photo: ?string, first_name: string, last_name: string} $current_user */

$pdo = getDBConnection();

require_once 'includes/dashboard_data.php';

$dash = $pdo ? jq_dashboard_data($pdo) : jq_dashboard_empty();
$active_bookings = $dash['counts']['active'];
$pending_bookings = $dash['counts']['pending'];
$approved_bookings = $dash['counts']['approved'];
$cancelled_bookings = $dash['counts']['cancelled'];
$disabled_bookings = $dash['counts']['disabled'];
$total_packages = $dash['catalog']['packages'];
$total_food_items = $dash['catalog']['items'];
$total_revenue = $dash['revenue']['total'];
$upcoming_revenue = $dash['revenue']['upcoming'];
$completed_bookings = $dash['counts']['completed'];
// The Owner can hide prices. For an Admin they are always visible.
$can_hide_prices = ($current_user['role'] ?? '') === 'OWNER';

require_once 'includes/header.php';
?>

<div class="card mb-4" style="background: linear-gradient(135deg, #9370DB 0%, #8B5CF6 100%);">
    <div class="card-body py-4">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h4 class="mb-2" style="color:#fff; font-weight:600;">
                    Welcome back, <?php echo htmlspecialchars($current_user['first_name'] ?? $current_user['username']); ?>!
                </h4>
                <p class="mb-0" style="color:rgba(255,255,255,.9);">
                    Here's what's happening with your catering business today. You have <strong><span id="dashPendingWelcome"><?php echo $pending_bookings; ?></span> pending bookings</strong> that need attention.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                <a href="event_bookings.php?view=wizard" class="btn btn-primary">
                    <i class="bx bx-plus me-1"></i> New Event Booking
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-3 col-6 mb-4">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="d-block text-muted mb-1" style="font-size:.8125rem;">Active Bookings</span>
                    <h3 class="mb-2" style="color:var(--bs-primary);" id="dashActive"><?php echo $active_bookings; ?></h3>
                    <small style="color:var(--bs-success);"><i class="bx bx-check"></i> <span id="dashApproved"><?php echo $approved_bookings; ?></span> approved</small><br>
                    <small class="text-muted"><span id="dashCancelled"><?php echo $cancelled_bookings; ?></span> cancelled, <span id="dashDisabled"><?php echo $disabled_bookings; ?></span> disabled, <span id="dashCompleted"><?php echo $completed_bookings; ?></span> completed</small>
                </div>
                <div style="width:48px;height:48px;background:rgba(147,112,219,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="bx bx-calendar-check" style="font-size:1.5rem;color:var(--bs-primary);"></i>
                </div>
            </div>
        </div></div>
    </div>

    <div class="col-lg-3 col-6 mb-4">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="d-block text-muted mb-1" style="font-size:.8125rem;">Pending Bookings</span>
                    <h3 class="mb-2" style="color:var(--bs-warning);" id="dashPending"><?php echo $pending_bookings; ?></h3>
                    <small class="text-muted">Awaiting approval</small>
                </div>
                <div style="width:48px;height:48px;background:rgba(255,171,0,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="bx bx-time-five" style="font-size:1.5rem;color:var(--bs-warning);"></i>
                </div>
            </div>
        </div></div>
    </div>

    <div class="col-lg-3 col-6 mb-4">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="d-block text-muted mb-1" style="font-size:.8125rem;">Menu Catalog</span>
                    <h3 class="mb-2" style="color:var(--bs-success);"><?php echo $total_food_items; ?></h3>
                    <small class="text-muted"><?php echo $total_packages; ?> packages</small>
                </div>
                <div style="width:48px;height:48px;background:rgba(113,221,55,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="bx bx-restaurant" style="font-size:1.5rem;color:var(--bs-success);"></i>
                </div>
            </div>
        </div></div>
    </div>

    <!-- CHANGED: Total Revenue card now masked with (*) and has an eye toggle -->
    <div class="col-lg-3 col-6 mb-4">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="d-block text-muted mb-1" style="font-size:.8125rem;">Total Revenue</span>
                    <div class="d-flex align-items-center mb-2">
                        <h3 class="mb-0 jq-price" id="totalRevenueValue"
                            data-real="₱<?php echo number_format($total_revenue, 0); ?>"
                            data-mask="₱••••••"
                            style="color:var(--heading-color);">₱••••••</h3>
                        <?php if ($can_hide_prices): ?>
                        <button type="button" class="revenue-eye-btn jq-price-eye" aria-label="Show prices" title="Show prices">
                            <i class="bx bx-show"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                    <small style="color:var(--bs-primary);">
                        <i class="bx bx-trending-up"></i>
                        <span class="jq-price" id="upcomingRevenueValue"
                              data-real="₱<?php echo number_format($upcoming_revenue, 0); ?>"
                              data-mask="₱••••••">₱••••••</span> upcoming
                    </small>
                </div>
                <div style="width:48px;height:48px;background:rgba(147,112,219,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="bx bx-dollar-circle" style="font-size:1.5rem;color:var(--bs-primary);"></i>
                </div>
            </div>
        </div></div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-8 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="card-title mb-0">Bookings Overview
                            <span style="font-size:.72rem;font-weight:500;color:var(--bs-primary);background:rgba(147,112,219,.12);border-radius:20px;padding:2px 10px;margin-left:8px;vertical-align:middle;">ARIMA Forecast</span>
                        </h5>
                        <p class="mb-0 mt-1" style="font-size:.78rem;color:var(--body-color);opacity:.7;">Past 12 months + 3-month ARIMA forecast · <span id="forecastModel"><?php echo htmlspecialchars($dash['chart']['model']['bookings'] ?? 'ARIMA'); ?></span> · <a href="predictive_analytics.php">View predictive analytics</a></p>
                    </div>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-primary active" id="btnBookings" onclick="updateChart('bookings')">Bookings</button>
                        <button type="button" class="btn btn-label-primary" id="btnRevenue" onclick="updateChart('revenue')">Revenue</button>
                    </div>
                </div>
            </div>
            <div class="card-body pt-2">
                <canvas id="bookingsChart" style="height:240px;max-height:240px;"></canvas>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title mb-0">Quick Actions</h5></div>
            <div class="card-body">
                <a href="event_bookings.php?view=wizard" class="d-flex align-items-center p-3 mb-3" style="background:rgba(147,112,219,.1);border-radius:10px;text-decoration:none;">
                    <div style="width:42px;height:42px;background:linear-gradient(135deg,#9370DB,#8B5CF6);border-radius:10px;display:flex;align-items:center;justify-content:center;margin-right:12px;"><i class="bx bx-plus" style="color:#fff;font-size:1.25rem;"></i></div>
                    <div><strong style="color:var(--heading-color);">New Event Booking</strong><p class="mb-0 text-muted" style="font-size:.8125rem;">Build a catering booking</p></div>
                </a>
                <a href="food_menus.php" class="d-flex align-items-center p-3 mb-3" style="background:rgba(113,221,55,.1);border-radius:10px;text-decoration:none;">
                    <div style="width:42px;height:42px;background:var(--bs-success);border-radius:10px;display:flex;align-items:center;justify-content:center;margin-right:12px;"><i class="bx bx-restaurant" style="color:#fff;font-size:1.25rem;"></i></div>
                    <div><strong style="color:var(--heading-color);">Manage Food Menus</strong><p class="mb-0 text-muted" style="font-size:.8125rem;">Packages, items & bundles</p></div>
                </a>
                <a href="event_bookings.php" class="d-flex align-items-center p-3 mb-3" style="background:rgba(3,195,236,.1);border-radius:10px;text-decoration:none;">
                    <div style="width:42px;height:42px;background:var(--bs-info);border-radius:10px;display:flex;align-items:center;justify-content:center;margin-right:12px;"><i class="bx bx-list-ul" style="color:#fff;font-size:1.25rem;"></i></div>
                    <div><strong style="color:var(--heading-color);">All Bookings</strong><p class="mb-0 text-muted" style="font-size:.8125rem;">View & manage bookings</p></div>
                </a>
                <a href="reports.php" class="d-flex align-items-center p-3" style="background:rgba(255,171,0,.1);border-radius:10px;text-decoration:none;">
                    <div style="width:42px;height:42px;background:var(--bs-warning);border-radius:10px;display:flex;align-items:center;justify-content:center;margin-right:12px;"><i class="bx bx-file" style="color:#fff;font-size:1.25rem;"></i></div>
                    <div><strong style="color:var(--heading-color);">Reports</strong><p class="mb-0 text-muted" style="font-size:.8125rem;">Create and monitor reports</p></div>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h5 class="card-title mb-0">Recent Bookings</h5><small class="text-muted" id="dashUpdated" aria-live="polite">Live from Event Bookings</small></div>
                    <a href="event_bookings.php" class="btn btn-sm btn-label-primary">View All</a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Event</th><th>Customer</th><th>Date</th><th>Pax</th><th class="text-end">Total</th><th>Status</th></tr></thead>
                    <tbody id="recentBookingsBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Popular Packages</h5>
                    <a href="food_menus.php" class="btn btn-sm btn-label-primary">Menus</a>
                </div>
            </div>
            <div class="card-body" id="popularPackagesBody">
            </div>
        </div>
    </div>
</div>

<style>
.status-pill { padding:3px 12px; border-radius:20px; font-size:.72rem; font-weight:700; text-transform:uppercase; }
.status-pending { background:rgba(255,171,0,.16); color:#b8860b; }
.status-approved { background:rgba(113,221,55,.16); color:#3f7a10; }
.status-disabled { background:rgba(133,146,163,.2); color:#566072; }
.status-completed { background:rgba(91,63,168,.14); color:#4A3290; }
.status-cancelled { background:rgba(255,62,29,.14); color:#d9342b; }

#totalRevenueValue { font-size: 1.3rem; white-space: nowrap; }
/* Eye toggle button for dashboard prices */
.revenue-eye-btn {
    background: transparent;
    border: 0;
    padding: 0 0 0 10px;
    line-height: 1;
    cursor: pointer;
    color: var(--bs-secondary);
    transition: color .15s ease;
}
.revenue-eye-btn:hover { color: var(--bs-primary); }
.revenue-eye-btn i { font-size: 1.35rem; vertical-align: middle; }
.revenue-eye-btn:focus { outline: none; }
.revenue-eye-btn:focus-visible { outline: 2px solid var(--bs-primary); outline-offset: 2px; border-radius: 4px; }
</style>

<script>
const DASH_CAN_HIDE = <?php echo $can_hide_prices ? 'true' : 'false'; ?>;
let dashData = <?php echo json_encode($dash, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
const allLabels = dashData.chart.labels;
let predBookings = dashData.chart.pred_bookings;
let predRevenue = dashData.chart.pred_revenue;
let bookingActual = [...dashData.chart.bookings, null, null, null];
let revenueActual = [...dashData.chart.revenue, null, null, null];

let currentChart, currentMode = 'bookings';

// The ARIMA order chosen on the server for the series on display, e.g. ARIMA(1,0,0)
function forecastLabel(mode) {
    const m = (dashData.chart && dashData.chart.model) || {};
    return (mode === 'revenue' ? m.revenue : m.bookings) || 'ARIMA';
}
function showForecastModel() {
    const el = document.getElementById('forecastModel');
    if (el) el.textContent = forecastLabel(currentMode);
}

function buildDatasets(mode) {
    const ctx = document.getElementById('bookingsChart').getContext('2d');
    const mainColor = mode === 'bookings' ? '#9370DB' : '#71dd37';
    const predColor = '#f59e0b';
    const actual = mode === 'bookings' ? bookingActual : revenueActual;
    const pred   = mode === 'bookings' ? predBookings : predRevenue;

    const g = ctx.createLinearGradient(0,0,0,240);
    g.addColorStop(0, mainColor + '35'); g.addColorStop(1, mainColor + '00');

    return [
        { label: mode === 'bookings' ? 'Actual Bookings' : 'Actual Revenue', data: actual, fill:true, backgroundColor:g, borderColor:mainColor, borderWidth:3, tension:.4, pointBackgroundColor:mainColor, pointBorderColor:'#fff', pointBorderWidth:2, pointRadius:5, pointHoverRadius:7, spanGaps:false },
        { label: mode === 'bookings' ? 'Predicted Bookings' : 'Predicted Revenue', data: pred, fill:false, borderColor:predColor, borderWidth:2.5, borderDash:[6,4], tension:.4, pointBackgroundColor:predColor, pointBorderColor:'#fff', pointBorderWidth:2, pointRadius:5, pointHoverRadius:7, spanGaps:false }
    ];
}

function initChart() {
    const ctx = document.getElementById('bookingsChart').getContext('2d');
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridColor = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.05)';
    const tickColor = isDark ? '#a89cc8' : '#8592a3';

    currentChart = new Chart(ctx, {
        type: 'line',
        data: { labels: allLabels, datasets: buildDatasets('bookings') },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode:'index', intersect:false },
            plugins: {
                legend: { display:false },
                tooltip: {
                    callbacks: {
                        label: c => {
                            if (c.parsed.y === null) return null;
                            const isRev = currentMode === 'revenue';
                            const prefix = c.dataset.label.includes('Predicted') ? 'Forecast (' + forecastLabel(currentMode) + '): ' : 'Actual: ';
                            return prefix + (isRev ? (pricesVisible ? '₱' + c.parsed.y.toLocaleString() : '₱••••••') : c.parsed.y + ' bookings');
                        }
                    }
                }
            },
            scales: {
                x: { grid:{display:false}, ticks:{ color:tickColor, maxRotation:45, font:{size:11} } },
                y: { beginAtZero:true, grid:{color:gridColor}, ticks:{ color:tickColor, font:{size:11}, callback: v => (currentMode === 'revenue' && !pricesVisible) ? '₱•••' : v } }
            }
        }
    });
}

// Re-colour the chart axes when the user switches Dark / Light mode (no reload needed)
document.addEventListener('themechange', function (e) {
    if (!currentChart) return;
    const isDark = e.detail && e.detail.theme === 'dark';
    const s = currentChart.options.scales;
    s.x.ticks.color = s.y.ticks.color = isDark ? '#a89cc8' : '#8592a3';
    s.y.grid.color = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.05)';
    currentChart.update('none');
});

function updateChart(type) {
    currentMode = type;
    document.querySelectorAll('#btnBookings, #btnRevenue').forEach(b => { b.classList.remove('active','btn-primary'); b.classList.add('btn-label-primary'); });
    const active = type === 'bookings' ? document.getElementById('btnBookings') : document.getElementById('btnRevenue');
    active.classList.remove('btn-label-primary'); active.classList.add('btn-primary','active');
    currentChart.data.datasets = buildDatasets(type);
    currentChart.update();
    showForecastModel();
}

/* ---------- Price privacy: one eye state for every price on this page ---------- */
let pricesVisible = false; // hidden by default, for viewing the dashboard in public

function applyPriceVisibility() {
    document.querySelectorAll('.jq-price').forEach(function (el) {
        el.textContent = pricesVisible ? el.dataset.real : el.dataset.mask;
    });
    const label = pricesVisible ? 'Hide prices' : 'Show prices';
    document.querySelectorAll('.jq-price-eye').forEach(function (btn) {
        btn.title = label;
        btn.setAttribute('aria-label', label);
        btn.setAttribute('aria-pressed', pricesVisible ? 'true' : 'false');
        // open eye = prices are visible, crossed-out eye = prices are hidden
        btn.querySelector('i').className = 'bx ' + (pricesVisible ? 'bx-show' : 'bx-hide');
    });
    if (currentChart) currentChart.update('none');
}

function initPriceToggle() {
    if (!DASH_CAN_HIDE) { pricesVisible = true; applyPriceVisibility(); return; }
    try { pricesVisible = localStorage.getItem('dashRevenueVisible') === '1'; } catch (e) { pricesVisible = false; }
    applyPriceVisibility();
    // Delegated, because the recent bookings rows are replaced when new data arrives.
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.jq-price-eye')) return;
        pricesVisible = !pricesVisible;
        applyPriceVisibility();
        try { localStorage.setItem('dashRevenueVisible', pricesVisible ? '1' : '0'); } catch (err) {}
    });
}

/* ---------- Live data from Event Bookings ---------- */
const DASH_MASK = '₱••••••';
const dashPeso = (n, digits) => '₱' + Number(n).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });

function renderDashboard(d) {
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('dashActive', d.counts.active);
    set('dashApproved', d.counts.approved);
    set('dashCancelled', d.counts.cancelled);
    set('dashDisabled', d.counts.disabled);
    set('dashCompleted', d.counts.completed);
    set('dashPending', d.counts.pending);
    set('dashPendingWelcome', d.counts.pending);
    document.getElementById('totalRevenueValue').dataset.real = dashPeso(d.revenue.total, 0);
    document.getElementById('upcomingRevenueValue').dataset.real = dashPeso(d.revenue.upcoming, 0);

    const eye = DASH_CAN_HIDE ? '<button type="button" class="revenue-eye-btn jq-price-eye"><i class="bx bx-show"></i></button>' : '';
    document.getElementById('recentBookingsBody').innerHTML = d.recent.length ? d.recent.map(b => `<tr>
        <td><div class="d-flex align-items-center">
            <div style="width:38px;height:38px;background:rgba(147,112,219,.15);border-radius:8px;display:flex;align-items:center;justify-content:center;margin-right:12px;"><i class="bx bx-calendar-check" style="font-size:1rem;color:var(--bs-primary);"></i></div>
            <div><strong style="color:var(--heading-color);">${escapeHtml(b.event_name)}</strong><br><small class="text-muted">${escapeHtml(b.package_name || '')}</small></div>
        </div></td>
        <td>${escapeHtml(b.fullname)}</td>
        <td><small>${escapeHtml(b.event_date_label)}</small></td>
        <td><span class="badge badge-secondary">${Number(b.headcount)}</span></td>
        <td class="text-end" style="white-space:nowrap;"><strong class="jq-price" style="color:var(--bs-primary);" data-real="${dashPeso(b.grand_total, 2)}" data-mask="${DASH_MASK}">${DASH_MASK}</strong>${eye}</td>
        <td><span class="status-pill status-${escapeHtml(String(b.status).toLowerCase())}">${escapeHtml(b.status)}</span></td>
    </tr>`).join('') : '<tr><td colspan="6" class="text-center py-4"><div style="color:var(--bs-secondary);"><i class="bx bx-calendar" style="font-size:2.5rem;"></i><p class="mb-0 mt-2">No bookings found</p></div></td></tr>';

    document.getElementById('popularPackagesBody').innerHTML = d.popular.length ? d.popular.map(p => `<div class="d-flex align-items-center justify-content-between py-2 border-bottom" style="border-color:var(--border-color)!important;">
        <div class="d-flex align-items-center">
            <div style="width:38px;height:38px;background:rgba(147,112,219,.15);border-radius:8px;display:flex;align-items:center;justify-content:center;margin-right:12px;"><i class="bx bx-crown" style="font-size:1rem;color:var(--bs-primary);"></i></div>
            <div><strong style="color:var(--heading-color);font-size:.875rem;">${escapeHtml(p.package_name)}</strong><p class="mb-0 text-muted" style="font-size:.75rem;"><span class="jq-price" data-real="${dashPeso(p.revenue, 0)}" data-mask="${DASH_MASK}">${DASH_MASK}</span> revenue</p></div>
        </div>
        <span class="badge badge-primary">${Number(p.booking_count)} booked</span>
    </div>`).join('') : '<div class="text-center py-4"><div style="color:var(--bs-secondary);"><i class="bx bx-package" style="font-size:2.5rem;"></i><p class="mb-0 mt-2">No booking data yet</p></div></div>';

    bookingActual = [...d.chart.bookings, null, null, null];
    revenueActual = [...d.chart.revenue, null, null, null];
    predBookings = d.chart.pred_bookings;
    predRevenue = d.chart.pred_revenue;
    dashData.chart.model = d.chart.model;
    showForecastModel();
    if (currentChart) {
        currentChart.data.datasets = buildDatasets(currentMode);
    }
    applyPriceVisibility();
}

function refreshDashboard() {
    if (document.hidden) return;
    fetch('ajax/dashboard_bookings.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.ok ? r.json() : Promise.reject(r.status))
        .then(res => {
            if (!res.success) return;
            if (JSON.stringify(res.data) !== JSON.stringify(dashData)) {
                dashData = res.data;
                renderDashboard(dashData);
            }
            document.getElementById('dashUpdated').textContent = 'Live from Event Bookings, updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        })
        .catch(() => { document.getElementById('dashUpdated').textContent = 'Could not refresh. Showing the last data received.'; });
}

document.addEventListener('DOMContentLoaded', function() {
    initChart();
    renderDashboard(dashData);
    initPriceToggle();
    setInterval(refreshDashboard, 15000);
    document.addEventListener('visibilitychange', refreshDashboard);
    <?php if ($flash = getFlash()): ?>
    Swal.fire({ icon:'<?php echo $flash['type']==='success'?'success':($flash['type']==='danger'?'error':$flash['type']); ?>', title:'<?php echo $flash['type']==='success'?'Welcome!':'Notice'; ?>', text:'<?php echo addslashes($flash['message']); ?>', confirmButtonColor:'#9370DB', timer:3000, timerProgressBar:true });
    <?php endif; ?>
});
</script>

<?php include 'includes/footer.php'; ?>