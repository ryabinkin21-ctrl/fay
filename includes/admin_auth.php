<?php

require __DIR__ . "/auth.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: index.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}