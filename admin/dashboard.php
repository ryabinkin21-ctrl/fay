<?php

require "../includes/admin_auth.php";
require "../includes/db.php";
require "../includes/header.php";

$moviesCount  = $pdo->query("SELECT COUNT(*) FROM movies")->fetchColumn();
$reviewsCount = $pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
$usersCount   = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

$movies = $pdo->query("SELECT * FROM movies ORDER BY id DESC")->fetchAll();

$reviews = $pdo->query("
    SELECT reviews.id, reviews.score, reviews.review_text, reviews.created_at,
           users.username, movies.title AS movie_title, movies.id AS movie_id,
           movies.tmdb_id, movies.media_type
    FROM reviews
    JOIN users  ON reviews.user_id  = users.id
    JOIN movies ON reviews.movie_id = movies.id
    ORDER BY reviews.created_at DESC
")->fetchAll();
?>

<section class="section">
    <div class="section-title">
        <h2>Admin Panel</h2>
        <div></div>
    </div>

    <div class="admin-stats">
        <div>
            <h3><?php echo $moviesCount; ?></h3>
            <p>Movies</p>
        </div>
        <div>
            <h3><?php echo $reviewsCount; ?></h3>
            <p>Reviews</p>
        </div>
        <div>
            <h3><?php echo $usersCount; ?></h3>
            <p>Users</p>
        </div>
    </div>

    <div class="admin-actions">
        <a href="<?php echo $base; ?>/admin/add_movie.php">Add Movie</a>
        <a href="<?php echo $base; ?>/admin/import_movies.php">Bulk Import</a>
    </div>

    <!-- Movies table -->
    <div class="section-title admin-list-title">
        <h2>Movie List</h2>
        <div></div>
    </div>

    <div class="admin-table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Poster</th>
                    <th>Title</th>
                    <th>Year</th>
                    <th>Genre</th>
                    <th>Rating</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movies as $movie): ?>
                    <tr>
                        <td><?php echo $movie['id']; ?></td>
                        <td>
                            <?php if (!empty($movie['poster'])): ?>
                                <img class="admin-poster" src="<?php echo htmlspecialchars($movie['poster']); ?>" alt="Poster">
                            <?php else: ?>
                                <span>No poster</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($movie['title']); ?></td>
                        <td><?php echo htmlspecialchars($movie['year']); ?></td>
                        <td><?php echo htmlspecialchars($movie['genre']); ?></td>
                        <td>★ <?php echo htmlspecialchars($movie['rating']); ?></td>
                        <td class="admin-buttons">
                            <a class="edit-btn" href="<?php echo $base; ?>/admin/edit_movie.php?id=<?php echo $movie['id']; ?>">Edit</a>
                            <form method="POST" action="<?php echo $base; ?>/admin/delete_movie.php" style="display:inline" onsubmit="return confirm('Delete this movie and all its reviews?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="id" value="<?php echo $movie['id']; ?>">
                                <button type="submit" class="delete-btn">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Reviews table -->
    <div class="section-title admin-list-title" id="reviews">
        <h2>Reviews</h2>
        <div></div>
    </div>

    <div class="admin-table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Movie</th>
                    <th>User</th>
                    <th>Score</th>
                    <th>Review</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($reviews) > 0): ?>
                    <?php foreach ($reviews as $r): ?>
                        <?php
                            $rTypeQs = ($r['media_type'] ?? 'movie') === 'tv' ? '&type=tv' : '';
                            $movieUrl = $r['tmdb_id']
                                ? $base . '/movie.php?tmdb_id=' . (int)$r['tmdb_id'] . $rTypeQs
                                : $base . '/movie.php?id='      . (int)$r['movie_id'];
                        ?>
                        <tr>
                            <td><?php echo $r['id']; ?></td>
                            <td>
                                <a href="<?php echo $movieUrl; ?>" target="_blank">
                                    <?php echo htmlspecialchars($r['movie_title']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($r['username']); ?></td>
                            <td>★ <?php echo $r['score']; ?>/10</td>
                            <td class="review-text-cell">
                                <?php echo !empty($r['review_text'])
                                    ? htmlspecialchars(mb_strimwidth($r['review_text'], 0, 80, '…'))
                                    : '<span style="color:var(--muted)">—</span>'; ?>
                            </td>
                            <td><?php echo $r['created_at']; ?></td>
                            <td class="admin-buttons">
                                <form method="POST" action="<?php echo $base; ?>/admin/delete_review.php" style="display:inline" onsubmit="return confirm('Delete this review?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                    <button type="submit" class="delete-btn">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="color:var(--muted);padding:20px">No reviews yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</section>

</main></div></body></html>
