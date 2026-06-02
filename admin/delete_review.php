<?php

require "../includes/admin_auth.php";
require "../includes/db.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_POST['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    || !isset($_POST['id'])
) {
    header("Location: dashboard.php");
    exit;
}

$id = (int)$_POST['id'];

$stmt = $pdo->prepare("SELECT movie_id FROM reviews WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if ($row) {
    $movieId = (int)$row['movie_id'];

    $pdo->prepare("DELETE FROM reviews WHERE id = ?")->execute([$id]);

    $avg = $pdo->prepare("
        SELECT AVG(r.score) AS a
        FROM reviews r
        INNER JOIN (
            SELECT user_id, MAX(id) AS latest_id
            FROM reviews WHERE movie_id = ?
            GROUP BY user_id
        ) t ON r.id = t.latest_id
    ");
    $avg->execute([$movieId]);
    $newRating = $avg->fetch()['a'];

    $pdo->prepare("UPDATE movies SET rating = ? WHERE id = ?")
        ->execute([$newRating ? round($newRating, 1) : null, $movieId]);
}

header("Location: dashboard.php#reviews");
exit;
