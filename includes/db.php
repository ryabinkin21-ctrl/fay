<?php

$host = "sql107.infinityfree.com";
$dbname = "if0_42072952_fay";
$user = "if0_42072952";
$password = "X0jNGIjd62UnzP6";
try {

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch(PDOException $e) {

    die("Connection failed: " . $e->getMessage());
}
?>