<?php
require 'includes/auth.php';
require 'includes/db.php';
require_once 'includes/lang.php';
require 'includes/tmdb.php';

$message = '';
$mode    = 'local';

if (isset($_GET['tmdb_id'])) {
    $mode   = 'tmdb';
    $tmdbId = (int)$_GET['tmdb_id'];
    if (!$tmdbId) die('Movie not found');

    $tmdbLangCode = tmdbLang($currentLang);
    $tmdbData     = tmdbGetMovie($tmdbId, $tmdbLangCode);
    if (!$tmdbData) die('Movie not found');

    $movie = [
        'tmdb_id'     => $tmdbId,
        'id'          => null,
        'title'       => $tmdbData['title'],
        'year'        => !empty($tmdbData['release_date']) ? substr($tmdbData['release_date'], 0, 4) : '',
        'genre'       => tmdbGenresString($tmdbData),
        'director'    => tmdbDirector($tmdbData),
        'description' => $tmdbData['overview'] ?? '',
        'poster'      => !empty($tmdbData['poster_path'])
                            ? 'https://image.tmdb.org/t/p/w500' . $tmdbData['poster_path']
                            : '',
        'rating'      => null,
        'tmdb_vote'   => number_format((float)($tmdbData['vote_average'] ?? 0), 1),
    ];

    $localStmt  = $pdo->prepare("SELECT * FROM movies WHERE tmdb_id = ?");
    $localStmt->execute([$tmdbId]);
    $localMovie   = $localStmt->fetch();
    $localMovieId = $localMovie ? (int)$localMovie['id'] : null;
    if ($localMovie) $movie['rating'] = $localMovie['rating'];

} elseif (isset($_GET['id'])) {
    $localMovieId = (int)$_GET['id'];
    if (!$localMovieId) die('Movie not found');
    $stmt = $pdo->prepare("SELECT * FROM movies WHERE id = ?");
    $stmt->execute([$localMovieId]);
    $movie = $stmt->fetch();
    if (!$movie) die('Movie not found');
    $movie        = (array)$movie;
    $localMovieId = (int)$movie['id'];
} else {
    die('Movie not found');
}

// POST: submit review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $score       = (int)($_POST['score'] ?? 0);
    $review_text = trim($_POST['review_text'] ?? '');
    $user_id     = (int)$_SESSION['user_id'];

    if ($score < 1 || $score > 10) {
        $message = t('pick_first');
    } else {
        // For TMDB movies: auto-create local record on first review
        if ($mode === 'tmdb' && !$localMovieId) {
            $enData = tmdbGetMovie($tmdbId, 'en-US');
            $pdo->prepare("
                INSERT INTO movies (tmdb_id, title, year, genre, director, description, poster)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $tmdbId,
                $enData['title']    ?? $movie['title'],
                $movie['year'],
                tmdbGenresString($enData ?? $tmdbData),
                tmdbDirector($enData ?? $tmdbData),
                $enData['overview'] ?? $movie['description'],
                $movie['poster'],
            ]);
            $localMovieId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("INSERT INTO reviews (movie_id, user_id, review_text, score) VALUES (?,?,?,?)")
            ->execute([$localMovieId, $user_id, $review_text, $score]);

        $avg = $pdo->prepare("SELECT AVG(score) AS a FROM reviews WHERE movie_id = ?");
        $avg->execute([$localMovieId]);
        $pdo->prepare("UPDATE movies SET rating = ? WHERE id = ?")
            ->execute([round($avg->fetch()['a'], 1), $localMovieId]);

        $location = $mode === 'tmdb' ? "movie.php?tmdb_id={$tmdbId}" : "movie.php?id={$localMovieId}";
        header("Location: $location");
        exit;
    }
}

// Check wishlist
$inWishlist = false;
$wTmdbId    = $mode === 'tmdb' ? $tmdbId : (int)($movie['tmdb_id'] ?? 0);
if (isset($_SESSION['user_id']) && $wTmdbId) {
    $wCheck = $pdo->prepare("SELECT id FROM wishlist WHERE user_id = ? AND tmdb_id = ?");
    $wCheck->execute([(int)$_SESSION['user_id'], $wTmdbId]);
    $inWishlist = (bool)$wCheck->fetch();
}

// Fetch trailer
$trailerKey = null;
$trailerTmdbId = null;
if ($mode === 'tmdb') {
    $trailerTmdbId = $tmdbId;
} elseif (!empty($movie['tmdb_id'])) {
    $trailerTmdbId = (int)$movie['tmdb_id'];
}
if ($trailerTmdbId) {
    $trailerKey = tmdbGetTrailer($trailerTmdbId);
}

// Fetch reviews from local DB
$reviews = [];
if ($localMovieId) {
    $revStmt = $pdo->prepare("
        SELECT reviews.*, users.username
        FROM reviews JOIN users ON reviews.user_id = users.id
        WHERE reviews.movie_id = ?
        ORDER BY reviews.created_at DESC
    ");
    $revStmt->execute([$localMovieId]);
    $reviews = $revStmt->fetchAll();
}

require 'includes/header.php';
?>

<section class="movie-detail">
    <div class="breadcrumbs">
        <a href="<?php echo $base; ?>/index.php"><?php echo t('home'); ?></a> ›
        <a href="<?php echo $base; ?>/index.php"><?php echo t('nav_movies'); ?></a> ›
        <?php echo htmlspecialchars($movie['title']); ?>
    </div>

    <div class="detail-wrapper<?php echo $trailerKey ? ' has-trailer' : ''; ?>">
        <div class="detail-poster" style="position:relative;">
            <?php if (!empty($movie['poster'])): ?>
                <img src="<?php echo htmlspecialchars($movie['poster']); ?>" alt="Poster">
            <?php else: ?>
                <div class="poster-placeholder large">No Poster</div>
            <?php endif; ?>
            <?php if (isset($_SESSION['user_id']) && $wTmdbId): ?>
            <button class="wish-btn<?php echo $inWishlist ? ' active' : ''; ?>"
                id="wishBtn"
                data-tmdb="<?php echo $wTmdbId; ?>"
                data-title="<?php echo htmlspecialchars($movie['title'], ENT_QUOTES); ?>"
                data-poster="<?php echo htmlspecialchars($movie['poster'] ?? '', ENT_QUOTES); ?>"
                data-year="<?php echo htmlspecialchars($movie['year'] ?? '', ENT_QUOTES); ?>"
                title="<?php echo $inWishlist ? t('wishlist_added') : t('wishlist_add'); ?>">
                <?php echo $inWishlist ? '★' : '☆'; ?>
            </button>
            <?php endif; ?>
        </div>

        <div class="detail-info">
            <h1><?php echo htmlspecialchars($movie['title']); ?></h1>

            <div class="detail-meta">
                <?php if (!empty($movie['year'])): ?>
                    <span class="meta-tag"><?php echo htmlspecialchars($movie['year']); ?></span>
                <?php endif; ?>
                <?php if (!empty($movie['genre'])): ?>
                    <span class="meta-tag"><?php echo htmlspecialchars($movie['genre']); ?></span>
                <?php endif; ?>
                <?php if (!empty($movie['director'])): ?>
                    <span class="meta-tag">Dir. <?php echo htmlspecialchars($movie['director']); ?></span>
                <?php endif; ?>
                <?php if ($mode === 'tmdb' && !empty($movie['tmdb_vote'])): ?>
                    <span class="meta-tag">TMDB &#9733; <?php echo $movie['tmdb_vote']; ?></span>
                <?php endif; ?>
                <?php if (!empty($movie['rating'])): ?>
                    <span class="meta-tag meta-rating">&#9733; <?php echo htmlspecialchars($movie['rating']); ?> / 10</span>
                <?php endif; ?>
            </div>

            <h3><?php echo t('description'); ?></h3>
            <p class="description">
                <?php echo !empty($movie['description'])
                    ? htmlspecialchars($movie['description'])
                    : '<em>' . t('no_desc') . '</em>'; ?>
            </p>
        </div>

        <?php if ($trailerKey): ?>
        <div class="detail-trailer">
            <iframe
                src="https://www.youtube.com/embed/<?php echo htmlspecialchars($trailerKey); ?>?rel=0"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"
                allowfullscreen>
            </iframe>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="section">
    <div class="section-title">
        <h2><?php echo t('reviews_title'); ?></h2>
        <div></div>
    </div>

    <div class="review-list">
        <?php if (count($reviews) > 0): ?>
            <?php foreach ($reviews as $review): ?>
                <div class="review-item">
                    <div class="avatar">
                        <?php echo strtoupper(substr($review['username'], 0, 2)); ?>
                    </div>
                    <div>
                        <h4><?php echo htmlspecialchars($review['username']); ?></h4>
                        <?php if (!empty($review['review_text'])): ?>
                            <p><?php echo htmlspecialchars($review['review_text']); ?></p>
                        <?php else: ?>
                            <p class="muted-text"><?php echo t('no_written'); ?></p>
                        <?php endif; ?>
                        <small><?php echo $review['created_at']; ?></small>
                    </div>
                    <strong>&#9733; <?php echo $review['score']; ?>/10</strong>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="padding:20px;color:var(--muted)"><?php echo t('no_reviews_yet'); ?></p>
        <?php endif; ?>
    </div>
</section>

<?php if (isset($_SESSION['user_id'])): ?>
<section id="write-review" class="write-review">
    <h2><?php echo t('write_review'); ?></h2>

    <?php if ($message): ?>
        <p class="message"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="POST" id="reviewForm">
        <label><?php echo t('your_rating'); ?></label>
        <div class="rating-picker">
            <div class="rating-numbers" id="ratingNumbers">
                <?php for ($i = 1; $i <= 10; $i++): ?>
                    <button type="button" class="r-btn" data-val="<?php echo $i; ?>"><?php echo $i; ?></button>
                <?php endfor; ?>
            </div>
            <input type="hidden" name="score" id="scoreInput" value="">
            <span class="rating-label" id="ratingLabel"><?php echo t('choose_score'); ?></span>
        </div>

        <label><?php echo t('your_review'); ?></label>
        <textarea name="review_text" placeholder="<?php echo htmlspecialchars(t('review_ph')); ?>"></textarea>

        <button type="submit"><?php echo t('submit_review'); ?></button>
    </form>
</section>

<script>
(function () {
    const btns  = document.querySelectorAll('.r-btn');
    const input = document.getElementById('scoreInput');
    const label = document.getElementById('ratingLabel');
    const MSG_SCORE = <?php echo json_encode(t('your_score')); ?>;
    const MSG_PICK  = <?php echo json_encode(t('pick_first')); ?>;
    let selected = 0;

    function cls(v) { return v <= 3 ? 'low' : v <= 6 ? 'mid' : 'high'; }
    function hex(v) { return v <= 3 ? '#ef4444' : v <= 6 ? '#eab308' : '#22c55e'; }

    function clearAll() {
        btns.forEach(b => b.classList.remove('low', 'mid', 'high', 'selected'));
    }
    function applyUp(upTo) {
        const c = cls(upTo);
        btns.forEach(b => { if (+b.dataset.val <= upTo) b.classList.add(c); });
    }
    function applySelected(val) {
        clearAll();
        const c = cls(val);
        btns.forEach(b => { if (+b.dataset.val <= val) b.classList.add(c, 'selected'); });
    }

    btns.forEach(btn => {
        const val = +btn.dataset.val;
        btn.addEventListener('mouseenter', () => {
            if (!selected) { clearAll(); applyUp(val); }
        });
        btn.addEventListener('mouseleave', () => {
            if (!selected) clearAll();
            else applySelected(selected);
        });
        btn.addEventListener('click', () => {
            selected    = val;
            input.value = val;
            applySelected(val);
            label.textContent = MSG_SCORE.replace('%d', val);
            label.style.color = hex(val);
        });
    });

    document.getElementById('reviewForm').addEventListener('submit', function (e) {
        if (!input.value) {
            e.preventDefault();
            label.textContent = MSG_PICK;
            label.style.color = '#ef4444';
        }
    });
})();
</script>

<?php else: ?>
<section class="write-review">
    <h2><?php echo t('write_review'); ?></h2>
    <p style="color:var(--muted)">
        <a href="<?php echo $base; ?>/login.php" style="color:var(--violet)"><?php echo t('login_link'); ?></a>
        <?php echo t('or'); ?>
        <a href="<?php echo $base; ?>/register.php" style="color:var(--violet)"><?php echo t('register_link'); ?></a>
        <?php echo t('login_to_review'); ?>
    </p>
</section>
<?php endif; ?>

<?php if (isset($_SESSION['user_id']) && $wTmdbId): ?>
<script>
(function() {
    const btn = document.getElementById('wishBtn');
    if (!btn) return;
    btn.addEventListener('click', function() {
        fetch('<?php echo $base; ?>/wishlist.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                tmdb_id: btn.dataset.tmdb,
                title:   btn.dataset.title,
                poster:  btn.dataset.poster,
                year:    btn.dataset.year
            })
        })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'added') {
                btn.classList.add('active');
                btn.textContent = '★';
            } else if (d.status === 'removed') {
                btn.classList.remove('active');
                btn.textContent = '☆';
            }
        });
    });
})();
</script>
<?php endif; ?>

</main></div></body></html>
