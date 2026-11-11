<?php
/**
 * Email Configuration for Janet's Quality Catering Services
 * Using Gmail SMTP with App Password (TRUE Email Sending)
 * 
 * ===================================================================
 * GMAIL SMTP SETUP INSTRUCTIONS
 * ===================================================================
 * 
 * STEP 1: Enable 2-Step Verification on your Google Account
 *   - Go to https://myaccount.google.com/security
 *   - Click "2-Step Verification" and follow the setup
 * 
 * STEP 2: Generate an App Password
 *   - After enabling 2-Step Verification, go to:
 *     https://myaccount.google.com/apppasswords
 *   - Or: Google Account > Security > App passwords
 *   - Select "Mail" for the app
 *   - Select "Other (Custom name)" for the device
 *   - Name it "Janet's Catering" or similar
 *   - Click "Generate"
 *   - You'll get a 16-character password (e.g., "abcd efgh ijkl mnop")
 *   - COPY THIS PASSWORD - You won't see it again!
 * 
 * STEP 3: Update Configuration Below
 *   - Set SMTP_USERNAME to your Gmail address
 *   - Set SMTP_PASSWORD to the 16-character App Password (no spaces)
 *   - Set SMTP_FROM_EMAIL to your Gmail address
 * 
 * IMPORTANT NOTES:
 *   - Do NOT use your regular Gmail password
 *   - The 16-character App Password is specific to this application
 *   - If you see "Less secure app access" settings, ignore it - App Passwords bypass this
 * 
 * ===================================================================
 */

// ==================== GMAIL SMTP CONFIGURATION ====================
if (!defined('SMTP_HOST')) define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));                                              // TLS Port
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', getenv('SMTP_SECURE') !== false ? (string)getenv('SMTP_SECURE') : 'tls');                                          // Use 'tls' for port 587
if (!defined('SMTP_USERNAME')) define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: 'veranosean91@gmail.com');                        // Gmail that OWNS the App Password (was misspelled "gmaill.com")
// The Gmail App Password is kept outside the web folder (C:\xampp\janets_catering_private\mail_secret.php).
// Set JQ_PRIVATE_DIR to use another folder. See that file to change the password.
$jq_private_dir = getenv('JQ_PRIVATE_DIR') ?: dirname(__DIR__, 3) . '/janets_catering_private';
$jq_mail_secret = is_file($jq_private_dir . '/mail_secret.php') ? include $jq_private_dir . '/mail_secret.php' : [];
// When hosted (Vercel), the App Password comes from the SMTP_PASSWORD environment variable instead of the file.
// SMTP_HOST, SMTP_PORT, SMTP_SECURE, SMTP_USERNAME and SMTP_FROM_EMAIL can be set the same way.
if (!defined('SMTP_PASSWORD')) { define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: (is_array($jq_mail_secret) ? (string)($jq_mail_secret['smtp_password'] ?? '') : '')); }
unset($jq_mail_secret, $jq_private_dir);
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: (getenv('SMTP_USERNAME') ?: 'veranosean91@gmail.com'));                           // Always the same as SMTP_USERNAME (Gmail rejects/rewrites other senders)
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', "Janet's Catering");                  // Display name for emails

// Troubleshooting switches
define('SMTP_DEBUG', false);               // true = login page shows the exact mail error and logs the SMTP conversation (troubleshooting only)
define('SMTP_ALLOW_SELF_SIGNED', false);    // set true ONLY on local XAMPP/WAMP if you get an SSL certificate verify error

// ==================== EMAIL SETTINGS ====================
// Address people open the system at, without a trailing slash. Verification links are built from it, never from
// the request, so a forged Host header cannot send a token elsewhere. Change it when the system moves to a real domain.
// Address used in emailed links. When hosted, set APP_BASE_URL (e.g. https://janets-catering.vercel.app).
if (!defined('APP_BASE_URL')) define('APP_BASE_URL', getenv('APP_BASE_URL') ?: 'http://localhost/VERANO');
define('EMAIL_VERIFICATION_EXPIRY', 30);            // Minutes until an account verification link and code expire
define('EMAIL_VERIFICATION_MAX_ATTEMPTS', 5);       // Wrong tries before that code stops working
define('EMAIL_VERIFICATION_RESEND_SECONDS', 60);    // Wait time between verification emails
define('EMAIL_SEND_ATTEMPTS', 3);                   // Tries per email when the mail server has a temporary problem
define('EMAIL_VERIFICATION_CODE_LENGTH', 6);        // 6-digit login code
define('LOGIN_CODE_EXPIRY_MINUTES', 5);             // Minutes until a LOGIN code expires
define('LOGIN_CODE_MAX_ATTEMPTS', 5);               // Wrong-code attempts before a new code is required
define('LOGIN_CODE_RESEND_SECONDS', 60);            // Wait time between resend requests

/** Time shown in email subjects. Gmail stacks same-subject mail into one conversation, which can look like nothing arrived. */
function emailSubjectTime() {
    return (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('g:i A');
}

// ==================== PHPMAILER LOADER ====================
/**
 * Load PHPMailer (https://github.com/PHPMailer/PHPMailer)
 * Looks for the bundled copy in /phpmailer first, then Composer's autoloader.
 *
 * @return bool True if PHPMailer is available
 */
function loadPHPMailer() {
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) {
        return true;
    }
    $bundled = __DIR__ . '/../phpmailer/';
    if (file_exists($bundled . 'PHPMailer.php')) {
        require_once $bundled . 'Exception.php';
        require_once $bundled . 'PHPMailer.php';
        require_once $bundled . 'SMTP.php';
        return true;
    }
    $composer = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($composer)) {
        require_once $composer;
        return true;
    }
    return false;
}

// ==================== MAIN EMAIL FUNCTION ====================
/**
 * Send email using Gmail SMTP
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $body HTML email body
 * @param string $altBody Plain text alternative (optional)
 * @return array Response with success status
 */
function sendEmail($to, $subject, $body, $altBody = '') {
    // Check if email is valid
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'Invalid email address format.',
            'error_type' => 'validation'
        ];
    }
    
    // Check if SMTP is configured
    if (strpos(SMTP_USERNAME, 'your-email') !== false || empty(SMTP_USERNAME)) {
        return [
            'success' => false,
            'message' => 'Gmail SMTP not configured. Please set your Gmail and App Password in config/email_config.php',
            'config_needed' => true,
            'instructions' => [
                '1. Enable 2-Step Verification on your Google Account',
                '2. Generate an App Password at https://myaccount.google.com/apppasswords',
                '3. Update SMTP_USERNAME and SMTP_PASSWORD in config/email_config.php'
            ]
        ];
    }
    
    // Use PHPMailer (bundled in /phpmailer or installed via Composer in /vendor)
    if (loadPHPMailer()) {
        return sendEmailWithPHPMailer($to, $subject, $body, $altBody);
    }
    return sendEmailNative($to, $subject, $body);
}

/**
 * Send email using PHPMailer (Recommended)
 * Install via: composer require phpmailer/phpmailer
 */
function sendEmailWithPHPMailer($to, $subject, $body, $altBody = '') {
    // Gmail sometimes answers "421 Temporary System Problem" or drops a connection. Those clear on
    // their own, so retry them; a wrong password or rejected address will not, so stop at once.
    $result = [];
    for ($attempt = 1; $attempt <= EMAIL_SEND_ATTEMPTS; $attempt++) {
        $result = sendEmailOnceWithPHPMailer($to, $subject, $body, $altBody);
        if ($result['success']) {
            return $result;
        }
        error_log("Email to $to failed (attempt $attempt): " . $result['message']);
        $temporary = preg_match('/connect|data not accepted|temporar|timed? ?out|\b4\d\d\b/i', $result['message'])
            && stripos($result['message'], 'authenticate') === false;
        if (!$temporary || $attempt === EMAIL_SEND_ATTEMPTS) {
            break;
        }
        sleep(2 * $attempt);
    }
    return $result;
}

function sendEmailOnceWithPHPMailer($to, $subject, $body, $altBody = '') {
    loadPHPMailer();
    $mail = null;
    
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = str_replace(' ', '', SMTP_PASSWORD);
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;

        // Local-server SSL workaround (XAMPP/WAMP without CA certificates)
        if (SMTP_ALLOW_SELF_SIGNED) {
            $mail->SMTPOptions = ['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]];
        }
        
        // Timeout settings
        $mail->Timeout = 30;

        // When SMTP_DEBUG is on, write the full SMTP conversation to the PHP error log
        if (SMTP_DEBUG) {
            $mail->SMTPDebug   = 2;
            $mail->Debugoutput = function ($str, $level) {
                error_log('PHPMailer: ' . trim($str));
            };
        }
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $altBody ?: strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
        $mail->CharSet = 'UTF-8';
        
        $mail->send();
        
        return [
            'success' => true,
            'message' => 'Email sent successfully!',
            'provider' => 'phpmailer'
        ];
        
    } catch (PHPMailer\PHPMailer\Exception $e) {
        return [
            'success' => false,
            'message' => 'PHPMailer Error: ' . ($mail ? $mail->ErrorInfo : $e->getMessage()),
            'error_type' => 'phpmailer'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Exception: ' . $e->getMessage(),
            'error_type' => 'exception'
        ];
    }
}

/**
 * Send email using native PHP SMTP socket
 * Fallback when PHPMailer is not available
 */
function sendEmailNative($to, $subject, $body) {
    try {
        // Connect to Gmail SMTP server via SSL
        $socket = @fsockopen('ssl://' . SMTP_HOST, 465, $errno, $errstr, 30);
        
        if (!$socket) {
            return [
                'success' => false,
                'message' => "Could not connect to SMTP server: {$errstr} ({$errno})",
                'error_type' => 'connection'
            ];
        }
        
        // Set stream timeout
        stream_set_timeout($socket, 30);
        
        // Read server greeting
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '220') {
            fclose($socket);
            return [
                'success' => false,
                'message' => "SMTP connection failed: {$response}",
                'error_type' => 'smtp'
            ];
        }
        
        // Send EHLO
        fputs($socket, "EHLO " . gethostname() . "\r\n");
        do {
            $line = fgets($socket, 515);
        } while (substr($line, 3, 1) == '-');
        
        // Authenticate
        fputs($socket, "AUTH LOGIN\r\n");
        fgets($socket, 515);
        
        fputs($socket, base64_encode(SMTP_USERNAME) . "\r\n");
        fgets($socket, 515);
        
        $password = str_replace(' ', '', SMTP_PASSWORD);
        fputs($socket, base64_encode($password) . "\r\n");
        $authResponse = fgets($socket, 515);
        
        if (substr($authResponse, 0, 3) != '235') {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'Authentication failed. Check your Gmail and App Password.',
                'error_type' => 'auth',
                'hint' => 'Make sure you are using a 16-character App Password, not your regular Gmail password.'
            ];
        }
        
        // Set sender
        fputs($socket, "MAIL FROM:<" . SMTP_FROM_EMAIL . ">\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '250') {
            fclose($socket);
            return ['success' => false, 'message' => "MAIL FROM failed: {$response}", 'error_type' => 'smtp'];
        }
        
        // Set recipient
        fputs($socket, "RCPT TO:<{$to}>\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '250') {
            fclose($socket);
            return ['success' => false, 'message' => "RCPT TO failed: {$response}", 'error_type' => 'smtp'];
        }
        
        // Send DATA command
        fputs($socket, "DATA\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '354') {
            fclose($socket);
            return ['success' => false, 'message' => "DATA failed: {$response}", 'error_type' => 'smtp'];
        }
        
        // Build email headers and body
        $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
        $headers .= "To: {$to}\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "\r\n";
        
        // Send headers and body
        fputs($socket, $headers . $body . "\r\n.\r\n");
        $response = fgets($socket, 515);
        
        // Close connection
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        
        if (substr($response, 0, 3) != '250') {
            return ['success' => false, 'message' => "Send failed: {$response}", 'error_type' => 'smtp'];
        }
        
        return [
            'success' => true,
            'message' => 'Email sent successfully!',
            'provider' => 'native_smtp'
        ];
        
    } catch (Exception $e) {
        if (isset($socket) && $socket) {
            fclose($socket);
        }
        return [
            'success' => false,
            'message' => 'Exception: ' . $e->getMessage(),
            'error_type' => 'exception'
        ];
    }
}

// ==================== VERIFICATION EMAIL FUNCTIONS ====================

/**
 * Generate verification code
 */
function generateEmailVerificationCode($length = 6) {
    $min = pow(10, $length - 1);
    $max = pow(10, $length) - 1;
    return (string) random_int($min, $max);
}

/**
 * Send the account verification code (new accounts, resend, changed email address).
 *
 * @param string $email Recipient email
 * @param string $name Recipient name
 * @param string $username The account's username
 * @param string $code The verification code
 * @return array Response with success status
 */
function sendAccountVerificationEmail($email, $name, $username, $code, $link = '') {
    $subject = "Verify your email - Janet's Quality Catering (" . emailSubjectTime() . ")";
    $safeName = htmlspecialchars($name ?: 'there', ENT_QUOTES, 'UTF-8');
    $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $minutes  = EMAIL_VERIFICATION_EXPIRY;
    $linkHtml = $link === '' ? '' :
        '<p style="text-align:center;margin:0 0 24px;"><a href="' . $safeLink . '" style="display:inline-block;background:#7B5FC7;color:#ffffff;text-decoration:none;font-weight:700;font-size:16px;padding:14px 32px;border-radius:8px;">Verify my email</a></p>'
      . '<p style="color:#777;font-size:13px;line-height:1.5;margin:0 0 24px;">If the button does not work, copy this address into your browser:<br><a href="' . $safeLink . '" style="color:#7B5FC7;word-break:break-all;">' . $safeLink . '</a></p>'
      . '<p style="color:#555;font-size:14px;margin:0 0 10px;">Or enter this code on the verification page:</p>';
    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
      . '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f4f4f4;">'
      . '<table role="presentation" style="width:100%;border-collapse:collapse;"><tr><td style="padding:40px 20px;">'
      . '<table role="presentation" style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1);">'
      . '<tr><td style="background:linear-gradient(135deg,#9370DB 0%,#8B5CF6 100%);padding:32px 30px;text-align:center;">'
      . '<h1 style="color:#fff;margin:0;font-size:26px;">Janet\'s Quality Catering</h1>'
      . '<p style="color:rgba(255,255,255,.9);margin:8px 0 0;font-size:14px;">Email Verification</p></td></tr>'
      . '<tr><td style="padding:36px 30px;">'
      . '<p style="color:#333;font-size:16px;margin:0 0 16px;">Hello <strong>' . $safeName . '</strong>,</p>'
      . '<p style="color:#555;font-size:15px;line-height:1.6;margin:0 0 24px;">Please verify the email address for the account <strong>' . $safeUser . '</strong>. The account cannot sign in until this is done.</p>'
      . $linkHtml
      . '<div style="background:#f8f5ff;border:2px solid #9370DB;border-radius:12px;padding:18px;text-align:center;margin:0 0 24px;">'
      . '<span style="font-size:30px;font-weight:700;letter-spacing:8px;color:#7B5FC7;font-family:monospace;">' . $safeCode . '</span></div>'
      . '<p style="color:#555;font-size:14px;margin:0 0 12px;"><strong>This link and code expire in ' . $minutes . ' minutes and work only once.</strong> If you received several emails, use the newest.</p>'
      . '<p style="color:#777;font-size:13px;margin:0;">If you did not expect this email, you can ignore it.</p>'
      . '</td></tr></table></td></tr></table></body></html>';
    $alt = "Hello {$name},\n\nPlease verify the email address for the account {$username}.\n\n"
         . ($link === '' ? '' : "Open this link to verify:\n{$link}\n\nOr enter this code on the verification page: ")
         . ($link === '' ? "The verification code is: " : '') . "{$code}\n\nThe link and code expire in {$minutes} minutes and work only once. If you received several emails, use the newest.\nIf you did not expect this email, you can ignore it.";
    return sendEmail($email, $subject, $body, $alt);
}

/**
 * Send the LOGIN verification code (used by login.php instead of the captcha)
 */
function sendLoginCodeEmail($email, $name, $code) {
    $subject = "Your Login Code - Janet's Quality Catering (" . emailSubjectTime() . ")";
    $safeName = htmlspecialchars($name ?: 'there');
    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
      . '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f4f4f4;">'
      . '<table role="presentation" style="width:100%;border-collapse:collapse;"><tr><td style="padding:40px 20px;">'
      . '<table role="presentation" style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1);">'
      . '<tr><td style="background:linear-gradient(135deg,#9370DB 0%,#8B5CF6 100%);padding:32px 30px;text-align:center;">'
      . '<h1 style="color:#fff;margin:0;font-size:26px;">Janet\'s Quality Catering</h1>'
      . '<p style="color:rgba(255,255,255,.9);margin:8px 0 0;font-size:14px;">Login Verification</p></td></tr>'
      . '<tr><td style="padding:36px 30px;">'
      . '<p style="color:#333;font-size:16px;margin:0 0 16px;">Hello <strong>' . $safeName . '</strong>,</p>'
      . '<p style="color:#555;font-size:15px;margin:0 0 24px;">Use this code to finish signing in:</p>'
      . '<div style="background:#f8f5ff;border:2px solid #9370DB;border-radius:12px;padding:22px;text-align:center;margin:0 0 24px;">'
      . '<span style="font-size:34px;font-weight:700;letter-spacing:8px;color:#9370DB;font-family:monospace;">' . $code . '</span></div>'
      . '<p style="color:#555;font-size:14px;margin:0 0 12px;"><strong>This code expires in ' . LOGIN_CODE_EXPIRY_MINUTES . ' minutes.</strong></p>'
      . '<p style="color:#777;font-size:13px;margin:0;">If you did not try to sign in, please ignore this email and consider changing your password.</p>'
      . '</td></tr></table></td></tr></table></body></html>';
    $alt = "Hello {$name},\n\nYour login code is: {$code}\n\nIt expires in " . LOGIN_CODE_EXPIRY_MINUTES . " minutes.\nIf you did not try to sign in, please ignore this email.";
    return sendEmail($email, $subject, $body, $alt);
}

/**
 * Send welcome email to new user
 */
function sendWelcomeEmail($email, $name, $username) {
    $subject = "Welcome to Janet's Quality Catering!";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
    </head>
    <body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
        <table role="presentation" style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 40px 20px;">
                    <table role="presentation" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
                        <tr>
                            <td style="background: linear-gradient(135deg, #9370DB 0%, #8B5CF6 100%); padding: 40px 30px; text-align: center;">
                                <h1 style="color: #ffffff; margin: 0; font-size: 28px;">Welcome to Janet\'s Quality Catering!</h1>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 40px 30px;">
                                <p style="color: #333333; font-size: 16px; margin: 0 0 20px 0;">Hello <strong>' . htmlspecialchars($name) . '</strong>,</p>
                                <p style="color: #555555; font-size: 15px; line-height: 1.6; margin: 0 0 20px 0;">
                                    Your account has been successfully created!
                                </p>
                                <div style="background: #f8f5ff; border-left: 4px solid #9370DB; padding: 20px; margin: 20px 0;">
                                    <p style="margin: 0 0 10px 0; color: #333333;"><strong>Username:</strong> ' . htmlspecialchars($username) . '</p>
                                    <p style="margin: 0; color: #333333;"><strong>Email:</strong> ' . htmlspecialchars($email) . '</p>
                                </div>
                                <p style="color: #555555; font-size: 15px; line-height: 1.6; margin: 20px 0 0 0;">
                                    You can now log in and start managing events and inventory.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <td style="background: #f8f8f8; padding: 25px 30px; text-align: center;">
                                <p style="color: #999999; font-size: 12px; margin: 0;">
                                    &copy; ' . date('Y') . ' Janet\'s Quality Catering. All rights reserved.
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';
    
    return sendEmail($email, $subject, $body);
}

/**
 * Test email configuration without sending
 */
function testEmailConfig() {
    if (strpos(SMTP_USERNAME, 'your-email') !== false || empty(SMTP_USERNAME)) {
        return [
            'configured' => false,
            'message' => 'Gmail SMTP credentials not set',
            'hint' => 'Update SMTP_USERNAME and SMTP_PASSWORD in config/email_config.php'
        ];
    }
    
    if (strpos(SMTP_PASSWORD, 'xxxx') !== false || strlen(str_replace(' ', '', SMTP_PASSWORD)) < 16) {
        return [
            'configured' => false,
            'message' => 'App Password not set or invalid',
            'hint' => 'Generate an App Password from https://myaccount.google.com/apppasswords'
        ];
    }
    
    return [
        'configured' => true,
        'message' => 'Email configuration looks good',
        'smtp_host' => SMTP_HOST,
        'smtp_user' => SMTP_USERNAME
    ];
}
?>