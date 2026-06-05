<?php
require __DIR__ . '/includes/session_init.php';
require 'includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

// Create table if not exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS wishlist (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        tmdb_id    INT NOT NULL,
        title      VARCHAR(255) NOT NULL DEFAULT '',
        poster     VARCHAR(500) NOT NULL DEFAULT '',
        year       VARCHAR(4)   NOT NULL DEFAULT '',
        added_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_movie (user_id, tmdb_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$userId = (int)$_SESSION['user_id'];
$tmdbId = (int)($_POST['tmdb_id'] ?? 0);
$title  = trim($_POST['title']   ?? '');
$poster = trim($_POST['poster']  ?? '');
$year   = trim($_POST['year']    ?? '');

if (!$tmdbId) {
    echo json_encode(['error' => 'invalid']);
    exit;
}

$check = $pdo->prepare("SELECT id FROM wishlist WHERE user_id = ? AND tmdb_id = ?");
$check->execute([$userId, $tmdbId]);

if ($check->fetch()) {
    $pdo->prepare("DELETE FROM wishlist WHERE user_id = ? AND tmdb_id = ?")->execute([$userId, $tmdbId]);
    echo json_encode(['status' => 'removed']);
} else {
    $pdo->prepare("INSERT INTO wishlist (user_id, tmdb_id, title, poster, year) VALUES (?,?,?,?,?)")
        ->execute([$userId, $tmdbId, $title, $poster, $year]);
    echo json_encode(['status' => 'added']);
}
