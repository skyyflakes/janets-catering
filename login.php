<?php
/**
 * Login Page - Janet's Quality Catering System
 *
 * Two-step sign in:
 *   Step 1 - the Terms and Conditions are accepted, username + password are checked, then a 6-digit code is emailed with PHPMailer
 *   Step 2 - the user enters the emailed code (replaces the old captcha)
 */
require_once __DIR__ . '/config/session.php';
require_once 'config/database.php';
require_once 'config/email_config.php';
require_once 'includes/activity_logger.php';
require_once 'includes/account_security.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$error   = '';
$success = '';
$show_resend = false;

// Signed out by static/js/idle-logout.js
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['timeout'])) {
    $success = 'You were signed out after 30 minutes of inactivity. Please sign in again.';
}

// Message from a redirect (session ended, account deactivated, email changed ...)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($flash = getFlash())) {
    if ($flash['type'] === 'info' || $flash['type'] === 'success') {
        $success = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}

// ---------- helpers ----------
/** The Terms and Conditions text, shown in the window the first login page opens. */
function jq_terms_body(string $heading): string
{
    $sections = [
        ['1. Introduction', "<p>Welcome to Janet's Quality Catering System. By using this system, you agree to these terms.</p>"],
        ['2. User Accounts', '<ul><li>You are responsible for maintaining the confidentiality of your login credentials</li><li>You agree to provide accurate information</li><li>Unauthorized access to the system is strictly prohibited</li></ul>'],
        ['3. Data Privacy', '<ul><li>We collect and store information necessary for system operation</li><li>Personal data is protected and not shared with third parties</li><li>You have the right to request deletion of your account data</li></ul>'],
        ['4. System Usage', '<ul><li>The system is for authorized personnel only</li><li>All activities are logged for security purposes</li><li>Misuse may result in account suspension</li></ul>'],
        ['5. Contact', "<p>For questions, contact Janet's Quality Catering Services.</p><p>09178582572</p>"],
    ];
    $html = '';
    foreach ($sections as [$title, $body]) {
        $html .= "<{$heading}>{$title}</{$heading}>{$body}";
    }
    return $html;
}

function maskEmail($email) {
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $visible = substr($local, 0, min(2, strlen($local)));
    return $visible . str_repeat('*', max(strlen($local) - strlen($visible), 3)) . '@' . $domain;
}

/**
 * Generate a code, email it with PHPMailer and store only its hash in the session.
 * Returns true on success, or a readable error message on failure.
 */
function issueLoginCode(array $pending) {
    $code = generateEmailVerificationCode(EMAIL_VERIFICATION_CODE_LENGTH);
    $result = sendLoginCodeEmail($pending['email'], $pending['name'], $code);

    if (empty($result['success'])) {
        error_log('Login code email failed for user ' . $pending['user_id'] . ': ' . ($result['message'] ?? 'unknown error'));
        logActivity(null, 'LOGIN_FAILED', 'Authentication', 'The login code could not be emailed to ' . $pending['username'],
            ['user_id' => $pending['user_id'], 'username' => $pending['username'], 'role' => $pending['role'],
             'record_table' => 'users', 'record_id' => $pending['user_id'], 'record_label' => $pending['username']]);
        return 'We could not send the verification email. Please try again in a few minutes.';
    }

    $_SESSION['login_pending'] = [
        'user_id'   => $pending['user_id'],
        'username'  => $pending['username'],
        'role'      => $pending['role'],
        'name'      => $pending['name'],
        'email'     => $pending['email'],
        'code_hash' => jq_login_code_hash((int)$pending['user_id'], $pending['email'], $code),
        'expires'   => time() + (LOGIN_CODE_EXPIRY_MINUTES * 60),
        'attempts'  => 0,
        'last_sent' => time(),
    ];
    return true;
}

/** The account may have changed since the code was sent: it must still be active, verified and on the same email. */
function jq_login_still_allowed(array $pending): array {
    $pdo = getDBConnection();
    if (!$pdo) {
        return ['ok' => true, 'message' => '']; // built-in accounts keep working during a database outage
    }
    $st = $pdo->prepare('SELECT email, status, is_verified, is_archived, credentials_deleted_at FROM users WHERE id = ?');
    $st->execute([$pending['user_id']]);
    $row = $st->fetch();
    if (!$row || $row['status'] !== 'active' || empty($row['is_verified']) || !empty($row['is_archived']) || !empty($row['credentials_deleted_at'])) {
        return ['ok' => false, 'message' => 'This account cannot sign in right now. Please contact the Owner.'];
    }
    if (strcasecmp(trim((string)$row['email']), $pending['email']) !== 0) {
        return ['ok' => false, 'message' => 'The email on this account has changed. Please sign in again.'];
    }
    return ['ok' => true, 'message' => ''];
}

// ---------- request handling ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if (!jq_csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'login' && ($_POST['terms_accepted'] ?? '') !== '1') {
        // The browser also requires the box; this is the check that counts.
        unset($_SESSION['login_pending']);
        $error = 'You must agree to the Terms and Conditions before you can log in.';
    } elseif ($action === 'login') {
        // STEP 1: check credentials, then email the code
        unset($_SESSION['login_pending']);
        $username = sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $found = null;

        // Database login: the username box accepts either the username (e.g. "admin") or the account's email
        try {
            $pdo = getDBConnection();
            if ($pdo) {
                $stmt = $pdo->prepare(
                    "SELECT id, username, password, role, email, first_name
                       FROM users
                      WHERE username = ? OR email = ?
                      ORDER BY (username = ?) DESC, id ASC"
                );
                $stmt->execute([$username, $username, $username]);
                // First password match wins
                while ($user = $stmt->fetch()) {
                    if (!empty($user['password']) && jq_password_verify($password, $user['password'])) {
                        if (jq_password_needs_upgrade($user['password'])) { // move an older bcrypt hash to SHA-256
                            try {
                                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([jq_password_hash($password), $user['id']]);
                            } catch (Throwable $e) {
                                error_log('Password hash upgrade failed: ' . $e->getMessage());
                            }
                        }
                        $found = [
                            'user_id'  => $user['id'],
                            'username' => $user['username'],
                            'role'     => $user['role'],
                            'name'     => $user['first_name'] ?: $user['username'],
                            'email'    => trim($user['email'] ?? ''),
                        ];
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            // Missing table / bad DB credentials: log it and fall through to the built-in accounts below
            error_log('Login DB lookup failed: ' . $e->getMessage());
        }

        // Built-in fallback accounts (work even if the database is unavailable).
        // Passwords are stored as bcrypt hashes. The username is also the email that receives the code.
        if (!$found) {
            foreach (getFallbackAccounts() as $acc) {
                $nameMatches = strcasecmp($username, $acc['username']) === 0 || strcasecmp($username, $acc['alias']) === 0;
                if ($nameMatches && jq_password_verify($password, $acc['hash'])) {
                    $found = [
                        'user_id'  => $acc['user_id'],
                        'username' => $acc['username'],
                        'role'     => $acc['role'],
                        'name'     => $acc['role'] === 'OWNER' ? 'Owner' : 'Admin',
                        'email'    => $acc['username'],
                    ];
                    break;
                }
            }
        }

        // The code goes to the Gmail registered on this account, read from the database.
        // The built-in address is used only when the database is unavailable.
        if ($found) {
            $found['email'] = jq_registered_email($pdo ?? null, (int)$found['user_id'], $found['email']);
        }

        // Correct password, but is this account allowed in?
        $account_block = null;
        if ($found && !empty($pdo)) {
            try {
                $stmt = $pdo->prepare("SELECT status, is_verified, is_archived, credentials_deleted_at FROM users WHERE id = ?");
                $stmt->execute([$found['user_id']]);
                $state = $stmt->fetch();
                if ($state && !empty($state['credentials_deleted_at'])) {
                    // The built-in fallback password must not revive an account whose credentials were removed.
                    $account_block = 'Invalid username or password!';
                } elseif ($state && !empty($state['is_archived'])) {
                    $account_block = 'This account has been archived and can no longer sign in.';
                } elseif ($state && $state['status'] !== 'active') {
                    $account_block = 'This account has been deactivated. Please contact the Owner.';
                } elseif ($state && empty($state['is_verified'])) {
                    $account_block = 'Please verify your email address first. Open the link we emailed you, or enter the code from that email.';
                    $_SESSION['verify_pending_user'] = (int)$found['user_id']; // proven by the correct password
                    $show_resend = true;
                }
            } catch (Throwable $e) {
                error_log('Login account check failed: ' . $e->getMessage());
            }
        }

        if ($account_block !== null) {
            $error = $account_block;
            logActivity(null, 'LOGIN_FAILED', 'Authentication', 'Sign-in refused for ' . $found['username'] . ': ' . $account_block,
                ['user_id' => $found['user_id'], 'username' => $found['username'], 'role' => $found['role'],
                 'record_table' => 'users', 'record_id' => $found['user_id'], 'record_label' => $found['username']]);
        } elseif (!$found) {
            $error = 'Invalid username or password!';
            logActivity(null, 'LOGIN_FAILED', 'Authentication',
                'Failed sign-in attempt (wrong username or password) for "' . mb_substr(html_entity_decode($username, ENT_QUOTES), 0, 60) . '"',
                ['user_id' => null, 'username' => mb_substr($username, 0, 100), 'role' => null]);
        } elseif (!filter_var($found['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'No valid email address is registered for this account. Please contact the administrator.';
        } elseif (($mailResult = issueLoginCode($found)) !== true) {
            $error = $mailResult;
        } else {
            jq_record_terms_acceptance($pdo ?? null, (int)$found['user_id']);
            $success = 'A verification code was sent to ' . maskEmail($found['email']) . '.';
        }

    } elseif ($action === 'verify') {
        // STEP 2: check the emailed code
        $pending = $_SESSION['login_pending'] ?? null;
        $code_input = preg_replace('/\D/', '', $_POST['code'] ?? '');

        if (!$pending) {
            $error = 'Your session expired. Please sign in again.';
        } elseif (time() > $pending['expires']) {
            unset($_SESSION['login_pending']);
            $error = 'The code has expired. Please sign in again.';
        } elseif ($pending['attempts'] >= LOGIN_CODE_MAX_ATTEMPTS) {
            unset($_SESSION['login_pending']);
            $error = 'Too many wrong attempts. Please sign in again.';
        } elseif (!hash_equals($pending['code_hash'], jq_login_code_hash((int)$pending['user_id'], $pending['email'], $code_input))) {
            $_SESSION['login_pending']['attempts']++;
            $left = LOGIN_CODE_MAX_ATTEMPTS - $_SESSION['login_pending']['attempts'];
            logActivity(null, 'LOGIN_FAILED', 'Authentication',
                'Wrong email verification code for ' . $pending['username'] . ($left <= 0 ? ' – sign-in locked, too many attempts' : " ({$left} attempt(s) left)"),
                ['user_id' => $pending['user_id'], 'username' => $pending['username'], 'role' => $pending['role'],
                 'record_table' => 'users', 'record_id' => $pending['user_id'], 'record_label' => $pending['username']]);
            if ($left <= 0) {
                unset($_SESSION['login_pending']);
                $error = 'Too many wrong attempts. Please sign in again.';
            } else {
                $error = "Invalid code! {$left} attempt(s) remaining.";
            }
        } elseif (!($login_state = jq_login_still_allowed($pending))['ok']) {
            unset($_SESSION['login_pending']);
            $error = $login_state['message'];
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $pending['user_id'];
            $_SESSION['username'] = $pending['username'];
            $_SESSION['role']     = $pending['role'];
            unset($_SESSION['login_pending']);
            $_SESSION['login_success'] = true;
            $_SESSION['login_message'] = 'Welcome back, ' . $_SESSION['username'] . '!';
            logActivity(null, 'LOGIN', 'Authentication', 'User ' . $pending['username'] . ' signed in (password + email code verified)', [
                'user_id' => $pending['user_id'], 'username' => $pending['username'], 'role' => $pending['role'],
                'record_table' => 'users', 'record_id' => $pending['user_id'], 'record_label' => $pending['username'],
            ]);
            redirect('dashboard.php');
        }

    } elseif ($action === 'resend') {
        $pending = $_SESSION['login_pending'] ?? null;
        if (!$pending) {
            $error = 'Your session expired. Please sign in again.';
        } else {
            $wait = LOGIN_CODE_RESEND_SECONDS - (time() - $pending['last_sent']);
            if ($wait > 0) {
                $error = "Please wait {$wait} second(s) before requesting a new code.";
            } elseif (!($login_state = jq_login_still_allowed($pending))['ok']) {
                unset($_SESSION['login_pending']);
                $error = $login_state['message'];
            } elseif (($mailResult = issueLoginCode($pending)) !== true) {
                $error = $mailResult;
            } else {
                $success = 'A new code was sent to ' . maskEmail($pending['email']) . '.';
            }
        }

    } elseif ($action === 'cancel') {
        unset($_SESSION['login_pending']);
    }
}

// Drop an expired pending login so the user goes back to step 1
if (isset($_SESSION['login_pending']) && time() > $_SESSION['login_pending']['expires']) {
    unset($_SESSION['login_pending']);
    if ($error === '') { $error = 'The code has expired. Please sign in again.'; }
}

$pending     = $_SESSION['login_pending'] ?? null;
$verify_step = $pending !== null;
$resend_wait = $pending ? max(0, LOGIN_CODE_RESEND_SECONDS - (time() - $pending['last_sent'])) : 0;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login | Janet's Quality Catering</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root{--bs-primary:#9370DB;--bs-primary-dark:#7B5FC7;--border-color:#C8B8E8;--heading-color:#2D2D4A;--body-color:#4A4A6A;}
        *{margin:0;padding:0;box-sizing:border-box;}
        html,body{height:100%;}
        body{font-family:'Public Sans',sans-serif;background:url('static/images/logo.png') center/cover no-repeat fixed;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;position:relative;}
        body::before{content:'';position:fixed;inset:0;background:rgba(230,230,250,0.85);backdrop-filter:blur(8px);z-index:-1;}
        .auth-wrapper{width:100%;max-width:420px;}
        .auth-inner{background:#fff;border-radius:16px;box-shadow:0 8px 32px rgba(147,112,219,0.2);padding:40px;overflow:hidden;}
        .app-brand{display:flex;flex-direction:column;align-items:center;margin-bottom:24px;}
        .app-brand-logo{width:120px;height:120px;border-radius:16px;margin-bottom:16px;overflow:hidden;box-shadow:0 4px 12px rgba(147,112,219,0.3);}
        .app-brand-logo img{width:100%;height:100%;object-fit:cover;}
        .app-brand-text{font-size:1.25rem;font-weight:700;color:var(--heading-color);text-align:center;}
        .app-brand-text span{display:block;font-size:0.8rem;font-weight:500;color:var(--bs-primary);text-transform:uppercase;letter-spacing:1px;}
        .form-label{font-size:0.8125rem;font-weight:500;color:var(--heading-color);margin-bottom:6px;display:block;}
        .form-control{padding:12px 16px;border:1px solid var(--border-color);border-radius:8px;font-size:0.9375rem;background:#FAFAFF;color:var(--heading-color);width:100%;transition:border-color .15s,box-shadow .15s;}
        .form-control:focus{border-color:var(--bs-primary);box-shadow:0 0 0 3px rgba(147,112,219,0.15);background:#fff;outline:none;}
        .form-control::placeholder{color:#A0A0B8;}
        .input-group{position:relative;width:100%;}
        .input-group .form-control{padding-right:45px;}
        /* Hide native browser password reveal icons (Edge/IE) so only one custom eye icon shows */
        .input-group .form-control::-ms-reveal,
        .input-group .form-control::-ms-clear{display:none;}
        .password-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--body-color);cursor:pointer;z-index:10;padding:0;}
        .password-toggle:hover{color:var(--bs-primary);}
        .btn-primary{background:linear-gradient(135deg,var(--bs-primary) 0%,#8B5CF6 100%);border:none;padding:14px 24px;font-weight:600;font-size:0.9375rem;border-radius:8px;width:100%;color:#fff;cursor:pointer;transition:all .2s;}
        .btn-primary:hover{background:linear-gradient(135deg,var(--bs-primary-dark) 0%,#7C3AED 100%);box-shadow:0 4px 16px rgba(147,112,219,0.4);transform:translateY(-1px);}
        .mb-3{margin-bottom:1rem;}
        .otp-container{background:#F8F6FF;border:1px solid var(--border-color);border-radius:10px;padding:18px;margin-bottom:16px;}
        .otp-section{background:#fff;border:1px solid var(--border-color);border-radius:6px;padding:14px;}
        .otp-section label{font-size:0.7rem;font-weight:600;color:var(--body-color);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:block;}
        .otp-info{font-size:.8125rem;color:var(--body-color);margin-bottom:12px;text-align:center;}
        .otp-info strong{color:var(--heading-color);}
        .otp-input{width:100%;padding:12px 14px;border:1px solid var(--border-color);border-radius:6px;font-size:1.4rem;font-family:'Courier New',monospace;font-weight:bold;text-align:center;letter-spacing:10px;background:#FAFAFF;}
        .otp-input:focus{outline:none;border-color:var(--bs-primary);box-shadow:0 0 0 2px rgba(147,112,219,0.15);}
        .otp-links{display:flex;justify-content:space-between;align-items:center;margin-top:12px;font-size:.8125rem;}
        .link-btn{background:none;border:none;padding:0;color:var(--bs-primary);font-weight:500;cursor:pointer;font-size:.8125rem;}
        .link-btn:disabled{color:#A0A0B8;cursor:not-allowed;}
        .terms-error{color:#B3261E;font-size:.8125rem;font-weight:500;margin-top:6px;}
        .form-check-input.is-required{border-color:#B3261E;}
        .form-check{padding-left:1.75rem;min-height:0;}
        .form-check-input{width:1.125rem;height:1.125rem;margin-left:-1.75rem;border-color:var(--border-color);cursor:pointer;}
        .form-check-input:checked{background-color:var(--bs-primary);border-color:var(--bs-primary);}
        .form-check-label{font-size:.8125rem;color:var(--heading-color);cursor:pointer;}
        .form-check-label a{color:var(--bs-primary);text-decoration:none;font-weight:500;}
        .modal-content{border:none;border-radius:12px;}
        .modal-header{border-bottom:1px solid var(--border-color);padding:20px 24px;}
        .modal-title{font-weight:600;color:var(--heading-color);}
        .modal-body{padding:24px;max-height:60vh;overflow-y:auto;}
        .modal-body h5{color:var(--heading-color);font-weight:600;margin:20px 0 10px;}
        .modal-body ul{padding-left:20px;}
        .modal-body li{margin-bottom:6px;font-size:.875rem;}
        .modal-footer{border-top:1px solid var(--border-color);padding:16px 24px;}
        .modal-footer .btn{width:auto;}
        .swal2-popup{font-family:'Public Sans',sans-serif !important;border-radius:16px !important;background:#1e1b2e !important;color:#e0d9f7 !important;border:1px solid rgba(147,112,219,0.2) !important;}
        .swal2-title{color:#e0d9f7 !important;font-weight:700 !important;}
        .swal2-html-container{color:#a89cc8 !important;}
        .swal2-icon{border:none !important;width:80px !important;height:80px !important;}
        .swal2-icon.swal2-error{background:rgba(255,60,80,0.12) !important;border-radius:50% !important;}
        .swal2-icon.swal2-error .swal2-x-mark-line-left,.swal2-icon.swal2-error .swal2-x-mark-line-right{background:#ff3c50 !important;}
        .swal2-icon.swal2-success{background:rgba(0,200,120,0.12) !important;border-radius:50% !important;}
        .swal2-icon.swal2-success .swal2-success-ring{border:4px solid rgba(0,200,120,0.35) !important;width:80px !important;height:80px !important;top:0 !important;left:0 !important;}
        .swal2-icon.swal2-success [class^='swal2-success-line']{background:#00c878 !important;border-radius:4px !important;height:5px !important;}
        .swal2-icon.swal2-success .swal2-success-line-tip{width:24px !important;top:44px !important;left:13px !important;}
        .swal2-icon.swal2-success .swal2-success-line-long{width:40px !important;top:38px !important;right:9px !important;}
        .swal2-icon.swal2-success .swal2-success-fix{background:#1e1b2e !important;}
        .swal2-confirm{background:linear-gradient(135deg,#9370DB 0%,#8B5CF6 100%) !important;border:none !important;border-radius:8px !important;font-weight:600 !important;padding:10px 28px !important;box-shadow:0 4px 15px rgba(147,112,219,0.4) !important;}
        .swal2-timer-progress-bar{background:linear-gradient(135deg,#9370DB,#8B5CF6) !important;}
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-inner">
            <div class="app-brand">
                <div class="app-brand-logo">
                    <img src="static/images/logo.png" alt="Janet's Catering Logo">
                </div>
                <div class="app-brand-text">
                    Janet's Quality
                    <span>Catering Services</span>
                </div>
            </div>

            <?php if (!$verify_step): ?>
            <!-- STEP 1: credentials -->
            <form method="POST" id="loginForm"><?php echo jq_csrf_field(); ?>
                <input type="hidden" name="action" value="login">

                <div class="mb-3">
                    <label class="form-label" for="loginUsername">USERNAME:</label>
                    <input type="text" class="form-control" id="loginUsername" name="username" placeholder="Enter your username" required
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" autofocus>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">PASSWORD:</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="Enter your password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Show or hide password">
                            <i class="bx bx-show" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="terms_accepted" id="termsAccepted" value="1" aria-describedby="termsError" <?php echo ($_POST['terms_accepted'] ?? '') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="termsAccepted">I agree to the <a href="#" onclick="openTermsModal(event)">Terms and Conditions</a>.</label>
                    </div>
                    <div class="terms-error" id="termsError" role="alert" style="display:none;">You must agree to the Terms and Conditions before you can log in.</div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bx bx-log-in me-2"></i>Sign In
                </button>
            </form>
            <?php if (!empty($show_resend)): ?>
            <a href="verify_email.php" class="btn btn-outline-secondary w-100 mt-3">
                <i class="bx bx-envelope me-2"></i>Enter code or get a new email
            </a>
            <?php endif; ?>
            <?php else: ?>
            <!-- STEP 2: emailed verification code (replaces the captcha) -->
            <form method="POST" id="verifyForm"><?php echo jq_csrf_field(); ?>
                <input type="hidden" name="action" value="verify">

                <div class="otp-container">
                    <div class="otp-section">
                        <label>Email verification code</label>
                        <div class="otp-info">
                            We sent a <?php echo (int) EMAIL_VERIFICATION_CODE_LENGTH; ?>-digit code to<br>
                            <strong><?php echo htmlspecialchars(maskEmail($pending['email'])); ?></strong>
                        </div>
                        <input type="text" class="otp-input" name="code" inputmode="numeric" pattern="[0-9]*"
                               maxlength="<?php echo (int) EMAIL_VERIFICATION_CODE_LENGTH; ?>"
                               placeholder="------" autocomplete="one-time-code" required autofocus>
                        <div class="otp-links">
                            <button type="submit" form="resendForm" class="link-btn" id="resendBtn"
                                    data-wait="<?php echo (int) $resend_wait; ?>">Resend code</button>
                            <button type="submit" form="cancelForm" class="link-btn">Use a different account</button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bx bx-log-in me-2"></i>Verify &amp; Sign In
                </button>
            </form>
            <form method="POST" id="resendForm"><?php echo jq_csrf_field(); ?><input type="hidden" name="action" value="resend"></form>
            <form method="POST" id="cancelForm"><?php echo jq_csrf_field(); ?><input type="hidden" name="action" value="cancel"></form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$verify_step): ?>
    <!-- Terms Modal: opened from the first login page -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="termsModalTitle">Terms and Conditions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body"><?php echo jq_terms_body('h5'); ?></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="acceptTerms()">I Understand</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openTermsModal(e) {
            e.preventDefault();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('termsModal')).show();
        }

        function acceptTerms() {
            var checkbox = document.getElementById('termsAccepted');
            if (checkbox) {
                checkbox.checked = true;
                checkbox.dispatchEvent(new Event('change'));
            }
            bootstrap.Modal.getInstance(document.getElementById('termsModal')).hide();
        }

        function togglePassword() {
            const pw = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            if (pw.type === 'password') {
                pw.type = 'text';
                icon.classList.replace('bx-show','bx-hide');
            } else {
                pw.type = 'password';
                icon.classList.replace('bx-hide','bx-show');
            }
        }

        // Sign In stays blocked until the box is ticked. The server checks this too.
        document.addEventListener('DOMContentLoaded', function() {
            var form = document.getElementById('loginForm');
            if (!form) return;
            var box = document.getElementById('termsAccepted'), message = document.getElementById('termsError');
            function show(on) {
                message.style.display = on ? 'block' : 'none';
                box.classList.toggle('is-required', on);
            }
            form.addEventListener('submit', function(e) {
                if (!box.checked) {
                    e.preventDefault();
                    show(true);
                    box.focus();
                }
            });
            box.addEventListener('change', function() { if (box.checked) show(false); });
        });

        // Digits only in the code box + resend countdown
        document.addEventListener('DOMContentLoaded', function() {
            var codeInput = document.querySelector('.otp-input');
            if (codeInput) {
                codeInput.addEventListener('input', function() {
                    this.value = this.value.replace(/\D/g, '');
                });
            }
            var btn = document.getElementById('resendBtn');
            if (btn) {
                var wait = parseInt(btn.dataset.wait, 10) || 0;
                var label = 'Resend code';
                function tick() {
                    if (wait > 0) {
                        btn.disabled = true;
                        btn.textContent = label + ' (' + wait + 's)';
                        wait--;
                        setTimeout(tick, 1000);
                    } else {
                        btn.disabled = false;
                        btn.textContent = label;
                    }
                }
                tick();
            }
        });

        <?php if (!empty($error)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                imageUrl: 'static/images/logo.png',
                imageWidth: 80,
                imageHeight: 80,
                imageAlt: "Janet's Catering",
                title: <?php echo json_encode($verify_step ? 'Verification Failed' : 'Login Failed'); ?>,
                text: <?php echo json_encode($error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                confirmButtonColor: '#9370DB',
                confirmButtonText: 'Try Again'
            });
        });
        <?php endif; ?>
        <?php if (!empty($success)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                imageUrl: 'static/images/logo.png',
                imageWidth: 80,
                imageHeight: 80,
                imageAlt: "Janet's Catering",
                title: 'Code Sent!',
                text: <?php echo json_encode($success, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                confirmButtonColor: '#9370DB'
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>