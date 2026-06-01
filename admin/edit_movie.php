<?php

require "../includes/admin_auth.php";
require "../includes/db.php";
require "../includes/header.php";

if (!isset($_GET["id"])) {
    die("Movie not found");
}

$id = (int)$_GET["id"];
$message = "";

$stmt = $pdo->prepare("SELECT * FROM movies WHERE id = ?");
$stmt->execute([$id]);
$movie = $stmt->fetch();

if (!$movie) {
    die("Movie not found");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title = trim($_POST["title"]);
    $year = (int)$_POST["year"];
    $genre = trim($_POST["genre"]);
    $director = trim($_POST["director"]);
    $description = trim($_POST["description"]);
    $rating = (float)$_POST["rating"];
    $poster = $movie["poster"];

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
            UPDATE movies
            SET title = ?, year = ?, genre = ?, director = ?, description = ?, poster = ?, rating = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $title,
            $year,
            $genre,
            $director,
            $description,
            $poster,
            $rating,
            $id
        ]);

        header("Location: dashboard.php");
        exit;
    }
}
?>

<section class="section">
    <div class="section-title">
        <h2>Edit Movie</h2>
        <div></div>
    </div>

    <?php if($message): ?>
        <p class="message"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="admin-form">   
        <input 
            type="text" 
            name="title" 
            placeholder="Movie title" 
            value="<?php echo htmlspecialchars($movie["title"]); ?>" 
            required
        >

        <input 
            type="number" 
            name="year" 
            placeholder="Year" 
            value="<?php echo htmlspecialchars($movie["year"]); ?>"
        >

        <input 
            type="text" 
            name="genre" 
            placeholder="Genre" 
            value="<?php echo htmlspecialchars($movie["genre"]); ?>" 
            required
        >

        <input 
            type="text" 
            name="director" 
            placeholder="Director" 
            value="<?php echo htmlspecialchars($movie["director"]); ?>" 
            required
        >

        <input type="file" name="poster" accept="image/*">

        <input 
            type="number" 
            step="0.1" 
            min="0" 
            max="10" 
            name="rating" 
            placeholder="Rating" 
            value="<?php echo htmlspecialchars($movie["rating"]); ?>"
        >

        <?php if(!empty($movie["poster"])): ?>
    <img 
        src="<?php echo htmlspecialchars($movie["poster"]); ?>" 
        class="edit-preview"
        alt="Poster"
    >
<?php endif; ?>

        <textarea name="description" placeholder="Description"><?php echo htmlspecialchars($movie["description"]); ?></textarea>

        <button type="submit">Save Changes</button>
    </form>

    <div class="admin-actions single">
        <a href="dashboard.php">Back to Admin Panel</a>
    </div>
</section>

</main></div></body></html>