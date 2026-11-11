<!-- ===================== SIDEBAR: NO ANIMATION + STAYS IN ===================== -->
<style>
    /* No animations anywhere on the sidebar */
    .layout-menu,
    .layout-menu *,
    .layout-page,
    .layout-overlay {
        transition: none !important;
        animation: none !important;
    }
</style>
<script>
(function () {
    var menu  = document.querySelector('.layout-menu');
    var inner = document.querySelector('.menu-inner');
    var page  = document.querySelector('.layout-page');
    if (!menu || !inner) return;

    // 1) Keep the sidebar open / closed exactly as the user left it (desktop)
    try {
        if (window.innerWidth >= 1200 && localStorage.getItem('sidebarCollapsed') === 'true') {
            menu.classList.add('collapsed');
            if (page) page.classList.add('expanded');
        }
    } catch (e) {}

    // 2) Keep the Backdrops submenu open (instantly, no slide)
    try {
        var item = document.querySelector('.menu-item.has-submenu');
        if (item && (/backdrops\.php/.test(location.pathname) || localStorage.getItem('backdropSubmenuOpen') === 'true')) {
            item.classList.add('open');
        }
    } catch (e) {}

    // 3) Keep the sidebar scroll position between pages
    var KEY = 'sidebarScrollTop';
    try {
        var saved = parseInt(sessionStorage.getItem(KEY), 10);
        if (!isNaN(saved)) inner.scrollTop = saved;
    } catch (e) {}

    function save() { try { sessionStorage.setItem(KEY, inner.scrollTop); } catch (e) {} }
    inner.addEventListener('scroll', save, { passive: true });
    inner.addEventListener('click', save, true);
    window.addEventListener('pagehide', save);

    // 4) Only if the active link is hidden, bring it into view
    var active = inner.querySelector('.submenu-link.active, .menu-link.active');
    if (active) {
        var r = active.getBoundingClientRect(), c = inner.getBoundingClientRect();
        if (r.top < c.top || r.bottom > c.bottom) {
            inner.scrollTop = active.offsetTop - inner.clientHeight / 2;
        }
    }
})();
</script>

            </div><!-- End Content Wrapper -->

            <!-- Footer -->
            <footer class="footer" style="padding: 16px 24px; border-top: 1px solid var(--border-color); margin-top: auto;">
                <div class="d-flex justify-content-center align-items-center flex-wrap" style="gap: 12px;">
                    <div style="font-size: 0.875rem; color: var(--body-color);">
                        &copy; <?php echo date('Y'); ?> <strong style="color: var(--heading-color);">Janet's Quality Catering Services</strong>. All rights reserved.
                    </div>
                </div>
            </footer>
        </div><!-- End Layout Page -->
    </div><!-- End Layout Wrapper -->

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="static/js/password-toggle.js"></script>

    <script>
        // ===================== LOGIN SUCCESS ALERT =====================
        document.addEventListener('DOMContentLoaded', function() {
            const loginSuccess = <?php echo isset($_SESSION['login_success']) && $_SESSION['login_success'] ? 'true' : 'false'; ?>;
            const loginMsg = '<?php echo isset($_SESSION["login_message"]) ? addslashes($_SESSION["login_message"]) : "You have successfully logged in!"; ?>';
            <?php unset($_SESSION['login_success'], $_SESSION['login_message']); ?>
            if (loginSuccess) {
                Swal.fire({
                    imageUrl: 'static/images/logo.png',
                    imageWidth: 90,
                    imageHeight: 90,
                    imageAlt: "Janet's Quality Catering",
                    title: 'Welcome!',
                    text: loginMsg,
                    confirmButtonColor: '#9370DB',
                    timer: 3000,
                    timerProgressBar: true
                });
            }
        });

        // ===================== NOTIFICATIONS SYSTEM =====================
        let notificationsLoaded = false;

        // Toggle Notifications Dropdown
        function toggleNotifications() {
            const dropdown = document.getElementById('notificationsDropdown');
            dropdown.classList.toggle('show');
            
            if (dropdown.classList.contains('show') && !notificationsLoaded) {
                loadNotifications();
            }
        }

        // Load Notifications via AJAX
        function loadNotifications() {
            const listContainer = document.getElementById('notificationsList');
            
            fetch('ajax/notifications.php?action=get_notifications&limit=10')
                .then(response => response.json())
                .then(data => {
                    notificationsLoaded = true;
                    
                    if (data.success && data.notifications.length > 0) {
                        let html = '';
                        data.notifications.forEach(notif => {
                            const unreadClass = notif.is_read == 0 ? 'unread' : '';
                            const iconClass = getNotificationIconClass(notif.type);
                            
                            html += `
                                <div class="notification-item ${unreadClass}" 
                                     onclick="handleNotificationClick(${notif.id}, '${escapeHtml(notif.reference_type || '')}', ${notif.reference_id || 'null'})">
                                    <div class="notification-icon ${notif.type}">
                                        <i class="${iconClass}"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">${escapeHtml(notif.title)}</div>
                                        <div class="notification-message">${escapeHtml(notif.message)}</div>
                                        <div class="notification-time">${notif.time_ago}${notif.actor_username ? ' · by ' + escapeHtml(notif.actor_username) : ''}</div>
                                    </div>
                                </div>
                            `;
                        });
                        listContainer.innerHTML = html;
                    } else {
                        listContainer.innerHTML = `
                            <div class="notifications-empty">
                                <i class="bx bx-bell-off"></i>
                                <p>No notifications yet</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading notifications:', error);
                    listContainer.innerHTML = `
                        <div class="notifications-empty">
                            <i class="bx bx-error-circle"></i>
                            <p>Failed to load notifications</p>
                        </div>
                    `;
                });
        }

        // Get notification icon class based on type
        function getNotificationIconClass(type) {
            const icons = {
                'report': 'bx bx-file',
                'feedback': 'bx bx-comment',
                'inventory': 'bx bx-box',
                'event': 'bx bx-calendar',
                'system': 'bx bx-cog'
            };
            return icons[type] || 'bx bx-bell';
        }

        // Handle notification click
        function handleNotificationClick(notificationId, referenceType, referenceId) {
            // Mark as read
            fetch('ajax/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=mark_as_read&notification_id=${notificationId}`
            })
            .then(() => {
                updateNotificationCount();
                
                // Navigate to relevant page
                if (referenceType === 'reports' && referenceId) {
                    window.location.href = `reports.php?highlight=${referenceId}`;
                } else if (referenceType === 'event_bookings' && referenceId) {
                    window.location.href = `event_bookings.php?view=wizard&edit_id=${referenceId}`;
                } else {
                    window.location.href = 'notifications.php';
                }
            });
        }

        // Mark all notifications as read
        function markAllNotificationsRead() {
            fetch('ajax/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove unread styling
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                    // Update badge
                    updateNotificationBadge(0);
                    
                    // Show success toast
                    showToast('All notifications marked as read', 'success');
                }
            });
        }

        // Update notification count
        function updateNotificationCount() {
            if (!document.getElementById('notificationBadge')) return;
            fetch('ajax/notifications.php?action=get_unread_count')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateNotificationBadge(data.count);
                    }
                });
        }

        // Update the notification badge
        function updateNotificationBadge(count) {
            const badge = document.getElementById('notificationBadge');
            if (badge) {
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            }
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ===================== SWEETALERT2 HELPER FUNCTIONS =====================
        
        // Success Alert
        function showSuccess(title, message, callback) {
            Swal.fire({
                icon: 'success',
                title: title,
                text: message,
                confirmButtonColor: '#9370DB',
                timer: 3000,
                timerProgressBar: true
            }).then(() => {
                if (typeof callback === 'function') callback();
            });
        }

        // Error Alert
        function showError(title, message) {
            Swal.fire({
                icon: 'error',
                title: title,
                text: message,
                confirmButtonColor: '#9370DB'
            });
        }

        // Warning Alert
        function showWarning(title, message) {
            Swal.fire({
                icon: 'warning',
                title: title,
                text: message,
                confirmButtonColor: '#9370DB'
            });
        }

        // Info Alert
        function showInfo(title, message) {
            Swal.fire({
                icon: 'info',
                title: title,
                text: message,
                confirmButtonColor: '#9370DB'
            });
        }

        // Confirmation Dialog
        function showConfirm(title, message, confirmText, cancelText) {
            return Swal.fire({
                icon: 'question',
                title: title,
                text: message,
                showCancelButton: true,
                confirmButtonColor: '#9370DB',
                cancelButtonColor: '#8592a3',
                confirmButtonText: confirmText || 'Yes, confirm',
                cancelButtonText: cancelText || 'Cancel'
            });
        }

        // Delete Confirmation
        function showDeleteConfirm(itemName) {
            return Swal.fire({
                icon: 'warning',
                title: 'Delete ' + itemName + '?',
                text: 'This action cannot be undone!',
                showCancelButton: true,
                confirmButtonColor: '#ff3e1d',
                cancelButtonColor: '#8592a3',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            });
        }

        // Toast Notification
        function showToast(message, type = 'success') {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer);
                    toast.addEventListener('mouseleave', Swal.resumeTimer);
                }
            });

            Toast.fire({
                icon: type,
                title: message
            });
        }

        // Loading Overlay (defined globally in header.php)
        // showLoading(msg) and hideLoading() are available globally

        // ===================== TOGGLE FUNCTIONS =====================

        // Toggle Sidebar Menu (works for all roles)
        // Desktop: collapse / expand and REMEMBER the choice.
        // Mobile : slide-in drawer with overlay.
        function toggleMenu() {
            const menu = document.querySelector('.layout-menu');
            const overlay = document.querySelector('.layout-overlay');
            const page = document.querySelector('.layout-page');

            if (window.innerWidth < 1200) {
                menu.classList.remove('collapsed');
                const open = menu.classList.toggle('show');
                overlay.classList.toggle('show', open);
                return;
            }

            const collapse = !menu.classList.contains('collapsed');
            menu.classList.toggle('collapsed', collapse);
            page.classList.toggle('expanded', collapse);
            overlay.classList.remove('show');
            try { localStorage.setItem('sidebarCollapsed', collapse ? 'true' : 'false'); } catch (e) {}
        }

        // Clicking a module does NOT close the sidebar on desktop (it stays in).
        // Only the mobile drawer closes after navigating.
        function setupAutoHideSidebar() {
            document.querySelectorAll('.menu-link, .submenu-link').forEach(link => {
                link.addEventListener('click', function () {
                    if (window.innerWidth >= 1200) return;               // desktop: keep sidebar as is
                    if (this.classList.contains('text-danger')) return;  // logout
                    if (this.classList.contains('menu-link') && this.closest('.has-submenu')) return; // submenu toggle
                    const menu = document.querySelector('.layout-menu');
                    const overlay = document.querySelector('.layout-overlay');
                    if (menu) menu.classList.remove('show');
                    if (overlay) overlay.classList.remove('show');
                });
            });
        }

        // Toggle Backdrop Submenu in Sidebar
        function toggleBackdropSubmenu(event) {
            event.preventDefault();
            event.stopPropagation();
            
            const menuItem = event.currentTarget.closest('.menu-item.has-submenu');
            if (menuItem) {
                menuItem.classList.toggle('open');
                
                // Save state to localStorage
                const isOpen = menuItem.classList.contains('open');
                localStorage.setItem('backdropSubmenuOpen', isOpen ? 'true' : 'false');
            }
        }
        
        // Initialize backdrop submenu state
        function initBackdropSubmenu() {
            const savedState = localStorage.getItem('backdropSubmenuOpen');
            const currentPage = window.location.pathname;
            const urlParams = new URLSearchParams(window.location.search);
            const hasTypeParam = urlParams.has('type');
            
            // Auto-open if on backdrops page or if was previously open
            if (currentPage.includes('backdrops.php') || savedState === 'true') {
                const menuItem = document.querySelector('.menu-item.has-submenu');
                if (menuItem) {
                    menuItem.classList.add('open');
                }
            }
        }

                // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            // Close notifications dropdown
            const notificationsDropdown = document.getElementById('notificationsDropdown');
            if (notificationsDropdown && !notificationsDropdown.contains(e.target)) {
                notificationsDropdown.classList.remove('show');
            }
        });

        // ===================== THEME MANAGEMENT =====================

        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            setTheme(newTheme);
        }

        function setTheme(theme) {
            theme = theme === 'dark' ? 'dark' : 'light';
            const html = document.documentElement;
            const changed = html.getAttribute('data-theme') !== theme;
            html.setAttribute('data-theme', theme);
            html.classList.toggle('dark-style', theme === 'dark');
            html.classList.toggle('light-style', theme !== 'dark');
            try { localStorage.setItem('theme', theme); } catch (e) {}
            updateThemeIcon(theme);
            updateSwalTheme(theme);
            // Lets pages (e.g. dashboard charts) re-colour themselves without a reload
            if (changed) {
                document.dispatchEvent(new CustomEvent('themechange', { detail: { theme: theme } }));
            }
        }

        function updateSwalTheme(theme) {
            // Dynamically update Swal defaults to match current theme
            if (typeof Swal !== 'undefined') {
                const isDark = theme === 'dark';
                Swal.mixin({
                    background: isDark ? '#1e1b2e' : '#ffffff',
                    color: isDark ? '#e0d9f7' : '#3d3558',
                });
            }
        }

        function updateThemeIcon(theme) {
            const icon = document.getElementById('themeIcon');
            if (icon) {
                icon.className = theme === 'dark' ? 'bx bx-sun' : 'bx bx-moon';
                const btn = icon.closest('button');
                if (btn) {
                    const label = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
                    btn.title = label;
                    btn.setAttribute('aria-label', label);
                    btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
                }
            }
        }

        // ===================== INITIALIZATION =====================

        document.addEventListener('DOMContentLoaded', function() {
            // Load saved theme (already applied before first paint in header.php; this syncs icon + Swal)
            let savedTheme = 'light';
            try { savedTheme = localStorage.getItem('theme') || document.documentElement.getAttribute('data-theme') || 'light'; } catch (e) {}
            setTheme(savedTheme);

            // Load notification count
            updateNotificationCount();
            
            // Refresh notification count every 30 seconds
            setInterval(updateNotificationCount, 30000);
            
            // Setup auto-hide sidebar functionality
            setupAutoHideSidebar();
            
            // Saved sidebar state (desktop only) - already applied instantly above
            const sidebarCollapsed = window.innerWidth >= 1200 && localStorage.getItem('sidebarCollapsed') === 'true';
            if (sidebarCollapsed) {
                const menu = document.querySelector('.layout-menu');
                const page = document.querySelector('.layout-page');
                if (menu) menu.classList.add('collapsed');
                if (page) page.classList.add('expanded');
            }
            
            // Initialize backdrop submenu
            initBackdropSubmenu();

            // Auto-hide flash messages after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert:not(.swal2-popup .alert)');
                alerts.forEach(function(alert) {
                    if (alert.classList.contains('alert-dismissible')) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                });
            }, 5000);
        });

        // ===================== LOGOUT CONFIRMATION =====================
        // confirmLogout is defined globally in header.php (includes showLoading)

    </script>
</body>
</html>