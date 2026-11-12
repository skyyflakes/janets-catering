<?php
/**
 * Accounts Management Page - OWNER only
 * Janet's Quality Catering System
 * The Owner creates Admin and Supervisor accounts. Accounts can be archived (they cannot sign in) and, once archived,
 * have their email and password permanently removed. Names and other personal records stay on file.
 */
$page_title = "Accounts Management | Janet's Quality Catering";
$current_page = 'accounts';
require_once 'includes/auth_check.php';

require_once 'config/email_config.php';
require_once 'includes/account_security.php';

// Only the OWNER can open this page or submit its forms (role read from the database, not the session).
$viewer_role = jq_require_roles($pdo, ['OWNER'], 'Accounts');
$view_archived = ($_GET['view'] ?? '') === 'archived';

// Get current user info
$current_user = null;
if ($pdo && isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch();
}

$error = '';
$success = '';
$verify_modal = null; // account whose code-entry window should open
$verify_error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Only the Owner may change a Supervisor account, whatever the action.
    $target_is_supervisor = false;
    if (!empty($_POST['user_id'])) {
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $st->execute([(int)$_POST['user_id']]);
        $target_is_supervisor = $st->fetchColumn() === 'SUPERVISOR';
    }

    if (!jq_csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session security token expired. Please try again.';
    } elseif ($target_is_supervisor && $viewer_role !== 'OWNER') {
        $error = 'Only the Owner can manage Supervisor accounts.';
    } elseif ($action === 'create_account') {
        $new_role = strtoupper(trim((string)($_POST['role'] ?? '')));
        $username = sanitize($_POST['username'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $first_name = jq_clean_name($_POST['first_name'] ?? '');
        $last_name = jq_clean_name($_POST['last_name'] ?? '');
        $contact = jq_normalize_phone($_POST['contact'] ?? '');
        $postal_code = sanitize($_POST['postal_code'] ?? '');

        // Get address fields
        $region_code = sanitize($_POST['region'] ?? '');
        $province_code = sanitize($_POST['province'] ?? '');
        $city_code = sanitize($_POST['city'] ?? '');
        $barangay_code = sanitize($_POST['barangay'] ?? '');
        $street_address = sanitize($_POST['street_address'] ?? '');

        // Get address text values
        $region_text = sanitize($_POST['region_text'] ?? '');
        $province_text = sanitize($_POST['province_text'] ?? '');
        $city_text = sanitize($_POST['city_text'] ?? '');
        $barangay_text = sanitize($_POST['barangay_text'] ?? '');

        // Compose full address
        $address_parts = array_filter([$street_address, $barangay_text, $city_text, $province_text, $region_text]);
        $address = implode(', ', $address_parts);

        // Validation
        $password_error = jq_password_error($password);
        // The browser only offers allowed roles; this is the check that counts.
        if (!in_array($new_role, ['ADMIN', 'SUPERVISOR'], true)) {
            $error = 'Please choose a valid role: ADMIN or SUPERVISOR.';
        } elseif (!in_array($new_role, jq_assignable_roles((string)$viewer_role), true)) {
            $error = 'Only the Owner can create Supervisor accounts.';
        } elseif (($name_error = jq_name_error($first_name, 'First name') ?? jq_name_error($last_name, 'Last name')) !== null) {
            $error = $name_error;
        } elseif (($postal_error = jq_postal_error($postal_code)) !== null) {
            $error = $postal_error;
        } elseif ($username === '') {
            $error = 'Username is required!';
        } elseif (mb_strlen($username) > 50) {
            $error = 'Username can be at most 50 characters!';
        } elseif (($email_error = jq_email_error($email)) !== null) {
            $error = $email_error;
        } elseif ($contact === false) {
            $error = JQ_PHONE_MESSAGE;
        } elseif ($password_error !== null) {
            $error = $password_error;
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match!';
        } elseif (!$pdo) {
            $error = 'Database connection failed!';
        } elseif (jq_username_taken($pdo, $username)) {
            $error = 'This username already exists. Please choose another one.';
        } elseif (jq_email_taken($pdo, $email)) {
            $error = 'This email address is already registered to another account.';
        } elseif ($contact !== null && jq_phone_taken($pdo, $contact)) {
            $error = 'This phone number is already registered to another account.';
        } else {
            try {
                $hashed_password = jq_password_hash($password);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, email, first_name, last_name, contact_number, role, status, is_verified, address, region_code, province_code, city_code, barangay_code, street_address, postal_code) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hashed_password, $email, $first_name, $last_name, $contact, $new_role, $address, $region_code, $province_code, $city_code, $barangay_code, $street_address, $postal_code]);
                $new_user_id = jq_insert_id($pdo, 'users', 'id');

                $mail = jq_send_verification($pdo, ['id' => $new_user_id, 'username' => $username, 'email' => $email, 'first_name' => $first_name]);
                if ($mail['success']) {
                    $verify_modal = ['id' => $new_user_id, 'username' => $username, 'email' => jq_mask_email($email),
                                     'notice' => ucfirst(strtolower($new_role)) . ' account created for ' . $username . '. A verification email with a link was sent to ' . jq_mask_email($email) . '.'];
                } else {
                    $error = ucfirst(strtolower($new_role)) . ' account created for ' . $username . ', but: ' . $mail['message'] . ' Use Actions > Resend Verification Email.';
                }
            } catch (PDOException $e) {
                // The unique indexes are the final guard against two people submitting at once
                $error = jq_is_duplicate($e)
                    ? 'That username, email or phone number was just registered by another account.'
                    : 'The account could not be created.';
                error_log('create_account: ' . $e->getMessage());
            }
        }
    } elseif (in_array($action, ['archive_account', 'restore_account', 'delete_credentials'], true)) {
        $user_id = intval($_POST['user_id'] ?? 0);
        $actor_id = (int)$_SESSION['user_id'];
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT id, username, role, status, is_archived, credentials_deleted_at FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$user_id]);
            $target = $stmt->fetch();
            $problem = null;
            if ($action === 'restore_account' && $viewer_role !== 'OWNER') {
                $problem = 'Only the Owner can restore archived accounts.';
            } elseif (!$target) {
                $problem = 'This account could not be found.';
            } elseif ($user_id === $actor_id) {
                $problem = 'You cannot archive or remove your own account.';
            } elseif ($target['role'] === 'OWNER') {
                $problem = 'The Owner account cannot be archived or removed.';
            } elseif ($action === 'archive_account') {
                if ($target['is_archived']) {
                    $problem = 'This account is already archived.';
                } elseif ($target['role'] === 'ADMIN') {
                    // Backup & Restore is ADMIN only, so at least one working ADMIN must remain.
                    $others = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND status = 'active' AND is_archived = 0 AND is_verified = 1 AND id <> " . (int)$user_id)->fetchColumn();
                    if ($others < 1) {
                        $problem = 'This is the last active Admin account, so it cannot be archived.';
                    }
                }
            } elseif (!$target['is_archived']) {
                $problem = 'Archive the account first.';
            } elseif ($target['credentials_deleted_at']) {
                $problem = $action === 'restore_account'
                    ? 'This account cannot be restored because its email and password were permanently removed.'
                    : 'The email and password of this account were already removed.';
            }

            if ($problem !== null) {
                $pdo->rollBack();
                $error = $problem;
            } elseif ($action === 'archive_account') {
                $pdo->prepare("UPDATE users SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?")->execute([$actor_id, $user_id]);
                $pdo->commit();
                $success = $target['username'] . ' was archived and can no longer sign in.';
            } elseif ($action === 'restore_account') {
                $pdo->prepare("UPDATE users SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?")->execute([$user_id]);
                $pdo->commit();
                $success = $target['username'] . ' was restored to the active accounts list and can sign in again.';
            } else {
                $pdo->prepare("UPDATE users SET email = NULL, password = NULL, is_verified = 0, credentials_deleted_at = NOW(), credentials_deleted_by = ? WHERE id = ?")->execute([$actor_id, $user_id]);
                $pdo->prepare("DELETE FROM email_verifications WHERE user_id = ?")->execute([$user_id]);
                $pdo->commit();
                $success = 'The email and password of ' . $target['username'] . ' were permanently removed. The name and other records were kept.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('account archive action: ' . $e->getMessage());
            $error = 'The account could not be changed. Nothing was saved.';
        }
    } elseif ($action === 'toggle_status') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $new_status = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'inactive';

        if ($user_id > 0 && $user_id != $_SESSION['user_id']) {
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role != 'OWNER' AND is_archived = 0");
            $stmt->execute([$new_status, $user_id]);

            if ($stmt->rowCount() > 0) {
                $success = 'Account status updated!';
            }
        }
    } elseif ($action === 'send_verification') {
        $user_id = intval($_POST['user_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT id, username, email, first_name, is_verified FROM users WHERE id = ? AND role != 'OWNER' AND is_archived = 0");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!$user || empty($user['email'])) {
            $error = 'This account has no email address to verify.';
        } elseif ((int)$user['is_verified'] === 1) {
            $error = 'This account is already verified.';
        } else {
            $mail = jq_send_verification($pdo, $user);
            if ($mail['success']) {
                $verify_modal = ['id' => (int)$user['id'], 'username' => $user['username'], 'email' => jq_mask_email($user['email']), 'notice' => $mail['message'] . ' Earlier links and codes no longer work.'];
            } else {
                $error = $mail['message'];
            }
        }
    } elseif ($action === 'verify_code') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE id = ? AND role != 'OWNER' AND is_archived = 0");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user) {
            $error = 'This account could not be found.';
        } else {
            $result = jq_verify_code($pdo, $user_id, (string)($_POST['code'] ?? ''));
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $verify_modal = ['id' => (int)$user['id'], 'username' => $user['username'], 'email' => jq_mask_email($user['email']), 'notice' => ''];
                $verify_error = $result['message'];
            }
        }
    }
}

// Get all users except current user
$users = [];
$archived_total = 0;
if ($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE is_archived = ? ORDER BY " . ($view_archived ? 'archived_at DESC' : 'created_at DESC'));
    $stmt->execute([$view_archived ? 1 : 0]);
    $users = $stmt->fetchAll();
    $archived_total = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_archived = 1")->fetchColumn();
}

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Accounts Management</h1>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Accounts</li>
        </ol>
    </nav>
</div>

<?php if (!empty($error)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: '<?php echo addslashes(strip_tags($error)); ?>',
        confirmButtonColor: '#9370DB'
    });
});
</script>
<?php endif; ?>

<?php if (!empty($success)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: '<?php echo addslashes(strip_tags($success)); ?>',
        confirmButtonColor: '#9370DB',
        timer: 3000,
        timerProgressBar: true
    });
});
</script>
<?php endif; ?>

<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bx bx-user-plus me-2"></i>Create New Account</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="createAccountForm" data-account-form="create" data-password-form="1">
                    <?php echo jq_csrf_field(); ?>
                    <input type="hidden" name="action" value="create_account">

                    <?php
                    $assignable_roles = jq_assignable_roles((string)$viewer_role);
                    $chosen_role = ($_POST['action'] ?? '') === 'create_account' ? strtoupper(trim((string)($_POST['role'] ?? ''))) : 'ADMIN';
                    if (!in_array($chosen_role, $assignable_roles, true) || $success !== '') { $chosen_role = 'ADMIN'; }
                    ?>
                    <div class="mb-3">
                        <label class="form-label d-block" id="roleLabel">Role <span class="text-danger">*</span></label>
                        <div class="jq-role-group" role="radiogroup" aria-labelledby="roleLabel">
                            <?php foreach (['ADMIN', 'SUPERVISOR'] as $role_option): $role_allowed = in_array($role_option, $assignable_roles, true); ?>
                            <input type="radio" class="btn-check" name="role" id="role<?php echo $role_option; ?>" value="<?php echo $role_option; ?>" autocomplete="off" required <?php echo $chosen_role === $role_option ? 'checked' : ''; ?> <?php echo $role_allowed ? '' : 'disabled'; ?>>
                            <label for="role<?php echo $role_option; ?>"><?php echo $role_option; ?></label>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted"><?php echo in_array('SUPERVISOR', $assignable_roles, true)
                            ? 'A Supervisor sees only the Event Bookings Dashboard and no prices.'
                            : 'Only the Owner can create Supervisor accounts.'; ?></small>
                    </div>

                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="first_name" <?= jq_field_attrs('name') ?> maxlength="100" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="last_name" <?= jq_field_attrs('name') ?> maxlength="100" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="username" required maxlength="50" placeholder="Must be unique">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" required placeholder="name@gmail.com">
                        <small class="text-muted">Each account needs its own Gmail. Verification and login codes are sent here.</small>
</div>
                    
                    <div class="mb-3">
                        <label class="form-label">Contact Number</label>
                        <input type="tel" class="form-control" name="contact" <?= jq_field_attrs('ph-mobile') ?>>
                        <small class="text-muted">10 digits after +63, starting with 9. Each number can be used by one account only.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="password" required minlength="<?php echo JQ_PASSWORD_MIN_LENGTH; ?>" maxlength="72" autocomplete="new-password" placeholder="At least <?php echo JQ_PASSWORD_MIN_LENGTH; ?> characters">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="confirm_password" required autocomplete="new-password">
                    </div>
                    
                    <hr class="my-3">
                    <h6 class="mb-3"><i class="bx bx-map me-1"></i> Address (Philippine Standard)</h6>
                    
                    <!-- Hidden fields for address text values -->
                    <input type="hidden" name="region_text" id="acc_region_text">
                    <input type="hidden" name="province_text" id="acc_province_text">
                    <input type="hidden" name="city_text" id="acc_city_text">
                    <input type="hidden" name="barangay_text" id="acc_barangay_text">
                    
                    <div class="mb-3">
                        <label class="form-label">Region</label>
                        <select name="region" id="acc_region" class="form-select">
                            <option value="">Choose Region</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Province</label>
                        <select name="province" id="acc_province" class="form-select">
                            <option value="">Choose Province</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">City/Municipality</label>
                        <select name="city" id="acc_city" class="form-select">
                            <option value="">Choose City/Municipality</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Barangay</label>
                        <select name="barangay" id="acc_barangay" class="form-select">
                            <option value="">Choose Barangay</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Street Address</label>
                        <input type="text" class="form-control" name="street_address" placeholder="House No., Street, etc.">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Postal Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="postal_code" data-rule="postal" required placeholder="1100">
                    </div>
                    
                    <hr class="my-3">
                    
                    <p class="text-muted small mb-3"><i class="bx bx-envelope me-1"></i>New accounts get the Admin role automatically. A verification code is emailed, and the account cannot sign in until the code is entered.</p>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bx bx-plus me-2"></i>Create Account
                    </button>
                </form>
            </div>
        </div>
        
    </div>
    
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bx <?php echo $view_archived ? 'bx-archive' : 'bx-group'; ?> me-2"></i><?php echo $view_archived ? 'Archived Accounts' : 'All Accounts'; ?></h5>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <a href="accounts.php<?php echo $view_archived ? '' : '?view=archived'; ?>" class="btn btn-sm btn-label-primary"><i class="bx <?php echo $view_archived ? 'bx-arrow-back' : 'bx-archive'; ?> me-1"></i><?php echo $view_archived ? 'Back to Accounts' : 'Archive (' . $archived_total . ')'; ?></a>
                    <span class="badge badge-primary"><?php echo count($users); ?> Users</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-wrapper">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Contact</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Verified</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted"><?php echo $view_archived ? 'No archived accounts' : 'No accounts found'; ?></td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <div class="acc-user">
                                        <div class="nav-user-avatar acc-avatar">
                                            <?php if (!empty($user['profile_photo']) && jq_file_exists($user['profile_photo'])): ?>
                                                <img src="<?php echo htmlspecialchars($user['profile_photo']); ?>" alt="Profile">
                                            <?php else: ?>
                                                <?php echo htmlspecialchars(mb_strtoupper(mb_substr($user['first_name'] ?: $user['username'], 0, 1))); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="acc-user-text">
                                            <div class="fw-semibold acc-cut" title="<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                                            <small class="text-muted acc-cut" title="@<?php echo htmlspecialchars($user['username']); ?>">@<?php echo htmlspecialchars($user['username']); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="small acc-cut" title="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"><?php echo htmlspecialchars($user['email'] ?? ($user['credentials_deleted_at'] ? 'Removed' : 'N/A')); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars(jq_phone_display($user['contact_number']) ?: 'N/A'); ?></div>
                                </td>
                                <td>
                                    <?php
                                    $role_class = match($user['role']) {
                                        'OWNER' => 'badge-primary',
                                        'ADMIN' => 'badge-info',
                                        'SUPERVISOR' => 'badge-success',
                                        default => 'badge-secondary'
                                    };
                                    ?>
                                    <span class="badge <?php echo $role_class; ?>"><?php echo $user['role']; ?></span>
                                </td>
                                <td>
                                    <?php if ($view_archived): ?>
                                    <span class="status-pill status-cancelled">Archived</span>
                                    <?php else:
                                    $status_class = match($user['status'] ?? 'active') {
                                        'active' => 'status-confirmed',
                                        'inactive' => 'status-cancelled',
                                        'pending' => 'status-pending',
                                        default => 'status-pending'
                                    };
                                    ?>
                                    <span class="status-pill <?php echo $status_class; ?>"><?php echo ucfirst($user['status'] ?? 'Active'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['credentials_deleted_at']): ?>
                                        <span class="status-pill status-cancelled" title="Email and password were permanently removed">Removed</span>
                                    <?php elseif (!empty($user['is_verified'])): ?>
                                        <span class="status-pill status-confirmed" title="Email verified">Verified</span>
                                    <?php else: ?>
                                        <span class="status-pill status-pending" title="The emailed verification link has not been opened yet">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($view_archived): ?>
                                    <div class="acc-archive-actions">
                                        <?php if ($user['role'] === 'SUPERVISOR' && $viewer_role !== 'OWNER'): ?>
                                        <span class="text-muted small">Owner only</span>
                                        <?php elseif (!$user['credentials_deleted_at']): ?>
                                        <?php if ($viewer_role === 'OWNER'): ?>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="confirmAccountAction('restore_account', <?php echo (int)$user['id']; ?>, <?php echo htmlspecialchars(json_encode($user['username']), ENT_QUOTES); ?>)"><i class="bx bx-archive-out me-1"></i>Restore</button>
                                        <?php else: ?>
                                        <span class="text-muted small">Restore: Owner only</span>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="confirmAccountAction('delete_credentials', <?php echo (int)$user['id']; ?>, <?php echo htmlspecialchars(json_encode($user['username']), ENT_QUOTES); ?>)"><i class="bx bx-trash me-1"></i>Permanent Delete</button>
                                        <?php else: ?>
                                        <span class="text-muted small" title="Removed <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($user['credentials_deleted_at']))); ?>">Credentials removed</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php elseif ($user['role'] === 'SUPERVISOR' && $viewer_role !== 'OWNER'): ?>
                                    <span class="text-muted small">Owner only</span>
                                    <?php elseif ($user['id'] != $_SESSION['user_id'] && $user['role'] !== 'OWNER'): ?>
                                    <div class="dropup">
                                        <button class="btn btn-sm btn-label-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            Actions
                                        </button>
                                        <ul class="dropdown-menu">
                                            <?php if (empty($user['is_verified'])): ?>
                                            <li>
                                                <form method="POST" class="d-inline">
                                                    <?php echo jq_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="send_verification">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="dropdown-item">
                                                        <i class="bx bx-envelope me-2"></i>Resend Verification Email
                                                    </button>
                                                </form>
                                            </li>
                                            <li>
                                                <button type="button" class="dropdown-item" onclick="openVerifyModal(<?php echo (int)$user['id']; ?>, <?php echo htmlspecialchars(json_encode($user['username']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode(jq_mask_email((string)$user['email'])), ENT_QUOTES); ?>)">
                                                    <i class="bx bx-key me-2"></i>Enter Verification Code
                                                </button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php endif; ?>
                                            <li>
                                                <form method="POST" class="d-inline">
                                                    <?php echo jq_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <input type="hidden" name="new_status" value="<?php echo ($user['status'] ?? 'active') === 'active' ? 'inactive' : 'active'; ?>">
                                                    <button type="submit" class="dropdown-item">
                                                        <i class="bx bx-power-off me-2"></i>
                                                        <?php echo ($user['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </form>
                                            </li>
                                            <li>
                                                <button type="button" class="dropdown-item text-danger" onclick="confirmAccountAction('archive_account', <?php echo (int)$user['id']; ?>, <?php echo htmlspecialchars(json_encode($user['username']), ENT_QUOTES); ?>)">
                                                    <i class="bx bx-archive-in me-2"></i>Archive
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted small">
                                        <?php echo $user['role'] === 'OWNER' ? 'Owner' : 'Current'; ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ===== DROPDOWN MENU - LIGHT MODE ===== */
[data-theme="light"] .dropdown-menu {
    background-color: #FFFFFF !important;
    border: 1px solid #E0E0E0 !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
    z-index: 1050 !important;
}

[data-theme="light"] .dropdown-menu .dropdown-item {
    color: #1A1A2E !important;
    padding: 10px 16px !important;
    font-size: 0.875rem !important;
}

[data-theme="light"] .dropdown-menu .dropdown-item:hover {
    background-color: #F5F0FA !important;
    color: #1A1A2E !important;
}

[data-theme="light"] .dropdown-menu .dropdown-item.text-danger {
    color: #DC3545 !important;
}

[data-theme="light"] .dropdown-menu .dropdown-item.text-danger:hover {
    background-color: #FFF5F5 !important;
    color: #DC3545 !important;
}

[data-theme="light"] .dropdown-menu .dropdown-item i {
    color: #6C757D !important;
}

[data-theme="light"] .dropdown-menu .dropdown-item.text-danger i {
    color: #DC3545 !important;
}

[data-theme="light"] .dropdown-divider {
    border-color: #E0E0E0 !important;
}

/* ===== DROPDOWN MENU - DARK MODE ===== */
[data-theme="dark"] .dropdown-menu {
    background-color: #2A2A45 !important;
    border: 1px solid #3D3D5C !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3) !important;
    z-index: 1050 !important;
}

[data-theme="dark"] .dropdown-menu .dropdown-item {
    color: #E8E6F0 !important;
    padding: 10px 16px !important;
    font-size: 0.875rem !important;
}

[data-theme="dark"] .dropdown-menu .dropdown-item:hover {
    background-color: #3A3A5A !important;
    color: #FFFFFF !important;
}

[data-theme="dark"] .dropdown-menu .dropdown-item.text-danger {
    color: #FF6B6B !important;
}

[data-theme="dark"] .dropdown-menu .dropdown-item.text-danger:hover {
    background-color: rgba(255, 62, 29, 0.15) !important;
    color: #FF6B6B !important;
}

[data-theme="dark"] .dropdown-menu .dropdown-item i {
    color: #B8B8D0 !important;
}

[data-theme="dark"] .dropdown-menu .dropdown-item.text-danger i {
    color: #FF6B6B !important;
}

[data-theme="dark"] .dropdown-divider {
    border-color: #3D3D5C !important;
}

/* ===== ACTION BUTTON TEXT VISIBILITY ===== */
/* Light Mode - Action button text should be visible on light background */
[data-theme="light"] .btn-label-primary {
    background-color: rgba(147, 112, 219, 0.16) !important;
    color: #7B5FC7 !important;
    border: none !important;
}

[data-theme="light"] .btn-sm.dropdown-toggle {
    color: #5A5A7A !important;
}

/* Dark Mode - Action button text should be visible on dark background */
[data-theme="dark"] .btn-label-primary {
    background-color: rgba(147, 112, 219, 0.25) !important;
    color: #FFFFFF !important;
    border: none !important;
}

[data-theme="dark"] .btn-sm.dropdown-toggle {
    color: #E8E6F0 !important;
}

/* Accounts table scroll fix with Dropup compatibility */
.table-wrapper {
    max-height: 500px;
    overflow-y: auto;
    overflow-x: visible !important; /* Allow menu to break out horizontally if needed */
    padding-top: 150px; /* Space for the top row's dropup menu */
    margin-top: -150px; /* Offset the padding to maintain layout */
    padding-bottom: 10px;
}
.table-wrapper table {
    width: 100%;
    table-layout: fixed;
    min-width: 720px; /* narrow screens scroll inside the table instead of cutting off phone numbers */
}
.table-wrapper th,
.table-wrapper td {
    white-space: nowrap;
}
.table-wrapper th:first-child,
.table-wrapper td:first-child {
    width: 29%;
}
.table-wrapper th:nth-child(2),
.table-wrapper td:nth-child(2) {
    width: 25%;
}
/* Round, identical avatars: the circle never shrinks, whatever the name length or image shape */
.acc-user { display: flex; align-items: center; gap: 12px; min-width: 0; }
.acc-avatar { flex: 0 0 40px; width: 40px; height: 40px; border-radius: 50%; overflow: hidden; font-size: 0.875rem; }
.acc-avatar img { display: block; width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
.acc-user-text { min-width: 0; flex: 1; }
.acc-cut { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.table-wrapper td:not(:last-child) { overflow: hidden; }
.table-wrapper .status-pill { padding: 4px 8px; font-size: .75rem; }
.table-wrapper .table thead th, .table-wrapper .table tbody td { padding-left: 10px; padding-right: 10px; }
.table-wrapper .table thead th:first-child, .table-wrapper .table tbody td:first-child { padding-left: 20px; }
.table-wrapper .table .btn-sm { padding: 6px 10px; font-size: .8rem; }
.table-wrapper th:nth-child(3),
.table-wrapper td:nth-child(3) {
    width: 116px; /* room for SUPERVISOR */
}
.table-wrapper th:nth-child(4),
.table-wrapper td:nth-child(4) {
    width: 84px;
}
.table-wrapper th:nth-child(5),
.table-wrapper td:nth-child(5) {
    width: 84px;
}
.table-wrapper th:last-child,
.table-wrapper td:last-child {
    width: 158px;
    overflow: visible; /* CRITICAL: Allows the dropup menu to be seen */
}

/* Role choice: the selected role is filled, a role this person may not assign is dimmed */
.jq-role-group { margin-bottom: 6px; display: flex; border: 1px solid var(--bs-primary, #7B5FC7); border-radius: 8px; overflow: hidden; }
.jq-role-group label { flex: 1 1 50%; margin: 0; padding: 9px 8px; text-align: center; font-size: .8125rem; font-weight: 600; letter-spacing: .02em; cursor: pointer; color: var(--bs-primary, #7B5FC7); transition: background-color .15s, color .15s; }
.jq-role-group label + input + label { border-left: 1px solid var(--bs-primary, #7B5FC7); }
html body .jq-role-group input:checked + label:not(#x) { background: #7B5FC7; color: #fff !important; }
.jq-role-group input:disabled + label { opacity: .5; cursor: not-allowed; }
.jq-role-group input:focus-visible + label { outline: 2px solid var(--bs-primary, #7B5FC7); outline-offset: -4px; }

.acc-archive-actions { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; white-space: nowrap; }
/* Ensure the dropup button container is relative for positioning */
.dropup {
    position: relative;
}
</style>

<!-- Enter the emailed verification code -->
<div class="modal fade" id="verifyCodeModal" tabindex="-1" aria-labelledby="verifyCodeTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="verifyCodeTitle"><i class="bx bx-key me-2"></i>Verify email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success" id="verifyNotice" style="display:none;"></div>
                <div class="alert alert-danger" id="verifyError" style="display:none;"></div>
                <p class="mb-3">Enter the <?php echo (int)EMAIL_VERIFICATION_CODE_LENGTH; ?>-digit code sent to <strong id="verifyEmail"></strong> for <strong id="verifyUsername"></strong>. The new user can also simply open the link in that email. Both expire after <?php echo (int)EMAIL_VERIFICATION_EXPIRY; ?> minutes.</p>
                <form method="POST" id="verifyCodeForm">
                    <?php echo jq_csrf_field(); ?>
                    <input type="hidden" name="action" value="verify_code">
                    <input type="hidden" name="user_id" id="verifyUserId">
                    <label class="form-label" for="verifyCode">Verification code</label>
                    <input type="text" class="form-control mb-3" name="code" id="verifyCode" inputmode="numeric" autocomplete="one-time-code" maxlength="<?php echo (int)EMAIL_VERIFICATION_CODE_LENGTH; ?>" pattern="[0-9]{<?php echo (int)EMAIL_VERIFICATION_CODE_LENGTH; ?>}" required>
                    <button type="submit" class="btn btn-primary w-100"><i class="bx bx-check me-2"></i>Verify</button>
                </form>
                <form method="POST" class="mt-2" id="verifyResendForm">
                    <?php echo jq_csrf_field(); ?>
                    <input type="hidden" name="action" value="send_verification">
                    <input type="hidden" name="user_id" id="verifyResendId">
                    <button type="submit" class="btn btn-outline-secondary w-100"><i class="bx bx-envelope me-2"></i>Resend email</button>
                </form>
            </div>
        </div>
    </div>
</div>

<form id="accountActionForm" method="POST" action="accounts.php<?php echo $view_archived ? '?view=archived' : ''; ?>" style="display: none;">
    <?php echo jq_csrf_field(); ?>
    <input type="hidden" name="action" id="accountActionName">
    <input type="hidden" name="user_id" id="accountActionUser">
</form>

<script>
function openVerifyModal(id, username, email, notice, error) {
    document.getElementById('verifyUserId').value = id;
    document.getElementById('verifyResendId').value = id;
    document.getElementById('verifyUsername').textContent = username;
    document.getElementById('verifyEmail').textContent = email;
    const show = (elId, text) => { const el = document.getElementById(elId); el.textContent = text || ''; el.style.display = text ? '' : 'none'; };
    show('verifyNotice', notice);
    show('verifyError', error);
    document.getElementById('verifyCode').value = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('verifyCodeModal')).show();
}
<?php if ($verify_modal): ?>
document.addEventListener('DOMContentLoaded', function () {
    openVerifyModal(<?php echo (int)$verify_modal['id']; ?>, <?php echo json_encode($verify_modal['username'], JSON_HEX_TAG); ?>, <?php echo json_encode($verify_modal['email'], JSON_HEX_TAG); ?>, <?php echo json_encode($verify_modal['notice'], JSON_HEX_TAG); ?>, <?php echo json_encode($verify_error, JSON_HEX_TAG); ?>);
});
<?php endif; ?>

function confirmAccountAction(action, userId, username) {
    const name = escapeHtml(username);
    const text = {
        archive_account: { title: 'Archive Account?', button: 'Yes, Archive', danger: false,
            html: `<strong>${name}</strong> will move to the archive and will not be able to sign in. All information is kept, and you can restore the account later.` },
        restore_account: { title: 'Restore Account?', button: 'Yes, Restore', danger: false,
            html: `<strong>${name}</strong> will return to the active accounts and can sign in again.` },
        delete_credentials: { title: 'Permanently Delete Credentials?', button: 'Permanent Delete', danger: true,
            html: `The email and password of <strong>${name}</strong> will be <strong>permanently removed and cannot be recovered</strong>. The account can never sign in or be restored. The name and other personal records stay on file.` }
    }[action];
    Swal.fire({
        title: text.title,
        html: text.html,
        icon: text.danger ? 'warning' : 'question',
        showCancelButton: true,
        reverseButtons: true,
        focusCancel: true,
        confirmButtonColor: text.danger ? '#ff3e1d' : '#9370DB',
        cancelButtonColor: '#8592a3',
        confirmButtonText: text.button,
        cancelButtonText: 'Cancel',
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('accountActionName').value = action;
            document.getElementById('accountActionUser').value = userId;
            showLoading('Processing...');
            document.getElementById('accountActionForm').submit();
        }
    });
}
</script>

<!-- Philippine Address Selector (wilfredpine/philippine-address-selector) -->
<script src="static/js/ph-address-selector.js"></script>
<script src="static/js/account-validation.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    PhAddressSelector.init({
        prefix: 'acc_',
        regionId:       'acc_region',
        provinceId:     'acc_province',
        cityId:         'acc_city',
        barangayId:     'acc_barangay',
        regionTextId:   'acc_region_text',
        provinceTextId: 'acc_province_text',
        cityTextId:     'acc_city_text',
        barangayTextId: 'acc_barangay_text'
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
