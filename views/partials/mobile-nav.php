<?php $me = auth(); $uri = $_SERVER['REQUEST_URI'] ?? '/'; $base = cfg('base_url'); ?>
<nav class="mobile-nav" aria-label="Основная навигация">
    <a href="<?= e(url('/')) ?>" class="<?= ($uri === ($base ?: '/') || $uri === $base . '/') ? 'is-active' : '' ?>">
        <?= icon('flame') ?>
        Горячее
    </a>

    <a href="<?= e(url('/skidki')) ?>" class="<?= (str_starts_with($uri, $base . '/skidki') || str_starts_with($uri, $base . '/promokody')) ? 'is-active' : '' ?>">
        <?= icon('tag') ?>
        Скидки
    </a>

    <a href="<?= e(url('/dobavit')) ?>" class="mobile-nav__add" aria-label="Добавить публикацию">
        <span class="bubble">
            <?= icon('plus') ?>
        </span>
        <em>Добавить</em>
    </a>

    <a href="<?= e(url('/blogi')) ?>" class="<?= str_starts_with($uri, $base . '/blogi') ? 'is-active' : '' ?>">
        <?= icon('document') ?>
        Блоги
    </a>

    <?php if ($me): ?>
        <a href="<?= e(url('/profile/' . rawurlencode($me['username']))) ?>" class="<?= str_starts_with($uri, $base . '/profile') ? 'is-active' : '' ?>">
            <?= icon('user') ?>
            Профиль
        </a>
    <?php else: ?>
        <a href="<?= e(url('/vhod')) ?>" class="<?= str_starts_with($uri, $base . '/vhod') ? 'is-active' : '' ?>">
            <?= icon('logout') ?>
            Войти
        </a>
    <?php endif; ?>
</nav>
