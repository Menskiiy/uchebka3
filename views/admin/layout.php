<?php /** Оболочка админки: своя навигация, без пользовательской шапки. */ ?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-url" content="<?= e(cfg('base_url')) ?>">
    <title><?= e($title ?? 'Админка') ?> — <?= e(setting('site_name', cfg('app_name'))) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="<?= e(asset('/favicon.ico')) ?>" sizes="48x48">
    <link rel="apple-touch-icon" href="<?= e(asset('/apple-touch-icon.png')) ?>" sizes="180x180">
    <link rel="stylesheet" href="<?= e(asset('/css/app.css')) ?>">
    <script>window.PROMIKI_UPLOAD_LIMIT = <?= upload_limit_bytes() ?>;
    (function(){var s=localStorage.getItem('promiki-theme');if(s){document.documentElement.dataset.theme=s;}})();</script>
</head>
<body class="admin-body">

<div class="admin" id="admin-shell">
    <div class="admin__overlay" data-drawer-close></div>

    <aside class="admin__side">
        <a href="<?= e(url('/')) ?>" class="admin__logo">
            <img src="<?= e(url('/img/logo-fire.svg')) ?>" alt="" class="logo__img logo__img--light">
            <img src="<?= e(url('/img/logo-fire-dark.svg')) ?>" alt="" class="logo__img logo__img--dark">
            <small>панель управления</small>
        </a>

        <nav class="admin__nav">
            <?php
            $sections = [
                ''             => ['Сводка',           'dashboard'],
                'publikacii'   => ['Публикации',       'document'],
                'polzovateli'  => ['Пользователи',     'users'],
                'razdely'      => ['Разделы',          'discussion'],
                'kategorii'    => ['Категории',        'tag'],
                'magaziny'     => ['Магазины',         'shop'],
                'zhaloby'      => ['Жалобы',           'flag'],
                'zhurnal'      => ['Журнал',           'log'],
                'stranicy'     => ['Страницы',         'document'],
                'bot'          => ['Бот Steam',        'shop'],
                'kanaly'       => ['Каналы',           'share'],
                'vyplaty'      => ['Выплаты',          'stats'],
                'pochta'       => ['Почта',            'mail'],
                'nastroyki'    => ['Настройки сайта',  'settings'],
            ];

            // редактор наполняет только справочник магазинов — остальные
            // пункты ему не показываем, чтобы не тыкался в закрытые двери
            if (! is_moderator()) {
                $sections = ['magaziny' => $sections['magaziny']];
            }
            $path = trim(str_replace(cfg('base_url') . '/admin', '', strtok($_SERVER['REQUEST_URI'] ?? '', '?')), '/');
            $current = explode('/', $path)[0];

            foreach ($sections as $slug => [$label, $icon]):
                $active = $current === $slug;
            ?>
                <a href="<?= e(url('/admin' . ($slug ? '/' . $slug : ''))) ?>" class="<?= $active ? 'is-active' : '' ?>" data-drawer-close>
                    <?= icon($icon) ?>
                    <?= e($label) ?>
                    <?php if ($slug === 'zhaloby' && ! empty($GLOBALS['open_reports'])): ?>
                        <span class="admin__badge"><?= (int) $GLOBALS['open_reports'] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="admin__foot">
            <button class="theme-toggle" onclick="toggleTheme()" aria-label="Сменить тему"><?= icon('sun', 'theme-toggle__sun') ?><?= icon('moon', 'theme-toggle__moon') ?><span class="theme-toggle__knob"></span></button>
            <a href="<?= e(url('/')) ?>" class="btn btn--sm btn--ghost">На сайт</a>
            <form method="POST" action="<?= e(url('/vyhod')) ?>"><?= csrf_field() ?>
                <button class="btn btn--sm">Выйти</button>
            </form>
        </div>
    </aside>

    <main class="admin__main">
        <header class="admin__head">
            <button class="admin__burger" data-drawer-toggle aria-label="Меню" aria-expanded="false">
                <?= icon('menu') ?>
            </button>
            <h1><?= e($heading ?? $title ?? 'Админка') ?></h1>
            <?php if (! empty($headAction)): ?>
                <a href="<?= e($headAction['url']) ?>" class="btn btn--primary btn--sm"><?= e($headAction['label']) ?></a>
            <?php endif; ?>

            <?php
            /*
             * Кнопка правой панели. На телефоне форма добавления уезжала
             * в самый низ страницы: чтобы что-то создать или изменить,
             * приходилось прокручивать весь список. Теперь она открывается
             * отсюда поверх содержимого.
             *
             * Кнопка появляется только на узких экранах и только там, где
             * панель действительно есть — это решает CSS, а не разметка.
             */
            ?>
            <button class="admin__panel-btn" data-panel-toggle aria-label="Форма" aria-expanded="false">
                <?= icon('edit') ?>
            </button>
        </header>

        <?php foreach (take_flash() as $item): ?>
            <div class="alert <?= $item['type'] === 'error' ? 'alert--error' : '' ?>"><?= e($item['message']) ?></div>
        <?php endforeach; ?>

        <?= $content ?>
    </main>
</div>

<script src="<?= e(asset('/js/app.js')) ?>"></script>
</body>
</html>
