<?php
require "../includes/admin_auth.php";
require "../includes/db.php";

if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit;
}

$id = (int)$_GET['id'];

// Get movie_id before deleting so we can recalculate its rating
$stmt = $pdo->prepare("SELECT movie_id FROM reviews WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if ($row) {
    $movieId = (int)$row['movie_id'];

    $pdo->prepare("DELETE FROM reviews WHERE id = ?")->execute([$id]);

    // Recalculate avg rating (set to NULL if no reviews left)
    $avg = $pdo->prepare("SELECT AVG(score) AS a FROM reviews WHERE movie_id = ?");
    $avg->execute([$movieId]);
    $newRating = $avg->fetch()['a'];

    $pdo->prepare("UPDATE movies SET rating = ? WHERE id = ?")
        ->execute([$newRating ? round($newRating, 1) : null, $movieId]);
}

header("Location: dashboard.php#reviews");
exit;
