<?php
require __DIR__ . '/includes/session_init.php';
require 'includes/db.php';
require 'includes/lang.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];

        if (!empty($user['lang']) && in_array($user['lang'], ['en','ru'], true)) {
            $_SESSION['lang'] = $user['lang'];
            setcookie('lang', $user['lang'], time() + 365 * 24 * 3600, '/');
        }

        $base = '';
        header("Location: $base/index.php");
        exit;
    } else {
        $message = t('login_invalid');
    }
}

$auth_page = true;
require 'includes/header.php';
?>

<section class="auth-section">
    <div class="auth-card">
        <h1><?php echo t('login_title'); ?></h1>

        <?php if (!empty($_SESSION['register_success'])): ?>
            <p class="message success"><?php echo t('register_success'); ?></p>
            <?php unset($_SESSION['register_success']); ?>
        <?php endif; ?>

        <?php if ($message): ?>
            <p class="message"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <form method="POST">
            <input type="text"     name="email"    placeholder="<?php echo htmlspecialchars(t('email_ph')); ?>">
            <input type="password" name="password" placeholder="<?php echo htmlspecialchars(t('password_ph')); ?>">
            <button type="submit"><?php echo t('login_btn'); ?></button>
        </form>
    </div>
</section>

</main></div></body></html>
