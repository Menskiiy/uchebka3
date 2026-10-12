<?php $me = auth(); $uri = $_SERVER['REQUEST_URI'] ?? '/'; ?>
<header class="header">
    <div class="container">
        <div class="header__row">
            <a href="<?= e(url('/')) ?>" class="logo" aria-label="<?= e(setting('site_name', cfg('app_name'))) ?>">
                <img src="<?= e(url('/img/logo-fire.svg')) ?>" alt="<?= e(setting('site_name', cfg('app_name'))) ?>" class="logo__img logo__img--light">
                <img src="<?= e(url('/img/logo-fire-dark.svg')) ?>" alt="" class="logo__img logo__img--dark" aria-hidden="true">
            </a>

            <form class="search" action="<?= e(url('/poisk')) ?>" method="GET" role="search">
                <span class="search__icon"><?= icon('search') ?></span>
                <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Поиск брендов, товаров и т.д." aria-label="Поиск">
            </form>

            <div class="header__spacer"></div>

            <div class="header__actions">
                <button class="theme-toggle" onclick="toggleTheme()" aria-label="Сменить тему"><?= icon('sun', 'theme-toggle__sun') ?><?= icon('moon', 'theme-toggle__moon') ?><span class="theme-toggle__knob"></span></button>

                <?php if ($me): ?>
                    <?php $unread = unread_count(); $recent = notifications_for((int) $me['id'], 6); ?>
                    <div class="dropdown">
                        <button class="btn btn--ghost bell" data-dropdown data-unread aria-label="Уведомления">
                            <?= icon('bell', '', 'Уведомления') ?>
                            <?php if ($unread > 0): ?>
                                <span class="bell__dot bell__count"><?= $unread > 99 ? '99+' : (int) $unread ?></span>
                            <?php endif; ?>
                        </button>

                        <div class="dropdown__menu dropdown__menu--wide">
                            <?php if (! $recent): ?>
                                <p class="muted" style="padding:14px;margin:0;font-size:13px">Уведомлений пока нет.</p>
                            <?php endif; ?>

                            <?php foreach ($recent as $n): ?>
                                <a href="<?= e(notification_url($n)) ?>" class="notif notif--compact <?= empty($n['read_at']) ? 'is-unread' : '' ?>">
                                    <span class="notif__icon"><?= notification_icon($n['type']) ?></span>
                                    <span class="notif__body">
                                        <span class="notif__text"><?= notification_text($n) ?></span>
                                        <span class="notif__time"><?= e(ago($n['created_at'])) ?></span>
                                    </span>
                                </a>
                            <?php endforeach; ?>

                            <a href="<?= e(url('/uvedomleniya')) ?>" style="text-align:center;font-weight:600;border-top:1px solid var(--border);margin-top:4px">
                                Все уведомления
                            </a>
                        </div>
                    </div>

                    <div class="dropdown">
                        <button class="btn btn--ghost" data-dropdown>
                            <span class="avatar">
                                <?php if ($me['avatar']): ?><img src="<?= e(url('/storage/' . $me['avatar'])) ?>" alt="">
                                <?php else: ?><?= e(initials($me)) ?><?php endif; ?>
                            </span>
                            <span class="no-mobile"><?= e($me['username']) ?> &#9662;</span>
                        </button>
                        <div class="dropdown__menu">
                            <a href="<?= e(url('/profile/' . rawurlencode($me['username']))) ?>">Мой профиль</a>
                            <a href="<?= e(url('/zakladki')) ?>">Закладки</a>
                            <a href="<?= e(url('/balans')) ?>">Баланс: <?= e(flames_format((float) ($me['balance'] ?? 0))) ?> <?= icon('flame') ?></a>
                            <a href="<?= e(url('/uvedomleniya')) ?>">Уведомления<?= unread_count() > 0 ? ' (' . unread_count() . ')' : '' ?></a>
                            <a href="<?= e(url('/nastroyki')) ?>">Настройки</a>
                            <?php if (is_moderator()): ?>
                                <a href="<?= e(url('/admin')) ?>">Панель управления</a>
                            <?php endif; ?>
                            <form method="POST" action="<?= e(url('/vyhod')) ?>"><?= csrf_field() ?>
                                <button type="submit">Выйти</button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?= e(url('/vhod')) ?>" class="btn btn--ghost">Войти</a>
                <?php endif; ?>

                <div class="dropdown dropdown--add">
                    <button class="btn btn--primary" data-dropdown>+ Добавить</button>
                    <div class="dropdown__menu">
                        <?php foreach (publishable_types() as $typeMeta): ?>
                            <a href="<?= e(url('/dobavit/' . $typeMeta['slug'])) ?>"><?= e($typeMeta['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <nav class="nav">
            <a href="<?= e(url('/kategorii')) ?>" class="<?= str_starts_with($uri, url('/kategor')) ? 'is-active' : '' ?>">Категории</a>
            <a href="<?= e(url('/promokody')) ?>" class="<?= str_starts_with($uri, url('/promokody')) ? 'is-active' : '' ?>">Промокоды</a>
            <a href="<?= e(url('/skidki')) ?>" class="<?= str_starts_with($uri, url('/skidki')) ? 'is-active' : '' ?>">Скидки</a>
            <a href="<?= e(url('/blogi')) ?>" class="<?= str_starts_with($uri, url('/blogi')) ? 'is-active' : '' ?>">Блоги</a>
            <a href="<?= e(url('/obsuzhdeniya')) ?>" class="<?= str_starts_with($uri, url('/obsuzhdeniya')) ? 'is-active' : '' ?>">Обсуждения</a>
            <a href="<?= e(url('/magaziny')) ?>" class="<?= str_starts_with($uri, url('/magaz')) ? 'is-active' : '' ?>">Магазины</a>
        </nav>
    </div>
</header>
