<?php

require __DIR__ . '/includes/session_init.php';

$_SESSION = [];

session_destroy();

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Location: login.php");
exit;