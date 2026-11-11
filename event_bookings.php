<?php
/**
 * EVENT BOOKINGS MODULE - DASHBOARD + WIZARD
 * Janet's Quality Catering - Complete event booking management
 * Integrated Dashboard with 4-Step Booking Wizard
 *
 * CHANGES IN THIS VERSION
 *  - Dashboard "Total" column is masked with asterisks (*) and has an eye icon to show / hide (privacy)
 *  - "Delete" was replaced by "Archive" (soft-delete, with an Archived view + Restore)
 *  - All buttons now use a professional, muted palette (navy / slate)
 *  - Statuses are Pending, Approved, Cancelled and Disabled. Only Pending and Approved count as active.
 *  - Archiving sets the status to Cancelled and schedules permanent deletion after 30 days
 *    (includes/archive_manager.php, run by cli/maintenance_cli.php)
 */

require_once __DIR__ . '/config/session.php';
date_default_timezone_set('Asia/Manila');
$page_title = "Event Bookings | Janet's Quality Catering";
$current_page = 'event_bookings';

require_once 'includes/auth_check.php';
require_once 'includes/archive_manager.php';
require_once 'includes/booking_manager.php';

$pdo = getDBConnection();
$viewer_role = jq_require_roles($pdo, ['ADMIN', 'OWNER'], 'Event Bookings');
$can_hide_money = $viewer_role === 'OWNER'; // only the Owner has the eye icon. An Admin always sees amounts.
$viewer_id = (int)$_SESSION['user_id'];

// ─────────────────────────────────────────────────────────────
// Ensure the archive columns exist (runs once, safe to repeat)
// ─────────────────────────────────────────────────────────────
try {
    // MySQL only: the PostgreSQL database is created complete.
    $col = jq_is_pg() ? true : $pdo->query("SHOW COLUMNS FROM event_bookings LIKE 'is_archived'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE event_bookings ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN archived_at DATETIME NULL");
    }
} catch (Exception $e) {
    // If the DB user cannot ALTER, run the SQL manually (see notes provided with this file)
}

// Receipts and attachments are stored where the web server refuses direct requests, and are sent only from here.
if (isset($_GET['file'], $_GET['id'])) {
    $kind = in_array($_GET['file'], ['receipt', 'old_receipt'], true) ? $_GET['file'] : 'attachment';
    $st = $pdo->prepare([
        'receipt'     => "SELECT receipt_path AS p, receipt_name AS n, receipt_mime AS m FROM booking_payments WHERE payment_id = ? AND receipt_path IS NOT NULL",
        'old_receipt' => "SELECT receipt_path AS p, receipt_name AS n, receipt_mime AS m FROM payment_receipt_history WHERE history_id = ?",
        'attachment'  => "SELECT stored_path AS p, original_name AS n, mime_type AS m FROM booking_attachments WHERE attachment_id = ?",
    ][$kind]);
    $st->execute([(int)$_GET['id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        exit('File not found.');
    }
    if ($kind === 'receipt') {
        jq_mark_receipt_opened($pdo, (int)$_GET['id']); // only Admins and the Owner reach this page
    }
    jq_send_private_file($row['p'], $row['n'], $row['m'], isset($_GET['view']));
}

// Actions added by the payments, attachments, preparation and event type features
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_action'])) {
    $act = (string)$_POST['booking_action'];
    $bid = (int)($_POST['booking_id'] ?? 0);
    $back = 'event_bookings.php';
    try {
        switch ($act) {
            case 'add_event_type':
            case 'rename_event_type':
                $back = 'event_bookings.php?types=1';
                if ($act === 'add_event_type') {
                    $added = jq_add_event_type($pdo, (string)($_POST['event_type_name'] ?? ''));
                    setFlash($added['existing'] ? 'That event type already exists.' : $added['message'], $added['ok'] && !$added['existing'] ? 'success' : 'danger');
                    break;
                }
                $name = jq_event_type_clean_name((string)($_POST['event_type_name'] ?? ''));
                if (($problem = jq_event_type_name_error($name)) !== null) {
                    setFlash($problem, 'danger');
                    break;
                }
                try {
                    // Bookings keep the name they were saved with, so past records do not change.
                    $pdo->prepare("UPDATE event_types SET event_type_name = ? WHERE event_type_id = ?")->execute([$name, (int)$_POST['event_type_id']]);
                    setFlash('Event type renamed.', 'success');
                } catch (PDOException $e) {
                    if (!jq_is_duplicate($e)) {
                        throw $e;
                    }
                    setFlash('That event type already exists.', 'danger');
                }
                break;

            case 'toggle_event_type':
                $back = 'event_bookings.php?types=1';
                $pdo->prepare("UPDATE event_types SET is_active = 1 - is_active WHERE event_type_id = ?")->execute([(int)$_POST['event_type_id']]);
                setFlash('Event type updated.', 'success');
                break;

            case 'delete_event_type':
                $back = 'event_bookings.php?types=1';
                $id = (int)$_POST['event_type_id'];
                $st = $pdo->prepare("SELECT COUNT(*) FROM event_bookings WHERE event_type_id = ?");
                $st->execute([$id]);
                if ((int)$st->fetchColumn() > 0) {
                    setFlash('This event type is used by bookings. Disable it instead, so those records stay intact.', 'danger');
                    break;
                }
                $pdo->prepare("DELETE FROM event_types WHERE event_type_id = ?")->execute([$id]);
                setFlash('Event type deleted.', 'success');
                break;

            case 'record_payment':
                $back = 'event_bookings.php?open=' . $bid . '&tab=payments';
                $res = jq_record_payment($pdo, $bid, $_POST, $viewer_id, $_FILES['receipt'] ?? null);
                setFlash($res['message'], $res['ok'] ? 'success' : 'danger');
                break;

            case 'delete_payment':
                $back = 'event_bookings.php?open=' . $bid . '&tab=payments';
                $st = $pdo->prepare("SELECT verification_status FROM booking_payments WHERE payment_id = ? AND booking_id = ?");
                $st->execute([(int)$_POST['payment_id'], $bid]);
                $review = $st->fetchColumn();
                if ($review === false) {
                    setFlash('This payment could not be found.', 'danger');
                    break;
                }
                // Receipts are kept for auditing, so only an entry with no receipt can be removed.
                if ($review !== 'Awaiting Receipt') {
                    setFlash('A payment with a receipt stays on record. Reject it instead.', 'danger');
                    break;
                }
                $pdo->prepare("DELETE FROM booking_payments WHERE payment_id = ? AND booking_id = ? AND verification_status = 'Awaiting Receipt'")->execute([(int)$_POST['payment_id'], $bid]);
                setFlash('Payment deleted. The balance was recalculated.', 'success');
                break;

            case 'upload_receipt':
                $back = 'event_bookings.php?open=' . $bid . '&tab=payments';
                $res = jq_attach_receipt($pdo, $bid, (int)($_POST['payment_id'] ?? 0), $_FILES['receipt'] ?? null, $viewer_id);
                setFlash($res['message'], $res['ok'] ? 'success' : 'danger');
                break;

            case 'verify_payment':
            case 'reject_payment':
                $back = 'event_bookings.php?open=' . $bid . '&tab=payments';
                $res = jq_review_payment($pdo, $bid, (int)($_POST['payment_id'] ?? 0), $act === 'verify_payment', (string)($_POST['review_notes'] ?? ''), $viewer_id);
                setFlash($res['message'], $res['ok'] ? 'success' : 'danger');
                break;

            case 'upload_attachment':
                $back = 'event_bookings.php?open=' . $bid . '&tab=attachments';
                $type = (string)($_POST['document_type'] ?? 'Other');
                $st = $pdo->prepare("SELECT is_archived FROM event_bookings WHERE booking_id = ?");
                $st->execute([$bid]);
                $archived = $st->fetchColumn();
                if ($archived === false || (int)$archived === 1) {
                    setFlash('Attachments can only be added to a booking that is not archived.', 'danger');
                    break;
                }
                if (!in_array($type, JQ_DOCUMENT_TYPES, true)) {
                    setFlash('Choose a valid document type.', 'danger');
                    break;
                }
                $error = null;
                $stored = jq_store_private_file($_FILES['attachment'] ?? [], $bid, 'doc', $error);
                if ($stored === null) {
                    setFlash($error, 'danger');
                    break;
                }
                $replace = (int)($_POST['replace_id'] ?? 0);
                $oldPath = false;
                if ($replace > 0) {
                    $st = $pdo->prepare("SELECT stored_path FROM booking_attachments WHERE attachment_id = ? AND booking_id = ?");
                    $st->execute([$replace, $bid]);
                    $oldPath = $st->fetchColumn();
                }
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO booking_attachments (booking_id, document_type, original_name, stored_path, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$bid, $type, $stored['name'], $stored['path'], $stored['mime'], $stored['size'], $viewer_id]);
                if ($oldPath !== false) {
                    $pdo->prepare("DELETE FROM booking_attachments WHERE attachment_id = ?")->execute([$replace]);
                }
                $pdo->commit();
                if ($oldPath !== false) {
                    jq_remove_private_files([$oldPath]);
                }
                setFlash($oldPath !== false ? 'Attachment replaced.' : 'Attachment uploaded.', 'success');
                break;

            case 'delete_attachment':
                $back = 'event_bookings.php?open=' . $bid . '&tab=attachments';
                $st = $pdo->prepare("SELECT stored_path FROM booking_attachments WHERE attachment_id = ? AND booking_id = ?");
                $st->execute([(int)$_POST['attachment_id'], $bid]);
                $path = $st->fetchColumn();
                if ($path === false) {
                    setFlash('This attachment could not be found.', 'danger');
                    break;
                }
                $pdo->prepare("DELETE FROM booking_attachments WHERE attachment_id = ?")->execute([(int)$_POST['attachment_id']]);
                jq_remove_private_files([$path]);
                setFlash('Attachment deleted.', 'success');
                break;

            case 'complete_booking':
                $res = jq_complete_booking($pdo, $bid, $viewer_id);
                setFlash($res['message'], $res['ok'] ? 'success' : 'danger');
                break;

            case 'update_preparation':
                $back = 'event_bookings.php?open=' . $bid . '&tab=preparation';
                $prep = (string)($_POST['preparation_status'] ?? '');
                if (!in_array($prep, JQ_PREPARATION_STATUSES, true)) {
                    setFlash('Choose a valid preparation status.', 'danger');
                    break;
                }
                $pdo->prepare("UPDATE event_bookings SET preparation_status = ?, preparation_notes = ? WHERE booking_id = ? AND is_archived = 0")
                    ->execute([$prep, mb_substr(trim((string)($_POST['preparation_notes'] ?? '')), 0, 2000) ?: null, $bid]);
                setFlash('Preparation details saved.', 'success');
                break;

            case 'save_business':
                $back = 'event_bookings.php?soa=' . $bid;
                foreach (['business_name' => 150, 'business_address' => 255, 'business_phone' => 60, 'business_email' => 120] as $key => $max) {
                    jq_setting_set($pdo, $key, mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max), $viewer_id);
                }
                setFlash('Business details saved.', 'success');
                break;

            default:
                setFlash('Unknown action.', 'warning');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('event_bookings.php ' . $act . ': ' . $e->getMessage());
        setFlash('The change could not be saved. Nothing was changed.', 'danger');
    }
    header('Location: ' . $back);
    exit;
}

// Statement of Account, for this one booking
if (isset($_GET['soa'])) {
    $soa_id = (int)$_GET['soa'];
    $st = $pdo->prepare("SELECT * FROM event_bookings WHERE booking_id = ?");
    $st->execute([$soa_id]);
    $soa = $st->fetch(PDO::FETCH_ASSOC);
    if (!$soa) {
        setFlash('This booking could not be found.', 'danger');
        header('Location: event_bookings.php');
        exit;
    }
    $st = $pdo->prepare("SELECT item_name, ref_type, unit_price, price_mode, quantity, line_total FROM booking_items WHERE booking_id = ? ORDER BY ref_type, id");
    $st->execute([$soa_id]);
    $soa_items = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT payment_type, amount, payment_date, payment_method, reference_no, verification_status FROM booking_payments WHERE booking_id = ? AND verification_status <> 'Rejected' ORDER BY payment_date, payment_id");
    $st->execute([$soa_id]);
    $soa_payments = $st->fetchAll(PDO::FETCH_ASSOC);
    $soa_inclusions = jq_booking_inclusions($pdo, $soa_id);
    $soa_totals = jq_payment_totals($pdo, [$soa_id])[$soa_id];
    $soa_business = jq_business_details($pdo);
    $soa_flash = getFlash();
    require 'includes/_soa_view.php';
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $ajax_action = sanitize($_POST['ajax_action']);

    if ($ajax_action === 'get_provinces') {
        $region_code = sanitize($_POST['region_code'] ?? '');
        $stmt = $pdo->prepare("SELECT id, province_code, province_name FROM psgc_provinces WHERE region_code = ? ORDER BY province_name ASC");
        $stmt->execute([$region_code]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($ajax_action === 'get_municipalities') {
        $province_code = sanitize($_POST['province_code'] ?? '');
        $stmt = $pdo->prepare("SELECT id, municipality_code, municipality_name FROM psgc_municipalities WHERE province_code = ? ORDER BY municipality_name ASC");
        $stmt->execute([$province_code]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($ajax_action === 'get_barangays') {
        $municipality_code = sanitize($_POST['municipality_code'] ?? '');
        $stmt = $pdo->prepare("SELECT id, barangay_code, barangay_name FROM psgc_barangays WHERE municipality_code = ? ORDER BY barangay_name ASC");
        $stmt->execute([$municipality_code]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // "Others" in the booking form: same save as the Add Event Type button, without leaving the form.
    if ($ajax_action === 'add_event_type') {
        try {
            echo json_encode(jq_add_event_type($pdo, (string)($_POST['event_type_name'] ?? '')));
        } catch (Throwable $e) {
            error_log('add_event_type: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'existing' => false, 'name' => '', 'message' => 'The event type could not be saved. Please try again.']);
        }
        exit;
    }

    if ($ajax_action === 'get_backdrops') {
        $event_type = sanitize($_POST['event_type'] ?? '');
        $event_type_to_folder = [
            'Wedding'          => 'Weddings',
            'Birthday'         => 'Birthdays',
            'Debut'            => 'Debut',
            'Kiddie Birthday'  => 'Kiddie Birthday',
            'Christmas Party'  => 'Christmas Party',
            'Corporate Event'  => 'Parties',
            'Gala Dinner'      => 'Parties',
            'Graduation'       => 'Parties',
            'Seminar'          => 'Seminar',
            'Anniversary'      => 'WeddingAnniv',
            'Renewal of Vows'  => 'RenewalofVows',
            'General Party'    => 'Parties',
        ];
        $folder = $event_type_to_folder[$event_type] ?? null;
        $backdrops_result = [];
        if ($folder) {
            $path = "static/images/" . $folder;
            if (is_dir($path)) {
                $files = scandir($path);
                $idx = 1;
                foreach ($files as $file) {
                    if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) {
                        $name = ucwords(str_replace(['-', '_'], ' ', pathinfo($file, PATHINFO_FILENAME)));
                        $backdrops_result[] = [
                            'id'            => $idx,
                            'backdrop_name' => $name,
                            'image_path'    => "static/images/" . $folder . "/" . $file,
                            'description'   => $name . ' backdrop',
                            'source'        => 'folder',
                        ];
                        $idx++;
                    }
                }
            }
        }
        if (empty($backdrops_result)) {
            try {
                $stmt = $pdo->prepare("SELECT id, backdrop_name, image_path, description FROM backdrops WHERE event_type = ? AND is_active = 1 ORDER BY backdrop_name ASC");
                $stmt->execute([$event_type]);
                $backdrops_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $backdrops_result = [];
            }
        }
        // A custom event type has no gallery of its own. A backdrop is required, so offer every one.
        if (empty($backdrops_result) && !$folder) {
            $seen = [];
            foreach (array_unique(array_values($event_type_to_folder)) as $any_folder) {
                foreach (glob('static/images/' . $any_folder . '/*') ?: [] as $image) {
                    if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $image) && !isset($seen[$image])) {
                        $seen[$image] = true;
                        $label = ucwords(str_replace(['-', '_'], ' ', pathinfo($image, PATHINFO_FILENAME)));
                        $backdrops_result[] = ['id' => count($seen), 'backdrop_name' => $label, 'image_path' => $image, 'description' => $label . ' backdrop', 'source' => 'folder'];
                    }
                }
            }
        }
        echo json_encode($backdrops_result);
        exit;
    }

    if ($ajax_action === 'get_packages') {
        $event_type = sanitize($_POST['event_type'] ?? '');
        // A package with no event types listed is offered for every event type.
        $stmt = $pdo->prepare("SELECT p.package_id, p.package_name, p.tier, p.price_per_guest, p.pricing_note, p.icon
                                 FROM menu_packages p
                                WHERE p.is_active = 1
                                  AND (NOT EXISTS (SELECT 1 FROM package_event_types x WHERE x.package_id = p.package_id)
                                       OR EXISTS (SELECT 1 FROM package_event_types x JOIN event_types t ON t.event_type_id = x.event_type_id WHERE x.package_id = p.package_id AND t.event_type_name = ?))
                                ORDER BY p.sort_order ASC, p.package_name ASC");
        $stmt->execute([$event_type]);
        $packages_json = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $inclusions = jq_all_inclusions($pdo, 'package');
        foreach ($packages_json as &$row) {
            $row['inclusions'] = $inclusions[(int)$row['package_id']] ?? [];
        }
        unset($row);
        echo json_encode($packages_json);
        exit;
    }

    if ($ajax_action === 'get_menu_data') {
        $result = ['bundles' => [], 'items' => []];
        try {
            $stmt = $pdo->query("SELECT bundle_id, bundle_name, order_note, bundle_type, price, price_mode, guests_covered FROM menu_bundles WHERE is_active = 1 ORDER BY bundle_type ASC, bundle_name ASC");
            $result['bundles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $bundle_inclusions = jq_all_inclusions($pdo, 'bundle');
            foreach ($result['bundles'] as &$row) {
                $row['inclusions'] = $bundle_inclusions[(int)$row['bundle_id']] ?? [];
            }
            unset($row);
        } catch (Exception $e) { $result['bundles'] = []; }
        try {
            $stmt = $pdo->query("SELECT item_id, item_name, course, description, availability_note, upgrade_price FROM menu_items WHERE is_active = 1 ORDER BY course ASC, item_name ASC");
            $result['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $result['items'] = []; }
        echo json_encode($result);
        exit;
    }

    if ($ajax_action === 'get_booking') {
        $booking_id = (int)($_POST['booking_id'] ?? 0);
        if ($booking_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM event_bookings WHERE booking_id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($booking) {
                $items = $pdo->prepare("SELECT ref_type, item_name, unit_price, price_mode, quantity, line_total FROM booking_items WHERE booking_id = ? ORDER BY ref_type, id");
                $items->execute([$booking_id]);
                $booking['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
                $booking['inclusions'] = jq_booking_inclusions($pdo, $booking_id);
                $pays = $pdo->prepare("SELECT p.payment_id, p.payment_type, p.amount, p.payment_date, p.payment_method, p.reference_no, p.notes, p.receipt_name, p.verification_status, p.reviewed_at, p.review_notes, p.created_at, u.username AS recorded_by_name, r.username AS reviewed_by_name
                                         FROM booking_payments p LEFT JOIN users u ON u.id = p.recorded_by LEFT JOIN users r ON r.id = p.reviewed_by WHERE p.booking_id = ? ORDER BY p.payment_date, p.payment_id");
                $pays->execute([$booking_id]);
                $booking['payments'] = $pays->fetchAll(PDO::FETCH_ASSOC);
                $old = $pdo->prepare("SELECT h.history_id, h.payment_id, h.receipt_name, h.verification_status, h.reviewed_at, h.review_notes, h.replaced_at, r.username AS reviewed_by_name
                                        FROM payment_receipt_history h JOIN booking_payments p ON p.payment_id = h.payment_id LEFT JOIN users r ON r.id = h.reviewed_by
                                       WHERE p.booking_id = ? ORDER BY h.history_id");
                $old->execute([$booking_id]);
                $booking['old_receipts'] = $old->fetchAll(PDO::FETCH_ASSOC);
                $docs = $pdo->prepare("SELECT a.attachment_id, a.document_type, a.original_name, a.mime_type, a.file_size, a.created_at, u.username AS uploaded_by_name
                                         FROM booking_attachments a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.booking_id = ? ORDER BY a.attachment_id");
                $docs->execute([$booking_id]);
                $booking['attachments'] = $docs->fetchAll(PDO::FETCH_ASSOC);
                $money = jq_payment_totals($pdo, [$booking_id])[$booking_id];
                $booking['money'] = $money + [
                    'balance' => round((float)$booking['grand_total'] - $money['paid'], 2),
                    'status'  => jq_payment_status((float)$booking['grand_total'], $money, (string)$booking['status']),
                ];
                // Internal record and user ids are not needed by the page, so they are not sent.
                foreach (['client_id', 'event_type_id', 'backdrop_id', 'package_id', 'created_by', 'completed_by', 'archived_by', 'restored_by', 'pre_archive_status'] as $internal) {
                    unset($booking[$internal]);
                }
            }
            echo json_encode($booking ?: ['error' => 'Booking not found']);
        }
        exit;
    }

    // AJAX: find an existing client for the booking form
    if ($ajax_action === 'search_clients') {
        $term = trim((string)($_POST['term'] ?? ''));
        if (mb_strlen($term) < 2) {
            echo json_encode([]);
            exit;
        }
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $st = $pdo->prepare("SELECT c.client_id, c.client_code, c.fullname, c.email, c.contact, c.postal_code,
                                     (SELECT COUNT(*) FROM event_bookings b WHERE b.client_id = c.client_id) AS booking_count
                                FROM clients c WHERE c.fullname LIKE ? OR c.contact LIKE ? OR c.email LIKE ? OR c.client_code LIKE ?
                               ORDER BY c.fullname LIMIT 10");
        $st->execute([$like, $like, $like, $like]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // AJAX: Update status inline
    if ($ajax_action === 'update_status_inline') {
        $booking_id = (int)($_POST['booking_id'] ?? 0);
        $status = sanitize($_POST['status'] ?? '');
        $allowed = ['Pending', 'Approved', 'Cancelled', 'Disabled'];
        if ($booking_id > 0 && in_array($status, $allowed, true)) {
            try {
                $stmt = $pdo->prepare("SELECT event_date, event_time, venue_address, status FROM event_bookings WHERE booking_id = ? AND is_archived = 0");
                $stmt->execute([$booking_id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$current) {
                    echo json_encode(['success' => false, 'error' => 'An archived booking cannot change status. Restore it first.']);
                    exit;
                }
                if ($current['status'] === 'Completed') {
                    echo json_encode(['success' => false, 'error' => 'A completed event keeps its status.']);
                    exit;
                }
                // Only Pending and Approved bookings hold a slot, so becoming active must not double-book it.
                if (in_array($status, ['Pending', 'Approved'], true)) {
                    $dupe = $pdo->prepare("SELECT COUNT(*) FROM event_bookings WHERE event_date = ? AND event_time = ? AND venue_address = ? AND booking_id <> ? AND is_archived = 0 AND status IN ('Pending','Approved')");
                    $dupe->execute([$current['event_date'], $current['event_time'], $current['venue_address'], $booking_id]);
                    if ((int)$dupe->fetchColumn() > 0) {
                        echo json_encode(['success' => false, 'error' => 'Another active booking already holds this venue, date and time.']);
                        exit;
                    }
                }
                $pdo->prepare("UPDATE event_bookings SET status = ? WHERE booking_id = ? AND is_archived = 0")->execute([$status, $booking_id]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                error_log('update_status_inline: ' . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'The status could not be changed.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        }
exit;
    }
}

/**
 * Reads and validates the wizard form. Prices are taken from the catalogue on the server.
 * @return array{0: array, 1: string[]} the cleaned values and any errors
 */
function eb_read_booking_form(PDO $pdo, int $bookingId): array
{
    $v = [
        'fullname'      => jq_clean_name($_POST['fullname'] ?? ''),
        'postal_code'   => sanitize($_POST['postal_code'] ?? ''),
        'email'         => sanitize($_POST['email'] ?? ''),
        'contact'       => jq_normalize_phone($_POST['contact'] ?? ''),
        'event_name'    => sanitize($_POST['event_name'] ?? ''),
        'event_type'    => sanitize($_POST['event_type'] ?? ''),
        'event_date'    => sanitize($_POST['event_date'] ?? ''),
        'event_time'    => sanitize($_POST['event_time'] ?? ''),
        'headcount'     => (int)($_POST['headcount'] ?? 0),
        'region'        => sanitize($_POST['region_name'] ?? ''),
        'province'      => sanitize($_POST['province_name'] ?? ''),
        'city'          => sanitize($_POST['municipality_name'] ?? ''),
        'barangay'      => sanitize($_POST['barangay_name'] ?? ''),
        'venue_address' => sanitize($_POST['venue_address'] ?? ''),
        'notes'         => mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 2000),
        'backdrop_id'   => (int)($_POST['backdrop_id'] ?? 0),
        'backdrop_name' => sanitize($_POST['backdrop_name'] ?? ''),
        'backdrop_path' => sanitize($_POST['backdrop_path'] ?? ''),
        'package_id'    => (int)($_POST['package_id'] ?? 0),
        'client_id'     => (int)($_POST['client_id'] ?? 0),
    ];
    $e = [];
    if (($m = jq_name_error($v['fullname'], 'Full name', true, 255)) !== null) $e[] = $m;
    if (!$v['email'] || !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $e[] = 'A valid email is required.';
    if ($v['contact'] === null) $e[] = 'Contact number is required.';
    elseif ($v['contact'] === false) $e[] = JQ_PHONE_MESSAGE;
    if (($m = jq_postal_error($v['postal_code'])) !== null) $e[] = $m;
    if (!$v['event_name']) $e[] = 'Event name is required.';
    if (!$v['event_time']) $e[] = 'Event time is required.';
    if ($v['headcount'] <= 0) $e[] = 'Number of guests must be greater than 0.';
    if (!$v['region'] || !$v['province'] || !$v['city'] || !$v['barangay']) $e[] = 'A complete venue location is required.';
    if (!$v['venue_address']) $e[] = 'Venue address is required.';
    if ($v['backdrop_id'] <= 0 || !$v['backdrop_name']) $e[] = 'Backdrop selection is required.';
    // The path is shown as a picture, so only the system's own image folders are accepted.
    if ($v['backdrop_path'] !== '' && (strpos($v['backdrop_path'], '..') !== false || !preg_match('~^(static/images|uploads)/~', $v['backdrop_path']))) {
        $v['backdrop_path'] = '';
    }

    $stored = null;
    if ($bookingId > 0) {
        $st = $pdo->prepare("SELECT event_date, headcount, event_type_id, package_id, package_name, package_price, is_archived FROM event_bookings WHERE booking_id = ?");
        $st->execute([$bookingId]);
        $stored = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$stored) $e[] = 'This booking could not be found.';
        elseif ((int)$stored['is_archived'] === 1) $e[] = 'An archived booking cannot be edited. Restore it first.';
    }

    // Event type must be a real record. An inactive type is accepted only if the booking already has it.
    $st = $pdo->prepare("SELECT event_type_id, is_active FROM event_types WHERE event_type_name = ?");
    $st->execute([$v['event_type']]);
    $type = $st->fetch(PDO::FETCH_ASSOC);
    if (!$type || (!(int)$type['is_active'] && !($stored && (int)$stored['event_type_id'] === (int)$type['event_type_id']))) {
        $e[] = 'Choose a valid event type.';
    }
    $v['event_type_id'] = $type ? (int)$type['event_type_id'] : null;

    // A saved booking keeps the package name and price it was made with, unless the package is changed.
    if ($stored && (int)$stored['package_id'] === $v['package_id'] && $v['package_id'] > 0) {
        $v['package_name'] = $stored['package_name'];
        $v['package_price'] = (float)$stored['package_price'];
    } else {
        $st = $pdo->prepare("SELECT package_name, price_per_guest FROM menu_packages WHERE package_id = ? AND is_active = 1");
        $st->execute([$v['package_id']]);
        $pkg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pkg) $e[] = 'Package selection is required.';
        $v['package_name'] = $pkg['package_name'] ?? '';
        $v['package_price'] = (float)($pkg['price_per_guest'] ?? 0);
    }

    $d = DateTime::createFromFormat('Y-m-d', $v['event_date']);
    if (!$d || $d->format('Y-m-d') !== $v['event_date']) {
        $e[] = 'A valid event date is required.';
    } elseif ($v['headcount'] > 0) {
        // Preparation time: 2 days for 1-99 guests, 5 days for 100 or more, counted from today.
        // A saved booking that keeps its date and guest count is not re-checked, so it can still be edited.
        $unchanged = $stored && $stored['event_date'] === $v['event_date'] && (int)$stored['headcount'] === $v['headcount'];
        if (!$unchanged && ($m = jq_event_date_error($v['event_date'], $v['headcount'], date('Y-m-d'))) !== null) {
            $e[] = $m;
        }
    }

    // Downpayment entered on Step 4. Whether it reaches 50% is checked once the total is priced.
    $v['dp_amount'] = 0.0;
    $raw = trim((string)($_POST['dp_amount'] ?? ''));
    if ($raw !== '' && (float)$raw > 0) {
        if (!preg_match('/^\d+(\.\d{1,2})?$/D', $raw)) {
            $e[] = 'Enter the downpayment as an amount with at most two decimal places.';
        } else {
            $v['dp_amount'] = round((float)$raw, 2);
        }
        $v['dp_method'] = (string)($_POST['dp_method'] ?? '');
        if (!in_array($v['dp_method'], JQ_PAYMENT_METHODS, true)) $e[] = 'Choose how the downpayment was paid.';
        $v['dp_date'] = (string)($_POST['dp_date'] ?? '');
        $pd = DateTime::createFromFormat('Y-m-d', $v['dp_date']);
        if (!$pd || $pd->format('Y-m-d') !== $v['dp_date']) $e[] = 'Enter a valid downpayment date.';
        elseif ($v['dp_date'] > date('Y-m-d')) $e[] = 'The downpayment date cannot be in the future.';
        $v['dp_reference'] = mb_substr(trim((string)($_POST['dp_reference'] ?? '')), 0, 100);
    }

    if (!$e) {
        $dupe = $pdo->prepare("SELECT COUNT(*) FROM event_bookings WHERE event_date = ? AND event_time = ? AND venue_address = ? AND booking_id <> ? AND is_archived = 0 AND status IN ('Pending','Approved')");
        $dupe->execute([$v['event_date'], $v['event_time'], $v['venue_address'], $bookingId]);
        if ((int)$dupe->fetchColumn() > 0) {
            $e[] = 'Invalid booking: another event is already booked at this venue on the same date and time.';
        }
    }
    return [$v, $e];
}

/** The client this booking belongs to: the one chosen on the form, or an exact match. New clients are created on completion. */
function eb_resolve_client(PDO $pdo, array $v): ?int
{
    $id = null;
    if ($v['client_id'] > 0) {
        $st = $pdo->prepare("SELECT client_id FROM clients WHERE client_id = ?");
        $st->execute([$v['client_id']]);
        $id = $st->fetchColumn() ? $v['client_id'] : null;
    }
    if ($id && isset($_POST['update_client'])) {
        $pdo->prepare("UPDATE clients SET fullname = ?, email = ?, contact = ?, postal_code = ? WHERE client_id = ?")
            ->execute([$v['fullname'], $v['email'], $v['contact'], $v['postal_code'], $id]);
    }
    return $id ?: jq_match_client($pdo, $v['fullname'], $v['email'], $v['contact']);
}

// Handle WIZARD submit: new booking or edit. The booking, lines and inclusions are saved together or not at all.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save_booking', 'update_full_booking'], true)) {
    $is_update = $_POST['action'] === 'update_full_booking';
    $booking_id = $is_update ? (int)($_POST['booking_id'] ?? 0) : 0;
    if ($is_update && $booking_id <= 0) {
        setFlash('Invalid booking.', 'danger');
        header('Location: event_bookings.php');
        exit;
    }
    [$v, $errors] = eb_read_booking_form($pdo, $booking_id);
    if ($errors) {
        setFlash(implode(' ', $errors), 'danger');
        header('Location: event_bookings.php' . ($is_update ? '?view=wizard&edit_id=' . $booking_id : ''));
        exit;
    }
    $fields = [$v['fullname'], $v['email'], $v['contact'], $v['postal_code'], $v['region'], $v['province'], $v['city'], $v['barangay'],
               $v['event_name'], $v['event_type'], $v['event_type_id'], $v['event_date'], $v['event_time'], $v['headcount'], $v['venue_address'], $v['notes'] ?: null,
               $v['backdrop_id'], $v['backdrop_name'], $v['backdrop_path'] ?: null, $v['package_id'], $v['package_name'], $v['package_price']];
    $problem = null;
    $ref = '';
    $dp_receipt = null;
    try {
        $pdo->beginTransaction();
        $client_id = eb_resolve_client($pdo, $v);
        if ($is_update) {
            $pdo->prepare("SELECT booking_id FROM event_bookings WHERE booking_id = ? FOR UPDATE")->execute([$booking_id]);
            $pdo->prepare("UPDATE event_bookings SET fullname = ?, email = ?, contact = ?, postal_code = ?, region = ?, province = ?, city = ?, barangay = ?,
                                  event_name = ?, event_type = ?, event_type_id = ?, event_date = ?, event_time = ?, headcount = ?, venue_address = ?, notes = ?,
                                  backdrop_id = ?, backdrop_name = ?, backdrop_path = ?, package_id = ?, package_name = ?, package_price = ?, client_id = ?
                            WHERE booking_id = ?")->execute(array_merge($fields, [$client_id, $booking_id]));
        } else {
            $ref = 'BK' . date('YmdHis') . substr(str_shuffle('0123456789'), 0, 4);
            $pdo->prepare("INSERT INTO event_bookings (fullname, email, contact, postal_code, region, province, city, barangay,
                                  event_name, event_type, event_type_id, event_date, event_time, headcount, venue_address, notes,
                                  backdrop_id, backdrop_name, backdrop_path, package_id, package_name, package_price, client_id, booking_ref, status, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)")
                ->execute(array_merge($fields, [$client_id, $ref, $viewer_id]));
            $booking_id = jq_insert_id($pdo, 'event_bookings', 'booking_id');
        }
        $totals = jq_save_booking_lines($pdo, $booking_id, $v['headcount'], $v['package_id'], $v['package_name'], $v['package_price'],
            (array)($_POST['selected_items'] ?? []), (array)($_POST['selected_bundles'] ?? []), (array)($_POST['item_qty'] ?? []), (array)($_POST['bundle_qty'] ?? []));
        $paid = jq_payment_totals($pdo, [$booking_id])[$booking_id]['paid'];
        $required = jq_required_downpayment($totals['total']);
        $peso = static fn(float $n) => "\u{20B1}" . number_format($n, 2);
        if ($totals['total'] < $paid) {
            $problem = 'This change would make the total (' . $peso($totals['total']) . ') lower than the amount already paid (' . $peso($paid) . '). Nothing was changed.';
        } elseif ($v['dp_amount'] > round($totals['total'] - $paid, 2)) {
            $problem = 'The downpayment of ' . $peso($v['dp_amount']) . ' is larger than the remaining balance of ' . $peso($totals['total'] - $paid) . '. Nothing was saved.';
        } elseif (round($paid + $v['dp_amount'], 2) < $required) {
            // Mandatory 50% downpayment, counted from payments on record (rejected receipts excluded) plus this one.
            $problem = 'A downpayment of at least ' . JQ_DOWNPAYMENT_PERCENT . '% is required to book this event: ' . $peso($required) . ' of the ' . $peso($totals['total']) . ' total'
                . ($paid > 0 ? ' (' . $peso($paid) . ' is already paid, so at least ' . $peso($required - $paid) . ' more is needed)' : '') . '. Nothing was saved.';
        }
        if ($problem !== null) {
            $pdo->rollBack();
        } else {
            $pdo->prepare("UPDATE event_bookings SET base_total = ?, menu_upgrade_total = ?, addon_total = ?, subtotal = ?, service_tax_rate = ?, service_tax = ?, grand_total = ? WHERE booking_id = ?")
                ->execute([$totals['base'], $totals['upgrades'], $totals['addons'], $totals['subtotal'], JQ_SERVICE_TAX_RATE, $totals['tax'], $totals['total'], $booking_id]);
            if ($v['dp_amount'] > 0) {
                $file = $_FILES['dp_receipt'] ?? null;
                if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $fileError = null;
                    $dp_receipt = jq_store_private_file($file, $booking_id, 'receipt', $fileError);
                    if ($dp_receipt === null) {
                        throw new RuntimeException('receipt: ' . $fileError);
                    }
                }
                $hasDown = (int)$pdo->query("SELECT COUNT(*) FROM booking_payments WHERE booking_id = " . (int)$booking_id . " AND payment_type = 'Downpayment' AND verification_status <> 'Rejected'")->fetchColumn() > 0;
                $pdo->prepare("INSERT INTO booking_payments (booking_id, payment_type, amount, payment_date, payment_method, reference_no, receipt_path, receipt_name, receipt_mime, verification_status, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$booking_id, $hasDown ? 'Additional Payment' : 'Downpayment', $v['dp_amount'], $v['dp_date'], $v['dp_method'], $v['dp_reference'] ?: null,
                               $dp_receipt['path'] ?? null, $dp_receipt['name'] ?? null, $dp_receipt['mime'] ?? null, isset($dp_receipt) ? 'Receipt Submitted' : 'Awaiting Receipt', $viewer_id]);
            }
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!empty($dp_receipt['path'])) {
            jq_remove_private_files([$dp_receipt['path']]);
        }
        if (str_starts_with($e->getMessage(), 'receipt: ')) {
            setFlash('The downpayment receipt could not be saved: ' . substr($e->getMessage(), 9) . ' Nothing was saved.', 'danger');
            header('Location: event_bookings.php' . ($is_update ? '?view=wizard&edit_id=' . $booking_id : '?view=wizard'));
            exit;
        }
        error_log('save booking: ' . $e->getMessage());
        $problem = 'The booking could not be saved. Nothing was changed.';
    }
    $dp_note = $problem === null && $v['dp_amount'] > 0
        ? ' Downpayment of ' . "\u{20B1}" . number_format($v['dp_amount'], 2) . ' recorded' . ($dp_receipt ? '; its receipt is waiting to be verified.' : '; upload its receipt from Payments so it can be verified.')
        : '';
    setFlash($problem ?? (($is_update ? 'Booking updated successfully!' : 'Event booking saved successfully! Reference: ' . $ref) . $dp_note), $problem ? 'danger' : 'success');
    header('Location: event_bookings.php');
    exit;
}

// Handle ARCHIVE: the booking leaves the active list, becomes Cancelled, and is deleted after 30 days
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_id'])) {
    $archive_id = (int)$_POST['archive_id'];
    try {
        $stmt = $pdo->prepare("UPDATE event_bookings
                                  SET pre_archive_status = status, status = 'Cancelled', is_archived = 1, archived_at = NOW(),
                                      archive_expires_at = " . jq_sql_add('NOW()', JQ_ARCHIVE_RETENTION_DAYS, 'DAY') . ", archived_by = ?
                                WHERE booking_id = ? AND is_archived = 0");
        $stmt->execute([(int)$_SESSION['user_id'], $archive_id]);
        setFlash($stmt->rowCount() > 0
            ? 'Booking archived and marked Cancelled. It will be permanently deleted after ' . JQ_ARCHIVE_RETENTION_DAYS . ' days unless you restore it.'
            : 'This booking is already archived.', $stmt->rowCount() > 0 ? 'success' : 'warning');
    } catch (Exception $e) {
        error_log('archive booking: ' . $e->getMessage());
        setFlash('The booking could not be archived. Nothing was changed.', 'danger');
    }
    header('Location: event_bookings.php');
    exit;
}

// Handle PERMANENT DELETE (archived bookings only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    try {
        $ref = jq_delete_archived_booking($pdo, (int)$_POST['delete_id']);
        if ($ref === null) {
            setFlash('Only an archived booking can be permanently deleted.', 'danger');
        } else {
            setFlash('Booking ' . $ref . ' was permanently deleted.', 'success');
        }
    } catch (Exception $e) {
        error_log('delete booking: ' . $e->getMessage());
        setFlash('The booking could not be deleted. Nothing was changed.', 'danger');
    }
    header('Location: event_bookings.php?view=archived');
    exit;
}

// Handle RESTORE from archive: the booking returns to the status it had before archiving
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_id'])) {
    $restore_id = (int)$_POST['restore_id'];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT event_date, event_time, venue_address, pre_archive_status FROM event_bookings WHERE booking_id = ? AND is_archived = 1 FOR UPDATE");
        $stmt->execute([$restore_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            setFlash('This booking is not in the archive.', 'warning');
        } else {
            $status = in_array($row['pre_archive_status'], JQ_BOOKING_STATUSES, true) ? $row['pre_archive_status'] : 'Cancelled';
            $message = 'Booking restored with its earlier status, ' . $status . '.';
            if (in_array($status, ['Pending', 'Approved'], true)) {
                $dupe = $pdo->prepare("SELECT COUNT(*) FROM event_bookings WHERE event_date = ? AND event_time = ? AND venue_address = ? AND booking_id <> ? AND is_archived = 0 AND status IN ('Pending','Approved')");
                $dupe->execute([$row['event_date'], $row['event_time'], $row['venue_address'], $restore_id]);
                if ((int)$dupe->fetchColumn() > 0) {
                    $status = 'Cancelled';
                    $message = 'Booking restored as Cancelled, because another active booking now holds its venue, date and time.';
                }
            }
            $pdo->prepare("UPDATE event_bookings SET is_archived = 0, status = ?, pre_archive_status = NULL, archived_at = NULL, archive_expires_at = NULL, archived_by = NULL, restored_at = NOW(), restored_by = ? WHERE booking_id = ?")
                ->execute([$status, (int)$_SESSION['user_id'], $restore_id]);
            $pdo->commit();
            setFlash($message, 'success');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('restore booking: ' . $e->getMessage());
        setFlash('The booking could not be restored. Nothing was changed.', 'danger');
    }
    header('Location: event_bookings.php?view=archived');
    exit;
}

// Determine view mode
$view_mode  = $_GET['view'] ?? 'dashboard';
$edit_id    = (int)($_GET['edit_id'] ?? 0);
$edit_data  = null;
$is_archive_view = ($view_mode === 'archived');

// Load edit data if editing
if ($view_mode === 'wizard' && $edit_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM event_bookings WHERE booking_id = ?");
        $stmt->execute([$edit_id]);
        $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $edit_data = null;
    }
    if ($edit_data && (int)$edit_data['is_archived'] === 1) {
        setFlash('An archived booking cannot be edited. Restore it first.', 'warning');
        header('Location: event_bookings.php?view=archived');
        exit;
    }
}

// Everything the wizard needs to show an existing booking as it was saved
$edit_preset = null;
$edit_client = null;
$edit_paid = 0.0;
if ($edit_data) {
    $edit_paid = jq_payment_totals($pdo, [$edit_id])[$edit_id]['paid'];
    $st = $pdo->prepare("SELECT ref_type, ref_id, quantity FROM booking_items WHERE booking_id = ?");
    $st->execute([$edit_id]);
    $edit_preset = ['package_id' => (int)$edit_data['package_id'], 'backdrop_id' => (int)$edit_data['backdrop_id'], 'backdrop_name' => (string)$edit_data['backdrop_name'], 'backdrop_path' => (string)$edit_data['backdrop_path'], 'bundles' => [], 'items' => []];
    foreach ($st as $row) {
        $edit_preset[$row['ref_type'] === 'bundle' ? 'bundles' : 'items'][] = ['id' => (int)$row['ref_id'], 'qty' => (int)$row['quantity']];
    }
    if ($edit_data['client_id']) {
        $st = $pdo->prepare("SELECT client_id, client_code, fullname FROM clients WHERE client_id = ?");
        $st->execute([$edit_data['client_id']]);
        $edit_client = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
$wizard_types = $pdo->query("SELECT event_type_name, is_active, event_type_id FROM event_types ORDER BY sort_order, event_type_name")->fetchAll(PDO::FETCH_ASSOC);
$manage_types = jq_event_types($pdo, false);

// Backdrop saved from the Backdrops module (new bookings only)
$folder_to_event_type = [
    'Weddings'        => 'Wedding',
    'Birthdays'       => 'Birthday',
    'Debut'           => 'Debut',
    'Kiddie Birthday' => 'Kiddie Birthday',
    'Christmas Party' => 'Christmas Party',
    'Seminar'         => 'Seminar',
    'Parties'         => 'General Party',
    'WeddingAnniv'    => 'Anniversary',
    'RenewalofVows'   => 'Renewal of Vows',
];
$pre_backdrop_path = '';
$pre_backdrop_type = '';
if ($view_mode === 'wizard' && $edit_id <= 0 && !empty($_SESSION['selected_backdrop'])) {
    $pre_backdrop_path = 'static/images/' . $_SESSION['selected_backdrop'];
    $pre_folder        = $_SESSION['selected_backdrop_type'] ?? explode('/', $_SESSION['selected_backdrop'])[0];
    $pre_backdrop_type = $folder_to_event_type[$pre_folder] ?? '';
}

// Get bookings for dashboard (active) or archived list
$bookings = [];
if ($view_mode === 'dashboard' || $is_archive_view) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM event_bookings WHERE COALESCE(is_archived,0) = ? ORDER BY created_at DESC");
        $stmt->execute([$is_archive_view ? 1 : 0]);
        $bookings = $stmt->fetchAll();
    } catch (Exception $e) {
        $bookings = [];
    }
}

// Count archived (for the dashboard link badge)
$archived_count = 0;
if ($view_mode === 'dashboard') {
    try {
        $archived_count = (int)$pdo->query("SELECT COUNT(*) FROM event_bookings WHERE COALESCE(is_archived,0) = 1")->fetchColumn();
    } catch (Exception $e) {}
}

// Get regions for dropdown
$regions = [];
try {
    $stmt = $pdo->query("SELECT region_code, region_name FROM psgc_regions ORDER BY region_name ASC");
    $regions = $stmt->fetchAll();
} catch (Exception $e) {}

// Get packages
$packages = [];
try {
    $stmt = $pdo->query("SELECT * FROM menu_packages ORDER BY package_name ASC");
    $packages = $stmt->fetchAll();
} catch (Exception $e) {}

require_once 'includes/header.php';
?>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
/* ══════════════════════════════════════════════════════════════
   EVENT BOOKINGS — uses the SAME theme variables as every other
   module (--body-bg, --card-bg, --border-color …) so the page
   background / cards match, and neutral "professional" buttons
   that keep contrast in both light and dark mode.
══════════════════════════════════════════════════════════════ */
/* Brand tokens come from static/css/app-theme.css (--jq-*), so this module follows the same
   lavender identity as dashboard.php in both light and dark mode. */
:root {
    --primary: #6A4FB5;
    --primary-dark: #4E3790;
    --secondary: #5E5A78;
    --success: #1F6B47;
    --danger: #A03A3A;
    --warning: #8A5A00;
    --info: #2B5A8A;
    --light: #F8F5FC;
    --border: var(--border-color);

    /* Button / surface tokens (resolve to light or dark values automatically) */
    --eb-solid-bg: var(--jq-primary-bg);
    --eb-solid-bg-hover: var(--jq-primary-hover);
    --eb-solid-text: #FFFFFF;
    --eb-ghost-text: var(--jq-view-text);
    --eb-ghost-border: var(--jq-view-border);
    --eb-ghost-hover: var(--jq-view-hover);
    --eb-text: var(--jq-text);
    --eb-muted: var(--jq-text-muted);
    --eb-surface: var(--jq-surface);
    --eb-surface-alt: var(--jq-surface-alt);
    --eb-input-bg: var(--jq-input-bg);
    --eb-focus: var(--jq-brand-ring);
}
html[data-theme="dark"] {
    --primary: #C9B5FF;
    --primary-dark: #FFFFFF;
    --secondary: #B4B0CC;
    --success: #82D9AC;
    --danger: #F3A3A3;
    --warning: #F2C46B;
    --info: #A9CBF2;
    --light: #1F1F35;
}

.event-bookings-container { max-width: 1400px; margin: 0 auto; padding: 8px 0 30px; }

/* ── HEADER ── */
.dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; gap: 20px; }
.dashboard-title { font-size: 1.625rem; font-weight: 600; color: var(--eb-text); margin: 0; }
.header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* ── BUTTONS: brand primary + muted, purpose-coded actions ──
   Primary = brand lavender · Edit = muted blue · View / Back / Cancel = neutral gray
   Archive = muted amber · Restore = professional green */
.btn-add-event, .btn-next, .btn-submit, .btn-update {
    background: var(--jq-primary-bg); color: #fff; border: 1px solid var(--jq-primary-bg);
}
.btn-add-event:hover, .btn-next:hover, .btn-submit:hover, .btn-update:hover {
    background: var(--jq-primary-hover); border-color: var(--jq-primary-hover); color: #fff;
}
.btn-edit    { background: var(--jq-edit-bg);    color: var(--jq-edit-text);    border: 1px solid var(--jq-edit-border); }
.btn-archive { background: var(--jq-archive-bg); color: var(--jq-archive-text); border: 1px solid var(--jq-archive-border); }
.btn-restore { background: var(--jq-restore-bg); color: var(--jq-restore-text); border: 1px solid var(--jq-restore-border); }
.btn-outline-neutral, .btn-view, .btn-prev, .back-button {
    background: var(--jq-view-bg); color: var(--jq-view-text); border: 1px solid var(--jq-view-border);
}
.btn-edit:hover    { background: var(--jq-edit-hover); }
.btn-archive:hover { background: var(--jq-archive-hover); }
.btn-restore:hover { background: var(--jq-restore-hover); }
.btn-outline-neutral:hover, .btn-view:hover, .btn-prev:hover, .back-button:hover {
    background: var(--jq-view-hover); text-decoration: none;
}
.btn-action i, .btn-add-event i, .btn-outline-neutral i { font-size: 1rem; }
.btn-add-event, .btn-outline-neutral {
    padding: 10px 20px; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer;
    transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
}
.btn-add-event:hover { text-decoration: none; box-shadow: 0 2px 8px rgba(0,0,0,0.18); }
.btn-add-event:focus-visible, .btn-outline-neutral:focus-visible, .btn-action:focus-visible,
.btn-prev:focus-visible, .btn-next:focus-visible, .btn-submit:focus-visible, .btn-update:focus-visible {
    outline: 2px solid var(--eb-ghost-text); outline-offset: 2px;
}
.badge-count { background: var(--eb-solid-bg); color: var(--eb-solid-text); border-radius: 10px; font-size: 0.72rem; padding: 1px 8px; font-weight: 700; }

/* ── STATS ── */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 24px; }
.stat-card {
    background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px;
    padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(147,112,219,0.08);
}
.stat-number { font-size: 2rem; font-weight: 700; color: var(--eb-text); }
.stat-label  { font-size: 0.8rem; color: var(--eb-muted); text-transform: uppercase; margin-top: 5px; letter-spacing: .4px; }

/* ── TABLE ── */
.bookings-table-wrapper {
    background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px;
    overflow-x: auto; box-shadow: 0 2px 8px rgba(147,112,219,0.08);
}
.bookings-table { width: 100%; border-collapse: collapse; background: transparent; }
.bookings-table th {
    background: var(--eb-surface-alt); padding: 14px 15px; text-align: left; font-weight: 700;
    color: var(--eb-text); border-bottom: 1px solid var(--border-color);
    font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap;
}
.bookings-table td { padding: 13px 15px; border-bottom: 1px solid var(--border-color); vertical-align: middle; color: var(--eb-text); background: transparent; }
.bookings-table td strong { color: var(--eb-text); }
.bookings-table tbody tr:last-child td { border-bottom: none; }
.bookings-table tbody tr:hover td { background: var(--menu-item-hover-bg); }

/* ── TOTAL (masked) + EYE TOGGLE ── */
.total-cell { display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; }
.total-amount { font-weight: 700; letter-spacing: 0.3px; min-width: 90px; display: inline-block; }
.total-amount.masked { letter-spacing: 2px; }
.eye-toggle {
    background: transparent; border: 1px solid transparent; color: var(--eb-muted);
    width: 30px; height: 30px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; padding: 0; transition: all 0.2s;
}
.eye-toggle:hover { background: var(--eb-ghost-hover); color: var(--eb-text); border-color: var(--border-color); }
.eye-toggle svg { width: 18px; height: 18px; display: block; }
.eye-toggle .icon-eye-off { display: none; }
.eye-toggle.revealed .icon-eye { display: none; }
.eye-toggle.revealed .icon-eye-off { display: block; }
.th-total { display: inline-flex; align-items: center; gap: 6px; }

/* ── STATUS DROPDOWN (neutral outlined pill, status shown by a small dot-tone border) ── */
.status-select {
    appearance: none; -webkit-appearance: none;
    border: 1px solid var(--eb-ghost-border); border-radius: 20px;
    padding: 5px 28px 5px 12px; font-size: 0.78rem; font-weight: 700;
    color: var(--eb-text); background-color: var(--eb-surface-alt); cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath fill='%238A8AA5' d='M0 0l5 6 5-6z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    transition: all 0.2s; min-width: 120px;
}
.status-select:hover { border-color: var(--eb-ghost-text); }
.status-select:focus { outline: none; box-shadow: 0 0 0 3px var(--eb-focus); }
.status-select option { background: var(--eb-surface); color: var(--eb-text); }
.status-select.status-pending   { border-left: 4px solid var(--warning); }
.status-select.status-approved  { border-left: 4px solid var(--success); }
.status-select.status-disabled  { border-left: 4px solid var(--secondary); }
.eb-money { white-space: nowrap; font-weight: 600; }
.eb-paystatus { display: inline-block; margin-top: 3px; padding: 1px 7px; border-radius: 10px; font-size: .68rem; font-weight: 700; white-space: nowrap; background: var(--eb-surface-alt); color: var(--eb-text) !important; border: 1px solid var(--eb-ghost-border); }
.eb-paystatus.is-unpaid { border-left: 3px solid var(--danger); }
.eb-paystatus.is-partially-paid, .eb-paystatus.is-pending-verification, .eb-paystatus.is-payment-pending-verification, .eb-paystatus.is-receipt-submitted { border-left: 3px solid var(--warning); }
.eb-paystatus.is-fully-paid, .eb-paystatus.is-settled, .eb-paystatus.is-verified { border-left: 3px solid var(--success); }
.eb-paystatus.is-awaiting-proof-of-payment, .eb-paystatus.is-awaiting-receipt, .eb-paystatus.is-rejected { border-left: 3px solid var(--danger); }
.bookings-table .eb-paystatus { white-space: normal; max-width: 92px; line-height: 1.2; }
.eb-pay-actions { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.eb-pay-actions form { margin: 0; }
.eb-pay-actions label.btn-action { margin: 0; cursor: pointer; font-weight: inherit; }
.eb-receipt-rule { display: block; margin-top: 4px; color: var(--eb-muted); }
/* Twelve columns: compact enough to fit from 1280px up. Narrower screens get one card per booking (below). */
.bookings-table { font-size: .82rem; }
html body .bookings-table th:not(#x) { padding: 10px 4px; font-size: .66rem; letter-spacing: .02em; white-space: normal; line-height: 1.25; vertical-align: middle; }
html body .bookings-table td:not(#x) { padding: 9px 4px; }
.bookings-table td:nth-child(2) { width: 92px; min-width: 92px; max-width: 92px; font-size: .72rem; overflow-wrap: anywhere; }
.bookings-table td:nth-child(2) strong { font-weight: 600; }
.bookings-table td:nth-child(3), .bookings-table td:nth-child(4) { max-width: 96px; }
.bookings-table td:nth-child(5) { font-size: .78rem; }
.bookings-table td:nth-child(11) { white-space: nowrap; }
.bookings-table .total-amount { min-width: 0; }
.bookings-table .total-amount.masked { letter-spacing: 1px; }
.bookings-table .eye-toggle { width: 22px; height: 22px; }
.bookings-table .eye-toggle svg { width: 15px; height: 15px; }
.bookings-table .status-select { min-width: 96px; width: 96px; padding-left: 6px; padding-right: 2px; font-size: .76rem; }
.bookings-table .eb-money { font-size: .78rem; }
.bookings-table .status-pill { padding: 3px 7px; font-size: .72rem; }
.bookings-table .btn-action { padding: 5px 8px; font-size: .76rem; }
.bookings-table .dropdown-menu { font-size: .86rem; background: var(--eb-surface); border: 1px solid var(--border-color); }
html body .bookings-table .dropdown-item:not(#x) { color: var(--eb-text) !important; display: flex; align-items: center; gap: 8px; padding: 7px 14px; background: transparent; }
html body .bookings-table .dropdown-item:not(#x):hover { background: var(--menu-item-hover-bg); }
html body .bookings-table .dropdown-item:not(#x) * { color: inherit !important; }
/* Below 1280px each booking is a card: label on the left, value on the right, nothing to scroll or overlap. */
@media (max-width: 1279.98px) {
    .bookings-table, .bookings-table tbody, .bookings-table thead { display: block; width: 100%; }
    .bookings-table thead tr { display: flex; justify-content: flex-end; }
    html body .bookings-table thead th:not(#x) { display: none; border: 0; }
    html body .bookings-table thead th.eb-th-toggle:not(#x) { display: block; background: transparent; }
    .bookings-table tbody { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 12px; padding: 12px; }
    .bookings-table tbody tr { display: block; background: var(--eb-surface); border: 1px solid var(--border-color); border-radius: 10px; overflow: hidden; }
    .bookings-table tbody tr.eb-no-match { grid-column: 1 / -1; }
    html body .bookings-table:not(#eb) tbody td:not(#x) { display: block; position: relative; width: auto; min-width: 0; max-width: none; min-height: 34px; padding: 8px 12px 8px 150px; text-align: right; font-size: .82rem; overflow-wrap: anywhere; white-space: normal !important; border-bottom: 1px solid var(--border-color); }
    html body .bookings-table tbody tr:not(.eb-no-match) td:last-child:not(#x) { border-bottom: 0; }
    html body .bookings-table:not(#eb) tbody tr.eb-no-match td:not(#x) { padding: 20px 12px; text-align: center; border: 0; }
    .bookings-table tbody td[data-label]::before { content: attr(data-label); position: absolute; left: 12px; top: 9px; white-space: nowrap; text-align: left; font-size: .66rem; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; color: var(--eb-muted); }
    .bookings-table .status-select { width: auto; }
    .bookings-table .eb-paystatus { max-width: none; }
}
.eb-tabs { display: flex; gap: 4px; flex-wrap: wrap; border-bottom: 2px solid var(--border-color); margin-bottom: 16px; }
.eb-tab { border: 0; background: transparent; padding: 8px 12px; font-weight: 600; font-size: .88rem; color: var(--eb-muted); border-bottom: 3px solid transparent; margin-bottom: -2px; cursor: pointer; }
html body .eb-tab:not(#x).active { color: var(--eb-text) !important; border-bottom-color: var(--jq-brand); }
.eb-pane { display: none; }
.eb-pane.active { display: block; }
.eb-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(125px, 1fr)); gap: 10px; margin-bottom: 16px; }
.eb-summary div { background: var(--eb-surface-alt); border: 1px solid var(--eb-ghost-border); border-radius: 8px; padding: 9px 11px; }
.eb-summary small { display: block; font-size: .72rem; color: var(--eb-muted); }
.eb-summary strong { font-size: .98rem; color: var(--eb-text); }
.eb-subform { border-top: 1px solid var(--border-color); margin-top: 14px; padding-top: 14px; }
.eb-subform .form-row { gap: 10px; }
.eb-subform label { display: block; font-size: .8rem; font-weight: 600; margin-bottom: 3px; color: var(--eb-text); }
.eb-subform input, .eb-subform select, .eb-subform textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--eb-surface); color: var(--eb-text); font-size: .88rem; }
.eb-grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-bottom: 10px; }
.eb-list { margin: 4px 0 10px; padding-left: 18px; }
.eb-list li { margin-bottom: 2px; }
.eb-incl-title { font-weight: 700; font-size: .88rem; margin-top: 8px; color: var(--eb-text); }
.bundle-inc { margin: 4px 0; padding-left: 18px; font-size: .8rem; color: var(--secondary); }
.pkg-inc { margin: 6px 0 0; padding-left: 20px; }
.eb-client-results { border: 1px solid var(--border-color); border-radius: 8px; margin-top: 6px; overflow: hidden; }
.eb-client-results button { display: block; width: 100%; text-align: left; border: 0; border-bottom: 1px solid var(--border-color); background: var(--eb-surface); color: var(--eb-text); padding: 8px 12px; font-size: .88rem; cursor: pointer; }
.eb-client-results button:hover { background: var(--menu-item-hover-bg); }
.eb-client-picked { margin-top: 6px; padding: 8px 12px; border-radius: 8px; background: var(--eb-surface-alt); font-size: .88rem; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.eb-expiry { display: block; margin-top: 4px; font-size: .74rem; line-height: 1.3; color: var(--eb-muted); white-space: nowrap; }
.status-select.status-cancelled { border-left: 4px solid var(--danger); }
.status-select:disabled { opacity: .6; cursor: wait; }

/* ── ACTION BUTTONS ── */
.action-buttons { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; min-width: 172px; }
.btn-action {
    display: inline-flex; align-items: center; gap: 4px; padding: 6px 14px; font-size: 0.8rem; font-weight: 600;
    border-radius: 6px; cursor: pointer; transition: all 0.2s; white-space: nowrap;
}
.btn-action:hover { box-shadow: 0 2px 6px rgba(0,0,0,0.15); }

/* ── EMPTY STATE ── */
.empty-state { text-align: center; padding: 60px 20px; color: var(--eb-muted); background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; }
.empty-state h3 { color: var(--eb-text); }
.empty-state-icon { font-size: 3rem; margin-bottom: 15px; }

/* ══════════════════ WIZARD ══════════════════ */
.wizard-container {
    background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px;
    padding: 40px; max-width: 900px; margin: 10px auto 30px; box-shadow: 0 2px 8px rgba(147,112,219,0.08);
}
.wizard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.wizard-header h1 { color: var(--eb-text); margin: 0; font-size: 1.5rem; font-weight: 600; }
.back-button { padding: 9px 18px; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.2s; text-decoration: none; font-size: 0.9rem; }

.steps-indicator { display: flex; justify-content: space-between; margin: 30px 0; gap: 15px; }
.step { flex: 1; text-align: center; }
.step-circle {
    width: 50px; height: 50px; border-radius: 50%; background: var(--eb-surface-alt); border: 2px solid var(--border-color);
    color: var(--eb-text); display: flex; align-items: center; justify-content: center; font-weight: 700; margin: 0 auto 10px; transition: all 0.3s;
}
.step.active .step-circle    { background: var(--eb-solid-bg); color: var(--eb-solid-text); border-color: var(--eb-solid-bg); }
.step.completed .step-circle { background: var(--success); color: #fff; border-color: var(--success); }
.step-title { font-weight: 600; color: var(--eb-muted); font-size: 0.9rem; }
.step.active .step-title { color: var(--eb-text); }

.form-section { display: none; }
.form-section.active { display: block; animation: fadeIn 0.3s; }
@keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
.form-section h3, .form-section h4 { color: var(--eb-text); }

.form-group { margin-bottom: 20px; }
.form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: var(--eb-text); }
.form-group label .required { color: var(--danger); }
.form-group input, .form-group select, .form-group textarea,
.qty-input, #bundleSearch, #foodSearch, .form-section input[type="text"], .form-section input[type="search"], .form-section input[type="number"] {
    width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 8px;
    font-size: 1rem; background-color: var(--eb-input-bg); color: var(--eb-text);
}
html[data-theme="dark"] .form-group input, html[data-theme="dark"] .form-group select, html[data-theme="dark"] .form-group textarea,
html[data-theme="dark"] .form-section input { color-scheme: dark; }
.form-group input::placeholder, .form-group textarea::placeholder, .form-section input::placeholder { color: var(--eb-muted); opacity: 1; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus, .form-section input:focus {
    outline: none; border-color: var(--eb-ghost-text); box-shadow: 0 0 0 3px var(--eb-focus);
}
.form-group input:disabled, .form-group select:disabled, .form-group textarea:disabled, .form-group input[readonly] {
    background-color: var(--eb-surface-alt); color: var(--eb-muted); cursor: not-allowed;
}
.form-group select option { background: var(--eb-surface); color: var(--eb-text); }
.form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }

/* Backdrop gallery */
.backdrop-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 15px; margin-bottom: 20px; }
.backdrop-item {
    cursor: pointer; border: 2px solid var(--border-color); border-radius: 10px; overflow: hidden; text-align: center;
    transition: all 0.2s; position: relative; background: var(--eb-surface-alt);
}
.backdrop-item:hover { border-color: var(--eb-ghost-text); box-shadow: 0 4px 14px rgba(0,0,0,0.18); transform: translateY(-2px); }
.backdrop-item.selected { border-color: var(--eb-solid-bg); box-shadow: 0 0 0 2px var(--eb-solid-bg); }
.backdrop-item.selected::after {
    content: '✓'; position: absolute; top: 6px; right: 8px; background: var(--eb-solid-bg); color: var(--eb-solid-text);
    width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700;
}
.backdrop-img { width: 100%; height: 110px; object-fit: cover; display: block; }
.backdrop-img-placeholder { width: 100%; height: 110px; background: var(--eb-surface-alt); display: flex; align-items: center; justify-content: center; font-size: 2.5rem; }
.backdrop-name { font-size: 0.8rem; font-weight: 600; color: var(--eb-text); padding: 8px 6px; line-height: 1.3; }
#selectedBackdropPreview { background: var(--eb-ghost-hover) !important; }
#selectedBackdropPreview strong { color: var(--eb-text) !important; }
#selectedBackdropName { color: var(--eb-text) !important; }

/* Food / Bundle */
.food-course-group { margin-bottom: 18px; }
.food-course-title { font-weight: 700; color: var(--eb-text); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; padding-bottom: 5px; border-bottom: 1px solid var(--border-color); }
.food-item-checkbox, .bundle-checkbox { display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; border-radius: 6px; transition: background 0.2s; cursor: pointer; }
.food-item-checkbox:hover, .bundle-checkbox:hover { background: var(--menu-item-hover-bg); }
.food-item-checkbox input[type="checkbox"], .bundle-checkbox input[type="checkbox"] { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--eb-solid-bg); flex-shrink: 0; cursor: pointer; }
.food-item-info, .bundle-info { flex: 1; }
.food-item-name, .bundle-name { font-weight: 600; font-size: 0.9rem; color: var(--eb-text); }
.food-item-note, .bundle-desc { font-size: 0.8rem; color: var(--eb-muted); margin-top: 2px; }
.food-item-price, .bundle-price { font-size: 0.85rem; font-weight: 700; color: var(--eb-text); white-space: nowrap; margin-top: 2px; }
/* Bundles & Add-ons: Catering Package | Price | Guest / Table | Qty */
.bundle-head { display: flex; gap: 12px; padding: 0 10px 6px 38px; font-size: .72rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--eb-muted); border-bottom: 1px solid var(--border); margin-bottom: 6px; }
.bundle-head span:nth-child(1) { flex: 1; }
.bundle-head span:nth-child(2) { width: 120px; }
.bundle-head span:nth-child(3) { width: 130px; }
.bundle-head span:nth-child(4) { width: 98px; text-align: center; }
.bundle-row .bundle-col-price { width: 120px; flex-shrink: 0; margin-top: 2px; }
.bundle-guests { width: 130px; flex-shrink: 0; font-size: .85rem; font-weight: 600; color: var(--eb-text); margin-top: 2px; }
.bundle-guests-unset { font-weight: 400; font-style: italic; color: var(--eb-muted); }
@media (max-width: 767px) {
    .bundle-head { display: none; }
    .bundle-row .bundle-checkbox { flex-wrap: wrap; }
    .bundle-row .bundle-info { flex-basis: calc(100% - 28px); }
    .bundle-row .bundle-col-price, .bundle-guests { width: auto; margin-left: 28px; }
    .bundle-guests::before { content: 'Guest / Table: '; font-weight: 400; color: var(--eb-muted); }
}

/* Inline white boxes written in JS/PHP markup → follow theme */
.form-section div[style*="background:#fff"], .form-section div[style*="background: #fff"], .step3-card {
    background: var(--eb-surface-alt) !important; border-color: var(--border-color) !important; box-shadow: none !important;
}
.step3-card { border: 1px solid var(--border-color); }
.step3-card h4 { color: var(--eb-text) !important; }
.step3-card h4 span, .step3-card p, #packageDescription { color: var(--eb-muted) !important; }
.step3-card h4 .required { color: var(--danger) !important; }
.step3-card .form-group select, .step3-card input[type="text"], .step3-card .qty-input { background-color: var(--eb-input-bg) !important; color: var(--eb-text) !important; border: 1px solid var(--border-color) !important; }

/* Qty buttons */
.qty-btn { background: var(--eb-surface-alt) !important; border: 1px solid var(--eb-ghost-border) !important; color: var(--eb-ghost-text) !important; }
.qty-btn:hover { background: var(--eb-solid-bg) !important; color: var(--eb-solid-text) !important; }

/* Price summary */
.price-summary { background: var(--eb-ghost-hover); border-left: 4px solid var(--eb-solid-bg); padding: 15px; border-radius: 6px; margin: 20px 0; font-size: 0.95rem; color: var(--eb-text); }
.price-summary .price-row, .price-summary .price-row span { color: var(--eb-text); }
.price-row { display: flex; justify-content: space-between; margin-bottom: 10px; }
.price-total { font-size: 1.3rem; font-weight: 700; color: var(--eb-text); border-top: 2px solid var(--border-color); padding-top: 10px; margin-top: 10px; }
.price-total span { color: var(--eb-text); }

/* Review summary */
#reviewSummary { background: var(--eb-surface-alt) !important; }
#reviewSummary table tr { border-bottom-color: var(--border-color) !important; }
#reviewSummary table tr[style*="background:#f9f9f9"] { background: var(--eb-ghost-hover) !important; }
#reviewSummary table td { color: var(--eb-text) !important; background: transparent !important; }
#reviewSummary table td[style*="color:var(--primary)"] { color: var(--eb-muted) !important; }
#reviewSummary p { color: var(--eb-muted) !important; }

/* Wizard nav buttons */
.button-group { display: flex; gap: 15px; justify-content: space-between; margin-top: 40px; }
.btn-prev, .btn-next, .btn-submit, .btn-update { padding: 11px 26px; border-radius: 8px; font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.btn-next, .btn-submit, .btn-update { margin-left: auto; }
.btn-update:hover, .btn-submit:hover, .btn-next:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.2); }

/* Alert */
.alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
.alert-danger { background: rgba(169,68,66,0.12); color: var(--danger); border-left: 4px solid var(--danger); }

/* Edit mode banner */
.edit-mode-banner {
    background: var(--eb-surface-alt); color: var(--eb-text); border: 1px solid var(--border-color); border-left: 4px solid var(--eb-solid-bg);
    padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: 700; display: flex; align-items: center; gap: 10px; font-size: 1rem;
}
.edit-mode-banner span { color: var(--eb-text); }

/* View Modal */
.view-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 2000; align-items: center; justify-content: center; }
.view-modal-overlay.active { display: flex; }
.view-modal {
    background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 0;
    max-width: 720px; width: 92%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.35); animation: modalIn 0.25s ease;
}
@keyframes modalIn { from { opacity:0; transform:scale(0.94); } to { opacity:1; transform:scale(1); } }
.view-modal-header {
    background: var(--eb-surface-alt); color: var(--eb-text); border-bottom: 1px solid var(--border-color);
    border-top: 3px solid var(--jq-brand);
    padding: 18px 28px; display: flex; justify-content: space-between; align-items: center; border-radius: 12px 12px 0 0;
    position: sticky; top: 0; z-index: 1;
}
.view-modal-header h2 { margin: 0; font-size: 1.2rem; color: var(--eb-text); }
.view-modal-close {
    background: transparent; border: 1px solid var(--eb-ghost-border); color: var(--eb-ghost-text);
    width: 32px; height: 32px; border-radius: 8px; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;
}
.view-modal-close:hover { background: var(--eb-ghost-hover); }
.view-modal-body { padding: 24px 28px; }
.view-modal-footer { position: sticky; bottom: 0; display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap; padding: 14px 28px; background: var(--eb-surface-alt); border-top: 1px solid var(--border-color); border-radius: 0 0 12px 12px; }
.view-items { width: 100%; border-collapse: collapse; font-size: .88rem; }
.view-items th { text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; padding: 6px 8px; border-bottom: 1px solid var(--border-color); }
.view-items td { padding: 7px 8px; border-bottom: 1px solid var(--border-color); }
.view-items .num { text-align: right; white-space: nowrap; }
.swal2-container { z-index: 2100 !important; }
/* Laptops: every action stays visible without sideways scrolling */
@media (max-width: 1399px) {
    html body .bookings-table:not(#eb) th, html body .bookings-table:not(#eb) td { padding-left: 4px; padding-right: 4px; }
    html body .bookings-table:not(#eb) td:nth-child(2) { word-break: break-all; min-width: 104px; }
    html body .bookings-table:not(#eb) .action-buttons { min-width: 92px; flex-direction: column; align-items: stretch; }
    html body .bookings-table:not(#eb) .btn-action { justify-content: center; }
}
@media (max-width: 600px) {
    .view-modal-footer { padding: 12px 16px; }
    .view-modal-footer .btn-action { flex: 1 1 auto; justify-content: center; }
    .view-items th:nth-child(2), .view-items td:nth-child(2) { display: none; }
    .view-items th, .view-items td { padding-left: 4px; padding-right: 4px; }
}
.btn-delete { background: var(--jq-delete-bg); color: var(--jq-delete-text); border: 1px solid var(--jq-delete-border); }
.btn-delete:hover { background: var(--jq-delete-hover); }
.view-modal-body p { color: var(--eb-muted); }
.booking-sections h3 { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--eb-text); margin: 18px 0 8px; padding-bottom: 6px; border-bottom: 2px solid var(--border-color); }
.booking-sections h3:first-child { margin-top: 0; }
.booking-sections table.kv { width: 100%; border-collapse: collapse; }
.booking-sections table.kv th { width: 34%; text-align: left; padding: 5px 10px 5px 0; font-size: .82rem; font-weight: 600; vertical-align: top; }
.booking-sections table.kv td { padding: 5px 0; font-size: .92rem; word-break: break-word; }
.booking-sections .notes { white-space: pre-wrap; margin: 0; }
.booking-sections .none { margin: 0; }
.view-row { display: flex; gap: 10px; margin-bottom: 14px; padding-bottom: 14px; border-bottom: 1px solid var(--border-color); align-items: flex-start; }
.view-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.view-label { font-weight: 700; color: var(--eb-muted); min-width: 150px; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.3px; }
.view-value { color: var(--eb-text); font-size: 0.95rem; flex: 1; }
.view-value strong { color: var(--eb-text); }
.view-section-title { font-weight: 700; color: var(--eb-text); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; margin: 20px 0 12px; padding-bottom: 6px; border-bottom: 2px solid var(--border-color); }
.view-status-badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; color: var(--eb-text); background: var(--eb-surface-alt); border: 1px solid var(--eb-ghost-border); }
.view-status-pending   { border-left: 4px solid var(--warning); }
.view-status-approved  { border-left: 4px solid var(--success); }
.view-status-disabled  { border-left: 4px solid var(--secondary); }
.view-status-cancelled { border-left: 4px solid var(--danger); }

/* SweetAlert2 buttons → brand primary (archive / restore use .jq-swal-* tones from app-theme.css) */
.swal2-popup .swal2-confirm {
    background: var(--eb-solid-bg) !important; background-image: none !important; color: var(--eb-solid-text) !important; box-shadow: none !important; border: 1px solid var(--eb-solid-bg) !important;
}
/* Booking table inside a jq-card: the card provides the frame */
.jq-card .bookings-table-wrapper { border: 0; border-radius: 0; box-shadow: none; background: transparent !important; }
.bookings-table tbody tr.eb-hidden { display: none; }
.eb-no-match td { text-align: center; padding: 36px 16px !important; color: var(--eb-muted) !important; }
.eb-header-sub { margin: 4px 0 0; font-size: .875rem; color: var(--eb-muted); }
/* Compact table so all columns (incl. actions) fit on a standard desktop without scrolling */
.bookings-table th, .bookings-table td { padding-left: 11px; padding-right: 11px; }
.bookings-table td { font-size: .9rem; }
.bookings-table td:nth-child(2) strong { font-size: .82rem; letter-spacing: .01em; }
.bookings-table td:nth-child(5) { white-space: nowrap; }
.bookings-table .btn-action { padding: 6px 10px; }
.bookings-table .total-amount { min-width: 0; }
.bookings-table .status-select { min-width: 96px; }
.swal2-popup .swal2-cancel, .swal2-popup .swal2-deny {
    background: transparent !important; color: var(--eb-ghost-text) !important; border: 1px solid var(--eb-ghost-border) !important; box-shadow: none !important;
}
.swal2-popup .swal2-timer-progress-bar { background: var(--eb-ghost-border) !important; }

@media (max-width: 768px) {
    .dashboard-header { flex-direction: column; align-items: flex-start; }
    .stats-grid { grid-template-columns: repeat(2,1fr); }
    .action-buttons { flex-wrap: wrap; }
    .wizard-container { padding: 20px; }
    .steps-indicator { gap: 5px; }
    .step-title { font-size: 0.7rem; }
    .form-row { grid-template-columns: 1fr; }
    .button-group { flex-direction: column; }
    .btn-next, .btn-submit, .btn-update { margin-left: 0; width: 100%; }
}

/* ── Beat the global button/span colour rules in includes/header.php (id-boost via :not(#eb)) ── */
html body .btn-add-event:not(#eb), html body .btn-add-event:not(#eb):hover, html body .btn-add-event:not(#eb) *,
html body .btn-next:not(#eb), html body .btn-submit:not(#eb), html body .btn-update:not(#eb),
html body .badge-count:not(#eb),
html body .swal2-popup .swal2-confirm:not(#eb) { color: var(--eb-solid-text) !important; }
html body .btn-outline-neutral:not(#eb), html body .btn-outline-neutral:not(#eb) *, html body .btn-view:not(#eb), html body .btn-view:not(#eb) *,
html body .btn-prev:not(#eb), html body .back-button:not(#eb), html body .qty-btn:not(#eb),
html body .view-modal-close:not(#eb), html body .swal2-popup .swal2-cancel:not(#eb) { color: var(--eb-ghost-text) !important; }
html body .btn-edit:not(#eb), html body .btn-edit:not(#eb) *       { color: var(--jq-edit-text) !important; }
html body .btn-archive:not(#eb), html body .btn-archive:not(#eb) * { color: var(--jq-archive-text) !important; }
html body .btn-delete:not(#eb), html body .btn-delete:not(#eb) * { color: var(--jq-delete-text) !important; }
html body .btn-delete:not(#eb) { background-color: var(--jq-delete-bg) !important; }
html body .btn-delete:not(#eb):hover { background-color: var(--jq-delete-hover) !important; }
html body .swal2-popup .swal2-confirm.jq-swal-delete:not(#eb) { background-color: #A94442 !important; border-color: #A94442 !important; color: #fff !important; }
html body .btn-restore:not(#eb), html body .btn-restore:not(#eb) * { color: var(--jq-restore-text) !important; }
html body .btn-next:not(#eb), html body .btn-submit:not(#eb), html body .btn-update:not(#eb), html body .btn-add-event:not(#eb) { background-color: var(--eb-solid-bg) !important; background-image: none !important; }
html body .btn-next:not(#eb):hover, html body .btn-submit:not(#eb):hover, html body .btn-update:not(#eb):hover, html body .btn-add-event:not(#eb):hover { background-color: var(--eb-solid-bg-hover) !important; }
html body .btn-edit:not(#eb)    { background-color: var(--jq-edit-bg) !important; }
html body .btn-archive:not(#eb) { background-color: var(--jq-archive-bg) !important; }
html body .btn-restore:not(#eb) { background-color: var(--jq-restore-bg) !important; }
html body .btn-edit:not(#eb):hover    { background-color: var(--jq-edit-hover) !important; }
html body .btn-archive:not(#eb):hover { background-color: var(--jq-archive-hover) !important; }
html body .btn-restore:not(#eb):hover { background-color: var(--jq-restore-hover) !important; }
html body .btn-view:not(#eb), html body .btn-prev:not(#eb), html body .back-button:not(#eb), html body .btn-outline-neutral:not(#eb) { background-color: var(--jq-view-bg) !important; }
html body .btn-view:not(#eb):hover, html body .btn-prev:not(#eb):hover, html body .back-button:not(#eb):hover, html body .btn-outline-neutral:not(#eb):hover { background-color: var(--jq-view-hover) !important; }
html body .swal2-popup .swal2-confirm.jq-swal-archive:not(#eb) { background-color: #B7791F !important; border-color: #B7791F !important; color: #fff !important; }
html body .swal2-popup .swal2-confirm.jq-swal-restore:not(#eb) { background-color: #2E7D5B !important; border-color: #2E7D5B !important; color: #fff !important; }
html body .qty-btn:not(#eb):hover { background-color: var(--eb-solid-bg) !important; color: var(--eb-solid-text) !important; }
html body .eye-toggle:not(#eb) { color: var(--eb-muted) !important; }
html body .eye-toggle:not(#eb):hover { color: var(--eb-text) !important; }
html body .status-select:not(#eb) { color: var(--eb-text) !important; }
html body .stat-label:not(#eb), html body .step-title:not(#eb) { color: var(--eb-muted) !important; }
html body .step.active .step-title:not(#eb) { color: var(--eb-text) !important; }
html body .step.active .step-circle:not(#eb) { color: var(--eb-solid-text) !important; }
html body .step.completed .step-circle:not(#eb) { color: #fff !important; }
html body .view-label:not(#eb), html body .food-item-note:not(#eb), html body .bundle-desc:not(#eb) { color: var(--eb-muted) !important; }
html body .bookings-table th:not(#eb), html body .bookings-table td:not(#eb), html body .bookings-table td strong:not(#eb) { color: var(--eb-text) !important; }
html body .bookings-table th:not(#eb), html body .bookings-table td:not(#eb) { background-color: transparent; }
html body .bookings-table th:not(#eb) { background-color: var(--eb-surface-alt); }
html body .bookings-table td:not(#eb)[style*="color:var(--secondary)"] { color: var(--eb-muted) !important; }
html body .bookings-table-wrapper:not(#eb), html body .stat-card:not(#eb), html body .wizard-container:not(#eb), html body .view-modal:not(#eb) { background-color: var(--card-bg) !important; }
</style>

<?php if ($view_mode === 'dashboard' || $is_archive_view): ?>
<!-- ═══════════════════════════════════════════════════
     DASHBOARD / ARCHIVED VIEW
═══════════════════════════════════════════════════ -->
<div class="event-bookings-container" id="jqPage">
    <div class="dashboard-header">
        <div>
            <h1 class="dashboard-title"><?= $is_archive_view ? 'Archived Bookings' : 'Event Bookings' ?></h1>
            <p class="eb-header-sub"><?= $is_archive_view ? 'Archived bookings are marked Cancelled and permanently deleted after ' . JQ_ARCHIVE_RETENTION_DAYS . ' days. Restore one to keep it.' : 'Manage catering reservations, statuses and booking details.' ?></p>
        </div>
        <div class="header-actions">
            <?php if ($is_archive_view): ?>
                <a href="event_bookings.php" class="btn-outline-neutral"><i class="bx bx-arrow-back"></i> Back to Bookings</a>
            <?php else: ?>
                <a href="event_bookings.php?view=archived" class="btn-outline-neutral">
                    <i class="bx bx-archive"></i> Archived <?php if ($archived_count > 0): ?><span class="badge-count"><?= $archived_count ?></span><?php endif; ?>
                </a>
                <button type="button" class="btn-outline-neutral" data-bs-toggle="modal" data-bs-target="#eventTypesModal"><i class="bx bx-category"></i> Add Event Type</button>
                <a href="event_bookings.php?view=wizard" class="btn-add-event"><i class="bx bx-plus"></i> New Event Booking</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$is_archive_view): ?>
    <?php
    $eb_count = static fn($status) => count(array_filter($bookings, static fn($b) => $b['status'] === $status));
    $pending   = $eb_count('Pending');
    $approved  = $eb_count('Approved');
    $cancelled = $eb_count('Cancelled');
    $disabled  = $eb_count('Disabled');
    $completed = $eb_count('Completed');
    $eb_tiles = [
        ['Active bookings', $pending + $approved, 'Pending and approved', 'bx-calendar-check', 'brand',   '__active',  'Active'],
        ['Pending',         $pending,   'Awaiting approval',  'bx-time-five',    'archive', 'Pending',   'Pending'],
        ['Approved',        $approved,  'Ready to serve',     'bx-check-circle', 'restore', 'Approved',  'Approved'],
        ['Cancelled',       $cancelled, 'Will not proceed',   'bx-x-circle',     'delete',  'Cancelled', 'Cancelled'],
        ['Completed',       $completed, 'Event finished',    'bx-badge-check',  'brand',   'Completed', 'Completed'],
        ['Disabled',        $disabled,  'Not treated as active', 'bx-block',     'view',    'Disabled',  'Disabled'],
    ];
    ?>
    <div class="jq-stats">
        <?php foreach ($eb_tiles as [$label, $count, $hint, $icon, $tone, $filter, $tile_key]): ?>
        <div class="jq-stat" role="button" tabindex="0" style="cursor:pointer;" onclick="ebQuickStatus('<?= $filter ?>')"
             onkeydown="if(event.key==='Enter'){ebQuickStatus('<?= $filter ?>');}" title="Show <?= strtolower($label) ?>">
            <div><span class="jq-stat-label"><?= $label ?></span><div class="jq-stat-value" data-tile="<?= $tile_key ?>"><?= $count ?></div><span class="jq-stat-hint"><?= $hint ?></span></div>
            <div class="jq-stat-icon jq-tone-<?= $tone ?>"><i class="bx <?= $icon ?>"></i></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: $retention = jq_retention_status($pdo); ?>
    <div class="jq-alert <?= $retention['ok'] ? 'jq-alert-info' : 'jq-alert-warning' ?>" style="margin-bottom:16px;"><i class="bx <?= $retention['ok'] ? 'bx-time-five' : 'bx-error' ?>"></i>
        <p><strong>Archived bookings are deleted automatically <?= JQ_ARCHIVE_RETENTION_DAYS ?> days after they are archived.</strong> <?= jq_e($retention['message']) ?></p>
    </div>
    <?php endif; ?>

    <?php if (empty($bookings)): ?>
        <div class="empty-state">
            <h3><?= $is_archive_view ? 'No Archived Bookings' : 'No Event Bookings Yet' ?></h3>
            <p><?= $is_archive_view ? 'Bookings you archive will appear here.' : 'Create your first event booking to get started' ?></p>
            <br>
            <?php if (!$is_archive_view): ?>
                <a href="event_bookings.php?view=wizard" class="btn-add-event">+ Add Event Booking</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php $eb_types = array_values(array_unique(array_filter(array_column($bookings, 'event_type')))); sort($eb_types); ?>
        <div class="jq-card">
        <div class="jq-card-body" style="border-bottom:1px solid var(--jq-border);">
            <div class="jq-filters" id="ebFilters">
                <div class="jq-field jq-field-wide">
                    <label class="jq-label" for="ebSearch">Search</label>
                    <div class="jq-search"><i class="bx bx-search"></i>
                        <input class="jq-input" type="search" id="ebSearch" placeholder="Booking ref, client, event name or type…" autocomplete="off">
                    </div>
                </div>
                <div class="jq-field">
                    <label class="jq-label" for="ebStatus">Status</label>
                    <select class="jq-select" id="ebStatus" data-default="<?= $is_archive_view ? '' : '__active' ?>">
                        <option value="">All statuses</option>
                        <?php if (!$is_archive_view): ?><option value="__active" selected>Active only</option><?php endif; ?>
                        <?php foreach ($is_archive_view ? ['Cancelled'] : ['Pending', 'Approved', 'Completed', 'Cancelled', 'Disabled'] as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="jq-field">
                    <label class="jq-label" for="ebType">Event type</label>
                    <select class="jq-select" id="ebType">
                        <option value="">All types</option>
                        <?php foreach ($eb_types as $t): ?><option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars(html_entity_decode($t, ENT_QUOTES)) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="jq-field">
                    <label class="jq-label" for="ebFrom">Event date from</label>
                    <input class="jq-input" type="date" id="ebFrom">
                </div>
                <div class="jq-field">
                    <label class="jq-label" for="ebTo">Event date to</label>
                    <input class="jq-input" type="date" id="ebTo">
                </div>
                <div class="jq-filter-actions">
                    <button type="button" class="jq-btn jq-btn-cancel" id="ebClear"><i class="bx bx-x"></i> Clear</button>
                </div>
            </div>
        </div>
        <div class="bookings-table-wrapper">
            <?php
            $eb_pay = jq_payment_totals($pdo, array_column($bookings, 'booking_id'));
            $eb_money = static function ($amount) use ($can_hide_money) {
                $text = '₱' . number_format((float)$amount, 2);
                return $can_hide_money
                    ? '<span class="eb-money total-amount masked" data-amount="' . htmlspecialchars($text) . '">₱ ******</span>'
                    : '<span class="eb-money">' . htmlspecialchars($text) . '</span>';
            };
            ?>
            <table class="bookings-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Booking Ref</th>
                        <th>Client Name</th>
                        <th>Event Type</th>
                        <th>Event Date</th>
                        <th>Guests</th>
                        <th>Downpayment</th>
                        <th>Additional Payments</th>
                        <th>Balance</th>
                        <th class="<?= $can_hide_money ? 'eb-th-toggle' : '' ?>">
                            <span class="th-total">Total Price
                                <?php if ($can_hide_money): ?>
                                <button type="button" class="eye-toggle" id="toggleAllTotals" onclick="toggleAllTotals(this)" title="Show or hide financial information" aria-label="Show or hide financial information">
                                    <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                                <?php endif; ?>
                            </span>
                        </th>
                        <th>Booking Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $i => $booking): ?>
                    <?php
                    $statusClass = 'status-' . strtolower($booking['status']);
                    $bid_row = (int)$booking['booking_id'];
                    $row_pay = $eb_pay[$bid_row];
                    $row_total = (float)$booking['grand_total'];
                    $row_balance = round($row_total - $row_pay['paid'], 2);
                    $row_paystatus = jq_payment_status($row_total, $row_pay, (string)$booking['status']);
                    $eb_search = mb_strtolower(html_entity_decode(implode(' ', [$booking['booking_ref'], $booking['fullname'], $booking['event_name'] ?? '', $booking['event_type'], $booking['email'] ?? '', $booking['contact'] ?? '']), ENT_QUOTES));
                    ?>
                    <tr data-search="<?= htmlspecialchars($eb_search) ?>" data-status="<?= htmlspecialchars($booking['status']) ?>"
                        data-type="<?= htmlspecialchars($booking['event_type']) ?>" data-date="<?= htmlspecialchars($booking['event_date']) ?>">
                        <td data-label="#" style="color:var(--secondary);"><?= $i + 1 ?></td>
                        <td data-label="Booking Ref"><strong><?= htmlspecialchars($booking['booking_ref']) ?></strong></td>
                        <td data-label="Client Name"><?= htmlspecialchars($booking['fullname']) ?></td>
                        <td data-label="Event Type"><?= htmlspecialchars($booking['event_type']) ?></td>
                        <td data-label="Event Date" style="white-space:nowrap;"><?= date('M d, Y', strtotime($booking['event_date'])) ?></td>
                        <td data-label="Guests"><?= number_format($booking['headcount']) ?></td>
                        <td data-label="Downpayment"><?= $eb_money($row_pay['down']) ?></td>
                        <td data-label="Additional Payments"><?= $eb_money($row_pay['additional']) ?></td>
                        <td data-label="Balance"><?= $eb_money($row_balance) ?><br><span class="eb-paystatus is-<?= strtolower(str_replace(' ', '-', $row_paystatus)) ?>"><?= $row_paystatus ?></span></td>
                        <td data-label="Total Price"><?= $eb_money($row_total) ?></td>
                        <td data-label="Booking Status">
                            <?php if ($is_archive_view): ?>
                                <span class="status-pill status-cancelled">Cancelled</span>
                                <?php if (!empty($booking['archive_expires_at'])): $eb_days = jq_archive_days_left($booking['archive_expires_at']); ?>
                                <small class="eb-expiry" title="Archived <?= htmlspecialchars(date('M d, Y h:i A', strtotime($booking['archived_at'] ?? 'now'))) ?>">Deletes <?= date('M d, Y', strtotime($booking['archive_expires_at'])) ?><br><?= $eb_days ?> day<?= $eb_days === 1 ? '' : 's' ?> left</small>
                                <?php endif; ?>
                            <?php elseif ($booking['status'] === 'Completed'): ?>
                                <span class="status-pill status-completed">Completed</span>
                                <?php if (!empty($booking['completed_at'])): ?><small class="eb-expiry"><?= date('M d, Y', strtotime($booking['completed_at'])) ?></small><?php endif; ?>
                            <?php else: ?>
                            <select class="status-select <?= $statusClass ?>" data-booking-id="<?= $bid_row ?>" onchange="updateStatusInline(this)" title="Click to change status" aria-label="Booking status">
                                <?php foreach (['Pending', 'Approved', 'Cancelled', 'Disabled'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= $booking['status'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions">
                            <div class="dropdown">
                                <button type="button" class="btn-action btn-view dropdown-toggle" data-bs-toggle="dropdown" data-bs-config='{"popperConfig":{"strategy":"fixed"}}' aria-expanded="false">Actions</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button type="button" class="dropdown-item" onclick="viewBooking(<?= $bid_row ?>, 'details')"><i class="bx bx-show"></i> View details</button></li>
                                    <li><button type="button" class="dropdown-item" onclick="viewBooking(<?= $bid_row ?>, 'inclusions')"><i class="bx bx-list-ul"></i> View inclusions</button></li>
                                    <li><button type="button" class="dropdown-item" onclick="viewBooking(<?= $bid_row ?>, 'payments')"><i class="bx bx-wallet"></i> <?= $is_archive_view ? 'Payment history' : 'Payments' ?></button></li>
                                    <li><button type="button" class="dropdown-item" onclick="viewBooking(<?= $bid_row ?>, 'attachments')"><i class="bx bx-paperclip"></i> Attachments</button></li>
                                    <li><button type="button" class="dropdown-item" onclick="viewBooking(<?= $bid_row ?>, 'preparation')"><i class="bx bx-task"></i> Preparation</button></li>
                                    <li><a class="dropdown-item" href="event_bookings.php?soa=<?= $bid_row ?>" target="_blank" rel="noopener"><i class="bx bx-receipt"></i> Generate SOA</a></li>
                                    <li><button type="button" class="dropdown-item" onclick="printBookingById(<?= $bid_row ?>)"><i class="bx bx-printer"></i> Print / Save as PDF</button></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <?php if ($is_archive_view): ?>
                                    <li><button type="button" class="dropdown-item" onclick="restoreBooking(<?= $bid_row ?>, '<?= htmlspecialchars(addslashes($booking['booking_ref'])) ?>')"><i class="bx bx-archive-out"></i> Restore</button></li>
                                    <li><button type="button" class="dropdown-item" onclick="deleteBooking(<?= $bid_row ?>, <?= htmlspecialchars(json_encode($booking['booking_ref']), ENT_QUOTES) ?>)"><i class="bx bx-trash"></i> Delete</button></li>
                                    <?php else: ?>
                                    <li><button type="button" class="dropdown-item" onclick="editBooking(<?= $bid_row ?>)"><i class="bx bx-edit-alt"></i> Edit booking</button></li>
                                    <?php if (in_array($booking['status'], ['Pending', 'Approved'], true) && $row_total > 0 && $row_paystatus !== 'Fully Paid'): ?>
                                    <li><span class="dropdown-item disabled" title="Needs the full payment with every receipt verified"><i class="bx bx-badge-check"></i> Mark as Completed (payment not verified)</span></li>
                                    <?php elseif (in_array($booking['status'], ['Pending', 'Approved'], true)): ?>
                                    <li><button type="button" class="dropdown-item" onclick="completeBooking(<?= $bid_row ?>, <?= htmlspecialchars(json_encode($booking['booking_ref']), ENT_QUOTES) ?>, <?= $booking['event_date'] > date('Y-m-d') ? 'true' : 'false' ?>)"><i class="bx bx-badge-check"></i> Mark as Completed</button></li>
                                    <?php endif; ?>
                                    <li><button type="button" class="dropdown-item" onclick="archiveBooking(<?= $bid_row ?>, '<?= htmlspecialchars(addslashes($booking['booking_ref'])) ?>')"><i class="bx bx-archive-in"></i> Archive</button></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="eb-no-match" id="ebNoMatch" style="display:none;"><td colspan="12"><i class="bx bx-search-alt" style="font-size:1.6rem;"></i><br>No bookings match your filters.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="jq-table-footer">
            <span class="jq-muted jq-small" id="ebCount">Showing <?= count($bookings) ?> of <?= count($bookings) ?> booking(s)</span>
        </div>
        </div>
    <?php endif; ?>
</div>

<!-- VIEW BOOKING MODAL -->
<div id="viewModal" class="view-modal-overlay">
    <div class="view-modal">
        <div class="view-modal-header">
            <h2 id="viewModalTitle">Booking Details</h2>
            <button class="view-modal-close" onclick="closeViewModal()">✕</button>
        </div>
        <div class="view-modal-body" id="viewModalBody">
            <p style="text-align:center; color:var(--secondary); padding: 30px 0;">Loading...</p>
        </div>
        <div class="view-modal-footer" id="viewModalFooter" style="display:none;">
            <button type="button" class="btn-action btn-view" onclick="printBooking()"><i class="bx bx-printer"></i> Print</button>
            <button type="button" class="btn-action btn-edit" id="bookingPdfBtn" onclick="saveBookingPdf()"><i class="bx bxs-file-pdf"></i> Save as PDF</button>
            <a class="btn-action btn-view" id="bookingSoaBtn" href="#" target="_blank" rel="noopener" style="text-decoration:none;"><i class="bx bx-receipt"></i> Generate SOA</a>
            <button type="button" class="btn-action btn-view" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- Hidden archive / restore forms -->
<form id="archiveForm" method="POST" style="display:none;">
    <input type="hidden" name="archive_id" id="archiveId">
</form>
<form id="deleteForm" method="POST" style="display:none;">
    <?= jq_csrf_field() ?>
    <input type="hidden" name="delete_id" id="deleteId">
</form>
<form id="completeForm" method="POST" style="display:none;">
    <?= jq_csrf_field() ?>
    <input type="hidden" name="booking_action" value="complete_booking">
    <input type="hidden" name="booking_id" id="completeId">
</form>
<form id="ebItemDeleteForm" method="POST" style="display:none;">
    <?= jq_csrf_field() ?>
    <input type="hidden" name="booking_action" id="ebDeleteAction">
    <input type="hidden" name="booking_id" id="ebDeleteBooking">
    <input type="hidden" name="payment_id" id="ebDeletePayment">
    <input type="hidden" name="attachment_id" id="ebDeleteAttachment">
</form>

<!-- Event types -->
<div class="modal fade" id="eventTypesModal" tabindex="-1" aria-labelledby="eventTypesTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="eventTypesTitle"><i class="bx bx-category me-2"></i>Event Types</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" class="d-flex gap-2 mb-3">
                    <?= jq_csrf_field() ?>
                    <input type="hidden" name="booking_action" value="add_event_type">
                    <input type="text" class="form-control" name="event_type_name" maxlength="100" required placeholder="New event type" aria-label="New event type">
                    <button type="submit" class="btn btn-primary" style="white-space:nowrap;"><i class="bx bx-plus me-1"></i>Add</button>
                </form>
                <?php foreach ($manage_types as $et): ?>
                <div class="d-flex align-items-center gap-2 py-2 border-top flex-wrap">
                    <form method="POST" class="d-flex gap-2 flex-grow-1" style="min-width:180px;">
                        <?= jq_csrf_field() ?>
                        <input type="hidden" name="booking_action" value="rename_event_type">
                        <input type="hidden" name="event_type_id" value="<?= (int)$et['event_type_id'] ?>">
                        <input type="text" class="form-control form-control-sm" name="event_type_name" maxlength="100" required value="<?= htmlspecialchars($et['event_type_name']) ?>" aria-label="Event type name">
                        <button type="submit" class="btn btn-sm btn-label-primary">Save</button>
                    </form>
                    <small class="text-muted"><?= (int)$et['booking_count'] ?> booking(s)</small>
                    <form method="POST">
                        <?= jq_csrf_field() ?>
                        <input type="hidden" name="booking_action" value="toggle_event_type">
                        <input type="hidden" name="event_type_id" value="<?= (int)$et['event_type_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-label-primary"><?= $et['is_active'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <?php if ((int)$et['booking_count'] === 0): ?>
                    <form method="POST" onsubmit="return confirm('Delete this event type?');">
                        <?= jq_csrf_field() ?>
                        <input type="hidden" name="booking_action" value="delete_event_type">
                        <input type="hidden" name="event_type_id" value="<?= (int)$et['event_type_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-label-danger" aria-label="Delete event type"><i class="bx bx-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<form id="restoreForm" method="POST" style="display:none;">
    <input type="hidden" name="restore_id" id="restoreId">
</form>

<script>
// ─── MONEY PRIVACY: one eye for the Owner. An Admin has no eye and always sees amounts. ───
const MASKED_TEXT = '₱ ******';
const CAN_HIDE_MONEY = <?= $can_hide_money ? 'true' : 'false' ?>;
let moneyShown = !CAN_HIDE_MONEY;

function toggleAllTotals(btn) {
    moneyShown = !moneyShown;
    document.querySelectorAll('.bookings-table .total-amount').forEach(el => {
        el.textContent = moneyShown ? el.dataset.amount : MASKED_TEXT;
        el.classList.toggle('masked', !moneyShown);
    });
    btn.classList.toggle('revealed', moneyShown);
    if (currentBooking) renderBookingModal();
}

// ─── SEARCH & FILTERS (client-side, all rows are already loaded) ───
(function () {
    const q = document.getElementById('ebSearch');
    if (!q) return;
    const status = document.getElementById('ebStatus'), type = document.getElementById('ebType');
    const from = document.getElementById('ebFrom'), to = document.getElementById('ebTo');
    const rows = Array.from(document.querySelectorAll('.bookings-table tbody tr[data-search]'));
    const noMatch = document.getElementById('ebNoMatch'), count = document.getElementById('ebCount');
    const KEY = 'ebFilters:' + (new URLSearchParams(location.search).get('view') || 'dashboard');

    function apply() {
        const term = q.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach(tr => {
            const d = tr.dataset;
            const ok = (!term || d.search.indexOf(term) !== -1)
                && (!status.value || (status.value === '__active' ? (d.status === 'Pending' || d.status === 'Approved') : d.status === status.value))
                && (!type.value || d.type === type.value)
                && (!from.value || d.date >= from.value)
                && (!to.value || d.date <= to.value);
            tr.classList.toggle('eb-hidden', !ok);
            if (ok) shown++;
        });
        noMatch.style.display = shown ? 'none' : '';
        count.textContent = 'Showing ' + shown + ' of ' + rows.length + ' booking(s)';
        try { sessionStorage.setItem(KEY, JSON.stringify({ q: q.value, s: status.value, t: type.value, f: from.value, to: to.value })); } catch (e) {}
    }
    window.ebApplyFilters = apply;
    window.ebQuickStatus = function (s) {
        status.value = s || '';
        apply();
        q.closest('.jq-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    [q, from, to].forEach(el => el.addEventListener('input', apply));
    [status, type].forEach(el => el.addEventListener('change', apply));
    document.getElementById('ebClear').addEventListener('click', () => { q.value = ''; status.value = status.dataset.default || ''; type.value = ''; from.value = ''; to.value = ''; apply(); q.focus(); });
    try {
        const saved = JSON.parse(sessionStorage.getItem(KEY) || 'null');
        if (saved) { q.value = saved.q || ''; status.value = saved.s || ''; type.value = saved.t || ''; from.value = saved.f || ''; to.value = saved.to || ''; if (status.selectedIndex < 0) status.value = status.dataset.default || ''; }
    } catch (e) {}
    apply();
})();
if (typeof window.ebQuickStatus !== 'function') { window.ebQuickStatus = function () {}; }

// Keeps the summary tiles in step with the rows after a status change.
function ebRecountTiles() {
    const n = { Pending: 0, Approved: 0, Cancelled: 0, Disabled: 0, Completed: 0 };
    document.querySelectorAll('.bookings-table tbody tr[data-search]').forEach(tr => { if (tr.dataset.status in n) n[tr.dataset.status]++; });
    n.Active = n.Pending + n.Approved;
    document.querySelectorAll('[data-tile]').forEach(el => { el.textContent = n[el.dataset.tile]; });
}

// ─── STATUS INLINE UPDATE ───────────────────────────────
function updateStatusInline(selectEl) {
    const bookingId = selectEl.dataset.bookingId;
    const newStatus = selectEl.value;
    const row = selectEl.closest('tr');
    const previous = row && row.dataset.status ? row.dataset.status : newStatus;

    // Visual feedback: update badge color
    selectEl.className = 'status-select status-' + newStatus.toLowerCase();
    selectEl.disabled = true;

    const revert = () => {
        selectEl.value = previous;
        selectEl.className = 'status-select status-' + previous.toLowerCase();
    };

    fetch('event_bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `ajax_action=update_status_inline&booking_id=${bookingId}&status=${encodeURIComponent(newStatus)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (row) { row.dataset.status = newStatus; if (window.ebApplyFilters) window.ebApplyFilters(); }
            ebRecountTiles();
            Swal.fire({
                toast: true, position: 'top-end', icon: 'success',
                title: `Status updated to <strong>${newStatus}</strong>`,
                showConfirmButton: false, timer: 2200, timerProgressBar: true
            });
        } else {
            revert();
            Swal.fire({ icon: 'error', title: 'Update failed', text: data.error || 'Unknown error', customClass: { confirmButton: 'jq-swal-primary' } });
        }
    })
    .catch(() => {
        revert();
        Swal.fire({ icon: 'error', title: 'Network error', text: 'Could not update status.', customClass: { confirmButton: 'jq-swal-primary' } });
    })
    .finally(() => { selectEl.disabled = false; });
}

// ─── EDIT: redirect to wizard in edit mode ──────────────
function editBooking(bookingId) {
    window.location.href = 'event_bookings.php?view=wizard&edit_id=' + bookingId;
}

// ─── VIEW: details window, print and PDF share one document ───
let currentBooking = null;
const COMPANY_NAME = "Janet's Quality Catering Services";

const bkMoney = n => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const bkDate  = d => d ? new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
function bkTime(t) {
    if (!t) return 'N/A';
    const [h, m] = String(t).split(':').map(Number);
    return ((h % 12) || 12) + ':' + String(m).padStart(2, '0') + ' ' + (h < 12 ? 'AM' : 'PM');
}
function bkPhone(v) {
    const m = /^\+63(9\d{9})$/.exec(String(v || ''));
    return m ? '+63 ' + m[1] : v;
}
function bkRows(rows) {
    return rows.filter(r => r[1] !== null && r[1] !== undefined && r[1] !== '')
        .map(r => '<tr><th>' + escapeHtml(r[0]) + '</th><td>' + escapeHtml(r[1]) + '</td></tr>').join('');
}
function bkInclusionHtml(d) {
    const groups = d.inclusions || {};
    const names = Object.keys(groups);
    if (!names.length) return '<p class="none">No inclusions are recorded for this booking.</p>';
    return names.map(n => '<div class="eb-incl-title">' + escapeHtml(n) + '</div><ul class="eb-list">' + groups[n].map(t => '<li>' + escapeHtml(t) + '</li>').join('') + '</ul>').join('');
}
// Sections shared by every view. Everything from the database passes through escapeHtml.
// money() masks amounts on screen while the Owner has them hidden. Print and PDF pass real amounts.
function bookingSections(d, money = bkMoney) {
    const items = Array.isArray(d.items) ? d.items : [];
    const itemRows = items.map(i => '<tr><td>' + escapeHtml(i.item_name) + (Number(i.quantity) > 1 ? ' (x' + Number(i.quantity) + ')' : '') + '</td><td>' + (i.ref_type === 'bundle' ? 'Bundle / Add-on' : 'Food item') + '</td><td class="num">' + escapeHtml(money(i.unit_price)) + (i.price_mode === 'per_guest' ? ' / guest' : '') + '</td><td class="num">' + escapeHtml(money(i.line_total)) + '</td></tr>').join('');
    const m = d.money || { down: 0, additional: 0, paid: 0, balance: d.grand_total, status: 'Unpaid' };
    const pay = [
        ['Package', d.package_name],
        ['Price per guest', d.package_price > 0 ? money(d.package_price) : ''],
        ['Subtotal', d.subtotal > 0 ? money(d.subtotal) : ''],
        ['Service tax', d.service_tax > 0 ? money(d.service_tax) + ' (' + Number(d.service_tax_rate) + '%)' : ''],
        ['Grand total', money(d.grand_total)],
        ['Downpayments', money(m.down)],
        ['Additional payments', money(m.additional)],
        ['Total recorded', money(m.paid)],
        ['Verified by receipt', money(m.verified || 0)],
        ['Balance', money(m.balance)],
        ['Payment status', m.status],
        ['Booking status', d.status],
        ['Completed on', d.completed_at || ''],
        ['Archived on', Number(d.is_archived) === 1 ? d.archived_at : ''],
        ['Scheduled for deletion', Number(d.is_archived) === 1 ? d.archive_expires_at : ''],
    ];
    const place = [d.barangay, d.city, d.province, d.region].filter(Boolean).join(', ');
    return '<h3>Client Information</h3><table class="kv">' + bkRows([['Full name', d.fullname], ['Email', d.email], ['Contact number', bkPhone(d.contact)], ['Postal code', d.postal_code]]) + '</table>'
        + '<h3>Event Information</h3><table class="kv">' + bkRows([['Event name', d.event_name], ['Event type', d.event_type], ['Date', bkDate(d.event_date)], ['Time', bkTime(d.event_time)], ['Number of guests', Number(d.headcount || 0).toLocaleString('en-PH')], ['Venue', d.venue_address], ['Location', place], ['Backdrop', d.backdrop_name || 'Not recorded']]) + '</table>'
        + (d.backdrop_path ? '<img class="bk-backdrop" src="' + escapeHtml(d.backdrop_path) + '" alt="' + escapeHtml(d.backdrop_name || 'Selected backdrop') + '" style="display:block;max-width:100%;max-height:240px;border-radius:8px;margin:10px 0 4px;" onerror="this.remove()">' : '')
        + '<h3>Package and Food Inclusions</h3>' + bkInclusionHtml(d)
        + '<h3>Selected Items</h3>' + (itemRows ? '<table class="items view-items"><thead><tr><th>Item</th><th>Type</th><th class="num">Unit price</th><th class="num">Line total</th></tr></thead><tbody>' + itemRows + '</tbody></table>' : '<p class="none">No additional items were selected.</p>')
        + '<h3>Special Requests</h3><p class="notes">' + (d.notes ? escapeHtml(d.notes) : 'None recorded.') + '</p>'
        + '<h3>Payment and Status</h3><table class="kv">' + bkRows(pay) + '</table>';
}
// Fixed light colours, so printing and PDF never pick up dark mode from the page.
function bookingDocument(d) {
    const css = `*{box-sizing:border-box}html,body{margin:0;background:#fff}body{font-family:Arial,Helvetica,sans-serif;color:#1A1A2E;font-size:12.5px;line-height:1.45;width:794px;padding:40px 48px}
header{display:flex;align-items:center;gap:14px;border-bottom:3px solid #7B5FC7;padding-bottom:14px}header img{width:62px;height:62px;border-radius:10px;object-fit:cover}
.co{font-size:18px;font-weight:700}.sub{color:#5E5A78;font-size:12px}.meta{margin-left:auto;text-align:right;font-size:11.5px;color:#5E5A78}.meta strong{display:block;font-size:13px;color:#1A1A2E}
h1{font-size:17px;margin:18px 0 2px}h3{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#6A4FB5;margin:16px 0 6px;padding-bottom:4px;border-bottom:1px solid #E2D6F0}
table{width:100%;border-collapse:collapse}table.kv th{width:32%;text-align:left;font-weight:600;color:#5E5A78;padding:4px 8px 4px 0;vertical-align:top}table.kv td{padding:4px 0;font-weight:500;word-break:break-word}
table.items th{text-align:left;background:#F8F5FC;padding:6px 8px;font-size:11px;border-bottom:1px solid #CDBDE3}table.items td{padding:6px 8px;border-bottom:1px solid #E2D6F0}.num{text-align:right!important;white-space:nowrap}
p{margin:0}.notes{white-space:pre-wrap}.none{color:#5E5A78}footer{margin-top:22px;padding-top:8px;border-top:1px solid #E2D6F0;font-size:10.5px;color:#77748F;text-align:center}
tr{page-break-inside:avoid}h3{page-break-after:avoid}@page{size:A4;margin:12mm 0}@media print{body{width:auto;padding:0 48px;-webkit-print-color-adjust:exact;print-color-adjust:exact}}`;
    const printed = new Date().toLocaleString('en-PH', { year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escapeHtml('Booking ' + (d.booking_ref || '')) + '</title><style>' + css + '</style></head><body>'
        + '<header><img src="static/images/logo.png" alt=""><div><div class="co">' + escapeHtml(COMPANY_NAME) + '</div><div class="sub">Event Booking Details</div></div>'
        + '<div class="meta"><strong>' + escapeHtml(d.booking_ref || '') + '</strong>Status: ' + escapeHtml(d.status || 'N/A') + (Number(d.is_archived) ? ' (Archived)' : '') + '<br>Booked on ' + escapeHtml(bkDate(d.created_at)) + '</div></header>'
        + bookingSections(d) + '<footer>Printed ' + escapeHtml(printed) + ' · ' + escapeHtml(COMPANY_NAME) + '</footer></body></html>';
}
// Hidden frame holding the document, ready once its pictures have loaded (or failed)
function bookingFrame(d, offscreen) {
    return new Promise(resolve => {
        const f = document.createElement('iframe');
        f.setAttribute('aria-hidden', 'true');
        f.style.cssText = 'position:fixed;left:-10000px;top:0;width:794px;height:1123px;border:0;' + (offscreen ? '' : 'visibility:hidden;');
        f.onload = () => {
            // Wait for the logo and the backdrop, but never hang on a picture that does not answer.
            const waiting = [...f.contentDocument.images].filter(img => !img.complete)
                .map(img => new Promise(done => { img.addEventListener('load', done); img.addEventListener('error', done); }));
            Promise.race([Promise.all(waiting), new Promise(done => setTimeout(done, 8000))]).then(() => resolve(f));
        };
        f.srcdoc = bookingDocument(d);
        document.body.appendChild(f);
    });
}
let currentTab = 'details';
const PAY_METHODS = <?= json_encode(JQ_PAYMENT_METHODS) ?>, DOC_TYPES = <?= json_encode(JQ_DOCUMENT_TYPES) ?>, PREP_STATUSES = <?= json_encode(JQ_PREPARATION_STATUSES) ?>;
const opts = (list, selected) => list.map(v => '<option value="' + escapeHtml(v) + '"' + (v === selected ? ' selected' : '') + '>' + escapeHtml(v) + '</option>').join('');
const shownMoney = n => moneyShown ? bkMoney(n) : MASKED_TEXT;
const hiddenFields = (action, id) => '<input type="hidden" name="booking_action" value="' + action + '"><input type="hidden" name="booking_id" value="' + id + '">';

function paymentsPane(d) {
    const m = d.money, open = Number(d.is_archived) !== 1 && ['Pending', 'Approved', 'Completed'].includes(d.status);
    const bid = Number(d.booking_id);
    const rows = (d.payments || []).map(p => {
        const pid = Number(p.payment_id), state = p.verification_status;
        const review = '<span class="eb-paystatus is-' + state.toLowerCase().replace(/ /g, '-') + '">' + escapeHtml(state) + '</span>'
            + (p.reviewed_by_name ? '<br><small>' + escapeHtml(p.reviewed_by_name) + ', ' + escapeHtml(bkDate(p.reviewed_at)) + '</small>' : '')
            + (p.review_notes ? '<br><small>' + (state === 'Rejected' ? 'Replacement requested: ' : '') + escapeHtml(p.review_notes) + '</small>' : '')
            + (state === 'Receipt Submitted' ? '<br><small>Open the receipt to review it.</small>' : '');
        let actions = '';
        const olds = (d.old_receipts || []).filter(h => Number(h.payment_id) === pid).map(h => '<br><small>Earlier receipt ' + escapeHtml(h.verification_status.toLowerCase()) + (h.reviewed_by_name ? ' by ' + escapeHtml(h.reviewed_by_name) : '') + (h.review_notes ? ': ' + escapeHtml(h.review_notes) : '') + ' · <a href="event_bookings.php?file=old_receipt&id=' + Number(h.history_id) + '&view=1" target="_blank" rel="noopener">View</a></small>').join('');
        const uploadForm = label => '<form method="POST" enctype="multipart/form-data">' + hiddenFields('upload_receipt', bid) + '<input type="hidden" name="payment_id" value="' + pid + '"><label class="btn-action btn-view"><i class="bx bx-upload"></i> ' + label + '<input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none;" onchange="this.form.submit()"></label></form>';
        if (open && state === 'Awaiting Receipt') {
            actions = '<form method="POST" enctype="multipart/form-data">' + hiddenFields('upload_receipt', bid) + '<input type="hidden" name="payment_id" value="' + pid + '"><label class="btn-action btn-view"><i class="bx bx-upload"></i> Upload Receipt<input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none;" onchange="this.form.submit()"></label></form>'
                + '<button type="button" class="btn-action btn-delete" onclick="deleteBookingItem(\'delete_payment\', ' + bid + ', ' + pid + ')" aria-label="Delete payment"><i class="bx bx-trash"></i></button>';
        } else if (open && state === 'Rejected') {
            actions = uploadForm('Upload Replacement');
        } else if (open && (state === 'Receipt Submitted' || state === 'Pending Verification')) {
            actions = '<button type="button" class="btn-action btn-edit" onclick="reviewPayment(true, ' + bid + ', ' + pid + ')"><i class="bx bx-check"></i> Verify</button>'
                + '<button type="button" class="btn-action btn-delete" onclick="reviewPayment(false, ' + bid + ', ' + pid + ')"><i class="bx bx-x"></i> Reject</button>';
        } else if (open && state === 'Verified') {
            actions = '<button type="button" class="btn-action btn-delete" onclick="reviewPayment(false, ' + bid + ', ' + pid + ')"><i class="bx bx-x"></i> Reject</button>';
        }
        return '<tr><td>' + escapeHtml(bkDate(p.payment_date)) + '</td><td>' + escapeHtml(p.payment_type) + '</td><td>' + escapeHtml(p.payment_method) + (p.reference_no ? '<br><small>' + escapeHtml(p.reference_no) + '</small>' : '') + '</td><td class="num">' + escapeHtml(shownMoney(p.amount)) + '</td><td>'
            + (p.receipt_name ? '<a href="event_bookings.php?file=receipt&id=' + pid + '&view=1" target="_blank" rel="noopener">View</a> · <a href="event_bookings.php?file=receipt&id=' + pid + '">Download</a>' : 'None')
            + olds + '</td><td>' + review + '</td><td><div class="eb-pay-actions">' + actions + '</div></td></tr>';
    }).join('');
    const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    return '<div class="eb-summary"><div><small>Total booking price</small><strong>' + shownMoney(d.grand_total) + '</strong></div><div><small>Downpayments</small><strong>' + shownMoney(m.down) + '</strong></div><div><small>Additional payments</small><strong>' + shownMoney(m.additional) + '</strong></div><div><small>Total recorded</small><strong>' + shownMoney(m.paid) + '</strong></div><div><small>Verified by receipt</small><strong>' + shownMoney(m.verified) + '</strong></div><div><small>Balance</small><strong>' + shownMoney(m.balance) + '</strong></div><div><small>Payment status</small><strong>' + escapeHtml(m.status) + '</strong></div></div>'
        + '<h3>Payment History</h3>' + (rows ? '<div style="overflow-x:auto;"><table class="items view-items"><thead><tr><th>Date</th><th>Type</th><th>Method</th><th class="num">Amount</th><th>Receipt</th><th>Verification</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '<p class="none">No payments recorded yet.</p>')
        + (open && m.balance > 0 ? '<form method="POST" enctype="multipart/form-data" class="eb-subform" id="paymentForm"><h3>Record a Payment</h3>' + hiddenFields('record_payment', d.booking_id)
            + '<div class="eb-grid2"><div><label for="payType">Type *</label><select id="payType" name="payment_type" required>' + opts(<?= json_encode(JQ_PAYMENT_TYPES) ?>, m.paid > 0 ? 'Additional Payment' : 'Downpayment') + '</select></div>'
            + '<div><label for="payAmount">Amount (₱) *</label><input id="payAmount" type="number" name="amount" min="0.01" step="0.01" max="' + Number(m.balance) + '" required></div>'
            + '<div><label for="payDate">Payment date *</label><input id="payDate" type="date" name="payment_date" max="' + today + '" value="' + today + '" required></div>'
            + '<div><label for="payMethod">Method *</label><select id="payMethod" name="payment_method" required>' + opts(PAY_METHODS, 'Cash') + '</select></div>'
            + '<div><label for="payRef">Reference number</label><input id="payRef" name="reference_no" maxlength="100"></div>'
            + '<div><label for="payReceipt">Proof of payment / official receipt (PDF or image, up to 10 MB)</label><input id="payReceipt" type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp"><small class="eb-receipt-rule">Required to confirm payment. A booking is not Fully Paid until its receipts are uploaded and verified.</small></div></div>'
            + '<label for="payNotes">Notes</label><input id="payNotes" name="notes" maxlength="255" style="margin-bottom:10px;">'
            + '<button type="submit" class="btn-action btn-edit"><i class="bx bx-save"></i> Save Payment</button></form>' : '');
}
function attachmentsPane(d) {
    const open = Number(d.is_archived) !== 1;
    const rows = (d.attachments || []).map(a => {
        const id = Number(a.attachment_id);
        return '<tr><td>' + escapeHtml(a.document_type) + '</td><td style="word-break:break-word;">' + escapeHtml(a.original_name) + '<br><small>' + escapeHtml(bkDate(a.created_at)) + ', ' + (Number(a.file_size) / 1048576).toFixed(2) + ' MB</small></td><td style="white-space:nowrap;"><a href="event_bookings.php?file=attachment&id=' + id + '&view=1" target="_blank" rel="noopener">View</a> · <a href="event_bookings.php?file=attachment&id=' + id + '">Download</a></td><td style="white-space:nowrap;">'
            + (open ? '<button type="button" class="btn-action btn-view" onclick="replaceAttachment(' + id + ', ' + escapeHtml(JSON.stringify(a.document_type)).replace(/&quot;/g, "'") + ')">Replace</button> <button type="button" class="btn-action btn-delete" onclick="deleteBookingItem(\'delete_attachment\', ' + Number(d.booking_id) + ', ' + id + ')" aria-label="Delete attachment"><i class="bx bx-trash"></i></button>' : '') + '</td></tr>';
    }).join('');
    return '<h3>Attachments for ' + escapeHtml(d.booking_ref) + '</h3>' + (rows ? '<div style="overflow-x:auto;"><table class="items view-items"><thead><tr><th>Document</th><th>File</th><th></th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '<p class="none">No attachments yet.</p>')
        + (open ? '<form method="POST" enctype="multipart/form-data" class="eb-subform" id="attachmentForm"><h3 id="attachmentHeading">Upload an Attachment</h3>' + hiddenFields('upload_attachment', d.booking_id) + '<input type="hidden" name="replace_id" id="attachmentReplace" value="0">'
            + '<div class="eb-grid2"><div><label for="docType">Document type *</label><select id="docType" name="document_type" required>' + opts(DOC_TYPES, 'Signed Contract') + '</select></div>'
            + '<div><label for="docFile">File (PDF, image, DOC or DOCX, up to 10 MB) *</label><input id="docFile" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" required></div></div>'
            + '<button type="submit" class="btn-action btn-edit"><i class="bx bx-upload"></i> Upload</button></form>' : '');
}
function preparationPane(d) {
    const open = Number(d.is_archived) !== 1;
    const facts = '<table class="kv">' + bkRows([['Preparation status', d.preparation_status], ['Backdrop', d.backdrop_name], ['Guests', Number(d.headcount || 0).toLocaleString('en-PH')], ['Event date', bkDate(d.event_date)], ['Special requests', d.notes]]) + '</table>';
    return '<h3>Preparation Requirements</h3>' + facts + (d.preparation_notes && !open ? '<p class="notes">' + escapeHtml(d.preparation_notes) + '</p>' : '')
        + (open ? '<form method="POST" class="eb-subform">' + hiddenFields('update_preparation', d.booking_id) + '<div class="eb-grid2"><div><label for="prepStatus">Preparation status</label><select id="prepStatus" name="preparation_status">' + opts(PREP_STATUSES, d.preparation_status) + '</select></div></div>'
            + '<label for="prepNotes">Preparation notes (the Supervisor sees these)</label><textarea id="prepNotes" name="preparation_notes" rows="4" maxlength="2000" style="margin-bottom:10px;">' + escapeHtml(d.preparation_notes || '') + '</textarea><button type="submit" class="btn-action btn-edit"><i class="bx bx-save"></i> Save</button></form>' : '');
}
function renderBookingModal() {
    const d = currentBooking, body = document.getElementById('viewModalBody');
    const tabs = [['details', 'Details'], ['inclusions', 'Inclusions'], ['payments', 'Payments'], ['attachments', 'Attachments (' + (d.attachments || []).length + ')'], ['preparation', 'Preparation']];
    const pane = { details: () => bookingSections(d, shownMoney), inclusions: () => '<h3>Package and Food Inclusions</h3>' + bkInclusionHtml(d), payments: () => paymentsPane(d), attachments: () => attachmentsPane(d), preparation: () => preparationPane(d) };
    body.innerHTML = '<div class="eb-tabs" role="tablist">' + tabs.map(t => '<button type="button" role="tab" class="eb-tab' + (t[0] === currentTab ? ' active' : '') + '" onclick="showBookingTab(\'' + t[0] + '\')">' + escapeHtml(t[1]) + '</button>').join('') + '</div><div class="booking-sections">' + pane[currentTab]() + '</div>';
}
function showBookingTab(tab) { currentTab = tab; renderBookingModal(); }
function replaceAttachment(id, type) {
    document.getElementById('attachmentReplace').value = id;
    document.getElementById('docType').value = type;
    document.getElementById('attachmentHeading').textContent = 'Replace This Attachment';
    document.getElementById('docFile').focus();
    document.getElementById('attachmentForm').scrollIntoView({ block: 'center' });
}
function reviewPayment(approve, bookingId, paymentId) {
    Swal.fire(approve
        ? { title: 'Verify this payment?', text: 'Confirm you have opened the receipt and checked it against the amount and date.', icon: 'question', showCancelButton: true, reverseButtons: true, confirmButtonText: 'Yes, Verify', cancelButtonText: 'Cancel', customClass: { cancelButton: 'jq-swal-cancel' } }
        : { title: 'Reject this receipt?', text: 'It stays on record, the amount no longer counts as paid, and a replacement receipt is requested.', icon: 'warning', input: 'text', inputLabel: 'Reason and what the replacement should show', inputAttributes: { maxlength: 255, 'aria-label': 'Reason for rejecting' }, inputValidator: v => (v && v.trim()) ? undefined : 'Enter the reason.', showCancelButton: true, reverseButtons: true, focusCancel: true, confirmButtonText: 'Reject', cancelButtonText: 'Cancel', customClass: { confirmButton: 'jq-swal-delete', cancelButton: 'jq-swal-cancel' } }
    ).then(r => {
        if (!r.isConfirmed) return;
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = 'event_bookings.php';
        f.innerHTML = hiddenFields(approve ? 'verify_payment' : 'reject_payment', bookingId) + '<input type="hidden" name="payment_id" value="' + Number(paymentId) + '"><input type="hidden" name="review_notes">';
        f.elements.review_notes.value = approve ? '' : r.value;
        document.body.appendChild(f);
        f.submit();
    });
}
function deleteBookingItem(action, bookingId, itemId) {
    const isPayment = action === 'delete_payment';
    Swal.fire({ title: isPayment ? 'Delete this payment?' : 'Delete this attachment?', text: isPayment ? 'The balance will be recalculated. This cannot be undone.' : 'The file will be permanently deleted. This cannot be undone.', icon: 'warning', showCancelButton: true, reverseButtons: true, focusCancel: true, confirmButtonText: 'Delete', cancelButtonText: 'Cancel', customClass: { confirmButton: 'jq-swal-delete', cancelButton: 'jq-swal-cancel' } }).then(r => {
        if (!r.isConfirmed) return;
        document.getElementById('ebDeleteAction').value = action;
        document.getElementById('ebDeleteBooking').value = bookingId;
        document.getElementById('ebDeletePayment').value = isPayment ? itemId : '';
        document.getElementById('ebDeleteAttachment').value = isPayment ? '' : itemId;
        document.getElementById('ebItemDeleteForm').submit();
    });
}
function completeBooking(bookingId, ref, isFuture) {
    Swal.fire({ title: 'Mark as Completed?', html: '<p>Booking <strong>' + escapeHtml(ref) + '</strong> will be marked Completed and saved to Client Records.</p><p style="font-size:.85rem;">' + (isFuture ? '<strong>This event date has not arrived yet.</strong> ' : '') + 'Its verified payments are kept. A completed booking keeps this status.</p>', icon: 'question', showCancelButton: true, reverseButtons: true, focusCancel: true, confirmButtonText: 'Yes, Complete', cancelButtonText: 'Cancel', customClass: { confirmButton: 'jq-swal-restore', cancelButton: 'jq-swal-cancel' }, allowOutsideClick: false }).then(r => {
        if (r.isConfirmed) {
            document.getElementById('completeId').value = bookingId;
            showLoading('Saving...');
            document.getElementById('completeForm').submit();
        }
    });
}
function viewBooking(bookingId, tab) {
    const body = document.getElementById('viewModalBody'), footer = document.getElementById('viewModalFooter');
    currentBooking = null;
    currentTab = tab || 'details';
    footer.style.display = 'none';
    body.innerHTML = '<p style="text-align:center; color:var(--secondary); padding:30px 0;">Loading...</p>';
    document.getElementById('viewModal').classList.add('active');

    fetch('event_bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=get_booking&booking_id=' + encodeURIComponent(bookingId)
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            body.innerHTML = '<p style="color:var(--danger); text-align:center; padding:30px 0;">Booking not found.</p>';
            return;
        }
        currentBooking = data;
        document.getElementById('viewModalTitle').textContent = 'Booking ' + (data.booking_ref || 'Details');
        document.getElementById('bookingSoaBtn').href = 'event_bookings.php?soa=' + Number(data.booking_id);
        renderBookingModal();
        footer.style.display = 'flex';
    })
    .catch(() => {
        body.innerHTML = '<p style="color:var(--danger); text-align:center; padding:30px 0;">Error loading booking details.</p>';
    });
}
document.addEventListener('DOMContentLoaded', () => {
    const q = new URLSearchParams(location.search);
    if (/^\d+$/.test(q.get('open') || '')) viewBooking(q.get('open'), q.get('tab') || 'details');
    if (q.get('types')) bootstrap.Modal.getOrCreateInstance(document.getElementById('eventTypesModal')).show();
});
async function printBookingData(d) {
    const f = await bookingFrame(d, false);
    f.contentWindow.focus();
    f.contentWindow.print();
    setTimeout(() => f.remove(), 60000); // browsers keep printing from the frame until the dialog closes
}
async function printBooking() {
    if (currentBooking) await printBookingData(currentBooking);
}
// From a row's Actions menu. The print dialog also offers Save as PDF.
async function printBookingById(bookingId) {
    try {
        const r = await fetch('event_bookings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'ajax_action=get_booking&booking_id=' + encodeURIComponent(bookingId)
        });
        const data = await r.json();
        if (data.error) throw new Error('missing');
        await printBookingData(data);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Could not print', text: 'The booking could not be loaded. Please try again.', confirmButtonColor: '#2D2D4A' });
    }
}
function bkLoadScript(src) {
    return new Promise((resolve, reject) => {
        const el = document.createElement('script');
        el.src = src; el.onload = resolve; el.onerror = () => reject(new Error('load'));
        document.head.appendChild(el);
    });
}
async function saveBookingPdf() {
    if (!currentBooking) return;
    const btn = document.getElementById('bookingPdfBtn'), label = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Preparing…';
    let f = null;
    try {
        if (!window.html2canvas) await bkLoadScript('https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js');
        if (!window.jspdf) await bkLoadScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');
        f = await bookingFrame(currentBooking, true);
        const doc = f.contentDocument;
        const canvas = await html2canvas(doc.body, { scale: 2, backgroundColor: '#ffffff', windowWidth: 794, width: 794, height: doc.body.scrollHeight });
        const pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
        // Pages break between rows, never through a line of text.
        const pageW = 210, pageH = 297, margin = 12, pxPerMm = canvas.width / pageW, scale = canvas.width / 794;
        const breaks = [...doc.querySelectorAll('tbody tr, table.kv tr, p, footer, img.bk-backdrop')].map(el => Math.ceil((el.getBoundingClientRect().bottom + 2) * scale)).sort((a, b) => a - b);
        for (let y = 0, page = 0; y < canvas.height - 1; page++) {
            const top = page ? margin : 0;
            let end = Math.min(canvas.height, y + (pageH - top - margin) * pxPerMm);
            if (end < canvas.height) {
                const fit = breaks.filter(b => b > y && b <= end).pop();
                if (fit) end = fit;
            }
            end = Math.ceil(end);
            const slice = document.createElement('canvas');
            slice.width = canvas.width;
            slice.height = end - y;
            const ctx = slice.getContext('2d');
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, slice.width, slice.height);
            ctx.drawImage(canvas, 0, -y);
            if (page > 0) pdf.addPage();
            pdf.addImage(slice.toDataURL('image/jpeg', 0.92), 'JPEG', 0, top, pageW, slice.height / pxPerMm);
            y = end;
        }
        pdf.save('booking-' + String(currentBooking.booking_ref || currentBooking.booking_id).replace(/[^A-Za-z0-9_-]/g, '') + '.pdf');
    } catch (e) {
        console.error(e);
        Swal.fire({ icon: 'error', title: 'PDF not created', text: 'The PDF could not be created. Check the internet connection, or use Print and choose "Save as PDF".', customClass: { confirmButton: 'jq-swal-primary' } });
    } finally {
        if (f) f.remove();
        btn.disabled = false;
        btn.innerHTML = label;
    }
}

// ─── PERMANENT DELETE ───────────────────────────────────
function deleteBooking(bookingId, bookingRef) {
    Swal.fire({
        title: 'Permanently delete this booking?',
        html: `<p>Booking <strong>${escapeHtml(bookingRef)}</strong> and its selected items will be <strong>permanently deleted and cannot be recovered</strong>.</p><p style="font-size:0.85rem; margin-top:8px;">A client survey for this booking stays on record. To keep the booking, use Restore instead.</p>`,
        icon: 'warning',
        showCancelButton: true,
        reverseButtons: true,
        focusCancel: true,
        confirmButtonText: 'Permanently Delete',
        cancelButtonText: 'Cancel',
        customClass: { confirmButton: 'jq-swal-delete', cancelButton: 'jq-swal-cancel' },
        allowOutsideClick: false
    }).then(result => {
        if (result.isConfirmed) {
            document.getElementById('deleteId').value = bookingId;
            Swal.fire({ title: 'Deleting…', allowOutsideClick: false, allowEscapeKey: false, didOpen: () => { Swal.showLoading(); document.getElementById('deleteForm').submit(); } });
        }
    });
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
}
document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeViewModal();
});

// ─── ARCHIVE ────────────────────────────────────────────
function archiveBooking(bookingId, bookingRef) {
    Swal.fire({
        title: 'Archive Booking?',
        html: `<p>You are about to archive booking <strong>${bookingRef}</strong>.</p><p style="font-size:0.85rem; margin-top:8px;">Its status will change to <strong>Cancelled</strong>. It will be <strong>permanently deleted after 30 days</strong> unless you restore it from the Archived list first.</p>`,
        icon: 'question',
        showCancelButton: true,
        reverseButtons: true,
        confirmButtonText: 'Yes, Archive',
        cancelButtonText: 'Cancel',
        customClass: { confirmButton: 'jq-swal-archive', cancelButton: 'jq-swal-cancel' },
        allowOutsideClick: false
    }).then(result => {
        if (result.isConfirmed) {
            document.getElementById('archiveId').value = bookingId;
            Swal.fire({ title: 'Archiving…', allowOutsideClick: false, allowEscapeKey: false, didOpen: () => { Swal.showLoading(); document.getElementById('archiveForm').submit(); } });
        }
    });
}

// ─── RESTORE ────────────────────────────────────────────
function restoreBooking(bookingId, bookingRef) {
    Swal.fire({
        title: 'Restore Booking?',
        html: `<p>Booking <strong>${bookingRef}</strong> will return to the bookings list with the status it had before it was archived.</p>`,
        icon: 'question',
        showCancelButton: true,
        reverseButtons: true,
        confirmButtonText: 'Yes, Restore',
        cancelButtonText: 'Cancel',
        customClass: { confirmButton: 'jq-swal-restore', cancelButton: 'jq-swal-cancel' },
        allowOutsideClick: false
    }).then(result => {
        if (result.isConfirmed) {
            document.getElementById('restoreId').value = bookingId;
            Swal.fire({ title: 'Restoring…', allowOutsideClick: false, allowEscapeKey: false, didOpen: () => { Swal.showLoading(); document.getElementById('restoreForm').submit(); } });
        }
    });
}
</script>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════
     WIZARD VIEW  (new booking OR edit mode)
═══════════════════════════════════════════════════ -->
<?php
$is_edit   = ($edit_id > 0 && $edit_data !== null);
$form_action = 'event_bookings.php';
$wizard_title = $is_edit ? 'Edit Event Booking' : 'Event Booking Wizard';
$submit_action = $is_edit ? 'update_full_booking' : 'save_booking';

// Pre-fill values from edit_data (or empty strings for new)
$pf = function($key, $default = '') use ($is_edit, $edit_data) {
    if ($is_edit && isset($edit_data[$key])) return htmlspecialchars($edit_data[$key]);
    return $default;
};
?>
<div class="event-bookings-container">
    <div class="wizard-container">
        <div class="wizard-header">
            <h1><?= $wizard_title ?></h1>
            <a href="event_bookings.php" class="back-button">← Back to Dashboard</a>
        </div>

        <?php if ($is_edit): ?>
        <div class="edit-mode-banner">
            Editing Booking: <span><?= htmlspecialchars($edit_data['booking_ref']) ?></span>
            &nbsp;|&nbsp; Client: <span><?= htmlspecialchars($edit_data['fullname']) ?></span>
        </div>
        <?php endif; ?>

        <div class="steps-indicator">
            <div class="step active" id="step-1-indicator"><div class="step-circle">1</div><div class="step-title">Event Details & Location</div></div>
            <div class="step"        id="step-2-indicator"><div class="step-circle">2</div><div class="step-title">Backdrop & Theme</div></div>
            <div class="step"        id="step-3-indicator"><div class="step-circle">3</div><div class="step-title">Packages & Menu</div></div>
            <div class="step"        id="step-4-indicator"><div class="step-circle">4</div><div class="step-title">Review & <?= $is_edit ? 'Update' : 'Confirm' ?></div></div>
        </div>

        <form id="bookingForm" method="POST" action="<?= $form_action ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?= $submit_action ?>">
            <?php if ($is_edit): ?>
                <input type="hidden" name="booking_id" value="<?= $edit_id ?>">
            <?php endif; ?>

            <!-- ── STEP 1 ── -->
            <div class="form-section active" id="step-1">
                <h3 style="color:var(--primary); margin-bottom:20px;">Step 1: Event Details & Venue Location</h3>

                <div class="form-group">
                    <label for="clientSearch">Returning client? Search existing clients</label>
                    <input type="search" id="clientSearch" placeholder="Name, contact number, email or client code" autocomplete="off">
                    <input type="hidden" name="client_id" id="clientId" value="<?= (int)($edit_client['client_id'] ?? 0) ?>">
                    <div id="clientResults" class="eb-client-results" style="display:none;"></div>
                    <div id="clientPicked" class="eb-client-picked" style="<?= $edit_client ? '' : 'display:none;' ?>">
                        <span id="clientPickedName"><?= $edit_client ? htmlspecialchars($edit_client['client_code'] . ' - ' . $edit_client['fullname']) : '' ?></span>
                        <label style="margin:0; font-weight:400; display:flex; gap:6px; align-items:center;"><input type="checkbox" name="update_client" value="1" style="width:auto;"> Also update the client record with these details</label>
                        <button type="button" class="btn-action btn-view" onclick="clearClient()">Remove</button>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name <span class="required">*</span></label>
                        <input type="text" name="fullname" <?= jq_field_attrs('name') ?> maxlength="255" value="<?= $pf('fullname') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number <span class="required">*</span></label>
                        <input type="tel" name="contact" <?= jq_field_attrs('ph-mobile') ?> value="<?= $pf('contact') ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Email Address <span class="required">*</span></label>
                    <input type="email" name="email" value="<?= $pf('email') ?>" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Event Name <span class="required">*</span></label>
                        <input type="text" name="event_name" value="<?= $pf('event_name') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Event Type <span class="required">*</span></label>
                        <select name="event_type" id="eventTypeSelect" required onchange="ebEventTypeChanged();">
                            <option value="">-- Select Event Type --</option>
                            <?php foreach ($wizard_types as $wt):
                                $wt_selected = $is_edit ? ($edit_data['event_type'] ?? '') === $wt['event_type_name'] : $pre_backdrop_type === $wt['event_type_name'];
                                if (!(int)$wt['is_active'] && !$wt_selected) { continue; } ?>
                                <option value="<?= htmlspecialchars($wt['event_type_name']) ?>" <?= $wt_selected ? 'selected' : '' ?>><?= htmlspecialchars($wt['event_type_name']) ?></option>
                            <?php endforeach; ?>
                            <option value="" data-others="1">Others</option>
                        </select>
                        <div id="ebOtherType" style="display:none; margin-top:10px;">
                            <label for="ebOtherName">Specify the event type <span class="required">*</span></label>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <input type="text" id="ebOtherName" maxlength="100" placeholder="e.g. Family Reunion" autocomplete="off" style="flex:1 1 180px; min-width:0;">
                                <button type="button" class="btn-outline-neutral" id="ebOtherSave" onclick="ebSaveOtherType()"><i class="bx bx-plus"></i> Add Event Type</button>
                            </div>
                            <small id="ebOtherMsg" role="status" style="display:block; margin-top:6px; color:var(--secondary);">It will be saved and offered for future bookings too.</small>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Event Date <span class="required">*</span></label>
                        <input type="date" name="event_date" id="eventDateInput" value="<?= $pf('event_date') ?>" min="<?= jq_earliest_event_date((int)$pf('headcount', 0), date('Y-m-d')) ?>" required aria-describedby="eventDateHint">
                        <small id="eventDateHint" role="status" style="display:block; margin-top:6px; color:var(--secondary); font-size:.82rem;"></small>
                    </div>
                    <div class="form-group">
                        <label>Event Time <span class="required">*</span></label>
                        <input type="time" name="event_time" value="<?= $pf('event_time') ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Number of Guests <span class="required">*</span></label>
                    <input type="number" name="headcount" id="headcountInput" min="1" value="<?= $pf('headcount') ?>" required>
                </div>

                <h4 style="color:var(--primary); margin-top:30px; margin-bottom:15px;">Venue Location</h4>
                <?php if ($is_edit && !empty($edit_data['barangay'])): ?>
                <p style="color:var(--secondary); font-size:.88rem; margin-top:-8px;" id="currentLocationNote">Current location: <strong><?= htmlspecialchars(implode(', ', array_filter([$edit_data['barangay'], $edit_data['city'], $edit_data['province'], $edit_data['region']]))) ?></strong>. Choose below only to change it.</p>
                <?php endif; ?>
                <div class="form-row">
                    <div class="form-group">
                        <label>Region <span class="required">*</span></label>
                        <select name="region_code" id="regionSelect" required onchange="loadProvinces()">
                            <option value="">-- Select Region --</option>
                            <?php foreach ($regions as $region): ?>
                                <option value="<?= $region['region_code'] ?>"><?= htmlspecialchars($region['region_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="region_name" id="regionName" value="<?= $pf('region') ?>">
                    </div>
                    <div class="form-group">
                        <label>Province <span class="required">*</span></label>
                        <select name="province_code" id="provinceSelect" required onchange="loadMunicipalities()">
                            <option value="">-- Select Province --</option>
                        </select>
                        <input type="hidden" name="province_name" id="provinceName" value="<?= $pf('province') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>City/Municipality <span class="required">*</span></label>
                        <select name="municipality_code" id="municipalitySelect" required onchange="loadBarangays()">
                            <option value="">-- Select City/Municipality --</option>
                        </select>
                        <input type="hidden" name="municipality_name" id="municipalityName" value="<?= $pf('city') ?>">
                    </div>
                    <div class="form-group">
                        <label>Barangay <span class="required">*</span></label>
                        <select name="barangay_code" id="barangaySelect" required onchange="updateBarangayName()">
                            <option value="">-- Select Barangay --</option>
                        </select>
                        <input type="hidden" name="barangay_name" id="barangayName" value="<?= $pf('barangay') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Venue Address <span class="required">*</span></label>
                    <textarea name="venue_address" rows="3" required placeholder="e.g. 123 Main Street, Beside the Park"><?= $pf('venue_address') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Postal Code <span class="required">*</span></label>
                    <input type="text" name="postal_code" data-rule="postal" placeholder="1100" value="<?= $pf('postal_code') ?>" required>
                </div>
                <div class="form-group">
                    <label for="bookingNotes">Special Requests and Notes</label>
                    <textarea name="notes" id="bookingNotes" rows="3" maxlength="2000" placeholder="Allergies, setup requests, schedule details"><?= $pf('notes') ?></textarea>
                </div>
            </div>

            <!-- ── STEP 2 ── -->
            <div class="form-section" id="step-2">
                <h3 style="color:var(--primary); margin-bottom:5px;">Step 2: Select Backdrop & Theme</h3>
                <p style="color:var(--secondary); margin-bottom:15px; font-size:0.9rem;">Backdrops are loaded based on your selected event type.</p>

                <div id="backdropLoadingMsg" style="text-align:center; padding:30px; color:var(--secondary); display:none;">
                    Loading backdrops…
                </div>

                <div class="backdrop-gallery" id="backdropGallery">
                    <p style="color:var(--secondary); padding:20px; text-align:center; grid-column:1/-1;">← Please select an Event Type in Step 1</p>
                </div>

                <div id="selectedBackdropPreview" style="display:none; margin-top:15px; padding:12px; background:var(--eb-ghost-hover); border-radius:8px; border-left:4px solid var(--primary);">
                    <strong style="color:var(--primary);">✓ Selected Backdrop:</strong>
                    <span id="selectedBackdropName" style="margin-left:8px; font-weight:600;"></span>
                </div>

                <input type="hidden" name="backdrop_id"   id="backdropId"   value="0">
                <input type="hidden" name="backdrop_name" id="backdropNameHidden" value="">
                <input type="hidden" name="backdrop_path" id="backdropPathHidden" value="">

                <div id="backdropError" class="alert alert-danger" style="display:none; margin-top:15px;">Please select a backdrop to continue</div>
            </div>

            <!-- ── STEP 3 ── -->
            <div class="form-section" id="step-3">
                <h3 style="color:var(--primary); margin-bottom:5px;">Step 3: Packages, Bundles & Food Menu</h3>
                <p style="color:var(--secondary); margin-bottom:20px; font-size:0.9rem;">Select a package, then optionally add bundles and customize your food menu.</p>

                <div class="step3-card" style="background:#fff; border:1px solid var(--border); border-radius:8px; padding:20px; margin-bottom:20px;">
                    <h4 style="color:var(--primary); margin:0 0 12px;">① Catering Package <span class="required">*</span></h4>
                    <div class="form-group" style="margin-bottom:0;">
                        <select name="package_id" id="packageSelect" required onchange="onPackageChange()">
                            <option value="">-- Select Package --</option>
                        </select>
                        <input type="hidden" name="package_name"  id="packageName">
                        <input type="hidden" name="package_price" id="packagePrice" value="0">
                    </div>
                    <div id="packageDescription" style="margin-top:10px; font-size:0.88rem; color:var(--secondary); display:none;"></div>
                </div>

                <div id="bundleSection" class="step3-card" style="background:#fff; border:1px solid var(--border); border-radius:8px; padding:20px; margin-bottom:20px; display:none;">
                    <h4 style="color:var(--primary); margin:0 0 4px;">② Bundles &amp; Add-ons <span style="font-size:0.8rem; color:var(--secondary); font-weight:400;">(Optional)</span></h4>
                    <p style="color:var(--secondary); font-size:0.82rem; margin:0 0 14px 0;">Check a bundle to unlock food customization below. Use +/− to set quantity.</p>
                    <div style="position:relative; margin-bottom:14px;">
                        <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--secondary);">🔍</span>
                        <input type="text" id="bundleSearchInput" onkeyup="filterBundles()" placeholder="Search bundles..." autocomplete="off"
                               style="width:100%; padding:10px 12px 10px 36px; border:1px solid var(--border); border-radius:8px; font-size:0.9rem;">
                    </div>
                    <div id="bundleList"></div>
                </div>

                <div id="foodSection" class="step3-card" style="background:#fff; border:1px solid var(--border); border-radius:8px; padding:20px; margin-bottom:20px; display:none;">
                    <h4 style="color:var(--primary); margin:0 0 4px;">③ Customize Food Menu <span style="font-size:0.8rem; color:var(--secondary); font-weight:400;">(Optional)</span></h4>
                    <p style="color:var(--secondary); font-size:0.82rem; margin:0 0 14px 0;">Items with upgrade price can be added as extras. Use +/− to set quantity.</p>
                    <div style="position:relative; margin-bottom:14px;">
                        <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--secondary);">🔍</span>
                        <input type="text" id="foodSearchInput" onkeyup="filterFoodItems()" placeholder="Search food items..." autocomplete="off"
                               style="width:100%; padding:10px 12px 10px 36px; border:1px solid var(--border); border-radius:8px; font-size:0.9rem;">
                    </div>
                    <div id="foodItemList"></div>
                </div>

                <div id="priceDisplay" style="display:none;">
                    <div class="price-summary">
                        <div class="price-row"><span>Package (per guest):</span><span id="packagePriceDisplay">₱0.00</span></div>
                        <div class="price-row"><span>Guests:</span><span id="guestCountValue">0</span></div>
                        <div class="price-row"><span>Package Subtotal:</span><span id="packageSubtotalDisplay">₱0.00</span></div>
                        <div id="bundlesSummaryRow" class="price-row" style="display:none;"><span>Bundles &amp; Add-ons:</span><span id="bundlesTotalDisplay">₱0.00</span></div>
                        <div id="upgradesSummaryRow" class="price-row" style="display:none;"><span>Menu Upgrades:</span><span id="upgradesTotalDisplay">₱0.00</span></div>
                        <div class="price-row" style="font-weight:700; border-top:1px solid rgba(0,0,0,0.1); padding-top:10px;"><span>Service Tax (12%):</span><span id="taxDisplay">₱0.00</span></div>
                        <div class="price-row price-total"><span>Grand Total:</span><span id="totalDisplay">₱0.00</span></div>
                    </div>
                </div>
                <input type="hidden" name="total_price" id="totalPrice" value="0">
            </div>

            <!-- ── STEP 4 ── -->
            <div class="form-section" id="step-4">
                <h3 style="color:var(--primary); margin-bottom:20px;">Step 4: Review & <?= $is_edit ? 'Update' : 'Confirm' ?></h3>
                <div id="reviewSummary" style="background:#f9f9f9; padding:20px; border-radius:8px; border-left:4px solid var(--primary);">
                    <p style="text-align:center; color:var(--secondary);">Please review your booking details below</p>
                </div>
                <div id="dpSection" class="step3-card" style="background:#fff; border:1px solid var(--border); border-radius:8px; padding:20px; margin-top:20px;">
                    <h4 style="color:var(--primary); margin:0 0 4px;">Downpayment <span class="required">*</span></h4>
                    <p style="color:var(--secondary); font-size:0.85rem; margin:0 0 14px;">A downpayment of at least <?= JQ_DOWNPAYMENT_PERCENT ?>% of the grand total is required to book this event.</p>
                    <div class="price-summary" style="margin-bottom:16px;">
                        <div class="price-row"><span>Grand Total:</span><span id="dpTotal">₱0.00</span></div>
                        <div class="price-row"><span>Required Downpayment (<?= JQ_DOWNPAYMENT_PERCENT ?>%):</span><span id="dpRequired">₱0.00</span></div>
                        <div class="price-row" id="dpPaidRow" style="display:none;"><span>Already Paid:</span><span id="dpPaid">₱0.00</span></div>
                        <div class="price-row price-total"><span id="dpStillLabel">Minimum to Pay Now:</span><span id="dpStill">₱0.00</span></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="dpAmount">Downpayment Amount (₱) <span class="required" id="dpAmountReq">*</span></label>
                            <input type="number" name="dp_amount" id="dpAmount" min="0" step="0.01" inputmode="decimal" placeholder="0.00" aria-describedby="dpMessage">
                        </div>
                        <div class="form-group">
                            <label for="dpMethod">Payment Method <span class="required">*</span></label>
                            <select name="dp_method" id="dpMethod">
                                <option value="">Select method</option>
                                <?php foreach (JQ_PAYMENT_METHODS as $m): ?><option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="dpDate">Payment Date <span class="required">*</span></label>
                            <input type="date" name="dp_date" id="dpDate" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label for="dpReference">Reference Number</label>
                            <input type="text" name="dp_reference" id="dpReference" maxlength="100" placeholder="GCash or bank reference (optional)">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="dpReceipt">Proof of Payment / Official Receipt</label>
                        <input type="file" name="dp_receipt" id="dpReceipt" accept=".pdf,.jpg,.jpeg,.png,.webp">
                        <small style="display:block; margin-top:6px; color:var(--secondary); font-size:.82rem;">PDF, JPG, PNG or WebP, up to 10 MB. It can also be uploaded later from Payments; the payment is verified only after its receipt is checked.</small>
                    </div>
                    <div id="dpMessage" role="alert" style="margin-top:6px; padding:12px 14px; border-radius:6px; font-size:.9rem; font-weight:600;"></div>
                </div>
                <div id="readyBox" style="margin-top:20px; padding:15px; background:rgba(46,125,91,0.1); border-left:4px solid var(--success); border-radius:4px;">
                    <p style="margin:0; color:var(--success); font-weight:600;">✓ All required fields are complete — ready to <?= $is_edit ? 'update' : 'confirm' ?></p>
                </div>
            </div>

            <!-- Wizard Buttons -->
            <div class="button-group">
                <button type="button" class="btn-prev" id="prevBtn" onclick="previousStep()" style="display:none;">← Previous</button>
                <button type="button" class="btn-next" id="nextBtn" onclick="nextStep()">Next →</button>
                <?php if ($is_edit): ?>
                    <button type="submit" class="btn-update" id="submitBtn" style="display:none;">Update Booking</button>
                <?php else: ?>
                    <button type="submit" class="btn-submit" id="submitBtn" style="display:none;">Confirm Booking</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
let currentStep = 1;
const IS_EDIT   = <?= $is_edit ? 'true' : 'false' ?>;
const ORIGINAL_EVENT_DATE = <?= json_encode($is_edit ? ($edit_data['event_date'] ?? '') : '') ?>;
let EDIT_PRESET = <?= json_encode($edit_preset, JSON_HEX_TAG) ?>;

// Existing clients
(function () {
    const box = document.getElementById('clientSearch'), results = document.getElementById('clientResults');
    let timer = null;
    box.addEventListener('input', () => {
        clearTimeout(timer);
        const term = box.value.trim();
        if (term.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(() => {
            fetch('event_bookings.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'ajax_action=search_clients&term=' + encodeURIComponent(term) })
                .then(r => r.json())
                .then(list => {
                    results.innerHTML = list.length ? '' : '<div style="padding:8px 12px; font-size:.88rem;">No matching client. Fill in the details below for a new one.</div>';
                    list.forEach(c => {
                        const b = document.createElement('button');
                        b.type = 'button';
                        b.textContent = c.client_code + ' - ' + c.fullname + (c.contact ? ', ' + c.contact : '') + ' (' + c.booking_count + ' booking' + (Number(c.booking_count) === 1 ? '' : 's') + ')';
                        b.addEventListener('click', () => pickClient(c));
                        results.appendChild(b);
                    });
                    results.style.display = 'block';
                });
        }, 300);
    });
})();
function pickClient(c) {
    const f = document.getElementById('bookingForm');
    document.getElementById('clientId').value = c.client_id;
    document.getElementById('clientPickedName').textContent = c.client_code + ' - ' + c.fullname;
    document.getElementById('clientPicked').style.display = 'flex';
    document.getElementById('clientResults').style.display = 'none';
    document.getElementById('clientSearch').value = '';
    f.querySelector('[name="fullname"]').value = c.fullname || '';
    f.querySelector('[name="email"]').value = c.email || '';
    f.querySelector('[name="contact"]').value = JQRules.phoneDigits(c.contact || '');
    f.querySelector('[name="postal_code"]').value = c.postal_code || '';
    JQRules.validateIn(document.getElementById('step-1'));
}
function clearClient() {
    document.getElementById('clientId').value = '0';
    document.getElementById('clientPicked').style.display = 'none';
}

function getTodayStr() {
    const n = new Date();
    return n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0') + '-' + String(n.getDate()).padStart(2, '0');
}
// ─── BOOKING RULES (same values as includes/booking_manager.php) ───
const SERVER_TODAY = <?= json_encode(date('Y-m-d')) ?>;           // the business's date (Asia/Manila), not the computer's
const ORIGINAL_HEADCOUNT = <?= (int)($is_edit ? ($edit_data['headcount'] ?? 0) : 0) ?>;
const EXISTING_PAID = <?= json_encode(round($edit_paid, 2)) ?>;    // payments already on record (rejected receipts excluded)
const LARGE_EVENT_GUESTS = <?= JQ_LARGE_EVENT_GUESTS ?>, PREP_SMALL = <?= JQ_PREP_DAYS_SMALL ?>, PREP_LARGE = <?= JQ_PREP_DAYS_LARGE ?>, DP_PERCENT = <?= JQ_DOWNPAYMENT_PERCENT ?>;

function prepDays(h) { return h >= LARGE_EVENT_GUESTS ? PREP_LARGE : PREP_SMALL; }
function addDays(ymd, n) {
    const d = new Date(ymd + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
}
function earliestEventDate(h) { return addDays(SERVER_TODAY, prepDays(h)); }
function longDate(ymd) { return new Date(ymd + 'T00:00:00').toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' }); }
function currentHeadcount() { return parseInt(document.getElementById('headcountInput').value, 10) || 0; }
// An edited booking that keeps its date and guest count is not re-checked (the server does the same).
function dateUnchanged(dateStr, h) { return IS_EDIT && dateStr === ORIGINAL_EVENT_DATE && h === ORIGINAL_HEADCOUNT; }
function eventDateError(dateStr, h) {
    if (!dateStr || h <= 0 || dateUnchanged(dateStr, h)) return '';
    const earliest = earliestEventDate(h);
    if (dateStr >= earliest) return '';
    return 'An event with ' + (h >= LARGE_EVENT_GUESTS ? LARGE_EVENT_GUESTS + ' or more guests' : 'fewer than ' + LARGE_EVENT_GUESTS + ' guests')
        + ' needs at least ' + prepDays(h) + ' days of preparation. The earliest date you can book is ' + longDate(earliest) + '. Same-day events are not allowed.';
}
// Keeps the date picker's earliest selectable day in step with the number of guests.
function updateDateRules() {
    const input = document.getElementById('eventDateInput'), hint = document.getElementById('eventDateHint');
    const h = currentHeadcount(), earliest = earliestEventDate(Math.max(h, 1));
    input.min = (IS_EDIT && ORIGINAL_EVENT_DATE && ORIGINAL_EVENT_DATE < earliest && h === ORIGINAL_HEADCOUNT) ? ORIGINAL_EVENT_DATE : earliest;
    const err = eventDateError(input.value, h);
    input.setCustomValidity(err);
    hint.style.color = err ? 'var(--danger, #c0392b)' : 'var(--secondary)';
    hint.textContent = err || (h > 0
        ? 'Earliest available date: ' + longDate(earliest) + ' (' + prepDays(h) + ' days of preparation for ' + (h >= LARGE_EVENT_GUESTS ? LARGE_EVENT_GUESTS + ' or more guests' : 'fewer than ' + LARGE_EVENT_GUESTS + ' guests') + ').'
        : 'Enter the number of guests to see the earliest available date.');
}
function isPastDateNotAllowed(dateStr) {
    return eventDateError(dateStr, currentHeadcount()) !== '';
}

// Required downpayment = grand total x 50%, rounded up to the centavo.
function requiredDownpayment(total) { return Math.ceil(Math.round(total * DP_PERCENT * 10000) / 10000) / 100; }
function downpaymentState() {
    const total = parseFloat(document.getElementById('totalPrice').value) || 0;
    const required = requiredDownpayment(total);
    const still = Math.max(0, Math.round((required - EXISTING_PAID) * 100) / 100);
    const balance = Math.max(0, Math.round((total - EXISTING_PAID) * 100) / 100);
    const raw = document.getElementById('dpAmount').value.trim();
    const amount = raw === '' ? 0 : Number(raw);
    let error = '';
    if (total <= 0) error = 'Choose a package and the number of guests first.';
    else if (raw !== '' && (!isFinite(amount) || amount < 0 || !/^\d+(\.\d{1,2})?$/.test(raw))) error = 'Enter the downpayment as an amount with at most two decimal places.';
    else if (amount < still) error = 'A downpayment of at least ' + fmtPeso(still) + ' is required (' + DP_PERCENT + '% of ' + fmtPeso(total) + (EXISTING_PAID > 0 ? ', less ' + fmtPeso(EXISTING_PAID) + ' already paid' : '') + '). The booking cannot be ' + (IS_EDIT ? 'updated' : 'confirmed') + ' until it is paid.';
    else if (amount > balance) error = 'The downpayment cannot be more than the balance of ' + fmtPeso(balance) + '.';
    else if (amount > 0 && !document.getElementById('dpMethod').value) error = 'Choose how the downpayment was paid.';
    else if (amount > 0 && (!document.getElementById('dpDate').value || document.getElementById('dpDate').value > SERVER_TODAY)) error = 'Enter a payment date that is today or earlier.';
    return { total, required, still, balance, amount, error };
}
function updateDownpayment() {
    const s = downpaymentState();
    document.getElementById('dpTotal').textContent = fmtPeso(s.total);
    document.getElementById('dpRequired').textContent = fmtPeso(s.required);
    document.getElementById('dpPaidRow').style.display = EXISTING_PAID > 0 ? 'flex' : 'none';
    document.getElementById('dpPaid').textContent = fmtPeso(EXISTING_PAID);
    document.getElementById('dpStill').textContent = fmtPeso(s.still);
    document.getElementById('dpAmountReq').style.display = s.still > 0 ? 'inline' : 'none';
    const amt = document.getElementById('dpAmount');
    amt.min = s.still.toFixed(2); amt.max = s.balance.toFixed(2);
    amt.placeholder = s.still > 0 ? 'At least ' + s.still.toFixed(2) : 'Optional';
    const msg = document.getElementById('dpMessage');
    if (s.error) {
        msg.textContent = '✗ ' + s.error;
        Object.assign(msg.style, { background:'rgba(192,57,43,.1)', color:'#c0392b', borderLeft:'4px solid #c0392b' });
    } else {
        msg.textContent = s.still > 0 || s.amount > 0
            ? '✓ The downpayment of ' + fmtPeso(s.amount) + ' meets the ' + DP_PERCENT + '% requirement (' + fmtPeso(s.required) + ').'
            : '✓ Payments already on record cover the ' + DP_PERCENT + '% requirement. No further downpayment is needed.';
        Object.assign(msg.style, { background:'rgba(46,125,91,.1)', color:'var(--success)', borderLeft:'4px solid var(--success)' });
    }
    const btn = document.getElementById('submitBtn');
    btn.disabled = !!s.error;
    btn.style.opacity = s.error ? '.5' : '';
    btn.style.cursor = s.error ? 'not-allowed' : '';
    btn.title = s.error ? 'A ' + DP_PERCENT + '% downpayment is required before booking.' : '';
    document.getElementById('readyBox').style.display = s.error ? 'none' : 'block';
    return !s.error;
}

function showStep(step) {
    document.querySelectorAll('.form-section').forEach(el => el.classList.remove('active'));
    document.getElementById('step-' + step).classList.add('active');
    document.querySelectorAll('.step').forEach(el => el.classList.remove('active','completed'));
    for (let i = 1; i < step; i++) document.getElementById('step-' + i + '-indicator').classList.add('completed');
    document.getElementById('step-' + step + '-indicator').classList.add('active');
    document.getElementById('prevBtn').style.display   = step > 1 ? 'block' : 'none';
    document.getElementById('nextBtn').style.display   = step < 4 ? 'block' : 'none';
    document.getElementById('submitBtn').style.display = step === 4 ? 'block' : 'none';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function nextStep() { if (validateStep(currentStep)) { if (currentStep < 4) { currentStep++; showStep(currentStep); } } }
function previousStep() { if (currentStep > 1) { currentStep--; showStep(currentStep); } }

function validateStep(step) {
    const form = document.getElementById('bookingForm');
    if (step === 1) {
        if (ebOthersSelected()) {
            Swal.fire({ icon:'warning', title:'Add the event type', text:'Type the event type and press Add Event Type, or choose one from the list.', confirmButtonColor:'#2D2D4A' });
            document.getElementById('ebOtherName').focus();
            return false;
        }
        const required = ['fullname','email','contact','event_name','event_type','event_date','event_time','headcount','venue_address','postal_code'];
        // Location: region_name, province_name, municipality_name, barangay_name must be filled
        const locationHiddens = ['regionName','provinceName','municipalityName','barangayName'];
        for (let f of required) {
            const el = form.querySelector('[name="' + f + '"]');
            if (!el || !el.value.trim()) { Swal.fire({ icon:'warning', title:'Missing Fields', text:'Please fill in all required fields.', confirmButtonColor:'#2D2D4A' }); return false; }
        }
        if (!JQRules.validateIn(document.getElementById('step-1'))) {
            Swal.fire({ icon:'warning', title:'Please check the form', text:'Correct the highlighted fields before continuing.', confirmButtonColor:'#2D2D4A' });
            return false;
        }
        const dateVal = form.querySelector('[name="event_date"]').value;
        const dateErr = eventDateError(dateVal, currentHeadcount());
        if (dateErr) {
            Swal.fire({ icon:'warning', title:'Event date too soon', text:dateErr, confirmButtonColor:'#2D2D4A' });
            return false;
        }
        for (let id of locationHiddens) {
            const el = document.getElementById(id);
            if (!el || !el.value.trim()) { Swal.fire({ icon:'warning', title:'Location Required', text:'Please select a complete venue location (Region → Province → City → Barangay).', confirmButtonColor:'#2D2D4A' }); return false; }
        }
        return true;
    }
    if (step === 2) {
        if (!document.getElementById('backdropId').value || document.getElementById('backdropId').value === '0') {
            document.getElementById('backdropError').style.display = 'block';
            Swal.fire({ icon:'warning', title:'Select a Backdrop', text:'Please choose a backdrop theme before continuing.', confirmButtonColor:'#2D2D4A' });
            return false;
        }
        return true;
    }
    if (step === 3) {
        if (!form.querySelector('[name="package_id"]').value) {
            Swal.fire({ icon:'warning', title:'Select a Package', text:'Please choose a catering package before continuing.', confirmButtonColor:'#2D2D4A' });
            return false;
        }
        updateReviewSummary();
        updateDownpayment();
        return true;
    }
    return true;
}

// ─── BACKDROPS ────────────────────────────────────────────
const PRE_BACKDROP_PATH = <?= json_encode($edit_preset ? $edit_preset['backdrop_path'] : htmlspecialchars_decode($pre_backdrop_path)) ?>;
function selectBackdrop(element, id, name, imagePath) {
    document.querySelectorAll('.backdrop-item').forEach(el => el.classList.remove('selected'));
    element.classList.add('selected');
    document.getElementById('backdropId').value = id;
    document.getElementById('backdropNameHidden').value = name;
    document.getElementById('backdropPathHidden').value = imagePath || '';
    document.getElementById('backdropError').style.display = 'none';
    document.getElementById('selectedBackdropName').textContent = name;
    document.getElementById('selectedBackdropPreview').style.display = 'block';
}

// An edited booking keeps its backdrop even if that picture is no longer in the gallery.
function restoreSavedBackdrop() {
    if (!EDIT_PRESET || !EDIT_PRESET.backdrop_name || document.getElementById('backdropId').value !== '0') return;
    if (document.getElementById('eventTypeSelect').value !== <?= json_encode($is_edit ? (string)($edit_data['event_type'] ?? '') : '') ?>) return;
    document.getElementById('backdropId').value = EDIT_PRESET.backdrop_id || 1;
    document.getElementById('backdropNameHidden').value = EDIT_PRESET.backdrop_name;
    document.getElementById('backdropPathHidden').value = EDIT_PRESET.backdrop_path || '';
    document.getElementById('selectedBackdropName').textContent = EDIT_PRESET.backdrop_name;
    document.getElementById('selectedBackdropPreview').style.display = 'block';
}

function ebOthersSelected() {
    const sel = document.getElementById('eventTypeSelect');
    const opt = sel.options[sel.selectedIndex];
    return !!(opt && opt.dataset.others);
}

function ebEventTypeChanged() {
    const others = ebOthersSelected();
    document.getElementById('ebOtherType').style.display = others ? 'block' : 'none';
    if (others) document.getElementById('ebOtherName').focus();
    loadBackdropsByType(); loadPackagesByType(); loadMenuData();
}

function ebSaveOtherType() {
    const input = document.getElementById('ebOtherName');
    const msg = document.getElementById('ebOtherMsg');
    const btn = document.getElementById('ebOtherSave');
    const name = input.value.trim();
    if (!name) {
        msg.textContent = 'Please type the event type first.';
        msg.style.color = 'var(--danger, #A03A3A)';
        input.focus();
        return;
    }
    btn.disabled = true;
    fetch('event_bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=add_event_type&event_type_name=' + encodeURIComponent(name)
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        if (!res.ok) {
            msg.textContent = res.message;
            msg.style.color = 'var(--danger, #A03A3A)';
            return;
        }
        const sel = document.getElementById('eventTypeSelect');
        let opt = Array.from(sel.options).find(o => o.value === res.name);
        if (!opt) {
            opt = new Option(res.name, res.name);
            sel.insertBefore(opt, sel.querySelector('option[data-others]'));
        }
        sel.value = res.name;
        input.value = '';
        msg.textContent = 'It will be saved and offered for future bookings too.';
        msg.style.color = 'var(--secondary)';
        ebEventTypeChanged();
        Swal.fire({ icon:'success', title:'Event type saved', text:res.message, timer:1800, showConfirmButton:false });
    })
    .catch(() => {
        btn.disabled = false;
        msg.textContent = 'The event type could not be saved. Please try again.';
        msg.style.color = 'var(--danger, #A03A3A)';
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('ebOtherName');
    if (input) input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); ebSaveOtherType(); } });
});

function loadBackdropsByType() {
    const eventType = document.getElementById('eventTypeSelect').value;
    const gallery = document.getElementById('backdropGallery');
    document.getElementById('backdropId').value = '0';
    document.getElementById('backdropNameHidden').value = '';
    document.getElementById('backdropPathHidden').value = '';
    document.getElementById('selectedBackdropPreview').style.display = 'none';

    if (!eventType) {
        gallery.innerHTML = '<p style="color:var(--secondary); padding:20px; text-align:center; grid-column:1/-1;">← Please select an Event Type in Step 1</p>';
        return;
    }
    document.getElementById('backdropLoadingMsg').style.display = 'block';
    gallery.innerHTML = '';

    fetch('event_bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=get_backdrops&event_type=' + encodeURIComponent(eventType)
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('backdropLoadingMsg').style.display = 'none';
        if (data.length === 0) {
            gallery.innerHTML = '<p style="color:var(--secondary); padding:20px; text-align:center; grid-column:1/-1;">No backdrops available for this event type. Add some in the Backdrops module.</p>';
            return;
        }
        let html = '';
        data.forEach((b, idx) => {
            const safeName = escapeHtml(b.backdrop_name.replace(/'/g, "\\'"));
            const imgPath  = b.image_path || '';
            const safeImg  = imgPath.replace(/'/g, "\\'");
            const imgHtml  = imgPath
                ? `<img class="backdrop-img" src="${imgPath}" alt="${escapeHtml(b.backdrop_name)}" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"><div class="backdrop-img-placeholder" style="display:none;">🎨</div>`
                : `<div class="backdrop-img-placeholder">🎨</div>`;
            html += `<div class="backdrop-item" data-path="${imgPath}" onclick="selectBackdrop(this, ${idx+1}, '${safeName}', '${safeImg}')">${imgHtml}<div class="backdrop-name">${escapeHtml(b.backdrop_name)}</div></div>`;
        });
        gallery.innerHTML = html;
        // Auto-select the backdrop saved from the Backdrops module
        if (PRE_BACKDROP_PATH) {
            const match = Array.from(gallery.querySelectorAll('.backdrop-item'))
                .find(el => el.getAttribute('data-path') === PRE_BACKDROP_PATH);
            if (match) { match.click(); match.scrollIntoView({ block: 'nearest' }); }
        }
        restoreSavedBackdrop();
    })
    .catch(() => {
        document.getElementById('backdropLoadingMsg').style.display = 'none';
        gallery.innerHTML = '<p style="color:var(--secondary); padding:20px; text-align:center; grid-column:1/-1;">Error loading backdrops. Please try again.</p>';
    });
}

// ─── PACKAGES ─────────────────────────────────────────────
function loadPackagesByType() {
    const eventType = document.getElementById('eventTypeSelect').value;
    document.getElementById('bundleSection').style.display = 'none';
    document.getElementById('foodSection').style.display = 'none';
    document.getElementById('bundleList').innerHTML = '';
    document.getElementById('foodItemList').innerHTML = '';
    document.getElementById('priceDisplay').style.display = 'none';
    document.getElementById('packageDescription').style.display = 'none';
    _menuDataCache = null;

    if (!eventType) {
        document.getElementById('packageSelect').innerHTML = '<option value="">-- Select Package --</option>';
        return;
    }
    fetch('event_bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=get_packages&event_type=' + encodeURIComponent(eventType)
    })
    .then(r => r.json())
    .then(data => {
        let html = '<option value="">-- Select Package --</option>';
        if (!data.length) html += '<option disabled>No packages available — add in Food Menus module</option>';
        else data.forEach(pkg => {
            const pkgName = escapeHtml(pkg.package_name), pkgTier = escapeHtml(pkg.tier);
            _pkgInclusions[pkg.package_id] = { list: pkg.inclusions || [], note: pkg.pricing_note || '' };
            html += `<option value="${pkg.package_id}" data-price="${pkg.price_per_guest}" data-name="${pkgName}" data-tier="${pkgTier}">${pkgName} (${pkgTier}) — ${fmtPeso(pkg.price_per_guest)}/guest</option>`;
        });
        document.getElementById('packageSelect').innerHTML = html;
        if (EDIT_PRESET && EDIT_PRESET.package_id && !EDIT_PRESET.packageApplied) {
            EDIT_PRESET.packageApplied = true;
            const sel = document.getElementById('packageSelect');
            sel.value = String(EDIT_PRESET.package_id);
            if (sel.selectedIndex > 0) onPackageChange();
        }
    })
    .catch(() => { document.getElementById('packageSelect').innerHTML = '<option value="">Error loading packages</option>'; });
}

let _menuDataCache = null;
const _pkgInclusions = {};
function loadMenuData() {}

// Ticks what an edited booking already has, once its lists have loaded.
function applyEditPreset() {
    if (!EDIT_PRESET || EDIT_PRESET.linesApplied) return;
    EDIT_PRESET.linesApplied = true;
    EDIT_PRESET.bundles.forEach(b => {
        const cb = document.querySelector('input[name="selected_bundles[]"][value="' + b.id + '"]');
        if (cb) { cb.checked = true; const q = document.getElementById('bqty_' + b.id); if (q) q.value = b.qty; }
    });
    onBundleChange();
    EDIT_PRESET.items.forEach(i => {
        const cb = document.querySelector('input[name="selected_items[]"][value="' + i.id + '"]');
        if (cb && !cb.disabled) { cb.checked = true; const q = document.getElementById('qty_' + i.id); if (q) q.value = i.qty; }
    });
    recalcTotal();
}

function onPackageChange() {
    const sel    = document.getElementById('packageSelect');
    const option = sel.options[sel.selectedIndex];
    const price  = parseFloat(option.dataset.price) || 0;
    const name   = option.dataset.name || '';

    document.getElementById('packageName').value  = name;
    document.getElementById('packagePrice').value = price;

    const descEl = document.getElementById('packageDescription');
    const pkgInfo = _pkgInclusions[sel.value];
    if (pkgInfo && (pkgInfo.list.length || pkgInfo.note)) {
        descEl.innerHTML = (pkgInfo.note ? '<div style="font-weight:600;">' + escapeHtml(pkgInfo.note) + '</div>' : '') + '<ul class="pkg-inc">' + pkgInfo.list.map(t => '<li>' + escapeHtml(t) + '</li>').join('') + '</ul>';
        descEl.style.display = 'block';
    } else { descEl.style.display = 'none'; }

    document.getElementById('foodSection').style.display = 'none';
    const foodList = document.getElementById('foodItemList');
    foodList.innerHTML = '';
    delete foodList.dataset.loaded;

    if (!sel.value) {
        document.getElementById('bundleSection').style.display = 'none';
        document.getElementById('bundleList').innerHTML = '';
        document.getElementById('priceDisplay').style.display = 'none';
        return;
    }
    document.getElementById('bundleSection').style.display = 'block';

    if (_menuDataCache) { renderBundles(_menuDataCache.bundles || []); recalcTotal(); applyEditPreset(); return; }

    document.getElementById('bundleList').innerHTML = '<p style="color:var(--secondary); font-size:0.9rem; text-align:center; padding:15px 0;">Loading bundles…</p>';

    fetch('event_bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=get_menu_data'
    })
    .then(r => r.json())
    .then(data => { _menuDataCache = data; renderBundles(data.bundles || []); recalcTotal(); applyEditPreset(); })
    .catch(() => { document.getElementById('bundleList').innerHTML = '<p style="color:var(--secondary); font-size:0.9rem;">Error loading bundles. Please try again.</p>'; });
}

function renderBundles(bundles) {
    const container = document.getElementById('bundleList');
    if (!bundles.length) { container.innerHTML = '<p style="color:var(--secondary); font-size:0.9rem; padding:10px 0;">No bundles available.</p>'; return; }
    let groups = {};
    bundles.forEach(b => { if (!groups[b.bundle_type]) groups[b.bundle_type] = []; groups[b.bundle_type].push(b); });
    let html = '';
    for (const [type, items] of Object.entries(groups)) {
        html += `<div class="food-course-group"><div class="food-course-title">${escapeHtml(type)}</div>`
             + `<div class="bundle-head" aria-hidden="true"><span>Catering Package</span><span>Price</span><span>Guest / Table</span><span>Qty</span></div>`;
        items.forEach(b => {
            const perGuest = b.price_mode === 'per_guest';
            const priceLabel = perGuest ? `${fmtPeso(b.price)} / guest` : `${fmtPeso(b.price)} / order`;
            const guestLabel = bundleGuestLabel(b);
            const qtyControl = `
                <div class="qty-control" style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                    <button type="button" class="qty-btn" onclick="changeBundleQty(${b.bundle_id}, -1)" aria-label="Decrease quantity" style="width:30px; height:30px; border:1px solid var(--border); background:#fff; border-radius:6px; font-size:1.1rem; line-height:1; cursor:pointer; color:var(--primary);">−</button>
                    <input type="number" min="0" step="1" value="0" inputmode="numeric" onkeydown="blockQtyKeys(event)" oninput="onBundleQtyInput(${b.bundle_id})" onchange="onBundleQtyCommit(${b.bundle_id})" onfocus="this.select()" id="bqty_${b.bundle_id}" name="bundle_qty[${b.bundle_id}]" class="qty-input" style="width:44px; text-align:center; border:1px solid var(--border); border-radius:6px; padding:6px 0; font-size:0.9rem;">
                    <button type="button" class="qty-btn" onclick="changeBundleQty(${b.bundle_id}, 1)" aria-label="Increase quantity" style="width:30px; height:30px; border:1px solid var(--border); background:#fff; border-radius:6px; font-size:1.1rem; line-height:1; cursor:pointer; color:var(--primary);">+</button>
                </div>`;
            html += `<div class="food-item-row bundle-row" style="display:flex; align-items:center; gap:12px; margin-bottom:8px;"><label class="bundle-checkbox" style="flex:1; margin:0;"><input type="checkbox" name="selected_bundles[]" value="${b.bundle_id}" data-price="${Number(b.price)}" data-mode="${perGuest ? 'per_guest' : 'flat'}" data-guests="${b.guests_covered ? Number(b.guests_covered) : ''}" onchange="onBundleCheckboxToggle(this)"><div class="bundle-info"><div class="bundle-name">${escapeHtml(b.bundle_name)}</div>${b.order_note?`<div class="bundle-desc">${escapeHtml(b.order_note)}</div>`:''}${(b.inclusions||[]).length?`<ul class="bundle-inc">${b.inclusions.map(t=>`<li>${escapeHtml(t)}</li>`).join('')}</ul>`:''}</div><div class="food-item-price bundle-col-price">${priceLabel}</div><div class="bundle-guests${b.guests_covered || perGuest ? '' : ' bundle-guests-unset'}">${escapeHtml(guestLabel)}</div></label>${qtyControl}</div>`;
        });
        html += '</div>';
    }
    container.innerHTML = html;
}

// "10 guests per table", "Every guest" (per-guest price), or a note that the number has not been set in Food Menus.
function bundleGuestLabel(b) {
    if (b.price_mode === 'per_guest') return 'Every guest';
    const n = Number(b.guests_covered) || 0;
    return n > 0 ? n + (n === 1 ? ' guest' : ' guests') + ' per table' : 'Not set';
}
// Selected bundles and add-ons with quantity, line total and guests covered. Prices come from the data, not the text.
function selectedBundles() {
    const headcount = currentHeadcount();
    const out = [];
    document.querySelectorAll('input[name="selected_bundles[]"]:checked').forEach(cb => {
        const row = cb.closest('.food-item-row');
        const qtyInput = row ? row.querySelector('.qty-input') : null;
        const qty = qtyInput ? (parseInt(qtyInput.value, 10) || 1) : 1;
        const price = parseFloat(cb.dataset.price) || 0;
        const perGuest = cb.dataset.mode === 'per_guest';
        const guests = parseInt(cb.dataset.guests, 10) || 0;
        out.push({ name: row.querySelector('.bundle-name').textContent.trim(), qty, price, perGuest,
                   line: (perGuest ? price * headcount : price) * qty,
                   covers: perGuest ? headcount : guests * qty, guestsSet: perGuest || guests > 0 });
    });
    return out;
}

function onBundleChange() {
    const anyChecked = document.querySelectorAll('input[name="selected_bundles[]"]:checked').length > 0;
    const foodSection = document.getElementById('foodSection');
    const foodList    = document.getElementById('foodItemList');
    if (anyChecked) {
        foodSection.style.display = 'block';
        if (!foodList.dataset.loaded) { renderFoodItems(_menuDataCache ? (_menuDataCache.items || []) : []); foodList.dataset.loaded = '1'; }
    } else { foodSection.style.display = 'none'; }
    recalcTotal();
}

function renderFoodItems(items) {
    const container = document.getElementById('foodItemList');
    if (!items.length) { container.innerHTML = '<p style="color:var(--secondary); font-size:0.9rem; padding:10px 0;">No food items available.</p>'; return; }
    let groups = {};
    items.forEach(it => { if (!groups[it.course]) groups[it.course] = []; groups[it.course].push(it); });
    let html = '';
    for (const [course, courseItems] of Object.entries(groups)) {
        html += `<div class="food-course-group"><div class="food-course-title">${escapeHtml(course)}</div>`;
        courseItems.forEach(it => {
            const isIncluded = parseFloat(it.upgrade_price) === 0;
            const badge = isIncluded
                ? `<span style="font-size:0.78rem; color:#2E7D5B; font-weight:700; background:rgba(46,125,91,0.1); padding:2px 7px; border-radius:10px;">✓ Included</span>`
                : `<span class="food-item-price" style="font-size:0.82rem;">+${fmtPeso(it.upgrade_price)}/guest</span>`;
            const disStyle = isIncluded ? 'opacity:0.65; cursor:not-allowed;' : '';
            const qtyControl = isIncluded ? '' : `
                <div class="qty-control" style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                    <button type="button" class="qty-btn" onclick="changeQty(${it.item_id}, -1)" aria-label="Decrease quantity" style="width:30px; height:30px; border:1px solid var(--border); background:#fff; border-radius:6px; font-size:1.1rem; line-height:1; cursor:pointer; color:var(--primary);">−</button>
                    <input type="number" min="1" step="1" value="1" inputmode="numeric" onkeydown="blockQtyKeys(event)" oninput="onItemQtyInput(${it.item_id})" onchange="onItemQtyCommit(${it.item_id})" onfocus="this.select()" id="qty_${it.item_id}" name="item_qty[${it.item_id}]" class="qty-input" style="width:44px; text-align:center; border:1px solid var(--border); border-radius:6px; padding:6px 0; font-size:0.9rem;">
                    <button type="button" class="qty-btn" onclick="changeQty(${it.item_id}, 1)" aria-label="Increase quantity" style="width:30px; height:30px; border:1px solid var(--border); background:#fff; border-radius:6px; font-size:1.1rem; line-height:1; cursor:pointer; color:var(--primary);">+</button>
                </div>`;
            html += `<div class="food-item-row" style="display:flex; align-items:center; gap:12px; margin-bottom:8px;"><label class="food-item-checkbox" style="flex:1; margin:0; ${disStyle}"><input type="checkbox" name="selected_items[]" value="${it.item_id}" ${isIncluded?'checked disabled':''} onchange="recalcTotal()" style="${disStyle}"><div class="food-item-info"><div class="food-item-name">${escapeHtml(it.item_name)}</div>${it.description?`<div class="food-item-note">${escapeHtml(it.description)}</div>`:''}<div style="margin-top:3px;">${badge}</div></div></label>${qtyControl}</div>`;
        });
        html += '</div>';
    }
    container.innerHTML = html;
}

// ─── BUNDLE QUANTITY + SEARCH ──────────────────────────────
function onBundleCheckboxToggle(cb) {
    const id = cb.value;
    const qtyInput = document.getElementById('bqty_' + id);
    if (qtyInput) {
        if (cb.checked) {
            if ((parseInt(qtyInput.value) || 0) < 1) qtyInput.value = 1;
        } else {
            qtyInput.value = 0;
        }
    }
    onBundleChange();
}

function changeBundleQty(id, delta) {
    const input = document.getElementById('bqty_' + id);
    if (!input) return;
    let v = (parseInt(input.value) || 0) + delta;
    if (v < 0) v = 0;
    input.value = v;
    const cb = document.querySelector('input[name="selected_bundles[]"][value="' + id + '"]');
    if (cb) {
        if (v > 0 && !cb.checked) { cb.checked = true; onBundleChange(); return; }
        if (v === 0 && cb.checked) { cb.checked = false; onBundleChange(); return; }
    }
    recalcTotal();
}

// Typing support for quantity boxes
function blockQtyKeys(e) {
    if (['e', 'E', '+', '-', '.', ','].includes(e.key)) e.preventDefault();
}

function syncBundleCheckbox(id, v) {
    const cb = document.querySelector('input[name="selected_bundles[]"][value="' + id + '"]');
    if (cb) {
        if (v > 0 && !cb.checked) { cb.checked = true; onBundleChange(); return; }
        if (v === 0 && cb.checked) { cb.checked = false; onBundleChange(); return; }
    }
    recalcTotal();
}

function onBundleQtyInput(id) {
    const input = document.getElementById('bqty_' + id);
    if (!input) return;
    if (input.value === '') { return; } // allow clearing while typing
    let v = parseInt(input.value, 10);
    if (isNaN(v) || v < 0) v = 0;
    if (String(v) !== input.value) input.value = v;
    syncBundleCheckbox(id, v);
}

function onBundleQtyCommit(id) {
    const input = document.getElementById('bqty_' + id);
    if (!input) return;
    let v = parseInt(input.value, 10);
    if (isNaN(v) || v < 0) v = 0;
    input.value = v;
    syncBundleCheckbox(id, v);
}

function filterBundles() {
    const q = (document.getElementById('bundleSearchInput').value || '').toLowerCase().trim();
    document.querySelectorAll('#bundleList .food-course-group').forEach(group => {
        let visible = 0;
        group.querySelectorAll('.food-item-row').forEach(row => {
            const nameEl = row.querySelector('.bundle-name');
            const descEl = row.querySelector('.bundle-desc');
            const name = nameEl ? nameEl.textContent.toLowerCase() : '';
            const desc = descEl ? descEl.textContent.toLowerCase() : '';
            const match = !q || name.includes(q) || desc.includes(q);
            row.style.display = match ? 'flex' : 'none';
            if (match) visible++;
        });
        group.style.display = visible ? 'block' : 'none';
    });
}

// ─── FOOD ITEM QUANTITY + SEARCH ──────────────────────────
function changeQty(id, delta) {
    const input = document.getElementById('qty_' + id);
    if (!input) return;
    let v = (parseInt(input.value) || 1) + delta;
    if (v < 1) v = 1;
    input.value = v;
    // Auto-select the item when the quantity is adjusted
    const cb = document.querySelector('input[name="selected_items[]"][value="' + id + '"]');
    if (cb && !cb.disabled && !cb.checked) cb.checked = true;
    recalcTotal();
}

function onItemQtyInput(id) {
    const input = document.getElementById('qty_' + id);
    if (!input) return;
    if (input.value === '') { return; } // allow clearing while typing
    let v = parseInt(input.value, 10);
    if (isNaN(v) || v < 0) v = 0;
    if (String(v) !== input.value) input.value = v;
    if (v >= 1) {
        const cb = document.querySelector('input[name="selected_items[]"][value="' + id + '"]');
        if (cb && !cb.disabled && !cb.checked) cb.checked = true;
    }
    recalcTotal();
}

function onItemQtyCommit(id) {
    const input = document.getElementById('qty_' + id);
    if (!input) return;
    let v = parseInt(input.value, 10);
    if (isNaN(v) || v < 1) v = 1;
    input.value = v;
    const cb = document.querySelector('input[name="selected_items[]"][value="' + id + '"]');
    if (cb && !cb.disabled && !cb.checked) cb.checked = true;
    recalcTotal();
}

function filterFoodItems() {
    const q = (document.getElementById('foodSearchInput').value || '').toLowerCase().trim();
    document.querySelectorAll('#foodItemList .food-course-group').forEach(group => {
        let visible = 0;
        group.querySelectorAll('.food-item-row').forEach(row => {
            const nameEl = row.querySelector('.food-item-name');
            const noteEl = row.querySelector('.food-item-note');
            const name = nameEl ? nameEl.textContent.toLowerCase() : '';
            const note = noteEl ? noteEl.textContent.toLowerCase() : '';
            const match = !q || name.includes(q) || note.includes(q);
            row.style.display = match ? 'flex' : 'none';
            if (match) visible++;
        });
        // Hide the whole course group (and its title) when nothing matches
        group.style.display = visible ? 'block' : 'none';
    });
}

// ─── LOCATION CASCADES ────────────────────────────────────
function loadProvinces() {
    const regionCode = document.getElementById('regionSelect').value;
    const regionName = document.getElementById('regionSelect').options[document.getElementById('regionSelect').selectedIndex].text;
    document.getElementById('regionName').value = regionCode ? regionName : '';
    // A new region means the rest of the location must be chosen again.
    ['provinceName', 'municipalityName', 'barangayName'].forEach(id => { document.getElementById(id).value = ''; });
    if (!regionCode) { document.getElementById('provinceSelect').innerHTML = '<option value="">-- Select Province --</option>'; return; }
    fetch('event_bookings.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_action=get_provinces&region_code='+encodeURIComponent(regionCode) })
    .then(r=>r.json()).then(data => {
        let html = '<option value="">-- Select Province --</option>';
        data.forEach(p => { html += `<option value="${p.province_code}">${p.province_name}</option>`; });
        document.getElementById('provinceSelect').innerHTML = html;
        document.getElementById('municipalitySelect').innerHTML = '<option value="">-- Select City/Municipality --</option>';
        document.getElementById('barangaySelect').innerHTML = '<option value="">-- Select Barangay --</option>';
    });
}

function loadMunicipalities() {
    const pCode = document.getElementById('provinceSelect').value;
    const pName = document.getElementById('provinceSelect').options[document.getElementById('provinceSelect').selectedIndex].text;
    document.getElementById('provinceName').value = pName;
    if (!pCode) { document.getElementById('municipalitySelect').innerHTML = '<option value="">-- Select City/Municipality --</option>'; return; }
    fetch('event_bookings.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_action=get_municipalities&province_code='+encodeURIComponent(pCode) })
    .then(r=>r.json()).then(data => {
        let html = '<option value="">-- Select City/Municipality --</option>';
        data.forEach(m => { html += `<option value="${m.municipality_code}">${m.municipality_name}</option>`; });
        document.getElementById('municipalitySelect').innerHTML = html;
        document.getElementById('barangaySelect').innerHTML = '<option value="">-- Select Barangay --</option>';
    });
}

function loadBarangays() {
    const mCode = document.getElementById('municipalitySelect').value;
    const mName = document.getElementById('municipalitySelect').options[document.getElementById('municipalitySelect').selectedIndex].text;
    document.getElementById('municipalityName').value = mName;
    if (!mCode) { document.getElementById('barangaySelect').innerHTML = '<option value="">-- Select Barangay --</option>'; return; }
    fetch('event_bookings.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_action=get_barangays&municipality_code='+encodeURIComponent(mCode) })
    .then(r=>r.json()).then(data => {
        let html = '<option value="">-- Select Barangay --</option>';
        data.forEach(b => { html += `<option value="${b.barangay_code}">${b.barangay_name}</option>`; });
        document.getElementById('barangaySelect').innerHTML = html;
    });
}

function updateBarangayName() {
    const sel = document.getElementById('barangaySelect');
    document.getElementById('barangayName').value = sel.options[sel.selectedIndex].text;
}

// ─── PRICE CALC ───────────────────────────────────────────
// Format a number as Philippine Peso with commas on thousands (e.g. ₱1,000.00)
function fmtPeso(n) {
    return '₱' + (parseFloat(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function recalcTotal() {
    const sel = document.getElementById('packageSelect');
    const option = sel.options[sel.selectedIndex];
    const pkgPrice = parseFloat(option ? option.dataset.price : 0) || 0;
    const headcount = parseInt(document.querySelector('[name="headcount"]').value) || 0;
    if (pkgPrice <= 0 || headcount <= 0) { document.getElementById('priceDisplay').style.display = 'none'; return; }

    const pkgSubtotal = pkgPrice * headcount;
    const bundlesTotal = selectedBundles().reduce((sum, b) => sum + b.line, 0);
    let upgradesTotal = 0;
    document.querySelectorAll('input[name="selected_items[]"]:checked').forEach(cb => {
        const priceEl = cb.closest('label').querySelector('.food-item-price');
        if (priceEl) {
            const row = cb.closest('.food-item-row');
            const qtyInput = row ? row.querySelector('.qty-input') : null;
            const qty = qtyInput ? (parseInt(qtyInput.value) || 1) : 1;
            upgradesTotal += (parseFloat(priceEl.textContent.replace(/[^0-9.]/g,'')) || 0) * headcount * qty;
        }
    });

    const beforeTax = pkgSubtotal + bundlesTotal + upgradesTotal;
    const tax   = beforeTax * 0.12;
    const total = beforeTax + tax;

    document.getElementById('packagePriceDisplay').textContent    = fmtPeso(pkgPrice);
    document.getElementById('guestCountValue').textContent         = headcount.toLocaleString('en-PH');
    document.getElementById('packageSubtotalDisplay').textContent  = fmtPeso(pkgSubtotal);

    const bRow = document.getElementById('bundlesSummaryRow');
    const uRow = document.getElementById('upgradesSummaryRow');
    if (bundlesTotal > 0)  { document.getElementById('bundlesTotalDisplay').textContent  = fmtPeso(bundlesTotal);  bRow.style.display = 'flex'; } else { bRow.style.display = 'none'; }
    if (upgradesTotal > 0) { document.getElementById('upgradesTotalDisplay').textContent = fmtPeso(upgradesTotal); uRow.style.display = 'flex'; } else { uRow.style.display = 'none'; }

    document.getElementById('taxDisplay').textContent   = fmtPeso(tax);
    document.getElementById('totalDisplay').textContent = fmtPeso(total);
    document.getElementById('totalPrice').value         = total.toFixed(2);
    // Grand Total breakdown is intentionally not shown here on Step 3 —
    // it's revealed only on Step 4 (Review & Confirm) after the user reviews their booking.
}

// ─── REVIEW SUMMARY ───────────────────────────────────────
function updateReviewSummary() {
    const form = document.getElementById('bookingForm');
    const fullname    = form.querySelector('[name="fullname"]').value;
    const eventName   = form.querySelector('[name="event_name"]').value;
    const eventType   = form.querySelector('[name="event_type"]').value;
    const eventDate   = form.querySelector('[name="event_date"]').value;
    const eventTime   = form.querySelector('[name="event_time"]').value;
    const headcount   = form.querySelector('[name="headcount"]').value;
    const venue       = form.querySelector('[name="venue_address"]').value;
    const backdrop    = document.getElementById('backdropNameHidden').value;
    const packageName = document.getElementById('packageName').value;
    const total       = document.getElementById('totalDisplay').textContent;

    const bundleNames = selectedBundles().map(b => b.name + (b.qty > 1 ? ` (x${b.qty})` : '') + ' — ' + fmtPeso(b.line)
        + (b.perGuest ? ' (' + fmtPeso(b.price) + ' x ' + b.covers.toLocaleString('en-PH') + ' guests)'
                      : (b.guestsSet ? ' (covers ' + b.covers.toLocaleString('en-PH') + ' guests)' : '')));
    let foodNames = [];
    document.querySelectorAll('input[name="selected_items[]"]:checked').forEach(cb => {
        const n = cb.closest('label').querySelector('.food-item-name');
        if (n) {
            const row = cb.closest('.food-item-row');
            const qtyInput = row ? row.querySelector('.qty-input') : null;
            const qty = qtyInput ? (parseInt(qtyInput.value) || 1) : 1;
            foodNames.push(n.textContent.trim() + (qty > 1 ? ` (x${qty})` : ''));
        }
    });

    const bundleRow = bundleNames.length ? `<tr style="border-bottom:1px solid #ddd;"><td style="padding:10px; font-weight:600; vertical-align:top;">Bundles &amp; Add-ons:</td><td style="padding:10px;">${bundleNames.map(escapeHtml).join('<br>')}</td></tr>` : '';
    const foodRow   = foodNames.length   ? `<tr style="border-bottom:1px solid #ddd; background:#f9f9f9;"><td style="padding:10px; font-weight:600; vertical-align:top;">Food Items:</td><td style="padding:10px;">${foodNames.map(escapeHtml).join('<br>')}</td></tr>` : '';
    const mode      = IS_EDIT ? '(Updating existing booking)' : '(New Booking)';

    // Pull the (comma-formatted) figures from the live price calculator for the Step 4 breakdown
    const val = id => { const el = document.getElementById(id); return el ? el.textContent : ''; };
    const bundlesBreakdown  = document.getElementById('bundlesSummaryRow').style.display  !== 'none'
        ? `<div class="price-row"><span>Bundles &amp; Add-ons:</span><span>${val('bundlesTotalDisplay')}</span></div>` : '';
    const upgradesBreakdown = document.getElementById('upgradesSummaryRow').style.display !== 'none'
        ? `<div class="price-row"><span>Menu Upgrades:</span><span>${val('upgradesTotalDisplay')}</span></div>` : '';

    const priceBreakdown = `
        <div class="price-summary" style="margin-top:20px;">
            <div class="price-row"><span>Package (per guest):</span><span>${val('packagePriceDisplay')}</span></div>
            <div class="price-row"><span>Guests:</span><span>${val('guestCountValue')}</span></div>
            <div class="price-row"><span>Package Subtotal:</span><span>${val('packageSubtotalDisplay')}</span></div>
            ${bundlesBreakdown}
            ${upgradesBreakdown}
            <div class="price-row" style="font-weight:700; border-top:1px solid rgba(0,0,0,0.1); padding-top:10px;"><span>Service Tax (12%):</span><span>${val('taxDisplay')}</span></div>
            <div class="price-row price-total"><span>Grand Total:</span><span>${total}</span></div>
        </div>`;

    document.getElementById('reviewSummary').innerHTML = `
        <table style="width:100%; border-collapse:collapse; font-size:0.95rem;">
            <tr style="border-bottom:1px solid #ddd;"><td style="padding:10px; font-weight:600; width:42%;">Mode:</td><td style="padding:10px; color:var(--primary); font-weight:700;">${mode}</td></tr>
            <tr style="border-bottom:1px solid #ddd; background:#f9f9f9;"><td style="padding:10px; font-weight:600;">Client Name:</td><td style="padding:10px;">${escapeHtml(fullname)}</td></tr>
            <tr style="border-bottom:1px solid #ddd;"><td style="padding:10px; font-weight:600;">Event Name:</td><td style="padding:10px;">${escapeHtml(eventName)}</td></tr>
            <tr style="border-bottom:1px solid #ddd; background:#f9f9f9;"><td style="padding:10px; font-weight:600;">Event Type:</td><td style="padding:10px;">${escapeHtml(eventType)}</td></tr>
            <tr style="border-bottom:1px solid #ddd;"><td style="padding:10px; font-weight:600;">Date &amp; Time:</td><td style="padding:10px;">${new Date(eventDate + 'T00:00:00').toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'})} at ${escapeHtml(eventTime)}</td></tr>
            <tr style="border-bottom:1px solid #ddd; background:#f9f9f9;"><td style="padding:10px; font-weight:600;">Guests:</td><td style="padding:10px;">${parseInt(headcount).toLocaleString('en-PH')} pax</td></tr>
            <tr style="border-bottom:1px solid #ddd;"><td style="padding:10px; font-weight:600;">Venue:</td><td style="padding:10px;">${escapeHtml(venue)}</td></tr>
            <tr style="border-bottom:1px solid #ddd; background:#f9f9f9;"><td style="padding:10px; font-weight:600;">Backdrop:</td><td style="padding:10px;">${escapeHtml(backdrop)}</td></tr>
            <tr style="border-bottom:1px solid #ddd;"><td style="padding:10px; font-weight:600;">Package:</td><td style="padding:10px;">${escapeHtml(packageName)}</td></tr>
            ${bundleRow}
            ${foodRow}
        </table>
        ${priceBreakdown}`;
}

// ─── INIT ─────────────────────────────────────────────────
document.getElementById('eventDateInput').addEventListener('change', function () {
    const err = eventDateError(this.value, currentHeadcount());
    if (err) {
        Swal.fire({ icon:'warning', title:'Event date too soon', text:err, confirmButtonColor:'#2D2D4A' });
        this.value = IS_EDIT && !eventDateError(ORIGINAL_EVENT_DATE, currentHeadcount()) ? ORIGINAL_EVENT_DATE : '';
    }
    updateDateRules();
});
// More guests can mean a longer preparation time, so the earliest date is recalculated as the number changes.
document.getElementById('headcountInput').addEventListener('input', function () { updateDateRules(); recalcTotal(); });
['dpAmount', 'dpMethod', 'dpDate'].forEach(id => {
    document.getElementById(id).addEventListener('input', updateDownpayment);
    document.getElementById(id).addEventListener('change', updateDownpayment);
});
// Last check before sending: the date and the 50% downpayment (the server checks both again).
document.getElementById('bookingForm').addEventListener('submit', function (ev) {
    recalcTotal();
    const dateErr = eventDateError(this.querySelector('[name="event_date"]').value, currentHeadcount());
    if (dateErr) {
        ev.preventDefault();
        Swal.fire({ icon:'warning', title:'Event date too soon', text:dateErr, confirmButtonColor:'#2D2D4A' });
        currentStep = 1; showStep(1);
        return;
    }
    if (!updateDownpayment()) {
        ev.preventDefault();
        Swal.fire({ icon:'warning', title:'Downpayment required', text:downpaymentState().error, confirmButtonColor:'#2D2D4A' });
    }
});
updateDateRules();

showStep(1);

// On an edited booking the saved location stands unless a new one is chosen.
if (IS_EDIT && document.getElementById('barangayName').value) {
    ['regionSelect', 'provinceSelect', 'municipalitySelect', 'barangaySelect'].forEach(id => document.getElementById(id).removeAttribute('required'));
}

// If in edit mode, auto-trigger backdrop/package loading based on pre-filled event type
<?php if (!$is_edit && $pre_backdrop_type !== ''): ?>
window.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        loadBackdropsByType();
        loadPackagesByType();
        loadMenuData();
    }, 300);
});
<?php endif; ?>
<?php if ($is_edit && !empty($edit_data['event_type'])): ?>
window.addEventListener('DOMContentLoaded', function() {
    // Trigger loads after DOM ready
    setTimeout(() => {
        loadBackdropsByType();
        loadPackagesByType();
    }, 300);
});
<?php endif; ?>
</script>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>