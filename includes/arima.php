<?php
/**
 * ARIMA(p,d,q) forecasting for the dashboard (monthly bookings and revenue).
 *
 *   1. d (0 or 1): with 10 or more values an Augmented Dickey-Fuller test decides; with fewer, the
 *      series is differenced once if that lowers its variance (minimum-variance rule).
 *   2. For every candidate p in 0..2 and q in 0..1, the constant, AR coefficients (phi) and
 *      MA coefficients (theta) are estimated by conditional sum of squares (CSS), minimised with
 *      the Nelder-Mead method. Only stationary and invertible coefficients are accepted.
 *   3. The model with the lowest AICc (AIC corrected for short histories) is used to forecast the next months; differencing is then undone.
 *   4. A 95% prediction interval comes from the model's psi weights, and a back-test on the last
 *      3 months measures accuracy (MAE and MAPE).
 *
 * Model on the differenced series z:  z_t = c + phi_1 z_{t-1} + ... + phi_p z_{t-p} + e_t + theta_1 e_{t-1}
 * No external library is needed.
 */

const JQ_ARIMA_MAX_P = 2;
const JQ_ARIMA_MAX_Q = 1;

function jq_arima_variance(array $v): float
{
    $n = count($v);
    if ($n < 2) return 0.0;
    $m = array_sum($v) / $n;
    $s = 0.0;
    foreach ($v as $x) $s += ($x - $m) ** 2;
    return $s / ($n - 1);
}

/**
 * Augmented Dickey-Fuller test (constant, no lagged differences):  dy_t = a + g * y_{t-1} + e_t.
 * H0: unit root (g = 0, not stationary). Rejected when the t statistic of g is below the 5% critical value
 * (MacKinnon approximation for a model with a constant: -2.8621 - 2.738/n - 8.36/n^2).
 * Returns ['stat' => t, 'critical' => c, 'stationary' => bool, 'n' => n] or null with fewer than 10 values.
 */
function jq_arima_adf(array $y): ?array
{
    $y = array_values(array_map('floatval', $y));
    $n = count($y) - 1;
    if ($n < 9) return null;
    $x = array_slice($y, 0, -1);
    $dy = jq_arima_difference($y, 1);
    $mx = array_sum($x) / $n; $md = array_sum($dy) / $n;
    $sxx = $sxy = 0.0;
    for ($i = 0; $i < $n; $i++) { $sxx += ($x[$i] - $mx) ** 2; $sxy += ($x[$i] - $mx) * ($dy[$i] - $md); }
    if ($sxx <= 0) return null;                  // a constant series: nothing to test
    $g = $sxy / $sxx; $a = $md - $g * $mx;
    $sse = 0.0;
    for ($i = 0; $i < $n; $i++) $sse += ($dy[$i] - $a - $g * $x[$i]) ** 2;
    $se = sqrt(($sse / ($n - 2)) / $sxx);
    $t = $se > 0 ? $g / $se : -INF;
    $crit = -2.8621 - 2.738 / $n - 8.36 / ($n * $n);
    return ['stat' => $t, 'critical' => $crit, 'stationary' => $t < $crit, 'n' => $n];
}

/**
 * Chooses d. With 10 or more values the ADF test decides (d = 1 when a unit root is not rejected);
 * with fewer, the series is differenced only if that lowers its variance.
 * Returns ['d' => 0|1, 'rule' => 'ADF test'|'variance rule'|'too short', 'adf' => ?array].
 */
function jq_arima_choose_d(array $y): array
{
    $adf = jq_arima_adf($y);
    if ($adf) return ['d' => $adf['stationary'] ? 0 : 1, 'rule' => 'ADF test', 'adf' => $adf];
    if (count($y) < 4) return ['d' => 0, 'rule' => 'too short', 'adf' => null];
    return ['d' => jq_arima_variance(jq_arima_difference($y, 1)) < jq_arima_variance($y) ? 1 : 0, 'rule' => 'variance rule', 'adf' => null];
}

function jq_arima_difference(array $y, int $d): array
{
    for ($k = 0; $k < $d; $k++) {
        $z = [];
        for ($i = 1, $n = count($y); $i < $n; $i++) $z[] = $y[$i] - $y[$i - 1];
        $y = $z;
    }
    return $y;
}

/** True when the AR part is stationary and the MA part invertible. */
function jq_arima_admissible(array $phi, array $theta): bool
{
    $lim = 0.98;
    if (count($phi) === 1 && abs($phi[0]) >= $lim) return false;
    if (count($phi) === 2) {
        [$a, $b] = $phi;
        if (abs($b) >= $lim || $a + $b >= $lim || $b - $a >= $lim) return false;
    }
    foreach ($theta as $t) if (abs($t) >= $lim) return false;
    return true;
}

/** Conditional sum of squares: returns [sse, residuals, number of residuals used]. */
function jq_arima_css(array $z, int $p, int $q, array $par): array
{
    $n = count($z);
    $c = $par[0];
    $phi = array_slice($par, 1, $p);
    $theta = array_slice($par, 1 + $p, $q);
    $e = array_fill(0, $n, 0.0);
    $sse = 0.0; $m = 0;
    for ($t = $p; $t < $n; $t++) {
        $pred = $c;
        for ($i = 1; $i <= $p; $i++) $pred += $phi[$i - 1] * $z[$t - $i];
        for ($j = 1; $j <= $q; $j++) if ($t - $j >= 0) $pred += $theta[$j - 1] * $e[$t - $j];
        $e[$t] = $z[$t] - $pred;
        $sse += $e[$t] ** 2;
        $m++;
    }
    return [$sse, $e, $m];
}

/** Nelder-Mead minimisation of $f starting at $x0 with initial step sizes $step. */
function jq_arima_nelder_mead(callable $f, array $x0, array $step, int $maxIter = 600): array
{
    $k = count($x0);
    $pts = [$x0];
    for ($i = 0; $i < $k; $i++) { $x = $x0; $x[$i] += $step[$i]; $pts[] = $x; }
    $val = array_map($f, $pts);
    for ($it = 0; $it < $maxIter; $it++) {
        array_multisort($val, SORT_ASC, SORT_NUMERIC, $pts);
        if (abs($val[$k] - $val[0]) <= 1e-10 * (abs($val[0]) + 1e-12)) break;
        $cen = array_fill(0, $k, 0.0);
        for ($i = 0; $i < $k; $i++) for ($j = 0; $j < $k; $j++) $cen[$j] += $pts[$i][$j] / $k;
        $mv = static fn(float $a) => array_map(static fn($c, $w) => $c + $a * ($w - $c), $cen, $pts[$k]);
        $xr = $mv(-1.0); $fr = $f($xr);
        if ($fr < $val[0]) {
            $xe = $mv(-2.0); $fe = $f($xe);
            [$pts[$k], $val[$k]] = $fe < $fr ? [$xe, $fe] : [$xr, $fr];
        } elseif ($fr < $val[$k - 1]) {
            [$pts[$k], $val[$k]] = [$xr, $fr];
        } else {
            $xc = $fr < $val[$k] ? $mv(-0.5) : $mv(0.5); $fc = $f($xc);
            if ($fc < min($fr, $val[$k])) {
                [$pts[$k], $val[$k]] = [$xc, $fc];
            } else {
                for ($i = 1; $i <= $k; $i++) {
                    $pts[$i] = array_map(static fn($a, $b) => $a + 0.5 * ($b - $a), $pts[0], $pts[$i]);
                    $val[$i] = $f($pts[$i]);
                }
            }
        }
    }
    array_multisort($val, SORT_ASC, SORT_NUMERIC, $pts);
    return [$pts[0], $val[0]];
}

/** Fits ARIMA(p,d,q) to $y. Returns null when there is too little data for this order. */
function jq_arima_fit(array $y, int $p, int $d, int $q): ?array
{
    $z = jq_arima_difference(array_values(array_map('floatval', $y)), $d);
    $k = 1 + $p + $q;                          // constant + coefficients
    if (count($z) - $p < $k + 3) return null;
    $mean = array_sum($z) / max(count($z), 1);
    $sd = sqrt(jq_arima_variance($z)) ?: 1.0;
    $obj = static function (array $par) use ($z, $p, $q): float {
        if (!jq_arima_admissible(array_slice($par, 1, $p), array_slice($par, 1 + $p, $q))) return 1e300;
        return jq_arima_css($z, $p, $q, $par)[0];
    };
    $x0 = array_merge([$mean], array_fill(0, $p + $q, 0.0));
    $step = array_merge([$sd * 0.5], array_fill(0, $p + $q, 0.3));
    [$best] = jq_arima_nelder_mead($obj, $x0, $step);
    [$best] = jq_arima_nelder_mead($obj, $best, array_map(static fn($s) => $s * 0.2, $step)); // restart to refine
    [$sse, $e, $m] = jq_arima_css($z, $p, $q, $best);
    $sigma2 = max($sse / max($m, 1), 1e-12);
    $np = $k + 1;                                // parameters, including the error variance
    $aic = $m * log($sigma2) + 2 * $np;
    $coefs = array_slice($best, 1);
    return [
        'order' => [$p, $d, $q], 'c' => $best[0], 'phi' => array_slice($best, 1, $p), 'theta' => array_slice($best, 1 + $p, $q),
        'sigma2' => $sigma2, 'aic' => $aic,
        // small-sample correction (AICc), used to choose the order because monthly histories are short
        'aicc' => $m - $np - 1 > 0 ? $aic + 2 * $np * ($np + 1) / ($m - $np - 1) : INF,
        'bic' => $m * log($sigma2) + $np * log(max($m, 1)),
        // a coefficient that ended on the stationarity/invertibility limit means the order does not fit the data
        'boundary' => $coefs && max(array_map('abs', $coefs)) > 0.97,
        'z' => $z, 'e' => $e, 'y' => array_values($y),
    ];
}

/** Forecasts $h steps ahead from a fitted model, undoing the differencing. */
function jq_arima_predict(array $fit, int $h): array
{
    [$p, $d, $q] = $fit['order'];
    $z = $fit['z']; $e = $fit['e'];
    $n = count($z);
    for ($s = 0; $s < $h; $s++) {
        $t = $n + $s;
        $v = $fit['c'];
        for ($i = 1; $i <= $p; $i++) $v += $fit['phi'][$i - 1] * $z[$t - $i];
        for ($j = 1; $j <= $q; $j++) if ($t - $j < $n) $v += $fit['theta'][$j - 1] * $e[$t - $j];
        $z[] = $v; $e[] = 0.0;                 // future shocks have expected value 0
    }
    $out = array_slice($z, $n);
    if ($d === 1) {
        $last = end($fit['y']);
        foreach ($out as &$v) { $last += $v; $v = $last; }
        unset($v);
    }
    return $out;
}

/**
 * Psi weights of the fitted model on the original scale (differencing included):
 * y_{n+h} - forecast = e_{n+h} + psi_1 e_{n+h-1} + ... , so Var(h) = sigma2 * sum_{j<h} psi_j^2.
 */
function jq_arima_psi(array $fit, int $h): array
{
    [$p, $d, $q] = $fit['order'];
    $psi = [1.0];
    for ($j = 1; $j < $h; $j++) {
        $v = $j <= $q ? $fit['theta'][$j - 1] : 0.0;
        for ($i = 1; $i <= min($j, $p); $i++) $v += $fit['phi'][$i - 1] * $psi[$j - $i];
        $psi[] = $v;
    }
    if ($d === 1) {                              // integrating once turns the weights into running sums
        $run = 0.0;
        foreach ($psi as &$v) { $run += $v; $v = $run; }
        unset($v);
    }
    return $psi;
}

/**
 * Chooses d, then the (p,q) with the lowest AICc, skipping fits whose coefficients ended on the limit.
 * Returns the fitted model, or null for an empty history.
 */
function jq_arima_auto(array $y): ?array
{
    $y = array_values(array_map('floatval', $y));
    $choice = jq_arima_choose_d($y);
    $d = $choice['d'];
    $best = null;
    for ($p = 0; $p <= JQ_ARIMA_MAX_P; $p++) {
        for ($q = 0; $q <= JQ_ARIMA_MAX_Q; $q++) {
            $fit = jq_arima_fit($y, $p, $d, $q);
            if (!$fit || $fit['boundary'] || !is_finite($fit['aicc'])) continue;
            if (!$best || $fit['aicc'] < $best['aicc'] - 1e-9) $best = $fit;
        }
    }
    $best = $best ?: jq_arima_fit($y, 0, $d, 0) ?: jq_arima_fit($y, 0, 0, 0);   // short history: the simplest model
    if ($best) $best['stationarity'] = $choice;
    return $best;
}

/**
 * Chooses the order automatically and forecasts $h months, with a 95% prediction interval.
 * Returns forecast, lower and upper (all non-negative), order [p,d,q] and label 'ARIMA(p,d,q)'.
 */
function jq_arima_forecast(array $y, int $h = 3): array
{
    $y = array_values(array_map('floatval', $y));
    $best = jq_arima_auto($y);
    if (!$best) {
        $mean = count($y) ? array_sum($y) / count($y) : 0.0;
        $flat = array_fill(0, $h, $mean);
        return ['forecast' => $flat, 'lower' => $flat, 'upper' => $flat, 'order' => [0, 0, 0], 'label' => 'ARIMA(0,0,0)'];
    }
    $mid = jq_arima_predict($best, $h);
    $psi = jq_arima_psi($best, $h);
    $lower = $upper = [];
    $acc = 0.0;
    foreach ($mid as $i => $v) {
        $acc += $psi[$i] ** 2;
        $half = 1.96 * sqrt($best['sigma2'] * $acc);
        $lower[] = max(0.0, $v - $half);
        $upper[] = max(0.0, $v + $half);
    }
    return ['forecast' => array_map(static fn($v) => max(0.0, $v), $mid),  // bookings and revenue cannot be negative
            'lower' => $lower, 'upper' => $upper, 'order' => $best['order'], 'label' => vsprintf('ARIMA(%d,%d,%d)', $best['order']),
            'aicc' => $best['aicc'], 'bic' => $best['bic'], 'stationarity' => $best['stationarity'] ?? null];
}

/**
 * Back-test: fits on all but the last $hold months, forecasts them, and compares with what happened.
 * Returns ['mae' => .., 'mape' => ..|null, 'months' => $hold] or null when the history is too short (under 9 months).
 */
function jq_arima_backtest(array $y, int $hold = 3): ?array
{
    $y = array_values(array_map('floatval', $y));
    if (count($y) < 6 + $hold) return null;
    $f = jq_arima_forecast(array_slice($y, 0, -$hold), $hold)['forecast'];
    $actual = array_slice($y, -$hold);
    $abs = $pct = [];
    foreach ($actual as $i => $a) {
        $abs[] = abs($a - $f[$i]);
        if ($a != 0.0) $pct[] = abs($a - $f[$i]) / abs($a) * 100;
    }
    return ['mae' => array_sum($abs) / $hold, 'mape' => $pct ? array_sum($pct) / count($pct) : null, 'months' => $hold,
            'actual' => $actual, 'forecast' => $f];
}
