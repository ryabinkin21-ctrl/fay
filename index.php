<?php
require 'includes/auth.php';
require 'includes/db.php';
require 'includes/tmdb.php';
require 'includes/header.php';

// ── Carousel: top-20 popular, excluding already-reviewed by current user ──────
$carouselMovies = [];
{
    $tmdbLangCodeCarousel = tmdbLang($currentLang);
    $popularFilter = fn($m) => !empty($m['poster_path']) && tmdbHasLatinOrCyrillic($m) && ($m['vote_average'] ?? 0) > 0;
    $data = tmdbGetDiscover('popularity.desc', 1, $tmdbLangCodeCarousel, 0);
    $allPopular = array_values(array_filter($data['results'] ?? [], $popularFilter));

    $reviewedTmdbIds = [];
    if (isset($_SESSION['user_id'])) {
        $rStmt = $pdo->prepare("
            SELECT m.tmdb_id FROM reviews r
            JOIN movies m ON r.movie_id = m.id
            WHERE r.user_id = ? AND m.tmdb_id IS NOT NULL
        ");
        $rStmt->execute([$_SESSION['user_id']]);
        $reviewedTmdbIds = array_column($rStmt->fetchAll(), 'tmdb_id');
    }

    foreach ($allPopular as $m) {
        if (in_array($m['id'], $reviewedTmdbIds)) continue;
        $carouselMovies[] = $m;
        if (count($carouselMovies) >= 20) break;
    }
    // if not enough after filtering, fetch more pages
    if (count($carouselMovies) < 10) {
        $data2 = tmdbGetDiscover('popularity.desc', 2, $tmdbLangCodeCarousel, 0);
        foreach (array_values(array_filter($data2['results'] ?? [], $popularFilter)) as $m) {
            if (in_array($m['id'], $reviewedTmdbIds)) continue;
            $carouselMovies[] = $m;
            if (count($carouselMovies) >= 20) break;
        }
    }
}

// Создать таблицу если не существует
$pdo->exec("CREATE TABLE IF NOT EXISTS wishlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, tmdb_id INT NOT NULL,
    title VARCHAR(255) NOT NULL DEFAULT '', poster VARCHAR(500) NOT NULL DEFAULT '',
    year VARCHAR(4) NOT NULL DEFAULT '', added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_movie (user_id, tmdb_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Загрузить wishlist текущего пользователя
$userWishlist = [];
if (isset($_SESSION['user_id'])) {
    $ws = $pdo->prepare("SELECT tmdb_id FROM wishlist WHERE user_id = ?");
    $ws->execute([$_SESSION['user_id']]);
    $userWishlist = array_column($ws->fetchAll(), 'tmdb_id');
}

$search  = trim($_GET['search'] ?? '');
$sort    = $_GET['sort']    ?? 'popularity';
$genre   = (int)($_GET['genre'] ?? 0);
$page    = max(1, (int)($_GET['page'] ?? 1));

$tmdbLangCode = tmdbLang($currentLang);

$sortMap = [
    'popularity' => 'popularity.desc',
    'rating'     => 'vote_average.desc',
    'year'        => 'primary_release_date.desc',
    'title'      => 'original_title.asc',
];
$tmdbSort = $sortMap[$sort] ?? 'popularity.desc';

// Жанры с кешем
$genresMap = tmdbGetGenresMap($tmdbLangCode);

$onlyCyrLat = fn($m) =>
    !empty($m['poster_path'])
    && tmdbHasLatinOrCyrillic($m)
    && ($m['vote_average'] ?? 0) > 0;

if (!empty($search)) {
    $movies = array_values(array_filter(
        tmdbSearchFuzzy($search, $page, $tmdbLangCode),
        fn($m) => $onlyCyrLat($m) && (!$genre || in_array($genre, $m['genre_ids'] ?? []))
    ));
    $totalPages = 1;
} else {
    $target     = 20;
    $skip       = ($page - 1) * $target;
    $movies     = [];
    $seenIds    = [];
    $fetchPage  = 1;
    $totalPages = 1;
    $counted    = 0;

    while (count($movies) < $target) {
        $data = tmdbGetDiscover($tmdbSort, $fetchPage, $tmdbLangCode, $genre);
        if (!$data) break;
        $totalPages = min((int)($data['total_pages'] ?? 1), 500);
        $filtered   = array_values(array_filter($data['results'] ?? [], $onlyCyrLat));

        foreach ($filtered as $m) {
            if (isset($seenIds[$m['id']])) continue;
            $seenIds[$m['id']] = true;
            if ($counted < $skip) { $counted++; continue; }
            $movies[] = $m;
            if (count($movies) >= $target) break;
        }

        if ($fetchPage >= $totalPages || count($movies) >= $target) break;
        $fetchPage++;
    }
}
?>

<section class="hero">
    <div class="hero-content">
        <p><?php echo tr('hero_tagline'); ?></p>
        <h1><?php echo t('hero_h1a'); ?><br><span><?php echo t('hero_h1b'); ?></span></h1>
        <span><?php echo tr('hero_sub'); ?></span>
    </div>

    <?php if (!empty($carouselMovies)): ?>
    <div class="hero-carousel" id="heroCarousel">
        <div class="hc-stage" id="hcStage">
            <?php foreach ($carouselMovies as $cm): ?>
                <?php
                    $cmPoster = 'https://image.tmdb.org/t/p/w300' . $cm['poster_path'];
                    $cmTitle  = htmlspecialchars($cm['title']);
                    $cmRating = number_format((float)($cm['vote_average'] ?? 0), 1);
                    $cmUrl    = $base . '/movie.php?tmdb_id=' . (int)$cm['id'];
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
    <?php endif; ?>
</section>

<section id="movies" class="section">

    <div class="section-title">
        <h2><?php echo t('now_playing'); ?></h2>
        <div></div>
    </div>

    <form method="GET" class="search-form" autocomplete="off">
        <div class="search-wrap">
            <input
                id="searchInput"
                type="text"
                name="search"
                placeholder="<?php echo htmlspecialchars(t('search_ph')); ?>"
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
            <option value="title"      <?php if ($sort === 'title')      echo 'selected'; ?>><?php echo t('sort_title');   ?></option>
        </select>
        <button type="submit"><?php echo t('search_btn'); ?></button>
    </form>

    <div class="movie-grid">
        <?php if (!empty($movies)): ?>
            <?php foreach ($movies as $m): ?>
                <?php
                    $poster = !empty($m['poster_path'])
                        ? 'https://image.tmdb.org/t/p/w300' . $m['poster_path']
                        : '';
                    $year   = !empty($m['release_date']) ? substr($m['release_date'], 0, 4) : '';
                    $rating = number_format((float)($m['vote_average'] ?? 0), 1);
                ?>
                <?php
                    $inWish   = in_array($m['id'], $userWishlist);
                    $wishClass = $inWish ? ' active' : '';
                ?>
                <div class="movie-card">
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <button class="wish-btn<?php echo $wishClass; ?>"
                        data-tmdb="<?php echo (int)$m['id']; ?>"
                        data-title="<?php echo htmlspecialchars($m['title'], ENT_QUOTES); ?>"
                        data-poster="<?php echo htmlspecialchars($poster, ENT_QUOTES); ?>"
                        data-year="<?php echo htmlspecialchars($year, ENT_QUOTES); ?>"
                        title="<?php echo $inWish ? t('wishlist_added') : t('wishlist_add'); ?>">
                        <?php echo $inWish ? '★' : '☆'; ?>
                    </button>
                    <?php endif; ?>
                    <a href="<?php echo $base; ?>/movie.php?tmdb_id=<?php echo (int)$m['id']; ?>">
                        <?php if ($poster): ?>
                            <img src="<?php echo htmlspecialchars($poster); ?>" alt="Poster">
                        <?php else: ?>
                            <div class="poster-placeholder">No Poster</div>
                        <?php endif; ?>
                        <div class="movie-info">
                            <h3><?php echo htmlspecialchars($m['title']); ?></h3>
                            <div class="movie-meta">
                                <span><?php echo htmlspecialchars($year); ?></span>
                                <span>&#9733; <?php echo $rating; ?></span>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p><?php echo t('no_movies'); ?></p>
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
        timer = setTimeout(() => fetch(q), 300);
    });

    input.addEventListener('focus', function () {
        if (list.children.length) list.classList.add('open');
    });

    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !list.contains(e.target)) close();
    });

    function close() { list.classList.remove('open'); }

    function fetch(q) {
        window.fetch(base + '/search_suggest.php?q=' + encodeURIComponent(q))
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
                        window.location.href = base + '/movie.php?tmdb_id=' + this.dataset.id;
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
                tmdb_id: b.dataset.tmdb,
                title:   b.dataset.title,
                poster:  b.dataset.poster,
                year:    b.dataset.year
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

<?php if (!empty($carouselMovies)): ?>
<script>
(function () {
    const carousel = document.getElementById('heroCarousel');
    if (!carousel) return;

    const stage = document.getElementById('hcStage');
    const cards = Array.from(stage.querySelectorAll('.hc-card'));
    const total = cards.length;
    if (total < 2) return;

    let current = 0;
    let timer   = null;

    function apply() {
        cards.forEach((card, i) => {
            let offset = i - current;
            // wrap around for infinite loop
            if (offset >  total / 2) offset -= total;
            if (offset < -total / 2) offset += total;

            const abs = Math.abs(offset);
            let scale, rotY, z, opacity, blur;

            // card width=320px; translateX chosen so edges don't overlap:
            // center half=160px; ±1 half=320*0.70/2=112px → tx≥160+112+12=284, use 295
            // ±2 half=320*0.50/2=80px → tx≥295+112+80+12=499, use 515 (clips at container edge)
            const TX = [0, 295, 515, 640];
            let tx, sign = offset >= 0 ? 1 : -1;

            if (abs === 0) {
                scale = 1;    rotY = 0;              z = 40; opacity = 1;    blur = 0;
            } else if (abs === 1) {
                scale = 0.70; rotY = sign * 40;      z = 30; opacity = 0.88; blur = 0;
            } else if (abs === 2) {
                scale = 0.50; rotY = sign * 55;      z = 20; opacity = 0.45; blur = 1;
            } else {
                scale = 0.38; rotY = sign * 65;      z = 5;  opacity = 0;    blur = 3;
            }
            tx = sign * (TX[Math.min(abs, 3)]);

            card.style.transform  = `translateX(${tx}px) scale(${scale}) rotateY(${rotY}deg)`;
            card.style.zIndex     = z;
            card.style.opacity    = opacity;
            card.style.filter     = blur ? `blur(${blur}px)` : '';
            card.style.pointerEvents = abs <= 2 ? 'auto' : 'none';
        });
    }

    function next() {
        current = (current + 1) % total;
        apply();
    }

    function startTimer() { clearInterval(timer); timer = setInterval(next, 6000); }
    function stopTimer()  { clearInterval(timer); timer = null; }

    apply();
    startTimer();

    carousel.addEventListener('mouseenter', stopTimer);
    carousel.addEventListener('mouseleave', startTimer);

    // click on non-center card → jump to it
    cards.forEach((card, i) => {
        card.addEventListener('click', function (e) {
            const offset = ((i - current % total) + total) % total;
            const wrapped = offset > total / 2 ? offset - total : offset;
            if (wrapped !== 0) {
                e.preventDefault();
                stopTimer();
                current = i;
                apply();
                startTimer();
            }
        });
    });
})();
</script>
<?php endif; ?>

</main></div></body></html>
