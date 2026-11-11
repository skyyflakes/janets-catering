<?php
/**
 * Profile Dashboard - Janet's Quality Catering System
 * The signed-in user's details, profile editing, password change and profile photo.
 */
$page_title = "My Profile | Janet's Quality Catering";
$current_page = 'profile';

require_once 'includes/auth_check.php';
require_once 'config/email_config.php';
require_once 'includes/account_security.php';

const JQ_PROFILE_PHOTO_MAX_BYTES = 10 * 1024 * 1024;

$pdo = getDBConnection();
$user_id = (int)$_SESSION['user_id'];

$error_message = '';
$active_tab = in_array($_GET['tab'] ?? '', ['overview', 'edit', 'security'], true) ? $_GET['tab'] : 'overview';

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if (!jq_csrf_valid($_POST['csrf_token'] ?? null)) {
        $error_message = 'Your session security token expired. Please try again.';
        $active_tab = $action === 'change_password' ? 'security' : ($action === 'update_profile' ? 'edit' : 'overview');

    } elseif ($action === 'update_photo') {
        $ext = jq_image_upload_extension($_FILES['profile_photo'] ?? [], $error_message, JQ_PROFILE_PHOTO_MAX_BYTES, ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp']);
        if ($ext !== null) {
            $upload_dir = 'uploads/profile/';
            jq_file_dir($upload_dir);
            if (!jq_is_hosted() && !is_file($upload_dir . '.htaccess')) {
                @file_put_contents($upload_dir . '.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|phps|cgi|pl|py|sh)$\">\n    Require all denied\n</FilesMatch>\n");
            }
            $path = $upload_dir . 'profile_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!jq_file_save_upload($_FILES['profile_photo']['tmp_name'], $path)) {
                $error_message = 'The photo could not be saved. Please try again.';
            } else {
                try {
                    $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?")->execute([$path, $user_id]);
                    $old = (string)$user_data['profile_photo'];
                    if (strpos($old, $upload_dir) === 0 && strpos($old, '..') === false) {
                        jq_file_delete($old); // only after the database points at the new photo
                    }
                    setFlash('Your profile photo was updated.', 'success');
                    redirect('profile.php');
                } catch (PDOException $e) {
                    jq_file_delete($path);
                    error_log('update_photo: ' . $e->getMessage());
                    $error_message = 'The photo could not be saved. Please try again.';
                }
            }
        }

    } elseif ($action === 'update_profile') {
        $active_tab = 'edit';
        $first_name = jq_clean_name($_POST['first_name'] ?? '');
        $last_name = jq_clean_name($_POST['last_name'] ?? '');
        $postal_code = sanitize($_POST['postal_code'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $contact_number = jq_normalize_phone($_POST['contact_number'] ?? '');

        $region_code = sanitize($_POST['region'] ?? '');
        $province_code = sanitize($_POST['province'] ?? '');
        $city_code = sanitize($_POST['city'] ?? '');
        $barangay_code = sanitize($_POST['barangay'] ?? '');
        $street_address = sanitize($_POST['street_address'] ?? '');

        $address_parts = array_filter([$street_address, sanitize($_POST['barangay_text'] ?? ''), sanitize($_POST['city_text'] ?? ''),
                                       sanitize($_POST['province_text'] ?? ''), sanitize($_POST['region_text'] ?? '')]);
        $address = implode(', ', $address_parts);

        if (($name_error = jq_name_error($first_name, 'First name') ?? jq_name_error($last_name, 'Last name')) !== null) {
            $error_message = $name_error;
        } elseif (($postal_error = jq_postal_error($postal_code)) !== null) {
            $error_message = $postal_error;
        } elseif (($email_error = jq_email_error($email)) !== null) {
            $error_message = $email_error;
        } elseif (jq_email_taken($pdo, $email, $user_id)) {
            $error_message = 'This email address is already registered to another account.';
        } elseif ($contact_number === false) {
            $error_message = JQ_PHONE_MESSAGE;
        } elseif ($contact_number !== null && jq_phone_taken($pdo, $contact_number, $user_id)) {
            $error_message = 'This phone number is already registered to another account.';
        }

        // A new email address must be verified: the email is sent first, so a typo cannot lock the account.
        $email_changed = $error_message === '' && strcasecmp($email, (string)$user_data['email']) !== 0;
        if ($email_changed) {
            $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$user_id]);
            $mail = jq_send_verification($pdo, ['id' => $user_id, 'username' => $user_data['username'], 'email' => $email, 'first_name' => $first_name]);
            if (!$mail['success']) {
                $error_message = $mail['message'] . ' Your email address was not changed.';
            }
        }

        if ($error_message === '') {
            try {
                $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, contact_number = ?, address = ?, region_code = ?, province_code = ?, city_code = ?, barangay_code = ?, street_address = ?, postal_code = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$first_name, $last_name, $email, $contact_number, $address, $region_code, $province_code, $city_code, $barangay_code, $street_address, $postal_code, $user_id]);
                if ($email_changed) {
                    $pdo->prepare("UPDATE users SET is_verified = 0 WHERE id = ?")->execute([$user_id]);
                    session_unset();
                    session_regenerate_id(true);
                    $_SESSION['verify_pending_user'] = $user_id;
                    redirect('verify_email.php');
                }
                setFlash('Your profile was updated.', 'success');
                redirect('profile.php');
            } catch (PDOException $e) {
                error_log('update_profile: ' . $e->getMessage());
                $error_message = 'Your profile could not be saved. Please try again.';
            }
        }

    } elseif ($action === 'change_password') {
        $active_tab = 'security';
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            $error_message = 'All password fields are required.';
        } elseif (!jq_password_matches_account($pdo, $user_id, $current_password)) {
            $error_message = 'Your current password is incorrect.';
        } elseif ($new_password !== $confirm_password) {
            $error_message = 'The new password and confirmation do not match.';
        } elseif (($password_error = jq_password_error($new_password)) !== null) {
            $error_message = $password_error;
        } elseif ($new_password === $current_password) {
            $error_message = 'Choose a new password that is different from your current one.';
        } else {
            try {
                $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?")->execute([password_hash($new_password, PASSWORD_BCRYPT), $user_id]);
                setFlash('Your password was changed.', 'success');
                redirect('profile.php');
            } catch (PDOException $e) {
                error_log('change_password: ' . $e->getMessage());
                $error_message = 'Your password could not be changed. Please try again.';
            }
        }
    }
}

// Figures for the dashboard
$full_name = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));
$display_name = $full_name !== '' ? $full_name : $user_data['username'];
$has_photo = !empty($user_data['profile_photo']) && jq_file_exists($user_data['profile_photo']);
$stmt = $pdo->prepare("SELECT MAX(created_at) FROM activity_logs WHERE user_id = ? AND action = 'LOGIN'");
$stmt->execute([$user_id]);
$last_login = $stmt->fetchColumn();
$dash = static fn($v) => ($v === null || trim((string)$v) === '') ? '—' : (string)$v;

require_once 'includes/header.php';
?>

<style>
#jqPage .pf-grid { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 20px; align-items: start; }
#jqPage .pf-side { text-align: center; padding: 28px 20px; }
#jqPage .pf-avatar { width: 128px; height: 128px; border-radius: 50%; margin: 0 auto 14px; overflow: hidden; display: flex; align-items: center; justify-content: center;
    background: var(--jq-brand-badge-bg); border: 3px solid var(--jq-brand-badge-border); font-size: 3rem; font-weight: 700; color: var(--jq-brand-badge-text) !important; }
#jqPage .pf-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
#jqPage .pf-name { font-size: 1.15rem; font-weight: 700; margin: 0; color: var(--jq-text) !important; overflow-wrap: anywhere; }
#jqPage .pf-user { font-size: .85rem; margin: 2px 0 12px; color: var(--jq-text-muted) !important; overflow-wrap: anywhere; }
#jqPage .pf-badges { display: flex; justify-content: center; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
#jqPage .pf-side .jq-btn { width: 100%; }
#jqPage .pf-photo-actions { display: none; gap: 8px; margin-top: 8px; }
#jqPage .pf-photo-actions.is-visible { display: flex; }
#jqPage .pf-photo-actions .jq-btn { flex: 1; }
#jqPage .pf-section { margin: 0 0 12px; font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--jq-text-muted) !important; }
#jqPage .tab-pane > form > .jq-form-grid + .jq-section-title { margin-top: 24px; }
#jqPage .jq-tab.active { color: var(--jq-brand-text) !important; border-bottom-color: var(--jq-brand); }
@media (max-width: 991px) { #jqPage .pf-grid { grid-template-columns: minmax(0, 1fr); } }
@media (max-width: 480px) { #jqPage .jq-tabs { padding: 0 4px; gap: 0; } #jqPage .jq-tab { padding-left: 8px; padding-right: 8px; font-size: .82rem; } #jqPage .jq-tab i { display: none; } }
</style>

<div id="jqPage">
    <div class="jq-page-header">
        <div>
            <h1 class="jq-title">My Profile</h1>
            <p class="jq-subtitle">Your personal information, password and profile photo.</p>
        </div>
    </div>

    <div class="pf-grid">
        <!-- Summary and photo -->
        <div class="jq-card pf-side">
            <div class="pf-avatar" id="pfAvatar">
                <?php if ($has_photo): ?>
                <img src="<?php echo jq_e($user_data['profile_photo']); ?>?t=<?php echo (int)filemtime($user_data['profile_photo']); ?>" alt="Profile photo of <?php echo jq_e($display_name); ?>">
                <?php else: ?>
                <?php echo jq_e(mb_strtoupper(mb_substr($display_name, 0, 1))); ?>
                <?php endif; ?>
            </div>
            <p class="pf-name"><?php echo jq_e($display_name); ?></p>
            <p class="pf-user">@<?php echo jq_e($user_data['username']); ?></p>
            <div class="pf-badges">
                <span class="jq-badge jq-badge-brand"><?php echo jq_e($user_data['role']); ?></span>
                <span class="jq-badge jq-badge-<?php echo ($user_data['status'] ?? 'active') === 'active' ? 'restore' : 'archive'; ?>"><?php echo jq_e(ucfirst($user_data['status'] ?? 'active')); ?></span>
            </div>
            <form method="POST" enctype="multipart/form-data" id="photoForm">
                <?php echo jq_csrf_field(); ?>
                <input type="hidden" name="action" value="update_photo">
                <input type="file" id="profilePhotoInput" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" hidden onchange="previewProfilePhoto(this)">
                <button type="button" class="jq-btn jq-btn-view" id="photoChoose" onclick="document.getElementById('profilePhotoInput').click()">
                    <i class="bx bx-camera"></i> <?php echo $has_photo ? 'Change Photo' : 'Upload Photo'; ?>
                </button>
                <div class="pf-photo-actions" id="photoActions">
                    <button type="button" class="jq-btn jq-btn-cancel" onclick="cancelProfilePhoto()">Cancel</button>
                    <button type="submit" class="jq-btn jq-btn-primary"><i class="bx bx-save"></i> Save</button>
                </div>
            </form>
            <p class="jq-help" style="margin-top:10px;">JPG, JPEG, PNG or WebP, up to 10 MB.</p>
        </div>

        <!-- Details -->
        <div class="jq-card">
            <div class="jq-tabs" role="tablist">
                <a class="jq-tab <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" data-bs-toggle="tab" href="#pf-overview" role="tab"><i class="bx bx-id-card"></i> Overview</a>
                <a class="jq-tab <?php echo $active_tab === 'edit' ? 'active' : ''; ?>" data-bs-toggle="tab" href="#pf-edit" role="tab" id="pfEditTab"><i class="bx bx-edit-alt"></i> Edit Profile</a>
                <a class="jq-tab <?php echo $active_tab === 'security' ? 'active' : ''; ?>" data-bs-toggle="tab" href="#pf-security" role="tab"><i class="bx bx-lock-alt"></i> Change Password</a>
            </div>

            <div class="tab-content jq-card-body">
                <!-- Overview -->
                <div class="tab-pane fade <?php echo $active_tab === 'overview' ? 'show active' : ''; ?>" id="pf-overview" role="tabpanel">
                    <div class="jq-section-title">Personal information</div>
                    <dl class="jq-kv">
                        <div><dt>Full name</dt><dd><?php echo jq_e($dash($full_name)); ?></dd></div>
                        <div><dt>Username</dt><dd><?php echo jq_e($user_data['username']); ?></dd></div>
                        <div><dt>Email address</dt><dd><?php echo jq_e($dash($user_data['email'])); ?></dd></div>
                        <div><dt>Contact number</dt><dd><?php echo jq_e($dash(jq_phone_display($user_data['contact_number']))); ?></dd></div>
                        <div><dt>Postal code</dt><dd><?php echo jq_e($dash($user_data['postal_code'])); ?></dd></div>
                        <div style="grid-column:1/-1;"><dt>Address</dt><dd><?php echo jq_e($dash($user_data['address'])); ?></dd></div>
                    </dl>
                    <div class="jq-section-title">Account</div>
                    <dl class="jq-kv">
                        <div><dt>Role</dt><dd><?php echo jq_e($user_data['role']); ?></dd></div>
                        <div><dt>Status</dt><dd><?php echo jq_e(ucfirst($user_data['status'] ?? 'active')); ?></dd></div>
                        <div><dt>Email verification</dt><dd><?php echo !empty($user_data['is_verified']) ? 'Verified' : 'Pending'; ?></dd></div>
                        <div><dt>Member since</dt><dd><?php echo jq_e(date('F d, Y', strtotime($user_data['created_at']))); ?></dd></div>
                        <div><dt>Last updated</dt><dd><?php echo jq_e(date('F d, Y h:i A', strtotime($user_data['updated_at']))); ?></dd></div>
                        <div><dt>Last sign-in</dt><dd><?php echo $last_login ? jq_e(date('F d, Y h:i A', strtotime($last_login))) : '—'; ?></dd></div>
                    </dl>
                    <button type="button" class="jq-btn jq-btn-primary" style="margin-top:20px;" onclick="bootstrap.Tab.getOrCreateInstance(document.getElementById('pfEditTab')).show()"><i class="bx bx-edit-alt"></i> Edit Profile</button>
                </div>

                <!-- Edit profile -->
                <div class="tab-pane fade <?php echo $active_tab === 'edit' ? 'show active' : ''; ?>" id="pf-edit" role="tabpanel">
                    <form method="POST" data-account-form="profile">
                        <?php echo jq_csrf_field(); ?>
                        <input type="hidden" name="action" value="update_profile">
                        <div class="jq-section-title">Personal information</div>
                        <div class="jq-form-grid">
                            <div class="jq-field">
                                <label class="jq-label" for="pfFirst">First name <span class="jq-req">*</span></label>
                                <input class="jq-input" id="pfFirst" name="first_name" <?= jq_field_attrs('name') ?> maxlength="100" required value="<?php echo jq_e($_POST['first_name'] ?? $user_data['first_name']); ?>">
                            </div>
                            <div class="jq-field">
                                <label class="jq-label" for="pfLast">Last name <span class="jq-req">*</span></label>
                                <input class="jq-input" id="pfLast" name="last_name" <?= jq_field_attrs('name') ?> maxlength="100" required value="<?php echo jq_e($_POST['last_name'] ?? $user_data['last_name']); ?>">
                            </div>
                            <div class="jq-field">
                                <label class="jq-label" for="pfEmail">Gmail address <span class="jq-req">*</span></label>
                                <input class="jq-input" type="email" id="pfEmail" name="email" required value="<?php echo jq_e($_POST['email'] ?? $user_data['email']); ?>">
                                <p class="jq-help">Login codes are sent here. A new address must be verified before you can sign in again.</p>
                            </div>
                            <div class="jq-field">
                                <label class="jq-label" for="pfContact">Contact number</label>
                                <input class="jq-input" type="tel" id="pfContact" name="contact_number" <?= jq_field_attrs('ph-mobile') ?> value="<?php echo jq_e($_POST['contact_number'] ?? $user_data['contact_number']); ?>">
                            </div>
                        </div>

                        <div class="jq-section-title">Address</div>
                        <input type="hidden" name="region_text" id="profile_region_text">
                        <input type="hidden" name="province_text" id="profile_province_text">
                        <input type="hidden" name="city_text" id="profile_city_text">
                        <input type="hidden" name="barangay_text" id="profile_barangay_text">
                        <div class="jq-form-grid">
                            <div class="jq-field"><label class="jq-label" for="profile_region">Region</label><select class="jq-select" name="region" id="profile_region"><option value="">Choose Region</option></select></div>
                            <div class="jq-field"><label class="jq-label" for="profile_province">Province</label><select class="jq-select" name="province" id="profile_province"><option value="">Choose Province</option></select></div>
                            <div class="jq-field"><label class="jq-label" for="profile_city">City / Municipality</label><select class="jq-select" name="city" id="profile_city"><option value="">Choose City/Municipality</option></select></div>
                            <div class="jq-field"><label class="jq-label" for="profile_barangay">Barangay</label><select class="jq-select" name="barangay" id="profile_barangay"><option value="">Choose Barangay</option></select></div>
                            <div class="jq-field"><label class="jq-label" for="profile_street_address">Street address</label><input class="jq-input" name="street_address" id="profile_street_address" placeholder="House No., Street, Building Name, etc." value="<?php echo jq_e($_POST['street_address'] ?? $user_data['street_address']); ?>"></div>
                            <div class="jq-field"><label class="jq-label" for="profilePostal">Postal code <span class="jq-req">*</span></label><input class="jq-input" name="postal_code" id="profilePostal" data-rule="postal" required placeholder="1100" value="<?php echo jq_e($_POST['postal_code'] ?? $user_data['postal_code']); ?>"></div>
                        </div>
                        <button type="submit" class="jq-btn jq-btn-primary" style="margin-top:20px;"><i class="bx bx-save"></i> Save Changes</button>
                    </form>
                </div>

                <!-- Change password -->
                <div class="tab-pane fade <?php echo $active_tab === 'security' ? 'show active' : ''; ?>" id="pf-security" role="tabpanel">
                    <form method="POST" data-password-form="1" style="max-width:480px;" autocomplete="off">
                        <?php echo jq_csrf_field(); ?>
                        <input type="hidden" name="action" value="change_password">
                        <div class="jq-field" style="margin-bottom:16px;">
                            <label class="jq-label" for="currentPassword">Current password <span class="jq-req">*</span></label>
                            <input class="jq-input" type="password" name="current_password" id="currentPassword" required autocomplete="current-password">
                        </div>
                        <div class="jq-field" style="margin-bottom:16px;">
                            <label class="jq-label" for="newPassword">New password <span class="jq-req">*</span></label>
                            <input class="jq-input" type="password" name="new_password" id="newPassword" required minlength="<?php echo JQ_PASSWORD_MIN_LENGTH; ?>" maxlength="72" autocomplete="new-password">
                            <p class="jq-help">At least <?php echo JQ_PASSWORD_MIN_LENGTH; ?> characters.</p>
                        </div>
                        <div class="jq-field" style="margin-bottom:20px;">
                            <label class="jq-label" for="confirmPassword">Confirm new password <span class="jq-req">*</span></label>
                            <input class="jq-input" type="password" name="confirm_password" id="confirmPassword" required autocomplete="new-password">
                        </div>
                        <button type="submit" class="jq-btn jq-btn-primary"><i class="bx bx-lock-alt"></i> Change Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const PF_TYPES = ['image/jpeg', 'image/png', 'image/webp'], PF_NAME = /\.(jpe?g|png|webp)$/i, PF_MAX = <?php echo JQ_PROFILE_PHOTO_MAX_BYTES; ?>;
const pfOriginal = document.getElementById('pfAvatar').innerHTML;

function previewProfilePhoto(input) {
    const file = input.files[0];
    if (!file) return;
    const problem = !PF_TYPES.includes(file.type) || !PF_NAME.test(file.name)
        ? 'Please choose a JPG, JPEG, PNG or WebP image.'
        : (file.size > PF_MAX ? 'This photo is larger than 10 MB. Please choose a smaller one.' : '');
    if (problem) {
        input.value = '';
        Swal.fire({ icon: 'error', title: 'Photo not accepted', text: problem, confirmButtonColor: '#9370DB' });
        return;
    }
    const img = document.createElement('img');
    img.src = URL.createObjectURL(file);
    img.alt = 'Preview of your new profile photo';
    const avatar = document.getElementById('pfAvatar');
    avatar.innerHTML = '';
    avatar.appendChild(img);
    document.getElementById('photoChoose').hidden = true;
    document.getElementById('photoActions').classList.add('is-visible');
}
function cancelProfilePhoto() {
    document.getElementById('profilePhotoInput').value = '';
    document.getElementById('pfAvatar').innerHTML = pfOriginal;
    document.getElementById('photoChoose').hidden = false;
    document.getElementById('photoActions').classList.remove('is-visible');
}
<?php if ($error_message !== ''): ?>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({ icon: 'error', title: 'Not saved', text: <?php echo json_encode($error_message, JSON_HEX_TAG); ?>, confirmButtonColor: '#9370DB' });
});
<?php endif; ?>
</script>

<!-- Philippine Address Selector (wilfredpine/philippine-address-selector) -->
<script src="static/js/ph-address-selector.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    PhAddressSelector.init({
        prefix:          'profile_',
        regionId:        'profile_region',
        provinceId:      'profile_province',
        cityId:          'profile_city',
        barangayId:      'profile_barangay',
        regionTextId:    'profile_region_text',
        provinceTextId:  'profile_province_text',
        cityTextId:      'profile_city_text',
        barangayTextId:  'profile_barangay_text',
        defaultRegion:   <?php echo json_encode((string)($user_data['region_code'] ?? ''), JSON_HEX_TAG); ?>,
        defaultProvince: <?php echo json_encode((string)($user_data['province_code'] ?? ''), JSON_HEX_TAG); ?>,
        defaultCity:     <?php echo json_encode((string)($user_data['city_code'] ?? ''), JSON_HEX_TAG); ?>,
        defaultBarangay: <?php echo json_encode((string)($user_data['barangay_code'] ?? ''), JSON_HEX_TAG); ?>
    });
});
</script>

<script src="static/js/account-validation.js"></script>
<?php include 'includes/footer.php'; ?>
