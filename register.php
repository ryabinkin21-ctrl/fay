<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require 'includes/db.php';
require 'includes/lang.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($username) || empty($email) || empty($password)) {
        $message = t('register_err_empty');
    } else {
        $check = $pdo->prepare("SELECT email, username FROM users WHERE email = ? OR username = ? LIMIT 1");
        $check->execute([$email, $username]);
        $existing = $check->fetch();

        if ($existing && $existing['email'] === $email) {
            $message = t('register_err_email');
        } elseif ($existing && $existing['username'] === $username) {
            $message = t('register_err_username');
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt   = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?,?,?,'user')");
            $stmt->execute([$username, $email, $hashed]);
            $_SESSION['register_success'] = true;
            header('Location: login.php');
            exit;
        }
    }
}

$auth_page = true;
require 'includes/header.php';
?>

<section class="auth-section">
    <div class="auth-card">
        <h1><?php echo t('register_title'); ?></h1>

        <?php if ($message): ?>
            <p class="message"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <form method="POST">
            <input type="text"     name="username" placeholder="<?php echo htmlspecialchars(t('username_ph')); ?>">
            <input type="email"    name="email"    placeholder="<?php echo htmlspecialchars(t('email_ph')); ?>">
            <input type="password" name="password" placeholder="<?php echo htmlspecialchars(t('password_ph')); ?>">
            <button type="submit"><?php echo t('create_btn'); ?></button>
        </form>
    </div>
</section>

</main></div></body></html>
