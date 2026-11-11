<?php
/**
 * Client Surveys Module - Janet's Quality Catering System
 *
 * - Surveys open once an event is finished: the booking is Completed, or Approved with its date reached, and not archived
 *   (one survey per booking).
 * - Client mode      : surveys.php?t=TOKEN  -> public page (no login) where the CLIENT rates each criterion 1-5 stars.
 * - ADMIN and OWNER  : full CRUD + photo upload + "copy survey link" for finished events.
 */
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/activity_logger.php';

/* ------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------ */

/** The questions a client answers, each rated 1 to 5. Column name => label, question, icon. */
const SURVEY_CRITERIA = [
    'rating_food'         => ['Food quality', 'How did the food taste, look and hold up during the event?', 'bx-restaurant'],
    'rating_service'      => ['Staff and service', 'How helpful, courteous and attentive was our team?', 'bx-group'],
    'rating_timeliness'   => ['Timeliness', 'Were the setup and food service ready on time?', 'bx-time-five'],
    'rating_presentation' => ['Setup and presentation', 'How did the backdrop, tables and overall setup look?', 'bx-palette'],
    'rating_value'        => ['Value for money', 'Was the package worth what you paid?', 'bx-wallet'],
];

/**
 * Reads the criteria from a submitted form.
 * @return array{values: array<string, ?int>, recommend: ?int, error: ?string}
 */
function survey_read_criteria(bool $required): array
{
    $values = [];
    $error = null;
    foreach (SURVEY_CRITERIA as $col => [$label]) {
        $v = (int)($_POST[$col] ?? 0);
        if ($v >= 1 && $v <= 5) {
            $values[$col] = $v;
        } else {
            $values[$col] = null;
            if ($required && $error === null) {
                $error = 'Please rate ' . strtolower($label) . ' from 1 to 5 stars.';
            }
        }
    }
    $answer = $_POST['would_recommend'] ?? '';
    $recommend = $answer === '1' ? 1 : ($answer === '0' ? 0 : null);
    if ($required && $recommend === null && $error === null) {
        $error = 'Please say whether you would recommend us.';
    }
    return ['values' => $values, 'recommend' => $recommend, 'error' => $error];
}

// Escape for output (also fixes text that was saved HTML-encoded by the old sanitize())
function e($s)
{
    return htmlspecialchars(html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

// Secure token for a booking (used in the link sent to the client)
function survey_token(array $b)
{
    $key = 'janets-survey|' . DB_NAME . '|' . DB_PASS;
    $sig = substr(hash_hmac('sha256', $b['booking_id'] . '|' . $b['booking_ref'], $key), 0, 24);
    return $b['booking_id'] . '-' . $sig;
}

// Full public link for a booking
function survey_link(array $b)
{
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/surveys.php?t=' . survey_token($b);
}

// Find the booking that belongs to a token (or null)
function booking_from_token(PDO $pdo, $token)
{
    $parts = explode('-', (string)$token, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0])) return null;

    $stmt = $pdo->prepare("SELECT booking_id, booking_ref, fullname, event_name, event_date, status, is_archived FROM event_bookings WHERE booking_id = ?");
    $stmt->execute([(int)$parts[0]]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) return null;

    return hash_equals(survey_token($b), (string)$token) ? $b : null;
}

// Validates the picture by its real content and saves it. Returns the path, or null with $error set.
function uploadSurveyPhoto($file, $upload_dir, ?string &$error = null)
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null; // the photo is optional
    $ext = jq_image_upload_extension($file, $error);
    if ($ext === null) return null;

    $path = $upload_dir . 'survey_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        $error = 'The photo could not be saved. Please try again.';
        return null;
    }
    return $path;
}

$pdo = getDBConnection();
if (!$pdo) {
    die('Database connection failed.');
}

/* ------------------------------------------------------------------
 * Table setup
 * ------------------------------------------------------------------ */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `client_surveys` (
            `survey_id` INT AUTO_INCREMENT PRIMARY KEY,
            `booking_id` INT DEFAULT NULL,
            `client_name` VARCHAR(255) NOT NULL,
            `event_name` VARCHAR(255),
            `event_date` DATE,
            `rating` TINYINT(1) NOT NULL DEFAULT 5,
            `feedback` TEXT,
            `photo_path` VARCHAR(500) DEFAULT NULL,
            `is_featured` TINYINT(1) DEFAULT 0,
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
}

// Older installs: add booking_id + unique index (one survey per booking)
try {
    $has_col = $pdo->query("SHOW COLUMNS FROM `client_surveys` LIKE 'booking_id'")->fetchColumn();
    if (!$has_col) {
        $pdo->exec("ALTER TABLE `client_surveys` ADD COLUMN `booking_id` INT DEFAULT NULL AFTER `survey_id`");
    }
    $has_idx = $pdo->query("SHOW INDEX FROM `client_surveys` WHERE Key_name = 'uq_survey_booking'")->fetchColumn();
    if (!$has_idx) {
        $pdo->exec("ALTER TABLE `client_surveys` ADD UNIQUE KEY `uq_survey_booking` (`booking_id`)");
    }
} catch (Exception $e) {
}

/* ==================================================================
 * CLIENT MODE (public link, no login):  surveys.php?t=TOKEN
 * ================================================================== */
if (isset($_GET['t'])) {
    $token   = $_GET['t'];
    $booking = booking_from_token($pdo, $token);
    $state   = 'form';   // form | invalid | not_completed | done
    $error   = '';
    $existing = null;

    if (!$booking) {
        $state = 'invalid';
    } elseif ((int)$booking['is_archived'] === 1 || !($booking['status'] === 'Completed' || ($booking['status'] === 'Approved' && $booking['event_date'] <= date('Y-m-d')))) {
        $state = 'not_completed';
    } else {
        $stmt = $pdo->prepare("SELECT survey_id FROM client_surveys WHERE booking_id = ?");
        $stmt->execute([$booking['booking_id']]);
        $existing = $stmt->fetchColumn();
        if ($existing) $state = 'done';
    }

    if ($state === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rating   = (int)($_POST['rating'] ?? 0);
        $feedback = trim($_POST['feedback'] ?? '');
        $criteria = survey_read_criteria(true);

        if (!jq_csrf_valid($_POST['csrf_token'] ?? null)) {
            $error = 'Your session expired. Please check your answers and submit again.';
        } elseif ($criteria['error'] !== null) {
            $error = $criteria['error'];
        } elseif ($rating < 1 || $rating > 5) {
            $error = 'Please rate your overall experience from 1 to 5 stars.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO client_surveys (booking_id, client_name, event_name, event_date, rating, rating_food, rating_service, rating_timeliness, rating_presentation, rating_value, would_recommend, feedback, is_featured, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)");
                $stmt->execute(array_merge(
                    [$booking['booking_id'], $booking['fullname'], $booking['event_name'], $booking['event_date'], $rating],
                    array_values($criteria['values']),
                    [$criteria['recommend'], mb_substr($feedback, 0, 2000)]
                ));
                header('Location: surveys.php?t=' . urlencode($token) . '&thanks=1');
                exit();
            } catch (PDOException $ex) {
                // Duplicate (already answered) or other error
                $state = 'done';
            }
        }
    }
    $just_submitted = isset($_GET['thanks']);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Your Experience | Janet's Quality Catering</title>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css">
    <style>
        body { font-family: 'Public Sans', sans-serif; background: linear-gradient(135deg, #f3edff 0%, #ffffff 100%); min-height: 100vh; }
        .survey-wrap { max-width: 560px; margin: 0 auto; padding: 30px 16px; }
        .brand { text-align: center; margin-bottom: 20px; }
        .brand h4 { color: #5B3FA8; font-weight: 700; margin: 0; }
        .brand small { color: #5E5A78; letter-spacing: 1px; text-transform: uppercase; }
        .s-card { background: #fff; border-radius: 18px; padding: 28px; box-shadow: 0 10px 40px rgba(147,112,219,.15); }
        .event-box { background: #f5f0ff; border-radius: 12px; padding: 14px 16px; margin-bottom: 22px; }
        .event-box .n { font-weight: 700; color: #32325d; }
        .event-box .m { color: #5E5A78; font-size: .85rem; }
        .text-muted { color: #5E5A78 !important; }
        .consider { border-left: 4px solid #5B3FA8; background: #f8f6ff; border-radius: 0 10px 10px 0; padding: 12px 14px; margin-bottom: 20px; font-size: .9rem; color: #32325d; }
        .consider strong { display: block; margin-bottom: 4px; }
        .consider ul { margin: 0; padding-left: 18px; }
        .q { padding: 14px 0; border-bottom: 1px solid #ece7f8; }
        .q-head { display: flex; gap: 10px; align-items: flex-start; }
        .q-head i { font-size: 1.5rem; color: #5B3FA8; line-height: 1.2; flex-shrink: 0; }
        .q-label { font-weight: 700; color: #32325d; }
        .q-help { font-size: .85rem; color: #5E5A78; }
        .q .star-rating { justify-content: flex-start; margin-top: 8px; flex-wrap: wrap; }
        .q .star-rating .star i { font-size: 2.1rem; }
        .q .rating-text { text-align: left; margin-top: 2px; font-size: .85rem; }
        .recommend { display: flex; gap: 10px; margin-top: 8px; }
        .recommend label { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 10px; border: 2px solid #8A90A2; border-radius: 10px; cursor: pointer; font-weight: 600; color: #32325d; }
        .recommend input { accent-color: #5B3FA8; }
        .recommend label:has(input:checked) { border-color: #5B3FA8; background: #f3edff; }
        .star-rating { display: flex; justify-content: center; gap: 6px; }
        .star-rating .star { background: none; border: 0; padding: 2px; cursor: pointer; line-height: 1; }
        .star-rating .star:focus-visible { outline: 2px solid #5B3FA8; border-radius: 6px; }
        .star-rating .star i { font-size: 3rem; color: #8A90A2; transition: color .15s, transform .15s; }
        .star-rating .star.active i { color: #B45309; }
        .star-rating .star:hover i { transform: scale(1.15); }
        .rating-text { text-align: center; font-weight: 600; color: #5E5A78; margin-top: 6px; min-height: 24px; }
        .btn-primary { background: #5B3FA8; border-color: #5B3FA8; }
        .btn-primary:hover { background: #4A3290; border-color: #4A3290; }
        .big-icon { font-size: 4.5rem; }
    </style>
</head>
<body>
<div class="survey-wrap">
    <div class="brand">
        <h4>Janet's Quality Catering</h4>
        <small>Client Satisfaction Survey</small>
    </div>
    <div class="s-card">
    <?php if ($state === 'invalid'): ?>
        <div class="text-center py-3">
            <i class="bx bx-error-circle big-icon" style="color:#5B3FA8;"></i>
            <h5 class="mt-2">Invalid survey link</h5>
            <p class="text-muted mb-0">This link is not valid. Please ask Janet's Catering for a new one.</p>
        </div>

    <?php elseif ($state === 'not_completed'): ?>
        <div class="text-center py-3">
            <i class="bx bx-time-five big-icon" style="color:#5B3FA8;"></i>
            <h5 class="mt-2">Not available yet</h5>
            <p class="text-muted mb-0">The survey opens after your approved event has taken place. Please check back after your event.</p>
        </div>

    <?php elseif ($state === 'done'): ?>
        <div class="text-center py-3">
            <i class="bx bx-check-circle big-icon" style="color:#5B3FA8;"></i>
            <h5 class="mt-2"><?php echo $just_submitted ? 'Thank you for your feedback!' : 'Survey already submitted'; ?></h5>
            <p class="text-muted mb-0">
                <?php echo $just_submitted
                    ? "We truly appreciate you choosing Janet's Quality Catering."
                    : 'We already received your answer for this event. Thank you!'; ?>
            </p>
        </div>

    <?php else: ?>
        <div class="event-box">
            <div class="n"><?php echo e($booking['fullname']); ?></div>
            <div class="m">
                <i class="bx bx-calendar-event"></i>
                <?php echo e($booking['event_name']); ?>
                <?php if ($booking['event_date']): ?> - <?php echo date('M d, Y', strtotime($booking['event_date'])); ?><?php endif; ?>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?php echo e($error); ?></div>
        <?php endif; ?>

        <div class="consider">
            <strong><i class="bx bx-info-circle"></i> Before you begin</strong>
            <ul>
                <li>Please answer based on your own event. It takes about two minutes.</li>
                <li>A rating of 1 means poor and 5 means excellent.</li>
                <li>Your answers go to Janet's Quality Catering, linked to your name and event, so we can improve and follow up with you.</li>
            </ul>
        </div>

        <form method="POST" id="publicSurveyForm">
            <?php echo jq_csrf_field(); ?>
            <?php foreach (SURVEY_CRITERIA as $col => [$label, $question, $icon]): $picked = (int)($_POST[$col] ?? 0); ?>
            <div class="q">
                <div class="q-head"><i class="bx <?php echo $icon; ?>" aria-hidden="true"></i><div><div class="q-label"><?php echo e($label); ?></div><div class="q-help"><?php echo e($question); ?></div></div></div>
                <input type="hidden" name="<?php echo $col; ?>" id="<?php echo $col; ?>" value="<?php echo $picked; ?>">
                <div class="star-rating" data-input="<?php echo $col; ?>" data-label="<?php echo $col; ?>_text" data-name="<?php echo e($label); ?>" role="group" aria-label="<?php echo e($label); ?> rating">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <button type="button" class="star" data-value="<?php echo $i; ?>" aria-label="<?php echo $i; ?> of 5 stars"><i class="bx bxs-star"></i></button>
                    <?php endfor; ?>
                </div>
                <div class="rating-text" id="<?php echo $col; ?>_text"></div>
            </div>
            <?php endforeach; ?>

            <div class="q">
                <div class="q-head"><i class="bx bx-star" aria-hidden="true"></i><div><div class="q-label">Overall experience</div><div class="q-help">Taking everything together, how was your event with us?</div></div></div>
                <input type="hidden" name="rating" id="rating" value="<?php echo (int)($_POST['rating'] ?? 0); ?>">
                <div class="star-rating" data-input="rating" data-label="ratingText" data-name="Overall experience" role="group" aria-label="Overall experience rating">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <button type="button" class="star" data-value="<?php echo $i; ?>" aria-label="<?php echo $i; ?> of 5 stars"><i class="bx bxs-star"></i></button>
                    <?php endfor; ?>
                </div>
                <div class="rating-text" id="ratingText"></div>
            </div>

            <div class="q">
                <div class="q-head"><i class="bx bx-like" aria-hidden="true"></i><div><div class="q-label">Would you recommend us to family or friends?</div></div></div>
                <div class="recommend">
                    <label><input type="radio" name="would_recommend" value="1" <?php echo ($_POST['would_recommend'] ?? '') === '1' ? 'checked' : ''; ?>> Yes</label>
                    <label><input type="radio" name="would_recommend" value="0" <?php echo ($_POST['would_recommend'] ?? '') === '0' ? 'checked' : ''; ?>> No</label>
                </div>
            </div>

            <div class="mt-3 mb-3">
                <label class="form-label fw-semibold" for="surveyFeedback"><i class="bx bx-message-square-detail" style="color:#5B3FA8;" aria-hidden="true"></i> Anything else you would like to tell us? (optional)</label>
                <textarea name="feedback" id="surveyFeedback" class="form-control" rows="4" maxlength="2000" placeholder="What went well, and what could we do better?"><?php echo e($_POST['feedback'] ?? ''); ?></textarea>
            </div>

<button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bx bx-send me-1"></i> Submit Survey
            </button>
        </form>
    <?php endif; ?>
    </div>
</div>

<?php if ($state === 'form'): ?>
<script>
(function () {
    var LABELS = {0: 'Tap a star to rate', 1: 'Poor', 2: 'Fair', 3: 'Good', 4: 'Very Good', 5: 'Excellent'};
    var boxes = document.querySelectorAll('.star-rating');
    boxes.forEach(function (box) {
        var input = document.getElementById(box.dataset.input);
        var label = document.getElementById(box.dataset.label);
        var stars = box.querySelectorAll('.star');
        function paint(v) {
            stars.forEach(function (s) { s.classList.toggle('active', parseInt(s.dataset.value) <= v); });
            label.textContent = v ? (v + ' / 5 - ' + LABELS[v]) : LABELS[0];
            label.style.color = '';
        }
        stars.forEach(function (s) {
            s.addEventListener('mouseenter', function () { paint(parseInt(s.dataset.value)); });
            s.addEventListener('click', function () { input.value = s.dataset.value; paint(parseInt(input.value)); });
        });
        box.addEventListener('mouseleave', function () { paint(parseInt(input.value) || 0); });
        paint(parseInt(input.value) || 0);
    });
    document.getElementById('publicSurveyForm').addEventListener('submit', function (ev) {
        var first = null;
        boxes.forEach(function (box) {
            if (!parseInt(document.getElementById(box.dataset.input).value)) {
                var label = document.getElementById(box.dataset.label);
                label.textContent = 'Please choose 1 to 5 stars.';
                label.style.color = '#B42318';
                first = first || box;
            }
        });
        if (!first && !this.querySelector('input[name="would_recommend"]:checked')) {
            first = this.querySelector('.recommend');
            alert('Please say whether you would recommend us.');
        }
        if (first) { ev.preventDefault(); first.scrollIntoView({ block: 'center' }); }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
    <?php
    exit();
}

/* ==================================================================
 * ADMIN / OWNER MODE (login required)
 * ================================================================== */
$page_title   = "Client Surveys | Janet's Quality Catering";
$current_page = 'surveys';

require_once 'includes/auth_check.php';

// ADMIN and OWNER manage surveys (role read from the database)
$is_admin = in_array(jq_require_roles($pdo, ['ADMIN', 'OWNER'], 'Client Surveys'), ['ADMIN', 'OWNER'], true);

// Uploads folder
$upload_dir = 'uploads/surveys/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Finished events that do not have a survey yet
function getPendingBookings(PDO $pdo)
{
    try {
        $stmt = $pdo->query("
            SELECT b.booking_id, b.booking_ref, b.fullname, b.event_name, b.event_date
            FROM event_bookings b
            LEFT JOIN client_surveys s ON s.booking_id = b.booking_id
            WHERE b.is_archived = 0 AND (b.status = 'Completed' OR (b.status = 'Approved' AND b.event_date <= CURDATE())) AND s.survey_id IS NULL
            ORDER BY b.event_date DESC, b.booking_id DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/* ------------------------------------------------------------------
 * Handle form submissions (Admin only)
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    $form_action = $_POST['form_action'] ?? '';

    try {
        switch ($form_action) {
            case 'add_survey':
                $booking_id = (int)($_POST['booking_id'] ?? 0);
                $rating     = (int)($_POST['rating'] ?? 0);
                $criteria   = survey_read_criteria(true);

                if ($criteria['error'] !== null) {
                    setFlash($criteria['error'], 'danger');
                    break;
                }
                if ($rating < 1 || $rating > 5) {
                    setFlash('Please select an overall rating from 1 to 5 stars.', 'danger');
                    break;
                }

                // The event must be finished and not yet surveyed
                $booking = null;
                foreach (getPendingBookings($pdo) as $pb) {
                    if ((int)$pb['booking_id'] === $booking_id) { $booking = $pb; break; }
                }
                if (!$booking) {
                    setFlash('A survey can only be added for a finished event that has no survey yet.', 'danger');
                    break;
                }

                $photo_error = null;
                $photo_path = uploadSurveyPhoto($_FILES['photo'] ?? null, $upload_dir, $photo_error);
                if ($photo_error !== null) {
                    setFlash($photo_error, 'danger');
                    break;
                }

                $stmt = $pdo->prepare("INSERT INTO client_surveys (booking_id, client_name, event_name, event_date, rating, rating_food, rating_service, rating_timeliness, rating_presentation, rating_value, would_recommend, feedback, photo_path, is_featured, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute(array_merge(
                    [$booking_id, $booking['fullname'], $booking['event_name'], $booking['event_date'], $rating],
                    array_values($criteria['values']),
                    [$criteria['recommend'], trim($_POST['feedback'] ?? ''), $photo_path, isset($_POST['is_featured']) ? 1 : 0, $current_user['id']]
                ));
                setFlash('Client survey submitted successfully!', 'success');
                break;

            case 'edit_survey':
                $survey_id = (int)$_POST['survey_id'];
                $rating    = (int)($_POST['rating'] ?? 0);
                // Surveys answered before the criteria existed may leave them empty.
                $criteria  = survey_read_criteria(false);
                $client_name = jq_clean_name($_POST['client_name'] ?? '');

                if ($rating < 1 || $rating > 5) {
                    setFlash('Please select an overall rating from 1 to 5 stars.', 'danger');
                    break;
                }
                if (($name_error = jq_name_error($client_name, 'Client name', true, 255)) !== null) {
                    setFlash($name_error, 'danger');
                    break;
                }

                // Current photo comes from the database (not from the form)
                $stmt = $pdo->prepare("SELECT photo_path FROM client_surveys WHERE survey_id = ?");
                $stmt->execute([$survey_id]);
                $photo_path = $stmt->fetchColumn() ?: null;

                $photo_error = null;
                $new_photo = uploadSurveyPhoto($_FILES['photo'] ?? null, $upload_dir, $photo_error);
                if ($photo_error !== null) {
                    setFlash($photo_error, 'danger');
                    break;
                }
                if ($new_photo) {
                    if ($photo_path && file_exists($photo_path)) {
                        unlink($photo_path);
                    }
                    $photo_path = $new_photo;
                }

                $stmt = $pdo->prepare("UPDATE client_surveys SET client_name = ?, event_name = ?, event_date = ?, rating = ?, rating_food = ?, rating_service = ?, rating_timeliness = ?, rating_presentation = ?, rating_value = ?, would_recommend = ?, feedback = ?, photo_path = ?, is_featured = ? WHERE survey_id = ?");
                $stmt->execute(array_merge(
                    [$client_name, trim($_POST['event_name'] ?? ''), ($_POST['event_date'] ?? '') ?: null, $rating],
                    array_values($criteria['values']),
                    [$criteria['recommend'], trim($_POST['feedback'] ?? ''), $photo_path, isset($_POST['is_featured']) ? 1 : 0, $survey_id]
                ));
                setFlash('Client survey updated successfully!', 'success');
                break;

            case 'delete_survey':
                $survey_id = (int)$_POST['survey_id'];

                $stmt = $pdo->prepare("SELECT photo_path FROM client_surveys WHERE survey_id = ?");
                $stmt->execute([$survey_id]);
                $photo = $stmt->fetchColumn();
                if ($photo && file_exists($photo)) {
                    unlink($photo);
                }

                $stmt = $pdo->prepare("DELETE FROM client_surveys WHERE survey_id = ?");
                $stmt->execute([$survey_id]);
                setFlash('Client survey deleted successfully!', 'success');
                break;

            case 'toggle_featured':
                $survey_id = (int)$_POST['survey_id'];
                $stmt = $pdo->prepare("UPDATE client_surveys SET is_featured = NOT is_featured WHERE survey_id = ?");
                $stmt->execute([$survey_id]);
                setFlash('Featured status updated!', 'success');
                break;
        }
    } catch (PDOException $e) {
        error_log('surveys.php: ' . $e->getMessage());
        setFlash('The survey could not be saved. Nothing was changed.', 'danger');
    }

    redirect('surveys.php');
}

/* ------------------------------------------------------------------
 * Load data
 * ------------------------------------------------------------------ */
$surveys = [];
$stats = ['total' => 0, 'featured' => 0, 'avg_rating' => 0, 'five_star' => 0];
$criteria_avg = [];
$recommend_pct = null;

try {
    $surveys = $pdo->query("SELECT * FROM client_surveys ORDER BY created_at DESC")->fetchAll();

    $stats['total']     = count($surveys);
    $stats['featured']  = count(array_filter($surveys, fn($s) => $s['is_featured']));
    $stats['five_star'] = count(array_filter($surveys, fn($s) => $s['rating'] == 5));
    if ($stats['total'] > 0) {
        $stats['avg_rating'] = round(array_sum(array_column($surveys, 'rating')) / $stats['total'], 1);
    }
    foreach (SURVEY_CRITERIA as $col => $info) {
        $answers = array_filter(array_column($surveys, $col), static fn($v) => $v !== null);
        $criteria_avg[$col] = $answers ? round(array_sum($answers) / count($answers), 1) : null;
    }
    $recommend_answers = array_filter(array_column($surveys, 'would_recommend'), static fn($v) => $v !== null);
    if ($recommend_answers) {
        $recommend_pct = (int)round(array_sum($recommend_answers) / count($recommend_answers) * 100);
    }
} catch (Exception $e) {
}

$pending_bookings = getPendingBookings($pdo);

include 'includes/header.php';
?>

<style>
    /* One icon colour and one star colour across the module, with at least 3:1 contrast on the cards. */
    :root { --sv-icon: #5B3FA8; --sv-icon-bg: rgba(91, 63, 168, .12); --sv-star: #B45309; --sv-star-off: #8A90A2; }
    [data-theme="dark"] { --sv-icon: #C4B2F5; --sv-icon-bg: rgba(196, 178, 245, .16); --sv-star: #F5B83D; --sv-star-off: #8D88A8; }
    .sv-criteria { list-style: none; margin: 0 0 10px; padding: 0; font-size: .82rem; }
    .sv-criteria li { display: flex; align-items: center; gap: 6px; padding: 2px 0; color: var(--body-color); }
    .sv-criteria i { color: var(--sv-icon) !important; font-size: 1rem; }
    .sv-criteria b { margin-left: auto; color: var(--heading-color); }
    .sv-section { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px 20px; }
    .sv-avg-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
    .sv-avg { display: flex; align-items: center; gap: 10px; }
    .sv-avg i { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; background: var(--sv-icon-bg); color: var(--sv-icon) !important; flex-shrink: 0; }
    .sv-avg strong { display: block; color: var(--heading-color); }
    .sv-avg span { font-size: .78rem; color: var(--body-color); }
    .sv-q { margin-bottom: 12px; }
    .sv-q .sv-q-label { display: flex; gap: 8px; align-items: center; font-weight: 600; color: var(--heading-color); }
    .sv-q .sv-q-label i { color: var(--sv-icon) !important; font-size: 1.2rem; }
    .sv-q small { display: block; color: var(--body-color); margin-bottom: 2px; }
    .survey-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.3s;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .survey-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(147, 112, 219, 0.15); }

    .survey-photo {
        height: 200px;
        overflow: hidden;
        background: linear-gradient(135deg, var(--bs-primary) 0%, #8B5CF6 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
    }
    .survey-photo img { width: 100%; height: 100%; object-fit: cover; }
    .survey-photo .no-photo { color: white; font-size: 3rem; }
    .survey-photo .featured-badge {
        position: absolute; top: 12px; right: 12px;
        background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
        color: #000; padding: 4px 12px; border-radius: 20px;
        font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
        display: flex; align-items: center; gap: 4px;
    }
    .survey-photo .source-badge {
        position: absolute; top: 12px; left: 12px;
        background: rgba(255,255,255,.92); color: #7B5FC7;
        padding: 4px 10px; border-radius: 20px;
        font-size: 0.68rem; font-weight: 700;
    }

    .survey-content { padding: 20px; display: flex; flex-direction: column; flex: 1 1 auto; }
    .survey-rating { display: flex; gap: 3px; margin-bottom: 12px; }
    .survey-rating i { color: var(--sv-star); font-size: 1.1rem; }
    .survey-rating i.empty { color: var(--sv-star-off); }
    .survey-client { font-weight: 700; color: var(--heading-color); font-size: 1.1rem; margin-bottom: 4px; }
    .survey-event { color: var(--bs-secondary); font-size: 0.85rem; margin-bottom: 12px; }
    .survey-feedback {
        color: var(--body-color); font-size: 0.9rem; line-height: 1.6;
        display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
    }
    .survey-date {
        color: var(--bs-secondary); font-size: 0.75rem; margin-top: 12px; padding-top: 12px;
        border-top: 1px solid var(--border-color);
    }
    /* Action buttons: Edit | Featured | Delete  -> same height, same row, pinned to card bottom */
    html body .survey-photo .featured-badge:not(#x), html body .survey-photo .featured-badge:not(#x) * { color: #1F1500 !important; }
    html body .survey-photo .source-badge:not(#x), html body .survey-photo .source-badge:not(#x) * { color: #4A3290 !important; }
    .survey-actions {
        display: flex;
        align-items: stretch;
        gap: 8px;
        margin-top: auto;
        padding-top: 15px;
    }
    .survey-actions form { display: flex; margin: 0; }
    .survey-actions .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 40px;
        margin: 0;
        padding: 0 14px;
        line-height: 1;
        white-space: nowrap;
    }
    .survey-actions .btn i { font-size: 1.1rem; line-height: 1; margin: 0; }
    .survey-actions .btn-edit { flex: 1 1 auto; gap: 6px; }
    .survey-actions .btn-edit i { margin-right: 0 !important; }
    .survey-actions .btn-icon { width: 42px; min-width: 42px; padding: 0; flex: 0 0 42px; }
    .survey-actions form .btn-icon { height: 100%; }

    .stat-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; text-align: center; }
    .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; font-size: 1.5rem; }
    .stat-value { font-size: 1.8rem; font-weight: 700; color: var(--heading-color); }
    .stat-label { font-size: 0.8rem; color: var(--bs-secondary); text-transform: uppercase; letter-spacing: 0.5px; }

    /* ---------- Clickable 1-5 star rating ---------- */
    .star-rating { display: inline-flex; align-items: center; gap: 4px; user-select: none; }
    .star-rating .star {
        background: none !important; border: 0 !important; box-shadow: none !important;
        padding: 2px; cursor: pointer; line-height: 1;
    }
    .star-rating .star:focus-visible { outline: 2px solid var(--sv-icon); border-radius: 6px; }
    .star-rating .star i { font-size: 2.1rem; color: var(--sv-star-off) !important; transition: color .15s, transform .15s; }
    .star-rating .star.active i { color: var(--sv-star) !important; }
    .star-rating .star:hover i { transform: scale(1.2); }
    .rating-text { margin-left: 10px; font-size: 0.85rem; font-weight: 600; color: var(--bs-secondary); min-width: 110px; }

    .photo-preview { max-width: 200px; max-height: 150px; border-radius: 10px; border: 2px solid var(--border-color); margin-top: 10px; }

    .empty-surveys { text-align: center; padding: 60px 20px; background: var(--card-bg); border-radius: 16px; border: 1px solid var(--border-color); }
    .empty-surveys i { font-size: 4rem; color: var(--bs-secondary); opacity: 0.3; }

    /* Awaiting survey box */
    .pending-box { background: var(--card-bg); border: 1px dashed var(--bs-primary); border-radius: 14px; padding: 18px 20px; }
    .pending-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border-color); flex-wrap: wrap; }
    .pending-item:last-child { border-bottom: 0; }
    .pending-item .p-name { font-weight: 600; color: var(--heading-color); }
    .pending-item .p-meta { font-size: 0.8rem; color: var(--bs-secondary); }

    @media (max-width: 768px) { .survey-photo { height: 150px; } }
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <h4 class="page-title mb-1">Client Surveys</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Client Surveys</li>
            </ol>
        </nav>
    </div>
    <?php if ($is_admin): ?>
    <button type="button" class="btn btn-primary" onclick="openAddSurvey()">
        <i class="bx bx-plus me-1"></i> Add Survey
        <?php if (count($pending_bookings) > 0): ?>
        <span class="badge bg-white text-primary ms-1"><?php echo count($pending_bookings); ?></span>
        <?php endif; ?>
    </button>
    <?php endif; ?>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-icon" style="background: var(--sv-icon-bg);"><i class="bx bx-message-square-dots" style="color: var(--sv-icon);"></i></div>
            <div class="stat-value"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Surveys</div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-icon" style="background: var(--sv-icon-bg);"><i class="bx bx-star" style="color: var(--sv-icon);"></i></div>
            <div class="stat-value"><?php echo $stats['avg_rating']; ?></div>
            <div class="stat-label">Average Rating</div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-icon" style="background: var(--sv-icon-bg);"><i class="bx bx-trophy" style="color: var(--sv-icon);"></i></div>
            <div class="stat-value"><?php echo $stats['five_star']; ?></div>
            <div class="stat-label">5-Star Reviews</div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-icon" style="background: var(--sv-icon-bg);"><i class="bx bx-crown" style="color: var(--sv-icon);"></i></div>
            <div class="stat-value"><?php echo $stats['featured']; ?></div>
            <div class="stat-label">Featured</div>
        </div>
    </div>
</div>

<?php if (array_filter($criteria_avg, static fn($v) => $v !== null) || $recommend_pct !== null): ?>
<div class="sv-section mb-4">
    <h6 class="mb-3" style="color: var(--heading-color);">Average by criterion</h6>
    <div class="sv-avg-grid">
        <?php foreach (SURVEY_CRITERIA as $col => [$label, , $icon]): ?>
        <div class="sv-avg"><i class="bx <?php echo $icon; ?>" aria-hidden="true"></i><div><strong><?php echo $criteria_avg[$col] !== null ? $criteria_avg[$col] . ' / 5' : 'No answers'; ?></strong><span><?php echo e($label); ?></span></div></div>
        <?php endforeach; ?>
        <div class="sv-avg"><i class="bx bx-like" aria-hidden="true"></i><div><strong><?php echo $recommend_pct !== null ? $recommend_pct . '%' : 'No answers'; ?></strong><span>Would recommend us</span></div></div>
    </div>
</div>
<?php endif; ?>

<!-- Awaiting Survey: finished events without a survey -->
<?php if (!empty($pending_bookings)): ?>
<div class="pending-box mb-4">
    <h6 class="mb-1" style="color: var(--heading-color);">
        <i class="bx bx-time-five me-1" style="color: var(--sv-icon);"></i>
        Awaiting Survey (<?php echo count($pending_bookings); ?>)
    </h6>
    <small class="text-muted d-block mb-2">
        These approved events have taken place.
        <?php if ($is_admin): ?>Send the client their survey link, or fill it in for them.<?php endif; ?>
    </small>
    <?php foreach ($pending_bookings as $pb): ?>
    <div class="pending-item">
        <div>
            <div class="p-name"><?php echo e($pb['fullname']); ?></div>
            <div class="p-meta">
                <?php echo e($pb['booking_ref']); ?> &bull; <?php echo e($pb['event_name']); ?>
                <?php if ($pb['event_date']): ?> - <?php echo date('M d, Y', strtotime($pb['event_date'])); ?><?php endif; ?>
            </div>
        </div>
        <?php if ($is_admin): ?>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-label-primary"
                    data-link="<?php echo e(survey_link($pb)); ?>" onclick="copySurveyLink(this)">
                <i class="bx bx-link me-1"></i> Copy Client Link
            </button>
            <button type="button" class="btn btn-sm btn-primary" onclick="openAddSurvey(<?php echo (int)$pb['booking_id']; ?>)">
                <i class="bx bx-star me-1"></i> Give Survey
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Surveys Grid -->
<?php if (empty($surveys)): ?>
<div class="empty-surveys">
    <i class="bx bx-message-square-dots"></i>
    <h5 class="mt-3" style="color: var(--heading-color);">No Surveys Yet</h5>
    <p class="text-muted mb-4">Surveys open once an approved event has taken place.</p>
    <?php if ($is_admin && !empty($pending_bookings)): ?>
    <button type="button" class="btn btn-primary" onclick="openAddSurvey()">
        <i class="bx bx-plus me-1"></i> Add First Survey
    </button>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="row">
    <?php foreach ($surveys as $survey):
        $js_survey = [
            'survey_id'   => (int)$survey['survey_id'],
            'booking_id'  => $survey['booking_id'] ? (int)$survey['booking_id'] : null,
            'client_name' => html_entity_decode((string)$survey['client_name'], ENT_QUOTES, 'UTF-8'),
            'event_name'  => html_entity_decode((string)$survey['event_name'], ENT_QUOTES, 'UTF-8'),
            'event_date'  => $survey['event_date'],
            'rating'      => (int)$survey['rating'],
            'criteria'    => array_intersect_key($survey, SURVEY_CRITERIA),
            'would_recommend' => $survey['would_recommend'],
            'feedback'    => html_entity_decode((string)$survey['feedback'], ENT_QUOTES, 'UTF-8'),
            'photo_path'  => $survey['photo_path'],
            'is_featured' => (int)$survey['is_featured'],
        ];
    ?>
    <div class="col-lg-4 col-md-6 mb-4">
        <div class="survey-card">
            <div class="survey-photo">
                <?php if ($survey['photo_path'] && file_exists($survey['photo_path'])): ?>
                <img src="<?php echo e($survey['photo_path']); ?>" alt="Survey Photo">
                <?php else: ?>
                <i class="bx bx-image no-photo"></i>
                <?php endif; ?>

                <?php if ($survey['booking_id'] && $survey['created_by'] === null): ?>
                <span class="source-badge"><i class="bx bx-user-check"></i> Answered by client</span>
                <?php endif; ?>

                <?php if ($survey['is_featured']): ?>
                <span class="featured-badge"><i class="bx bx-star"></i> Featured</span>
                <?php endif; ?>
            </div>

            <div class="survey-content">
                <div class="survey-rating">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="bx bxs-star <?php echo $i > $survey['rating'] ? 'empty' : ''; ?>"></i>
                    <?php endfor; ?>
                </div>

                <div class="survey-client"><?php echo e($survey['client_name']); ?></div>

                <?php if ($survey['event_name']): ?>
                <div class="survey-event">
                    <i class="bx bx-calendar-event me-1"></i>
                    <?php echo e($survey['event_name']); ?>
                    <?php if ($survey['event_date']): ?> - <?php echo date('M d, Y', strtotime($survey['event_date'])); ?><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($survey['rating_food'] !== null || $survey['would_recommend'] !== null): ?>
                <ul class="sv-criteria">
                    <?php foreach (SURVEY_CRITERIA as $col => [$label, , $icon]): if ($survey[$col] === null) continue; ?>
                    <li><i class="bx <?php echo $icon; ?>" aria-hidden="true"></i><?php echo e($label); ?><b><?php echo (int)$survey[$col]; ?> / 5</b></li>
                    <?php endforeach; ?>
                    <?php if ($survey['would_recommend'] !== null): ?>
                    <li><i class="bx bx-like" aria-hidden="true"></i>Would recommend<b><?php echo $survey['would_recommend'] ? 'Yes' : 'No'; ?></b></li>
                    <?php endif; ?>
                </ul>
                <?php endif; ?>
                <?php if ($survey['feedback']): ?>
                <p class="survey-feedback">"<?php echo e($survey['feedback']); ?>"</p>
                <?php endif; ?>

                <div class="survey-date">
                    <i class="bx bx-time-five me-1"></i>
                    Added <?php echo date('M d, Y', strtotime($survey['created_at'])); ?>
                </div>

                <?php if ($is_admin): ?>
                <div class="survey-actions">
                    <button type="button" class="btn btn-sm btn-label-primary btn-edit"
                            data-survey="<?php echo htmlspecialchars(json_encode($js_survey), ENT_QUOTES, 'UTF-8'); ?>"
                            onclick="editSurvey(JSON.parse(this.dataset.survey))">
                        <i class="bx bx-edit me-1"></i> Edit
                    </button>
                    <form method="POST">
                        <input type="hidden" name="form_action" value="toggle_featured">
                        <input type="hidden" name="survey_id" value="<?php echo (int)$survey['survey_id']; ?>">
                        <button type="submit" class="btn btn-sm btn-icon <?php echo $survey['is_featured'] ? 'btn-warning' : 'btn-outline-warning'; ?>" title="Toggle Featured">
                            <i class="bx bx-star"></i>
                        </button>
                    </form>
                    <button type="button" class="btn btn-sm btn-icon btn-label-danger" title="Delete"
                            data-id="<?php echo (int)$survey['survey_id']; ?>"
                            data-name="<?php echo e($survey['client_name']); ?>"
                            onclick="deleteSurvey(this.dataset.id, this.dataset.name)">
                        <i class="bx bx-trash"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($is_admin): ?>
<!-- Add Survey Modal -->
<div class="modal fade" id="addSurveyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bx bx-plus-circle me-2" style="color: var(--bs-primary);"></i>Add Client Survey</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="addSurveyForm" onsubmit="return validateSurveyForm(this, true)">
                <input type="hidden" name="form_action" value="add_survey">
                <input type="hidden" name="rating" id="add_rating" value="0">
                <div class="modal-body">
                    <?php if (empty($pending_bookings)): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bx bx-info-circle me-1"></i>
                        There are no finished events waiting for a survey. A survey opens once an <strong>Approved</strong> booking's event date has arrived.
                    </div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label">Finished Event <span class="text-danger">*</span></label>
                        <select name="booking_id" id="add_booking_id" class="form-select" required onchange="fillFromBooking(this)">
                            <option value="">-- Select a finished event --</option>
                            <?php foreach ($pending_bookings as $pb): ?>
                            <option value="<?php echo (int)$pb['booking_id']; ?>"
                                    data-client="<?php echo e($pb['fullname']); ?>"
                                    data-event="<?php echo e($pb['event_name']); ?>"
                                    data-date="<?php echo $pb['event_date'] ? e(substr($pb['event_date'], 0, 10)) : ''; ?>">
                                <?php echo e($pb['booking_ref']); ?> - <?php echo e($pb['fullname']); ?> | <?php echo e($pb['event_name']); ?>
                                <?php if ($pb['event_date']): ?> (<?php echo date('M d, Y', strtotime($pb['event_date'])); ?>)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Only finished events without a survey are listed.</small>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Client Name</label>
                            <input type="text" id="add_client_name" class="form-control" readonly placeholder="Auto-filled from booking">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Event Name</label>
                            <input type="text" id="add_event_name" class="form-control" readonly placeholder="Auto-filled from booking">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Event Date</label>
                            <input type="date" id="add_event_date" class="form-control" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Rating <span class="text-danger">*</span></label>
                            <div>
                                <div class="star-rating" id="add_stars" data-input="add_rating" data-label="add_rating_text" data-name="Overall experience">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <button type="button" class="star" data-value="<?php echo $i; ?>" aria-label="<?php echo $i; ?> star"><i class="bx bxs-star"></i></button>
                                    <?php endfor; ?>
                                    <span class="rating-text" id="add_rating_text"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <?php foreach (SURVEY_CRITERIA as $col => [$label, $question, $icon]): ?>
                        <div class="col-md-6 sv-q">
                            <div class="sv-q-label"><i class="bx <?php echo $icon; ?>" aria-hidden="true"></i><?php echo e($label); ?></div>
                            <small><?php echo e($question); ?></small>
                            <input type="hidden" name="<?php echo $col; ?>" id="add_<?php echo $col; ?>" value="0">
                            <div class="star-rating" data-input="add_<?php echo $col; ?>" data-label="add_<?php echo $col; ?>_text" data-name="<?php echo e($label); ?>">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <button type="button" class="star" data-value="<?php echo $i; ?>" aria-label="<?php echo $i; ?> of 5 stars"><i class="bx bxs-star" style="font-size:1.6rem;"></i></button>
                                <?php endfor; ?>
                                <span class="rating-text" id="add_<?php echo $col; ?>_text"></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div class="col-md-6 sv-q">
                            <div class="sv-q-label"><i class="bx bx-like" aria-hidden="true"></i>Would recommend us</div>
                            <div class="d-flex gap-3 mt-1">
                                <div class="form-check"><input class="form-check-input" type="radio" name="would_recommend" id="add_rec_yes" value="1"><label class="form-check-label" for="add_rec_yes">Yes</label></div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="would_recommend" id="add_rec_no" value="0"><label class="form-check-label" for="add_rec_no">No</label></div>
                            </div>
                        </div>
                    </div>
<div class="mb-3">
                        <label class="form-label">Feedback / Testimonial</label>
                        <textarea name="feedback" class="form-control" rows="4" placeholder="Enter client feedback..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Upload Photo</label>
                        <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp" onchange="previewPhoto(this, 'addPhotoPreview')">
                        <small class="text-muted">Accepted formats: JPG, PNG, GIF, WebP (max 5 MB)</small>
                        <img id="addPhotoPreview" class="photo-preview" style="display: none;">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_featured" id="add_featured" class="form-check-input">
                        <label for="add_featured" class="form-check-label">Mark as Featured Testimonial</label>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <?php if (!empty($pending_bookings)): ?>
                    <button type="submit" class="btn btn-primary"><i class="bx bx-save me-1"></i> Submit Survey</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Survey Modal -->
<div class="modal fade" id="editSurveyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bx bx-edit me-2" style="color: var(--bs-primary);"></i>Edit Client Survey</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" onsubmit="return validateSurveyForm(this, false)">
                <input type="hidden" name="form_action" value="edit_survey">
                <input type="hidden" name="survey_id" id="edit_survey_id">
                <input type="hidden" name="rating" id="edit_rating" value="0">
                <div class="modal-body">
                    <div id="edit_booking_info" class="alert alert-info py-2" style="display:none;"></div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Client Name <span class="text-danger">*</span></label>
                            <input type="text" name="client_name" id="edit_client_name" class="form-control" <?= jq_field_attrs('name') ?> maxlength="255" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Event Name</label>
                            <input type="text" name="event_name" id="edit_event_name" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Event Date</label>
                            <input type="date" name="event_date" id="edit_event_date" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Rating <span class="text-danger">*</span></label>
                            <div>
                                <div class="star-rating" id="edit_stars" data-input="edit_rating" data-label="edit_rating_text" data-name="Overall experience">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <button type="button" class="star" data-value="<?php echo $i; ?>" aria-label="<?php echo $i; ?> star"><i class="bx bxs-star"></i></button>
                                    <?php endfor; ?>
                                    <span class="rating-text" id="edit_rating_text"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <?php foreach (SURVEY_CRITERIA as $col => [$label, $question, $icon]): ?>
                        <div class="col-md-6 sv-q">
                            <div class="sv-q-label"><i class="bx <?php echo $icon; ?>" aria-hidden="true"></i><?php echo e($label); ?></div>
                            <small><?php echo e($question); ?></small>
                            <input type="hidden" name="<?php echo $col; ?>" id="edit_<?php echo $col; ?>" value="0">
                            <div class="star-rating" data-input="edit_<?php echo $col; ?>" data-label="edit_<?php echo $col; ?>_text" data-name="<?php echo e($label); ?>">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <button type="button" class="star" data-value="<?php echo $i; ?>" aria-label="<?php echo $i; ?> of 5 stars"><i class="bx bxs-star" style="font-size:1.6rem;"></i></button>
                                <?php endfor; ?>
                                <span class="rating-text" id="edit_<?php echo $col; ?>_text"></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div class="col-md-6 sv-q">
                            <div class="sv-q-label"><i class="bx bx-like" aria-hidden="true"></i>Would recommend us</div>
                            <div class="d-flex gap-3 mt-1">
                                <div class="form-check"><input class="form-check-input" type="radio" name="would_recommend" id="edit_rec_yes" value="1"><label class="form-check-label" for="edit_rec_yes">Yes</label></div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="would_recommend" id="edit_rec_no" value="0"><label class="form-check-label" for="edit_rec_no">No</label></div>
                            </div>
                        </div>
                    </div>
<div class="mb-3">
                        <label class="form-label">Feedback / Testimonial</label>
                        <textarea name="feedback" id="edit_feedback" class="form-control" rows="4"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Current Photo</label>
                        <div id="currentPhotoContainer"><p class="text-muted">No photo uploaded</p></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Upload New Photo</label>
                        <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp" onchange="previewPhoto(this, 'editPhotoPreview')">
                        <small class="text-muted">Leave empty to keep current photo</small>
                        <img id="editPhotoPreview" class="photo-preview" style="display: none;">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_featured" id="edit_featured" class="form-check-input">
                        <label for="edit_featured" class="form-check-label">Mark as Featured Testimonial</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bx bx-save me-1"></i> Update Survey</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var RATING_LABELS = {0: 'Select rating', 1: 'Poor', 2: 'Fair', 3: 'Good', 4: 'Very Good', 5: 'Excellent'};

/* ---------- Clickable 1-5 star rating ---------- */
function initStarRating(container) {
    var input = document.getElementById(container.dataset.input);
    var label = document.getElementById(container.dataset.label);
    var stars = container.querySelectorAll('.star');

    function paint(v) {
        stars.forEach(function (s) { s.classList.toggle('active', parseInt(s.dataset.value) <= v); });
        if (label) label.textContent = v ? (v + ' / 5 - ' + RATING_LABELS[v]) : RATING_LABELS[0];
    }

    stars.forEach(function (s) {
        s.addEventListener('mouseenter', function () { paint(parseInt(s.dataset.value)); });
        s.addEventListener('click', function () {
            input.value = s.dataset.value;
            paint(parseInt(input.value));
        });
    });
    container.addEventListener('mouseleave', function () { paint(parseInt(input.value) || 0); });

    container.setRating = function (v) { input.value = v; paint(v); };
    paint(parseInt(input.value) || 0);
}
document.querySelectorAll('.star-rating').forEach(initStarRating);

/* All criteria are required on a new survey. An older survey being edited may leave them empty. */
function validateSurveyForm(form, requireAll) {
    var missing = null;
    form.querySelectorAll('.star-rating').forEach(function (box) {
        var overall = box.dataset.name === 'Overall experience';
        if ((requireAll || overall) && !missing && !parseInt(document.getElementById(box.dataset.input).value)) {
            missing = box.dataset.name;
        }
    });
    if (missing) {
        Swal.fire({ icon: 'warning', title: 'Rating needed', text: 'Please rate ' + missing.toLowerCase() + ' from 1 to 5 stars.', confirmButtonColor: '#9370DB' });
        return false;
    }
    if (requireAll && !form.querySelector('input[name="would_recommend"]:checked')) {
        Swal.fire({ icon: 'warning', title: 'Answer needed', text: 'Please say whether the client would recommend us.', confirmButtonColor: '#9370DB' });
        return false;
    }
    return true;
}

/* ---------- Photo preview ---------- */
function previewPhoto(input, previewId) {
    var preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) { preview.src = e.target.result; preview.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.style.display = 'none';
    }
}

/* ---------- Copy client survey link ---------- */
function copySurveyLink(btn) {
    var link = btn.dataset.link;
    function done() {
        Swal.fire({ icon: 'success', title: 'Link copied!', html: 'Send this link to the client:<br><small style="word-break:break-all;">' + link + '</small>', confirmButtonColor: '#9370DB' });
    }
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(link).then(done);
    } else {
        var t = document.createElement('textarea');
        t.value = link; document.body.appendChild(t); t.select();
        document.execCommand('copy'); document.body.removeChild(t);
        done();
    }
}

/* ---------- Add survey ---------- */
function fillFromBooking(select) {
    var opt = select.options[select.selectedIndex];
    document.getElementById('add_client_name').value = opt.dataset.client || '';
    document.getElementById('add_event_name').value  = opt.dataset.event || '';
    document.getElementById('add_event_date').value  = opt.dataset.date || '';
}

function openAddSurvey(bookingId) {
    var select = document.getElementById('add_booking_id');
    if (select) {
        select.value = bookingId ? String(bookingId) : '';
        fillFromBooking(select);
    }
    document.querySelectorAll('#addSurveyForm .star-rating').forEach(function (box) { if (box.setRating) box.setRating(0); });

    new bootstrap.Modal(document.getElementById('addSurveyModal')).show();
}

/* ---------- Edit survey ---------- */
function editSurvey(survey) {
    document.getElementById('edit_survey_id').value = survey.survey_id;
    document.getElementById('edit_client_name').value = survey.client_name || '';
    document.getElementById('edit_event_name').value = survey.event_name || '';
    document.getElementById('edit_event_date').value = survey.event_date || '';
    document.getElementById('edit_feedback').value = survey.feedback || '';
    document.getElementById('edit_featured').checked = survey.is_featured == 1;

    var info = document.getElementById('edit_booking_info');
    if (survey.booking_id) {
        info.innerHTML = '<i class="bx bx-link me-1"></i> Linked to booking <strong>#' + survey.booking_id + '</strong>';
        info.style.display = 'block';
    } else {
        info.style.display = 'none';
    }

    document.getElementById('edit_stars').setRating(parseInt(survey.rating) || 5);
    Object.keys(survey.criteria || {}).forEach(function (col) {
        var box = document.querySelector('#editSurveyModal .star-rating[data-input="edit_' + col + '"]');
        if (box && box.setRating) box.setRating(parseInt(survey.criteria[col]) || 0);
    });
    document.getElementById('edit_rec_yes').checked = survey.would_recommend === 1 || survey.would_recommend === '1';
    document.getElementById('edit_rec_no').checked = survey.would_recommend === 0 || survey.would_recommend === '0';

    var photoContainer = document.getElementById('currentPhotoContainer');
    photoContainer.innerHTML = '';
    if (survey.photo_path) {
        var img = document.createElement('img');
        img.src = survey.photo_path;
        img.className = 'photo-preview';
        img.style.display = 'block';
        photoContainer.appendChild(img);
    } else {
        photoContainer.innerHTML = '<p class="text-muted">No photo uploaded</p>';
    }

    document.getElementById('editPhotoPreview').style.display = 'none';
    new bootstrap.Modal(document.getElementById('editSurveyModal')).show();
}

/* ---------- Delete survey ---------- */
function deleteSurvey(id, name) {
    var safe = String(name).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    Swal.fire({
        title: 'Delete Survey?',
        html: 'Are you sure you want to delete the survey from <strong>"' + safe + '"</strong>?<br><small class="text-muted">This action cannot be undone.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ff3e1d',
        cancelButtonColor: '#8592a3',
        confirmButtonText: '<i class="bx bx-trash me-1"></i> Yes, delete it!',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then(function (result) {
        if (result.isConfirmed) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML =
                '<input type="hidden" name="form_action" value="delete_survey">' +
                '<input type="hidden" name="survey_id" value="' + parseInt(id) + '">';
            document.body.appendChild(form);
            if (typeof showLoading === 'function') showLoading('Deleting survey...');
            form.submit();
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    <?php if ($flash = getFlash()): ?>
    Swal.fire({
        icon: <?php echo json_encode($flash['type'] === 'success' ? 'success' : ($flash['type'] === 'danger' ? 'error' : $flash['type'])); ?>,
        title: <?php echo json_encode($flash['type'] === 'success' ? 'Success!' : 'Notice'); ?>,
        text: <?php echo json_encode($flash['message']); ?>,
        confirmButtonColor: '#9370DB',
        timer: 3000,
        timerProgressBar: true
    });
    <?php endif; ?>
});
</script>

<?php include 'includes/footer.php'; ?>