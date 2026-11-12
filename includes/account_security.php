<?php
/**
 * ACCOUNT RULES - Janet's Quality Catering System
 * One place for the password, username, phone and email-verification rules, used by
 * accounts.php, profile.php, login.php, verify_email.php and ajax/check_account_field.php.
 */

if (defined('JQ_ACCOUNT_SECURITY_LOADED')) {
    return;
}
define('JQ_ACCOUNT_SECURITY_LOADED', true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/activity_logger.php';

const JQ_PASSWORD_MIN_LENGTH = 16;
/** Raise this when the Terms and Conditions text in login.php changes, so new acceptances are recorded separately. */
const JQ_TERMS_VERSION = '1.0';

/** The roles this person may give a new account. The single place that decides role assignment. */
function jq_assignable_roles(string $viewerRole): array
{
    return $viewerRole === 'OWNER' ? ['ADMIN', 'SUPERVISOR'] : ['ADMIN'];
}

/** Records that this account accepted the current terms, keeping the first acceptance time. Never blocks a sign-in. */
function jq_record_terms_acceptance(?PDO $pdo, int $userId): void
{
    if (!$pdo) {
        return; // built-in accounts can sign in during a database outage
    }
    try {
        $pdo->prepare('INSERT INTO terms_acceptances (user_id, terms_version, accepted_at, last_accepted_at, ip_address, user_agent)
                       VALUES (?, ?, NOW(), NOW(), ?, ?) ' . (jq_is_pg()
                       ? 'ON CONFLICT (user_id, terms_version) DO UPDATE SET last_accepted_at = NOW(), ip_address = EXCLUDED.ip_address, user_agent = EXCLUDED.user_agent'
                       : 'ON DUPLICATE KEY UPDATE last_accepted_at = NOW(), ip_address = VALUES(ip_address), user_agent = VALUES(user_agent)'))
            ->execute([$userId, JQ_TERMS_VERSION, jq_client_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    } catch (Throwable $e) {
        error_log('Terms acceptance could not be recorded: ' . $e->getMessage());
    }
}

/** Returns an error message, or null when the password is acceptable. */
function jq_password_error(string $password): ?string
{
    if (mb_strlen($password) < JQ_PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . JQ_PASSWORD_MIN_LENGTH . ' characters long.';
    }
    if (strlen($password) > 72) {
        return 'Password can be at most 72 characters long.'; // bcrypt ignores anything longer
    }
    return null;
}


function jq_username_taken(PDO $pdo, string $username, int $excludeId = 0): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?");
    $st->execute([$username, $excludeId]);
    return (int)$st->fetchColumn() > 0;
}

function jq_phone_taken(PDO $pdo, string $phone, int $excludeId = 0): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE contact_number = ? AND id <> ?");
    $st->execute([$phone, $excludeId]);
    return (int)$st->fetchColumn() > 0;
}

/** Every account needs its own Gmail address, because codes are sent there. Returns an error or null. */
function jq_email_error(string $email): ?string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    if (!preg_match('/@(gmail|googlemail)\.com$/i', $email)) {
        return 'Please enter a Gmail address (name@gmail.com).';
    }
    return null;
}

/** Gmail ignores dots and "+tag", so a.b+x@gmail.com and ab@gmail.com are one mailbox. */
function jq_canonical_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', strtolower(trim($email)), 2), 2, '');
    if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
        $local = str_replace('.', '', explode('+', $local, 2)[0]);
        $domain = 'gmail.com';
    }
    return $local . '@' . $domain;
}

function jq_email_taken(PDO $pdo, string $email, int $excludeId = 0): bool
{
    $wanted = jq_canonical_email($email);
    $st = $pdo->prepare("SELECT email FROM users WHERE email IS NOT NULL AND email <> '' AND id <> ?");
    $st->execute([$excludeId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $existing) {
        if (jq_canonical_email($existing) === $wanted) {
            return true;
        }
    }
    return false;
}

/** The address saved on this account. $fallback is used only when the database cannot be read. */
function jq_registered_email(?PDO $pdo, int $userId, string $fallback = ''): string
{
    if ($pdo) {
        try {
            $st = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $st->execute([$userId]);
            $email = $st->fetchColumn();
            if ($email !== false) {
                return trim((string)$email);
            }
        } catch (Throwable $e) {
            error_log('Registered email lookup failed: ' . $e->getMessage());
        }
    }
    return trim($fallback);
}

/** A login code only matches the account and address it was sent to. */
function jq_login_code_hash(int $userId, string $email, string $code): string
{
    return hash_hmac('sha256', $code, 'login|' . $userId . '|' . strtolower($email));
}

/** Checks a password against the account, including its built-in fallback login. */
function jq_password_matches_account(PDO $pdo, int $userId, string $password): bool
{
    $st = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $st->execute([$userId]);
    $hash = $st->fetchColumn();
    if ($hash === false || $hash === null) {
        return false; // no such account, or its credentials were permanently removed
    }
    if (jq_password_verify($password, $hash)) {
        return true;
    }
    foreach (getFallbackAccounts() as $acc) {
        if ((int)$acc['user_id'] === $userId && jq_password_verify($password, $acc['hash'])) {
            return true;
        }
    }
    return false;
}

/* =====================================================================
 *  EMAIL VERIFICATION (link and 6-digit code, sent with the existing PHPMailer setup)
 * ===================================================================== */

function jq_mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($local, 0, 2) . str_repeat('*', max(mb_strlen($local) - 2, 3)) . '@' . $domain;
}

/**
 * Sends a new verification link and code to the account's email. The earlier ones are replaced only
 * after the new email was accepted, so a failed send never breaks what the user already received.
 * Only hashes of the token and code are stored.
 * @return array{success: bool, message: string, throttled?: bool}
 */
function jq_send_verification(PDO $pdo, array $user): array
{
    $userId = (int)$user['id'];
    // One send at a time per account: a double click cannot create two competing codes.
    $lock = 'jq_verify_' . $userId;
    if (!jq_db_lock($pdo, $lock)) {
        return ['success' => false, 'throttled' => true, 'message' => 'A verification email is already being sent. Please wait a moment.'];
    }
    try {
        if (jq_verification_recently_sent($pdo, $userId)) {
            return ['success' => false, 'throttled' => true, 'message' => 'A verification email was sent less than a minute ago. Please check the inbox and spam folder before requesting another.'];
        }
        $code = generateEmailVerificationCode(EMAIL_VERIFICATION_CODE_LENGTH);
        $token = bin2hex(random_bytes(32));
        $link = rtrim(APP_BASE_URL, '/') . '/verify_email.php?token=' . $token;
        $mail = sendAccountVerificationEmail((string)$user['email'], (string)($user['first_name'] ?: $user['username']), (string)$user['username'], $code, $link);
        if (empty($mail['success'])) {
            error_log('Verification email failed for user ' . $userId . ': ' . ($mail['message'] ?? 'unknown error'));
            return ['success' => false, 'message' => 'The verification email could not be sent. Please try again in a few minutes.'];
        }
        jq_store_verification_code($pdo, $userId, (string)$user['email'], $code, hash('sha256', $token));
        return ['success' => true, 'message' => 'Verification email sent to ' . jq_mask_email((string)$user['email']) . '.'];
    } finally {
        jq_db_unlock($pdo, $lock);
    }
}

/** Makes this code and link the account's only active ones. */
function jq_store_verification_code(PDO $pdo, int $userId, string $email, string $code, ?string $tokenHash = null): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        $pdo->prepare('INSERT INTO email_verifications (user_id, email, code_hash, token_hash, expires_at) VALUES (?, ?, ?, ?, ' . jq_sql_add('NOW()', (int)EMAIL_VERIFICATION_EXPIRY, 'MINUTE') . ')')
            ->execute([$userId, $email, password_hash($code, PASSWORD_DEFAULT), $tokenHash]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** True when a verification email went out to this user within the resend wait. */
function jq_verification_recently_sent(PDO $pdo, int $userId): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM email_verifications WHERE user_id = ? AND created_at > ' . jq_sql_add('NOW()', -(int)EMAIL_VERIFICATION_RESEND_SECONDS, 'SECOND'));
    $st->execute([$userId]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * Checks a code and, when correct, verifies the account in one transaction.
 * A code is accepted once, only before it expires, only for the email it was sent to,
 * and stops working after too many wrong tries.
 * @return array{success: bool, message: string}
 */
function jq_verify_code(PDO $pdo, int $userId, string $code): array
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== EMAIL_VERIFICATION_CODE_LENGTH) {
        return ['success' => false, 'message' => 'Enter the ' . EMAIL_VERIFICATION_CODE_LENGTH . '-digit code from the email.'];
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, username, email, is_verified FROM users WHERE id = ? FOR UPDATE');
        $st->execute([$userId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'This account no longer exists.'];
        }
        if ((int)$user['is_verified'] === 1) {
            $pdo->rollBack();
            return ['success' => true, 'message' => 'This account is already verified.'];
        }
        $st = $pdo->prepare('SELECT id, email, code_hash, attempts, expires_at <= NOW() AS expired
                               FROM email_verifications WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $retire = $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?');

        if (!$row) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'There is no active code for this account. Please request a new one.'];
        }
        if ((int)$row['expired'] === 1) {
            $retire->execute([$row['id']]);
            $pdo->commit();
            return ['success' => false, 'message' => 'This code has expired. Please request a new one.'];
        }
        if (strcasecmp($row['email'], (string)$user['email']) !== 0) {
            $retire->execute([$row['id']]);
            $pdo->commit();
            return ['success' => false, 'message' => 'This code was sent to a different email address. Please request a new one.'];
        }
        if (!password_verify($code, $row['code_hash'])) {
            $left = EMAIL_VERIFICATION_MAX_ATTEMPTS - (int)$row['attempts'] - 1;
            $pdo->prepare('UPDATE email_verifications SET attempts = attempts + 1, used_at = CASE WHEN ? <= 0 THEN NOW() ELSE NULL END WHERE id = ?')->execute([$left, $row['id']]);
            $pdo->commit();
            return ['success' => false, 'message' => $left > 0
                ? "Incorrect code. {$left} attempt(s) left. If you received several emails, use the newest."
                : 'Too many incorrect attempts. Please request a new code.'];
        }
        $retire->execute([$row['id']]);
        if (empty($_SESSION['user_id'])) {
            // Nobody is signed in on the self-service page: credit the account that verified itself.
            jq_set_audit_vars($pdo, ['user_id' => $userId, 'username' => $user['username']]);
        }
        $pdo->prepare('UPDATE users SET is_verified = 1 WHERE id = ?')->execute([$userId]);
        $pdo->commit();
        return ['success' => true, 'message' => 'Email verified. ' . $user['username'] . ' can now sign in.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Verification failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Verification could not be completed. Please try again.'];
    }
}

/** Looks up a link's token. Only well-formed tokens reach the database; the raw token is never stored. */
function jq_verification_token_row(PDO $pdo, string $token, bool $lock = false): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $st = $pdo->prepare('SELECT ev.id, ev.email AS sent_to, ev.used_at, ev.expires_at <= NOW() AS expired,
                                u.id AS user_id, u.username, u.email, u.first_name, u.is_verified, u.is_archived, u.status, u.credentials_deleted_at
                           FROM email_verifications ev JOIN users u ON u.id = ev.user_id
                          WHERE ev.token_hash = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([hash('sha256', $token)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Checks a verification link and, when it is good, verifies the account in one transaction.
 * @return array{state: string, message: string} state is verified, already, expired or invalid
 */
function jq_verify_token(PDO $pdo, string $token): array
{
    $invalid = ['state' => 'invalid', 'message' => 'This verification link is not valid. It may be incomplete, or a newer email may have replaced it.'];
    $pdo->beginTransaction();
    try {
        $row = jq_verification_token_row($pdo, $token, true);
        if (!$row || $row['status'] !== 'active' || !empty($row['is_archived']) || !empty($row['credentials_deleted_at'])
            || strcasecmp((string)$row['sent_to'], (string)$row['email']) !== 0) {
            $pdo->rollBack();
            return $invalid;
        }
        if ((int)$row['is_verified'] === 1) {
            $pdo->rollBack();
            return ['state' => 'already', 'message' => 'This email address is already verified. You can sign in.'];
        }
        if ($row['used_at'] !== null) {
            $pdo->rollBack();
            return $invalid;
        }
        if ((int)$row['expired'] === 1) {
            $pdo->rollBack();
            return ['state' => 'expired', 'message' => 'This verification link has expired. You can have a new one sent to the same address.'];
        }
        $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        if (empty($_SESSION['user_id'])) {
            // Nobody is signed in when a link is opened: credit the account that verified itself.
            jq_set_audit_vars($pdo, ['user_id' => $row['user_id'], 'username' => $row['username']]);
        }
        $pdo->prepare('UPDATE users SET is_verified = 1 WHERE id = ?')->execute([$row['user_id']]);
        $pdo->commit();
        return ['state' => 'verified', 'message' => 'Your email address is verified. You can now sign in.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Link verification failed: ' . $e->getMessage());
        return ['state' => 'invalid', 'message' => 'Verification could not be completed. Please try again.'];
    }
}

/**
 * Sends a fresh email from an expired link. Only the account's newest link qualifies, and the email goes
 * only to the account's own address, so holding an old link gives nothing but a throttled resend.
 * @return array{success: bool, message: string, throttled?: bool}
 */
function jq_resend_from_token(PDO $pdo, string $token): array
{
    $row = jq_verification_token_row($pdo, $token);
    if (!$row || $row['used_at'] !== null || (int)$row['expired'] !== 1 || (int)$row['is_verified'] === 1
        || $row['status'] !== 'active' || !empty($row['is_archived']) || !empty($row['credentials_deleted_at'])
        || strcasecmp((string)$row['sent_to'], (string)$row['email']) !== 0) {
        return ['success' => false, 'message' => 'A new link cannot be sent from this one. Please sign in to request another.'];
    }
    return jq_send_verification($pdo, ['id' => $row['user_id'], 'username' => $row['username'], 'email' => $row['email'], 'first_name' => $row['first_name']]);
}
