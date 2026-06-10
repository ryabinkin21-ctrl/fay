<?php
require __DIR__ . '/includes/session_init.php';
require 'includes/db.php';
require 'includes/lang.php';

$token   = trim($_GET['token'] ?? '');
$message = '';
$valid   = false;
$success = false;
$reset   = null;

if (!empty($token)) {
    $stmt = $pdo->prepare("
        SELECT pr.id, pr.user_id
        FROM password_resets pr
        WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if ($reset) {
        $valid = true;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm']  ?? '';

            if (strlen($password) < 6) {
                $message = t('reset_err_short');
            } elseif ($password !== $confirm) {
                $message = t('reset_err_mismatch');
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $reset['user_id']]);
                $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$reset['id']]);
                $success = true;
                $valid   = false;
            }
        }
    } else {
        $message = t('reset_err_invalid');
    }
}

$auth_page = true;
require 'includes/header.php';
?>

<section class="auth-section">
    <div class="auth-card">
        <h1><?php echo t('reset_title'); ?></h1>

        <?php if ($success): ?>
            <p class="message success"><?php echo htmlspecialchars(t('reset_success')); ?></p>
            <p style="margin-top:1rem;text-align:center">
                <a href="login.php"><?php echo t('back_to_login'); ?></a>
            </p>

        <?php elseif ($valid): ?>
            <?php if ($message): ?>
                <p class="message"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>
            <form method="POST" action="reset_password.php?token=<?php echo urlencode($token); ?>">
                <input type="password" name="password"
                       placeholder="<?php echo htmlspecialchars(t('reset_new_ph')); ?>">
                <input type="password" name="confirm"
                       placeholder="<?php echo htmlspecialchars(t('reset_confirm_ph')); ?>">
                <button type="submit"><?php echo t('reset_btn'); ?></button>
            </form>

        <?php else: ?>
            <p class="message"><?php echo htmlspecialchars($message ?: t('reset_err_invalid')); ?></p>
            <p style="margin-top:1rem;text-align:center">
                <a href="forgot_password.php"><?php echo t('forgot_title'); ?></a>
            </p>
        <?php endif; ?>
    </div>
</section>

</main></div></body></html>
