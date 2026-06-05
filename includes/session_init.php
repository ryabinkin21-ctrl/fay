<?php
if (session_status() !== PHP_SESSION_NONE) return;

$_sessionDir = dirname(__DIR__) . '/tmp/sessions';
if (!is_dir($_sessionDir)) {
    @mkdir($_sessionDir, 0700, true);
}
if (is_dir($_sessionDir) && is_writable($_sessionDir)) {
    session_save_path($_sessionDir);
}

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();
