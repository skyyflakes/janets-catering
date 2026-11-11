<?php
/**
 * Client Forecasts view of predictive_analytics.php. Expects $cf (jq_cf_overview), $h and $horizon.
 * Every figure comes from completed bookings in the database; see includes/client_forecast.php.
 */
$T = $cf['totals'];
$P = $cf['patterns'];
$mo = static fn($days) => $days === null ? '—' : number_format(jq_cf_months((float)$days), 1) . ' mo';
$dt = static fn($d) => $d ? date('M j, Y', strtotime($d)) : '—';
$METHOD = [
    'arima' => ['ARIMA forecast', 'badge-primary', 'Individual ARIMA forecast from the client\'s own booking gaps.'],
    'aggregate' => ['Aggregate estimate', 'badge-warning', 'Based on other returning clients, not on this client\'s own pattern.'],
    'none' => ['Waiting for history', 'badge-secondary', 'Not enough data for any estimate yet.'],
];
$badge = static function (string $m) use ($METHOD, $h) {
    [$label, $cls, $tip] = $METHOD[$m];
    return '<span class="badge ' . $cls . '" title="' . $h($tip) . '">' . $h($label) . '</span>';
};
$withHistory = array_values(array_filter($cf['rows'], static fn($r) => $r['count'] > 0));
usort($withHistory, static fn($a, $b) => [$b['count'], $a['predicted'] ?? '9999'] <=> [$a['count'], $b['predicted'] ?? '9999']);
$noHistory = $T['clients'] - $T['with_history'];
?>

<div class="row mb-2">
    <?php
    $cards = [
        ['Registered clients', $T['clients'], $noHistory ? $noHistory . ' without a completed event' : 'all have a completed event', 'bx-id-card', 'var(--bs-primary)', 'rgba(147,112,219,.15)'],
        ['Returning clients', $T['returning'], number_format($P['repeat_rate'] * 100, 0) . '% of clients with history', 'bx-revision', 'var(--bs-success)', 'rgba(113,221,55,.15)'],
        ['Completed events', $T['completed'], 'valid booking history', 'bx-calendar-check', 'var(--bs-info)', 'rgba(3,195,236,.15)'],
        ['Clients with ARIMA forecast', $T['arima'], 'at least ' . JQ_CF_MIN_ARIMA_EVENTS . ' completed events', 'bx-line-chart', 'var(--bs-primary)', 'rgba(147,112,219,.15)'],
        ['Awaiting more history', $T['awaiting'], 'fewer than ' . JQ_CF_MIN_ARIMA_EVENTS . ' completed events', 'bx-hourglass', 'var(--bs-warning)', 'rgba(255,171,0,.15)'],
        ['Expected client bookings', $T['expected'], 'due now or within ' . $horizon . ' months', 'bx-bell', 'var(--bs-danger)', 'rgba(255,62,29,.12)'],
    ];
    foreach ($cards as [$t, $v, $sub, $icon, $col, $bg]): ?>
    <div class="col-xl-4 col-md-6 mb-4">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div style="min-width:0;">
                    <span class="d-block text-muted mb-1" style="font-size:.8125rem;"><?= $h($t) ?></span>
                    <h3 class="mb-2" style="color:<?= $col ?>;"><?= (int)$v ?></h3>
                    <small class="text-muted"><?= $h($sub) ?></small>
                </div>
                <div style="width:40px;height:40px;background:<?= $bg ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;flex:none;">
                    <i class="bx <?= $icon ?>" style="font-size:1.25rem;color:<?= $col ?>;"></i>
                </div>
            </div>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">Completed events and expected client bookings</h5>
        <p class="mb-0 mt-1" style="font-size:.78rem;opacity:.75;">Completed events in the last 12 months, then the number of clients expected to book in each of the next 6 months, by estimate type. Estimates already passed are counted in the current month.</p>
    </div>
    <div class="card-body pt-2"><div style="position:relative;height:280px;"><canvas id="cfChart" role="img" aria-label="Completed events and expected client bookings"></canvas></div></div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">Expected upcoming client bookings</h5>
        <p class="mb-0 mt-1" style="font-size:.78rem;opacity:.75;">Clients whose estimated next booking is due now or falls within the next <?= $horizon ?> months. Good candidates for a follow-up call.</p>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Client</th><th>Expected period</th><th>Range</th><th>Last event</th><th>Estimate type</th></tr></thead>
            <tbody>
            <?php if (!$cf['upcoming']): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No client is expected to book within this period, or there is not enough history for an estimate yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($cf['upcoming'] as $r): $due = $r['predicted'] < $cf['today']; ?>
                <tr>
                    <td><a href="clients.php?id=<?= (int)$r['client_id'] ?>"><strong><?= $h($r['name']) ?></strong></a><br><small class="text-muted"><?= $h($r['code']) ?></small></td>
                    <td><strong><?= $h(date('F Y', strtotime($r['predicted']))) ?></strong><?php if ($due): ?> <span class="badge badge-danger">Due now</span><?php endif; ?></td>
                    <td><small><?= $h($dt($r['low'])) ?> – <?= $h($dt($r['high'])) ?></small></td>
                    <td><?= $h($dt($r['last_event'])) ?></td>
                    <td><?= $badge($r['method']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">Prediction results</h5>
        <p class="mb-0 mt-1" style="font-size:.78rem;opacity:.75;">Every client with at least one completed event. Open a client to see how their estimate was made.</p>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:.875rem;">
            <thead><tr><th>Client</th><th class="text-end">Completed events</th><th>Previous event dates</th><th class="text-end">Average gap</th><th>Next month</th><th>Next year</th><th>Forecast period</th><th>Status</th><th>Accuracy</th></tr></thead>
            <tbody>
            <?php if (!$withHistory): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No completed events yet. A client's history starts when their first event is marked Completed in Event Bookings.</td></tr>
            <?php endif; ?>
            <?php foreach ($withHistory as $r): $a = $r['accuracy']; ?>
                <tr>
                    <td><a href="clients.php?id=<?= (int)$r['client_id'] ?>"><strong><?= $h($r['name']) ?></strong></a><br><small class="text-muted"><?= $h($r['code']) ?></small></td>
                    <td class="text-end"><?= (int)$r['count'] ?></td>
                    <td><small><?= $h(implode(', ', array_map(static fn($e) => date('M j, Y', strtotime($e['event_date'])), array_slice($r['events'], -4)))) ?><?= $r['count'] > 4 ? ' <span class="text-muted">(last 4)</span>' : '' ?></small></td>
                    <td class="text-end"><?= $h($mo($r['avg_gap'])) ?></td>
                    <td><?= $r['predicted'] ? $h(date('F', strtotime($r['predicted']))) : '—' ?></td>
                    <td><?= $r['predicted'] ? $h(date('Y', strtotime($r['predicted']))) : '—' ?></td>
                    <td><small><?= $r['predicted'] ? $h($dt($r['low']) . ' – ' . $dt($r['high'])) : '—' ?></small></td>
                    <td><?= $badge($r['method']) ?><br><small class="text-muted"><?= $h(implode(' · ', array_filter([$r['method'] === 'arima' ? null : $r['status'], $r['model']]))) ?></small></td>
                    <td><small><?php if ($a): ?>MAE <?= number_format($a['mae'], 0) ?> d<br>RMSE <?= number_format($a['rmse'], 0) ?> d<?= $a['mape'] !== null ? '<br>MAPE ' . number_format($a['mape'], 1) . '%' : '' ?><?php else: ?><span class="text-muted">Needs <?= JQ_CF_MIN_ACCURACY_EVENTS ?>+ events</span><?php endif; ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row">
    <div class="col-lg-7 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="card-title mb-0">Months when clients hold events</h5>
                <p class="mb-0 mt-1" style="font-size:.78rem;opacity:.75;">Completed events by calendar month, over all years recorded.</p>
            </div>
            <div class="card-body"><div style="position:relative;height:230px;"><canvas id="cfMonths" role="img" aria-label="Completed events by calendar month"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-5 mb-4">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title mb-0">Repeat booking patterns</h5></div>
            <div class="card-body">
                <div class="d-flex gap-4 mb-3 flex-wrap">
                    <div><span class="d-block text-muted" style="font-size:.8125rem;">Repeat rate</span><h4 class="mb-0"><?= number_format($P['repeat_rate'] * 100, 0) ?>%</h4></div>
                    <div><span class="d-block text-muted" style="font-size:.8125rem;">Average gap</span><h4 class="mb-0"><?= $h($mo($P['avg_gap'])) ?></h4></div>
                    <div><span class="d-block text-muted" style="font-size:.8125rem;">Median gap</span><h4 class="mb-0"><?= $h($mo($P['median_gap'])) ?></h4></div>
                </div>
                <table class="table table-sm mb-3">
                    <thead><tr><th>Completed events per client</th><th class="text-end">Clients</th></tr></thead>
                    <tbody><?php foreach ($P['frequency'] as $k => $v): ?><tr><td><?= $h($k) ?></td><td class="text-end"><?= (int)$v ?></td></tr><?php endforeach; ?></tbody>
                </table>
                <p class="mb-1" style="font-size:.875rem;"><strong>Recurring occasions</strong> (events in the same month in different years):</p>
                <?php if ($P['recurring']): ?>
                    <ul class="mb-0" style="font-size:.875rem;"><?php foreach (array_slice($P['recurring'], 0, 8) as $r): ?><li><a href="clients.php?id=<?= (int)$r['client_id'] ?>"><?= $h($r['name']) ?></a> – every <?= $h($r['recurring_month']) ?></li><?php endforeach; ?></ul>
                <?php else: ?>
                    <p class="text-muted mb-0" style="font-size:.875rem;">None yet. This appears once a client completes events in the same month of two different years.</p>
                <?php endif; ?>
                <p class="text-muted mb-0 mt-2" style="font-size:.78rem;">Based on <?= (int)$P['gaps'] ?> gap<?= $P['gaps'] === 1 ? '' : 's' ?> between completed events of returning clients.</p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const hist = <?= json_encode($cf['chart']['history']) ?>;
    const exp = <?= json_encode($cf['chart']['expected']) ?>;
    const byMonth = <?= json_encode(array_values($P['by_month'])) ?>;
    const lab = ym => new Date(ym + '-01T00:00:00').toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
    const hk = Object.keys(hist), ek = Object.keys(exp);
    const labels = hk.map(lab).concat(ek.filter(k => !hk.includes(k)).map(lab));
    const futureStart = hk.length - (ek[0] && hk.includes(ek[0]) ? 1 : 0);
    const pad = n => Array(n).fill(null);
    const part = m => pad(futureStart).concat(ek.map(k => exp[k][m]));
    function draw() {
        const dark = document.documentElement.getAttribute('data-theme') === 'dark';
        const grid = dark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.05)', tick = dark ? '#a89cc8' : '#8592a3';
        const sc = { x: { stacked: true, grid: { display: false }, ticks: { color: tick, maxRotation: 45, font: { size: 11 } } }, y: { stacked: true, beginAtZero: true, grid: { color: grid }, ticks: { color: tick, precision: 0 } } };
        new Chart(document.getElementById('cfChart'), {
            type: 'bar',
            data: { labels, datasets: [
                { label: 'Completed events', data: hk.map(k => hist[k]).concat(pad(labels.length - hk.length)), backgroundColor: 'rgba(147,112,219,.6)', borderRadius: 4 },
                { label: 'Expected: ARIMA forecast', data: part('arima'), backgroundColor: '#7B5FC7', borderRadius: 4 },
                { label: 'Expected: aggregate estimate', data: part('aggregate'), backgroundColor: '#f59e0b', borderRadius: 4 },
            ] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: tick, boxWidth: 12 } } }, scales: sc }
        });
        new Chart(document.getElementById('cfMonths'), {
            type: 'bar',
            data: { labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'], datasets: [{ label: 'Completed events', data: byMonth, backgroundColor: 'rgba(147,112,219,.6)', borderRadius: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { color: tick } }, y: { beginAtZero: true, grid: { color: grid }, ticks: { color: tick, precision: 0 } } } }
        });
    }
    if (window.Chart) draw(); else window.addEventListener('load', draw);
})();
</script>
