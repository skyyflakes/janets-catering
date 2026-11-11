<?php
/**
 * Backdrops Gallery Page - Janet's Quality Catering System
 * Shows backdrops based on Event Type selected from sidebar
 */
$page_title = "Backdrops | Janet's Quality Catering";
$current_page = 'backdrops';

require_once 'includes/auth_check.php';

// Check if coming from event booking form
$from_booking = isset($_SESSION['event_form_data']);

// Event types mapped to backdrop folders
$event_type_folders = [
    'Weddings' => [
        'name' => 'Wedding Reception',
        'icon' => 'bx-heart',
        'folder' => 'Weddings'
    ],
    'Birthdays' => [
        'name' => 'Birthday Party',
        'icon' => 'bx-cake',
        'folder' => 'Birthdays'
    ],
    'Debut' => [
        'name' => 'Debut (18th Birthday)',
        'icon' => 'bx-crown',
        'folder' => 'Debut'
    ],
    'Kiddie Birthday' => [
        'name' => 'Kiddie Birthday',
        'icon' => 'bx-child',
        'folder' => 'Kiddie Birthday'
    ],
    'Christmas Party' => [
        'name' => 'Christmas Party',
        'icon' => 'bx-gift',
        'folder' => 'Christmas Party'
    ],
    'Seminar' => [
        'name' => 'Seminar / Conference',
        'icon' => 'bx-briefcase',
        'folder' => 'Seminar'
    ],
    'Parties' => [
        'name' => 'General Parties',
        'icon' => 'bx-party',
        'folder' => 'Parties'
    ],
    'WeddingAnniv' => [
        'name' => 'Wedding Anniversary',
        'icon' => 'bx-calendar-heart',
        'folder' => 'WeddingAnniv'
    ],
    'RenewalofVows' => [
        'name' => 'Renewal of Vows',
        'icon' => 'bx-heart-circle',
        'folder' => 'RenewalofVows'
    ]
];

// Get selected event type from URL (required - no 'all' option)
$selected_type = $_GET['type'] ?? '';

// Function to get backdrops from a folder
function getBackdropsFromFolder($folder) {
    $path = "static/images/" . $folder;
    $backdrops = [];
    
    if (is_dir($path)) {
        $files = scandir($path);
        foreach ($files as $file) {
            if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $file)) {
                $backdrops[] = [
                    'filename' => $file,
                    'path' => $folder . '/' . $file,
                    'folder' => $folder
                ];
            }
        }
    }
    return $backdrops;
}

// Get backdrops only if a type is selected
$display_backdrops = [];
$selected_type_info = null;
if ($selected_type && isset($event_type_folders[$selected_type])) {
    $selected_type_info = $event_type_folders[$selected_type];
    $display_backdrops = getBackdropsFromFolder($selected_type_info['folder']);
}

// Map backdrop folder -> event type used in the booking wizard
$folder_to_event_type = [
    'Weddings'        => 'Wedding',
    'Birthdays'       => 'Birthday',
    'Debut'           => 'Debut',
    'Kiddie Birthday' => 'Kiddie Birthday',
    'Christmas Party' => 'Christmas Party',
    'Seminar'         => 'Seminar',
    'Parties'         => 'General Party',
    'WeddingAnniv'    => 'Anniversary',
    'RenewalofVows'   => 'Renewal of Vows'
];

// ─────────────────────────────────────────────────────────────
// AJAX: save / clear selected backdrop (no redirect)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $ajax_action = $_POST['ajax_action'];

    if ($ajax_action === 'save_backdrop') {
        $raw_path = trim($_POST['photo_path'] ?? '');
        $parts    = explode('/', $raw_path);

        // Must be exactly "Folder/filename.ext" with a known folder and an existing file
        if (count($parts) === 2
            && isset($event_type_folders[$parts[0]])
            && $parts[1] === basename($parts[1])
            && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $parts[1])
            && is_file('static/images/' . $parts[0] . '/' . $parts[1])) {

            $_SESSION['selected_backdrop']      = $raw_path;
            $_SESSION['selected_backdrop_type'] = $parts[0];
            echo json_encode([
                'success'  => true,
                'message'  => 'Backdrop saved successfully!',
                'path'     => $raw_path,
                'filename' => $parts[1],
                'proceed'  => 'event_bookings.php?view=wizard'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid backdrop selected.']);
        }
        exit;
    }

    if ($ajax_action === 'clear_backdrop') {
        unset($_SESSION['selected_backdrop'], $_SESSION['selected_backdrop_type']);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

$saved_backdrop = $_SESSION['selected_backdrop'] ?? '';
$proceed_url    = 'event_bookings.php?view=wizard';

require_once 'includes/header.php';
?>

<style>
/* Gallery Grid */
.gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 24px;
}

.gallery-item {
    background: var(--card-bg);
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid var(--border-color);
    transition: all 0.3s;
    position: relative;
}

.gallery-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(147, 112, 219, 0.2);
    border-color: var(--bs-primary);
}

.gallery-item.selected {
    border-color: var(--bs-success);
    box-shadow: 0 0 20px rgba(113, 221, 55, 0.3);
}

.gallery-item .image-container {
    height: 180px;
    overflow: hidden;
    background: #000;
}

.gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s;
}

.gallery-item:hover img {
    transform: scale(1.1);
}

.gallery-item .item-info {
    padding: 16px;
}

.gallery-item .item-title {
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--heading-color);
    margin-bottom: 8px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.gallery-item .item-folder {
    font-size: 0.8rem;
    color: var(--bs-secondary);
    display: flex;
    align-items: center;
    gap: 5px;
}

.gallery-item .item-actions {
    display: flex;
    gap: 10px;
    margin-top: 12px;
}

.gallery-item .btn-icon {
    width: 36px;
    height: 36px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
}

.selected-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: var(--bs-success);
    color: white;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

/* Empty State / Welcome State */
.welcome-state {
    text-align: center;
    padding: 80px 30px;
    background: var(--card-bg);
    border-radius: 20px;
    border: 2px dashed var(--border-color);
}

.welcome-state .icon-container {
    width: 120px;
    height: 120px;
    margin: 0 auto 30px;
    background: linear-gradient(135deg, rgba(147, 112, 219, 0.1) 0%, rgba(139, 92, 246, 0.15) 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.welcome-state .icon-container i {
    font-size: 4rem;
    color: var(--bs-primary);
}

.welcome-state h4 {
    color: var(--heading-color);
    margin-bottom: 15px;
    font-weight: 700;
}

.welcome-state p {
    color: var(--bs-secondary);
    max-width: 500px;
    margin: 0 auto 30px;
    font-size: 1rem;
    line-height: 1.6;
}

.event-type-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    max-width: 800px;
    margin: 0 auto;
}

.event-type-card {
    background: var(--body-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px 15px;
    text-decoration: none;
    color: var(--body-color);
    transition: all 0.3s;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.event-type-card:hover {
    border-color: var(--bs-primary);
    background: linear-gradient(135deg, rgba(147, 112, 219, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(147, 112, 219, 0.15);
}

.event-type-card i {
    font-size: 2rem;
    color: var(--bs-primary);
}

.event-type-card span {
    font-weight: 600;
    font-size: 0.85rem;
    text-align: center;
}

/* Empty Category State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--card-bg);
    border-radius: 16px;
    border: 1px solid var(--border-color);
}

.empty-state i {
    font-size: 4rem;
    color: var(--bs-secondary);
    opacity: 0.5;
}

.empty-state h5 {
    color: var(--heading-color);
    margin: 20px 0 10px;
}

.empty-state p {
    color: var(--bs-secondary);
}


.select-modal-img {
    width: 100%;
    max-height: 55vh;
    object-fit: contain;
    background: #000;
    border-radius: 12px;
}

.selected-bar .btn { white-space: nowrap; }

@media (max-width: 768px) {
    .gallery-grid {
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
        gap: 15px;
    }
    
    .gallery-item .image-container {
        height: 140px;
    }
    
    .event-type-cards {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <h4 class="page-title mb-1">
            <?php if ($selected_type_info): ?>
                <i class="bx <?php echo $selected_type_info['icon']; ?> me-2" style="color: var(--bs-primary);"></i>
                <?php echo htmlspecialchars($selected_type_info['name']); ?> Backdrops
            <?php else: ?>
                Backdrops Gallery
            <?php endif; ?>
        </h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item <?php echo !$selected_type ? 'active' : ''; ?>">
                    <?php if ($selected_type): ?>
                        <a href="backdrops.php">Backdrops</a>
                    <?php else: ?>
                        Backdrops
                    <?php endif; ?>
                </li>
                <?php if ($selected_type_info): ?>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($selected_type_info['name']); ?></li>
                <?php endif; ?>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if ($from_booking): ?>
        <a href="event_bookings.php?view=wizard" class="btn btn-secondary">
            <i class="bx bx-arrow-back me-1"></i>Back to Booking
        </a>
        <?php endif; ?>
        <?php if ($selected_type): ?>
        <div class="input-group" style="width: 280px;">
            <span class="input-group-text" style="background: var(--card-bg); border-color: var(--border-color);">
                <i class="bx bx-search"></i>
            </span>
            <input type="text" id="searchInput" class="form-control" placeholder="Search backdrops..." onkeyup="filterBackdrops()">
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($from_booking): ?>
<div class="alert alert-info mb-4">
    <i class="bx bx-info-circle me-2"></i>
    You are selecting a backdrop for your event booking. Select an Event Type from the sidebar, then choose a backdrop.
</div>
<?php endif; ?>

<!-- Currently selected / Proceed bar -->
<div class="alert alert-success mb-4 selected-bar" id="selectedBar" style="<?php echo $saved_backdrop ? '' : 'display:none;'; ?>">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <i class="bx bx-check-circle me-2"></i>
            Currently selected: <strong id="selectedBarName"><?php echo htmlspecialchars($saved_backdrop); ?></strong>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearBackdrop()">
                <i class="bx bx-x me-1"></i>Clear
            </button>
            <a href="<?php echo $proceed_url; ?>" class="btn btn-sm btn-primary" id="proceedBtn">
                <i class="bx bx-right-arrow-alt me-1"></i>Proceed to Booking
            </a>
        </div>
    </div>
</div>

<?php if (!$selected_type): ?>
<!-- Welcome State - No Event Type Selected -->
<div class="welcome-state">
    <div class="icon-container">
        <i class="bx bx-image-alt"></i>
    </div>
    <h4>Select an Event Type</h4>
    <p>
        Choose an event type from the <strong>Backdrops</strong> dropdown menu in the sidebar to view available backdrop photos for that category.
    </p>
    
    <div class="event-type-cards">
        <?php foreach ($event_type_folders as $key => $info): ?>
        <a href="backdrops.php?type=<?php echo urlencode($key); ?>" class="event-type-card">
            <i class="bx <?php echo $info['icon']; ?>"></i>
            <span><?php echo $info['name']; ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php elseif (empty($display_backdrops)): ?>
<!-- Empty State -->
<div class="empty-state">
    <i class="bx bx-image"></i>
    <h5>No Backdrops Found</h5>
    <p>No backdrop photos available for <?php echo htmlspecialchars($selected_type_info['name']); ?>.</p>
    <a href="backdrops.php" class="btn btn-primary mt-3">
        <i class="bx bx-arrow-back me-1"></i>Choose Another Category
    </a>
</div>

<?php else: ?>
<!-- Gallery Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <p class="text-muted mb-0">
        <i class="bx bx-photo-album me-1"></i>
        <?php echo count($display_backdrops); ?> backdrop(s) available
    </p>
</div>

<!-- Gallery Grid -->
<div class="gallery-grid" id="galleryGrid">
    <?php foreach ($display_backdrops as $index => $backdrop): ?>
    <div class="gallery-item backdrop-item <?php echo (isset($_SESSION['selected_backdrop']) && $_SESSION['selected_backdrop'] === $backdrop['path']) ? 'selected' : ''; ?>" 
         data-path="<?php echo htmlspecialchars($backdrop['path'], ENT_QUOTES); ?>"
         data-title="<?php echo strtolower($backdrop['filename']); ?>" 
         data-folder="<?php echo strtolower($backdrop['folder']); ?>">
        
        <?php if (isset($_SESSION['selected_backdrop']) && $_SESSION['selected_backdrop'] === $backdrop['path']): ?>
        <span class="selected-badge">
            <i class="bx bx-check"></i> Selected
        </span>
        <?php endif; ?>
        
        <div class="image-container">
            <img src="static/images/<?php echo $backdrop['path']; ?>" 
                 alt="<?php echo $backdrop['filename']; ?>"
                 loading="lazy"
                 onerror="this.src='https://via.placeholder.com/220x180?text=No+Image'">
        </div>
        
        <div class="item-info">
            <div class="item-title" title="<?php echo $backdrop['filename']; ?>">
                <?php echo $backdrop['filename']; ?>
            </div>
            <div class="item-folder">
                <i class="bx bx-folder"></i>
                <?php echo $selected_type_info['name']; ?>
            </div>
            <div class="item-actions">
                <button type="button" class="btn btn-icon btn-sm btn-label-primary" 
                        onclick="viewImage('<?php echo addslashes($backdrop['path']); ?>', '<?php echo addslashes($backdrop['filename']); ?>')" 
                        title="View Full Size">
                    <i class="bx bx-expand"></i>
                </button>
                <button type="button" class="btn btn-sm btn-primary select-btn"
                        style="flex: 1;"
                        data-path="<?php echo htmlspecialchars($backdrop['path'], ENT_QUOTES); ?>"
                        data-filename="<?php echo htmlspecialchars($backdrop['filename'], ENT_QUOTES); ?>"
                        data-type="<?php echo htmlspecialchars($selected_type_info['name'], ENT_QUOTES); ?>"
                        onclick="openSelectModal(this)">
                    <i class="bx bx-check me-1"></i> Select
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Select / Save Backdrop Modal -->
<div class="modal fade" id="selectBackdropModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px;">
            <div class="modal-header" style="border-color: var(--border-color);">
                <h5 class="modal-title" id="selectModalTitle">
                    <i class="bx bx-image me-2" style="color: var(--bs-primary);"></i>Save Selected Backdrop
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <img id="selectModalImage" src="" alt="" class="select-modal-img">
                <div class="mt-3">
                    <div class="fw-bold" id="selectModalName" style="color: var(--heading-color); font-size: 1.05rem;"></div>
                    <div class="text-muted" style="font-size: 0.85rem;">
                        <i class="bx bx-folder me-1"></i><span id="selectModalType"></span>
                    </div>
                </div>

                <div id="selectModalMessage" class="mt-3" style="display:none;"></div>
            </div>

            <div class="modal-footer" style="border-color: var(--border-color);" id="selectModalFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="selectCancelBtn">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveBackdropBtn" onclick="saveBackdrop()">
                    <i class="bx bx-save me-1"></i>Save Backdrop
                </button>
                <a href="<?php echo $proceed_url; ?>" class="btn btn-success" id="modalProceedBtn" style="display:none;">
                    <i class="bx bx-right-arrow-alt me-1"></i>Proceed to Booking
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Lightbox Modal -->
<div class="modal fade" id="lightboxModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content" style="background: transparent; border: none;">
            <div class="modal-body p-0 text-center">
                <button type="button" class="btn-close btn-close-white position-absolute" 
                        style="top: -40px; right: 0;" data-bs-dismiss="modal"></button>
                <img id="lightboxImage" src="" alt="" style="max-width: 100%; max-height: 85vh; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.5);">
                <p id="lightboxTitle" class="text-white mt-3 mb-0" style="font-size: 1.1rem;"></p>
            </div>
        </div>
    </div>
</div>

<script>
let pendingPath = '';
let pendingFilename = '';
let pendingTypeName = '';

// View image in lightbox
function viewImage(path, title) {
    const modal = new bootstrap.Modal(document.getElementById('lightboxModal'));
    document.getElementById('lightboxImage').src = 'static/images/' + path;
    document.getElementById('lightboxTitle').textContent = title;
    modal.show();
}

// Filter backdrops by search
function filterBackdrops() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const items = document.querySelectorAll('.backdrop-item');

    items.forEach(item => {
        const title = item.getAttribute('data-title');
        const folder = item.getAttribute('data-folder');
        item.style.display = (title.includes(searchTerm) || folder.includes(searchTerm)) ? '' : 'none';
    });
}

// Open the "Save Selected Backdrop" modal (does NOT redirect)
function openSelectModal(btn) {
    pendingPath     = btn.getAttribute('data-path');
    pendingFilename = btn.getAttribute('data-filename');
    pendingTypeName = btn.getAttribute('data-type');

    document.getElementById('selectModalImage').src = 'static/images/' + pendingPath;
    document.getElementById('selectModalName').textContent = pendingFilename;
    document.getElementById('selectModalType').textContent = pendingTypeName;
    document.getElementById('selectModalTitle').innerHTML =
        '<i class="bx bx-image me-2" style="color: var(--bs-primary);"></i>Save Selected Backdrop';

    const msg = document.getElementById('selectModalMessage');
    msg.style.display = 'none';
    msg.innerHTML = '';

    const saveBtn = document.getElementById('saveBackdropBtn');
    saveBtn.style.display = '';
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<i class="bx bx-save me-1"></i>Save Backdrop';
    document.getElementById('modalProceedBtn').style.display = 'none';
    document.getElementById('selectCancelBtn').textContent = 'Cancel';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('selectBackdropModal')).show();
}

// Save the selected backdrop via AJAX
function saveBackdrop() {
    const saveBtn = document.getElementById('saveBackdropBtn');
    const msg = document.getElementById('selectModalMessage');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

    fetch('backdrops.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=save_backdrop&photo_path=' + encodeURIComponent(pendingPath)
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bx bx-save me-1"></i>Save Backdrop';
            msg.style.display = 'block';
            msg.innerHTML = '<div class="alert alert-danger mb-0"><i class="bx bx-error-circle me-2"></i>' +
                (data.message || 'Could not save backdrop.') + '</div>';
            return;
        }

        // Success state inside the modal
        document.getElementById('selectModalTitle').innerHTML =
            '<i class="bx bx-check-circle me-2" style="color: var(--bs-success);"></i>Backdrop Saved';
        msg.style.display = 'block';
        msg.innerHTML = '<div class="alert alert-success mb-0"><i class="bx bx-check-circle me-2"></i>' +
            'Your backdrop has been saved. You can proceed to the event booking now, or keep browsing.</div>';

        saveBtn.style.display = 'none';
        document.getElementById('modalProceedBtn').style.display = '';
        document.getElementById('selectCancelBtn').textContent = 'Stay Here';

        updateSelectedUI(data.path);
    })
    .catch(() => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bx bx-save me-1"></i>Save Backdrop';
        msg.style.display = 'block';
        msg.innerHTML = '<div class="alert alert-danger mb-0"><i class="bx bx-error-circle me-2"></i>Network error. Please try again.</div>';
    });
}

// Update badges, card highlight and the top bar without reloading
function updateSelectedUI(path) {
    document.querySelectorAll('.backdrop-item').forEach(item => {
        const badge = item.querySelector('.selected-badge');
        if (badge) badge.remove();
        item.classList.remove('selected');

        if (path && item.getAttribute('data-path') === path) {
            item.classList.add('selected');
            const span = document.createElement('span');
            span.className = 'selected-badge';
            span.innerHTML = '<i class="bx bx-check"></i> Selected';
            item.insertBefore(span, item.firstChild);
        }
    });

    const bar = document.getElementById('selectedBar');
    if (path) {
        document.getElementById('selectedBarName').textContent = path;
        bar.style.display = '';
    } else {
        bar.style.display = 'none';
    }
}

// Clear the saved selection
function clearBackdrop() {
    fetch('backdrops.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=clear_backdrop'
    })
    .then(r => r.json())
    .then(() => updateSelectedUI(''));
}
</script>

<?php require_once 'includes/footer.php'; ?>