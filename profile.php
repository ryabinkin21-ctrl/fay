<?php
require 'includes/auth.php';
require 'includes/db.php';
require 'includes/tmdb.php';
require 'includes/header.php';

$tmdbLangCode = tmdbLang($currentLang);

$stmt = $pdo->prepare("
    SELECT reviews.*, movies.title, movies.year, movies.genre, movies.poster, movies.rating, movies.tmdb_id
    FROM reviews JOIN movies ON reviews.movie_id = movies.id
    WHERE reviews.user_id = ?
    ORDER BY reviews.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$userReviews = $stmt->fetchAll();

foreach ($userReviews as &$item) {
    if (!empty($item['tmdb_id'])) {
        $td = tmdbGetMovie((int)$item['tmdb_id'], $tmdbLangCode);
        if ($td) {
            if (!empty($td['title']))        $item['title']  = $td['title'];
            if (!empty($td['poster_path']))  $item['poster'] = 'https://image.tmdb.org/t/p/w300' . $td['poster_path'];
        }
    }
}
unset($item);

$pdo->exec("CREATE TABLE IF NOT EXISTS wishlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, tmdb_id INT NOT NULL,
    title VARCHAR(255) NOT NULL DEFAULT '', poster VARCHAR(500) NOT NULL DEFAULT '',
    year VARCHAR(4) NOT NULL DEFAULT '', added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_movie (user_id, tmdb_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$wStmt = $pdo->prepare("SELECT * FROM wishlist WHERE user_id = ? ORDER BY added_at DESC");
$wStmt->execute([$_SESSION['user_id']]);
$wishlistMovies = $wStmt->fetchAll();

foreach ($wishlistMovies as &$w) {
    if (!empty($w['tmdb_id'])) {
        $td = tmdbGetMovie((int)$w['tmdb_id'], $tmdbLangCode);
        if ($td) {
            if (!empty($td['title']))        $w['title']  = $td['title'];
            if (!empty($td['poster_path']))  $w['poster'] = 'https://image.tmdb.org/t/p/w300' . $td['poster_path'];
        }
    }
}
unset($w);
?>

<section class="section">
    <div class="section-title">
        <h2><?php echo t('profile_title'); ?></h2>
        <div></div>
    </div>

    <div class="profile-card">
        <h3><?php echo t('profile_greeting', htmlspecialchars($_SESSION['username'])); ?></h3>
        <p><?php echo t('profile_sub'); ?></p>
    </div>

    <!-- Хочу посмотреть -->
    <div class="section-title" style="margin-top:8px;">
        <h2><?php echo t('wishlist_title'); ?></h2>
        <div></div>
    </div>

    <?php if (count($wishlistMovies) > 0): ?>
        <div class="movie-grid" style="margin-bottom:48px;">
            <?php foreach ($wishlistMovies as $w): ?>
                <div class="movie-card">
                    <button class="wish-btn active"
                        data-tmdb="<?php echo (int)$w['tmdb_id']; ?>"
                        data-title="<?php echo htmlspecialchars($w['title'], ENT_QUOTES); ?>"
                        data-poster="<?php echo htmlspecialchars($w['poster'], ENT_QUOTES); ?>"
                        data-year="<?php echo htmlspecialchars($w['year'], ENT_QUOTES); ?>">★</button>
                    <a href="<?php echo $base; ?>/movie.php?tmdb_id=<?php echo (int)$w['tmdb_id']; ?>">
                        <?php if (!empty($w['poster'])): ?>
                            <img src="<?php echo htmlspecialchars($w['poster']); ?>" alt="Poster">
                        <?php else: ?>
                            <div class="poster-placeholder">No Poster</div>
                        <?php endif; ?>
                        <div class="movie-info">
                            <h3><?php echo htmlspecialchars($w['title']); ?></h3>
                            <div class="movie-meta">
                                <span><?php echo htmlspecialchars($w['year']); ?></span>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p style="color:var(--muted);margin-bottom:48px;"><?php echo t('wishlist_empty'); ?></p>
    <?php endif; ?>

    <!-- Мои оценённые фильмы -->
    <div class="section-title">
        <h2><?php echo t('profile_rated'); ?></h2>
        <div></div>
    </div>

    <?php if (count($userReviews) > 0): ?>
        <div class="profile-reviews">
            <?php foreach ($userReviews as $item): ?>
                <?php
                    $movieUrl = $item['tmdb_id']
                        ? $base . '/movie.php?tmdb_id=' . (int)$item['tmdb_id']
                        : $base . '/movie.php?id='      . (int)$item['movie_id'];
                ?>
                <div class="profile-review-card">
                    <?php if (!empty($item['poster'])): ?>
                        <img src="<?php echo htmlspecialchars($item['poster']); ?>" alt="Poster">
                    <?php else: ?>
                        <div class="poster-placeholder small-poster">No Poster</div>
                    <?php endif; ?>
                    <div>
                        <h3>
                            <a href="<?php echo $movieUrl; ?>">
                                <?php echo htmlspecialchars($item['title']); ?>
                            </a>
                        </h3>
                        <p><?php echo htmlspecialchars($item['year']); ?> &middot; <?php echo htmlspecialchars($item['genre']); ?></p>
                        <p><strong><?php echo t('your_score_label'); ?></strong> &#9733; <?php echo $item['score']; ?>/10</p>
                        <p><strong><?php echo t('your_review_label'); ?></strong> <?php echo htmlspecialchars($item['review_text']); ?></p>
                        <small><?php echo $item['created_at']; ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p style="color:var(--muted)"><?php echo t('profile_empty'); ?></p>
    <?php endif; ?>
</section>

<script>
document.querySelectorAll('.wish-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault(); e.stopPropagation();
        const card = this.closest('.movie-card');
        fetch('<?php echo $base; ?>/wishlist.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                tmdb_id: this.dataset.tmdb,
                title:   this.dataset.title,
                poster:  this.dataset.poster,
                year:    this.dataset.year
            })
        })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'removed' && card) {
                card.style.transition = 'opacity .3s';
                card.style.opacity = '0';
                setTimeout(() => card.remove(), 300);
            }
        });
    });
});
</script>

</main></div></body></html>
