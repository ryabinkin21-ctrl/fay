<?php
require_once __DIR__ . '/session_init.php';

$base = '';

require_once __DIR__ . '/lang.php';   // defines t(), $currentLang
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fay</title>
    <link rel="icon" href="<?php echo $base; ?>/assets/logo.png" >
    <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/style.css">
    <!-- apply saved theme before render to avoid flash -->
    <script>
        (function() {
            const t = localStorage.getItem('fay-theme');
            if (t === 'light') document.documentElement.setAttribute('data-theme', 'light');
        })();
    </script>
</head>
<body>

<div class="page">

<header class="site-header">
    <a class="logo" href="<?php echo $base; ?>/index.php">FAY</a>

    <?php if (empty($auth_page)): ?>
    <nav class="nav">
        <a href="<?php echo $base; ?>/index.php"><?php echo t('nav_movies'); ?></a>
        <a href="<?php echo $base; ?>/reviews.php"><?php echo t('nav_reviews'); ?></a>
        <a href="#" id="openAbout"><?php echo t('nav_about'); ?></a>
    </nav>
    <?php endif; ?>

    <div class="auth-links">
        <!-- theme toggle -->
        <button class="theme-toggle" id="themeToggle" title="Switch theme" aria-label="Switch theme">
            <span class="theme-icon-dark">☾</span>
            <span class="theme-icon-light">☀</span>
        </button>

        <!-- language toggle -->
        <a class="lang-btn" href="<?php echo $base; ?>/switch_lang.php?lang=<?php echo t('lang_switch_to'); ?>">
            <?php echo t('lang_btn'); ?>
        </a>

        <?php if (isset($_SESSION['user_id'])): ?>
<a href="<?php echo $base; ?>/profile.php"><?php echo t('nav_profile'); ?></a>
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="<?php echo $base; ?>/admin/dashboard.php"><?php echo t('nav_admin'); ?></a>
            <?php endif; ?>
            <a class="register-btn" href="<?php echo $base; ?>/logout.php"><?php echo t('nav_logout'); ?></a>
        <?php else: ?>
            <a href="<?php echo $base; ?>/login.php"><?php echo t('nav_login'); ?></a>
            <a class="register-btn" href="<?php echo $base; ?>/register.php"><?php echo t('nav_register'); ?></a>
        <?php endif; ?>
    </div>
</header>

<main>

<script>
(function() {
    const btn  = document.getElementById('themeToggle');
    const html = document.documentElement;

    function apply(theme) {
        if (theme === 'light') {
            html.setAttribute('data-theme', 'light');
        } else {
            html.removeAttribute('data-theme');
        }
        localStorage.setItem('fay-theme', theme);
    }

    btn.addEventListener('click', function() {
        const current = html.getAttribute('data-theme');
        apply(current === 'light' ? 'dark' : 'light');
    });
})();
</script>

<div class="about-modal" id="aboutModal">
    <div class="about-box">
        <button class="close-about" id="closeAbout">&#215;</button>
        <h2><?php echo t('about_title'); ?></h2>
        <p><?php echo t('about_p1'); ?></p>
        <p><?php echo t('about_p2'); ?></p>
        <div class="about-features">
            <span><?php echo tr('feat_1'); ?></span>
            <span><?php echo tr('feat_2'); ?></span>
            <span><?php echo tr('feat_3'); ?></span>
            <span><?php echo tr('feat_4'); ?></span>
            <span><?php echo tr('feat_5'); ?></span>
        </div>
    </div>
</div>

<script>
(function() {
    const openAbout  = document.getElementById('openAbout');
    const closeAbout = document.getElementById('closeAbout');
    const modal      = document.getElementById('aboutModal');
    if (!openAbout) return;
    openAbout.addEventListener('click', e => { e.preventDefault(); modal.classList.add('active'); });
    closeAbout.addEventListener('click', () => modal.classList.remove('active'));
    modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('active'); });
})();
</script>
