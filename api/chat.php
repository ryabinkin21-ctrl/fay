<?php
/* ── FAY · AI movie-recommendation endpoint ──────────────────────
   POST JSON: { "message": "...", "history": [ {role, content}, ... ] }
   Returns  : { "comment": "...", "movies": [ {title, year, poster_url,
                                              rating, genre, reason,
                                              tmdb_id}, ... ] }
              or { "error": "..." }
──────────────────────────────────────────────────────────────── */

/* keep the response a clean JSON document no matter what */
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

/* ── helper: emit JSON + exit ─────────────────────────────────── */
function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* convert any uncaught fatal error into a JSON error (never raw HTML) */
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'Server error: ' . $err['message']], JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/../includes/session_init.php';
/* NB: we do NOT require includes/db.php here — it die()s on a failed connection,
   which would corrupt the JSON response. We connect defensively below instead,
   so the chatbot still answers (without personalisation) if the DB is down. */

/* ── method + auth gate ───────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Method not allowed'], 405);
}

if (!isset($_SESSION['user_id'])) {
    json_out(['error' => 'Not authenticated'], 401);
}

/* ── load config from .env (same loader as db.php) ────────────── */
$env    = parse_ini_file(__DIR__ . '/../.env');
$apiKey = $env['ANTHROPIC_API_KEY'] ?? '';

if ($apiKey === '') {
    json_out(['error' => 'AI service is not configured.'], 500);
}

/* preferred language for TMDB lookups (session > cookie > en) */
$lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'en');
$lang = in_array($lang, ['en', 'ru'], true) ? $lang : 'en';
$tmdbLang = $lang === 'ru' ? 'ru-RU' : 'en-US';

/* ── parse request body ───────────────────────────────────────── */
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    json_out(['error' => 'Invalid request body.'], 400);
}

$message = trim((string)($input['message'] ?? ''));
if ($message === '') {
    json_out(['error' => 'Message is empty.'], 400);
}

/* ── conversation log lives in the PHP session ───────────────────
   It persists across page reloads and is wiped on logout / when the
   browser session ends (see logout.php). Each entry is a rendered turn:
     { role:'user',      text:'...' }
     { role:'assistant', comment:'...', movies:[...] }                */
if (!isset($_SESSION['chat_log']) || !is_array($_SESSION['chat_log'])) {
    $_SESSION['chat_log'] = [];
}

/* rebuild the model's message list from the session log */
$messages = [];
foreach ($_SESSION['chat_log'] as $entry) {
    if (($entry['role'] ?? '') === 'user') {
        $text = (string)($entry['text'] ?? '');
        if ($text !== '') $messages[] = ['role' => 'user', 'content' => $text];
    } elseif (($entry['role'] ?? '') === 'assistant') {
        $comment = (string)($entry['comment'] ?? '');
        $titles  = [];
        foreach (($entry['movies'] ?? []) as $mv) {
            $t = (string)($mv['title'] ?? '');
            $y = (int)($mv['year'] ?? 0);
            if ($t !== '') $titles[] = $t . ($y ? " ({$y})" : '');
        }
        $content = $comment . ($titles ? "\n[рекомендовано: " . implode(', ', $titles) . ']' : '');
        if ($content !== '') $messages[] = ['role' => 'assistant', 'content' => $content];
    }
}
/* current user turn */
$messages[] = ['role' => 'user', 'content' => $message];

/* ── pull the user's reviewed titles & ratings from the DB ────── */
$watchHistory = '(пользователь ещё ничего не оценил)';
$reviewedKeys = [];   // set keyed "media_type:tmdb_id" of titles already reviewed
$pdo = null;
try {
    $pdo = new PDO(
        "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
        $env['DB_USER'],
        $env['DB_PASSWORD']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* make sure the media_type column exists (this endpoint bypasses db.php) */
    require_once __DIR__ . '/../includes/schema.php';
    fay_ensure_media_schema($pdo);

    $stmt = $pdo->prepare(
        "SELECT m.title, m.year, m.genre, m.tmdb_id, m.media_type, r.score
           FROM reviews r
           JOIN movies m ON r.movie_id = m.id
          WHERE r.user_id = ?
       ORDER BY r.score DESC"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lines = [];
    foreach ($rows as $row) {
        $title = $row['title'] ?? '';
        $year  = $row['year']  ?? '';
        $score = $row['score'] ?? '';
        $type  = ($row['media_type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
        $kind  = $type === 'tv' ? 'сериал' : 'фильм';
        $lines[] = "- {$title} ({$year}) [{$kind}] — оценка {$score}/10";
        if (!empty($row['tmdb_id'])) {
            $reviewedKeys[$type . ':' . (int)$row['tmdb_id']] = true;
        }
    }
    if ($lines) {
        $watchHistory = implode("\n", $lines);
    }
} catch (PDOException $e) {
    $watchHistory = '(история просмотров недоступна)';
}

/* ── system prompt (structured JSON card output) ──────────────── */
$systemPrompt = <<<SYS
Ты — AI-рекомендатель фильмов и сериалов для сайта FAY. Пользователь пишет запрос на русском или английском языке, описывая, что он хочет посмотреть.

Проанализируй запрос и историю оценок пользователя, и верни ОТВЕТ СТРОГО В ФОРМАТЕ JSON, без markdown-разметки, без ```, без преамбулы — только чистый JSON-объект следующей структуры:

{
  "comment": "короткое дружелюбное пояснение (1-2 предложения), почему подобраны именно эти тайтлы, на языке пользователя",
  "movies": [
    {
      "title": "название (на языке пользователя или оригинальное)",
      "type": "movie или series",
      "year": 2010,
      "rating": 7.8,
      "genre": "жанр",
      "reason": "короткая причина рекомендации (до 8-10 слов, без точки в конце)"
    }
  ]
}

Правила:
- Возвращай от 3 до 6 тайтлов.
- Можешь рекомендовать как фильмы (type: "movie"), так и сериалы (type: "series"). Если пользователь явно просит фильмы — давай только фильмы; если сериалы — только сериалы; иначе подбирай по смыслу запроса.
- Поле "type" ОБЯЗАТЕЛЬНО для каждого тайтла.
- Учитывай историю оценок: рекомендуй похожие по жанру/режиссёру/стилю. НИКОГДА не рекомендуй тайтлы, которые пользователь уже оценил (они в списке ниже). Отдавай приоритет стилю тайтлов с оценкой 7+, избегай похожих на тайтлы с оценкой 1-4.
- Поле "comment" — краткое, без перечисления названий внутри (они выводятся карточками отдельно).
- Поле "rating" не обязательно точное — сервер уточнит его по базе TMDB.
- НЕ добавляй poster_url — сервер сам подставит постеры.
- НЕ добавляй никакого текста до или после JSON. Только сам объект.

ИСТОРИЯ ОЦЕНОК ПОЛЬЗОВАТЕЛЯ:
{WATCH_HISTORY}
SYS;
$systemPrompt = str_replace('{WATCH_HISTORY}', $watchHistory, $systemPrompt);

/* ── call the Anthropic Messages API (raw HTTP, per spec) ─────── */
$payload = [
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => 1500,
    'system'     => $systemPrompt,
    'messages'   => $messages,
];

/* uses file_get_contents + stream context (same HTTP approach as includes/tmdb.php,
   so it works regardless of whether the cURL extension is enabled) */
$context = stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => "x-api-key: {$apiKey}\r\n" .
                           "anthropic-version: 2023-06-01\r\n" .
                           "content-type: application/json\r\n",
        'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'timeout'       => 60,
        'ignore_errors' => true,   // return the body even on 4xx/5xx (so we can read the API error)
    ],
]);

$response = @file_get_contents('https://api.anthropic.com/v1/messages', false, $context);

if ($response === false) {
    json_out(['error' => 'Failed to reach AI service.'], 502);
}

/* derive HTTP status from the response headers */
$httpCode = 0;
if (isset($http_response_header[0]) &&
    preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
    $httpCode = (int)$m[1];
}

$data = json_decode($response, true);

if ($httpCode !== 200 || !is_array($data)) {
    $msg = $data['error']['message'] ?? 'AI service returned an error.';
    json_out(['error' => $msg], 502);
}

/* extract the text from the content blocks */
$reply = '';
foreach ($data['content'] ?? [] as $block) {
    if (($block['type'] ?? '') === 'text') {
        $reply .= $block['text'];
    }
}

if ($reply === '') {
    json_out(['error' => 'Empty response from AI service.'], 502);
}

/* ── parse the model's JSON (tolerate stray prose / ``` fences) ── */
$parsed = decode_model_json($reply);
if (!is_array($parsed) || !isset($parsed['movies']) || !is_array($parsed['movies'])) {
    /* graceful fallback: show the raw text as a comment, no cards */
    $_SESSION['chat_log'][] = ['role' => 'user', 'text' => $message];
    $_SESSION['chat_log'][] = ['role' => 'assistant', 'comment' => $reply, 'movies' => []];
    json_out(['comment' => $reply, 'movies' => []]);
}

/* ── enrich each movie with a real TMDB poster / rating / id ───── */
$movies = [];
$enrich = ($pdo !== null);   // tmdbCached() needs a working $pdo
if ($enrich) {
    require_once __DIR__ . '/../includes/tmdb.php';
}

foreach ($parsed['movies'] as $mv) {
    if (!is_array($mv)) continue;
    $rawType = strtolower((string)($mv['type'] ?? 'movie'));
    $type    = ($rawType === 'series' || $rawType === 'tv' || $rawType === 'сериал') ? 'tv' : 'movie';
    $movie = [
        'title'      => (string)($mv['title'] ?? ''),
        'media_type' => $type,
        'year'       => (int)($mv['year'] ?? 0),
        'poster_url' => '',
        'rating'     => isset($mv['rating']) ? (float)$mv['rating'] : 0.0,
        'genre'      => (string)($mv['genre'] ?? ''),
        'reason'     => (string)($mv['reason'] ?? ''),
        'tmdb_id'    => 0,
    ];
    if ($movie['title'] === '') continue;

    if ($enrich) {
        $movie = tmdb_enrich($movie, $tmdbLang);
    }

    /* hard filter: never recommend a title the user has already reviewed */
    if ($movie['tmdb_id'] && isset($reviewedKeys[$movie['media_type'] . ':' . $movie['tmdb_id']])) {
        continue;
    }
    $movies[] = $movie;
}

$comment = (string)($parsed['comment'] ?? '');

/* persist this turn so it survives reloads (cleared on logout) */
$_SESSION['chat_log'][] = ['role' => 'user', 'text' => $message];
$_SESSION['chat_log'][] = ['role' => 'assistant', 'comment' => $comment, 'movies' => $movies];

json_out([
    'comment' => $comment,
    'movies'  => $movies,
]);


/* ── helpers ──────────────────────────────────────────────────── */

/* Decode the model's reply into an array, tolerating ```json fences
   or a stray sentence wrapped around the object. */
function decode_model_json(string $text): ?array {
    $text = trim($text);

    // strip a leading/trailing ``` fence if present
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) return $decoded;

    // last resort: grab the outermost {...} block
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (is_array($decoded)) return $decoded;
    }
    return null;
}

/* Look up a title on TMDB by name (+year) and fill in the real
   poster_url, rating, canonical title and tmdb_id. Handles both
   movies (/search/movie) and series (/search/tv), which use
   different field names (title/release_date vs name/first_air_date). */
function tmdb_enrich(array $movie, string $tmdbLang): array {
    $isTv      = ($movie['media_type'] ?? 'movie') === 'tv';
    $nameKey   = $isTv ? 'name'           : 'title';
    $dateKey   = $isTv ? 'first_air_date' : 'release_date';
    $search    = fn(string $q, string $lang) => $isTv
        ? (tmdbSearchTv($q, 1, $lang)['results'] ?? [])
        : (tmdbSearch($q, 1, $lang)['results'] ?? []);

    $results = $search($movie['title'], $tmdbLang);
    if (!$results && $tmdbLang !== 'en-US') {
        $results = $search($movie['title'], 'en-US');
    }
    if (!$results) return $movie;

    $year = $movie['year'];
    $best = null;
    foreach ($results as $r) {
        if (empty($r['poster_path'])) continue;
        $ry = (int)substr($r[$dateKey] ?? '', 0, 4);
        if ($year && $ry && abs($ry - $year) <= 1) { $best = $r; break; }
        if ($best === null) $best = $r;   // first poster-bearing result as fallback
    }
    if ($best === null) $best = $results[0];

    if (!empty($best['poster_path'])) {
        $movie['poster_url'] = 'https://image.tmdb.org/t/p/w300' . $best['poster_path'];
    }
    if (isset($best['vote_average']) && (float)$best['vote_average'] > 0) {
        $movie['rating'] = round((float)$best['vote_average'], 1);
    }
    if (!empty($best[$nameKey])) $movie['title']   = $best[$nameKey];
    if (!empty($best[$dateKey])) $movie['year']    = (int)substr($best[$dateKey], 0, 4);
    if (!empty($best['id']))     $movie['tmdb_id'] = (int)$best['id'];

    return $movie;
}
