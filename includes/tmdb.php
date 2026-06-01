<?php
require_once __DIR__ . "/config.php";

function tmdbRequest(string $url): ?array {
    $options = [
        "http" => [
            "header"  => "Authorization: Bearer " . TMDB_TOKEN . "\r\naccept: application/json\r\n",
            "timeout" => 5,
        ]
    ];
    $context  = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) return null;
    return json_decode($response, true);
}

function tmdbCached(string $url): ?array {
    global $pdo;
    $key  = md5($url);
    $stmt = $pdo->prepare("SELECT data, expires_at FROM tmdb_cache WHERE cache_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if ($row && strtotime($row['expires_at']) > time()) {
        return json_decode($row['data'], true);
    }
    $data = tmdbRequest($url);
    if ($data === null) return null;
    $expires = date('Y-m-d H:i:s', time() + 3600);
    $pdo->prepare("
        INSERT INTO tmdb_cache (cache_key, data, expires_at) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at)
    ")->execute([$key, json_encode($data), $expires]);
    return $data;
}

function tmdbLang(string $lang): string {
    return $lang === 'ru' ? 'ru-RU' : 'en-US';
}

function tmdbGetDiscover(string $sort = 'popularity.desc', int $page = 1, string $lang = 'en-US', int $genreId = 0): ?array {
    $today  = date('Y-m-d');
    $extra  = str_starts_with($sort, 'vote_average') ? '&vote_count.gte=200' : '';
    $extra .= $genreId ? '&with_genres=' . $genreId : '';
    $extra .= '&primary_release_date.lte=' . $today;
    $extra .= '&without_origin_country=IN';
    $url    = "https://api.themoviedb.org/3/discover/movie?sort_by={$sort}&language={$lang}&page={$page}{$extra}";
    return tmdbCached($url);
}

function tmdbSearch(string $query, int $page = 1, string $lang = 'en-US'): ?array {
    $url = "https://api.themoviedb.org/3/search/movie?query=" . urlencode($query) . "&language={$lang}&page={$page}";
    return tmdbCached($url);
}

// Языки с латиницей или кириллицей
const ALLOWED_LANGS = [
    'en','ru','fr','de','es','it','pt','nl','sv','no','da','fi',
    'hu','ro','pl','cs','sk','hr','sl','lt','lv','et','tr','id',
    'ms','uk','bg','sr','mk','be','ca','gl','eu','sq','bs','is',
];

function tmdbHasLatinOrCyrillic(array $movie): bool {
    $lang  = $movie['original_language'] ?? '';
    $title = $movie['title'] ?? '';

    // если язык из разрешённого списка — пропускаем
    if (in_array($lang, ALLOWED_LANGS, true)) return true;

    // если язык неизвестен — проверяем символы в названии
    if ($lang === '') {
        $lettersOnly = preg_replace('/[\d\s\p{P}\p{S}]/u', '', $title);
        if ($lettersOnly === '') return true;
        return (bool) preg_match('/^[\p{Latin}\p{Cyrillic}]+$/u', $lettersOnly);
    }

    return false;
}

function tmdbTranslit(string $text): string {
    $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo',
        'ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m',
        'н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u',
        'ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch',
        'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'Yo',
        'Ж'=>'Zh','З'=>'Z','И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M',
        'Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U',
        'Ф'=>'F','Х'=>'Kh','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch',
        'Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
    ];
    return strtr($text, $map);
}

function tmdbSearchFuzzy(string $query, int $page = 1, string $lang = 'en-US'): array {
    $seen    = [];
    $results = [];
    $queryLc = mb_strtolower($query, 'UTF-8');

    $add = function(array $items) use (&$seen, &$results, $queryLc) {
        foreach ($items as $m) {
            if (empty($m['poster_path']) || isset($seen[$m['id']])) continue;
            // считаем схожесть с запросом по нескольким полям
            $titleLc = mb_strtolower($m['title'] ?? '', 'UTF-8');
            $origLc  = mb_strtolower($m['original_title'] ?? '', 'UTF-8');
            similar_text($queryLc, $titleLc, $pct1);
            similar_text($queryLc, $origLc,  $pct2);
            $m['_score']     = max($pct1, $pct2);
            $seen[$m['id']]  = true;
            $results[]       = $m;
        }
    };

    // 1. Полный запрос на языке пользователя
    $add(tmdbSearch($query, $page, $lang)['results'] ?? []);

    // 2. Полный запрос по-английски
    if ($lang !== 'en-US') {
        $add(tmdbSearch($query, $page, 'en-US')['results'] ?? []);
    }

    // 3. Транслитерация всего запроса
    if (preg_match('/[а-яёА-ЯЁ]/u', $query)) {
        $translit = tmdbTranslit($query);
        $add(tmdbSearch($translit, 1, 'en-US')['results'] ?? []);
    }

    // 4. Поиск по каждому значимому слову отдельно (>= 4 символа)
    $words = array_filter(
        preg_split('/\s+/u', trim($query)),
        fn($w) => mb_strlen($w, 'UTF-8') >= 4
    );
    foreach ($words as $word) {
        if (mb_strtolower($word, 'UTF-8') === $queryLc) continue;
        $add(tmdbSearch($word, 1, $lang)['results'] ?? []);
        if ($lang !== 'en-US') {
            $add(tmdbSearch($word, 1, 'en-US')['results'] ?? []);
        }
        // транслитерация отдельного слова
        if (preg_match('/[а-яёА-ЯЁ]/u', $word)) {
            $add(tmdbSearch(tmdbTranslit($word), 1, 'en-US')['results'] ?? []);
        }
    }

    // сортируем по схожести: чем выше — тем лучше совпадение
    usort($results, fn($a, $b) => ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));

    return $results;
}

function tmdbGetMovie(int $tmdbId, string $lang = 'en-US'): ?array {
    $url = "https://api.themoviedb.org/3/movie/{$tmdbId}?language={$lang}&append_to_response=credits";
    return tmdbCached($url);
}

function tmdbGetTrailer(int $tmdbId): ?string {
    $url  = "https://api.themoviedb.org/3/movie/{$tmdbId}/videos?language=en-US";
    $data = tmdbCached($url);
    if (!$data || empty($data['results'])) return null;
    // prefer official YouTube trailer
    foreach ($data['results'] as $v) {
        if ($v['site'] === 'YouTube' && $v['type'] === 'Trailer' && ($v['official'] ?? false)) {
            return $v['key'];
        }
    }
    // fallback: any YouTube trailer
    foreach ($data['results'] as $v) {
        if ($v['site'] === 'YouTube' && $v['type'] === 'Trailer') {
            return $v['key'];
        }
    }
    // fallback: any YouTube video
    foreach ($data['results'] as $v) {
        if ($v['site'] === 'YouTube') {
            return $v['key'];
        }
    }
    return null;
}

function tmdbDirector(array $movie): string {
    foreach ($movie['credits']['crew'] ?? [] as $p) {
        if ($p['job'] === 'Director') return $p['name'];
    }
    return '';
}

function tmdbGenresString(array $movie): string {
    return implode(', ', array_column($movie['genres'] ?? [], 'name'));
}

function tmdbGetGenresMap(string $lang = 'en-US'): array {
    $url  = "https://api.themoviedb.org/3/genre/movie/list?language={$lang}";
    $data = tmdbCached($url);
    if (!$data || empty($data['genres'])) return [];
    $map  = [];
    foreach ($data['genres'] as $g) $map[$g['id']] = $g['name'];
    return $map;
}

// ─── Legacy functions (used by admin/import_movies.php) ──────────────────────

function getGenresMap(): array {
    return tmdbGetGenresMap('en-US');
}

function getDirectorFromTMDB($movieId): string {
    $url  = "https://api.themoviedb.org/3/movie/{$movieId}/credits?language=en-US";
    $data = tmdbRequest($url);
    if (!$data || empty($data['crew'])) return 'Unknown';
    foreach ($data['crew'] as $p) {
        if ($p['job'] === 'Director') return $p['name'];
    }
    return 'Unknown';
}

function getMovieFromTMDB(string $title): ?array {
    $url  = "https://api.themoviedb.org/3/search/movie?query=" . urlencode($title) . "&language=en-US";
    $data = tmdbRequest($url);
    if (!$data || empty($data['results'][0])) return null;
    $movie  = $data['results'][0];
    $map    = getGenresMap();
    $genres = [];
    foreach ($movie['genre_ids'] ?? [] as $id) {
        if (isset($map[$id])) $genres[] = $map[$id];
    }
    $movie['genres_string'] = implode(', ', $genres);
    $movie['director_name'] = getDirectorFromTMDB($movie['id']);
    return $movie;
}
