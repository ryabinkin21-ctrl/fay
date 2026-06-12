<?php
require 'includes/auth.php';
require 'includes/db.php';
require_once 'includes/lang.php';
require 'includes/tmdb.php';

$message = '';
$mode    = 'local';
// content type: 'movie' (default) or 'tv' — TMDB serves these under different paths
$mediaType = (($_GET['type'] ?? '') === 'tv') ? 'tv' : 'movie';
$isTv      = $mediaType === 'tv';

if (isset($_GET['tmdb_id'])) {
    $mode   = 'tmdb';
    $tmdbId = (int)$_GET['tmdb_id'];
    if (!$tmdbId) die('Movie not found');

    $tmdbLangCode = tmdbLang($currentLang);

    if ($isTv) {
        $tmdbData = tmdbGetTv($tmdbId, $tmdbLangCode);
        if (!$tmdbData) die('Movie not found');
        $movie = [
            'tmdb_id'     => $tmdbId,
            'id'          => null,
            'title'       => $tmdbData['name'] ?? '',
            'year'        => !empty($tmdbData['first_air_date']) ? substr($tmdbData['first_air_date'], 0, 4) : '',
            'genre'       => tmdbGenresString($tmdbData),
            'director'    => tmdbTvCreators($tmdbData),
            'description' => $tmdbData['overview'] ?? '',
            'poster'      => !empty($tmdbData['poster_path'])
                                ? 'https://image.tmdb.org/t/p/w500' . $tmdbData['poster_path']
                                : '',
            'rating'      => null,
            'tmdb_vote'   => number_format((float)($tmdbData['vote_average'] ?? 0), 1),
        ];
    } else {
        $tmdbData = tmdbGetMovie($tmdbId, $tmdbLangCode);
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
    }

    $localStmt  = $pdo->prepare("SELECT * FROM movies WHERE tmdb_id = ? AND media_type = ?");
    $localStmt->execute([$tmdbId, $mediaType]);
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
    // the stored row knows its own type — keep links/queries consistent
    $mediaType = ($movie['media_type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
    $isTv      = $mediaType === 'tv';
} else {
    die('Movie not found');
}

// querystring fragment that preserves the content type on redirects/links
$typeQs = $isTv ? '&type=tv' : '';

// POST: submit / update / delete review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $user_id  = (int)$_SESSION['user_id'];
    $action   = $_POST['action'] ?? 'submit';
    $location = $mode === 'tmdb' ? "movie.php?tmdb_id={$tmdbId}{$typeQs}" : "movie.php?id={$localMovieId}";

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: $location");
        exit;
    }

    if ($action === 'delete_review') {
        $reviewId = (int)($_POST['review_id'] ?? 0);
        $row = $pdo->prepare("SELECT movie_id FROM reviews WHERE id = ? AND user_id = ?");
        $row->execute([$reviewId, $user_id]);
        $rev = $row->fetch();
        if ($rev) {
            $pdo->prepare("DELETE FROM reviews WHERE id = ?")->execute([$reviewId]);
            $mid = (int)$rev['movie_id'];
            $avg = $pdo->prepare("
                SELECT AVG(r.score) AS a FROM reviews r
                INNER JOIN (SELECT user_id, MAX(id) AS latest_id FROM reviews WHERE movie_id = ? GROUP BY user_id) t
                ON r.id = t.latest_id
            ");
            $avg->execute([$mid]);
            $newRating = $avg->fetch()['a'];
            $pdo->prepare("UPDATE movies SET rating = ? WHERE id = ?")
                ->execute([$newRating ? round($newRating, 1) : null, $mid]);
        }
        header("Location: $location");
        exit;
    }

    $score       = (int)($_POST['score'] ?? 0);
    $review_text = trim($_POST['review_text'] ?? '');

    if ($score < 1 || $score > 10) {
        $message = t('pick_first');
    } else {
        // For TMDB titles: auto-create local record on first review
        if ($mode === 'tmdb' && !$localMovieId) {
            if ($isTv) {
                $enData     = tmdbGetTv($tmdbId, 'en-US');
                $enTitle    = $enData['name'] ?? $movie['title'];
                $enDirector = tmdbTvCreators($enData ?? $tmdbData);
            } else {
                $enData     = tmdbGetMovie($tmdbId, 'en-US');
                $enTitle    = $enData['title'] ?? $movie['title'];
                $enDirector = tmdbDirector($enData ?? $tmdbData);
            }
            $pdo->prepare("
                INSERT INTO movies (tmdb_id, media_type, title, year, genre, director, description, poster)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $tmdbId,
                $mediaType,
                $enTitle,
                $movie['year'],
                tmdbGenresString($enData ?? $tmdbData),
                $enDirector,
                $enData['overview'] ?? $movie['description'],
                $movie['poster'],
            ]);
            $localMovieId = (int)$pdo->lastInsertId();
        }

        if ($action === 'update_review') {
            $reviewId = (int)($_POST['review_id'] ?? 0);
            $check = $pdo->prepare("SELECT id FROM reviews WHERE id = ? AND user_id = ?");
            $check->execute([$reviewId, $user_id]);
            if ($check->fetch()) {
                $pdo->prepare("UPDATE reviews SET score = ?, review_text = ? WHERE id = ?")
                    ->execute([$score, $review_text, $reviewId]);
            }
        } else {
            $pdo->prepare("INSERT INTO reviews (movie_id, user_id, review_text, score) VALUES (?,?,?,?)")
                ->execute([$localMovieId, $user_id, $review_text, $score]);
        }

        $avg = $pdo->prepare("
            SELECT AVG(r.score) AS a
            FROM reviews r
            INNER JOIN (
                SELECT user_id, MAX(id) AS latest_id
                FROM reviews WHERE movie_id = ?
                GROUP BY user_id
            ) t ON r.id = t.latest_id
        ");
        $avg->execute([$localMovieId]);
        $pdo->prepare("UPDATE movies SET rating = ? WHERE id = ?")
            ->execute([round($avg->fetch()['a'], 1), $localMovieId]);

        header("Location: $location");
        exit;
    }
}

// Check wishlist
$inWishlist = false;
$wTmdbId    = $mode === 'tmdb' ? $tmdbId : (int)($movie['tmdb_id'] ?? 0);
if (isset($_SESSION['user_id']) && $wTmdbId) {
    $wCheck = $pdo->prepare("SELECT id FROM wishlist WHERE user_id = ? AND tmdb_id = ? AND media_type = ?");
    $wCheck->execute([(int)$_SESSION['user_id'], $wTmdbId, $mediaType]);
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
    $trailerKey = $isTv ? tmdbGetTvTrailer($trailerTmdbId) : tmdbGetTrailer($trailerTmdbId);
}

// Fetch reviews from local DB
$reviews = [];
if ($localMovieId) {
    $revStmt = $pdo->prepare("
        SELECT reviews.*, users.username,
            (SELECT COUNT(*) FROM reviews r2
             WHERE r2.movie_id = reviews.movie_id
               AND r2.user_id = reviews.user_id
               AND r2.id < reviews.id) AS watch_number
        FROM reviews JOIN users ON reviews.user_id = users.id
        WHERE reviews.movie_id = ?
        ORDER BY reviews.created_at DESC
    ");
    $revStmt->execute([$localMovieId]);
    $reviews = $revStmt->fetchAll();
}

// Fetch current user's latest review for pre-filling the form
$userReview = null;
if (isset($_SESSION['user_id']) && $localMovieId) {
    $urStmt = $pdo->prepare("SELECT * FROM reviews WHERE movie_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
    $urStmt->execute([$localMovieId, (int)$_SESSION['user_id']]);
    $userReview = $urStmt->fetch() ?: null;
}

require 'includes/header.php';
?>

<section class="movie-detail">
    <div class="breadcrumbs">
        <a href="<?php echo $base; ?>/index.php"><?php echo t('home'); ?></a> ›
        <?php if ($isTv): ?>
            <a href="<?php echo $base; ?>/series.php"><?php echo t('nav_series'); ?></a> ›
        <?php else: ?>
            <a href="<?php echo $base; ?>/index.php"><?php echo t('nav_movies'); ?></a> ›
        <?php endif; ?>
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
                data-type="<?php echo $mediaType; ?>"
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
                        <h4>
                            <?php echo htmlspecialchars($review['username']); ?>
                            <?php if ($review['watch_number'] > 0): ?>
                                <span class="rewatch-badge"><?php echo t('rewatch'); ?></span>
                            <?php endif; ?>
                        </h4>
                        <?php if (!empty($review['review_text'])): ?>
                            <p><?php echo htmlspecialchars($review['review_text']); ?></p>
                        <?php else: ?>
                            <p class="muted-text"><?php echo t('no_written'); ?></p>
                        <?php endif; ?>
                        <small><?php echo $review['created_at']; ?></small>
                        <?php if (isset($_SESSION['user_id']) && (int)$review['user_id'] === (int)$_SESSION['user_id']): ?>
                        <div class="review-actions">
                            <a class="review-edit-btn" href="#write-review"
                               data-id="<?php echo $review['id']; ?>"
                               data-score="<?php echo $review['score']; ?>"
                               data-text="<?php echo htmlspecialchars($review['review_text'], ENT_QUOTES); ?>">
                                <?php echo t('edit_review'); ?>
                            </a>
                            <form method="POST" style="display:inline"
                                  onsubmit="return confirm(<?php echo json_encode(t('delete_confirm')); ?>);">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="delete_review">
                                <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                <button type="submit" class="review-delete-btn"><?php echo t('delete_review'); ?></button>
                            </form>
                        </div>
                        <?php endif; ?>
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
    <h2 id="reviewFormTitle"><?php echo $userReview ? t('edit_review_title') : t('write_review'); ?></h2>

    <?php if ($message): ?>
        <p class="message"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="POST" id="reviewForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" id="reviewAction" value="<?php echo $userReview ? 'update_review' : 'submit'; ?>">
        <input type="hidden" name="review_id" id="reviewId" value="<?php echo $userReview ? $userReview['id'] : ''; ?>">

        <label><?php echo t('your_rating'); ?></label>
        <div class="rating-picker">
            <div class="rating-numbers" id="ratingNumbers">
                <?php for ($i = 1; $i <= 10; $i++): ?>
                    <button type="button" class="r-btn" data-val="<?php echo $i; ?>"><?php echo $i; ?></button>
                <?php endfor; ?>
            </div>
            <input type="hidden" name="score" id="scoreInput" value="<?php echo $userReview ? $userReview['score'] : ''; ?>">
            <span class="rating-label" id="ratingLabel"><?php echo $userReview ? t('your_score') : t('choose_score'); ?></span>
        </div>

        <label><?php echo t('your_review'); ?></label>
        <textarea name="review_text" placeholder="<?php echo htmlspecialchars(t('review_ph')); ?>"><?php echo $userReview ? htmlspecialchars($userReview['review_text']) : ''; ?></textarea>

        <button type="submit" id="reviewSubmitBtn"><?php echo $userReview ? t('update_review') : t('submit_review'); ?></button>
    </form>
</section>

<script>
(function () {
    const btns      = document.querySelectorAll('.r-btn');
    const input     = document.getElementById('scoreInput');
    const label     = document.getElementById('ratingLabel');
    const action    = document.getElementById('reviewAction');
    const reviewId  = document.getElementById('reviewId');
    const formTitle = document.getElementById('reviewFormTitle');
    const submitBtn = document.getElementById('reviewSubmitBtn');
    const MSG_SCORE       = <?php echo json_encode(t('your_score')); ?>;
    const MSG_PICK        = <?php echo json_encode(t('pick_first')); ?>;
    const MSG_EDIT_TITLE  = <?php echo json_encode(t('edit_review_title')); ?>;
    const MSG_WRITE_TITLE = <?php echo json_encode(t('write_review')); ?>;
    const MSG_UPDATE      = <?php echo json_encode(t('update_review')); ?>;
    const MSG_SUBMIT      = <?php echo json_encode(t('submit_review')); ?>;

    let selected = <?php echo $userReview ? (int)$userReview['score'] : 0; ?>;

    function cls(v) { return v <= 3 ? 'low' : v <= 6 ? 'mid' : 'high'; }
    function hex(v) { return v <= 3 ? '#ef4444' : v <= 6 ? '#eab308' : '#22c55e'; }

    function clearAll() {
        btns.forEach(b => b.classList.remove('low', 'mid', 'high', 'selected'));
    }
    function applySelected(val) {
        clearAll();
        const c = cls(val);
        btns.forEach(b => { if (+b.dataset.val <= val) b.classList.add(c, 'selected'); });
    }
    function applyUp(upTo) {
        const c = cls(upTo);
        btns.forEach(b => { if (+b.dataset.val <= upTo) b.classList.add(c); });
    }

    if (selected) {
        applySelected(selected);
        label.textContent = MSG_SCORE.replace('%d', selected);
        label.style.color = hex(selected);
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

    // Edit button: pre-fill form from review card
    document.querySelectorAll('.review-edit-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const id    = this.dataset.id;
            const score = +this.dataset.score;
            const text  = this.dataset.text;

            action.value    = 'update_review';
            reviewId.value  = id;
            input.value     = score;
            selected        = score;
            applySelected(score);
            label.textContent = MSG_SCORE.replace('%d', score);
            label.style.color = hex(score);

            document.querySelector('textarea[name="review_text"]').value = text;
            formTitle.textContent = MSG_EDIT_TITLE;
            submitBtn.textContent = MSG_UPDATE;

            document.getElementById('write-review').scrollIntoView({ behavior: 'smooth' });
        });
    });
})();
</script>

<?php else: ?>
<section class="write-review">
    <h2><?php echo t('write_review'); ?></h2>
    <p style="color:var(--muted)">
        <a href="<?php echo $base; ?>/login.php" style="color:var(--gold)"><?php echo t('login_link'); ?></a>
        <?php echo t('or'); ?>
        <a href="<?php echo $base; ?>/register.php" style="color:var(--gold)"><?php echo t('register_link'); ?></a>
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
                tmdb_id:    btn.dataset.tmdb,
                media_type: btn.dataset.type,
                title:      btn.dataset.title,
                poster:     btn.dataset.poster,
                year:       btn.dataset.year
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
