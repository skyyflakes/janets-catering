<?php
/**
 * Database Configuration for Janet's Quality Catering System
 * PHP Database Connection using PDO
 */

// Database credentials. On your computer (XAMPP) the defaults below are used.
// When hosted (for example on Vercel), set the environment variables DB_HOST, DB_PORT, DB_NAME, DB_USER,
// DB_PASS and, if the provider requires an encrypted connection, DB_SSL=1.
$jq_env = static fn(string $k, string $default) => (($v = getenv($k)) !== false && $v !== '') ? $v : $default;
if (!defined('DB_HOST')) define('DB_HOST', $jq_env('DB_HOST', 'localhost'));
if (!defined('DB_PORT')) define('DB_PORT', $jq_env('DB_PORT', '3306'));
if (!defined('DB_NAME')) define('DB_NAME', $jq_env('DB_NAME', 'janets_catering'));  // Database name - must match SQL file
if (!defined('DB_USER')) define('DB_USER', $jq_env('DB_USER', 'root'));
if (!defined('DB_PASS')) define('DB_PASS', $jq_env('DB_PASS', ''));
if (!defined('DB_SSL'))  define('DB_SSL', $jq_env('DB_SSL', '0') === '1');
define('DB_CHARSET', 'utf8mb4');

/**
 * Create database connection using PDO
 * @return PDO|null
 */
function getDBConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (DB_SSL) {
            // Encrypted connection with the system's trusted certificates (needed by most online MySQL providers)
            foreach (['/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt'] as $ca) {
                if (is_file($ca)) { $options[PDO::MYSQL_ATTR_SSL_CA] = $ca; break; }
            }
        }
        
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        applyAuditContext($pdo);
        return $pdo;
        
    } catch (PDOException $e) {
        // Log error (in production, don't show details to users)
        error_log("Database Connection Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Tags the connection with the current user so the activity-log triggers can attribute every
 * INSERT / UPDATE / DELETE to the person who made it (see includes/activity_logger.php).
 * Failure here must never block the application, so errors are only logged.
 * @param PDO $pdo
 */
function applyAuditContext($pdo) {
    try {
        $active = session_status() === PHP_SESSION_ACTIVE;
        $isCli  = PHP_SAPI === 'cli';
        $userId = ($active && !empty($_SESSION['user_id'])) ? (int)$_SESSION['user_id'] : null;
        $user   = $active && isset($_SESSION['username']) ? (string)$_SESSION['username'] : ($isCli ? 'system (CLI)' : 'guest (public page)');
        $role   = $active && isset($_SESSION['role']) ? (string)$_SESSION['role'] : ($isCli ? 'SYSTEM' : null);
        $ip     = $isCli ? 'cli' : substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $agent  = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ($isCli ? 'command line' : '')), 0, 255);
        $page   = substr(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), 0, 100);

        $stmt = $pdo->prepare("SET @app_context = 1, @app_user_id = ?, @app_username = ?, @app_role = ?,
                                   @app_ip = ?, @app_ua = ?, @app_page = ?, @app_log_mgmt = 0, @app_log_reason = NULL");
        $stmt->execute([$userId, $user, $role, $ip, $agent, $page]);
    } catch (Throwable $e) {
        error_log('applyAuditContext failed: ' . $e->getMessage());
    }
}

/**
 * Generate UUID
 * @return string
 */
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Clean text input before it is stored.
 * Values are stored as typed and escaped only when displayed (htmlspecialchars / jq_e / escapeHtml);
 * SQL safety comes from prepared statements. Encoding here caused text like "Wedding &amp; Debut".
 * @param string $data
 * @return string
 */
function sanitize($data) {
    return trim((string)$data);
}

/**
 * Built-in accounts that also work when the database is unavailable (see login.php).
 * @return array
 */
function getFallbackAccounts() {
    return [
        ['user_id' => 1, 'username' => 'veranosean91@gmail.com', 'alias' => 'admin', 'role' => 'ADMIN',
         'hash' => '$2y$12$/8pUSzajroNO.JHpecyq7eI0Gzg/Xipse//VudAlYwJ502M6lI2Ty'],
        ['user_id' => 2, 'username' => 'naesleirgabonarev@gmail.com', 'alias' => 'owner', 'role' => 'OWNER',
         'hash' => '$2y$12$9usIB0vpZGnQneLjKg1.MuGi9zrNxJeZawUqeIUWiLRs7fkCfGBUq'],
    ];
}

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Redirect to page
 * @param string $url
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Set flash message
 * @param string $message
 * @param string $type
 */
function setFlash($message, $type = 'info') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

/**
 * Get and clear flash message
 * @return array|null
 */
function getFlash() {
    if (isset($_SESSION['flash_message'])) {
        $flash = [
            'message' => $_SESSION['flash_message'],
            'type' => $_SESSION['flash_type'] ?? 'info'
        ];
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return $flash;
    }
    return null;
}

/**
 * Generate random captcha
 * @return string
 */
function generateCaptcha() {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $captcha = '';
    for ($i = 0; $i < 5; $i++) {
        $captcha .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $captcha;
}
?>
