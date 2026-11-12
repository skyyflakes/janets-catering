<?php
/**
 * Database Configuration for Janet's Quality Catering System
 * PHP Database Connection using PDO
 *
 * Two databases are supported with the same code:
 *   - MySQL/MariaDB (XAMPP on this computer): the defaults below.
 *   - PostgreSQL (Supabase, when hosted on Vercel): set the environment variables DB_DRIVER=pgsql, DB_HOST, DB_PORT,
 *     DB_NAME, DB_USER and DB_PASS. DB_SSL=1 asks for an encrypted connection (Supabase requires it).
 * Where the two SQL dialects differ, pages use the small jq_sql_* helpers below.
 */

// The business runs on Philippine time. PHP's dates must agree with the database's NOW().
date_default_timezone_set('Asia/Manila');

$jq_env = static fn(string $k, string $default) => (($v = getenv($k)) !== false && $v !== '') ? $v : $default;
if (!defined('DB_DRIVER')) define('DB_DRIVER', $jq_env('DB_DRIVER', 'mysql') === 'pgsql' ? 'pgsql' : 'mysql');
if (!defined('DB_HOST')) define('DB_HOST', $jq_env('DB_HOST', 'localhost'));
if (!defined('DB_PORT')) define('DB_PORT', $jq_env('DB_PORT', DB_DRIVER === 'pgsql' ? '5432' : '3306'));
if (!defined('DB_NAME')) define('DB_NAME', $jq_env('DB_NAME', DB_DRIVER === 'pgsql' ? 'postgres' : 'janets_catering'));
if (!defined('DB_USER')) define('DB_USER', $jq_env('DB_USER', DB_DRIVER === 'pgsql' ? 'postgres' : 'root'));
if (!defined('DB_PASS')) define('DB_PASS', $jq_env('DB_PASS', ''));
if (!defined('DB_SSL'))  define('DB_SSL', $jq_env('DB_SSL', '0') === '1');
define('DB_CHARSET', 'utf8mb4');

/** True when the database is PostgreSQL (Supabase). */
function jq_is_pg(): bool
{
    return DB_DRIVER === 'pgsql';
}

/**
 * True when running hosted (Vercel + Supabase): files and sessions are kept in the database because the
 * server's disk is temporary.
 */
function jq_is_hosted(): bool
{
    return jq_is_pg();
}

/* ---------- SQL that differs between MySQL and PostgreSQL ---------- */

/** True when the error is a duplicate value in a unique column (MySQL 1062, PostgreSQL 23505). */
function jq_is_duplicate(Throwable $e): bool
{
    return $e instanceof PDOException && ((int)($e->errorInfo[1] ?? 0) === 1062 || ($e->errorInfo[0] ?? '') === '23505' || (string)$e->getCode() === '23505');
}

/**
 * ID of the row just inserted into $table. PostgreSQL needs the table: its plain "last value" would return the
 * ID of the Activity Log row that the audit trigger inserted right after.
 */
function jq_insert_id(PDO $pdo, string $table, string $column = 'id'): int
{
    if (!jq_is_pg()) {
        return (int)$pdo->lastInsertId();
    }
    $st = $pdo->prepare('SELECT currval(pg_get_serial_sequence(?, ?))');
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn();
}

/** Takes a named lock without waiting (MySQL GET_LOCK, PostgreSQL advisory lock). */
function jq_db_lock(PDO $pdo, string $name): bool
{
    $st = $pdo->prepare(jq_is_pg() ? 'SELECT pg_try_advisory_lock(hashtext(?))' : 'SELECT GET_LOCK(?, 0)');
    $st->execute([$name]);
    $v = $st->fetchColumn();
    return $v === true || $v === 't' || (int)$v === 1;
}

function jq_db_unlock(PDO $pdo, string $name): void
{
    $pdo->prepare(jq_is_pg() ? 'SELECT pg_advisory_unlock(hashtext(?))' : 'SELECT RELEASE_LOCK(?)')->execute([$name]);
}

/** 'YYYY-MM' of a date column. */
function jq_sql_ym(string $col): string
{
    return jq_is_pg() ? "to_char($col, 'YYYY-MM')" : "DATE_FORMAT($col, '%Y-%m')";
}

/** $expr plus a whole number of DAY / HOUR / MINUTE / SECOND / MONTH (n is an integer written into the SQL). */
function jq_sql_add(string $expr, int $n, string $unit): string
{
    $unit = strtoupper($unit);
    if (!in_array($unit, ['SECOND', 'MINUTE', 'HOUR', 'DAY', 'MONTH', 'YEAR'], true)) {
        throw new InvalidArgumentException('Bad interval unit');
    }
    return jq_is_pg() ? "($expr + INTERVAL '$n $unit')" : "DATE_ADD($expr, INTERVAL $n $unit)";
}

/**
 * Sets audit context values read by the activity-log triggers, e.g. ['log_mgmt' => 1, 'log_reason' => 'x'].
 * MySQL keeps them as @app_* session variables, PostgreSQL as app.* settings. A null value clears it.
 */
function jq_set_audit_vars(PDO $pdo, array $vars): void
{
    $parts = [];
    $args = [];
    foreach ($vars as $k => $v) {
        if (!preg_match('/^[a-z_]+$/', $k)) {
            continue;
        }
        if (jq_is_pg()) {
            $parts[] = "set_config('app.$k', ?, false)";
            $args[] = $v === null ? '' : (string)$v;
        } else {
            $parts[] = "@app_$k = ?";
            $args[] = $v;
        }
    }
    if ($parts) {
        $pdo->prepare((jq_is_pg() ? 'SELECT ' : 'SET ') . implode(', ', $parts))->execute($args);
    }
}

/**
 * Create database connection using PDO
 * @return PDO|null
 */
function getDBConnection() {
    // Hosted: one connection per page load. Each new encrypted connection to Supabase costs a network round trip,
    // and a page used to open several (session, page, files). The audit context follows the signed-in user.
    static $shared = null, $sharedUser = false;
    if ($shared !== null) {
        $uid = session_status() === PHP_SESSION_ACTIVE ? ($_SESSION['user_id'] ?? null) : null;
        if ($uid !== $sharedUser) {
            applyAuditContext($shared);
            $sharedUser = $uid;
        }
        return $shared;
    }
    try {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (jq_is_pg()) {
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";sslmode=" . (DB_SSL ? 'require' : 'prefer');
            // Supabase's connection pooler cannot keep server-side prepared statements between requests.
            $options[PDO::ATTR_EMULATE_PREPARES] = true;
        } else {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            if (DB_SSL) {
                // Encrypted connection with the system's trusted certificates (needed by most online MySQL providers)
                foreach (['/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt'] as $ca) {
                    if (is_file($ca)) { $options[PDO::MYSQL_ATTR_SSL_CA] = $ca; break; }
                }
            }
        }

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        applyAuditContext($pdo); // on PostgreSQL this also sets Philippine time
        if (jq_is_pg()) {
            $shared = $pdo;
            $sharedUser = session_status() === PHP_SESSION_ACTIVE ? ($_SESSION['user_id'] ?? null) : null;
        }
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

        if (jq_is_pg()) {
            // PostgreSQL keeps the same values as connection settings (app.*) that the audit triggers read.
            // Times are stored and compared as Philippine time, as on XAMPP.
            $stmt = $pdo->prepare("SELECT set_config('TimeZone', 'Asia/Manila', false), set_config('app.context', '1', false), set_config('app.user_id', ?, false), set_config('app.username', ?, false),
                                          set_config('app.role', ?, false), set_config('app.ip', ?, false), set_config('app.ua', ?, false),
                                          set_config('app.page', ?, false), set_config('app.log_mgmt', '0', false), set_config('app.log_reason', '', false)");
            $stmt->execute([(string)($userId ?? ''), $user, (string)($role ?? ''), $ip, $agent, $page]);
            return;
        }
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
         'hash' => 'pbkdf2_sha256$310000$x4aIq72GGaG7ELczuSYn7Q==$CfIjqQc5HVMXtFt9lYY/KXIhpEinqDG3HyU1zER+fWc='],
        ['user_id' => 2, 'username' => 'naesleirgabonarev@gmail.com', 'alias' => 'owner', 'role' => 'OWNER',
         'hash' => 'pbkdf2_sha256$310000$v+7Qx4gci1BnRI1GWP6ykQ==$zrhuF0IvNcatFButn492p11ei6dJZxyiWQukf6uycK4='],
    ];
}

/*
 * Passwords are saved as salted SHA-256 hashes (PBKDF2-HMAC-SHA256), never as the password itself:
 *   pbkdf2_sha256$<rounds>$<salt, base64>$<hash, base64>
 * People still type their normal password; only the database copy is hashed. Older bcrypt hashes ($2y$...)
 * are still accepted and are replaced with the SHA-256 form the next time that person signs in.
 */
const JQ_PASSWORD_ROUNDS = 310000;

function jq_password_hash(string $password): string
{
    $salt = random_bytes(16);
    $hash = hash_pbkdf2('sha256', $password, $salt, JQ_PASSWORD_ROUNDS, 32, true);
    return 'pbkdf2_sha256$' . JQ_PASSWORD_ROUNDS . '$' . base64_encode($salt) . '$' . base64_encode($hash);
}

function jq_password_verify(string $password, ?string $stored): bool
{
    if ($stored === null || $stored === '') {
        return false;
    }
    $parts = explode('$', $stored);
    if (count($parts) === 4 && $parts[0] === 'pbkdf2_sha256') {
        $rounds = (int)$parts[1];
        $salt = base64_decode($parts[2], true);
        $hash = base64_decode($parts[3], true);
        if ($rounds < 1 || $salt === false || $hash === false || $hash === '') {
            return false;
        }
        return hash_equals($hash, hash_pbkdf2('sha256', $password, $salt, $rounds, strlen($hash), true));
    }
    return password_verify($password, $stored); // older bcrypt hash
}

/** True when the saved hash is an older kind and should be replaced at the next sign-in. */
function jq_password_needs_upgrade(?string $stored): bool
{
    return !preg_match('/^pbkdf2_sha256\$' . JQ_PASSWORD_ROUNDS . '\$/', (string)$stored);
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

require_once __DIR__ . '/file_store.php';
