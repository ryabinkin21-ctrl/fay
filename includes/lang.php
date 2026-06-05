<?php
require_once __DIR__ . '/session_init.php';

/* ── determine active language ─────────────────────────
   Priority: session > cookie > user DB preference > 'en'
─────────────────────────────────────────────────────── */
function _initLang($pdo = null): string {
    if (isset($_SESSION['lang'])) {
        return $_SESSION['lang'];
    }

    // check cookie
    if (!empty($_COOKIE['lang']) && in_array($_COOKIE['lang'], ['en', 'ru'], true)) {
        $_SESSION['lang'] = $_COOKIE['lang'];
        return $_SESSION['lang'];
    }

    // check DB for logged-in user
    if ($pdo && !empty($_SESSION['user_id'])) {
        try {
            $s = $pdo->prepare("SELECT lang FROM users WHERE id = ?");
            $s->execute([$_SESSION['user_id']]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row && in_array($row['lang'], ['en', 'ru'], true)) {
                $_SESSION['lang'] = $row['lang'];
                return $_SESSION['lang'];
            }
        } catch (PDOException $e) { /* column may not exist yet */ }
    }

    $_SESSION['lang'] = 'en';
    return 'en';
}

/* call with $pdo if available — header.php requires db.php first */
$currentLang = _initLang($pdo ?? null);

/* load translation array */
$__t = require __DIR__ . "/../lang/{$currentLang}.php";

/* translate key, optional sprintf args */
function t(string $key, ...$args): string {
    global $__t;
    $str = $__t[$key] ?? $key;
    return $args ? vsprintf($str, $args) : $str;
}

/* raw (no escaping) — for HTML entities already in strings */
function tr(string $key, ...$args): string {
    return t($key, ...$args);
}
