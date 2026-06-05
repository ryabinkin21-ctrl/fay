<?php
require __DIR__ . '/includes/session_init.php';

$newLang = (($_GET['lang'] ?? '') === 'ru') ? 'ru' : 'en';

/* save to session */
$_SESSION['lang'] = $newLang;

/* save to cookie — 1 year */
setcookie('lang', $newLang, time() + 365 * 24 * 3600, '/');

/* save to DB for logged-in users */
if (!empty($_SESSION['user_id'])) {
    require __DIR__ . '/includes/db.php';

    /* add column if it doesn't exist yet */
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN lang CHAR(2) NOT NULL DEFAULT 'en'");
    } catch (PDOException $e) { /* already exists */ }

    try {
        $stmt = $pdo->prepare("UPDATE users SET lang = ? WHERE id = ?");
        $stmt->execute([$newLang, $_SESSION['user_id']]);
    } catch (PDOException $e) { /* ignore */ }
}

/* redirect back */
$ref = $_SERVER['HTTP_REFERER'] ?? '/movie_review_site/index.php';
header("Location: $ref");
exit;
