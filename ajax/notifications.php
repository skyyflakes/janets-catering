<?php
/**
 * Notifications AJAX Handler (bell menu)
 * Same rules for OWNER and ADMIN: each user sees and changes only their own notifications.
 * Notifications themselves are created by the activity-log trigger (includes/activity_logger.php).
 */

require_once __DIR__ . '/../config/session.php';
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once '../config/database.php';
require_once '../includes/activity_logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !jq_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your session security token expired. Please reload the page.']);
    exit;
}

$pdo = getDBConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if (jq_verified_role($pdo) === 'SUPERVISOR') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';

try {
    switch ($action) {
        case 'get_notifications':
            $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
            $stmt = $pdo->prepare("
                SELECT n.id, n.type, n.title, n.message, n.reference_id, n.reference_type, n.is_read, n.actor_username, n.created_at
                  FROM notifications n
                 WHERE n.user_id = ?
                 ORDER BY n.created_at DESC, n.id DESC
                 LIMIT $limit
            ");
            $stmt->execute([$user_id]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // "5 min ago" is worked out here, the same way on MySQL and PostgreSQL
            foreach ($notifications as &$n) {
                $mins = intdiv(max(0, time() - strtotime((string)$n['created_at'])), 60);
                $n['time_ago'] = $mins < 60 ? $mins . ' min ago' : ($mins < 1440 ? intdiv($mins, 60) . ' hours ago'
                    : ($mins < 10080 ? intdiv($mins, 1440) . ' days ago' : date('F d, Y', strtotime((string)$n['created_at']))));
            }
            unset($n);
            echo json_encode(['success' => true, 'notifications' => $notifications, 'count' => count($notifications)]);
            break;

        case 'get_unread_count':
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true, 'count' => (int)$stmt->fetchColumn()]);
            break;

        case 'mark_as_read':
            $notification_id = (int)($_POST['notification_id'] ?? 0);
            if (!$is_post || !$notification_id) {
                echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
                break;
            }
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$notification_id, $user_id]);
            echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
            break;

        case 'mark_all_read':
            if (!$is_post) {
                echo json_encode(['success' => false, 'error' => 'Invalid request']);
                break;
            }
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('ajax/notifications.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The request could not be completed.']);
}
