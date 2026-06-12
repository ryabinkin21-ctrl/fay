<?php
require 'includes/auth.php';
require 'includes/db.php';
require_once 'includes/lang.php';
require 'includes/tmdb.php';

header('Content-Type: application/json');

$q    = trim($_GET['q'] ?? '');
$isTv = (($_GET['type'] ?? '') === 'tv');
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$lang = tmdbLang($currentLang);
$results = $isTv
    ? array_slice(tmdbSearchTvFuzzy($q, 1, $lang), 0, 6)
    : array_slice(tmdbSearchFuzzy($q, 1, $lang), 0, 6);

$out = [];
foreach ($results as $m) {
    // TV uses name/first_air_date, movies use title/release_date
    $title = $isTv ? ($m['name'] ?? '')           : ($m['title'] ?? '');
    $date  = $isTv ? ($m['first_air_date'] ?? '') : ($m['release_date'] ?? '');
    $out[] = [
        'id'     => (int)$m['id'],
        'title'  => $title,
        'year'   => $date !== '' ? substr($date, 0, 4) : '',
        'poster' => !empty($m['poster_path'])
                        ? 'https://image.tmdb.org/t/p/w92' . $m['poster_path']
                        : '',
    ];
}

echo json_encode($out);
