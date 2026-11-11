<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed layout-navbar-fixed" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <!-- Apply the saved theme BEFORE first paint (no light-mode flash when dark mode is selected) -->
    <script>
    (function () {
        var t = 'light';
        try { t = localStorage.getItem('theme') === 'dark' ? 'dark' : 'light'; } catch (e) {}
        var h = document.documentElement;
        h.setAttribute('data-theme', t);
        h.classList.toggle('dark-style', t === 'dark');
        h.classList.toggle('light-style', t !== 'dark');
    })();
    </script>
    <title><?php echo $page_title ?? "Janet's Quality Catering"; ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="static/favicon.ico">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css">
    
    <!-- Core CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root {
            /* Janet's Catering Lavender Theme */
            --bs-primary: #9370DB;
            --bs-primary-dark: #7B5FC7;
            --bs-secondary: #8592a3;
            --bs-success: #71dd37;
            --bs-info: #03c3ec;
            --bs-warning: #ffab00;
            --bs-danger: #ff3e1d;
            --bs-light: #fcfdfd;
            --bs-dark: #233446;
            
            /* Layout */
            --layout-menu-width: 260px;
            --layout-navbar-height: 64px;
            
            /* Light Theme - Lavender */
            --body-bg: #F0E6FA;
            --card-bg: #fff;
            --card-border: #D8C8E8;
            --heading-color: #1A1A2E;
            --body-color: #1A1A2E;
            --menu-bg: #fff;
            --menu-item-color: #1A1A2E;
            --menu-item-hover-bg: rgba(147, 112, 219, 0.1);
            --menu-item-active-color: #9370DB;
            --border-color: #D8C8E8;
            --table-text-color: #1A1A2E;
        }

        /* Override Bootstrap secondary color for light mode */
        [data-theme="light"] {
            --bs-secondary: #1A1A2E;
            --bs-secondary-rgb: 26, 26, 46;
            --heading-color: #1A1A2E;
            --body-color: #1A1A2E;
        }

        /* CRITICAL: Force ALL text dark on light mode white backgrounds */
        [data-theme="light"] .card,
        [data-theme="light"] .table-responsive,
        [data-theme="light"] .table-wrapper,
        [data-theme="light"] table {
            color: #1A1A2E !important;
        }

        [data-theme="light"] .card td,
        [data-theme="light"] .card td *,
        [data-theme="light"] .card th,
        [data-theme="light"] .card th *,
        [data-theme="light"] .card-header,
        [data-theme="light"] .card-header *,
        [data-theme="light"] .card-title {
            color: #1A1A2E !important;
        }

        [data-theme="dark"] {
            /* Dark Theme */
            --body-bg: #1A1A2E;
            --card-bg: #252540;
            --card-border: #3D3D5C;
            --heading-color: #E8E6F0;
            --body-color: #B8B8D0;
            --menu-bg: #252540;
            --menu-item-color: #B8B8D0;
            --menu-item-hover-bg: rgba(147, 112, 219, 0.2);
            --menu-item-active-color: #B896FF;
            --border-color: #3D3D5C;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body { 
            font-family: 'Public Sans', sans-serif;
            background-color: var(--body-bg);
            color: var(--body-color);
            min-height: 100vh;
            transition: background-color 0.3s, color 0.3s;
        }

        /* Dark mode text colors - Enhanced Contrast */
        [data-theme="dark"] .text-dark,
        [data-theme="dark"] h1,
        [data-theme="dark"] h2,
        [data-theme="dark"] h3,
        [data-theme="dark"] h4,
        [data-theme="dark"] h5,
        [data-theme="dark"] h6,
        [data-theme="dark"] .card-title,
        [data-theme="dark"] .page-title,
        [data-theme="dark"] strong,
        [data-theme="dark"] label,
        [data-theme="dark"] .form-label,
        [data-theme="dark"] th,
        [data-theme="dark"] .modal-title,
        [data-theme="dark"] .report-title,
        [data-theme="dark"] .analytics-title,
        [data-theme="dark"] .fw-semibold,
        [data-theme="dark"] .fw-bold,
        [data-theme="dark"] .dropdown-item,
        [data-theme="dark"] .nav-user-name,
        [data-theme="dark"] .sidebar-user-name,
        [data-theme="dark"] .app-brand-text {
            color: #FFFFFF !important;
        }

        [data-theme="dark"] p,
        [data-theme="dark"] span:not(.badge):not(.status-pill):not(.nav-badge),
        [data-theme="dark"] td,
        [data-theme="dark"] .text-muted,
        [data-theme="dark"] .report-meta,
        [data-theme="dark"] .report-content,
        [data-theme="dark"] small,
        [data-theme="dark"] .breadcrumb-item,
        [data-theme="dark"] .analytics-stat span,
        [data-theme="dark"] .menu-link,
        [data-theme="dark"] .card-body,
        [data-theme="dark"] .footer,
        [data-theme="dark"] .footer * {
            color: #D0D0E8 !important;
        }

        /* Dark mode footer specific */
        [data-theme="dark"] .footer strong {
            color: #FFFFFF !important;
        }

        /* Dark mode - active menu link should be white */
        [data-theme="dark"] .menu-link.active,
        [data-theme="dark"] .menu-link.active span {
            color: #FFFFFF !important;
        }

        /* Dark mode - ensure dropdown items are visible */
        [data-theme="dark"] .dropdown-menu .dropdown-item {
            color: #D0D0E8 !important;
        }
        [data-theme="dark"] .dropdown-menu .dropdown-item:hover {
            color: #FFFFFF !important;
            background: var(--menu-item-hover-bg) !important;
        }

        [data-theme="dark"] .form-control,
        [data-theme="dark"] .form-select,
        [data-theme="dark"] textarea {
            background: #2A2A45 !important;
            border-color: var(--border-color) !important;
            color: #FFFFFF !important;
        }

        [data-theme="dark"] .form-control::placeholder,
        [data-theme="dark"] .form-select::placeholder {
            color: #9090B0 !important;
        }

        [data-theme="dark"] .table {
            color: #D0D0E8;
        }

        [data-theme="dark"] .table thead th {
            background: #1F1F35 !important;
            color: #FFFFFF !important;
        }

        [data-theme="dark"] .table tbody td {
            color: #D0D0E8 !important;
            border-color: var(--border-color) !important;
        }

        [data-theme="dark"] .dropdown-menu {
            background: var(--card-bg);
            border-color: var(--border-color);
        }

        [data-theme="dark"] .dropdown-item {
            color: #D0D0E8;
        }

        [data-theme="dark"] .dropdown-item:hover {
            background: var(--menu-item-hover-bg);
            color: #FFFFFF;
        }

        /* Light mode - ensure dark text */
        [data-theme="light"] h1,
        [data-theme="light"] h2,
        [data-theme="light"] h3,
        [data-theme="light"] h4,
        [data-theme="light"] h5,
        [data-theme="light"] h6,
        [data-theme="light"] .card-title,
        [data-theme="light"] .page-title,
        [data-theme="light"] strong,
        [data-theme="light"] label,
        [data-theme="light"] .form-label,
        [data-theme="light"] th,
        [data-theme="light"] .modal-title,
        [data-theme="light"] .fw-semibold,
        [data-theme="light"] .fw-bold,
        [data-theme="light"] .dropdown-item,
        [data-theme="light"] .nav-user-name,
        [data-theme="light"] .sidebar-user-name,
        [data-theme="light"] .app-brand-text {
            color: #2D2D4A !important;
        }

        [data-theme="light"] p,
        [data-theme="light"] .text-muted,
        [data-theme="light"] small,
        [data-theme="light"] .menu-link,
        [data-theme="light"] .footer,
        [data-theme="light"] .footer * {
            color: #5A5A7A !important;
        }

        /* Light mode - TABLE TEXT must be BLACK/DARK on white backgrounds */
        [data-theme="light"] .table,
        [data-theme="light"] .table td,
        [data-theme="light"] .table td *,
        [data-theme="light"] .table tbody td,
        [data-theme="light"] .card .table td,
        [data-theme="light"] .card-body td,
        [data-theme="light"] td span:not(.badge):not(.status-pill),
        [data-theme="light"] td small,
        [data-theme="light"] td p,
        [data-theme="light"] td a,
        [data-theme="light"] .table-responsive td,
        [data-theme="light"] .table-wrapper td {
            color: #1A1A2E !important;
        }

        /* Light mode - Card body text should be dark */
        [data-theme="light"] .card-body,
        [data-theme="light"] .card-body p,
        [data-theme="light"] .card-body span:not(.badge):not(.status-pill):not(.nav-badge),
        [data-theme="light"] .card-body small,
        [data-theme="light"] .card-body div {
            color: #2D2D4A !important;
        }

        /* Light mode footer specific */
        [data-theme="light"] .footer strong {
            color: #2D2D4A !important;
        }

        /* Light mode - active menu link should be white */
        [data-theme="light"] .menu-link.active,
        [data-theme="light"] .menu-link.active span {
            color: #FFFFFF !important;
        }

        [data-theme="light"] .form-control,
        [data-theme="light"] .form-select {
            background: #FFFFFF !important;
            color: #2D2D4A !important;
        }

        /* Light mode - Ensure all table rows have dark text */
        [data-theme="light"] table tbody tr td,
        [data-theme="light"] table tbody tr td * {
            color: #1A1A2E !important;
        }

        /* Light mode - Keep badges colored properly */
        [data-theme="light"] .badge,
        [data-theme="light"] .status-pill {
            color: inherit !important;
        }

        /* ===== CRITICAL: Light mode WHITE background = BLACK text ===== */
        [data-theme="light"] .card,
        [data-theme="light"] .card-body {
            background: #FFFFFF !important;
        }

        /* All text inside cards with white background must be dark/black */
        [data-theme="light"] .card td,
        [data-theme="light"] .card th,
        [data-theme="light"] .card p,
        [data-theme="light"] .card span:not(.badge):not(.status-pill):not(.btn),
        [data-theme="light"] .card small,
        [data-theme="light"] .card a:not(.btn),
        [data-theme="light"] .card div:not(.badge):not(.btn),
        [data-theme="light"] .card label,
        [data-theme="light"] .card strong,
        [data-theme="light"] .card h1,
        [data-theme="light"] .card h2,
        [data-theme="light"] .card h3,
        [data-theme="light"] .card h4,
        [data-theme="light"] .card h5,
        [data-theme="light"] .card h6 {
            color: #1A1A2E !important;
        }

        /* Specifically target table cells in light mode */
        [data-theme="light"] tbody td,
        [data-theme="light"] tbody td *,
        [data-theme="light"] .table-striped tbody tr td,
        [data-theme="light"] .table-hover tbody tr td {
            color: #1A1A2E !important;
        }

        /* User info text in tables */
        [data-theme="light"] .user-info,
        [data-theme="light"] .user-info *,
        [data-theme="light"] .fw-semibold,
        [data-theme="light"] .text-muted {
            color: #2D2D4A !important;
        }

        /* Ensure white buttons keep white text */
        [data-theme="light"] .btn-primary,
        [data-theme="light"] .btn-success,
        [data-theme="light"] .btn-danger,
        [data-theme="light"] .btn-warning,
        [data-theme="light"] .btn-info {
            color: #FFFFFF !important;
        }

        /* ===== OVERRIDE CSS VARIABLES FOR LIGHT MODE TABLE TEXT ===== */
        [data-theme="light"] td [style*="color: var(--bs-secondary)"],
        [data-theme="light"] td [style*="color: var(--heading-color)"],
        [data-theme="light"] td span[style*="var(--bs-secondary)"],
        [data-theme="light"] td small[style*="var(--bs-secondary)"],
        [data-theme="light"] td strong[style*="var(--heading-color)"],
        [data-theme="light"] td div[style*="var(--bs-secondary)"] {
            color: #1A1A2E !important;
        }

        /* Force dark text on ALL table cells in light mode */
        [data-theme="light"] table tr td,
        [data-theme="light"] table tr td span,
        [data-theme="light"] table tr td small,
        [data-theme="light"] table tr td strong,
        [data-theme="light"] table tr td div,
        [data-theme="light"] table tr td a:not(.btn),
        [data-theme="light"] table tr td p {
            color: #1A1A2E !important;
        }

        /* Light mode - table header text must be dark */
        [data-theme="light"] table thead th,
        [data-theme="light"] table thead th * {
            color: #1A1A2E !important;
        }

        /* Light mode - Ensure ALL text inside white cards is dark */
        [data-theme="light"] .card *:not(.badge):not(.btn):not(.status-pill):not(.bx):not(.fa):not(.fas):not(.far):not(.fab):not(i) {
            color: #1A1A2E !important;
        }

        /* Preserve icon colors */
        [data-theme="light"] .bx,
        [data-theme="light"] .fa,
        [data-theme="light"] .fas,
        [data-theme="light"] .far,
        [data-theme="light"] .fab,
        [data-theme="light"] i[class*="bx-"],
        [data-theme="light"] i[class*="fa-"] {
            color: inherit !important;
        }

        /* Preserve badge colors */
        [data-theme="light"] .badge,
        [data-theme="light"] .status-pill,
        [data-theme="light"] .badge * {
            color: inherit !important;
        }

        /* Preserve button text colors */
        [data-theme="light"] .btn,
        [data-theme="light"] .btn * {
            color: inherit !important;
        }

        [data-theme="light"] .btn-primary,
        [data-theme="light"] .btn-primary *,
        [data-theme="light"] .btn-success,
        [data-theme="light"] .btn-success *,
        [data-theme="light"] .btn-danger,
        [data-theme="light"] .btn-danger * {
            color: #FFFFFF !important;
        }

        /* Dark mode cards and containers */
        [data-theme="dark"] .card {
            background: var(--card-bg) !important;
            border-color: var(--border-color) !important;
        }

        /* Ensure all text is visible in dark mode */
        [data-theme="dark"] * {
            transition: color 0.3s, background-color 0.3s;
        }

        [data-theme="dark"] button:not(.btn-primary):not(.btn-secondary):not(.btn-danger):not(.btn-success) {
            color: #FFFFFF !important;
        }

        [data-theme="dark"] .btn-secondary {
            border-color: #9370DB;
            color: #FFFFFF !important;
        }

        [data-theme="dark"] .btn-secondary:hover {
            background-color: #9370DB;
        }

        /* Light mode ensures all text is dark */
        [data-theme="light"] * {
            transition: color 0.3s, background-color 0.3s;
        }

        [data-theme="light"] button:not(.btn-primary):not(.btn-secondary):not(.btn-danger):not(.btn-success) {
            color: #2D2D4A !important;
        }

        [data-theme="dark"] .modal-content {
            background: #252540 !important;
        }

        [data-theme="dark"] .modal-header,
        [data-theme="dark"] .modal-footer {
            border-color: var(--border-color) !important;
        }

        [data-theme="dark"] .alert {
            color: inherit !important;
        }

        [data-theme="dark"] .input-group-text {
            background: #2A2A45 !important;
            border-color: var(--border-color) !important;
            color: #D0D0E8 !important;
        }

        [data-theme="dark"] .nav-tabs .nav-link {
            color: #D0D0E8 !important;
        }

        [data-theme="dark"] .nav-tabs .nav-link.active {
            color: var(--bs-primary) !important;
        }

        /* Analytics and reports dark mode */
        [data-theme="dark"] .analytics-section,
        [data-theme="dark"] .analytics-card,
        [data-theme="dark"] .report-form,
        [data-theme="dark"] .report-card {
            background: var(--card-bg) !important;
            border-color: var(--border-color) !important;
        }

        [data-theme="dark"] .analytics-stat div[style*="font-size: 1.8rem"],
        [data-theme="dark"] .analytics-stat div[style*="font-size: 2rem"] {
            color: inherit !important;
        }

        [data-theme="dark"] .summary-stat {
            color: inherit !important;
        }

        /* ===================== Layout Wrapper ===================== */
        .layout-wrapper {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* ===================== Sidebar Menu ===================== */
        .layout-menu {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--layout-menu-width);
            height: 100%;
            background: var(--menu-bg);
            border-right: 1px solid var(--border-color);
            z-index: 1040;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        
        /* Sidebar collapsed state */
        .layout-menu.collapsed {
            transform: translateX(-100%);
        }
        
        .layout-page.expanded {
            margin-left: 0 !important;
        }
        
        /* Overlay when sidebar is open */
        .layout-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1035;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }
        
        .layout-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .app-brand {
            display: flex;
            align-items: center;
            padding: 16px 20px;
            height: auto;
            border-bottom: 1px solid var(--border-color);
        }

        .app-brand-logo {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            overflow: hidden;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .app-brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .app-brand-text {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--heading-color);
            text-decoration: none;
            line-height: 1.3;
        }

        .app-brand-text span {
            display: block;
            font-size: 0.65rem;
            font-weight: 600;
            color: var(--bs-primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .menu-inner {
            flex: 1;
            overflow-y: auto;
            padding: 0 0 20px;
        }

        .menu-inner::-webkit-scrollbar {
            width: 5px;
        }

        .menu-inner::-webkit-scrollbar-thumb {
            background: var(--bs-secondary);
            border-radius: 10px;
        }

        .menu-header {
            padding: 24px 24px 8px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--bs-secondary);
        }

        .menu-item {
            padding: 0 16px;
            margin-bottom: 2px;
        }

        .menu-link {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            border-radius: 8px;
            color: var(--menu-item-color);
            text-decoration: none;
            font-size: 0.9375rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .menu-link:hover {
            background: var(--menu-item-hover-bg);
            color: var(--menu-item-active-color);
        }

        .menu-link.active {
            background: linear-gradient(135deg, var(--bs-primary) 0%, #8B5CF6 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(147, 112, 219, 0.4);
        }

        .menu-link i {
            font-size: 1.25rem;
            margin-right: 12px;
            width: 22px;
            text-align: center;
        }

        .menu-divider {
            height: 1px;
            background: var(--border-color);
            margin: 16px 24px;
        }

        .menu-badge {
            font-size: 0.6875rem;
            padding: 0.25em 0.5em;
            background: var(--bs-danger);
            color: #fff;
            border-radius: 4px;
            margin-left: auto;
            font-weight: 600;
        }
        
        /* Submenu Styles for Backdrops */
        .menu-item.has-submenu .menu-link {
            position: relative;
        }
        
        .menu-item.has-submenu .submenu-arrow {
            margin-left: auto;
            transition: transform 0.3s;
            font-size: 1rem;
        }
        
        .menu-item.has-submenu.open .submenu-arrow {
            transform: rotate(180deg);
        }
        
        .submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
            background: rgba(0, 0, 0, 0.03);
        }
        
        [data-theme="dark"] .submenu {
            background: rgba(255, 255, 255, 0.03);
        }
        
        .menu-item.has-submenu.open .submenu {
            max-height: 500px;
        }
        
        .submenu-link {
            display: flex;
            align-items: center;
            padding: 10px 20px 10px 45px;
            color: var(--body-color);
            text-decoration: none;
            font-size: 0.8rem;
            transition: all 0.2s;
            border-left: 2px solid transparent;
        }
        
        .submenu-link:hover {
            background: var(--menu-item-hover-bg);
            color: var(--bs-primary);
            border-left-color: var(--bs-primary);
        }
        
        .submenu-link.active {
            background: rgba(147, 112, 219, 0.1);
            color: var(--bs-primary);
            border-left-color: var(--bs-primary);
            font-weight: 600;
        }
        
        .submenu-link i {
            font-size: 1rem;
            margin-right: 10px;
            width: 20px;
            text-align: center;
            color: var(--bs-primary);
        }

        /* User Info in Sidebar */
        .sidebar-user {
            padding: 16px 20px;
            border-top: 1px solid var(--border-color);
            margin-top: auto;
        }

        .sidebar-user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--bs-primary) 0%, #8B5CF6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 600;
            overflow: hidden;
            flex-shrink: 0;
        }

        .sidebar-user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .sidebar-user-details {
            flex: 1;
            overflow: hidden;
        }

        .sidebar-user-name {
            font-weight: 600;
            color: var(--heading-color);
            font-size: 0.875rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-user-role {
            font-size: 0.75rem;
            color: var(--bs-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ===================== Content Wrapper ===================== */
        .layout-page {
            flex: 1;
            margin-left: var(--layout-menu-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-left 0.3s ease;
        }

        /* ===================== Navbar ===================== */
        .layout-navbar {
            position: sticky;
            top: 0;
            left: 0;
            right: 0;
            height: var(--layout-navbar-height);
            background: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
            z-index: 1030;
            display: flex;
            align-items: center;
            padding: 0 24px;
        }

        .navbar-nav-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .menu-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border: none;
            background: var(--menu-item-hover-bg);
            border-radius: 10px;
            cursor: pointer;
            color: var(--heading-color);
            font-size: 1.5rem;
            transition: all 0.3s;
        }
        
        .menu-toggle:hover {
            background: var(--bs-primary);
            color: white;
        }

        .search-container {
            position: relative;
        }

        .search-input {
            width: 200px;
            padding: 8px 16px 8px 40px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--body-bg);
            color: var(--body-color);
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--bs-primary);
            width: 280px;
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--bs-secondary);
        }

        .navbar-nav-right {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }

        .nav-item-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: none;
            background: transparent;
            color: var(--body-color);
            font-size: 1.25rem;
            cursor: pointer;
            position: relative;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .nav-item-icon:hover {
            background: var(--menu-item-hover-bg);
            color: var(--bs-primary);
        }

        .nav-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 18px;
            height: 18px;
            background: var(--bs-danger);
            color: #fff;
            border-radius: 50%;
            font-size: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        /* User Dropdown */
        .nav-user {
            position: relative;
        }

        .nav-user-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 12px 6px 6px;
            border: none;
            background: transparent;
            cursor: pointer;
            border-radius: 50px;
            transition: all 0.2s;
        }

        .nav-user-toggle:hover {
            background: var(--menu-item-hover-bg);
        }

        .nav-user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--bs-primary) 0%, #8B5CF6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 600;
            font-size: 0.875rem;
            overflow: hidden;
        }

        .nav-user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .nav-user-info {
            text-align: left;
            display: none;
        }

        @media (min-width: 992px) {
            .nav-user-info {
                display: block;
            }
        }

        .nav-user-name {
            font-weight: 600;
            color: var(--heading-color);
            font-size: 0.875rem;
        }

        .nav-user-role {
            font-size: 0.75rem;
            color: var(--bs-secondary);
        }

        .user-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            min-width: 200px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s;
            z-index: 1050;
        }

        .nav-user.show .user-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-header {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            color: var(--body-color);
            text-decoration: none;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .dropdown-item:hover {
            background: var(--menu-item-hover-bg);
            color: var(--bs-primary);
        }

        .dropdown-item i {
            font-size: 1.125rem;
            width: 20px;
        }

        .dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 8px 0;
        }

        .dropdown-item.text-danger {
            color: var(--bs-danger);
        }

        .dropdown-item.text-danger:hover {
            background: rgba(255, 62, 29, 0.08);
        }

        /* ===================== Content ===================== */
        .content-wrapper {
            flex: 1;
            padding: 24px;
        }

        /* ===================== Cards ===================== */
        .card {
            background: var(--card-bg);
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(147, 112, 219, 0.08);
            margin-bottom: 24px;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--heading-color);
            margin: 0;
        }

        .card-body {
            padding: 24px;
        }

        /* ===================== Tables ===================== */
        .table {
            color: var(--body-color);
            margin-bottom: 0;
        }

        .table thead th {
            background: var(--body-bg);
            border-bottom: 1px solid var(--border-color);
            color: var(--heading-color);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 20px;
            font-weight: 600;
        }

        .table tbody td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        .table-hover tbody tr:hover {
            background: var(--menu-item-hover-bg);
        }

        /* ===================== TABLE TEXT COLOR FIX - Light Mode ===================== */
        /* Force BLACK text on WHITE/LIGHT table row backgrounds in LIGHT MODE */
        [data-theme="light"] .table tbody tr,
        [data-theme="light"] .table tbody tr td,
        [data-theme="light"] .table tbody tr td strong,
        [data-theme="light"] .table tbody tr td span:not(.badge):not(.status-pill),
        [data-theme="light"] .table tbody tr td small,
        [data-theme="light"] .table tbody tr td p,
        [data-theme="light"] .table tbody tr td div:not(.badge),
        [data-theme="light"] .table tbody tr td a:not(.btn),
        [data-theme="light"] .table-sm tbody tr td,
        [data-theme="light"] .table-sm tbody tr td * {
            color: #1A1A2E !important;
        }

        /* Light mode - table header must be dark text */
        [data-theme="light"] .table thead tr th,
        [data-theme="light"] .table thead tr th * {
            color: #1A1A2E !important;
        }

        /* Light mode - Ensure inline styles are overridden */
        [data-theme="light"] .table tbody td [style*="color"],
        [data-theme="light"] .table tbody td strong[style*="color"],
        [data-theme="light"] .table tbody td span[style*="color"]:not(.badge):not(.status-pill) {
            color: #1A1A2E !important;
        }
        /* FIX: Default buttons in LIGHT MODE should NOT be black */
        [data-theme="light"] .btn:not(.btn-primary):not(.btn-success):not(.btn-danger):not(.btn-warning):not(.btn-info):not(.btn-secondary):not(.btn-label-danger):not(.btn-label-primary):not(.btn-purple):not(.btn-label-secondary):not(.btn-outline-secondary) {
            background-color: transparent !important;
            border-color: transparent !important;
        }

        /* ===== ACTION BUTTONS TEXT VISIBILITY FIX ===== */
        /* Light mode - action buttons with label style should have dark text on light background */
        [data-theme="light"] .btn-label-primary {
            background-color: rgba(147, 112, 219, 0.16) !important;
            color: #7B5FC7 !important;
            border: none !important;
        }
        [data-theme="light"] .btn-label-primary:hover {
            background-color: rgba(147, 112, 219, 0.25) !important;
            color: #6B4FAF !important;
        }
        [data-theme="light"] .btn-label-secondary {
            background-color: rgba(133, 146, 163, 0.16) !important;
            color: #5A6A7A !important;
            border: none !important;
        }
        [data-theme="light"] .btn-label-danger {
            background-color: rgba(255, 62, 29, 0.16) !important;
            color: #DC3545 !important;
            border: none !important;
        }

        /* Dark mode - action buttons should have light text visible on dark background */
        [data-theme="dark"] .btn-label-primary {
            background-color: rgba(147, 112, 219, 0.25) !important;
            color: #FFFFFF !important;
            border: none !important;
        }
        [data-theme="dark"] .btn-label-primary:hover {
            background-color: rgba(147, 112, 219, 0.4) !important;
            color: #FFFFFF !important;
        }
        [data-theme="dark"] .btn-label-secondary {
            background-color: rgba(133, 146, 163, 0.25) !important;
            color: #FFFFFF !important;
            border: none !important;
        }
        [data-theme="dark"] .btn-label-danger {
            background-color: rgba(255, 62, 29, 0.25) !important;
            color: #FF6B6B !important;
            border: none !important;
        }

        /* Dropdown toggle button in tables - ensure visible text */
        [data-theme="light"] .dropdown-toggle,
        [data-theme="light"] .dropup .dropdown-toggle {
            color: #2D2D4A !important;
        }
        [data-theme="dark"] .dropdown-toggle,
        [data-theme="dark"] .dropup .dropdown-toggle {
            color: #FFFFFF !important;
        }

        /* Action buttons in tables - visible in all modes */
        [data-theme="light"] .table .btn-sm {
            color: #2D2D4A !important;
        }
        [data-theme="dark"] .table .btn-sm {
            color: #FFFFFF !important;
        }
        [data-theme="light"] .table .btn-sm.btn-primary,
        [data-theme="light"] .table .btn-sm.btn-danger,
        [data-theme="light"] .table .btn-sm.btn-success {
            color: #FFFFFF !important;
        }
        [data-theme="dark"] .table .btn-sm.btn-primary,
        [data-theme="dark"] .table .btn-sm.btn-danger,
        [data-theme="dark"] .table .btn-sm.btn-success {
            color: #FFFFFF !important;
        }

        /* Outline buttons visibility */
        [data-theme="light"] .btn-outline-secondary {
            color: #5A6A7A !important;
            border-color: #CBD5E1 !important;
            background-color: transparent !important;
        }
        [data-theme="light"] .btn-outline-secondary:hover {
            background-color: #F1F5F9 !important;
            color: #2D2D4A !important;
        }
        [data-theme="dark"] .btn-outline-secondary {
            color: #D0D0E8 !important;
            border-color: #4A4A6A !important;
            background-color: transparent !important;
        }
        [data-theme="dark"] .btn-outline-secondary:hover {
            background-color: #3A3A5A !important;
            color: #FFFFFF !important;
        }

        /* ===================== TABLE TEXT COLOR FIX - Dark Mode ===================== */
        /* Force WHITE text on DARK table row backgrounds in DARK MODE */
        [data-theme="dark"] .table tbody tr,
        [data-theme="dark"] .table tbody tr td,
        [data-theme="dark"] .table tbody tr td strong,
        [data-theme="dark"] .table tbody tr td span:not(.badge):not(.status-pill),
        [data-theme="dark"] .table tbody tr td small,
        [data-theme="dark"] .table tbody tr td p,
        [data-theme="dark"] .table tbody tr td div:not(.badge),
        [data-theme="dark"] .table tbody tr td a:not(.btn),
        [data-theme="dark"] .table-sm tbody tr td,
        [data-theme="dark"] .table-sm tbody tr td * {
            color: #FFFFFF !important;
        }

        /* Dark mode - secondary text slightly dimmer */
        [data-theme="dark"] .table tbody td small,
        [data-theme="dark"] .table tbody td .text-muted {
            color: #D0D0E8 !important;
        }

        /* ===== DARK MODE TABLE ROW BACKGROUND FIX ===== */
        [data-theme="dark"] .table {
            --bs-table-bg: #252540 !important;
            --bs-table-striped-bg: #2A2A45 !important;
            --bs-table-hover-bg: #2F2F50 !important;
            background-color: #252540 !important;
        }

        [data-theme="dark"] .table tbody tr {
            background-color: #252540 !important;
        }

        [data-theme="dark"] .table tbody tr:nth-child(even) {
            background-color: #2A2A45 !important; /* striped effect */
        }

        /* ===================== CARD BODY TEXT FIX ===================== */
        /* Light mode - all text in card body should be dark */
        [data-theme="light"] .card-body *:not(.badge):not(.btn):not(.status-pill):not(i):not([class*="bx-"]):not([class*="fa-"]) {
            color: #1A1A2E !important;
        }

        /* Dark mode - all text in card body should be light */
        [data-theme="dark"] .card-body *:not(.badge):not(.btn):not(.status-pill):not(i):not([class*="bx-"]):not([class*="fa-"]) {
            color: #FFFFFF !important;
        }

        [data-theme="dark"] .card-body small,
        [data-theme="dark"] .card-body .text-muted {
            color: #D0D0E8 !important;
        }

        /* ===================== Buttons ===================== */
        .btn {
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.9375rem;
            transition: all 0.2s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--bs-primary) 0%, #8B5CF6 100%);
            border: none;
            color: #fff;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--bs-primary-dark) 0%, #7C3AED 100%);
            box-shadow: 0 4px 16px rgba(147, 112, 219, 0.4);
            transform: translateY(-1px);
        }

        .btn-label-danger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;

            background: rgba(255, 62, 29, 0.15);
            color: var(--bs-danger);
            border: none;

            padding: 6px 12px;
            border-radius: 8px;
            text-decoration: none; /* 🔥 IMPORTANT for <a> */
            white-space: nowrap;   /* 🔥 prevents text breaking */
        }

        .btn-label-danger:hover {
            background: var(--bs-danger);
            color: #fff;
        }

        .btn-success {
            background: var(--bs-success);
            border-color: var(--bs-success);
        }

        .btn-danger {
            background: var(--bs-danger);
            border-color: var(--bs-danger);
        }

        .btn-secondary {
            background: var(--bs-secondary);
            border-color: var(--bs-secondary);
        }

        .btn-label-primary {
            background: rgba(147, 112, 219, 0.15);
            color: var(--bs-primary);
            border: none;
        }

        .btn-label-primary:hover {
            background: var(--bs-primary);
            color: #fff;
        }

        .btn-label-danger {
            background: rgba(255, 62, 29, 0.15);
            color: var(--bs-danger);
            border: none;
        }

        .btn-label-danger:hover {
            background: var(--bs-danger);
            color: #fff;
        }

        .btn-icon {
            width: 38px;
            height: 38px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* PRIMARY PURPLE BUTTON */
        .btn-purple {
            background-color: #9370DB;
            border-color: #9370DB;
            color: #fff;
        }

        .btn-purple:hover {
            background-color: #7a5ac9;
            border-color: #7a5ac9;
            color: #fff;
        }

        /* OUTLINE VERSION */
        .btn-purple-outline {
            background-color: transparent;
            border: 2px solid #9370DB;
            color: #9370DB;
        }

        .btn-purple-outline:hover {
            background-color: #9370DB;
            color: #fff;
        }

        /* FORCE PURPLE BUTTONS ALWAYS */
        .btn-purple,
        .btn-secondary,
        .btn-label-danger {
            background-color: #9370DB !important;
            border-color: #9370DB !important;
            color: #fff !important;
        }

        .btn-purple:hover,
        .btn-secondary:hover,
        .btn-label-danger:hover {
            background-color: #7a5ac9 !important;
            border-color: #7a5ac9 !important;
        }

        /* ===================== Form Controls ===================== */
        .form-control,
        .form-select {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--heading-color);
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.9375rem;
            transition: all 0.2s;
        }

        .form-control:focus,
        .form-select:focus {
            background: var(--card-bg);
            border-color: var(--bs-primary);
            box-shadow: 0 0 0 3px rgba(147, 112, 219, 0.15);
            color: var(--heading-color);
        }

        .form-label {
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--heading-color);
            margin-bottom: 6px;
        }

        /* ===================== Badges ===================== */
        .badge {
            font-weight: 500;
            padding: 0.45em 0.8em;
            font-size: 0.8125rem;
            border-radius: 6px;
        }

        .badge-primary { background: rgba(147, 112, 219, 0.15); color: var(--bs-primary); }
        .badge-success { background: rgba(113, 221, 55, 0.15); color: var(--bs-success); }
        .badge-warning { background: rgba(255, 171, 0, 0.15); color: var(--bs-warning); }
        .badge-danger { background: rgba(255, 62, 29, 0.15); color: var(--bs-danger); }
        .badge-info { background: rgba(3, 195, 236, 0.15); color: var(--bs-info); }
        .badge-secondary { background: rgba(133, 146, 163, 0.15); color: var(--bs-secondary); }

        /* ===================== Status Pills ===================== */
        .status-pill {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 500;
        }

        .status-confirmed, .status-approved {
            background: rgba(113, 221, 55, 0.15);
            color: var(--bs-success);
        }

        .status-completed {
            background: rgba(91, 63, 168, 0.14);
            color: var(--jq-brand-text);
        }

        .status-disabled {
            background: rgba(133, 146, 163, 0.18);
            color: var(--bs-secondary);
        }

        .status-pending {
            background: rgba(255, 171, 0, 0.15);
            color: var(--bs-warning);
        }

        .status-cancelled {
            background: rgba(255, 62, 29, 0.15);
            color: var(--bs-danger);
        }

        .status-completed {
            background: rgba(147, 112, 219, 0.15);
            color: var(--bs-primary);
        }

        /* ===================== Flash Messages ===================== */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: none;
        }

        .alert-success {
            background: rgba(113, 221, 55, 0.15);
            color: var(--bs-success);
        }

        .alert-danger {
            background: rgba(255, 62, 29, 0.15);
            color: var(--bs-danger);
        }

        .alert-warning {
            background: rgba(255, 171, 0, 0.15);
            color: var(--bs-warning);
        }

        .alert-info {
            background: rgba(3, 195, 236, 0.15);
            color: var(--bs-info);
        }

        /* ===================== Modal ===================== */
        .modal-content {
            background: var(--card-bg);
            border: none;
            border-radius: 12px;
        }

        .modal-header {
            border-bottom: 1px solid var(--border-color);
            padding: 20px 24px;
        }

        .modal-title {
            font-weight: 600;
            color: var(--heading-color);
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            border-top: 1px solid var(--border-color);
            padding: 16px 24px;
        }

        .btn-close {
            filter: var(--body-color);
        }

        [data-theme="dark"] .btn-close {
            filter: invert(1);
        }

        [data-theme="dark"] .modal-content {
            background: var(--card-bg);
        }

        /* ===================== Page Header ===================== */
        .page-header {
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 1.625rem;
            font-weight: 600;
            color: var(--heading-color);
            margin: 0;
        }

        .breadcrumb {
            background: transparent;
            padding: 0;
            margin: 8px 0 0;
        }

        .breadcrumb-item {
            font-size: 0.8125rem;
        }

        .breadcrumb-item a {
            color: var(--bs-primary);
            text-decoration: none;
        }

        .breadcrumb-item.active {
            color: var(--bs-secondary);
        }

        /* ===================== Nav Tabs ===================== */
        .nav-tabs {
            border-bottom: 1px solid var(--border-color);
        }

        .nav-tabs .nav-link {
            color: var(--body-color);
            border: none;
            padding: 16px 24px;
        }

        .nav-tabs .nav-link:hover {
            border: none;
            color: var(--bs-primary);
        }

        .nav-tabs .nav-link.active {
            color: var(--bs-primary);
            background: transparent;
            border: none;
            border-bottom: 2px solid var(--bs-primary);
        }

        /* ===================== Responsive ===================== */
        @media (max-width: 1199px) {
            .layout-menu {
                transform: translateX(-100%);
            }

            .layout-menu.show {
                transform: translateX(0);
            }

            .layout-page {
                margin-left: 0;
            }

            .menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .layout-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 1035;
            }

            .layout-overlay.show {
                display: block;
            }
        }

        @media (max-width: 576px) {
            .content-wrapper {
                padding: 16px;
            }

            .search-container {
                display: none;
            }
        }

        /* ===================== Notifications Dropdown ===================== */
        .nav-notifications {
            position: relative;
        }

        .notifications-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 360px;
            max-height: 480px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s ease;
            z-index: 1050;
            display: flex;
            flex-direction: column;
        }

        .nav-notifications.show .notifications-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notifications-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .notifications-header h6 {
            color: var(--heading-color);
            font-weight: 600;
        }

        .notifications-list {
            flex: 1;
            overflow-y: auto;
            max-height: 350px;
        }

        .notification-item {
            display: flex;
            gap: 12px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: background 0.2s;
        }

        .notification-item:hover {
            background: var(--menu-item-hover-bg);
        }

        .notification-item.unread {
            background: rgba(147, 112, 219, 0.05);
        }

        .notification-item.unread::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 6px;
            height: 6px;
            background: var(--bs-primary);
            border-radius: 50%;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.125rem;
        }

        .notification-icon.report {
            background: rgba(147, 112, 219, 0.15);
            color: var(--bs-primary);
        }

        .notification-icon.feedback {
            background: rgba(3, 195, 236, 0.15);
            color: var(--bs-info);
        }

        .notification-icon.inventory {
            background: rgba(255, 171, 0, 0.15);
            color: var(--bs-warning);
        }

        .notification-icon.event {
            background: rgba(113, 221, 55, 0.15);
            color: var(--bs-success);
        }

        .notification-content {
            flex: 1;
            min-width: 0;
        }

        .notification-title {
            font-weight: 500;
            color: var(--heading-color);
            font-size: 0.875rem;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .notification-message {
            font-size: 0.8125rem;
            color: var(--body-color);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .notification-time {
            font-size: 0.75rem;
            color: var(--bs-secondary);
            margin-top: 4px;
        }

        .notifications-footer {
            padding: 12px 20px;
            border-top: 1px solid var(--border-color);
            text-align: center;
        }

        .notifications-footer a {
            font-size: 0.875rem;
            text-decoration: none;
            font-weight: 500;
        }

        .notifications-empty {
            padding: 40px 20px;
            text-align: center;
        }

        .notifications-empty i {
            font-size: 3rem;
            color: var(--bs-secondary);
            margin-bottom: 12px;
        }

        .notifications-empty p {
            color: var(--body-color);
            margin: 0;
        }

        @media (max-width: 576px) {
            .notifications-dropdown {
                width: 300px;
                right: -50px;
            }
        }

        /* ===================== SweetAlert2 — LIGHT MODE ===================== */
        [data-theme="light"] .swal2-popup {
            font-family: 'Public Sans', sans-serif !important;
            border-radius: 16px !important;
            padding: 32px 28px !important;
            background: #ffffff !important;
            color: #3d3558 !important;
            box-shadow: 0 20px 60px rgba(0,0,0,0.12) !important;
            border: 1px solid rgba(147, 112, 219, 0.18) !important;
        }
        [data-theme="light"] .swal2-title {
            color: #2d2449 !important;
            font-weight: 700 !important;
            font-size: 1.35rem !important;
        }
        [data-theme="light"] .swal2-html-container {
            color: #5e5a7a !important;
            font-size: 0.95rem !important;
        }
        [data-theme="light"] .swal2-cancel {
            border-radius: 8px !important;
            font-weight: 600 !important;
            padding: 10px 28px !important;
            background: #f0edf9 !important;
            color: #6e5fad !important;
            border: 1px solid rgba(147,112,219,0.25) !important;
        }
        [data-theme="light"] .swal2-icon.swal2-success .swal2-success-fix {
            background: #ffffff !important;
        }

        /* ===================== SweetAlert2 — DARK MODE ===================== */
        [data-theme="dark"] .swal2-popup {
            font-family: 'Public Sans', sans-serif !important;
            border-radius: 16px !important;
            padding: 32px 28px !important;
            background: #1e1b2e !important;
            color: #e0d9f7 !important;
            box-shadow: 0 25px 60px rgba(0,0,0,0.55) !important;
            border: 1px solid rgba(147, 112, 219, 0.22) !important;
        }
        [data-theme="dark"] .swal2-title {
            color: #e0d9f7 !important;
            font-weight: 700 !important;
            font-size: 1.35rem !important;
        }
        [data-theme="dark"] .swal2-html-container {
            color: #a89cc8 !important;
            font-size: 0.95rem !important;
        }
        [data-theme="dark"] .swal2-cancel {
            border-radius: 8px !important;
            font-weight: 600 !important;
            padding: 10px 28px !important;
            background: rgba(255,255,255,0.07) !important;
            color: #a89cc8 !important;
            border: 1px solid rgba(147,112,219,0.2) !important;
        }
        [data-theme="dark"] .swal2-icon.swal2-success .swal2-success-fix {
            background: #1e1b2e !important;
        }
        
        /* Dark mode - Enhanced icon visibility */
        [data-theme="dark"] .swal2-icon.swal2-success {
            background: rgba(0, 200, 120, 0.2) !important;
        }
        [data-theme="dark"] .swal2-icon.swal2-success .swal2-success-ring {
            border-color: rgba(0, 200, 120, 0.5) !important;
        }
        [data-theme="dark"] .swal2-icon.swal2-success [class^='swal2-success-line'] {
            background: #00e88d !important;
        }
        [data-theme="dark"] .swal2-icon.swal2-success .swal2-success-circular-line-left,
        [data-theme="dark"] .swal2-icon.swal2-success .swal2-success-circular-line-right {
            background: transparent !important;
        }
        
        [data-theme="dark"] .swal2-icon.swal2-error {
            background: rgba(255, 80, 100, 0.2) !important;
        }
        [data-theme="dark"] .swal2-icon.swal2-error [class^='swal2-x-mark-line'] {
            background: #ff6b7a !important;
        }
        
        [data-theme="dark"] .swal2-icon.swal2-warning {
            background: rgba(255, 200, 50, 0.2) !important;
        }
        [data-theme="dark"] .swal2-icon.swal2-warning .swal2-icon-content {
            color: #ffd54f !important;
        }
        
        [data-theme="dark"] .swal2-icon.swal2-question {
            background: rgba(180, 150, 255, 0.2) !important;
        }
        [data-theme="dark"] .swal2-icon.swal2-question .swal2-icon-content {
            color: #b896ff !important;
        }
        
        [data-theme="dark"] .swal2-icon.swal2-info {
            background: rgba(100, 180, 255, 0.2) !important;
        }
        [data-theme="dark"] .swal2-icon.swal2-info .swal2-icon-content {
            color: #64b5f6 !important;
        }

        /* ===================== SweetAlert2 — SHARED (both modes) ===================== */
        .swal2-icon {
            border: none !important;
            margin: 0 auto 16px auto !important;
            width: 76px !important;
            height: 76px !important;
        }
        /* Success icon */
        .swal2-icon.swal2-success {
            background: rgba(0, 200, 120, 0.1) !important;
            border-radius: 50% !important;
        }
        .swal2-icon.swal2-success .swal2-success-ring {
            border: 4px solid rgba(0, 200, 120, 0.35) !important;
            width: 76px !important;
            height: 76px !important;
            top: 0 !important;
            left: 0 !important;
        }
        .swal2-icon.swal2-success [class^='swal2-success-line'] {
            background: #00c878 !important;
            border-radius: 4px !important;
            height: 5px !important;
        }
        .swal2-icon.swal2-success .swal2-success-line-tip {
            width: 23px !important;
            top: 43px !important;
            left: 12px !important;
        }
        .swal2-icon.swal2-success .swal2-success-line-long {
            width: 38px !important;
            top: 37px !important;
            right: 8px !important;
        }
        /* Error icon */
        .swal2-icon.swal2-error {
            background: rgba(255, 60, 80, 0.1) !important;
            border-radius: 50% !important;
        }
        .swal2-icon.swal2-error .swal2-x-mark-line-left,
        .swal2-icon.swal2-error .swal2-x-mark-line-right {
            background: #ff3c50 !important;
            height: 5px !important;
            border-radius: 4px !important;
        }
        /* Warning icon */
        .swal2-icon.swal2-warning {
            background: rgba(255, 180, 0, 0.1) !important;
            border-radius: 50% !important;
            border: none !important;
            color: #ffb400 !important;
            font-size: 2.4rem !important;
            font-weight: 700 !important;
        }
        .swal2-icon.swal2-warning .swal2-icon-content { color: #ffb400 !important; }
        /* Question icon */
        .swal2-icon.swal2-question {
            background: rgba(147, 112, 219, 0.1) !important;
            border-radius: 50% !important;
            border: none !important;
            color: #9370DB !important;
            font-size: 2.4rem !important;
            font-weight: 700 !important;
        }
        .swal2-icon.swal2-question .swal2-icon-content { color: #9370DB !important; }
        /* Info icon */
        .swal2-icon.swal2-info {
            background: rgba(80, 160, 255, 0.1) !important;
            border-radius: 50% !important;
            border: none !important;
            color: #50a0ff !important;
            font-size: 2.4rem !important;
            font-weight: 700 !important;
        }
        .swal2-icon.swal2-info .swal2-icon-content { color: #50a0ff !important; }
        /* Confirm button */
        .swal2-confirm {
            background: linear-gradient(135deg, #9370DB 0%, #8B5CF6 100%) !important;
            border: none !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
            padding: 10px 28px !important;
            box-shadow: 0 4px 15px rgba(147, 112, 219, 0.35) !important;
            color: #fff !important;
        }
        .swal2-confirm:hover {
            box-shadow: 0 6px 20px rgba(147, 112, 219, 0.5) !important;
        }
        .swal2-timer-progress-bar {
            background: linear-gradient(135deg, #9370DB, #8B5CF6) !important;
        }
        /* Global Loading Overlay */
        #janet-loading-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(14, 12, 26, 0.75);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 18px;
        }
        #janet-loading-overlay.active { display: flex; }
        .janet-spinner {
            width: 56px;
            height: 56px;
            border: 4px solid rgba(147, 112, 219, 0.2);
            border-top-color: #9370DB;
            border-radius: 50%;
            animation: janet-spin 0.8s linear infinite;
        }
        .janet-loading-text {
            color: #c4b5f7;
            font-size: 0.95rem;
            font-weight: 500;
            letter-spacing: 0.03em;
        }
        @keyframes janet-spin { to { transform: rotate(360deg); } }
    </style>
    <!-- Shared theme tokens (light / dark) and components used by Activity Log, Backups and Event Bookings -->
    <link rel="stylesheet" href="static/css/app-theme.css?v=<?php echo @filemtime(__DIR__ . '/../static/css/app-theme.css') ?: 1; ?>">
    <script src="static/js/field-rules.js?v=<?php echo @filemtime(__DIR__ . '/../static/js/field-rules.js') ?: 1; ?>"></script>
    <script src="static/js/idle-logout.js" data-limit="<?php echo (int)JQ_SESSION_IDLE_SECONDS; ?>" data-warn="120" data-ping="ajax/session_ping.php" data-logout="logout.php?reason=idle" defer></script>
    <script>
    // Adds this session's security token to every form and same-site request (checked in includes/auth_check.php).
    (function () {
        var token = <?php echo json_encode(function_exists('jq_csrf_token') ? jq_csrf_token() : ''); ?>;
        function addToken(form) {
            if ((form.method || '').toLowerCase() === 'post' && !form.querySelector('input[name="csrf_token"]')) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'csrf_token'; input.value = token;
                form.appendChild(input);
            }
        }
        var nativeSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () { addToken(this); return nativeSubmit.call(this); };
        document.addEventListener('submit', function (e) { addToken(e.target); }, true);
        function sameSite(url) {
            try { return new URL(url, location.href).origin === location.origin; } catch (e) { return false; }
        }
        var nativeFetch = window.fetch;
        window.fetch = function (input, init) {
            init = init || {};
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            if (sameSite(url)) {
                var headers = new Headers(init.headers || (typeof input !== 'string' && input.headers) || {});
                headers.set('X-CSRF-Token', token);
                init.headers = headers;
                init.credentials = init.credentials || 'same-origin';
            }
            return nativeFetch.call(this, input, init);
        };
        var nativeOpen = XMLHttpRequest.prototype.open, nativeSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (method, url) { this._jqSameSite = sameSite(url); return nativeOpen.apply(this, arguments); };
        XMLHttpRequest.prototype.send = function () { if (this._jqSameSite) { this.setRequestHeader('X-CSRF-Token', token); } return nativeSend.apply(this, arguments); };
    })();
    </script>
</head>
<body>
    <!-- Global Loading Overlay -->
    <div id="janet-loading-overlay">
        <div class="janet-spinner"></div>
        <div class="janet-loading-text" id="janet-loading-text">Please wait...</div>
    </div>

    <div class="layout-wrapper">
        <!-- Overlay for mobile menu -->
        <div class="layout-overlay" onclick="toggleMenu()"></div>
        
        <!-- Sidebar Menu -->
        <aside class="layout-menu">
            <div class="app-brand">
                <div class="app-brand-logo">
                    <img src="static/images/logo.png" alt="Janet's Catering">
                </div>
                <a href="dashboard.php" class="app-brand-text">
                    Janet's Quality
                    <span>Catering Services</span>
                </a>
            </div>

            <div class="menu-inner">
                <?php if (($current_user['role'] ?? '') === 'SUPERVISOR'): ?>
                <div class="menu-header">Main</div>
                <div class="menu-item">
                    <a href="supervisor_dashboard.php" class="menu-link active">
                        <i class="bx bx-calendar-check"></i>
                        <span>Event Bookings Dashboard</span>
                    </a>
                </div>
                <?php else: ?>
                <div class="menu-header">Main</div>
                
                <div class="menu-item">
                    <a href="dashboard.php" class="menu-link <?php echo ($current_page === 'dashboard') ? 'active' : ''; ?>">
                        <i class="bx bx-home-circle"></i>
                        <span>Dashboard</span>
                    </a>
                </div>

                <div class="menu-header">Management</div>
                
                <div class="menu-item">
                    <a href="event_bookings.php" class="menu-link <?php echo ($current_page === 'event_bookings') ? 'active' : ''; ?>">
                        <i class="bx bx-calendar-check"></i>
                        <span>Event Bookings</span>
                    </a>
                </div>
                
                <div class="menu-item">
                    <a href="clients.php" class="menu-link <?php echo ($current_page === 'clients') ? 'active' : ''; ?>">
                        <i class="bx bx-id-card"></i>
                        <span>Client Records</span>
                    </a>
                </div>

                <div class="menu-item has-submenu">
                    <a href="javascript:void(0)" class="menu-link <?php echo ($current_page === 'backdrops') ? 'active' : ''; ?>" onclick="toggleBackdropSubmenu(event)">
                        <i class="bx bx-image"></i>
                        <span>Backdrops</span>
                        <i class="bx bx-chevron-down submenu-arrow"></i>
                    </a>
                    <div class="submenu" id="backdropSubmenu">
                        <?php
                        // Get event type folders for sidebar
                        $sidebar_event_types = [
                            'Weddings' => ['name' => 'Wedding Reception', 'icon' => 'bx-heart'],
                            'Birthdays' => ['name' => 'Birthday Party', 'icon' => 'bx-cake'],
                            'Debut' => ['name' => 'Debut (18th Birthday)', 'icon' => 'bx-crown'],
                            'Kiddie Birthday' => ['name' => 'Kiddie Birthday', 'icon' => 'bx-child'],
                            'Christmas Party' => ['name' => 'Christmas Party', 'icon' => 'bx-gift'],
                            'Seminar' => ['name' => 'Seminar / Conference', 'icon' => 'bx-briefcase'],
                            'Parties' => ['name' => 'General Parties', 'icon' => 'bx-party'],
                            'WeddingAnniv' => ['name' => 'Wedding Anniversary', 'icon' => 'bx-calendar-heart'],
                            'RenewalofVows' => ['name' => 'Renewal of Vows', 'icon' => 'bx-heart-circle']
                        ];
                        
                        $selected_type = $_GET['type'] ?? '';
                        foreach ($sidebar_event_types as $key => $info):
                        ?>
                        <a href="backdrops.php?type=<?php echo urlencode($key); ?>" class="submenu-link <?php echo ($selected_type === $key) ? 'active' : ''; ?>">
                            <i class="bx <?php echo $info['icon']; ?>"></i>
                            <span><?php echo $info['name']; ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="menu-item">
                    <a href="surveys.php" class="menu-link <?php echo ($current_page === 'surveys') ? 'active' : ''; ?>">
                        <i class="bx bx-message-square-dots"></i>
                        <span>Client Surveys</span>
                    </a>
                </div>

                <div class="menu-header">Food Management</div>

                <div class="menu-item">
                    <a href="food_menus.php" class="menu-link <?php echo ($current_page === 'food_menus') ? 'active' : ''; ?>">
                        <i class="bx bx-restaurant"></i>
                        <span>Food Menus</span>
                    </a>
                </div>

                <?php if (isset($current_user) && in_array($current_user['role'], ['ADMIN', 'OWNER'], true)): ?>
                <?php if ($current_user['role'] === 'OWNER'): ?>
                <div class="menu-header">Administration</div>
                
                <div class="menu-item">
                    <a href="accounts.php" class="menu-link <?php echo ($current_page === 'accounts') ? 'active' : ''; ?>">
                        <i class="bx bx-user-plus"></i>
                        <span>Accounts</span>
                    </a>
                </div>
                <?php endif; ?>
                
                <div class="menu-header">Reports</div>
                
                <div class="menu-item">
                    <a href="reports.php" class="menu-link <?php echo ($current_page === 'reports') ? 'active' : ''; ?>">
                        <i class="bx bx-file"></i>
                        <span>Reports</span>
                    </a>
                </div>

                <div class="menu-item">
                    <a href="predictive_analytics.php" class="menu-link <?php echo ($current_page === 'predictive_analytics') ? 'active' : ''; ?>">
                        <i class="bx bx-line-chart"></i>
                        <span>Predictive Analytics</span>
                    </a>
                </div>
                <?php endif; ?>

                <?php
                // Monitoring: Activity Log and Notifications (ADMIN + OWNER), Backup & Restore (ADMIN only).
                // The pages themselves re-check permissions on the server; this only controls what is shown.
                $jq_nav_role = (isset($current_user) && function_exists('jq_verified_role')) ? jq_verified_role($pdo ?? null) : ($current_user['role'] ?? '');
                if (in_array($jq_nav_role, ['ADMIN', 'OWNER'], true)):
                ?>
                <div class="menu-header">Monitoring</div>

                <div class="menu-item">
                    <a href="activitylog.php" class="menu-link <?php echo ($current_page === 'activitylog') ? 'active' : ''; ?>">
                        <i class="bx bx-history"></i>
                        <span>Activity Log</span>
                    </a>
                </div>

                <div class="menu-item">
                    <a href="notifications.php" class="menu-link <?php echo ($current_page === 'notifications') ? 'active' : ''; ?>">
                        <i class="bx bx-bell"></i>
                        <span>Notifications</span>
                    </a>
                </div>

                <?php if ($jq_nav_role === 'ADMIN'): ?>
                <div class="menu-item">
                    <a href="backups.php" class="menu-link <?php echo ($current_page === 'backups') ? 'active' : ''; ?>">
                        <i class="bx bx-data"></i>
                        <span>Backup &amp; Restore</span>
                    </a>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <div class="menu-divider"></div>

                <div class="menu-item">
                    <a href="profile.php" class="menu-link <?php echo ($current_page === 'profile') ? 'active' : ''; ?>">
                        <i class="bx bx-user-circle"></i>
                        <span>Profile</span>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Layout Page -->
        <div class="layout-page">
            <!-- Navbar -->
            <nav class="layout-navbar">
                <div class="navbar-nav-left">
                    <button class="menu-toggle" onclick="toggleMenu()" aria-label="Toggle menu">
                        <i class="bx bx-menu"></i>
                    </button>
                    <?php
                    // The signed-in user, read from the database by auth_check.php
                    $hdr_name = trim((string)($current_user['first_name'] ?? '') . ' ' . (string)($current_user['last_name'] ?? ''));
                    $hdr_name = $hdr_name !== '' ? $hdr_name : (string)($current_user['username'] ?? $_SESSION['username'] ?? 'User');
                    $hdr_role = (string)($current_user['role'] ?? $_SESSION['role'] ?? '');
                    $hdr_photo = !empty($current_user['profile_photo']) && jq_file_exists($current_user['profile_photo']) ? $current_user['profile_photo'] : null;
                    ?>
                </div>

                <div class="navbar-nav-right">
                    <!-- Theme Toggle -->
                    <button class="nav-item-icon" onclick="toggleTheme()" title="Toggle Theme">
                        <i class="bx bx-moon" id="themeIcon"></i>
                    </button>

                    <?php if (($current_user['role'] ?? '') !== 'SUPERVISOR'): ?>
                    <!-- Notifications Dropdown -->
                    <div class="nav-notifications" id="notificationsDropdown">
                        <button class="nav-item-icon" onclick="toggleNotifications()" title="Notifications">
                            <i class="bx bx-bell"></i>
                            <span class="nav-badge" id="notificationBadge" style="display: none;">0</span>
                        </button>
                        
                        <div class="notifications-dropdown" id="notificationsPanel">
                            <div class="notifications-header">
                                <h6 class="mb-0">Notifications</h6>
                                <button class="btn btn-sm btn-link text-primary p-0" onclick="markAllNotificationsRead()">
                                    Mark all as read
                                </button>
                            </div>
                            <div class="notifications-list" id="notificationsList">
                                <div class="text-center py-4 text-muted">
                                    <i class="bx bx-loader-alt bx-spin fs-3"></i>
                                    <p class="mb-0 mt-2">Loading notifications...</p>
                                </div>
                            </div>
                            <div class="notifications-footer">
                                <a href="notifications.php" class="text-primary">View notification history</a>
                            </div>
                        </div>
                    </div>

                    <?php endif; ?>

                    <!-- Signed-in user menu -->
                    <div class="nav-user" id="userMenu">
                        <button type="button" class="nav-user-toggle" id="userMenuButton" onclick="toggleUserMenu()" aria-expanded="false" aria-controls="userMenuPanel" title="<?php echo htmlspecialchars($hdr_name); ?>">
                            <?php if ($hdr_photo): ?>
                            <span class="nav-user-avatar nav-identity-photo"><img src="<?php echo htmlspecialchars($hdr_photo); ?>?t=<?php echo (int)filemtime($hdr_photo); ?>" alt=""></span>
                            <?php endif; ?>
                            <span class="nav-identity-text">
                                <span class="nav-identity-name"><?php echo htmlspecialchars($hdr_name); ?></span>
                                <span class="nav-identity-role"><?php echo htmlspecialchars($hdr_role); ?></span>
                            </span>
                            <i class="bx bx-chevron-down nav-user-caret"></i>
                        </button>
                        <div class="user-dropdown" id="userMenuPanel">
                            <?php if (($current_user['role'] ?? '') !== 'SUPERVISOR'): ?>
                            <a href="profile.php" class="dropdown-item"><i class="bx bx-user"></i><span>Profile / Settings</span></a>
                            <div class="dropdown-divider"></div>
                            <?php endif; ?>
                            <button type="button" class="dropdown-item text-danger" onclick="setUserMenu(false); confirmLogout();"><i class="bx bx-log-out"></i><span>Logout</span></button>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Global JS: Loading overlay + confirmLogout (always loaded) -->
            <script>
            function showLoading(msg) {
                var overlay = document.getElementById('janet-loading-overlay');
                var text    = document.getElementById('janet-loading-text');
                if (text) text.textContent = msg || 'Please wait...';
                if (overlay) overlay.classList.add('active');
            }
            function hideLoading() {
                var overlay = document.getElementById('janet-loading-overlay');
                if (overlay) overlay.classList.remove('active');
            }
            function confirmLogout() {
                Swal.fire({
                    icon: 'question',
                    title: 'Log Out?',
                    text: 'Are you sure you want to log out?',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Log Out',
                    cancelButtonText: 'Cancel',
                    reverseButtons: true
                }).then(function(result) {
                    if (result.isConfirmed) {
                        showLoading('Logging out...');
                        window.location.href = 'logout.php';
                    }
                });
            }
            function setUserMenu(open) {
                var menu = document.getElementById('userMenu');
                if (!menu) return;
                menu.classList.toggle('show', open);
                document.getElementById('userMenuButton').setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            function toggleUserMenu() {
                setUserMenu(!document.getElementById('userMenu').classList.contains('show'));
            }
            document.addEventListener('click', function(e) {
                var menu = document.getElementById('userMenu');
                if (menu && !menu.contains(e.target)) setUserMenu(false);
            });
            document.addEventListener('keydown', function(e) {
                var menu = document.getElementById('userMenu');
                if (e.key === 'Escape' && menu && menu.classList.contains('show')) {
                    setUserMenu(false);
                    document.getElementById('userMenuButton').focus();
                }
            });
            // Auto-show loading on form submit
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('form').forEach(function(form) {
                    if (form.dataset.noLoading) return;
                    form.addEventListener('submit', function() {
                        showLoading('Please wait...');
                    });
                });
            });
            </script>

            <!-- Content Wrapper -->
            <div class="content-wrapper">
                <?php 
                $flash = getFlash();
                if ($flash): 
                ?>
                <!-- Hidden flash data for SweetAlert -->
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    <?php 
                    $icon = 'info';
                    $title = 'Notice';
                    if ($flash['type'] === 'success') {
                        $icon = 'success';
                        $title = 'Saved!';
                    } elseif ($flash['type'] === 'danger' || $flash['type'] === 'error') {
                        $icon = 'error';
                        $title = 'Failed!';
                    } elseif ($flash['type'] === 'warning') {
                        $icon = 'warning';
                        $title = 'Warning';
                    }
                    ?>
                    Swal.fire({
                        icon: '<?php echo $icon; ?>',
                        title: '<?php echo $title; ?>',
                        text: '<?php echo addslashes(strip_tags($flash['message'])); ?>',
                        confirmButtonColor: '#9370DB',
                        timer: <?php echo ($flash['type'] === 'success') ? '2500' : '0'; ?>,
                        timerProgressBar: <?php echo ($flash['type'] === 'success') ? 'true' : 'false'; ?>
                    });
                });
                </script>
                <?php endif; ?>
