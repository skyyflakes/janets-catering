<?php
/**
 * Food Menus Module - Janet's Quality Catering System
 * CRUD for Catering Packages, Customizable Food Items, and Food Bundles/Add-ons.
 * ADMIN and OWNER: full CRUD and photo upload
 */
$page_title = "Food Menus | Janet's Quality Catering";
$current_page = 'food_menus';

require_once 'includes/auth_check.php';
require_once 'includes/booking_manager.php';
$pdo = getDBConnection();
$is_admin = in_array(jq_require_roles($pdo ?? getDBConnection(), ['ADMIN', 'OWNER'], 'Food Menus'), ['ADMIN', 'OWNER'], true);

// Upload directory
$upload_dir = 'uploads/menus/';
jq_file_dir($upload_dir);

// -----------------------------------------------------------------
// Ensure tables exist (safe fallback if SQL file not yet imported)
// -----------------------------------------------------------------
if (!jq_is_pg()) try { // MySQL only: the PostgreSQL database is created complete
    $pdo->exec("CREATE TABLE IF NOT EXISTS `menu_packages` (
        `package_id` INT AUTO_INCREMENT PRIMARY KEY,
        `package_name` VARCHAR(150) NOT NULL,
        `tier` VARCHAR(50) DEFAULT 'Standard',
        `price_per_guest` DECIMAL(10,2) NOT NULL DEFAULT 0,
        `description` TEXT,
        `icon` VARCHAR(50) DEFAULT 'bx-restaurant',
        `photo_path` VARCHAR(500) DEFAULT NULL,
        `sort_order` INT DEFAULT 0,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `menu_items` (
        `item_id` INT AUTO_INCREMENT PRIMARY KEY,
        `item_name` VARCHAR(200) NOT NULL,
        `course` ENUM('Appetizers','Main Courses','Desserts','Beverages','Sides') NOT NULL DEFAULT 'Main Courses',
        `description` VARCHAR(255) DEFAULT NULL,
        `availability_note` VARCHAR(150) DEFAULT NULL,
        `upgrade_price` DECIMAL(10,2) DEFAULT 0,
        `photo_path` VARCHAR(500) DEFAULT NULL,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `menu_bundles` (
        `bundle_id` INT AUTO_INCREMENT PRIMARY KEY,
        `bundle_name` VARCHAR(200) NOT NULL,
        `description` TEXT,
        `bundle_type` ENUM('Food Bundle','Add-on','Service') DEFAULT 'Food Bundle',
        `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
        `price_mode` ENUM('flat','per_guest') DEFAULT 'flat',
        `photo_path` VARCHAR(500) DEFAULT NULL,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Tables already exist
}

// -----------------------------------------------------------------
// Seed the real Janet's catalog the first time the tables are empty
// -----------------------------------------------------------------
require_once 'includes/_seed_menus.php';
seedMenusIfEmpty($pdo);
jq_v25_fill_inclusions($pdo); // only touches rows with no inclusions

$menu_photo_error = null;

/** True when a path is a file this page manages, so it is safe to delete or keep. */
function isManagedMenuPhoto(?string $path, string $upload_dir): bool {
    return $path !== null && strpos($path, $upload_dir) === 0 && strpos($path, '..') === false;
}

/**
 * Handle a photo upload, returns saved path or $fallback.
 * A picture that fails validation keeps the current one and sets $menu_photo_error.
 */
function handleMenuPhoto($field, $upload_dir, $prefix, $fallback = null) {
    global $menu_photo_error;
    $fallback = isManagedMenuPhoto($fallback ?: null, $upload_dir) ? $fallback : null;
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return $fallback;
    }
    $ext = jq_image_upload_extension($_FILES[$field], $menu_photo_error);
    if ($ext === null) {
        return $fallback;
    }
    $path = $upload_dir . $prefix . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!jq_file_save_upload($_FILES[$field]['tmp_name'], $path)) {
        $menu_photo_error = 'The picture could not be saved. Please try again.';
        return $fallback;
    }
    if ($fallback && jq_file_exists($fallback)) {
        jq_file_delete($fallback); // only after the new picture is safely stored
    }
    return $path;
}

// -----------------------------------------------------------------
// Handle POST actions (Admin only)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    $action = $_POST['form_action'] ?? '';
    try {
        switch ($action) {
            // ---------- PACKAGES ----------
            case 'add_package':
            case 'edit_package':
                $id = (int)($_POST['package_id'] ?? 0);
                $photo = handleMenuPhoto('photo', $upload_dir, 'pkg', $action === 'edit_package' ? ($_POST['existing_photo'] ?? null) : null);
                $values = [
                    sanitize($_POST['package_name']),
                    sanitize($_POST['tier'] ?? 'Standard'),
                    (float)$_POST['price_per_guest'],
                    mb_substr(sanitize($_POST['pricing_note'] ?? ''), 0, 150) ?: null,
                    $photo,
                ];
                // The package, its inclusions and its event types are saved together or not at all.
                $pdo->beginTransaction();
                if ($action === 'add_package') {
                    // A new package goes last in the list and uses the table's default icon.
                    $next_order = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_packages")->fetchColumn();
                    $pdo->prepare("INSERT INTO menu_packages (package_name, tier, price_per_guest, pricing_note, photo_path, sort_order) VALUES (?, ?, ?, ?, ?, ?)")->execute(array_merge($values, [$next_order]));
                    $id = jq_insert_id($pdo, 'menu_packages', 'package_id');
                } else {
                    $pdo->prepare("UPDATE menu_packages SET package_name=?, tier=?, price_per_guest=?, pricing_note=?, photo_path=?, sort_order=? WHERE package_id=?")->execute(array_merge($values, [(int)($_POST['sort_order'] ?? 0), $id]));
                }
                jq_save_catalog_inclusions($pdo, 'package', $id, (string)($_POST['inclusions'] ?? ''));
                $pdo->prepare("DELETE FROM package_event_types WHERE package_id = ?")->execute([$id]);
                $link = $pdo->prepare("INSERT INTO package_event_types (package_id, event_type_id) SELECT ?, event_type_id FROM event_types WHERE event_type_id = ?");
                foreach (array_map('intval', (array)($_POST['event_types'] ?? [])) as $typeId) {
                    $link->execute([$id, $typeId]);
                }
                $pdo->commit();
                setFlash($action === 'add_package' ? 'Package added successfully!' : 'Package updated successfully!', 'success');
                break;

            case 'delete_package':
                $id = (int)$_POST['package_id'];
                $stmt = $pdo->prepare("SELECT photo_path FROM menu_packages WHERE package_id=?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row && $row['photo_path']) jq_file_delete($row['photo_path']);
                $pdo->prepare("DELETE FROM menu_packages WHERE package_id=?")->execute([$id]);
                setFlash('Package deleted.', 'success');
                break;

            // ---------- FOOD ITEMS ----------
            case 'add_item':
                $photo = handleMenuPhoto('photo', $upload_dir, 'item');
                $stmt = $pdo->prepare("INSERT INTO menu_items (item_name, course, description, availability_note, upgrade_price, photo_path) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    sanitize($_POST['item_name']),
                    sanitize($_POST['course']),
                    sanitize($_POST['description'] ?? ''),
                    sanitize($_POST['availability_note'] ?? ''),
                    (float)($_POST['upgrade_price'] ?? 0),
                    $photo
                ]);
                setFlash('Food item added successfully!', 'success');
                break;

            case 'edit_item':
                $redirect_tab = '#tab-items';
                $id = (int)$_POST['item_id'];
                $photo = handleMenuPhoto('photo', $upload_dir, 'item', $_POST['existing_photo'] ?? null);
                $stmt = $pdo->prepare("UPDATE menu_items SET item_name=?, course=?, description=?, availability_note=?, upgrade_price=?, photo_path=? WHERE item_id=?");
                $stmt->execute([
                    sanitize($_POST['item_name']),
                    sanitize($_POST['course']),
                    sanitize($_POST['description'] ?? ''),
                    sanitize($_POST['availability_note'] ?? ''),
                    (float)($_POST['upgrade_price'] ?? 0),
                    $photo,
                    $id
                ]);
                setFlash('Food item updated successfully!', 'success');
                break;

            case 'delete_item':
                $id = (int)$_POST['item_id'];
                $stmt = $pdo->prepare("SELECT photo_path FROM menu_items WHERE item_id=?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row && $row['photo_path']) jq_file_delete($row['photo_path']);
                $pdo->prepare("DELETE FROM menu_items WHERE item_id=?")->execute([$id]);
                setFlash('Food item deleted.', 'success');
                break;

            // ---------- BUNDLES ----------
            case 'add_bundle':
            case 'edit_bundle':
                $redirect_tab = '#tab-bundles';
                $id = (int)($_POST['bundle_id'] ?? 0);
                $photo = handleMenuPhoto('photo', $upload_dir, 'bundle', $action === 'edit_bundle' ? ($_POST['existing_photo'] ?? null) : null);
                $values = [
                    sanitize($_POST['bundle_name']),
                    sanitize($_POST['bundle_type']),
                    (float)$_POST['price'],
                    sanitize($_POST['price_mode']),
                    mb_substr(sanitize($_POST['order_note'] ?? ''), 0, 150) ?: null,
                    $photo,
                    // Guests one order serves; per-guest prices already cover every guest.
                    sanitize($_POST['price_mode']) === 'per_guest' ? null : (filter_var($_POST['guests_covered'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) ?: null),
                ];
                $pdo->beginTransaction();
                if ($action === 'add_bundle') {
                    $pdo->prepare("INSERT INTO menu_bundles (bundle_name, bundle_type, price, price_mode, order_note, photo_path, guests_covered) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute($values);
                    $id = jq_insert_id($pdo, 'menu_bundles', 'bundle_id');
                } else {
                    $pdo->prepare("UPDATE menu_bundles SET bundle_name=?, bundle_type=?, price=?, price_mode=?, order_note=?, photo_path=?, guests_covered=? WHERE bundle_id=?")->execute(array_merge($values, [$id]));
                }
                jq_save_catalog_inclusions($pdo, 'bundle', $id, (string)($_POST['inclusions'] ?? ''));
                $pdo->commit();
                setFlash($action === 'add_bundle' ? 'Bundle added successfully!' : 'Bundle updated successfully!', 'success');
                break;

            case 'delete_bundle':
                $id = (int)$_POST['bundle_id'];
                $stmt = $pdo->prepare("SELECT photo_path FROM menu_bundles WHERE bundle_id=?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row && $row['photo_path']) jq_file_delete($row['photo_path']);
                $pdo->prepare("DELETE FROM menu_bundles WHERE bundle_id=?")->execute([$id]);
                setFlash('Bundle deleted.', 'success');
                break;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('food_menus.php: ' . $e->getMessage());
        setFlash(jq_is_duplicate($e) ? 'That name already exists. Each package, bundle and food item needs its own name.' : 'The change could not be saved. Nothing was changed.', 'danger');
    }
    if ($menu_photo_error) {
        setFlash('The picture was not saved: ' . $menu_photo_error, 'danger');
    }
    redirect('food_menus.php' . ($redirect_tab ?? ''));
}

// -----------------------------------------------------------------
// Fetch data
// -----------------------------------------------------------------
$packages = $pdo->query("SELECT * FROM menu_packages ORDER BY sort_order ASC, price_per_guest ASC")->fetchAll();
$items     = $pdo->query("SELECT * FROM menu_items ORDER BY course ASC, item_name ASC")->fetchAll();
$bundles   = $pdo->query("SELECT * FROM menu_bundles ORDER BY bundle_type ASC, bundle_name ASC")->fetchAll();

$package_inclusions = jq_all_inclusions($pdo, 'package');
$bundle_inclusions = jq_all_inclusions($pdo, 'bundle');
$all_event_types = jq_event_types($pdo);
$package_types = [];
foreach ($pdo->query("SELECT package_id, event_type_id FROM package_event_types") as $row) {
    $package_types[(int)$row['package_id']][] = (int)$row['event_type_id'];
}
$courses = ['Appetizers','Main Courses','Desserts','Beverages','Sides'];

include 'includes/header.php';
?>

<style>
    .menu-tabs .nav-link { color: var(--body-color); font-weight: 600; border: none; border-bottom: 3px solid transparent; }
    .menu-tabs .nav-link.active { color: var(--bs-primary); background: transparent; border-bottom-color: var(--bs-primary); }

    /* Card = flex column so the action row can be pinned to the bottom */
    .menu-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; width: 100%; height: 100%; display: flex; flex-direction: column; transition: all .25s; }
    .menu-card:hover { transform: translateY(-4px); box-shadow: 0 10px 28px rgba(147,112,219,.15); }

    .menu-thumb { height: 160px; flex-shrink: 0; background: linear-gradient(135deg, var(--bs-primary) 0%, #8B5CF6 100%); display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative; }
    .menu-thumb img { width:100%; height:100%; object-fit:cover; }
    .menu-thumb .no-img { color:#fff; font-size:2.6rem; }
    .menu-thumb .price-tag { position:absolute; bottom:10px; right:10px; background: rgba(0,0,0,.6); color:#fff; padding:4px 12px; border-radius:20px; font-weight:700; font-size:.85rem; }

    /* Body grows to fill the card; description takes the free space */
    .menu-body { padding:16px; display:flex; flex-direction:column; flex:1 1 auto; }
    .menu-title { font-weight:700; color:var(--heading-color); font-size:1.05rem; margin-bottom:4px; }
    .menu-sub { color:var(--bs-secondary); font-size:.8rem; }
    .menu-desc { color:var(--body-color); font-size:.85rem; line-height:1.5; margin:10px 0; min-height:40px; flex-grow:1; }
    .menu-list { margin:10px 0; padding-left:18px; color:var(--body-color); font-size:.85rem; line-height:1.45; flex-grow:1; max-height:230px; overflow-y:auto; }
    .menu-list li { margin-bottom:2px; }
    .menu-note { font-size:.78rem; font-weight:600; color:var(--body-color); }
    .tier-badge { display:inline-block; align-self:flex-start; padding:2px 10px; border-radius:20px; font-size:.7rem; font-weight:700; text-transform:uppercase; }

    /* Buttons always sit at the very bottom, same size on every card */
    .menu-actions { display:flex; gap:8px; margin-top:auto; padding-top:10px; align-items:stretch; width:100%; }
    .menu-actions .btn-edit { flex:1 1 auto; height:38px; display:flex; align-items:center; justify-content:center; }
    .menu-actions .btn-label-danger { flex:0 0 42px; height:38px; display:flex; align-items:center; justify-content:center; padding:0; }

    .tier-Silver { background:rgba(148,163,184,.2); color:#64748b; }
    .tier-Gold { background:rgba(255,193,7,.18); color:#b8860b; }
    .tier-Platinum { background:rgba(99,102,241,.16); color:#6366f1; }
    .stat-card { background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:18px; text-align:center; }
    .stat-card .v { font-size:1.7rem; font-weight:700; color:var(--heading-color); }
    .stat-card .l { font-size:.78rem; color:var(--bs-secondary); text-transform:uppercase; letter-spacing:.5px; }
    .photo-preview { max-width:200px; max-height:140px; border-radius:10px; border:2px solid var(--border-color); margin-top:10px; }
    .empty-state { text-align:center; padding:50px 20px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:16px; }
    .empty-state i { font-size:3.5rem; color:var(--bs-secondary); opacity:.3; }
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <h4 class="page-title mb-1">Food Menus</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Food Menus</li>
            </ol>
        </nav>
    </div>
</div>

<!-- Stats -->
<div class="row mb-4">
    <div class="col-md-4 col-6 mb-3"><div class="stat-card"><div class="v"><?php echo count($packages); ?></div><div class="l">Packages</div></div></div>
    <div class="col-md-4 col-6 mb-3"><div class="stat-card"><div class="v"><?php echo count($items); ?></div><div class="l">Food Items</div></div></div>
    <div class="col-md-4 col-12 mb-3"><div class="stat-card"><div class="v"><?php echo count($bundles); ?></div><div class="l">Bundles & Add-ons</div></div></div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs menu-tabs mb-4" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-packages" type="button"><i class="bx bx-package me-1"></i> Packages</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-items" type="button"><i class="bx bx-dish me-1"></i> Food Items</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-bundles" type="button"><i class="bx bx-gift me-1"></i> Bundles & Add-ons</button></li>
</ul>

<div class="tab-content">
    <!-- ============ PACKAGES TAB ============ -->
    <div class="tab-pane fade show active" id="tab-packages">
        <?php if ($is_admin): ?>
        <div class="d-flex justify-content-end mb-3">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPackageModal"><i class="bx bx-plus me-1"></i> Add Package</button>
        </div>
        <?php endif; ?>
        <?php if (empty($packages)): ?>
        <div class="empty-state"><i class="bx bx-package"></i><h5 class="mt-3" style="color:var(--heading-color);">No packages yet</h5></div>
        <?php else: ?>
        <div class="row">
            <?php foreach ($packages as $p): ?>
            <div class="col-lg-4 col-md-6 mb-4 d-flex">
                <div class="menu-card">
                    <div class="menu-thumb">
                        <?php if ($p['photo_path'] && jq_file_exists($p['photo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($p['photo_path']); ?>" alt="<?php echo htmlspecialchars($p['package_name']); ?>">
                        <?php else: ?>
                        <i class="bx <?php echo htmlspecialchars($p['icon'] ?: 'bx-restaurant'); ?> no-img"></i>
                        <?php endif; ?>
                        <span class="price-tag">₱<?php echo number_format($p['price_per_guest'], 0); ?>/guest</span>
                    </div>
                    <div class="menu-body">
                        <span class="tier-badge tier-<?php echo htmlspecialchars($p['tier']); ?>"><?php echo htmlspecialchars($p['tier']); ?></span>
                        <div class="menu-title mt-2"><?php echo htmlspecialchars($p['package_name']); ?></div>
                        <?php if (!empty($p['pricing_note'])): ?><div class="menu-note"><?php echo htmlspecialchars($p['pricing_note']); ?></div><?php endif; ?>
                        <ul class="menu-list"><?php foreach ($package_inclusions[(int)$p['package_id']] ?? [] as $inc): ?><li><?php echo htmlspecialchars($inc); ?></li><?php endforeach; ?></ul>
                        <?php if ($is_admin): ?>
                        <div class="menu-actions">
                            <button class="btn btn-sm btn-label-primary btn-edit" onclick="editPackage(<?php echo htmlspecialchars(json_encode($p + ['inclusions' => $package_inclusions[(int)$p['package_id']] ?? [], 'event_types' => $package_types[(int)$p['package_id']] ?? []]), ENT_QUOTES); ?>)"><i class="bx bx-edit me-1"></i> Edit</button>
                            <button class="btn btn-sm btn-label-danger" onclick="deleteEntity('delete_package','package_id',<?php echo $p['package_id']; ?>,'<?php echo htmlspecialchars(addslashes($p['package_name'])); ?>')"><i class="bx bx-trash"></i></button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============ FOOD ITEMS TAB ============ -->
    <div class="tab-pane fade" id="tab-items">
        <?php if ($is_admin): ?>
        <div class="d-flex justify-content-end mb-3">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal"><i class="bx bx-plus me-1"></i> Add Food Item</button>
        </div>
        <?php endif; ?>
        <?php if (empty($items)): ?>
        <div class="empty-state"><i class="bx bx-dish"></i><h5 class="mt-3" style="color:var(--heading-color);">No food items yet</h5></div>
        <?php else: ?>
        <div class="row">
            <?php foreach ($items as $it): ?>
            <div class="col-lg-3 col-md-6 mb-4 d-flex">
                <div class="menu-card">
                    <div class="menu-thumb">
                        <?php if ($it['photo_path'] && jq_file_exists($it['photo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($it['photo_path']); ?>" alt="<?php echo htmlspecialchars($it['item_name']); ?>" loading="lazy">
                        <?php else: ?>
                        <i class="bx bx-dish no-img"></i>
                        <?php endif; ?>
                        <span class="price-tag"><?php echo $it['upgrade_price'] > 0 ? '+₱'.number_format($it['upgrade_price'],0) : 'Included'; ?></span>
                    </div>
                    <div class="menu-body">
                        <div class="menu-sub"><i class="bx bx-bowl-hot me-1"></i><?php echo htmlspecialchars($it['course']); ?></div>
                        <div class="menu-title mt-1"><?php echo htmlspecialchars($it['item_name']); ?></div>
                        <?php if ($it['availability_note']): ?><div class="menu-sub">(<?php echo htmlspecialchars($it['availability_note']); ?>)</div><?php endif; ?>
                        <p class="menu-desc"><?php echo htmlspecialchars($it['description']); ?></p>
                        <?php if ($is_admin): ?>
                        <div class="menu-actions">
                            <button class="btn btn-sm btn-label-primary btn-edit" onclick="editItem(<?php echo htmlspecialchars(json_encode($it), ENT_QUOTES); ?>)"><i class="bx bx-edit me-1"></i> Edit</button>
                            <button class="btn btn-sm btn-label-danger" onclick="deleteEntity('delete_item','item_id',<?php echo $it['item_id']; ?>,'<?php echo htmlspecialchars(addslashes($it['item_name'])); ?>')"><i class="bx bx-trash"></i></button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============ BUNDLES TAB ============ -->
    <div class="tab-pane fade" id="tab-bundles">
        <?php if ($is_admin): ?>
        <div class="d-flex justify-content-end mb-3">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBundleModal"><i class="bx bx-plus me-1"></i> Add Bundle</button>
        </div>
        <?php endif; ?>
        <?php if (empty($bundles)): ?>
        <div class="empty-state"><i class="bx bx-gift"></i><h5 class="mt-3" style="color:var(--heading-color);">No bundles yet</h5></div>
        <?php else: ?>
        <div class="row">
            <?php foreach ($bundles as $b): ?>
            <div class="col-lg-4 col-md-6 mb-4 d-flex">
                <div class="menu-card">
                    <div class="menu-thumb">
                        <?php if ($b['photo_path'] && jq_file_exists($b['photo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($b['photo_path']); ?>" alt="<?php echo htmlspecialchars($b['bundle_name']); ?>" loading="lazy">
                        <?php else: ?>
                        <i class="bx bx-gift no-img"></i>
                        <?php endif; ?>
                        <span class="price-tag">₱<?php echo number_format($b['price'],0); ?><?php echo $b['price_mode']==='per_guest'?'/guest':''; ?></span>
                    </div>
                    <div class="menu-body">
                        <div class="menu-sub"><i class="bx bx-purchase-tag me-1"></i><?php echo htmlspecialchars($b['bundle_type']); ?></div>
                        <div class="menu-title mt-1"><?php echo htmlspecialchars($b['bundle_name']); ?></div>
                        <?php if (!empty($b['order_note'])): ?><div class="menu-note"><?php echo htmlspecialchars($b['order_note']); ?></div><?php endif; ?>
                        <div class="menu-note"><i class="bx bx-group me-1"></i><?php echo $b['price_mode'] === 'per_guest' ? 'Every guest' : (!empty($b['guests_covered']) ? (int)$b['guests_covered'] . ' guests per table' : 'Guests per table not set'); ?></div>
                        <ul class="menu-list"><?php foreach ($bundle_inclusions[(int)$b['bundle_id']] ?? [] as $inc): ?><li><?php echo htmlspecialchars($inc); ?></li><?php endforeach; ?></ul>
                        <?php if ($is_admin): ?>
                        <div class="menu-actions">
                            <button class="btn btn-sm btn-label-primary btn-edit" onclick="editBundle(<?php echo htmlspecialchars(json_encode($b + ['inclusions' => $bundle_inclusions[(int)$b['bundle_id']] ?? []]), ENT_QUOTES); ?>)"><i class="bx bx-edit me-1"></i> Edit</button>
                            <button class="btn btn-sm btn-label-danger" onclick="deleteEntity('delete_bundle','bundle_id',<?php echo $b['bundle_id']; ?>,'<?php echo htmlspecialchars(addslashes($b['bundle_name'])); ?>')"><i class="bx bx-trash"></i></button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($is_admin): ?>
<!-- ===================== PACKAGE MODALS ===================== -->
<div class="modal fade" id="addPackageModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bx bx-plus-circle me-2" style="color:var(--bs-primary);"></i>Add Package</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST" enctype="multipart/form-data"><input type="hidden" name="form_action" value="add_package">
        <div class="modal-body"><?php $pkg_form_edit = false; include 'includes/_package_form_fields.php'; ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bx bx-save me-1"></i> Save</button></div>
    </form>
</div></div></div>

<div class="modal fade" id="editPackageModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bx bx-edit me-2" style="color:var(--bs-primary);"></i>Edit Package</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST" enctype="multipart/form-data"><input type="hidden" name="form_action" value="edit_package"><input type="hidden" name="package_id" id="e_pkg_id"><input type="hidden" name="existing_photo" id="e_pkg_photo">
        <div class="modal-body"><?php $pkg_form_edit = true; include 'includes/_package_form_fields.php'; ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bx bx-save me-1"></i> Update</button></div>
    </form>
</div></div></div>

<!-- ===================== ITEM MODALS ===================== -->
<div class="modal fade" id="addItemModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bx bx-plus-circle me-2" style="color:var(--bs-primary);"></i>Add Food Item</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST" enctype="multipart/form-data"><input type="hidden" name="form_action" value="add_item">
        <div class="modal-body"><?php include 'includes/_item_form_fields.php'; ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bx bx-save me-1"></i> Save</button></div>
    </form>
</div></div></div>

<div class="modal fade" id="editItemModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bx bx-edit me-2" style="color:var(--bs-primary);"></i>Edit Food Item</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST" enctype="multipart/form-data"><input type="hidden" name="form_action" value="edit_item"><input type="hidden" name="item_id" id="e_item_id"><input type="hidden" name="existing_photo" id="e_item_photo">
        <div class="modal-body"><?php include 'includes/_item_form_fields.php'; ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bx bx-save me-1"></i> Update</button></div>
    </form>
</div></div></div>

<!-- ===================== BUNDLE MODALS ===================== -->
<div class="modal fade" id="addBundleModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bx bx-plus-circle me-2" style="color:var(--bs-primary);"></i>Add Bundle</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST" enctype="multipart/form-data"><input type="hidden" name="form_action" value="add_bundle">
        <div class="modal-body"><?php include 'includes/_bundle_form_fields.php'; ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bx bx-save me-1"></i> Save</button></div>
    </form>
</div></div></div>

<div class="modal fade" id="editBundleModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bx bx-edit me-2" style="color:var(--bs-primary);"></i>Edit Bundle</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST" enctype="multipart/form-data"><input type="hidden" name="form_action" value="edit_bundle"><input type="hidden" name="bundle_id" id="e_bundle_id"><input type="hidden" name="existing_photo" id="e_bundle_photo">
        <div class="modal-body"><?php include 'includes/_bundle_form_fields.php'; ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bx bx-save me-1"></i> Update</button></div>
    </form>
</div></div></div>
<?php endif; ?>

<script>
// Reopen the tab the user was on after saving
document.addEventListener('DOMContentLoaded', function () {
    const t = location.hash && document.querySelector('[data-bs-target="' + location.hash + '"]');
    if (t) bootstrap.Tab.getOrCreateInstance(t).show();
});

function previewPhoto(input, targetId) {
    const img = document.getElementById(targetId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; img.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    }
}

function editPackage(p) {
    const f = document.querySelector('#editPackageModal form');
    f.package_name.value = p.package_name;
    f.tier.value = p.tier;
    f.price_per_guest.value = p.price_per_guest;
    f.inclusions.value = (p.inclusions || []).join('\n');
    f.pricing_note.value = p.pricing_note || '';
    f.querySelectorAll('input[name="event_types[]"]').forEach(c => { c.checked = (p.event_types || []).includes(parseInt(c.value, 10)); });
    f.sort_order.value = p.sort_order || 0;
    document.getElementById('e_pkg_id').value = p.package_id;
    document.getElementById('e_pkg_photo').value = p.photo_path || '';
    new bootstrap.Modal(document.getElementById('editPackageModal')).show();
}

function editItem(it) {
    const f = document.querySelector('#editItemModal form');
    f.photo.value = '';
    const preview = f.querySelector('.photo-preview');
    preview.src = it.photo_path || '';
    preview.style.display = it.photo_path ? 'block' : 'none';
    f.item_name.value = it.item_name;
    f.course.value = it.course;
    f.description.value = it.description || '';
    f.availability_note.value = it.availability_note || '';
    f.upgrade_price.value = it.upgrade_price;
    document.getElementById('e_item_id').value = it.item_id;
    document.getElementById('e_item_photo').value = it.photo_path || '';
    new bootstrap.Modal(document.getElementById('editItemModal')).show();
}

function editBundle(b) {
    const f = document.querySelector('#editBundleModal form');
    f.bundle_name.value = b.bundle_name;
    f.bundle_type.value = b.bundle_type;
    f.price.value = b.price;
    f.price_mode.value = b.price_mode;
    f.inclusions.value = (b.inclusions || []).join('\n');
    f.order_note.value = b.order_note || '';
    f.guests_covered.value = b.guests_covered || '';
    document.getElementById('e_bundle_id').value = b.bundle_id;
    document.getElementById('e_bundle_photo').value = b.photo_path || '';
    new bootstrap.Modal(document.getElementById('editBundleModal')).show();
}

function deleteEntity(action, field, id, name) {
    Swal.fire({
        title: 'Delete?',
        html: `Delete <strong>"${escapeHtml(name)}"</strong>?<br><small class="text-muted">This cannot be undone.</small>`,
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#ff3e1d', cancelButtonColor: '#8592a3',
        confirmButtonText: '<i class="bx bx-trash me-1"></i> Yes, delete', cancelButtonText: 'Cancel', reverseButtons: true
    }).then(r => {
        if (r.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="form_action" value="${action}"><input type="hidden" name="${field}" value="${id}">`;
            document.body.appendChild(form);
            showLoading('Deleting...');
            form.submit();
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>