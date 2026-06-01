<?php

require "../includes/admin_auth.php";
require "../includes/db.php";
require "../includes/tmdb.php";
require "../includes/header.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title = trim($_POST["title"]);
    $year = (int)$_POST["year"];
    $genre = trim($_POST["genre"]);
    $director = trim($_POST["director"]);
    $description = trim($_POST["description"]);
    $rating = (float)$_POST["rating"];
    $poster = "";
    $tmdbMovie = getMovieFromTMDB($title);

if ($tmdbMovie) {
    if (!empty($tmdbMovie["poster_path"])) {
        $poster = "https://image.tmdb.org/t/p/w500" . $tmdbMovie["poster_path"];
    }

    if (empty($description) && !empty($tmdbMovie["overview"])) {
        $description = $tmdbMovie["overview"];
    }

    if (empty($year) && !empty($tmdbMovie["release_date"])) {
        $year = (int)substr($tmdbMovie["release_date"], 0, 4);
    }

    if (empty($rating) && isset($tmdbMovie["vote_average"])) {
        $rating = round($tmdbMovie["vote_average"], 1);
    }
    
    if (empty($genre) && !empty($tmdbMovie["genres_string"])) {
    $genre = $tmdbMovie["genres_string"];
    }

    if (empty($director) && !empty($tmdbMovie["director_name"])) {
    $director = $tmdbMovie["director_name"];
    }
}

if (!empty($_FILES["poster"]["name"])) {

    $fileName = time() . "_" . basename($_FILES["poster"]["name"]);

    $target = "../assets/uploads/" . $fileName;

    move_uploaded_file($_FILES["poster"]["tmp_name"], $target);

    $poster = "assets/uploads/" . $fileName;
}
    
    if (empty($title) || empty($genre) || empty($director)) {
        $message = "Please fill in the required fields.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO movies (title, year, genre, director, description, poster, rating)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([$title, $year, $genre, $director, $description, $poster, $rating]);

        header("Location: dashboard.php");
        exit;
    }
}
?>

<section class="section">
    <div class="section-title">
        <h2>Add Movie</h2>
        <div></div>
    </div>

    <?php if($message): ?>
        <p class="message"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="admin-form">
        <input type="text" name="title" placeholder="Movie title" required>
        <input type="number" name="year" placeholder="Year">
        <input type="text" name="genre" placeholder="Genre" required>
        <input type="text" name="director" placeholder="Director" required>
        <input type="file" name="poster" accept="image/*">
        <input type="number" step="0.1" min="0" max="10" name="rating" placeholder="Rating">

        <textarea name="description" placeholder="Description"></textarea>

        <button type="submit">Add Movie</button>
    </form>
</section>

</main></div></body></html>