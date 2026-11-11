<?php
/**
 * Email Verification Page - Janet's Quality Catering System
 *
 * The emailed link opens this page with ?token=... and verifies the account; holding the link is the proof.
 * The code from the same email can be entered here instead, after login.php accepts the account's
 * password (or after changing an email on Profile), which proves who is asking.
 */
require_once __DIR__ . '/config/session.php';
require_once 'config/database.php';
require_once 'includes/activity_logger.php';
require_once 'includes/account_security.php';

$pdo = getDBConnection();
if ($pdo) {
    jq_ensure_audit_schema($pdo);
}

$state = 'form';  // form | done | signin | link_ok | link_already | link_expired | link_invalid
$error = '';
$notice = '';
$user = null;
$userId = (int)($_SESSION['verify_pending_user'] ?? 0);
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');

if ($token !== '') {
    // The address holds a secret until it is used: keep it out of other sites' logs and browser caches.
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}

if (!$pdo) {
    $state = 'signin';
    $error = 'The system is temporarily unavailable. Please try again later.';
} elseif ($token !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // A new email requested from an expired link
    $state = 'link_expired';
    if (!jq_csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $res = jq_resend_from_token($pdo, $token);
        if ($res['success']) {
            $state = 'link_sent';
            $notice = $res['message'] . ' Earlier links and codes no longer work.';
        } else {
            $error = $res['message'];
        }
    }
} elseif ($token !== '') {
    $res = jq_verify_token($pdo, $token);
    $state = ['verified' => 'link_ok', 'already' => 'link_already', 'expired' => 'link_expired'][$res['state']] ?? 'link_invalid';
    if ($state === 'link_ok' || $state === 'link_already') {
        $notice = $res['message'];
        unset($_SESSION['verify_pending_user']);
    } else {
        $error = $res['message'];
    }
} elseif ($userId <= 0) {
    $state = 'signin';
} else {
    $st = $pdo->prepare('SELECT id, username, email, first_name, is_verified FROM users WHERE id = ?');
    $st->execute([$userId]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        unset($_SESSION['verify_pending_user']);
        $state = 'signin';
    } elseif ((int)$user['is_verified'] === 1) {
        unset($_SESSION['verify_pending_user']);
        $state = 'done';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!jq_csrf_valid($_POST['csrf_token'] ?? null)) {
            $error = 'Your session expired. Please try again.';
        } elseif (($_POST['action'] ?? '') === 'resend') {
            $res = jq_send_verification($pdo, $user);
            if ($res['success']) {
                $notice = $res['message'] . ' Earlier links and codes no longer work.';
            } else {
                $error = $res['message'];
            }
        } else {
            $res = jq_verify_code($pdo, $userId, (string)($_POST['code'] ?? ''));
            if ($res['success']) {
                unset($_SESSION['verify_pending_user']);
                $state = 'done';
            } else {
                $error = $res['message'];
                logActivity($pdo, 'VERIFY_FAILED', 'Authentication', 'Wrong email verification code for ' . $user['username'],
                    ['user_id' => $userId, 'username' => $user['username'], 'record_table' => 'users', 'record_id' => $userId, 'record_label' => $user['username']]);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify email | Janet's Quality Catering</title>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css">
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box;
               background: #F0E6FA; color: #1A1A2E; font-family: 'Public Sans', 'Segoe UI', sans-serif; }
        .box { background: #fff; border: 1px solid #D8C8E8; border-radius: 12px; padding: 32px; max-width: 440px; width: 100%; text-align: center; box-sizing: border-box; }
        .box img { width: 64px; height: 64px; border-radius: 10px; object-fit: cover; }
        .box > i { display: block; font-size: 2.6rem; color: #7B5FC7; margin: 14px 0 6px; }
        h1 { font-size: 1.35rem; margin: 0 0 8px; }
        p { color: #5E5A78; margin: 0 0 20px; line-height: 1.5; font-size: .95rem; }
        .msg { border-radius: 8px; padding: 10px 12px; font-size: .9rem; margin: 0 0 16px; }
        .msg.err { background: #FBEBEB; color: #A03A3A; }
        .msg.ok { background: #E4F4EC; color: #1F6B47; }
        input { width: 100%; box-sizing: border-box; padding: 12px; font-size: 1.4rem; letter-spacing: .4em; text-align: center; border: 1px solid #CDBDE3; border-radius: 8px; margin-bottom: 14px; }
        input:focus { outline: 2px solid #9370DB; }
        .btn { display: block; width: 100%; box-sizing: border-box; background: #7B5FC7; color: #fff; border: 1px solid #7B5FC7; border-radius: 8px; padding: 11px 26px; font: 600 .95rem inherit; text-decoration: none; cursor: pointer; }
        .btn:hover { background: #6A4FB5; }
        .btn.alt { background: #F1F2F6; color: #3F4656; border-color: #D5D9E2; margin-top: 10px; }
        .btn.alt:hover { background: #E5E7ED; }
    </style>
</head>
<body>
<div class="box">
    <img src="static/images/logo.png" alt="Janet's Catering">
    <?php if ($state === 'link_ok' || $state === 'link_already'): ?>
        <i class="bx bx-check-circle"></i>
        <h1><?php echo $state === 'link_ok' ? 'Email verified' : 'Already verified'; ?></h1>
        <div class="msg ok" role="status"><?php echo jq_e($notice); ?></div>
        <a class="btn" href="login.php">Go to sign in</a>
    <?php elseif ($state === 'link_sent'): ?>
        <i class="bx bx-mail-send"></i>
        <h1>Check your email</h1>
        <div class="msg ok" role="status"><?php echo jq_e($notice); ?></div>
        <a class="btn alt" href="login.php">Back to sign in</a>
    <?php elseif ($state === 'link_expired'): ?>
        <i class="bx bx-time-five"></i>
        <h1>Link expired</h1>
        <div class="msg err" role="alert"><?php echo jq_e($error); ?></div>
        <form method="POST" action="verify_email.php">
            <?php echo jq_csrf_field(); ?>
            <input type="hidden" name="token" value="<?php echo jq_e($token); ?>">
            <button type="submit" class="btn">Send a new link</button>
        </form>
        <a class="btn alt" href="login.php">Back to sign in</a>
    <?php elseif ($state === 'link_invalid'): ?>
        <i class="bx bx-error-circle"></i>
        <h1>Link not valid</h1>
        <div class="msg err" role="alert"><?php echo jq_e($error); ?></div>
        <p>Use the newest email, or sign in with your username and password to get a new one.</p>
        <a class="btn" href="login.php">Go to sign in</a>
    <?php elseif ($state === 'done'): ?>
        <i class="bx bx-check-circle"></i>
        <h1>Email verified</h1>
        <p>Your account is verified. You can now sign in.</p>
        <a class="btn" href="login.php">Go to sign in</a>
    <?php elseif ($state === 'signin'): ?>
        <i class="bx bx-log-in-circle"></i>
        <h1>Please sign in first</h1>
        <?php if ($error): ?><div class="msg err" role="alert"><?php echo jq_e($error); ?></div><?php endif; ?>
        <p>Sign in with your username and password. If your email is not verified yet, you will be brought back here.</p>
        <a class="btn" href="login.php">Go to sign in</a>
    <?php else: ?>
        <i class="bx bx-envelope"></i>
        <h1>Verify your email</h1>
        <?php if ($error): ?><div class="msg err" role="alert"><?php echo jq_e($error); ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="msg ok" role="status"><?php echo jq_e($notice); ?></div><?php endif; ?>
        <p>Open the link in the email sent to <strong><?php echo jq_e(jq_mask_email((string)$user['email'])); ?></strong>, or enter the <?php echo (int)EMAIL_VERIFICATION_CODE_LENGTH; ?>-digit code from it here. Check your spam folder if you do not see it.</p>
        <form method="POST">
            <?php echo jq_csrf_field(); ?>
            <input type="hidden" name="action" value="verify">
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="<?php echo (int)EMAIL_VERIFICATION_CODE_LENGTH; ?>" pattern="[0-9]*" aria-label="Verification code" required autofocus>
            <button type="submit" class="btn">Verify my email</button>
        </form>
        <form method="POST">
            <?php echo jq_csrf_field(); ?>
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="btn alt">Send a new email</button>
        </form>
        <a class="btn alt" href="login.php">Back to sign in</a>
    <?php endif; ?>
</div>
</body>
</html>
