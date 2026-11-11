<?php
/**
 * Vercel entry point (vercel.json sends every page request here).
 * Runs the requested page from the project folder exactly as Apache does on XAMPP.
 * Only top-level pages and ajax/ endpoints can be opened; includes/, config/ and the rest stay private.
 */
$root = dirname(__DIR__);
$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/');
if ($path === '' || $path === 'index.php') {
    $path = 'login.php';
}
if (!preg_match('~^(ajax/)?[a-z0-9_]+\.php$~', $path) || !is_file("$root/$path")) {
    http_response_code(404);
    exit('Page not found.');
}
date_default_timezone_set('Asia/Manila');
chdir(dirname("$root/$path"));
$_SERVER['SCRIPT_FILENAME'] = "$root/$path";
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $path;
require "$root/$path";
