<?php
/**
 * Predictive analytics for predictive_analytics.php.
 * Everything is read from event_bookings each time; nothing is stored.
 * Bookings that count: not archived, and Pending, Approved or Completed (the same rule as the dashboard).
 *
 *  - ARIMA forecasts (includes/arima.php) of monthly bookings, revenue and guests, with 95% intervals
 *  - a back-test of the bookings model on the last 3 months
 *  - seasonal demand: average bookings for each calendar month over the whole history
 *  - expected demand by event type and package: forecast total x share of the last 12 months
 *  - bookings already on the calendar for each forecast month
 */
require_once __DIR__ . '/arima.php';

const JQ_ANALYTICS_EARNING = "is_archived = 0 AND status IN ('Pending','Approved','Completed')";

function jq_analytics_data(PDO $pdo, int $horizon = 3): array
{
    $horizon = in_array($horizon, [3, 6], true) ? $horizon : 3;
    $w = JQ_ANALYTICS_EARNING;

    // Monthly history, up to 36 months back to this month
    $rows = $pdo->query("SELECT DATE_FORMAT(event_date, '%Y-%m') AS ym, COUNT(*) AS n, COALESCE(SUM(grand_total),0) AS revenue, COALESCE(SUM(headcount),0) AS guests
                           FROM event_bookings
                          WHERE $w AND event_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 35 MONTH)
                            AND event_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
                          GROUP BY ym")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $months = $bookings = $revenue = $guests = [];
    for ($i = 35; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("first day of -$i months"));
        $r = $rows[$ym] ?? null;
        if (!$r && !$months) continue;                // start at the first month with a booking
        $months[] = $ym;
        $bookings[] = $r ? (int)$r['n'] : 0;
        $revenue[] = $r ? (float)$r['revenue'] : 0.0;
        $guests[] = $r ? (int)$r['guests'] : 0;
    }

    $fc = [
        'bookings' => jq_arima_forecast($bookings, $horizon),
        'revenue'  => jq_arima_forecast($revenue, $horizon),
        'guests'   => jq_arima_forecast($guests, $horizon),
    ];
    $future = [];
    for ($i = 1; $i <= $horizon; $i++) $future[] = date('Y-m', strtotime("first day of +$i months"));

    // Bookings already on the calendar in each forecast month
    $st = $pdo->prepare("SELECT DATE_FORMAT(event_date, '%Y-%m') AS ym, COUNT(*) AS n, COALESCE(SUM(headcount),0) AS guests
                           FROM event_bookings WHERE is_archived = 0 AND status IN ('Pending','Approved')
                            AND event_date >= ? AND event_date < ? GROUP BY ym");
    $st->execute([$future[0] . '-01', date('Y-m-01', strtotime(end($future) . '-01 +1 month'))]);
    $booked = $st->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    $table = [];
    foreach ($future as $i => $ym) {
        $table[] = [
            'month' => $ym, 'label' => date('F Y', strtotime("$ym-01")),
            'bookings' => $fc['bookings']['forecast'][$i], 'bookings_low' => $fc['bookings']['lower'][$i], 'bookings_high' => $fc['bookings']['upper'][$i],
            'revenue' => $fc['revenue']['forecast'][$i], 'revenue_low' => $fc['revenue']['lower'][$i], 'revenue_high' => $fc['revenue']['upper'][$i],
            'guests' => $fc['guests']['forecast'][$i], 'guests_low' => $fc['guests']['lower'][$i], 'guests_high' => $fc['guests']['upper'][$i],
            'already_booked' => (int)($booked[$ym]['n'] ?? 0), 'already_guests' => (int)($booked[$ym]['guests'] ?? 0),
        ];
    }

    // Seasonal demand over the whole history: average bookings per calendar month, per year observed
    $season = $pdo->query("SELECT MONTH(event_date) AS m, COUNT(*) AS n, COUNT(DISTINCT YEAR(event_date)) AS years
                             FROM event_bookings WHERE $w AND event_date <= LAST_DAY(CURDATE()) GROUP BY m")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $seasonal = [];
    for ($m = 1; $m <= 12; $m++) {
        $s = $season[$m] ?? null;
        $seasonal[] = ['month' => date('M', mktime(0, 0, 0, $m, 1)), 'total' => (int)($s['n'] ?? 0), 'avg' => $s ? (int)$s['n'] / max((int)$s['years'], 1) : 0.0];
    }
    $overall = array_sum(array_column($seasonal, 'avg')) / 12;
    foreach ($seasonal as &$s) $s['index'] = $overall > 0 ? $s['avg'] / $overall : 0.0;
    unset($s);
    $ranked = $seasonal;
    usort($ranked, static fn($a, $b) => $b['avg'] <=> $a['avg']);
    $peaks = array_values(array_filter(array_slice($ranked, 0, 3), static fn($s) => $s['avg'] > 0));

    // Expected demand by event type and by package over the forecast horizon
    $total = array_sum($fc['bookings']['forecast']);
    $mix = static function (string $col) use ($pdo, $w, $total): array {
        $r = $pdo->query("SELECT $col AS name, COUNT(*) AS n FROM event_bookings eb LEFT JOIN event_types et ON et.event_type_id = eb.event_type_id
                           WHERE eb.$w AND eb.event_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND eb.event_date <= LAST_DAY(CURDATE())
                           GROUP BY name HAVING name IS NOT NULL AND name <> '' ORDER BY n DESC, name")->fetchAll(PDO::FETCH_ASSOC);
        $all = array_sum(array_column($r, 'n'));
        return array_map(static fn($x) => ['name' => $x['name'], 'count' => (int)$x['n'], 'share' => $all ? $x['n'] / $all : 0,
                                           'expected' => $all ? $total * $x['n'] / $all : 0], $r);
    };
    $byType = $mix('COALESCE(et.event_type_name, eb.event_type)');
    $byPackage = $mix('eb.package_name');

    $avgGuests = array_sum($bookings) ? array_sum($guests) / array_sum($bookings) : 0;

    return [
        'horizon' => $horizon,
        'history' => ['months' => $months, 'bookings' => $bookings, 'revenue' => $revenue, 'guests' => $guests],
        'future' => $future,
        'forecast' => $fc,
        'table' => $table,
        'totals' => ['bookings' => $total, 'revenue' => array_sum($fc['revenue']['forecast']), 'guests' => array_sum($fc['guests']['forecast']),
                     'already_booked' => array_sum(array_column($table, 'already_booked'))],
        'backtest' => jq_arima_backtest($bookings, 3),
        'seasonal' => $seasonal, 'peaks' => $peaks,
        'by_type' => $byType, 'by_package' => $byPackage,
        'avg_guests' => $avgGuests,
    ];
}
