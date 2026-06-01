<?php

require "../includes/admin_auth.php";
require "../includes/db.php";
require "../includes/tmdb.php";
require "../includes/header.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $moviesText = trim($_POST["movies"]);

    $moviesArray = explode("\n", $moviesText);

    $added = 0;

    foreach ($moviesArray as $movieTitle) {

        $movieTitle = trim($movieTitle);

        if (empty($movieTitle)) {
            continue;
        }

        $tmdbMovie = getMovieFromTMDB($movieTitle);

        if (!$tmdbMovie) {
            continue;
        }

        $title = $tmdbMovie["title"] ?? $movieTitle;

        $year = 0;

        if (!empty($tmdbMovie["release_date"])) {
            $year = (int)substr($tmdbMovie["release_date"], 0, 4);
        }

        $description = $tmdbMovie["overview"] ?? "";

        $rating = isset($tmdbMovie["vote_average"])
            ? round($tmdbMovie["vote_average"], 1)
            : 0;

        $poster = "";

        if (!empty($tmdbMovie["poster_path"])) {
            $poster = "https://image.tmdb.org/t/p/w500" . $tmdbMovie["poster_path"];
        }

        $genre = !empty($tmdbMovie["genres_string"])
            ? $tmdbMovie["genres_string"]
            : "Unknown";
        $director = !empty($tmdbMovie["director_name"])
            ? $tmdbMovie["director_name"]
            : "Unknown";

        $checkStmt = $pdo->prepare("
            SELECT id FROM movies WHERE title = ?
        ");

        $checkStmt->execute([$title]);

        if ($checkStmt->fetch()) {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT INTO movies
            (title, year, genre, director, description, poster, rating)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $title,
            $year,
            $genre,
            $director,
            $description,
            $poster,
            $rating
        ]);

        $added++;
    }

    $message = "$added movies imported successfully.";
}
?>

<section class="section">

    <div class="section-title">
        <h2>Bulk Import Movies</h2>
        <div></div>
    </div>

    <?php if($message): ?>
        <p class="message"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="POST" class="admin-form">

        <textarea
            name="movies"
            placeholder="One movie title per line..."
            style="min-height: 300px;"
        ></textarea>

        <button type="submit">
            Import Movies
        </button>

    </form>

</section>

</main></div></body></html>