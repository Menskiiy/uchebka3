<div class="layout">
    <div>
        <?php if (! empty($heading)): ?>
            <div class="card" style="margin-bottom:14px"><div class="card__body">
                <h1 style="font-size:21px;margin:0"><?= e($heading) ?></h1>
                <?php if (! empty($subheading)): ?>
                    <p class="muted" style="margin:6px 0 0"><?= e($subheading) ?></p>
                <?php endif; ?>
                <?php if (! empty($actionUrl)): ?>
                    <a href="<?= e($actionUrl) ?>" class="btn btn--primary" style="margin-top:12px"><?= e($actionLabel) ?></a>
                <?php endif; ?>
            </div></div>
        <?php endif; ?>

        <?php if (! empty($carousel['items'])) { require __DIR__ . '/partials/carousel.php'; } ?>

        <?php if (! empty($merchants)): ?>
            <div class="card search-merchants" style="margin-bottom:14px"><div class="card__body">
                <h2 class="card__title">Магазины</h2>
                <div class="search-merchants__list">
                    <?php foreach ($merchants as $m): ?>
                        <a href="<?= e(url('/magazin/' . rawurlencode($m['slug']))) ?>" class="search-merchants__item">
                            <span class="search-merchants__logo" style="background:<?= e(empty($m['logo']) ? ($m['logo_color'] ?: '#c9ced6') : '#fff') ?>">
                                <?php if (! empty($m['logo'])): ?>
                                    <img src="<?= e(url('/storage/' . $m['logo'])) ?>" alt="<?= e($m['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <?= e(mb_substr($m['name'], 0, 3)) ?>
                                <?php endif; ?>
                            </span>
                            <span><?= e($m['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div></div>
        <?php endif; ?>

        <?php if (! empty($showTabs)): ?>
            <div class="feed-bar">
<?php
                /*
                 * «Сгорает» на месте всегда. Раньше вкладка появлялась только
                 * при трёх и более срочных находках и пропадала в тихий день —
                 * со стороны это выглядело как поломка. Пустую вкладку
                 * объясняет текст на самой странице.
                 */
                $tabs = [
                    'hot'       => 'Горячее',
                    'new'       => 'Новое',
                    'discussed' => 'Обсуждаемое',
                    'expiring'  => 'Сгорает',
                ];
                ?>
                <div class="tabs">
                    <?php foreach ($tabs as $key => $label):
                        $qs = $_GET; $qs['tab'] = $key; unset($qs['page']); ?>
                        <a href="?<?= e(http_build_query($qs)) ?>" class="<?= $sort === $key ? 'is-active' : '' ?><?= $key === 'expiring' ? ' tabs__hot' : '' ?>">
                            <?= e($label) ?>
                            <?php if ($key === 'expiring' && ($expiring ?? 0) > 0): ?>
                                <span class="tabs__count"><?= (int) $expiring ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if (! empty($filters)) { require __DIR__ . '/partials/filters.php'; } ?>
            </div>
        <?php endif; ?>

        <?php if (! empty($filters['count'])): ?>
            <p class="muted" style="margin:-4px 0 12px;font-size:13px">
                Найдено публикаций: <?= (int) $result['pager']['total'] ?>
            </p>
        <?php endif; ?>

        <?php if (! $result['items']): ?>
            <div class="card"><div class="empty">
                <?= $sort === 'expiring'
                    ? 'Сейчас ничего не сгорает в ближайшие двое суток. Загляните завтра.'
                    : e($emptyText ?? 'Пока ничего не опубликовано. Будьте первым!') ?>
            </div></div>
        <?php elseif ($sort === 'expiring'): ?>
        <?php endif; ?>

<?php
        // Параметры текущей ленты передаём скрипту как есть: он дозапрашивает
        // страницы теми же условиями, что применил сервер, — иначе догрузка
        // выдавала бы не то, что человек сейчас видит.
        $feedQuery = http_build_query(array_filter([
            'type'        => $lockedType ?? null,
            'scope'       => ($scope ?? null),
            'merchant'    => $merchantSlug ?? null,
            'category'    => $categorySlug ?? null,
            'q'           => input('q', '') ?: null,
            'tab'         => $sort ?? null,
            'cat'         => input('cat', '') ?: null,
            'shop'        => input('shop', '') ?: null,
            'pmin'        => input('pmin', '') ?: null,
            'pmax'        => input('pmax', '') ?: null,
            'disc'        => input('disc', '') ?: null,
            'hot'         => input('hot', '') ?: null,
            'code'        => input('code', '') ?: null,
            'free'        => input('free', '') ?: null,
            'img'         => input('img', '') ?: null,
            'exp'         => input('exp', '') ?: null,
        ], fn ($v) => $v !== null && $v !== ''));

        /*
         * Отметка времени для плашки «новые публикации» приходит из страницы:
         * это время самой свежей публикации во всей выборке, а не первой
         * карточки на экране. Первая карточка — самая свежая только во
         * вкладке «Новое», в остальных отсчёт от неё показывал новыми
         * старые посты, и плашка возвращалась после каждой перезагрузки.
         */
        $newest = $newest ?? ($result['items'][0]['published_at'] ?? null);
?>
        <?php $autoload = empty($noAutoload); ?>

        <?php if ($autoload): ?>
            <button type="button" class="feed-new" data-feed-new hidden></button>
        <?php endif; ?>

        <div <?= $autoload ? 'data-feed' : '' ?>
             data-query="<?= e($feedQuery) ?>"
             data-since="<?= e((string) $newest) ?>"
             data-has-more="<?= $result['pager']['page'] < $result['pager']['pages'] ? '1' : '0' ?>">
            <?php foreach ($result['items'] as $t) echo c_thread_card($t); ?>
        </div>

        <?php if ($autoload): ?>
            <button type="button" class="btn btn--block feed-more" data-feed-more hidden>Показать ещё</button>
            <noscript><?= c_pagination($result['pager']) ?></noscript>
        <?php else: ?>
            <?= c_pagination($result['pager']) ?>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/partials/sidebar.php'; ?>
</div>
