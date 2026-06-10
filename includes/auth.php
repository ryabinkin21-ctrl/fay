<?php

require_once __DIR__ . '/session_init.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: /login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}