<?php
require __DIR__ . '/includes/session_init.php';
require 'includes/db.php';
require 'includes/lang.php';

$token   = trim($_GET['token'] ?? '');
$success = false;
$message = '';

if (!empty($token)) {
    $stmt = $pdo->prepare("
        SELECT ev.id, ev.user_id
        FROM email_verifications ev
        WHERE ev.token = ? AND ev.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if ($row) {
        $pdo->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$row['user_id']]);
        $pdo->prepare("DELETE FROM email_verifications WHERE id = ?")->execute([$row['id']]);
        $success = true;
    } else {
        $message = t('verify_err_invalid');
    }
} else {
    $message = t('verify_err_invalid');
}

$auth_page = true;
require 'includes/header.php';
?>

<section class="auth-section">
    <div class="auth-card">
        <h1><?php echo t('verify_title'); ?></h1>

        <?php if ($success): ?>
            <p class="message success"><?php echo htmlspecialchars(t('verify_success')); ?></p>
            <p style="margin-top:1rem;text-align:center">
                <a href="login.php"><?php echo t('back_to_login'); ?></a>
            </p>
        <?php else: ?>
            <p class="message"><?php echo htmlspecialchars($message); ?></p>
            <p style="margin-top:1rem;text-align:center">
                <a href="register.php"><?php echo t('register_title'); ?></a>
            </p>
        <?php endif; ?>
    </div>
</section>

</main></div></body></html>
