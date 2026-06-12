<?php
require 'includes/auth.php';
require 'includes/db.php';
require 'includes/tmdb.php';
require 'includes/header.php';

$tmdbLangCode = tmdbLang($currentLang);

$stmt = $pdo->query("
    SELECT reviews.*, users.username,
           movies.title AS movie_title, movies.poster, movies.id AS movie_id,
           movies.tmdb_id, movies.media_type
    FROM reviews
    JOIN users  ON reviews.user_id  = users.id
    JOIN movies ON reviews.movie_id = movies.id
    ORDER BY reviews.created_at DESC
");
$reviews = $stmt->fetchAll();

foreach ($reviews as &$review) {
    if (!empty($review['tmdb_id'])) {
        if (($review['media_type'] ?? 'movie') === 'tv') {
            $td = tmdbGetTv((int)$review['tmdb_id'], $tmdbLangCode);
            if ($td) {
                if (!empty($td['name']))        $review['movie_title'] = $td['name'];
                if (!empty($td['poster_path'])) $review['poster']      = 'https://image.tmdb.org/t/p/w300' . $td['poster_path'];
            }
        } else {
            $td = tmdbGetMovie((int)$review['tmdb_id'], $tmdbLangCode);
            if ($td) {
                if (!empty($td['title']))       $review['movie_title'] = $td['title'];
                if (!empty($td['poster_path'])) $review['poster']      = 'https://image.tmdb.org/t/p/w300' . $td['poster_path'];
            }
        }
    }
}
unset($review);
?>

<section class="section">
    <div class="section-title">
        <h2><?php echo t('latest_reviews'); ?></h2>
        <div></div>
    </div>

    <?php if (count($reviews) > 0): ?>
        <div class="all-reviews">
            <?php foreach ($reviews as $review): ?>
                <?php
                    $rvTypeQs = ($review['media_type'] ?? 'movie') === 'tv' ? '&type=tv' : '';
                    $movieUrl = $review['tmdb_id']
                        ? $base . '/movie.php?tmdb_id=' . (int)$review['tmdb_id'] . $rvTypeQs
                        : $base . '/movie.php?id='      . (int)$review['movie_id'];
                ?>
                <div class="global-review-card compact">

                    <a class="review-poster-link" href="<?php echo $movieUrl; ?>">
                        <?php if (!empty($review['poster'])): ?>
                            <img src="<?php echo htmlspecialchars($review['poster']); ?>" alt="Poster">
                        <?php else: ?>
                            <div class="poster-placeholder compact-poster">No Poster</div>
                        <?php endif; ?>
                    </a>

                    <div class="compact-review-content">
                        <div class="compact-review-top">
                            <h3>
                                <a href="<?php echo $movieUrl; ?>">
                                    <?php echo htmlspecialchars($review['movie_title']); ?>
                                </a>
                            </h3>
                            <span class="compact-score">&#9733; <?php echo $review['score']; ?></span>
                        </div>

                        <p class="review-author">
                            <?php echo t('review_by'); ?> <?php echo htmlspecialchars($review['username']); ?>
                        </p>

                        <?php if (!empty($review['review_text'])): ?>
                            <p class="compact-review-text"><?php echo htmlspecialchars($review['review_text']); ?></p>
                        <?php else: ?>
                            <p class="muted-text"><?php echo t('rating_only'); ?></p>
                        <?php endif; ?>

                        <small class="review-date"><?php echo $review['created_at']; ?></small>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p style="color:var(--muted)"><?php echo t('no_reviews_page'); ?></p>
    <?php endif; ?>
</section>

</main></div></body></html>
