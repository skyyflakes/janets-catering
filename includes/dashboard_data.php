<?php
/**
 * Dashboard figures, read directly from event_bookings each time.
 * dashboard.php draws the page from this and ajax/dashboard_bookings.php refreshes it without a reload.
 * Active means not archived and either Pending or Approved. A booking deleted permanently no longer exists.
 */

require_once __DIR__ . '/arima.php';

function jq_dashboard_empty(): array
{
    return jq_dashboard_shape([], [], [], [], 0, 0, 0.0, 0.0);
}

function jq_dashboard_data(PDO $pdo): array
{
    try {
        $counts = $pdo->query("SELECT status, COUNT(*) FROM event_bookings WHERE is_archived = 0 GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $packages = (int)$pdo->query("SELECT COUNT(*) FROM menu_packages")->fetchColumn();
        $items = (int)$pdo->query("SELECT COUNT(*) FROM menu_items")->fetchColumn();
        $active = "is_archived = 0 AND status IN ('Pending','Approved')";
        $earning = "is_archived = 0 AND status IN ('Pending','Approved','Completed')";
        $total = (float)$pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM event_bookings WHERE $earning")->fetchColumn();
        $upcoming = (float)$pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM event_bookings WHERE $active AND event_date >= CURDATE()")->fetchColumn();
        $recent = $pdo->query("SELECT booking_id, booking_ref, event_name, package_name, fullname, event_date, headcount, grand_total, status
                                 FROM event_bookings WHERE is_archived = 0 ORDER BY created_at DESC, booking_id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recent as &$r) {
            $r['event_date_label'] = date('M d, Y', strtotime($r['event_date']));
        }
        unset($r);
        $popular = $pdo->query("SELECT package_name, COUNT(*) AS booking_count, COALESCE(SUM(grand_total),0) AS revenue
                                  FROM event_bookings WHERE $earning AND package_name IS NOT NULL
                                 GROUP BY package_name ORDER BY booking_count DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $monthly = $pdo->query("SELECT DATE_FORMAT(event_date, '%Y-%m') AS month, COUNT(*) AS booking_count, COALESCE(SUM(grand_total),0) AS revenue
                                  FROM event_bookings
                                 WHERE $earning AND event_date >= DATE_SUB(CURDATE(), INTERVAL 36 MONTH)
                                 GROUP BY DATE_FORMAT(event_date, '%Y-%m') ORDER BY month ASC")->fetchAll(PDO::FETCH_ASSOC);
        return jq_dashboard_shape($counts, $recent, $popular, $monthly, $packages, $items, $total, $upcoming);
    } catch (Throwable $e) {
        error_log('Dashboard query error: ' . $e->getMessage());
        return jq_dashboard_empty();
    }
}

function jq_dashboard_shape(array $counts, array $recent, array $popular, array $monthly, int $packages, int $items, float $total, float $upcoming): array
{
    $labels = $bookings = $revenue = [];
    $byMonth = array_column($monthly, null, 'month');
    for ($i = 11; $i >= 0; $i--) {
        $when = strtotime("first day of -$i months");
        $labels[] = date('M Y', $when);
        $row = $byMonth[date('Y-m', $when)] ?? null;
        $bookings[] = $row ? (int)$row['booking_count'] : 0;
        $revenue[] = $row ? (float)$row['revenue'] : 0.0;
    }

    // 3-month ARIMA forecast (includes/arima.php), fitted on up to 36 months of history that starts at the
    // first month with a booking. The order (p,d,q) is chosen automatically for each series.
    $histB = $histR = [];
    for ($i = 35; $i >= 0; $i--) {
        $row = $byMonth[date('Y-m', strtotime("first day of -$i months"))] ?? null;
        if (!$row && !$histB) continue;            // skip the empty months before the first booking
        $histB[] = $row ? (int)$row['booking_count'] : 0;
        $histR[] = $row ? (float)$row['revenue'] : 0.0;
    }
    $fb = jq_arima_forecast($histB, 3);
    $fr = jq_arima_forecast($histR, 3);
    $predBookings = array_merge(array_fill(0, 12, null), array_map(static fn($v) => round($v, 1), $fb['forecast']));
    $predRevenue = array_merge(array_fill(0, 12, null), array_map(static fn($v) => round($v, 0), $fr['forecast']));
    $predBookings[11] = end($bookings);
    $predRevenue[11] = end($revenue);
    for ($i = 1; $i <= 3; $i++) {
        $labels[] = date('M Y', strtotime("first day of +$i months"));
    }

    $pending = (int)($counts['Pending'] ?? 0);
    $approved = (int)($counts['Approved'] ?? 0);
    return [
        'counts'  => ['active' => $pending + $approved, 'pending' => $pending, 'approved' => $approved,
                      'cancelled' => (int)($counts['Cancelled'] ?? 0), 'disabled' => (int)($counts['Disabled'] ?? 0), 'completed' => (int)($counts['Completed'] ?? 0)],
        'catalog' => ['packages' => $packages, 'items' => $items],
        'revenue' => ['total' => $total, 'upcoming' => $upcoming],
        'recent'  => $recent,
        'popular' => $popular,
        'chart'   => ['labels' => $labels, 'bookings' => $bookings, 'revenue' => $revenue, 'pred_bookings' => $predBookings, 'pred_revenue' => $predRevenue,
                      'model' => ['bookings' => $fb['label'], 'revenue' => $fr['label']]],
    ];
}
