<?php
require 'includes/auth.php';
require 'includes/db.php';
require_once 'includes/lang.php';
require 'includes/tmdb.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$results = array_slice(tmdbSearchFuzzy($q, 1, tmdbLang($currentLang)), 0, 6);

$out = [];
foreach ($results as $m) {
    $out[] = [
        'id'     => (int)$m['id'],
        'title'  => $m['title'],
        'year'   => !empty($m['release_date']) ? substr($m['release_date'], 0, 4) : '',
        'poster' => !empty($m['poster_path'])
                        ? 'https://image.tmdb.org/t/p/w92' . $m['poster_path']
                        : '',
    ];
}

echo json_encode($out);
