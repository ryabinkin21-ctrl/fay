<?php
require 'includes/auth.php';
require 'includes/db.php';
require 'includes/tmdb.php';
require 'includes/header.php';

// Load current user's TV wishlist (scoped to series so movie ids don't collide)
$userWishlist = [];
if (isset($_SESSION['user_id'])) {
    $ws = $pdo->prepare("SELECT tmdb_id FROM wishlist WHERE user_id = ? AND media_type = 'tv'");
    $ws->execute([$_SESSION['user_id']]);
    $userWishlist = array_column($ws->fetchAll(), 'tmdb_id');
}

// ── Carousel: top popular series, excluding ones the user already reviewed ──
$carouselSeries = [];
{
    $carLang = tmdbLang($currentLang);
    $carFilter = fn($m) =>
        !empty($m['poster_path'])
        && ($m['vote_average'] ?? 0) > 0
        && tmdbHasLatinOrCyrillic(['original_language' => $m['original_language'] ?? '', 'title' => $m['name'] ?? '']);

    $reviewedTvIds = [];
    if (isset($_SESSION['user_id'])) {
        $rStmt = $pdo->prepare("
            SELECT m.tmdb_id FROM reviews r
            JOIN movies m ON r.movie_id = m.id
            WHERE r.user_id = ? AND m.tmdb_id IS NOT NULL AND m.media_type = 'tv'
        ");
        $rStmt->execute([$_SESSION['user_id']]);
        $reviewedTvIds = array_column($rStmt->fetchAll(), 'tmdb_id');
    }

    for ($cp = 1; $cp <= 2 && count($carouselSeries) < 20; $cp++) {
        $cData = tmdbGetDiscoverTv('popularity.desc', $cp, $carLang, 0);
        foreach (array_values(array_filter($cData['results'] ?? [], $carFilter)) as $m) {
            if (in_array($m['id'], $reviewedTvIds)) continue;
            $carouselSeries[] = $m;
            if (count($carouselSeries) >= 20) break;
        }
    }
}

$search = trim($_GET['search'] ?? '');
$sort   = $_GET['sort']  ?? 'popularity';
$genre  = (int)($_GET['genre'] ?? 0);
$page   = max(1, min(100, (int)($_GET['page'] ?? 1)));

$tmdbLangCode = tmdbLang($currentLang);

// TMDB /discover/tv sort fields differ from movies
$sortMap = [
    'popularity' => 'popularity.desc',
    'rating'     => 'vote_average.desc',
    'year'       => 'first_air_date.desc',
];
$tmdbSort = $sortMap[$sort] ?? 'popularity.desc';

$genresMap = tmdbGetTvGenresMap($tmdbLangCode);

// keep only series with a poster, a score, and a Latin/Cyrillic title
$onlyCyrLat = fn($m) =>
    !empty($m['poster_path'])
    && ($m['vote_average'] ?? 0) > 0
    && tmdbHasLatinOrCyrillic(['original_language' => $m['original_language'] ?? '', 'title' => $m['name'] ?? '']);

if (!empty($search)) {
    $items = array_values(array_filter(
        tmdbSearchTvFuzzy($search, $page, $tmdbLangCode),
        fn($m) => $onlyCyrLat($m) && (!$genre || in_array($genre, $m['genre_ids'] ?? []))
    ));
    $totalPages = 1;
} else {
    $target     = 20;
    $skip       = ($page - 1) * $target;
    $items      = [];
    $seenIds    = [];
    $fetchPage  = 1;
    $totalPages = 1;
    $counted    = 0;

    while (count($items) < $target) {
        $data = tmdbGetDiscoverTv($tmdbSort, $fetchPage, $tmdbLangCode, $genre);
        if (!$data) break;
        $totalPages = min((int)($data['total_pages'] ?? 1), 500);
        $filtered   = array_values(array_filter($data['results'] ?? [], $onlyCyrLat));

        foreach ($filtered as $m) {
            if (isset($seenIds[$m['id']])) continue;
            $seenIds[$m['id']] = true;
            if ($counted < $skip) { $counted++; continue; }
            $items[] = $m;
            if (count($items) >= $target) break;
        }

        if ($fetchPage >= $totalPages || count($items) >= $target || $fetchPage >= $page + 15) break;
        $fetchPage++;
    }
}
?>

<?php if (!empty($carouselSeries)): ?>
<section class="hero">
    <div class="hero-content">
        <p><?php echo tr('series_hero_tag'); ?></p>
        <h1><?php echo t('series_hero_h1a'); ?><br><span><?php echo t('series_hero_h1b'); ?></span></h1>
        <span><?php echo tr('series_hero_sub'); ?></span>
    </div>

    <div class="hero-carousel" id="heroCarousel">
        <div class="hc-stage" id="hcStage">
            <?php foreach ($carouselSeries as $cm): ?>
                <?php
                    $cmPoster = 'https://image.tmdb.org/t/p/w300' . $cm['poster_path'];
                    $cmTitle  = htmlspecialchars($cm['name'] ?? '');
                    $cmRating = number_format((float)($cm['vote_average'] ?? 0), 1);
                    $cmUrl    = $base . '/movie.php?tmdb_id=' . (int)$cm['id'] . '&type=tv';
                ?>
                <a class="hc-card" href="<?php echo $cmUrl; ?>" data-title="<?php echo $cmTitle; ?>">
                    <img src="<?php echo htmlspecialchars($cmPoster); ?>" alt="<?php echo $cmTitle; ?>" loading="lazy">
                    <div class="hc-info">
                        <span class="hc-title"><?php echo $cmTitle; ?></span>
                        <span class="hc-rating">&#9733; <?php echo $cmRating; ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section id="series" class="section">

    <div class="section-title">
        <h2><?php echo t('series_heading'); ?></h2>
        <div></div>
    </div>

    <form method="GET" class="search-form" autocomplete="off">
        <div class="search-wrap">
            <input
                id="searchInput"
                type="text"
                name="search"
                placeholder="<?php echo htmlspecialchars(t('search_series_ph')); ?>"
                value="<?php echo htmlspecialchars($search); ?>"
            >
            <div class="suggest-list" id="suggestList"></div>
        </div>
        <select name="genre">
            <option value="0"><?php echo t('all_genres'); ?></option>
            <?php foreach ($genresMap as $gId => $gName): ?>
                <option value="<?php echo (int)$gId; ?>" <?php if ($genre === (int)$gId) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($gName); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="sort">
            <option value="popularity" <?php if ($sort === 'popularity') echo 'selected'; ?>><?php echo t('sort_popular'); ?></option>
            <option value="rating"     <?php if ($sort === 'rating')     echo 'selected'; ?>><?php echo t('sort_rating');  ?></option>
            <option value="year"       <?php if ($sort === 'year')       echo 'selected'; ?>><?php echo t('sort_year');    ?></option>
        </select>
        <button type="submit"><?php echo t('search_btn'); ?></button>
    </form>

    <div class="movie-grid">
        <?php if (!empty($items)): ?>
            <?php foreach ($items as $m): ?>
                <?php
                    $poster = !empty($m['poster_path'])
                        ? 'https://image.tmdb.org/t/p/w300' . $m['poster_path']
                        : '';
                    $year   = !empty($m['first_air_date']) ? substr($m['first_air_date'], 0, 4) : '';
                    $rating = number_format((float)($m['vote_average'] ?? 0), 1);
                    $title  = $m['name'] ?? '';
                    $inWish = in_array($m['id'], $userWishlist);
                ?>
                <div class="movie-card">
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <button class="wish-btn<?php echo $inWish ? ' active' : ''; ?>"
                        data-tmdb="<?php echo (int)$m['id']; ?>"
                        data-type="tv"
                        data-title="<?php echo htmlspecialchars($title, ENT_QUOTES); ?>"
                        data-poster="<?php echo htmlspecialchars($poster, ENT_QUOTES); ?>"
                        data-year="<?php echo htmlspecialchars($year, ENT_QUOTES); ?>"
                        title="<?php echo $inWish ? t('wishlist_added') : t('wishlist_add'); ?>">
                        <?php echo $inWish ? '★' : '☆'; ?>
                    </button>
                    <?php endif; ?>
                    <a href="<?php echo $base; ?>/movie.php?tmdb_id=<?php echo (int)$m['id']; ?>&type=tv">
                        <?php if ($poster): ?>
                            <img src="<?php echo htmlspecialchars($poster); ?>" alt="Poster">
                        <?php else: ?>
                            <div class="poster-placeholder">No Poster</div>
                        <?php endif; ?>
                        <div class="movie-info">
                            <h3><?php echo htmlspecialchars($title); ?></h3>
                            <div class="movie-meta">
                                <span><?php echo htmlspecialchars($year); ?></span>
                                <span>&#9733; <?php echo $rating; ?></span>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p><?php echo t('no_series'); ?></p>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <?php
        $qs = fn($p) => '?search=' . urlencode($search) . '&genre=' . $genre . '&sort=' . urlencode($sort) . '&page=' . $p;
        $window = 2;
        $pages = [];
        for ($p2 = 1; $p2 <= $totalPages; $p2++) {
            if ($p2 === 1 || $p2 === $totalPages || abs($p2 - $page) <= $window) {
                $pages[] = $p2;
            }
        }
    ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a class="page-btn page-arrow" href="<?php echo $qs($page - 1); ?>">&#8592;</a>
        <?php endif; ?>

        <?php $prev2 = null; foreach ($pages as $p2):
            if ($prev2 !== null && $p2 - $prev2 > 1): ?>
                <span class="page-ellipsis">…</span>
            <?php endif; ?>
            <a class="page-btn<?php echo $p2 === $page ? ' active' : ''; ?>"
               href="<?php echo $qs($p2); ?>"><?php echo $p2; ?></a>
        <?php $prev2 = $p2; endforeach; ?>

        <?php if ($page < $totalPages): ?>
            <a class="page-btn page-arrow" href="<?php echo $qs($page + 1); ?>">&#8594;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</section>

<script>
(function () {
    const input = document.getElementById('searchInput');
    const list  = document.getElementById('suggestList');
    const base  = '<?php echo $base; ?>';
    let timer, lastQ = '';

    input.addEventListener('input', function () {
        clearTimeout(timer);
        const q = this.value.trim();
        if (q === lastQ) return;
        lastQ = q;
        if (q.length < 2) { close(); return; }
        timer = setTimeout(() => fetchSuggest(q), 300);
    });

    input.addEventListener('focus', function () {
        if (list.children.length) list.classList.add('open');
    });

    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !list.contains(e.target)) close();
    });

    function close() { list.classList.remove('open'); }

    function fetchSuggest(q) {
        window.fetch(base + '/search_suggest.php?type=tv&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.length) { close(); list.innerHTML = ''; return; }
                list.innerHTML = data.map(m => `
                    <div class="suggest-item" data-id="${m.id}">
                        ${m.poster
                            ? `<img class="suggest-poster" src="${m.poster}" alt="">`
                            : `<div class="suggest-no-poster"></div>`}
                        <div>
                            <div class="suggest-title">${esc(m.title)}</div>
                            <div class="suggest-year">${m.year}</div>
                        </div>
                    </div>
                `).join('');
                list.classList.add('open');
                list.querySelectorAll('.suggest-item').forEach(item => {
                    item.addEventListener('click', function () {
                        window.location.href = base + '/movie.php?tmdb_id=' + this.dataset.id + '&type=tv';
                    });
                });
            })
            .catch(() => {});
    }

    function esc(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>

<?php if (isset($_SESSION['user_id'])): ?>
<script>
document.querySelectorAll('.wish-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault(); e.stopPropagation();
        const b = this;
        fetch('<?php echo $base; ?>/wishlist.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                tmdb_id:    b.dataset.tmdb,
                media_type: b.dataset.type,
                title:      b.dataset.title,
                poster:     b.dataset.poster,
                year:       b.dataset.year
            })
        })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'added') {
                b.classList.add('active');
                b.textContent = '★';
            } else if (d.status === 'removed') {
                b.classList.remove('active');
                b.textContent = '☆';
            }
        });
    });
});
</script>
<?php endif; ?>

<?php if (!empty($carouselSeries)): ?>
<script src="<?php echo $base; ?>/assets/js/carousel.js" defer></script>
<?php endif; ?>

</main></div></body></html>
