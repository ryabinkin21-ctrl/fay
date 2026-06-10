<?php
require __DIR__ . '/includes/session_init.php';
require 'includes/db.php';
require 'includes/lang.php';
require 'includes/mailer.php';

// Add email_verified column if not yet exists
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0");
    // Mark all existing users as verified so they can still log in
    $pdo->exec("UPDATE users SET email_verified = 1");
} catch (PDOException $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_token (token),
    INDEX idx_user  (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$message = '';
$success = false;

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
            $stmt   = $pdo->prepare("INSERT INTO users (username, email, password, role, email_verified) VALUES (?,?,?,'user',0)");
            $stmt->execute([$username, $email, $hashed]);
            $userId = (int)$pdo->lastInsertId();

            // Delete any old verification tokens for this user
            $pdo->prepare("DELETE FROM email_verifications WHERE user_id = ?")->execute([$userId]);

            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 86400); // 24 hours

            $pdo->prepare("INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)")
                ->execute([$userId, $token, $expires]);

            $appUrl     = rtrim(parse_ini_file(__DIR__ . '/.env')['APP_URL'] ?? '', '/');
            $verifyUrl  = $appUrl . '/verify_email.php?token=' . urlencode($token);

            $subject = t('verify_email_subject');
            $body    = t('verify_email_body') . "\n\n" . $verifyUrl . "\n\n" . t('verify_email_expire');

            sendMail($email, $username, $subject, $body);

            $success = true;
        }
    }
}

$auth_page = true;
require 'includes/header.php';
?>

<section class="auth-section">
    <div class="auth-card">
        <h1><?php echo t('register_title'); ?></h1>

        <?php if ($success): ?>
            <p class="message success"><?php echo htmlspecialchars(t('register_verify_sent')); ?></p>
        <?php else: ?>
            <?php if ($message): ?>
                <p class="message"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>
            <form method="POST">
                <input type="text"     name="username" placeholder="<?php echo htmlspecialchars(t('username_ph')); ?>">
                <input type="email"    name="email"    placeholder="<?php echo htmlspecialchars(t('email_ph')); ?>">
                <input type="password" name="password" placeholder="<?php echo htmlspecialchars(t('password_ph')); ?>">
                <button type="submit"><?php echo t('create_btn'); ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>

</main></div></body></html>
