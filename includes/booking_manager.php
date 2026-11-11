<?php
/**
 * BOOKING RULES - Janet's Quality Catering System
 * Totals, payments, booking inclusions, clients, event types and private files.
 * Used by event_bookings.php, clients.php, food_menus.php and supervisor_dashboard.php.
 */

if (defined('JQ_BOOKING_MANAGER_LOADED')) {
    return;
}
define('JQ_BOOKING_MANAGER_LOADED', true);

require_once __DIR__ . '/activity_logger.php';

const JQ_BOOKING_STATUSES = ['Pending', 'Approved', 'Cancelled', 'Disabled', 'Completed'];
const JQ_PREPARATION_STATUSES = ['Pending Preparation', 'Preparing', 'Ready for Event', 'Completed'];
const JQ_PAYMENT_METHODS = ['Cash', 'GCash', 'Bank Transfer', 'Check', 'Credit or Debit Card', 'Other'];
const JQ_PAYMENT_TYPES = ['Downpayment', 'Additional Payment'];
const JQ_PAYMENT_REVIEW_STATUSES = ['Awaiting Receipt', 'Receipt Submitted', 'Pending Verification', 'Verified', 'Rejected'];
const JQ_DOCUMENT_TYPES = ['Signed Contract', 'Client Signature', 'Approval Document', 'Confirmation Document', 'Other'];
const JQ_PRIVATE_FILE_MAX_BYTES = 10485760;
const JQ_SERVICE_TAX_RATE = 12.00;
const JQ_DOWNPAYMENT_PERCENT = 50;        // minimum share of the total that must be paid to book
const JQ_LARGE_EVENT_GUESTS = 100;        // from this many guests, the longer preparation applies
const JQ_PREP_DAYS_SMALL = 2;             // 1-99 guests
const JQ_PREP_DAYS_LARGE = 5;             // 100 or more guests

/* ---------- Booking rules: downpayment and preparation time ---------- */

/** Required downpayment = total x 50%, rounded up to the centavo so it is never below half. */
function jq_required_downpayment(float $total): float
{
    return ceil(round($total * JQ_DOWNPAYMENT_PERCENT, 4)) / 100;
}

/** Days of preparation needed before the event: 2 for 1-99 guests, 5 for 100 or more. */
function jq_prep_days(int $headcount): int
{
    return $headcount >= JQ_LARGE_EVENT_GUESTS ? JQ_PREP_DAYS_LARGE : JQ_PREP_DAYS_SMALL;
}

/** Earliest event date allowed when booking on $today (Y-m-d). Same-day events are never possible. */
function jq_earliest_event_date(int $headcount, string $today): string
{
    return (new DateTimeImmutable($today, new DateTimeZone('UTC')))->modify('+' . jq_prep_days($headcount) . ' days')->format('Y-m-d');
}

/** Error message when $eventDate is too soon for $headcount guests, otherwise null. */
function jq_event_date_error(string $eventDate, int $headcount, string $today): ?string
{
    $earliest = jq_earliest_event_date($headcount, $today);
    if ($eventDate >= $earliest) {
        return null;
    }
    $days = jq_prep_days($headcount);
    return sprintf('Invalid event date: an event with %s needs at least %d days of preparation, so the earliest date you can book is %s. Same-day events are not allowed.',
        $headcount >= JQ_LARGE_EVENT_GUESTS ? JQ_LARGE_EVENT_GUESTS . ' or more guests' : 'fewer than ' . JQ_LARGE_EVENT_GUESTS . ' guests', $days, date('F j, Y', strtotime($earliest)));
}

/* ---------- Event types ---------- */

function jq_event_type_clean_name(string $name): string
{
    return trim(preg_replace('/\s+/u', ' ', str_replace("\u{2019}", "'", $name)));
}

/** Returns an error message, or null when the name can be saved. */
function jq_event_type_name_error(string $name): ?string
{
    if ($name === '' || mb_strlen($name) > 100) {
        return 'Enter an event type name of up to 100 characters.';
    }
    if (in_array(mb_strtolower($name), ['other', 'others'], true)) {
        return 'Please type the actual event type instead of "Others".';
    }
    if (!preg_match("~^[\\p{L}\\p{N}][\\p{L}\\p{N} &'/.,()\\-]*$~u", $name)) {
        return "An event type can use letters, numbers, spaces and these marks: & ' - / . , ( )";
    }
    return null;
}

/**
 * Saves a new event type. The database's unique name index refuses duplicates, whatever the letter case.
 * @return array{ok: bool, existing: bool, name: string, message: string}
 */
function jq_add_event_type(PDO $pdo, string $rawName): array
{
    $name = jq_event_type_clean_name($rawName);
    if (($problem = jq_event_type_name_error($name)) !== null) {
        return ['ok' => false, 'existing' => false, 'name' => $name, 'message' => $problem];
    }
    try {
        $pdo->prepare("INSERT INTO event_types (event_type_name, sort_order) VALUES (?, 100)")->execute([$name]);
        return ['ok' => true, 'existing' => false, 'name' => $name, 'message' => 'Event type "' . $name . '" added.'];
    } catch (PDOException $e) {
        if (!jq_is_duplicate($e)) {
            throw $e;
        }
    }
    $st = $pdo->prepare("SELECT event_type_name, is_active FROM event_types WHERE event_type_name = ?");
    $st->execute([$name]);
    $found = $st->fetch(PDO::FETCH_ASSOC);
    if ($found && (int)$found['is_active'] === 1) {
        return ['ok' => true, 'existing' => true, 'name' => $found['event_type_name'], 'message' => '"' . $found['event_type_name'] . '" already exists, so it was selected.'];
    }
    return ['ok' => false, 'existing' => true, 'name' => $name, 'message' => 'That event type already exists but is disabled. Enable it from Add Event Type on the Event Bookings Dashboard.'];
}

function jq_event_types(PDO $pdo, bool $activeOnly = true): array
{
    return $pdo->query("SELECT event_type_id, event_type_name, is_active,
                               (SELECT COUNT(*) FROM event_bookings b WHERE b.event_type_id = t.event_type_id) AS booking_count
                          FROM event_types t " . ($activeOnly ? 'WHERE is_active = 1 ' : '') . "ORDER BY sort_order, event_type_name")->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------- Inclusions ---------- */

/** One package or bundle's inclusions as a list of strings. $kind is 'package' or 'bundle'. */
function jq_catalog_inclusions(PDO $pdo, string $kind, int $id): array
{
    [$table, $pk] = $kind === 'package' ? ['package_inclusions', 'package_id'] : ['bundle_inclusions', 'bundle_id'];
    $st = $pdo->prepare("SELECT inclusion_text FROM $table WHERE $pk = ? ORDER BY sort_order, inclusion_id");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** All inclusions of a catalogue table, grouped by owner id. */
function jq_all_inclusions(PDO $pdo, string $kind): array
{
    [$table, $pk] = $kind === 'package' ? ['package_inclusions', 'package_id'] : ['bundle_inclusions', 'bundle_id'];
    $out = [];
    foreach ($pdo->query("SELECT $pk AS owner, inclusion_text FROM $table ORDER BY sort_order, inclusion_id") as $r) {
        $out[(int)$r['owner']][] = $r['inclusion_text'];
    }
    return $out;
}

/**
 * Replaces a package's or bundle's inclusions from text with one inclusion per line.
 * Each line is linked to a food item when exactly one item name starts with it.
 * @return int inclusions saved
 */
function jq_save_catalog_inclusions(PDO $pdo, string $kind, int $id, string $text): int
{
    [$table, $pk] = $kind === 'package' ? ['package_inclusions', 'package_id'] : ['bundle_inclusions', 'bundle_id'];
    $lines = [];
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim(preg_replace('/^\s*[-*\x{2022}]\s*/u', '', $line));
        if ($line !== '' && !in_array(mb_strtolower($line), array_map('mb_strtolower', $lines), true)) {
            $lines[] = mb_substr($line, 0, 200);
        }
    }
    $pdo->prepare("DELETE FROM $table WHERE $pk = ?")->execute([$id]);
    $ins = $pdo->prepare("INSERT INTO $table ($pk, inclusion_text, sort_order) VALUES (?, ?, ?)");
    foreach ($lines as $i => $line) {
        $ins->execute([$id, $line, $i + 1]);
    }
    jq_v25_link_inclusions($pdo);
    return count($lines);
}

/** Copies what a booking includes right now. Later catalogue changes do not alter these rows. */
function jq_snapshot_booking_inclusions(PDO $pdo, int $bookingId, int $packageId, string $packageName, array $bundles): void
{
    $pdo->prepare("DELETE FROM booking_inclusions WHERE booking_id = ?")->execute([$bookingId]);
    $ins = $pdo->prepare("INSERT INTO booking_inclusions (booking_id, source_type, source_id, source_name, inclusion_text, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    foreach (jq_catalog_inclusions($pdo, 'package', $packageId) as $i => $text) {
        $ins->execute([$bookingId, 'package', $packageId, $packageName, $text, $i + 1]);
    }
    foreach ($bundles as $bundleId => $bundleName) {
        foreach (jq_catalog_inclusions($pdo, 'bundle', (int)$bundleId) as $i => $text) {
            $ins->execute([$bookingId, 'bundle', (int)$bundleId, $bundleName, $text, $i + 1]);
        }
    }
}

/** A booking's saved inclusions grouped as [source name => [text, ...]]. Holds no prices. */
function jq_booking_inclusions(PDO $pdo, int $bookingId): array
{
    $st = $pdo->prepare("SELECT source_type, source_name, inclusion_text FROM booking_inclusions WHERE booking_id = ? ORDER BY source_type = 'bundle', source_id, sort_order, booking_inclusion_id");
    $st->execute([$bookingId]);
    $out = [];
    foreach ($st as $r) {
        $out[$r['source_name']][] = $r['inclusion_text'];
    }
    return $out;
}

/* ---------- Booking lines and totals ---------- */

/**
 * Prices a booking from the database, never from the browser, and rewrites its line items and inclusions.
 * Call inside a transaction. A selected item that no longer exists is skipped.
 * @return array{base: float, upgrades: float, addons: float, subtotal: float, tax: float, total: float}
 */
function jq_save_booking_lines(PDO $pdo, int $bookingId, int $headcount, int $packageId, string $packageName, float $packagePrice, array $selectedItems, array $selectedBundles, array $itemQty, array $bundleQty): array
{
    $pdo->prepare("DELETE FROM booking_items WHERE booking_id = ?")->execute([$bookingId]);
    $ins = $pdo->prepare("INSERT INTO booking_items (booking_id, ref_type, ref_id, item_name, unit_price, price_mode, quantity, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $upgrades = 0.0;
    $itemSt = $pdo->prepare("SELECT item_id, item_name, upgrade_price FROM menu_items WHERE item_id = ? AND is_active = 1");
    foreach (array_unique(array_map('intval', $selectedItems)) as $id) {
        $itemSt->execute([$id]);
        if ($row = $itemSt->fetch(PDO::FETCH_ASSOC)) {
            $qty = max(1, (int)($itemQty[$id] ?? 1));
            $line = round((float)$row['upgrade_price'] * $headcount * $qty, 2);
            $ins->execute([$bookingId, 'item', $row['item_id'], $row['item_name'], $row['upgrade_price'], 'per_guest', $qty, $line]);
            $upgrades += $line;
        }
    }
    $addons = 0.0;
    $names = [];
    $bundleSt = $pdo->prepare("SELECT bundle_id, bundle_name, price, price_mode FROM menu_bundles WHERE bundle_id = ? AND is_active = 1");
    foreach (array_unique(array_map('intval', $selectedBundles)) as $id) {
        $bundleSt->execute([$id]);
        if ($row = $bundleSt->fetch(PDO::FETCH_ASSOC)) {
            $qty = max(1, (int)($bundleQty[$id] ?? 1));
            $unit = $row['price_mode'] === 'per_guest' ? (float)$row['price'] * $headcount : (float)$row['price'];
            $line = round($unit * $qty, 2);
            $ins->execute([$bookingId, 'bundle', $row['bundle_id'], $row['bundle_name'], $row['price'], $row['price_mode'], $qty, $line]);
            $addons += $line;
            $names[(int)$row['bundle_id']] = $row['bundle_name'];
        }
    }
    jq_snapshot_booking_inclusions($pdo, $bookingId, $packageId, $packageName, $names);

    $base = round($packagePrice * $headcount, 2);
    $subtotal = $base + $upgrades + $addons;
    $tax = round($subtotal * JQ_SERVICE_TAX_RATE / 100, 2);
    return ['base' => $base, 'upgrades' => $upgrades, 'addons' => $addons, 'subtotal' => $subtotal, 'tax' => $tax, 'total' => round($subtotal + $tax, 2)];
}

/* ---------- Payments ---------- */

/**
 * Payment figures for many bookings at once: [booking_id => [down, additional, paid, verified, no_receipt]].
 * paid is every recorded payment. verified is the part approved from a receipt. no_receipt counts payments with no proof yet.
 * A rejected payment stays on record but counts for nothing.
 */
function jq_payment_totals(PDO $pdo, array $bookingIds): array
{
    $ids = array_values(array_filter(array_map('intval', $bookingIds)));
    $out = array_fill_keys($ids, ['down' => 0.0, 'additional' => 0.0, 'paid' => 0.0, 'verified' => 0.0, 'no_receipt' => 0]);
    if (!$ids) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT booking_id, payment_type, verification_status, SUM(amount) AS total, COUNT(*) AS n FROM booking_payments WHERE booking_id IN ($in) AND verification_status <> 'Rejected' GROUP BY booking_id, payment_type, verification_status");
    $st->execute($ids);
    foreach ($st as $r) {
        $key = $r['payment_type'] === 'Downpayment' ? 'down' : 'additional';
        $out[(int)$r['booking_id']][$key] = (float)$r['total'];
        $out[(int)$r['booking_id']]['paid'] += (float)$r['total'];
        if ($r['verification_status'] === 'Verified') {
            $out[(int)$r['booking_id']]['verified'] += (float)$r['total'];
        } elseif ($r['verification_status'] === 'Awaiting Receipt') {
            $out[(int)$r['booking_id']]['no_receipt'] += (int)$r['n'];
        }
    }
    return $out;
}

/**
 * Fully Paid needs verified receipts covering the whole total: an amount alone is never enough.
 * Settled is Fully Paid on a completed event. $money comes from jq_payment_totals().
 */
function jq_payment_status(float $total, array $money, string $bookingStatus): string
{
    if ($money['paid'] <= 0) {
        return 'Unpaid';
    }
    if (round($total - $money['paid'], 2) > 0) {
        return 'Partially Paid';
    }
    if (round($total - $money['verified'], 2) > 0) {
        return $money['no_receipt'] > 0 ? 'Awaiting Proof of Payment' : 'Payment Pending Verification';
    }
    return $bookingStatus === 'Completed' ? 'Settled' : 'Fully Paid';
}

/**
 * Records a payment. The booking row is locked so two people cannot both pay the last balance.
 * @return array{ok: bool, message: string, path?: ?string}
 */
function jq_record_payment(PDO $pdo, int $bookingId, array $input, int $userId, ?array $receiptFile): array
{
    $type = (string)($input['payment_type'] ?? '');
    $method = (string)($input['payment_method'] ?? '');
    $date = (string)($input['payment_date'] ?? '');
    $rawAmount = trim((string)($input['amount'] ?? ''));
    $reference = mb_substr(trim((string)($input['reference_no'] ?? '')), 0, 100);
    $notes = mb_substr(trim((string)($input['notes'] ?? '')), 0, 255);

    if (!in_array($type, JQ_PAYMENT_TYPES, true)) {
        return ['ok' => false, 'message' => 'Choose a valid payment type.'];
    }
    if (!in_array($method, JQ_PAYMENT_METHODS, true)) {
        return ['ok' => false, 'message' => 'Choose a valid payment method.'];
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/D', $rawAmount) || (float)$rawAmount <= 0) {
        return ['ok' => false, 'message' => 'Enter an amount greater than zero, with at most two decimal places.'];
    }
    $amount = round((float)$rawAmount, 2);
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        return ['ok' => false, 'message' => 'Enter a valid payment date.'];
    }
    if ($date > date('Y-m-d')) {
        return ['ok' => false, 'message' => 'The payment date cannot be in the future.'];
    }

    $stored = null;
    if ($receiptFile && ($receiptFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $error = null;
        $stored = jq_store_private_file($receiptFile, $bookingId, 'receipt', $error);
        if ($stored === null) {
            return ['ok' => false, 'message' => $error];
        }
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT grand_total, status, is_archived FROM event_bookings WHERE booking_id = ? FOR UPDATE");
        $st->execute([$bookingId]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        $problem = null;
        if (!$b) {
            $problem = 'This booking no longer exists.';
        } elseif ((int)$b['is_archived'] === 1 || !in_array($b['status'], ['Pending', 'Approved', 'Completed'], true)) {
            $problem = 'Payments can only be recorded on a Pending, Approved or Completed booking.';
        } else {
            $paid = jq_payment_totals($pdo, [$bookingId])[$bookingId]['paid'];
            $balance = round((float)$b['grand_total'] - $paid, 2);
            if ($balance <= 0) {
                $problem = 'This booking has no remaining balance.';
            } elseif ($amount > $balance) {
                $problem = 'This payment is larger than the remaining balance of ' . "\u{20B1}" . number_format($balance, 2) . '.';
            }
        }
        if ($problem !== null) {
            $pdo->rollBack();
            if ($stored) {
                jq_file_delete($stored['path']);
            }
            return ['ok' => false, 'message' => $problem];
        }
        $pdo->prepare("INSERT INTO booking_payments (booking_id, payment_type, amount, payment_date, payment_method, reference_no, notes, receipt_path, receipt_name, receipt_mime, verification_status, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$bookingId, $type, $amount, $date, $method, $reference ?: null, $notes ?: null, $stored['path'] ?? null, $stored['name'] ?? null, $stored['mime'] ?? null, $stored ? 'Receipt Submitted' : 'Awaiting Receipt', $userId]);
        $pdo->commit();
        return ['ok' => true, 'message' => 'Payment of ' . "\u{20B1}" . number_format($amount, 2) . ' recorded. ' . ($stored ? 'Its receipt is waiting to be verified.' : 'It stays unconfirmed until a receipt is uploaded and verified.')];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($stored) {
            jq_file_delete($stored['path']);
        }
        error_log('record payment: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The payment could not be saved. Nothing was changed.'];
    }
}

/** Locks a payment and its booking for a change. Returns the payment, or a reason it cannot be changed. */
function jq_lock_open_payment(PDO $pdo, int $bookingId, int $paymentId, ?string &$problem): ?array
{
    $st = $pdo->prepare("SELECT status, is_archived FROM event_bookings WHERE booking_id = ? FOR UPDATE");
    $st->execute([$bookingId]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b || (int)$b['is_archived'] === 1 || !in_array($b['status'], ['Pending', 'Approved', 'Completed'], true)) {
        $problem = 'Receipts can only be handled on a Pending, Approved or Completed booking.';
        return null;
    }
    $st = $pdo->prepare("SELECT payment_id, verification_status, receipt_path FROM booking_payments WHERE payment_id = ? AND booking_id = ? FOR UPDATE");
    $st->execute([$paymentId, $bookingId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        $problem = 'This payment could not be found.';
        return null;
    }
    return $p;
}

/**
 * Adds the receipt to a payment that has none, or a replacement for a rejected one. The rejected receipt and its
 * review move to payment_receipt_history, so nothing that was ever uploaded is lost.
 * @return array{ok: bool, message: string}
 */
function jq_attach_receipt(PDO $pdo, int $bookingId, int $paymentId, ?array $file, int $userId = 0): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'Choose the receipt file to upload.'];
    }
    $error = null;
    $stored = jq_store_private_file($file, $bookingId, 'receipt', $error);
    if ($stored === null) {
        return ['ok' => false, 'message' => $error];
    }
    $pdo->beginTransaction();
    try {
        $problem = null;
        $p = jq_lock_open_payment($pdo, $bookingId, $paymentId, $problem);
        if ($p && !in_array($p['verification_status'], ['Awaiting Receipt', 'Rejected'], true)) {
            $problem = 'This payment already has a receipt under review or verified. Reject it first to ask for a replacement.';
        }
        if ($problem !== null) {
            $pdo->rollBack();
            jq_file_delete($stored['path']);
            return ['ok' => false, 'message' => $problem];
        }
        $replacing = $p['verification_status'] === 'Rejected';
        if ($replacing) {
            $pdo->prepare("INSERT INTO payment_receipt_history (payment_id, receipt_path, receipt_name, receipt_mime, verification_status, reviewed_by, reviewed_at, review_notes, replaced_by, replaced_at)
                            SELECT payment_id, receipt_path, receipt_name, receipt_mime, verification_status, reviewed_by, reviewed_at, review_notes, ?, NOW() FROM booking_payments WHERE payment_id = ?")
                ->execute([$userId ?: null, $paymentId]);
        }
        $pdo->prepare("UPDATE booking_payments SET receipt_path = ?, receipt_name = ?, receipt_mime = ?, verification_status = 'Receipt Submitted', reviewed_by = NULL, reviewed_at = NULL, review_notes = NULL WHERE payment_id = ?")
            ->execute([$stored['path'], $stored['name'], $stored['mime'], $paymentId]);
        $pdo->commit();
        return ['ok' => true, 'message' => $replacing ? 'Replacement receipt uploaded. The rejected one is kept on record. Open it to review.' : 'Receipt uploaded. Open it to review, then verify or reject it.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jq_file_delete($stored['path']);
        error_log('attach receipt: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The receipt could not be saved. Nothing was changed.'];
    }
}

/**
 * Approves or rejects a payment's receipt, recording who did it and when.
 * @return array{ok: bool, message: string}
 */
function jq_review_payment(PDO $pdo, int $bookingId, int $paymentId, bool $approve, string $reason, int $userId): array
{
    $reason = mb_substr(trim($reason), 0, 255);
    if (!$approve && $reason === '') {
        return ['ok' => false, 'message' => 'Enter the reason for rejecting this receipt.'];
    }
    $pdo->beginTransaction();
    try {
        $problem = null;
        $p = jq_lock_open_payment($pdo, $bookingId, $paymentId, $problem);
        if ($p && $approve) {
            if ($p['verification_status'] === 'Receipt Submitted') {
                $problem = 'Open the receipt and check it before verifying.';
            } elseif ($p['verification_status'] !== 'Pending Verification') {
                $problem = $p['verification_status'] === 'Awaiting Receipt' ? 'This payment has no receipt, so it cannot be verified.' : 'Only a receipt waiting for verification can be verified.';
            } elseif (!jq_file_exists($p['receipt_path'])) {
                $problem = 'The receipt file is missing, so this payment cannot be verified.';
            }
        } elseif ($p && !in_array($p['verification_status'], ['Receipt Submitted', 'Pending Verification', 'Verified'], true)) {
            $problem = 'Only a receipt on file that has not been rejected can be rejected.';
        }
        if ($problem !== null) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => $problem];
        }
        $pdo->prepare("UPDATE booking_payments SET verification_status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE payment_id = ?")
            ->execute([$approve ? 'Verified' : 'Rejected', $userId, $approve ? null : $reason, $paymentId]);
        $pdo->commit();
        return ['ok' => true, 'message' => $approve ? 'Payment verified.' : 'Receipt rejected and a replacement requested. It stays on record, and the amount no longer counts as paid until a replacement is verified.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('review payment: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The review could not be saved. Nothing was changed.'];
    }
}

/** Opening a submitted receipt moves it to Pending Verification. Called only from the Admin and Owner file view. */
function jq_mark_receipt_opened(PDO $pdo, int $paymentId): void
{
    $pdo->prepare("UPDATE booking_payments SET verification_status = 'Pending Verification'
                    WHERE payment_id = ? AND verification_status = 'Receipt Submitted'
                      AND booking_id IN (SELECT booking_id FROM event_bookings WHERE is_archived = 0)")->execute([$paymentId]);
}

/* ---------- Clients ---------- */

/** The existing client with exactly this name and phone or email, or null. Nothing is created. */
function jq_match_client(PDO $pdo, string $name, ?string $email, ?string $contact): ?int
{
    $st = $pdo->prepare("SELECT client_id FROM clients WHERE LOWER(TRIM(fullname)) = LOWER(TRIM(?)) AND ((contact IS NOT NULL AND contact = ?) OR (email IS NOT NULL AND email <> '' AND LOWER(email) = LOWER(?))) ORDER BY client_id LIMIT 1");
    $st->execute([$name, $contact, $email]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/**
 * Finds the client record for these details, or creates one. An explicitly chosen client wins.
 * Otherwise the same name with the same phone or email is the same person. The same phone under a
 * different name stays separate, because families share numbers.
 */
function jq_find_or_create_client(PDO $pdo, string $name, ?string $email, ?string $contact, ?string $postal, ?string $address, ?int $chosenId, int $userId): int
{
    if ($chosenId) {
        $st = $pdo->prepare("SELECT client_id FROM clients WHERE client_id = ?");
        $st->execute([$chosenId]);
        if ($st->fetchColumn()) {
            return $chosenId;
        }
    }
    $found = jq_match_client($pdo, $name, $email, $contact);
    if ($found) {
        return $found;
    }
    $pdo->prepare("INSERT INTO clients (fullname, email, contact, postal_code, address, created_by) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$name, $email ?: null, $contact ?: null, $postal ?: null, $address ?: null, $userId ?: null]);
    $id = jq_insert_id($pdo, 'clients', 'client_id');
    $pdo->prepare("UPDATE clients SET client_code = ? WHERE client_id = ?")->execute([sprintf('CL-%05d', $id), $id]);
    return $id;
}

/**
 * Marks an event Completed, saves the date, and connects the booking to its client record.
 * Payments are untouched: finishing an event does not mean it was paid for.
 * @return array{ok: bool, message: string}
 */
function jq_complete_booking(PDO $pdo, int $bookingId, int $userId): array
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT booking_ref, client_id, fullname, email, contact, postal_code, venue_address, status, is_archived, grand_total FROM event_bookings WHERE booking_id = ? FOR UPDATE");
        $st->execute([$bookingId]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b || (int)$b['is_archived'] === 1) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Only a booking that is not archived can be completed.'];
        }
        if ($b['status'] === 'Completed') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This event is already completed.'];
        }
        if (!in_array($b['status'], ['Pending', 'Approved'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'A ' . $b['status'] . ' booking cannot be completed.'];
        }
        // Completion needs the payment received, its receipts uploaded, and all of them verified.
        $total = (float)$b['grand_total'];
        $pay = jq_payment_status($total, jq_payment_totals($pdo, [$bookingId])[$bookingId], (string)$b['status']);
        if ($total > 0 && $pay !== 'Fully Paid') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This event cannot be completed until its full payment is received and every receipt is verified. Payment status: ' . $pay . '.'];
        }
        $clientId = jq_find_or_create_client($pdo, $b['fullname'], $b['email'], $b['contact'], $b['postal_code'], $b['venue_address'], $b['client_id'] ? (int)$b['client_id'] : null, $userId);
        $pdo->prepare("UPDATE event_bookings SET status = 'Completed', preparation_status = 'Completed', completed_at = NOW(), completed_by = ?, client_id = ? WHERE booking_id = ?")
            ->execute([$userId ?: null, $clientId, $bookingId]);
        $pdo->commit();
        return ['ok' => true, 'message' => 'Booking ' . $b['booking_ref'] . ' is completed and saved to Client Records.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('complete booking: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The booking could not be completed. Nothing was changed.'];
    }
}

/* ---------- Private files: receipts and attachments ---------- */

function jq_private_dir(int $bookingId): string
{
    if (jq_is_hosted()) {
        return 'uploads/private/' . $bookingId . '/'; // kept in the database, no folder needed
    }
    $root = __DIR__ . '/../uploads/private';
    if (!is_dir($root)) {
        mkdir($root, 0755, true);
    }
    if (!is_file($root . '/.htaccess')) {
        file_put_contents($root . '/.htaccess', "Require all denied\n");
    }
    $dir = $root . '/' . $bookingId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return 'uploads/private/' . $bookingId . '/';
}

/**
 * Validates and stores a receipt or attachment. Files are served only through event_bookings.php after
 * a role check, because the folder denies all direct requests.
 * @return array{path: string, name: string, mime: string, size: int}|null null with $error set
 */
function jq_store_private_file(array $file, int $bookingId, string $prefix, ?string &$error = null): ?array
{
    $codes = [UPLOAD_ERR_INI_SIZE => 'The file is larger than the server allows.', UPLOAD_ERR_FORM_SIZE => 'The file is too large.', UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.', UPLOAD_ERR_NO_FILE => 'Please choose a file.'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = $codes[$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'The file could not be uploaded because of a server problem.';
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid upload.';
        return null;
    }
    if ($file['size'] > JQ_PRIVATE_FILE_MAX_BYTES) {
        $error = 'The file must be 10 MB or smaller.';
        return null;
    }
    if ($file['size'] < 1) {
        $error = 'The file is empty.';
        return null;
    }
    $allowed = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];
    $name = basename((string)$file['name']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$ext]) || !in_array($mime, $allowed[$ext], true)) {
        $error = 'Only valid PDF, JPG, PNG, WebP, DOC or DOCX files are accepted.';
        return null;
    }
    if (strpos($mime, 'image/') === 0 && !@getimagesize($file['tmp_name'])) {
        $error = 'Only valid PDF, JPG, PNG, WebP, DOC or DOCX files are accepted.';
        return null;
    }
    if ($ext === 'docx' && file_get_contents($file['tmp_name'], false, null, 0, 2) !== 'PK') {
        $error = 'Only valid PDF, JPG, PNG, WebP, DOC or DOCX files are accepted.';
        return null;
    }
    $path = jq_private_dir($bookingId) . $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!jq_file_save_upload($file['tmp_name'], $path)) {
        $error = 'The file could not be saved. Please try again.';
        return null;
    }
    return ['path' => $path, 'name' => mb_substr($name, 0, 255), 'mime' => $mime, 'size' => (int)$file['size']];
}

/** Sends a stored file. Pictures and PDFs may open in the browser; anything else always downloads. */
function jq_send_private_file(string $path, string $name, string $mime, bool $inline): void
{
    $file = strpos($path, 'uploads/private/') === 0 ? jq_file_read($path) : null;
    if (!$file) {
        http_response_code(404);
        exit('File not found.');
    }
    $canShow = $inline && ($mime === 'application/pdf' || in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . ($canShow ? $mime : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    $safe = str_replace(['"', "\r", "\n"], '', $name);
    header('Content-Disposition: ' . ($canShow ? 'inline' : 'attachment') . '; filename="' . $safe . '"');
    header('Content-Length: ' . strlen($file['data']));
    echo $file['data'];
    exit;
}

/** Stored file paths of a booking, for removal after the booking is permanently deleted. */
function jq_booking_file_paths(PDO $pdo, int $bookingId): array
{
    $st = $pdo->prepare("SELECT stored_path FROM booking_attachments WHERE booking_id = ? UNION SELECT receipt_path FROM booking_payments WHERE booking_id = ? AND receipt_path IS NOT NULL
                          UNION SELECT h.receipt_path FROM payment_receipt_history h JOIN booking_payments p ON p.payment_id = h.payment_id WHERE p.booking_id = ?");
    $st->execute([$bookingId, $bookingId, $bookingId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function jq_remove_private_files(array $paths): void
{
    foreach ($paths as $path) {
        if (strpos((string)$path, 'uploads/private/') === 0 && strpos((string)$path, '..') === false) {
            jq_file_delete($path); // also removes the booking's folder once it is empty
        }
    }
}

/* ---------- Business details for the Statement of Account ---------- */

function jq_business_details(PDO $pdo): array
{
    return [
        'name'    => jq_setting_get($pdo, 'business_name', "Janet's Quality Catering Services"),
        'address' => jq_setting_get($pdo, 'business_address', ''),
        'phone'   => jq_setting_get($pdo, 'business_phone', ''),
        'email'   => jq_setting_get($pdo, 'business_email', ''),
    ];
}
