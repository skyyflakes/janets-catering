<?php
/**
 * Authentication Check - Include at top of protected pages
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setFlash('Please login to access this page.', 'warning');
    redirect('login.php');
}

// Keep the audit tables / triggers and schema migrations in sync (cheap, cached per session).
// Runs before the account check below because migrations verify pre-existing accounts.
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/backup_manager.php';
$pdo = getDBConnection();
if ($pdo) {
    jq_ensure_audit_schema($pdo);
}

// Sign out after 30 minutes without activity. Opening a page counts, and so does the browser's activity report
// (ajax/session_ping.php, sent while the person types, clicks or scrolls). Other background requests do not count.
const JQ_SESSION_IDLE_SECONDS = 1800;
$jq_now = time();
if (isset($_SESSION['jq_last_seen']) && $jq_now - (int)$_SESSION['jq_last_seen'] > JQ_SESSION_IDLE_SECONDS) {
    session_unset();
    session_regenerate_id(true);
    setFlash('You were signed out after 30 minutes of inactivity. Please sign in again.', 'info');
    redirect('login.php');
}
if (!isset($_SESSION['jq_last_seen']) || !jq_is_ajax()) {
    $_SESSION['jq_last_seen'] = $jq_now;
}

// Get current user info with fresh profile photo from database
$current_user = [
    'id' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['username'] ?? 'Guest',
    'role' => $_SESSION['role'] ?? 'ADMIN',
    'profile_photo' => null,
    'first_name' => '',
    'last_name' => ''
];

// Fetch fresh user data including profile photo. The database decides role and access on every
// request: a deleted, deactivated or unverified account loses its session immediately.
if ($pdo && isset($_SESSION['user_id'])) {
    $access_denied = null;
    try {
        $stmt = $pdo->prepare("SELECT first_name, last_name, profile_photo, role, status, is_verified, is_archived FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user_info) {
            $access_denied = 'Your account is no longer available.';
        } elseif (!empty($user_info['is_archived'])) {
            $access_denied = 'This account has been archived and can no longer sign in.';
        } elseif (($user_info['status'] ?? 'active') !== 'active') {
            $access_denied = 'Your account has been deactivated. Please contact the Owner.';
        } elseif (empty($user_info['is_verified'])) {
            $access_denied = 'Please verify your email address before signing in.';
        } else {
            $current_user['profile_photo'] = $user_info['profile_photo'];
            $current_user['first_name'] = $user_info['first_name'];
            $current_user['last_name'] = $user_info['last_name'];
            $current_user['role'] = $user_info['role'];
            $_SESSION['role'] = $user_info['role'];
        }
    } catch (Exception $e) {
        // Silent fail
    }
    if ($access_denied !== null) {
        session_unset();
        session_regenerate_id(true);
        setFlash($access_denied, 'warning');
        redirect('login.php');
    }
}

// A Supervisor has one module. Every other page and endpoint is refused here, whatever the URL.
// The role comes from the database check above, so an old session cannot keep wider access.
if (($current_user['role'] ?? '') === 'SUPERVISOR') {
    $jq_script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!in_array($jq_script, ['supervisor_dashboard.php', 'logout.php', 'session_ping.php'], true)) {
        if ($jq_script !== 'dashboard.php') {
            logActivity($pdo, 'ACCESS_DENIED', 'Access Control', 'Blocked Supervisor access to ' . $jq_script);
        }
        if (jq_is_ajax() || isset($_POST['ajax_action']) || basename(dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))) === 'ajax') {
            jq_json_response(['success' => false, 'error' => 'You are not authorized to perform this action.'], 403);
        }
        redirect('supervisor_dashboard.php');
    }
}

// Every POST from a signed-in page must carry this session's security token. The token is added to
// forms and same-site requests by the script in includes/header.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jq_is_json = jq_is_ajax() || isset($_POST['ajax_action']);
    $jq_post_limit = (int)ini_get('post_max_size') * 1048576;
    if ($jq_post_limit > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $jq_post_limit) {
        // PHP discards the whole request when it is this large, so there is no token to check.
        if ($jq_is_json) {
            jq_json_response(['success' => false, 'error' => 'The upload is too large.'], 413);
        }
        setFlash('The upload is larger than the server allows (' . ini_get('post_max_size') . '). Nothing was saved.', 'danger');
        redirect(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php')));
    }
    if (!jq_csrf_valid($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
        if ($jq_is_json) {
            jq_json_response(['success' => false, 'error' => 'Your session security token expired. Please reload the page.'], 403);
        }
        setFlash('Your session security token expired. Please try again.', 'warning');
        redirect(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php')));
    }
}

// Block pages while a database restore is running, and run a due scheduled backup after this
// page has been delivered (fallback for Task Scheduler / cron).
jq_maintenance_guard();
jq_backup_register_scheduler($pdo);
?>
