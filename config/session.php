<?php
/**
 * Starts the PHP session with secure cookie settings. Require this instead of calling session_start().
 */
if (session_status() === PHP_SESSION_NONE) {
    $jq_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');   // refuse session ids the server did not create
    ini_set('session.use_only_cookies', '1');  // never accept a session id from the URL
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $jq_https,
        'httponly' => true,                    // page scripts cannot read the cookie
        'samesite' => 'Lax',                   // other sites cannot post with it
    ]);
    session_start();
}
