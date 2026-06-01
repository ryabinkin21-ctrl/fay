<?php

require "../includes/admin_auth.php";
require "../includes/db.php";

if (!isset($_GET["id"])) {
    header("Location: dashboard.php");
    exit;
}

$id = (int)$_GET["id"];

$stmt = $pdo->prepare("DELETE FROM movies WHERE id = ?");
$stmt->execute([$id]);

header("Location: dashboard.php");
exit;