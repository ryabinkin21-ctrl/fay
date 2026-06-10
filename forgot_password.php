<?php
require __DIR__ . '/includes/session_init.php';
require 'includes/db.php';
require 'includes/lang.php';
require 'includes/mailer.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used       TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_token  (token),
    INDEX idx_user   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $message = t('forgot_err_empty');
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always show success so we don't reveal whether email exists
        $success = true;

        if ($user) {
            $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);

            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);

            $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)")
                ->execute([$user['id'], $token, $expires]);

            $appUrl   = rtrim(parse_ini_file(__DIR__ . '/.env')['APP_URL'] ?? '', '/');
            $resetUrl = $appUrl . '/reset_password.php?token=' . urlencode($token);

            $subject = t('forgot_email_subject');
            $body    = t('forgot_email_body') . "\n\n" . $resetUrl . "\n\n" . t('forgot_email_expire');

            sendMail($email, '', $subject, $body);
        }
    }
}

$auth_page = true;
require 'includes/header.php';
?>

<section class="auth-section">
    <div class="auth-card">
        <h1><?php echo t('forgot_title'); ?></h1>

        <?php if ($success): ?>
            <p class="message success"><?php echo htmlspecialchars(t('forgot_success')); ?></p>
        <?php else: ?>
            <?php if ($message): ?>
                <p class="message"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>
            <form method="POST">
                <input type="email" name="email"
                       placeholder="<?php echo htmlspecialchars(t('forgot_email_ph')); ?>"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                <button type="submit"><?php echo t('forgot_btn'); ?></button>
            </form>
        <?php endif; ?>

        <p style="margin-top:1rem;text-align:center">
            <a href="login.php"><?php echo t('back_to_login'); ?></a>
        </p>
    </div>
</section>

</main></div></body></html>
