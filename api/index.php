<?php
/**
 * Vercel entry point (vercel.json sends every page request here).
 * Runs the requested page from the project folder exactly as Apache does on XAMPP.
 * Only top-level pages and ajax/ endpoints can be opened; includes/, config/ and the rest stay private.
 * Public pictures under uploads/ (menus, profile and survey photos) are sent from the database.
 */
$root = dirname(__DIR__);
date_default_timezone_set('Asia/Manila');

// Vercel ends HTTPS at its proxy; tell the pages the visit is secure and who the visitor is.
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $_SERVER['REMOTE_ADDR'] = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
}

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/');
// Local testing with PHP's built-in server: let it send pictures, styles and scripts itself (Vercel does this before PHP).
if (PHP_SAPI === 'cli-server' && $path !== '' && !str_ends_with($path, '.php') && !str_contains($path, '..') && is_file("$root/$path")
    && !preg_match('~^(includes|config|database|cli|uploads/private)/~', $path)) {
    return false;
}
if ($path === '' || $path === 'index.php') {
    $path = 'login.php';
}

if (preg_match('~^uploads/(menus|profile|surveys)/[A-Za-z0-9_.-]+$~', $path)) {
    require "$root/config/database.php";
    $file = jq_file_read($path);
    if (!$file || !str_starts_with($file['mime'], 'image/')) {
        http_response_code(404);
        exit('Not found.');
    }
    header('Content-Type: ' . $file['mime']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=86400');
    echo $file['data'];
    exit;
}

if (!preg_match('~^(ajax/)?[a-z0-9_]+\.php$~', $path) || !is_file("$root/$path")) {
    http_response_code(404);
    exit('Page not found.');
}
chdir(dirname("$root/$path"));
$_SERVER['SCRIPT_FILENAME'] = "$root/$path";
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $path;
require "$root/$path";
