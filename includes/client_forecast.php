<?php
/**
 * Client booking forecasts - when is each client likely to book again?
 * Used by predictive_analytics.php (Client Forecasts) and clients.php (one client's panel).
 *
 * Source data: event_bookings rows with status 'Completed', not archived, linked to a client record.
 * jq_complete_booking() in includes/booking_manager.php creates or links the client when an event is
 * completed, so every completed event is part of a client's history. Pending, Approved, Cancelled and
 * Disabled bookings are never used. Nothing is stored: forecasts are recalculated from the database on
 * every page load, so they change as soon as an event is completed, edited, archived or restored.
 *
 * The series for each client is the number of days between consecutive completed event dates.
 *   1 completed event   -> Initial history. No individual forecast. An aggregate estimate (the median gap
 *                          of all returning clients) is shown when at least 3 gaps exist system-wide.
 *   2+ completed events  -> ARIMA forecast of the next gap (includes/arima.php). With fewer than 4 gaps only the
 *                           simplest model, ARIMA(0,0,0) (the mean gap), can be estimated; its range is then the
 *                           shortest to the longest gap. From 4 gaps on, the order is selected and a 95% interval is used.
 *   8+ completed events  -> plus accuracy: the last 3 gaps are predicted one step ahead from the gaps before them.
 */
require_once __DIR__ . '/arima.php';

const JQ_CF_MIN_ARIMA_EVENTS = 2;     // 1 gap; richer ARIMA orders are tried once there are 4 gaps
const JQ_CF_MIN_ACCURACY_EVENTS = 8;  // 7 gaps: the last 3 are tested, each predicted from at least 4 earlier gaps
const JQ_CF_MIN_POOLED_GAPS = 3;      // gaps needed system-wide for the aggregate estimate
const JQ_CF_DAYS_PER_MONTH = 30.44;

// Calendar dates are handled in UTC so that daylight-saving changes in the server's timezone never shift a day.
function jq_cf_days(string $from, string $to): int
{
    $utc = new DateTimeZone('UTC');
    $a = new DateTimeImmutable($from, $utc); $b = new DateTimeImmutable($to, $utc);
    return (int)$a->diff($b)->format('%r%a');
}

function jq_cf_add_days(string $date, float $days): string
{
    $n = (int)round($days);
    return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
}

/** Today's date in the Philippines, whatever the server's default timezone is. */
function jq_cf_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
}

function jq_cf_months(float $days): float
{
    return $days / JQ_CF_DAYS_PER_MONTH;
}

function jq_cf_percentile(array $v, float $p): float
{
    sort($v);
    $n = count($v);
    if ($n === 0) return 0.0;
    $pos = ($n - 1) * $p;
    $lo = (int)floor($pos); $hi = (int)ceil($pos);
    return $v[$lo] + ($v[$hi] - $v[$lo]) * ($pos - $lo);
}

/** Every client with their completed events, oldest first. [client_id => ['client' => row, 'events' => rows]] */
function jq_cf_load(PDO $pdo, ?int $onlyClient = null): array
{
    $where = $onlyClient ? 'WHERE c.client_id = ?' : '';
    $st = $pdo->prepare("SELECT c.client_id, c.client_code, c.fullname, c.created_at FROM clients c $where ORDER BY c.fullname");
    $st->execute($onlyClient ? [$onlyClient] : []);
    $out = [];
    foreach ($st as $c) $out[(int)$c['client_id']] = ['client' => $c, 'events' => []];
    $st = $pdo->prepare("SELECT booking_id, booking_ref, client_id, event_name, event_type, event_date, DATE(created_at) AS booked_on
                           FROM event_bookings
                          WHERE status = 'Completed' AND is_archived = 0 AND client_id IS NOT NULL" . ($onlyClient ? ' AND client_id = ?' : '') . "
                          ORDER BY client_id, event_date, booking_id");
    $st->execute($onlyClient ? [$onlyClient] : []);
    foreach ($st as $e) {
        $id = (int)$e['client_id'];
        if (isset($out[$id])) $out[$id]['events'][] = $e;
    }
    return $out;
}

/** Gaps in days between consecutive completed events of one client. */
function jq_cf_gaps(array $events): array
{
    $g = [];
    for ($i = 1, $n = count($events); $i < $n; $i++) $g[] = max(0, jq_cf_days($events[$i - 1]['event_date'], $events[$i]['event_date']));
    return $g;
}

/** One-step-ahead accuracy on the last 3 gaps, each predicted from the gaps before it. Days. */
function jq_cf_accuracy(array $gaps): ?array
{
    if (count($gaps) < JQ_CF_MIN_ACCURACY_EVENTS - 1) return null;
    $err = $pct = [];
    for ($i = count($gaps) - 3, $n = count($gaps); $i < $n; $i++) {
        $pred = jq_arima_forecast(array_slice($gaps, 0, $i), 1)['forecast'][0];
        $err[] = $gaps[$i] - $pred;
        if ($gaps[$i] > 0) $pct[] = abs($gaps[$i] - $pred) / $gaps[$i] * 100;
    }
    return ['mae' => array_sum(array_map('abs', $err)) / count($err), 'rmse' => sqrt(array_sum(array_map(static fn($e) => $e * $e, $err)) / count($err)),
            'mape' => $pct ? array_sum($pct) / count($pct) : null, 'tested' => count($err)];
}

/** A calendar month in which the client held events in at least two different years, or null. */
function jq_cf_recurring_month(array $events): ?string
{
    $years = [];
    foreach ($events as $e) $years[(int)date('n', strtotime($e['event_date']))][date('Y', strtotime($e['event_date']))] = true;
    $best = null; $bestN = 1;
    foreach ($years as $m => $ys) if (count($ys) > $bestN) { $best = $m; $bestN = count($ys); }
    return $best ? date('F', mktime(0, 0, 0, $best, 1)) : null;
}

/**
 * The forecast for one client. $pooled are the gaps of all returning clients (for the aggregate estimate).
 * method: 'arima' | 'aggregate' | 'none'.
 */
function jq_cf_client(array $client, array $events, array $pooled, string $today): array
{
    $n = count($events);
    $gaps = jq_cf_gaps($events);
    $last = $n ? end($events)['event_date'] : null;
    $r = [
        'client_id' => (int)$client['client_id'], 'code' => $client['client_code'], 'name' => $client['fullname'],
        'events' => $events, 'count' => $n, 'gaps' => $gaps, 'last_event' => $last,
        'avg_gap' => $gaps ? array_sum($gaps) / count($gaps) : null,
        'recurring_month' => jq_cf_recurring_month($events),
        'status' => $n === 0 ? 'No completed events' : ($n >= JQ_CF_MIN_ARIMA_EVENTS ? 'ARIMA forecast' : 'Initial history'),
        'method' => 'none', 'predicted' => null, 'low' => null, 'high' => null, 'model' => null, 'accuracy' => null, 'explanation' => '',
    ];
    if ($n === 0) {
        $r['explanation'] = 'This client has no completed events yet, so there is no booking history to analyze.';
        return $r;
    }
    if ($n >= JQ_CF_MIN_ARIMA_EVENTS) {
        $f = jq_arima_forecast($gaps, 1);
        $r['method'] = 'arima';
        $r['model'] = $f['label'];
        $r['predicted'] = jq_cf_add_days($last, $f['forecast'][0]);
        $short = $f['lower'][0] === $f['upper'][0];   // too few gaps for a statistical interval
        $r['low'] = jq_cf_add_days($last, $short ? min($gaps) : $f['lower'][0]);
        $r['high'] = jq_cf_add_days($last, $short ? max($gaps) : $f['upper'][0]);
        $r['next_gap'] = $f['forecast'][0];
        $r['stationarity'] = $f['stationarity'] ?? null;
        $r['accuracy'] = jq_cf_accuracy($gaps);
        $r['explanation'] = sprintf('%s was fitted to the %d gaps between this client\'s %d completed events (average %.1f months). It predicts the next gap at %.1f months after the last event on %s.',
            $f['label'], count($gaps), $n, jq_cf_months($r['avg_gap']), jq_cf_months($f['forecast'][0]), date('M j, Y', strtotime($last)));
    } elseif (count($pooled) >= JQ_CF_MIN_POOLED_GAPS) {
        $med = jq_cf_percentile($pooled, 0.5);
        $r['method'] = 'aggregate';
        $r['predicted'] = jq_cf_add_days($last, $med);
        $r['low'] = jq_cf_add_days($last, jq_cf_percentile($pooled, 0.25));
        $r['high'] = jq_cf_add_days($last, jq_cf_percentile($pooled, 0.75));
        $r['explanation'] = sprintf('This client has one completed event, which is saved as their initial history. No individual forecast is possible from one event. This aggregate estimate uses the typical gap of other returning clients (median %.1f months, from %d gaps).',
            jq_cf_months($med), count($pooled));
    } else {
        $r['explanation'] = sprintf('This client has one completed event, which is saved as their initial history. No individual forecast is possible yet, and fewer than %d repeat gaps exist across all clients for an aggregate estimate.', JQ_CF_MIN_POOLED_GAPS);
    }
    if ($r['predicted'] !== null && $r['predicted'] < $today) {
        $r['explanation'] .= ' The expected period has already passed without a new completed event, so the client may be due to book now.';
    }
    return $r;
}

/** Forecast for one client, using all clients' gaps for the aggregate estimate. */
function jq_cf_for_client(PDO $pdo, int $clientId): ?array
{
    $all = jq_cf_load($pdo);
    if (!isset($all[$clientId])) return null;
    $pooled = [];
    foreach ($all as $c) $pooled = array_merge($pooled, jq_cf_gaps($c['events']));
    return jq_cf_client($all[$clientId]['client'], $all[$clientId]['events'], $pooled, jq_cf_today());
}

/** Everything the Client Forecasts view shows. */
function jq_cf_overview(PDO $pdo, int $horizonMonths = 3): array
{
    $today = jq_cf_today();
    $all = jq_cf_load($pdo);
    $pooled = [];
    foreach ($all as $c) $pooled = array_merge($pooled, jq_cf_gaps($c['events']));
    $rows = [];
    foreach ($all as $c) $rows[] = jq_cf_client($c['client'], $c['events'], $pooled, $today);

    $withEvents = array_filter($rows, static fn($r) => $r['count'] > 0);
    $returning = array_filter($rows, static fn($r) => $r['count'] >= 2);
    $arima = array_filter($rows, static fn($r) => $r['method'] === 'arima');
    $completedAll = (int)$pdo->query("SELECT COUNT(*) FROM event_bookings WHERE status = 'Completed' AND is_archived = 0")->fetchColumn();

    // Expected bookings: estimates from today to the end of the horizon, plus estimates already passed (due now)
    $end = date('Y-m-t', strtotime("first day of +$horizonMonths months"));
    $upcoming = array_values(array_filter($rows, static fn($r) => $r['predicted'] !== null && $r['predicted'] <= $end));
    usort($upcoming, static fn($a, $b) => strcmp($a['predicted'], $b['predicted']));

    // Expected client bookings per month over the next 6 months, by method
    $months = [];
    for ($i = 0; $i < 6; $i++) $months[date('Y-m', strtotime("first day of +$i months"))] = ['arima' => 0, 'aggregate' => 0];
    foreach ($rows as $r) {
        if ($r['predicted'] === null) continue;
        $ym = substr(max($r['predicted'], $today), 0, 7);   // a passed estimate counts in the current month
        if (isset($months[$ym])) $months[$ym][$r['method']]++;
    }

    // Completed events per month over the last 12 months (history for the chart)
    $hist = [];
    for ($i = 11; $i >= 0; $i--) $hist[date('Y-m', strtotime("first day of -$i months"))] = 0;
    foreach ($all as $c) foreach ($c['events'] as $e) {
        $ym = substr($e['event_date'], 0, 7);
        if (isset($hist[$ym])) $hist[$ym]++;
    }

    // Booking patterns
    $byMonth = array_fill(1, 12, 0);
    foreach ($all as $c) foreach ($c['events'] as $e) $byMonth[(int)date('n', strtotime($e['event_date']))]++;
    $freq = ['1 event' => 0, '2 events' => 0, '3-4 events' => 0, '5 or more' => 0];
    foreach ($withEvents as $r) $freq[$r['count'] === 1 ? '1 event' : ($r['count'] === 2 ? '2 events' : ($r['count'] <= 4 ? '3-4 events' : '5 or more'))]++;
    $recurring = array_values(array_filter($rows, static fn($r) => $r['recurring_month'] !== null));

    return [
        'today' => $today, 'horizon' => $horizonMonths, 'rows' => $rows, 'upcoming' => $upcoming,
        'totals' => [
            'clients' => count($rows), 'with_history' => count($withEvents), 'returning' => count($returning),
            'completed' => $completedAll, 'arima' => count($arima), 'awaiting' => count($withEvents) - count($arima),
            'expected' => count($upcoming),
        ],
        'patterns' => [
            'by_month' => $byMonth, 'frequency' => $freq, 'recurring' => $recurring,
            'repeat_rate' => count($withEvents) ? count($returning) / count($withEvents) : 0.0,
            'avg_gap' => $pooled ? array_sum($pooled) / count($pooled) : null,
            'median_gap' => $pooled ? jq_cf_percentile($pooled, 0.5) : null, 'gaps' => count($pooled),
        ],
        'chart' => ['history' => $hist, 'expected' => $months],
    ];
}
