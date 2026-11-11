<?php
/**
 * Predictive Analytics - Janet's Quality Catering System
 * ADMIN and OWNER: ARIMA forecasts of bookings, revenue and guests, seasonal demand,
 * expected demand by event type and package, and model accuracy (Business Forecast view), and each client's
 * expected next booking month (Client Forecasts view, ?view=clients). Read-only.
 * The calculations are in includes/analytics_data.php, includes/client_forecast.php and includes/arima.php.
 */

$page_title = "Predictive Analytics | Janet's Quality Catering";
$current_page = 'predictive_analytics';

require_once 'includes/auth_check.php';
require_once 'includes/analytics_data.php';
require_once 'includes/client_forecast.php';

/** @var array{id: ?int, username: string, role: string, profile_photo: ?string, first_name: string, last_name: string} $current_user */

$pdo = getDBConnection();
if (!$pdo) {
    setFlash('The database is not available. Predictive analytics cannot be displayed.', 'danger');
    redirect('dashboard.php');
}
jq_require_roles($pdo, ['ADMIN', 'OWNER'], 'Predictive Analytics');

$horizon = (int)($_GET['months'] ?? 3) === 6 ? 6 : 3;
$view = ($_GET['view'] ?? '') === 'clients' ? 'clients' : 'business';
try {
    if ($view === 'clients') {
        $cf = jq_cf_overview($pdo, $horizon);
    } else {
        $an = jq_analytics_data($pdo, $horizon);
    }
} catch (Throwable $e) {
    error_log('Predictive analytics error: ' . $e->getMessage());
    setFlash('The forecast could not be calculated. Please try again.', 'danger');
    redirect('dashboard.php');
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$peso = static fn($v) => '₱' . number_format((float)$v, 0);
$num1 = static fn($v) => number_format((float)$v, 1);
$num0 = static fn($v) => number_format((float)$v, 0);
if ($view === 'business'):
$historyMonths = count($an['history']['months']);
$fcB = $an['forecast']['bookings']; $fcR = $an['forecast']['revenue'];
$bt = $an['backtest'];

// Chart data: the last 12 months of history, then the forecast months
$show = min(12, $historyMonths);
$hist = static fn(array $a) => array_slice($a, -$show);
$labels = array_merge(array_map(static fn($ym) => date('M Y', strtotime("$ym-01")), $hist($an['history']['months'])),
                      array_map(static fn($ym) => date('M Y', strtotime("$ym-01")), $an['future']));
$series = [];
foreach (['bookings' => 1, 'revenue' => 0, 'guests' => 0] as $key => $dp) {
    $act = $hist($an['history'][$key]);
    $f = $an['forecast'][$key];
    $pad = $show ? array_fill(0, $show - 1, null) : [];
    $last = $show ? [end($act)] : [];
    $r = static fn(array $a) => array_map(static fn($v) => round($v, $dp), $a);
    $series[$key] = [
        'actual' => array_merge($act, array_fill(0, $horizon, null)),
        'forecast' => array_merge($pad, $last, $r($f['forecast'])),
        'lower' => array_merge($pad, $last, $r($f['lower'])),
        'upper' => array_merge($pad, $last, $r($f['upper'])),
        'model' => $f['label'],
    ];
}
endif;

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <h4 class="mb-1" style="color:var(--heading-color);font-weight:600;">Predictive Analytics</h4>
        <p class="mb-0 text-muted">ARIMA forecasts from your booking history, to plan manpower, food preparation and resources.</p>
    </div>
    <form method="get" class="d-flex align-items-center gap-2" aria-label="Forecast period">
        <label for="months" class="text-muted mb-0" style="font-size:.875rem;white-space:nowrap;">Forecast period</label>
        <select id="months" name="months" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
            <option value="3" <?= $horizon === 3 ? 'selected' : '' ?>>Next 3 months</option>
            <option value="6" <?= $horizon === 6 ? 'selected' : '' ?>>Next 6 months</option>
        </select>
        <input type="hidden" name="view" value="<?= $view ?>">
        <noscript><button class="btn btn-sm btn-primary">Show</button></noscript>
    </form>
</div>

<div class="btn-group mb-4" role="group" aria-label="Forecast type">
    <a href="predictive_analytics.php?months=<?= $horizon ?>" class="btn <?= $view === 'business' ? 'btn-primary active' : 'btn-label-primary' ?>" <?= $view === 'business' ? 'aria-current="page"' : '' ?>><i class="bx bx-line-chart"></i> Business Forecast</a>
    <a href="predictive_analytics.php?view=clients&amp;months=<?= $horizon ?>" class="btn <?= $view === 'clients' ? 'btn-primary active' : 'btn-label-primary' ?>" <?= $view === 'clients' ? 'aria-current="page"' : '' ?>><i class="bx bx-user-check"></i> Client Forecasts</a>
</div>

<?php if ($view === 'clients'): require 'includes/_client_forecast_view.php'; else: ?>


<div class="row mb-2">
    <?php
    $cards = [
        ['Forecast bookings', $num1($an['totals']['bookings']), 'next ' . $horizon . ' months · ' . $fcB['label'], 'bx-calendar-event', 'var(--bs-primary)', 'rgba(147,112,219,.15)'],
        ['Forecast revenue', $peso($an['totals']['revenue']), 'next ' . $horizon . ' months · ' . $fcR['label'], 'bx-trending-up', 'var(--bs-success)', 'rgba(113,221,55,.15)'],
        ['Forecast guests', $num0($an['totals']['guests']), 'about ' . $num0($an['avg_guests']) . ' guests per booking so far', 'bx-group', 'var(--bs-info)', 'rgba(3,195,236,.15)'],
        ['Already booked', (string)$an['totals']['already_booked'], 'Pending or Approved in the forecast period', 'bx-check-square', 'var(--bs-warning)', 'rgba(255,171,0,.15)'],
    ];
    foreach ($cards as [$t, $v, $sub, $icon, $col, $bg]): ?>
    <div class="col-lg-3 col-md-6 col-sm-6 mb-4">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="d-block text-muted mb-1" style="font-size:.8125rem;"><?= $h($t) ?></span>
                    <h3 class="mb-2" style="color:<?= $col ?>;"><?= $h($v) ?></h3>
                    <small class="text-muted"><?= $h($sub) ?></small>
                </div>
                <div style="width:48px;height:48px;background:<?= $bg ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;flex:none;">
                    <i class="bx <?= $icon ?>" style="font-size:1.5rem;color:<?= $col ?>;"></i>
                </div>
            </div>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="card-title mb-0">Forecast <span style="font-size:.72rem;font-weight:500;color:var(--bs-primary);background:rgba(147,112,219,.12);border-radius:20px;padding:2px 10px;margin-left:6px;vertical-align:middle;" id="paModel"><?= $h($fcB['label']) ?></span></h5>
                <p class="mb-0 mt-1" style="font-size:.78rem;opacity:.75;">Actual months, then the ARIMA forecast. The shaded band is the 95% prediction interval.</p>
            </div>
            <div class="btn-group btn-group-sm" role="group" aria-label="Series">
                <button type="button" class="btn btn-primary active" data-series="bookings">Bookings</button>
                <button type="button" class="btn btn-label-primary" data-series="revenue">Revenue</button>
                <button type="button" class="btn btn-label-primary" data-series="guests">Guests</button>
            </div>
        </div>
    </div>
    <div class="card-body pt-2"><div style="position:relative;height:300px;"><canvas id="paChart" aria-label="Forecast chart" role="img"></canvas></div></div>
</div>

<div class="card mb-4">
    <div class="card-header"><h5 class="card-title mb-0">Month-by-month forecast</h5></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Month</th><th class="text-end">Bookings</th><th class="text-end">Already booked</th><th class="text-end">Revenue</th><th class="text-end">Guests</th></tr></thead>
            <tbody>
            <?php foreach ($an['table'] as $r): ?>
                <tr>
                    <td><strong><?= $h($r['label']) ?></strong></td>
                    <td class="text-end"><?= $num1($r['bookings']) ?><br><small class="text-muted"><?= $num1($r['bookings_low']) ?> – <?= $num1($r['bookings_high']) ?></small></td>
                    <td class="text-end"><?= (int)$r['already_booked'] ?><br><small class="text-muted"><?= $num0($r['already_guests']) ?> guests</small></td>
                    <td class="text-end"><?= $peso($r['revenue']) ?><br><small class="text-muted"><?= $peso($r['revenue_low']) ?> – <?= $peso($r['revenue_high']) ?></small></td>
                    <td class="text-end"><?= $num0($r['guests']) ?><br><small class="text-muted"><?= $num0($r['guests_low']) ?> – <?= $num0($r['guests_high']) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body pt-3 pb-3"><small class="text-muted">The small figures are the 95% range. "Already booked" counts Pending and Approved bookings that are on the calendar for that month.</small></div>
</div>

<div class="row">
    <div class="col-lg-7 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="card-title mb-0">Seasonal demand</h5>
                <p class="mb-0 mt-1" style="font-size:.78rem;opacity:.75;">Average bookings in each calendar month, over all years recorded.</p>
            </div>
            <div class="card-body">
                <div style="position:relative;height:230px;"><canvas id="paSeason" aria-label="Seasonal demand chart" role="img"></canvas></div>
                <p class="mb-0 mt-3" style="font-size:.875rem;">
                <?php if ($an['peaks']): ?>
                    Busiest months: <strong><?= $h(implode(', ', array_map(static fn($p) => $p['month'] . ' (' . number_format($p['avg'], 1) . ')', $an['peaks']))) ?></strong>.
                    Plan extra waiters, food attendants and supplies for these months.
                <?php else: ?>
                    No bookings recorded yet.
                <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
    <div class="col-lg-5 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="card-title mb-0">Model accuracy</h5>
                <p class="mb-0 mt-1" style="font-size:.78rem;opacity:.75;">The bookings model is refitted without the last 3 months and asked to predict them.</p>
            </div>
            <div class="card-body">
            <?php if ($bt): ?>
                <div class="d-flex gap-4 mb-3">
                    <div><span class="d-block text-muted" style="font-size:.8125rem;">Mean absolute error</span><h4 class="mb-0"><?= $num1($bt['mae']) ?> <small class="text-muted" style="font-size:.8rem;">bookings</small></h4></div>
                    <div><span class="d-block text-muted" style="font-size:.8125rem;">Mean absolute % error</span><h4 class="mb-0"><?= $bt['mape'] === null ? '—' : $num1($bt['mape']) . '%' ?></h4></div>
                </div>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Month</th><th class="text-end">Actual</th><th class="text-end">Predicted</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($an['history']['months'], -3) as $i => $ym): ?>
                        <tr><td><?= $h(date('M Y', strtotime("$ym-01"))) ?></td><td class="text-end"><?= $num0($bt['actual'][$i]) ?></td><td class="text-end"><?= $num1($bt['forecast'][$i]) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-muted mb-0">At least 9 months of booking history are needed to measure accuracy. There <?= $historyMonths === 1 ? 'is' : 'are' ?> <?= $historyMonths ?> now.</p>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <?php foreach ([['Expected demand by event type', $an['by_type'], 'Event type'], ['Expected demand by package', $an['by_package'], 'Package']] as [$title, $rows, $col]): ?>
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="card-title mb-0"><?= $h($title) ?></h5>
                <p class="mb-0 mt-1" style="font-size:.78rem;opacity:.75;">Forecast bookings for the next <?= $horizon ?> months, divided by each one's share of the last 12 months.</p>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th><?= $h($col) ?></th><th class="text-end">Share</th><th class="text-end">Expected bookings</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">No bookings in the last 12 months.</td></tr>
                    <?php endif; ?>
                    <?php foreach (array_slice($rows, 0, 8) as $r): ?>
                        <tr><td><?= $h($r['name']) ?></td><td class="text-end"><?= number_format($r['share'] * 100, 0) ?>%</td><td class="text-end"><?= $num1($r['expected']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
(function () {
    const labels = <?= json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    const S = <?= json_encode($series, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const season = <?= json_encode($an['seasonal'], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const COLORS = { bookings: '#9370DB', revenue: '#3fa51f', guests: '#03a9d0' };
    const NAMES = { bookings: 'bookings', revenue: 'revenue', guests: 'guests' };
    const fmt = (key, v) => key === 'revenue' ? '₱' + Math.round(v).toLocaleString() : (key === 'bookings' ? v.toFixed(1) : Math.round(v).toLocaleString());
    const dark = () => document.documentElement.getAttribute('data-theme') === 'dark';
    let key = 'bookings', chart;

    function datasets(k) {
        const c = COLORS[k], s = S[k];
        return [
            { label: '95% upper', data: s.upper, borderColor: 'transparent', backgroundColor: 'rgba(245,158,11,.16)', fill: '+1', pointRadius: 0, tension: .3 },
            { label: '95% lower', data: s.lower, borderColor: 'transparent', backgroundColor: 'transparent', fill: false, pointRadius: 0, tension: .3 },
            { label: 'Actual', data: s.actual, borderColor: c, backgroundColor: c, borderWidth: 3, pointRadius: 4, tension: .3, fill: false },
            { label: 'Forecast', data: s.forecast, borderColor: '#f59e0b', backgroundColor: '#f59e0b', borderWidth: 2.5, borderDash: [6, 4], pointRadius: 4, tension: .3, fill: false },
        ];
    }
    function draw() {
        const grid = dark() ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.05)', tick = dark() ? '#a89cc8' : '#8592a3';
        chart = new Chart(document.getElementById('paChart'), {
            type: 'line', data: { labels, datasets: datasets(key) },
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false }, tooltip: { filter: i => i.parsed.y !== null && !i.dataset.label.startsWith('95%') || (i.dataset.label === '95% upper' && i.parsed.y !== null && S[key].actual[i.dataIndex] === null),
                    callbacks: { label: c => c.dataset.label === '95% upper' ? '95% range: ' + fmt(key, S[key].lower[c.dataIndex]) + ' – ' + fmt(key, c.parsed.y) : c.dataset.label + ': ' + fmt(key, c.parsed.y) } } },
                scales: { x: { grid: { display: false }, ticks: { color: tick, maxRotation: 45, font: { size: 11 } } },
                          y: { beginAtZero: true, grid: { color: grid }, ticks: { color: tick, font: { size: 11 }, callback: v => key === 'revenue' ? '₱' + Number(v).toLocaleString() : v } } } }
        });
        new Chart(document.getElementById('paSeason'), {
            type: 'bar', data: { labels: season.map(s => s.month), datasets: [{ label: 'Average bookings', data: season.map(s => Math.round(s.avg * 10) / 10), backgroundColor: season.map(s => s.index >= 1.2 ? '#f59e0b' : 'rgba(147,112,219,.55)'), borderRadius: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { x: { grid: { display: false }, ticks: { color: tick } }, y: { beginAtZero: true, grid: { color: grid }, ticks: { color: tick } } } }
        });
    }
    document.querySelectorAll('[data-series]').forEach(b => b.addEventListener('click', () => {
        key = b.dataset.series;
        document.querySelectorAll('[data-series]').forEach(x => { x.classList.toggle('btn-primary', x === b); x.classList.toggle('active', x === b); x.classList.toggle('btn-label-primary', x !== b); });
        document.getElementById('paModel').textContent = S[key].model;
        chart.data.datasets = datasets(key); chart.update();
    }));
    if (window.Chart) draw(); else window.addEventListener('load', draw);
})();
</script>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
