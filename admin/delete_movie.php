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

$pdo->prepare("DELETE FROM movies WHERE id = ?")->execute([$id]);

header("Location: dashboard.php");
exit;
