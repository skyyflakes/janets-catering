<?php
/**
 * Live duplicate check used by the account forms. The server re-checks on submit;
 * this only gives the user an early, clear message.
 * username: ADMIN and OWNER (account creation). phone / email: any signed-in user (own profile excluded).
 */
require_once __DIR__ . '/../config/session.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Not authorized.']);
    exit;
}

require_once __DIR__ . '/../includes/account_security.php';

if (!jq_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Your session security token expired. Please reload the page.']);
    exit;
}

$pdo = getDBConnection();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Database unavailable.']);
    exit;
}

$field = (string)($_POST['field'] ?? '');
$value = trim((string)($_POST['value'] ?? ''));
$role  = jq_verified_role($pdo);
if (!in_array($role, ['ADMIN', 'OWNER'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Not authorized.']);
    exit;
}
// On the profile form the user's own record does not count as a duplicate.
$exclude = ($_POST['scope'] ?? '') === 'profile' ? (int)$_SESSION['user_id'] : 0;

$respond = static function (bool $ok, string $message = '', ?string $normalized = null) {
    echo json_encode(['ok' => $ok, 'message' => $message, 'normalized' => $normalized]);
    exit;
};

switch ($field) {
    case 'username':
        if (!in_array($role, ['ADMIN', 'OWNER'], true)) {
            http_response_code(403);
            $respond(false, 'Not authorized.');
        }
        if ($value === '') {
            $respond(true);
        }
        $respond(!jq_username_taken($pdo, $value), jq_username_taken($pdo, $value) ? 'This username already exists. Please choose another one.' : '');
        // no break: $respond exits

    case 'phone':
        $phone = jq_normalize_phone($value);
        if ($phone === false) {
            $respond(false, JQ_PHONE_MESSAGE);
        }
        if ($phone === null) {
            $respond(true);
        }
        $taken = jq_phone_taken($pdo, $phone, $exclude);
        $respond(!$taken, $taken ? 'This phone number is already registered to another account.' : '');
        // no break: $respond exits

    case 'email':
        if ($value === '') {
            $respond(true);
        }
        if (($email_error = jq_email_error($value)) !== null) {
            $respond(false, $email_error);
        }
        $taken = jq_email_taken($pdo, $value, $exclude);
        $respond(!$taken, $taken ? 'This email address is already registered to another account.' : '');
        // no break: $respond exits

    default:
        http_response_code(400);
        $respond(false, 'Unknown field.');
}
