<?php
/**
 * ACTIVITY LOG & AUDIT TRAIL CORE
 * Janet's Quality Catering System
 *
 * Responsibilities
 *  - logActivity(): explicit, application-level events (login, logout, backups, access denied …)
 *  - jq_ensure_audit_schema(): installs / upgrades the audit tables and generates the database
 *    triggers that automatically record every CREATE / UPDATE / ARCHIVE / RESTORE / DELETE made
 *    on the business tables, including previous and updated values.
 *  - Role verification, CSRF protection and small shared helpers for the new modules.
 *
 * How user attribution works
 *  config/database.php -> getDBConnection() sets MySQL session variables on every connection
 *  (@app_user_id, @app_username, @app_role, @app_ip, @app_ua, @app_page). The triggers read them,
 *  so changes made from ANY module are attributed to the logged-in user. Changes made outside the
 *  application (e.g. phpMyAdmin) are still recorded and flagged with source = 'direct_db'.
 *
 * Tamper protection (enforced by the database, not only by PHP)
 *  - activity_logs rows can never be deleted.
 *  - activity_logs rows are write-once. Nobody can edit them; the only change the database accepts
 *    is the OWNER flipping the archive flag through the Activity Log module.
 *  - Every archive / restore of a log row is copied into the append-only table
 *    activity_log_audit, which can be neither updated nor deleted.
 *
 * Notifications
 *  The AFTER INSERT trigger on activity_logs fans each important event out to every active, verified
 *  OWNER and ADMIN (table notifications). UNIQUE(user_id, activity_log_id) makes duplicates impossible.
 */

if (defined('JQ_ACTIVITY_LOGGER_LOADED')) {
    return;
}
define('JQ_ACTIVITY_LOGGER_LOADED', true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/schema_v25.php';
require_once __DIR__ . '/schema_v26.php';
require_once __DIR__ . '/schema_v27.php';
require_once __DIR__ . '/schema_v28.php';
require_once __DIR__ . '/schema_v29.php';

/** Bump when the trigger / table definitions below change: forces a re-sync. */
const JQ_AUDIT_SCHEMA_VERSION = '2.9.0';

/** Business tables whose changes are captured automatically. */
function jq_audited_tables(): array
{
    return [
        'event_bookings'        => ['module' => 'Event Bookings',  'entity' => 'event booking',       'label' => ['booking_ref', 'event_name']],
        'users'                 => ['module' => 'Accounts',        'entity' => 'user account',        'label' => ['username'], 'sensitive' => ['password', 'email']],
        'menu_packages'         => ['module' => 'Food Menus',      'entity' => 'menu package',        'label' => ['package_name']],
        'menu_items'            => ['module' => 'Food Menus',      'entity' => 'menu item',           'label' => ['item_name']],
        'menu_bundles'          => ['module' => 'Food Menus',      'entity' => 'menu bundle',         'label' => ['bundle_name']],
        'foodstocks'            => ['module' => 'Food Stocks',     'entity' => 'food stock item',     'label' => ['item_name']],
        'foodstocks_categories' => ['module' => 'Food Stocks',     'entity' => 'food stock category', 'label' => ['category_name']],
        'client_surveys'        => ['module' => 'Client Surveys',  'entity' => 'client survey',       'label' => ['client_name', 'event_name']],
        'reports'               => ['module' => 'Reports',         'entity' => 'report',              'label' => ['title']],
        'backdrops'             => ['module' => 'Backdrops',       'entity' => 'backdrop',            'label' => ['backdrop_name']],
        'clients'               => ['module' => 'Client Records',  'entity' => 'client record',       'label' => ['client_code', 'fullname']],
        'event_types'           => ['module' => 'Event Bookings',  'entity' => 'event type',          'label' => ['event_type_name']],
        'booking_payments'      => ['module' => 'Event Bookings',  'entity' => 'booking payment',     'label' => ['payment_type', 'amount']],
        'booking_attachments'   => ['module' => 'Event Bookings',  'entity' => 'booking attachment',  'label' => ['document_type', 'original_name']],
    ];
}

/** Columns ignored when deciding whether an UPDATE changed anything meaningful. */
function jq_audit_ignored_columns(): array
{
    return ['updated_at'];
}

/** Static triggers that protect the audit tables themselves. */
function jq_static_trigger_names(): array
{
    return [
        'trg_jq_activity_logs_bi', 'trg_jq_activity_logs_ai', 'trg_jq_activity_logs_bu', 'trg_jq_activity_logs_bd',
        'trg_jq_log_audit_bu', 'trg_jq_log_audit_bd',
        'trg_jq_backup_history_bu', 'trg_jq_backup_history_bd',
    ];
}

/* =====================================================================
 *  GENERAL HELPERS
 * ===================================================================== */

function jq_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('Asia/Manila');
}

function jq_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', jq_tz());
}

function jq_client_ip(): string
{
    if (PHP_SAPI === 'cli') {
        return 'cli';
    }
    // REMOTE_ADDR only – forwarded headers are client-controlled and can be spoofed.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function jq_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Values stored by legacy modules are already HTML-escaped (sanitize()); decode once for display. */
function jq_plain($value): string
{
    return html_entity_decode((string)($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function jq_is_ajax(): bool
{
    return (isset($_GET['ajax']) || isset($_POST['ajax']))
        || (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
}

function jq_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function jq_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function jq_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([$table, $column]);
            $cache[$key] = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

/* =====================================================================
 *  CSRF PROTECTION (used by the new modules)
 * ===================================================================== */

function jq_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['jq_csrf'])) {
        $_SESSION['jq_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['jq_csrf'];
}

function jq_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . jq_e(jq_csrf_token()) . '">';
}

function jq_csrf_valid(?string $token): bool
{
    return is_string($token) && $token !== '' && !empty($_SESSION['jq_csrf']) && hash_equals($_SESSION['jq_csrf'], $token);
}

/* =====================================================================
 *  ROLE-BASED ACCESS CONTROL
 * ===================================================================== */

/**
 * Returns the user's role as stored in the database (falls back to the session when the DB is
 * unavailable). Re-reading it prevents a stale session role from granting access after an OWNER
 * changes or deactivates an account.
 */
function jq_verified_role(?PDO $pdo): string
{
    static $role = null;
    if ($role !== null) {
        return $role;
    }
    $role = (string)($_SESSION['role'] ?? '');
    if ($pdo && !empty($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT role, status, is_archived FROM users WHERE id = ?");
            $stmt->execute([(int)$_SESSION['user_id']]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $role = ($row['status'] ?? 'active') === 'active' && empty($row['is_archived']) ? (string)$row['role'] : '';
            }
        } catch (Throwable $e) {
            // keep session role
        }
    }
    return $role;
}

/**
 * Server-side guard. Stops the request (redirect or JSON 403) when the verified role is not allowed.
 * The denial itself is recorded in the activity log.
 */
function jq_require_roles(?PDO $pdo, array $allowed, string $moduleName): string
{
    $role = jq_verified_role($pdo);
    if (in_array($role, $allowed, true)) {
        return $role;
    }
    logActivity($pdo, 'ACCESS_DENIED', $moduleName,
        'Blocked access to ' . $moduleName . ' (' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ') for role ' . ($role ?: 'unknown'));
    if (jq_is_ajax()) {
        jq_json_response(['success' => false, 'error' => 'You are not authorized to perform this action.'], 403);
    }
    setFlash('You do not have permission to access ' . $moduleName . '.', 'danger');
    redirect('dashboard.php');
    return ''; // unreachable
}

/** Identity of the person performing an action, for history tables. */
function jq_actor(?PDO $pdo = null): array
{
    return [
        'id'       => !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        'username' => (string)($_SESSION['username'] ?? (PHP_SAPI === 'cli' ? 'system (CLI)' : 'system')),
        'role'     => $pdo ? jq_verified_role($pdo) : (string)($_SESSION['role'] ?? (PHP_SAPI === 'cli' ? 'SYSTEM' : '')),
    ];
}

/* =====================================================================
 *  EXPLICIT ACTIVITY LOGGING
 * ===================================================================== */

/**
 * Records an application event. Never throws – logging must not break the business operation.
 *
 * @param array $opts record_table, record_id, record_label, old_values (array), new_values (array),
 *                    user_id, username, role (override the session user, e.g. failed logins), source
 */
function logActivity(?PDO $pdo, string $action, string $module, string $description, array $opts = []): bool
{
    try {
        $pdo = $pdo ?: getDBConnection();
        if (!$pdo) {
            return false;
        }
        $sessionUser = session_status() === PHP_SESSION_ACTIVE ? ($_SESSION['user_id'] ?? null) : null;
        $userId   = array_key_exists('user_id', $opts) ? $opts['user_id'] : $sessionUser;
        $username = $opts['username'] ?? ($_SESSION['username'] ?? (PHP_SAPI === 'cli' ? 'system (CLI)' : 'guest'));
        $role     = $opts['role'] ?? ($_SESSION['role'] ?? (PHP_SAPI === 'cli' ? 'SYSTEM' : null));
        $encode   = static function ($v) {
            return $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        };

        $stmt = $pdo->prepare("
            INSERT INTO activity_logs
                (user_id, username, user_role, action, module, record_table, record_id, record_label,
                 description, old_values, new_values, ip_address, user_agent, page, source)
            VALUES
                ((SELECT u.id FROM users u WHERE u.id = ? LIMIT 1), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $userId !== null ? (int)$userId : null,
            mb_substr((string)$username, 0, 100),
            $role !== null ? mb_substr((string)$role, 0, 20) : null,
            mb_substr(strtoupper($action), 0, 100),
            mb_substr($module, 0, 100),
            isset($opts['record_table']) ? mb_substr((string)$opts['record_table'], 0, 64) : null,
            isset($opts['record_id']) ? mb_substr((string)$opts['record_id'], 0, 64) : null,
            isset($opts['record_label']) ? mb_substr((string)$opts['record_label'], 0, 255) : null,
            $description,
            $encode($opts['old_values'] ?? null),
            $encode($opts['new_values'] ?? null),
            jq_client_ip(),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? (PHP_SAPI === 'cli' ? 'command line' : '')), 0, 255),
            mb_substr(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), 0, 100),
            $opts['source'] ?? 'system',
        ]);
    } catch (Throwable $e) {
        error_log('logActivity failed: ' . $e->getMessage());
        return false;
    }
}

/* =====================================================================
 *  SCHEMA INSTALLER / UPGRADER
 * ===================================================================== */

function jq_setting_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

function jq_setting_set(PDO $pdo, string $key, ?string $value, ?int $userId = null): void
{
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
    ");
    $stmt->execute([$key, $value, $userId]);
}

/** DDL for the tables owned by the Activity Log and Backup modules. */
function jq_schema_table_ddl(): array
{
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        'system_settings' => "CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_key`   VARCHAR(100) NOT NULL PRIMARY KEY,
            `setting_value` TEXT NULL,
            `updated_by`    INT NULL,
            `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $opts",

        'activity_logs' => "CREATE TABLE IF NOT EXISTS `activity_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NULL,
            `action` VARCHAR(100) NOT NULL,
            `description` TEXT NULL,
            `ip_address` VARCHAR(45) NULL,
            `created_at` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            KEY `idx_user` (`user_id`),
            KEY `idx_created` (`created_at`),
            CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) $opts",

        'activity_log_audit' => "CREATE TABLE IF NOT EXISTS `activity_log_audit` (
            `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `log_id`                INT NOT NULL,
            `operation`             VARCHAR(20) NOT NULL,
            `old_snapshot`          LONGTEXT NULL,
            `new_snapshot`          LONGTEXT NULL,
            `reason`                VARCHAR(500) NULL,
            `performed_by`          INT NULL,
            `performed_by_username` VARCHAR(100) NULL,
            `performed_by_role`     VARCHAR(20) NULL,
            `ip_address`            VARCHAR(45) NULL,
            `created_at`            TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            KEY `idx_ala_log` (`log_id`),
            KEY `idx_ala_created` (`created_at`),
            KEY `idx_ala_operation` (`operation`),
            CONSTRAINT `fk_ala_log` FOREIGN KEY (`log_id`) REFERENCES `activity_logs` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) $opts",

        'database_backups' => "CREATE TABLE IF NOT EXISTS `database_backups` (
            `id`                  INT AUTO_INCREMENT PRIMARY KEY,
            `filename`            VARCHAR(255) NOT NULL,
            `backup_type`         VARCHAR(20) NOT NULL DEFAULT 'manual',
            `file_size`           BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `checksum_sha256`     CHAR(64) NULL,
            `table_count`         INT NOT NULL DEFAULT 0,
            `row_count`           BIGINT NOT NULL DEFAULT 0,
            `db_name`             VARCHAR(64) NULL,
            `server_version`      VARCHAR(100) NULL,
            `secondary_copy`      TINYINT(1) NOT NULL DEFAULT 0,
            `secondary_path`      VARCHAR(500) NULL,
            `status`              VARCHAR(20) NOT NULL DEFAULT 'available',
            `verify_status`       VARCHAR(20) NULL,
            `last_verified_at`    DATETIME NULL,
            `notes`               VARCHAR(500) NULL,
            `created_by`          INT NULL,
            `created_by_username` VARCHAR(100) NULL,
            `created_at`          TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at`          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_backup_filename` (`filename`),
            KEY `idx_backup_type` (`backup_type`),
            KEY `idx_backup_created` (`created_at`)
        ) $opts",

        'backup_history' => "CREATE TABLE IF NOT EXISTS `backup_history` (
            `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `backup_id`             INT NULL,
            `filename`              VARCHAR(255) NULL,
            `action`                VARCHAR(30) NOT NULL,
            `status`                VARCHAR(20) NOT NULL DEFAULT 'success',
            `details`               TEXT NULL,
            `duration_ms`           INT NULL,
            `performed_by`          INT NULL,
            `performed_by_username` VARCHAR(100) NULL,
            `performed_by_role`     VARCHAR(20) NULL,
            `ip_address`            VARCHAR(45) NULL,
            `created_at`            TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            KEY `idx_bh_backup` (`backup_id`),
            KEY `idx_bh_created` (`created_at`),
            KEY `idx_bh_action` (`action`),
            CONSTRAINT `fk_bh_backup` FOREIGN KEY (`backup_id`) REFERENCES `database_backups` (`id`) ON DELETE SET NULL
        ) $opts",
    ];
}

/** Columns added to the original activity_logs table (it already existed but was unused). */
function jq_activity_log_extra_columns(): array
{
    return [
        'username'     => "VARCHAR(100) NULL AFTER `user_id`",
        'user_role'    => "VARCHAR(20) NULL AFTER `username`",
        'module'       => "VARCHAR(100) NULL AFTER `action`",
        'record_table' => "VARCHAR(64) NULL AFTER `module`",
        'record_id'    => "VARCHAR(64) NULL AFTER `record_table`",
        'record_label' => "VARCHAR(255) NULL AFTER `record_id`",
        'old_values'   => "LONGTEXT NULL AFTER `description`",
        'new_values'   => "LONGTEXT NULL AFTER `old_values`",
        'user_agent'   => "VARCHAR(255) NULL AFTER `ip_address`",
        'page'         => "VARCHAR(100) NULL AFTER `user_agent`",
        'source'       => "VARCHAR(20) NOT NULL DEFAULT 'system' AFTER `page`",
        'notes'        => "TEXT NULL AFTER `source`",
        'is_archived'  => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`",
        'archived_at'  => "DATETIME(3) NULL AFTER `is_archived`",
        'archived_by'  => "INT NULL AFTER `archived_at`",
        'restored_at'  => "DATETIME(3) NULL AFTER `archived_by`",
        'restored_by'  => "INT NULL AFTER `restored_at`",
        'edited_at'    => "DATETIME(3) NULL AFTER `restored_by`",
        'edited_by'    => "INT NULL AFTER `edited_at`",
        'edit_count'   => "INT NOT NULL DEFAULT 0 AFTER `edited_by`",
        'updated_at'   => "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
    ];
}

/**
 * Makes sure tables, columns and triggers exist and match the current schema.
 * Cheap: runs fully at most once every 10 minutes per session (or when $force = true).
 *
 * @return array{ok: bool, messages: string[], rebuilt: bool}
 */
function jq_ensure_audit_schema(?PDO $pdo, bool $force = false): array
{
    static $ran = false;
    $result = ['ok' => true, 'messages' => [], 'rebuilt' => false];
    if (!$pdo) {
        return ['ok' => false, 'messages' => ['No database connection'], 'rebuilt' => false];
    }
    if ($ran && !$force) {
        return $result;
    }
    $sessionKey = 'jq_schema_checked_' . JQ_AUDIT_SCHEMA_VERSION;
    if (!$force && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] > time() - 600) {
        $ran = true;
        return $result;
    }
    $ran = true;

    $step = static function (string $label, callable $fn) use (&$result) {
        try {
            $fn();
        } catch (Throwable $e) {
            $result['ok'] = false;
            $result['messages'][] = $label . ': ' . $e->getMessage();
            error_log('[audit schema] ' . $label . ': ' . $e->getMessage());
        }
    };

    // 1) Tables
    foreach (jq_schema_table_ddl() as $table => $ddl) {
        $step("create table $table", static function () use ($pdo, $ddl) { $pdo->exec($ddl); });
    }

    // 2) Upgrade the pre-existing activity_logs table
    $step('upgrade activity_logs', static function () use ($pdo) {
        $existing = $pdo->query("SHOW COLUMNS FROM `activity_logs`")->fetchAll(PDO::FETCH_COLUMN);
        $add = [];
        foreach (jq_activity_log_extra_columns() as $col => $def) {
            if (!in_array($col, $existing, true)) {
                $add[] = "ADD COLUMN `$col` $def";
            }
        }
        // millisecond precision for "exact time"
        $type = $pdo->query("SHOW COLUMNS FROM `activity_logs` LIKE 'created_at'")->fetch(PDO::FETCH_ASSOC);
        if ($type && stripos((string)$type['Type'], 'timestamp(3)') === false) {
            $add[] = "MODIFY `created_at` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)";
        }
        if ($add) {
            $pdo->exec("ALTER TABLE `activity_logs` " . implode(', ', $add));
        }
        $indexes = $pdo->query("SHOW INDEX FROM `activity_logs`")->fetchAll(PDO::FETCH_COLUMN, 2);
        $wanted = [
            'idx_al_action'   => '(`action`)',
            'idx_al_module'   => '(`module`)',
            'idx_al_record'   => '(`record_table`, `record_id`)',
            'idx_al_archived' => '(`is_archived`, `created_at`)',
            'idx_al_username' => '(`username`)',
        ];
        foreach ($wanted as $name => $cols) {
            if (!in_array($name, $indexes, true)) {
                $pdo->exec("ALTER TABLE `activity_logs` ADD INDEX `$name` $cols");
            }
        }
    });

    // 3) Track who archived / restored event bookings
    $step('upgrade event_bookings', static function () use ($pdo) {
        if (!jq_table_exists($pdo, 'event_bookings')) {
            return;
        }
        $existing = $pdo->query("SHOW COLUMNS FROM `event_bookings`")->fetchAll(PDO::FETCH_COLUMN);
        $add = [];
        if (!in_array('is_archived', $existing, true)) $add[] = "ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0";
        if (!in_array('archived_at', $existing, true)) $add[] = "ADD COLUMN `archived_at` DATETIME NULL";
        if (!in_array('archived_by', $existing, true)) $add[] = "ADD COLUMN `archived_by` INT NULL";
        if (!in_array('restored_at', $existing, true)) $add[] = "ADD COLUMN `restored_at` DATETIME NULL";
        if (!in_array('restored_by', $existing, true)) $add[] = "ADD COLUMN `restored_by` INT NULL";
        if ($add) {
            $pdo->exec("ALTER TABLE `event_bookings` " . implode(', ', $add));
        }
    });

    // 3b) Roles, SMS removal, email tokens, notification columns, entity clean-up (idempotent)
    jq_run_schema_migrations($pdo, $result, $step);

    // 4) Triggers – rebuilt when the version, a table's columns, or a trigger is missing/changed
    $step('audit triggers', static function () use ($pdo, $force, &$result) {
        $signature = jq_audit_signature($pdo);
        $stored    = jq_setting_get($pdo, 'audit_trigger_signature');
        $expected  = jq_expected_trigger_names($pdo);
        $present   = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_jq\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        $missing   = array_diff($expected, $present);
        if ($force || $stored !== $signature || $missing) {
            jq_install_triggers($pdo);
            jq_setting_set($pdo, 'audit_trigger_signature', $signature);
            jq_setting_set($pdo, 'audit_schema_version', JQ_AUDIT_SCHEMA_VERSION);
            $result['rebuilt'] = true;
            $result['messages'][] = 'Audit triggers synchronized (' . count($expected) . ' triggers).';
        }
    });

    if ($result['ok'] && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[$sessionKey] = time();
    }
    return $result;
}

/**
 * Idempotent data/schema migrations. They also run after a restore, so restoring a backup taken
 * before these changes (STAFF role, SMS tables, HTML-encoded text) is brought up to date automatically.
 */
function jq_run_schema_migrations(PDO $pdo, array &$result, callable $step): void
{
    $step('migrate legacy accounts', static function () use ($pdo, &$result) {
        if (!jq_table_exists($pdo, 'users')) {
            return;
        }
        $cols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('phone_verified', $cols, true)) {
            return;
        }
        // "phone_verified" marks a pre-migration database. Its triggers reference columns about to
        // change and must not log this maintenance, so they are removed and rebuilt in step 4.
        $triggers = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_jq\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($triggers as $t) {
            if (!in_array($t, jq_static_trigger_names(), true)) {
                $pdo->exec("DROP TRIGGER IF EXISTS `$t`");
            }
        }
        // Accounts that already exist keep working; verification applies to accounts created from now on.
        $pdo->exec("UPDATE `users` SET `is_verified` = 1");
        $pdo->exec("ALTER TABLE `users` DROP COLUMN `phone_verified`");
        $changed = jq_decode_stored_entities($pdo);
        $st = $pdo->query("SELECT id, contact_number FROM users");
        $upd = $pdo->prepare("UPDATE users SET contact_number = ? WHERE id = ?");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $raw = trim((string)$u['contact_number']);
            $digits = preg_replace('/[\s\-().]/', '', $raw);
            $new = $digits === '' ? null : (preg_match('/^(?:\+?63|0)(9\d{9})$/', $digits, $m) ? '0' . $m[1] : $raw);
            if ($new !== $u['contact_number']) {
                $upd->execute([$new, $u['id']]);
            }
        }
        $result['messages'][] = "Legacy data migrated ($changed text values decoded).";
    });

    $step('remove STAFF role', static function () use ($pdo) {
        $type = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if ($type && stripos((string)$type['Type'], 'STAFF') !== false) {
            $pdo->exec("UPDATE `users` SET `role` = 'ADMIN' WHERE `role` = 'STAFF'");
            $pdo->exec("ALTER TABLE `users` MODIFY `role` ENUM('ADMIN','OWNER') NOT NULL DEFAULT 'ADMIN'");
        }
        // Without strict SQL mode an invalid ENUM value is silently stored as ''. Refuse it instead.
        $ddl = $pdo->query("SHOW CREATE TABLE `users`")->fetch(PDO::FETCH_NUM)[1] ?? '';
        if (strpos($ddl, 'chk_users_role') === false) {
            $pdo->exec("ALTER TABLE `users` ADD CONSTRAINT `chk_users_role` CHECK (`role` IN ('ADMIN','OWNER'))");
        }
    });

    $step('remove SMS verification', static function () use ($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `sms_verifications`");
    });

    $step('unique phone numbers', static function () use ($pdo) {
        $names = $pdo->query("SHOW INDEX FROM `users`")->fetchAll(PDO::FETCH_COLUMN, 2);
        if (!in_array('uq_users_contact', $names, true)) {
            $pdo->exec("ALTER TABLE `users` ADD UNIQUE KEY `uq_users_contact` (`contact_number`)");
        }
    });

    $step('unique emails', static function () use ($pdo) {
        $names = $pdo->query("SHOW INDEX FROM `users`")->fetchAll(PDO::FETCH_COLUMN, 2);
        if (!in_array('uq_users_email', $names, true)) {
            $pdo->exec("UPDATE `users` SET `email` = NULL WHERE TRIM(`email`) = ''");
            $pdo->exec("ALTER TABLE `users` ADD UNIQUE KEY `uq_users_email` (`email`)");
        }
    });

    $step('email verification codes', static function () use ($pdo) {
        // Earlier versions stored link tokens here; a pending account simply requests a new code.
        if (jq_table_exists($pdo, 'email_verifications') && !jq_column_exists_fresh($pdo, 'email_verifications', 'code_hash')) {
            $pdo->exec("DROP TABLE `email_verifications`");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS `email_verifications` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `user_id`    INT NOT NULL,
            `email`      VARCHAR(100) NOT NULL,
            `code_hash`  VARCHAR(255) NOT NULL,
            `attempts`   INT NOT NULL DEFAULT 0,
            `expires_at` DATETIME NOT NULL,
            `used_at`    DATETIME NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_ev_user` (`user_id`, `used_at`),
            CONSTRAINT `fk_ev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    });

    $step('notification history columns', static function () use ($pdo) {
        if (!jq_table_exists($pdo, 'notifications') || jq_column_exists_fresh($pdo, 'notifications', 'activity_log_id')) {
            return;
        }
        $pdo->exec("ALTER TABLE `notifications`
            ADD COLUMN `actor_user_id` INT NULL AFTER `reference_type`,
            ADD COLUMN `actor_username` VARCHAR(100) NULL AFTER `actor_user_id`,
            ADD COLUMN `activity_log_id` INT NULL AFTER `actor_username`,
            ADD UNIQUE KEY `uq_notification_event` (`user_id`, `activity_log_id`),
            ADD KEY `idx_notification_created` (`user_id`, `created_at`)");
    });

    $step('remove generated food photos', static function () use ($pdo) {
        // Also runs after restoring a backup taken while AI-generated photos existed.
        foreach (['menu_items', 'menu_bundles'] as $t) {
            if (jq_table_exists($pdo, $t) && jq_column_exists_fresh($pdo, $t, 'generated_photo_path')) {
                $pdo->exec("ALTER TABLE `$t` DROP COLUMN `generated_photo_path`");
            }
        }
    });

    $step('statuses, archives, postal codes and survey criteria', static function () use ($pdo, &$result) {
        jq_migrate_v24($pdo, $result);
    });

    $step('supervisor role, normalized catalogue, clients and payments', static function () use ($pdo, &$result) {
        jq_migrate_v25($pdo, $result);
    });

    $step('verification links, terms acceptance and Suppliers removal', static function () use ($pdo, &$result) {
        jq_migrate_v26($pdo, $result);
    });

    $step('proof of payment review', static function () use ($pdo, &$result) {
        jq_migrate_v27($pdo, $result);
    });

    $step('receipt review statuses and history', static function () use ($pdo, &$result) {
        jq_migrate_v28($pdo, $result);
    });

    $step('guests covered by bundles and add-ons', static function () use ($pdo, &$result) {
        jq_migrate_v29($pdo, $result);
    });

    $step('backup settings clean-up', static function () use ($pdo) {
        $pdo->exec("DELETE FROM `system_settings` WHERE `setting_key` = 'backup_admin_can_create'");
    });
}

/**
 * Booking statuses, archive retention, account archiving, postal codes, +63 numbers and survey criteria.
 * Also runs after a restore, so a backup taken before this version is brought up to date.
 * database/migration_v2_4_archiving_validation.sql is the same change as plain SQL.
 */
function jq_migrate_v24(PDO $pdo, array &$result): void
{
    foreach (['users', 'event_bookings', 'reports', 'client_surveys'] as $t) {
        if (!jq_table_exists($pdo, $t)) {
            return;
        }
    }
    $statusType = (string)($pdo->query("SHOW COLUMNS FROM `event_bookings` LIKE 'status'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '');
    $complete = jq_column_exists_fresh($pdo, 'users', 'postal_code') && jq_column_exists_fresh($pdo, 'reports', 'archive_expires_at')
        && jq_column_exists_fresh($pdo, 'client_surveys', 'would_recommend') && stripos($statusType, 'Confirmed') === false
        && stripos($statusType, 'Disabled') !== false;
    if ($complete) {
        return;
    }

    // This maintenance must not fill the Activity Log. Step 4 rebuilds the triggers from the new columns.
    foreach ($pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_jq\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (!in_array($t, jq_static_trigger_names(), true)) {
            $pdo->exec("DROP TRIGGER IF EXISTS `$t`");
        }
    }

    $addColumns = static function (string $table, array $columns) use ($pdo): void {
        $add = [];
        foreach ($columns as $name => $def) {
            if (!jq_column_exists_fresh($pdo, $table, $name)) {
                $add[] = "ADD COLUMN `$name` $def";
            }
        }
        if ($add) {
            $pdo->exec("ALTER TABLE `$table` " . implode(', ', $add));
        }
    };
    $addIndex = static function (string $table, string $name, string $cols) use ($pdo): void {
        if (!in_array($name, $pdo->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_COLUMN, 2), true)) {
            $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` $cols");
        }
    };
    $addConstraint = static function (string $table, string $name, string $definition) use ($pdo, &$result): void {
        $ddl = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1] ?? '';
        if (strpos($ddl, "`$name`") !== false) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` $definition");
        } catch (Throwable $e) {
            // Old values that break a rule are left for a person to correct; the forms still enforce it.
            $result['messages'][] = "Constraint $name was not added: " . $e->getMessage();
            error_log("Constraint $name: " . $e->getMessage());
        }
    };

    // Accounts
    $addColumns('users', [
        'postal_code'            => 'VARCHAR(4) NULL AFTER `street_address`',
        'is_archived'            => 'TINYINT(1) NOT NULL DEFAULT 0',
        'archived_at'            => 'DATETIME NULL',
        'archived_by'            => 'INT NULL',
        'credentials_deleted_at' => 'DATETIME NULL',
        'credentials_deleted_by' => 'INT NULL',
    ]);
    $pdo->exec("ALTER TABLE `users` MODIFY `password` VARCHAR(255) NULL");
    $addIndex('users', 'idx_users_archived', '(`is_archived`)');
    $addConstraint('users', 'chk_users_postal', "CHECK (`postal_code` IS NULL OR `postal_code` REGEXP '^[0-9]{4}$')");

    // Event bookings
    $addColumns('event_bookings', [
        'postal_code'        => 'VARCHAR(4) NULL AFTER `barangay`',
        'archive_expires_at' => 'DATETIME NULL AFTER `archived_at`',
        'pre_archive_status' => 'VARCHAR(20) NULL AFTER `archive_expires_at`',
    ]);
    if (stripos($statusType, 'Confirmed') !== false || stripos($statusType, 'Disabled') === false) {
        $pdo->exec("ALTER TABLE `event_bookings` MODIFY `status` ENUM('Pending','Approved','Cancelled','Disabled','Confirmed','Completed') DEFAULT 'Pending'");
        $pdo->exec("UPDATE `event_bookings` SET `status` = 'Approved' WHERE `status` = 'Confirmed'");
        $pdo->exec("ALTER TABLE `event_bookings` MODIFY `status` ENUM('Pending','Approved','Cancelled','Disabled','Completed') DEFAULT 'Pending'");
    }
    $pdo->exec("UPDATE `event_bookings` SET `pre_archive_status` = `status`, `status` = 'Cancelled',
                       `archived_at` = COALESCE(`archived_at`, NOW()),
                       `archive_expires_at` = DATE_ADD(COALESCE(`archived_at`, NOW()), INTERVAL 30 DAY)
                 WHERE `is_archived` = 1 AND `archive_expires_at` IS NULL");
    $addIndex('event_bookings', 'idx_booking_archive', '(`is_archived`, `archive_expires_at`)');
    $addConstraint('event_bookings', 'chk_booking_postal', "CHECK (`postal_code` IS NULL OR `postal_code` REGEXP '^[0-9]{4}$')");

    // Reports
    $addColumns('reports', [
        'is_archived'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'archived_at'        => 'DATETIME NULL',
        'archived_by'        => 'INT NULL',
        'archive_expires_at' => 'DATETIME NULL',
    ]);
    $pdo->exec("UPDATE `reports` SET `is_archived` = 1, `archived_at` = NOW(), `archive_expires_at` = DATE_ADD(NOW(), INTERVAL 30 DAY), `status` = 'Generated' WHERE `status` = 'Archived'");
    $pdo->exec("ALTER TABLE `reports` MODIFY `status` ENUM('Generated','Draft','Pending','Reviewed','Resolved') DEFAULT 'Generated'");
    $addIndex('reports', 'idx_report_archive', '(`is_archived`, `archive_expires_at`)');
    $pdo->exec("UPDATE `reports` r LEFT JOIN `users` u ON u.`id` = r.`user_id` SET r.`user_id` = NULL WHERE r.`user_id` IS NOT NULL AND u.`id` IS NULL");
    $addConstraint('reports', 'fk_report_user', "FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL");

    // Client surveys
    $addColumns('client_surveys', [
        'rating_food'         => 'TINYINT NULL AFTER `rating`',
        'rating_service'      => 'TINYINT NULL AFTER `rating_food`',
        'rating_timeliness'   => 'TINYINT NULL AFTER `rating_service`',
        'rating_presentation' => 'TINYINT NULL AFTER `rating_timeliness`',
        'rating_value'        => 'TINYINT NULL AFTER `rating_presentation`',
        'would_recommend'     => 'TINYINT(1) NULL AFTER `rating_value`',
    ]);
    $pdo->exec("UPDATE `client_surveys` s LEFT JOIN `event_bookings` b ON b.`booking_id` = s.`booking_id` SET s.`booking_id` = NULL WHERE s.`booking_id` IS NOT NULL AND b.`booking_id` IS NULL");
    $addConstraint('client_surveys', 'fk_survey_booking', "FOREIGN KEY (`booking_id`) REFERENCES `event_bookings` (`booking_id`) ON DELETE SET NULL");
    $addConstraint('client_surveys', 'chk_survey_criteria', "CHECK (COALESCE(`rating_food`,1) BETWEEN 1 AND 5 AND COALESCE(`rating_service`,1) BETWEEN 1 AND 5 AND COALESCE(`rating_timeliness`,1) BETWEEN 1 AND 5 AND COALESCE(`rating_presentation`,1) BETWEEN 1 AND 5 AND COALESCE(`rating_value`,1) BETWEEN 1 AND 5)");

    // Contact numbers move to +639XXXXXXXXX. Anything that is not a recognizable mobile number is left as it is.
    foreach ([['users', 'id', 'contact_number'], ['event_bookings', 'booking_id', 'contact']] as [$table, $pk, $col]) {
        if (!jq_table_exists($pdo, $table)) {
            continue;
        }
        $update = $pdo->prepare("UPDATE `$table` SET `$col` = ? WHERE `$pk` = ?");
        foreach ($pdo->query("SELECT `$pk` AS pk, `$col` AS v FROM `$table` WHERE `$col` IS NOT NULL AND `$col` <> ''")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $digits = preg_replace('/[\s\-().]/', '', (string)$row['v']);
            if (preg_match('/^(?:\+?63|0)?(9\d{9})$/', $digits, $m) && $row['v'] !== '+63' . $m[1]) {
                $update->execute(['+63' . $m[1], $row['pk']]);
            }
        }
    }
    $result['messages'][] = 'Database updated for booking statuses, archive retention, postal codes, +63 numbers and survey criteria.';
}

/** Uncached column check (migrations change columns during the request). */
function jq_column_exists_fresh(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * The old sanitize() stored text HTML-encoded ("Wedding &amp; Debut"), which pages then encoded again.
 * Decodes stored business text once and for all; output escaping now happens only at display time.
 * Activity logs are deliberately untouched (write-once). Returns the number of values changed.
 */
function jq_decode_stored_entities(PDO $pdo): int
{
    $tables = array_merge(array_keys(jq_audited_tables()), ['booking_items', 'notifications']);
    $in = implode(',', array_fill(0, count($tables), '?'));
    $st = $pdo->prepare("SELECT c.TABLE_NAME, c.COLUMN_NAME,
                                (SELECT k.COLUMN_NAME FROM information_schema.COLUMNS k
                                  WHERE k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME AND k.COLUMN_KEY = 'PRI' LIMIT 1) AS pk
                           FROM information_schema.COLUMNS c
                          WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME IN ($in)
                            AND c.DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext')
                            AND c.COLUMN_NAME <> 'password'");
    $st->execute($tables);
    $changed = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (empty($c['pk'])) {
            continue;
        }
        [$t, $col, $pk] = [$c['TABLE_NAME'], $c['COLUMN_NAME'], $c['pk']];
        $rows = $pdo->query("SELECT `$pk` AS pk, `$col` AS v FROM `$t` WHERE `$col` LIKE '%&%;%'")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $pdo->prepare("UPDATE `$t` SET `$col` = ? WHERE `$pk` = ?");
        foreach ($rows as $r) {
            $new = $r['v'];
            for ($i = 0; $i < 5; $i++) { // handles double-encoded values
                $next = html_entity_decode($new, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($next === $new) {
                    break;
                }
                $new = $next;
            }
            if ($new !== $r['v']) {
                $upd->execute([$new, $r['pk']]);
                $changed++;
            }
        }
    }
    return $changed;
}

/** Live column layout of the audited tables + schema version. */
function jq_audit_signature(PDO $pdo): string
{
    $tables = array_keys(jq_audited_tables());
    $tables[] = 'activity_logs';
    $in = implode(',', array_fill(0, count($tables), '?'));
    $stmt = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_KEY FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)
                            ORDER BY TABLE_NAME, ORDINAL_POSITION");
    $stmt->execute($tables);
    return md5(JQ_AUDIT_SCHEMA_VERSION . '|' . json_encode($stmt->fetchAll(PDO::FETCH_NUM)));
}

function jq_audited_table_columns(PDO $pdo): array
{
    $tables = array_keys(jq_audited_tables());
    $in = implode(',', array_fill(0, count($tables), '?'));
    $stmt = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_KEY FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)
                            ORDER BY TABLE_NAME, ORDINAL_POSITION");
    $stmt->execute($tables);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['TABLE_NAME']]['columns'][] = $r['COLUMN_NAME'];
        if ($r['COLUMN_KEY'] === 'PRI' && !isset($out[$r['TABLE_NAME']]['pk'])) {
            $out[$r['TABLE_NAME']]['pk'] = $r['COLUMN_NAME'];
        }
    }
    return $out;
}

function jq_expected_trigger_names(PDO $pdo): array
{
    $names = jq_static_trigger_names();
    foreach (jq_audited_table_columns($pdo) as $table => $info) {
        if (!empty($info['pk'])) {
            foreach (['ai', 'au', 'ad'] as $sfx) {
                $names[] = "trg_jq_{$table}_{$sfx}";
            }
        }
    }
    return $names;
}

/** SQL string literal. */
function jq_sql_str(string $s): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";
}

/** JSON "key": value fragment for a column of OLD/NEW, as an SQL expression. */
function jq_json_pair_sql(string $row, string $col, array $sensitive, ?string $sensitiveValue = null): string
{
    $key = jq_sql_str(json_encode($col) . ':');
    if (in_array($col, $sensitive, true)) {
        return "CONCAT($key, " . jq_sql_str(json_encode($sensitiveValue ?? '[protected]')) . ")";
    }
    return "CONCAT($key, IFNULL(JSON_QUOTE(CAST($row.`$col` AS CHAR)), 'null'))";
}

/** Builds the CREATE TRIGGER statements for one audited table. */
function jq_build_table_triggers(string $table, array $columns, string $pk, array $cfg): array
{
    $sensitive = $cfg['sensitive'] ?? [];
    $ignored   = jq_audit_ignored_columns();
    $module    = jq_sql_str($cfg['module']);
    $entity    = $cfg['entity'];
    $tableLit  = jq_sql_str($table);

    $labelCols = array_values(array_intersect($cfg['label'] ?? [], $columns));
    $label = static function (string $row) use ($labelCols) {
        if (!$labelCols) {
            return 'NULL';
        }
        $parts = array_map(static fn($c) => "$row.`$c`", $labelCols);
        return "NULLIF(CONCAT_WS(' - ', " . implode(', ', $parts) . "), '')";
    };
    $fullJson = static function (string $row) use ($columns, $sensitive) {
        $pairs = array_map(static fn($c) => jq_json_pair_sql($row, $c, $sensitive), $columns);
        return "CONCAT('{', CONCAT_WS(',', " . implode(', ', $pairs) . "), '}')";
    };

    $compareCols = array_values(array_diff($columns, $ignored));
    $eq = static fn($c) => "(OLD.`$c` <=> NEW.`$c`)";
    $allEqual = implode(' AND ', array_map($eq, $compareCols));

    $changedJson = static function (string $row) use ($compareCols, $sensitive) {
        $pairs = [];
        foreach ($compareCols as $c) {
            $val = $row === 'NEW' ? '[changed]' : '[protected]';
            $pairs[] = "IF(OLD.`$c` <=> NEW.`$c`, NULL, " . jq_json_pair_sql($row, $c, $sensitive, $val) . ")";
        }
        return "CONCAT('{', CONCAT_WS(',', " . implode(', ', $pairs) . "), '}')";
    };

    // Action classification for updates
    $hasArchive = in_array('is_archived', $columns, true);
    $hasStatus  = in_array('status', $columns, true);
    $hasActive  = in_array('is_active', $columns, true);
    $cases = [];
    if ($hasArchive) {
        $cases[] = "WHEN COALESCE(OLD.`is_archived`,0) = 0 AND COALESCE(NEW.`is_archived`,0) = 1 THEN 'ARCHIVE'";
        $cases[] = "WHEN COALESCE(OLD.`is_archived`,0) = 1 AND COALESCE(NEW.`is_archived`,0) = 0 THEN 'RESTORE'";
    }
    if ($hasStatus) {
        $others = array_values(array_diff($compareCols, ['status']));
        $othersEqual = $others ? implode(' AND ', array_map($eq, $others)) : '1=1';
        $cases[] = "WHEN NOT (OLD.`status` <=> NEW.`status`) AND ($othersEqual) THEN 'STATUS_CHANGE'";
    }
    $actionSql = $cases ? "CASE " . implode(' ', $cases) . " ELSE 'UPDATE' END" : "'UPDATE'";

    $verbSql = "CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END";
    $extra = [];
    if ($hasStatus) {
        $extra[] = "IF(OLD.`status` <=> NEW.`status`, '', CONCAT(' - status: ', IFNULL(OLD.`status`,'none'), ' -> ', IFNULL(NEW.`status`,'none')))";
    }
    if ($hasActive) {
        $extra[] = "IF(OLD.`is_active` <=> NEW.`is_active`, '', IF(NEW.`is_active` = 1, ' - activated', ' - deactivated'))";
    }
    foreach ($sensitive as $s) {
        if (in_array($s, $columns, true)) {
            $extra[] = "IF(OLD.`$s` <=> NEW.`$s`, '', ' - $s changed')";
        }
    }
    $extraSql = $extra ? ', ' . implode(', ', $extra) : '';

    $ctxUser = "(SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1)";
    $ctxName = "LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100)";
    $ctxSrc  = "IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')";
    $cols    = "(user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source)";

    $labelPart = static fn(string $row) => "IFNULL(CONCAT(' (', " . $label($row) . ", ')'), '')";

    $ai = "CREATE TRIGGER `trg_jq_{$table}_ai` AFTER INSERT ON `$table` FOR EACH ROW
        INSERT INTO `activity_logs` $cols VALUES (
            $ctxUser, $ctxName, @app_role, 'CREATE', $module, $tableLit, CAST(NEW.`$pk` AS CHAR), LEFT(" . $label('NEW') . ", 255),
            CONCAT('Created $entity #', NEW.`$pk`, " . $labelPart('NEW') . "),
            NULL, " . $fullJson('NEW') . ", @app_ip, @app_ua, @app_page, $ctxSrc)";

    $au = "CREATE TRIGGER `trg_jq_{$table}_au` AFTER UPDATE ON `$table` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ($allEqual) THEN
                SET v_action = $actionSql;
                INSERT INTO `activity_logs` $cols VALUES (
                    $ctxUser, $ctxName, @app_role, v_action, $module, $tableLit, CAST(NEW.`$pk` AS CHAR), LEFT(" . $label('NEW') . ", 255),
                    CONCAT($verbSql, ' $entity #', NEW.`$pk`, " . $labelPart('NEW') . "$extraSql),
                    " . $changedJson('OLD') . ", " . $changedJson('NEW') . ", @app_ip, @app_ua, @app_page, $ctxSrc);
            END IF;
        END";

    $ad = "CREATE TRIGGER `trg_jq_{$table}_ad` AFTER DELETE ON `$table` FOR EACH ROW
        INSERT INTO `activity_logs` $cols VALUES (
            $ctxUser, $ctxName, @app_role, 'DELETE', $module, $tableLit, CAST(OLD.`$pk` AS CHAR), LEFT(" . $label('OLD') . ", 255),
            CONCAT('Deleted $entity #', OLD.`$pk`, " . $labelPart('OLD') . "),
            " . $fullJson('OLD') . ", NULL, @app_ip, @app_ua, @app_page, $ctxSrc)";

    return ["trg_jq_{$table}_ai" => $ai, "trg_jq_{$table}_au" => $au, "trg_jq_{$table}_ad" => $ad];
}

/** Triggers protecting activity_logs, activity_log_audit and backup_history. */
function jq_build_static_triggers(): array
{
    $snapshot = static fn(string $r) => "JSON_OBJECT('action', $r.action, 'module', $r.module, 'description', $r.description,
        'record_label', $r.record_label, 'notes', $r.notes, 'is_archived', $r.is_archived, 'edit_count', $r.edit_count)";
    $deny = static fn(string $msg) => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = " . jq_sql_str($msg);
    $ctxUser = "(SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1)";

    return [
        // New log rows: server time only, never pre-archived; manual rows only by the OWNER.
        'trg_jq_activity_logs_bi' => "CREATE TRIGGER `trg_jq_activity_logs_bi` BEFORE INSERT ON `activity_logs` FOR EACH ROW
            BEGIN
                SET NEW.created_at = CURRENT_TIMESTAMP(3), NEW.is_archived = 0, NEW.archived_at = NULL, NEW.archived_by = NULL,
                    NEW.restored_at = NULL, NEW.restored_by = NULL, NEW.edited_at = NULL, NEW.edited_by = NULL, NEW.edit_count = 0;
                IF NEW.source = 'manual' AND COALESCE(@app_role, '') <> 'OWNER' THEN
                    " . $deny('Only the OWNER can create manual activity log entries.') . ";
                END IF;
            END",

        'trg_jq_activity_logs_ai' => "CREATE TRIGGER `trg_jq_activity_logs_ai` AFTER INSERT ON `activity_logs` FOR EACH ROW
            BEGIN
                IF NEW.source = 'manual' THEN
                    INSERT INTO `activity_log_audit` (log_id, operation, old_snapshot, new_snapshot, reason, performed_by, performed_by_username, performed_by_role, ip_address)
                    VALUES (NEW.id, 'CREATE', NULL, " . $snapshot('NEW') . ", LEFT(COALESCE(@app_log_reason, 'Manual entry'), 500),
                            $ctxUser, LEFT(@app_username, 100), @app_role, @app_ip);
                END IF;
                BEGIN
                    -- A notification problem must never block the business operation being logged.
                    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
                    IF NEW.action IN ('CREATE','UPDATE','STATUS_CHANGE','ARCHIVE','RESTORE','DELETE','BACKUP_CREATE','BACKUP_RESTORE','BACKUP_FAILED','LOG_ARCHIVE','LOG_RESTORE','RETENTION_PURGE') THEN
                        INSERT IGNORE INTO `notifications` (user_id, type, title, message, reference_id, reference_type, actor_user_id, actor_username, activity_log_id)
                        SELECT u.`id`,
                               CASE WHEN NEW.record_table IN ('event_bookings','booking_payments','booking_attachments','event_types','clients') THEN 'event'
                                    WHEN NEW.record_table = 'reports' THEN 'report'
                                    WHEN NEW.record_table = 'client_surveys' THEN 'feedback'
                                    WHEN NEW.record_table IN ('foodstocks','foodstocks_categories','menu_packages','menu_items','menu_bundles') THEN 'inventory'
                                    ELSE 'system' END,
                               CASE WHEN NEW.record_table = 'event_bookings' AND NEW.action = 'CREATE' THEN 'New booking'
                                    WHEN NEW.record_table = 'event_bookings' AND NEW.action = 'STATUS_CHANGE' THEN 'Booking status changed'
                                    WHEN NEW.record_table = 'event_bookings' AND NEW.action = 'UPDATE' THEN 'Booking updated'
                                    WHEN NEW.record_table = 'users' AND NEW.action = 'CREATE' THEN 'New user account'
                                    WHEN NEW.record_table = 'users' THEN 'User account changed'
                                    WHEN NEW.record_table IN ('menu_packages','menu_items','menu_bundles') THEN 'Menu or package changed'
                                    WHEN NEW.action = 'BACKUP_CREATE' THEN 'Database backup created'
                                    WHEN NEW.action = 'BACKUP_RESTORE' THEN 'Database restored'
                                    WHEN NEW.action = 'BACKUP_FAILED' THEN 'Backup operation failed'
                                    WHEN NEW.action IN ('LOG_ARCHIVE','LOG_RESTORE') THEN 'Activity logs managed'
                                    ELSE CONCAT(COALESCE(NEW.module, 'System'), ' record changed') END,
                               COALESCE(NEW.description, ''),
                               IF(NEW.record_id REGEXP '^[0-9]+$', CAST(NEW.record_id AS UNSIGNED), NULL),
                               NEW.record_table, NEW.user_id, NEW.username, NEW.id
                          FROM `users` u
                         WHERE u.`role` IN ('OWNER','ADMIN') AND u.`status` = 'active' AND u.`is_verified` = 1 AND u.`is_archived` = 0;
                    END IF;
                END;
            END",

        // Write-once: the archive flag is the only thing the OWNER can change.
        'trg_jq_activity_logs_bu' => "CREATE TRIGGER `trg_jq_activity_logs_bu` BEFORE UPDATE ON `activity_logs` FOR EACH ROW
            BEGIN
                DECLARE v_op VARCHAR(20);
                IF COALESCE(@app_log_mgmt, 0) <> 1 OR COALESCE(@app_role, '') <> 'OWNER' THEN
                    " . $deny('Activity log records are protected. Only the OWNER can archive them through the Activity Log module.') . ";
                END IF;
                IF NOT (OLD.user_id <=> NEW.user_id AND OLD.username <=> NEW.username AND OLD.user_role <=> NEW.user_role
                        AND OLD.created_at <=> NEW.created_at AND OLD.ip_address <=> NEW.ip_address AND OLD.user_agent <=> NEW.user_agent
                        AND OLD.record_table <=> NEW.record_table AND OLD.record_id <=> NEW.record_id AND OLD.record_label <=> NEW.record_label
                        AND OLD.old_values <=> NEW.old_values AND OLD.new_values <=> NEW.new_values
                        AND OLD.action <=> NEW.action AND OLD.module <=> NEW.module AND OLD.description <=> NEW.description AND OLD.notes <=> NEW.notes
                        AND OLD.source <=> NEW.source AND OLD.page <=> NEW.page) THEN
                    " . $deny('Activity log records cannot be edited.') . ";
                END IF;
                IF COALESCE(OLD.is_archived,0) = 0 AND COALESCE(NEW.is_archived,0) = 1 THEN
                    SET v_op = 'ARCHIVE', NEW.archived_at = CURRENT_TIMESTAMP(3), NEW.archived_by = @app_user_id,
                        NEW.restored_at = OLD.restored_at, NEW.restored_by = OLD.restored_by,
                        NEW.edited_at = OLD.edited_at, NEW.edited_by = OLD.edited_by, NEW.edit_count = OLD.edit_count;
                ELSEIF COALESCE(OLD.is_archived,0) = 1 AND COALESCE(NEW.is_archived,0) = 0 THEN
                    SET v_op = 'RESTORE', NEW.restored_at = CURRENT_TIMESTAMP(3), NEW.restored_by = @app_user_id,
                        NEW.archived_at = OLD.archived_at, NEW.archived_by = OLD.archived_by,
                        NEW.edited_at = OLD.edited_at, NEW.edited_by = OLD.edited_by, NEW.edit_count = OLD.edit_count;
                ELSE
                    " . $deny('Activity log records cannot be edited.') . ";
                END IF;
                INSERT INTO `activity_log_audit` (log_id, operation, old_snapshot, new_snapshot, reason, performed_by, performed_by_username, performed_by_role, ip_address)
                VALUES (OLD.id, v_op, " . $snapshot('OLD') . ", " . $snapshot('NEW') . ", LEFT(@app_log_reason, 500),
                        $ctxUser, LEFT(@app_username, 100), @app_role, @app_ip);
            END",

        'trg_jq_activity_logs_bd' => "CREATE TRIGGER `trg_jq_activity_logs_bd` BEFORE DELETE ON `activity_logs` FOR EACH ROW
            " . $deny('Activity log records cannot be deleted. Archive them instead.'),

        'trg_jq_log_audit_bu' => "CREATE TRIGGER `trg_jq_log_audit_bu` BEFORE UPDATE ON `activity_log_audit` FOR EACH ROW
            " . $deny('The activity log audit trail is append-only.'),
        'trg_jq_log_audit_bd' => "CREATE TRIGGER `trg_jq_log_audit_bd` BEFORE DELETE ON `activity_log_audit` FOR EACH ROW
            " . $deny('The activity log audit trail is append-only.'),

        'trg_jq_backup_history_bu' => "CREATE TRIGGER `trg_jq_backup_history_bu` BEFORE UPDATE ON `backup_history` FOR EACH ROW
            " . $deny('Backup history is append-only.'),
        'trg_jq_backup_history_bd' => "CREATE TRIGGER `trg_jq_backup_history_bd` BEFORE DELETE ON `backup_history` FOR EACH ROW
            " . $deny('Backup history is append-only.'),
    ];
}

/** Drops and recreates every trg_jq_* trigger to match the live table layout. */
function jq_install_triggers(PDO $pdo): void
{
    $statements = jq_build_static_triggers();
    $config = jq_audited_tables();
    foreach (jq_audited_table_columns($pdo) as $table => $info) {
        if (empty($info['pk']) || !isset($config[$table])) {
            continue;
        }
        $statements += jq_build_table_triggers($table, $info['columns'], $info['pk'], $config[$table]);
    }

    $present = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_jq\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($present as $name) {
        if (!isset($statements[$name])) {
            $pdo->exec("DROP TRIGGER IF EXISTS `$name`");
        }
    }
    foreach ($statements as $name => $sql) {
        $pdo->exec("DROP TRIGGER IF EXISTS `$name`");
        $pdo->exec($sql);
    }
}

/* =====================================================================
 *  PRESENTATION HELPERS (shared by activitylog.php / backups.php)
 * ===================================================================== */

/** Visual tone (CSS modifier) for an action badge. */
function jq_action_tone(string $action): string
{
    $a = strtoupper($action);
    if (in_array($a, ['DELETE', 'LOGIN_FAILED', 'ACCESS_DENIED', 'BACKUP_FAILED'], true)) return 'delete';
    if (in_array($a, ['ARCHIVE', 'LOG_ARCHIVE'], true)) return 'archive';
    if (in_array($a, ['RESTORE', 'LOG_RESTORE', 'BACKUP_RESTORE'], true)) return 'restore';
    if (in_array($a, ['UPDATE', 'STATUS_CHANGE', 'BACKUP_SETTINGS'], true)) return 'edit';
    if (in_array($a, ['CREATE', 'LOG_CREATE', 'BACKUP_CREATE', 'BACKUP_UPLOAD'], true)) return 'brand';
    return 'view';
}

function jq_action_label(string $action): string
{
    return ucwords(strtolower(str_replace('_', ' ', $action)));
}

function jq_human_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $size = (float)$bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return ($i === 0 ? (string)$bytes : number_format($size, $size >= 100 ? 0 : 1)) . ' ' . $units[$i];
}

/**
 * Validates an uploaded picture by its real content, not by what the browser claims.
 * @return string|null the safe file extension, or null with $error set
 */
function jq_image_upload_extension(array $file, ?string &$error = null, int $maxBytes = 5242880, array $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif']): ?string
{
    $typeNames = implode(', ', array_map(static fn($e) => $e === 'webp' ? 'WebP' : strtoupper($e), $types));
    $codes = [
        UPLOAD_ERR_INI_SIZE  => 'The picture is larger than the server allows (' . ini_get('upload_max_filesize') . ').',
        UPLOAD_ERR_FORM_SIZE => 'The picture is too large.',
        UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE   => 'Please choose a picture.',
    ];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = $codes[$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'The picture could not be uploaded because of a server problem.';
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid upload.';
        return null;
    }
    if ($file['size'] > $maxBytes) {
        $error = 'The picture must be ' . round($maxBytes / 1048576) . ' MB or smaller.';
        return null;
    }
    // The name must agree with the content, so "photo.php.jpg" style tricks and renamed files are refused.
    $given = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $given = $given === 'jpeg' ? 'jpg' : $given;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $info = @getimagesize($file['tmp_name']);
    if (!isset($types[$mime]) || !$info || $info['mime'] !== $mime || $given !== $types[$mime]) {
        $error = 'Only valid ' . $typeNames . ' images are accepted.';
        return null;
    }
    return $types[$mime];
}

/** Builds a URL to the current page with overridden query parameters. */
function jq_url(array $overrides = [], array $drop = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($drop as $k) {
        unset($params[$k]);
    }
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return $script . ($params ? '?' . http_build_query($params) : '');
}

/** Previous / Next pagination with the current position. Links keep the current filters. */
function jq_pagination(int $page, int $pages, string $param = 'page'): string
{
    $pages = max(1, $pages);
    $link = static function (int $p, string $label, bool $disabled) use ($param) {
        if ($disabled) {
            return '<span class="jq-page-link is-disabled" aria-disabled="true">' . $label . '</span>';
        }
        return '<a class="jq-page-link" href="' . jq_e(jq_url([$param => $p])) . '">' . $label . '</a>';
    };
    return '<nav class="jq-pagination" aria-label="Pagination">'
        . $link($page - 1, '<i class="bx bx-chevron-left"></i> Previous', $page <= 1)
        . '<span class="jq-page-info">Page ' . $page . ' of ' . $pages . '</span>'
        . $link($page + 1, 'Next <i class="bx bx-chevron-right"></i>', $page >= $pages)
        . '</nav>';
}

/**
 * Compares previous / updated JSON values for display.
 * @return array<int, array{field: string, old: ?string, new: ?string, changed: bool}>
 */
function jq_diff_values(?string $oldJson, ?string $newJson): array
{
    $old = $oldJson ? json_decode($oldJson, true) : null;
    $new = $newJson ? json_decode($newJson, true) : null;
    $old = is_array($old) ? $old : [];
    $new = is_array($new) ? $new : [];
    $rows = [];
    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
        $o = array_key_exists($field, $old) ? $old[$field] : null;
        $n = array_key_exists($field, $new) ? $new[$field] : null;
        $fmt = static function ($v) {
            if ($v === null) return null;
            if (is_bool($v)) return $v ? 'true' : 'false';
            if (is_scalar($v)) return jq_plain((string)$v);
            return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        };
        $rows[] = [
            'field'   => (string)$field,
            'old'     => $fmt($o),
            'new'     => $fmt($n),
            'changed' => $oldJson && $newJson ? $o !== $n : true,
        ];
    }
    return $rows;
}
