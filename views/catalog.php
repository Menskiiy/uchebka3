<div class="layout">
    <div>
        <?php if ($kind === 'categories'): ?>
<?php
            /*
             * Каталог разделов. Раньше это была сетка одинаковых серых
             * карточек с одним названием — ни за что не зацепиться глазом,
             * и не понять, есть ли внутри хоть что-то. Теперь у раздела есть
             * значок (загруженный в админке или смайлик), число живых
             * предложений, время последнего и магазины, которые в нём торгуют.
             * Сверху — поиск по разделам.
             */
            $liveTotal = array_sum(array_column($items, 'live'));
?>
            <div class="cats__head">
                <div>
                    <h1 class="cats__title"><?= e($heading) ?></h1>
                    <p class="cats__sub">
                        <?= count($items) ?> <?= e(plural(count($items), 'раздел', 'раздела', 'разделов')) ?>,
                        <?= $liveTotal ?> <?= e(plural($liveTotal, 'действующее предложение', 'действующих предложения', 'действующих предложений')) ?>
                    </p>
                </div>

                <label class="cats__search">
                    <?= icon('search') ?>
                    <input type="search" class="input" placeholder="Найти раздел" aria-label="Найти раздел"
                           autocomplete="off" data-cats-filter>
                </label>
            </div>

            <div class="cats" data-cats>
                <?php foreach ($items as $c): ?>
                    <?php
                    $names = array_merge([$c['name']], array_column($c['children'], 'name'));
                    $shops = $c['shops'];
                    $icon  = category_icon($c);
                    ?>
                    <article class="cat<?= $c['live'] ? '' : ' cat--quiet' ?>"
                             style="--hue: <?= crc32($c['slug']) % 360 ?>"
                             data-cat-name="<?= e(mb_strtolower(implode(' ', $names))) ?>">
                        <span class="cat__icon<?= $icon['image'] ? ' cat__icon--img' : '' ?>" aria-hidden="true"><?= $icon['html'] ?></span>

                        <div class="cat__body">
                            <h2 class="cat__name">
                                <a href="<?= e(url('/kategoriya/' . rawurlencode($c['slug']))) ?>" class="cat__link"><?= e($c['name']) ?></a>
                            </h2>
                            <p class="cat__meta">
                                <?php if ($c['live']): ?>
                                    <b><?= (int) $c['live'] ?></b> <?= e(plural((int) $c['live'], 'предложение', 'предложения', 'предложений')) ?>
                                    <?php if ($c['last_at']): ?>
                                        <span class="cat__fresh">новое <?= e(ago($c['last_at'])) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Пока без предложений
                                <?php endif; ?>
                            </p>
                        </div>

                        <?php if ($shops): ?>
                            <div class="cat__shops">
                                <span class="cat__stack">
                                    <?php foreach (array_slice($shops, 0, 4) as $m): ?>
                                        <span class="cat__shop" title="<?= e($m['name']) ?>"
                                              style="background:<?= e(! empty($m['logo']) ? '#fff' : ($m['logo_color'] ?: '#c9ced6')) ?>">
                                            <?php if (! empty($m['logo'])): ?>
                                                <img src="<?= e(url('/storage/' . $m['logo'])) ?>" alt="" loading="lazy">
                                            <?php else: ?>
                                                <?= e(mb_strtoupper(mb_substr($m['name'], 0, 1))) ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </span>
                                <span class="cat__shopnames">
                                    <?= e(implode(', ', array_column(array_slice($shops, 0, 2), 'name'))) ?><?php if (count($shops) > 2): ?>
                                        и ещё <?= count($shops) - 2 ?><?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ($c['children']): ?>
                            <div class="cat__children">
                                <?php foreach ($c['children'] as $child): ?>
                                    <a href="<?= e(url('/kategoriya/' . rawurlencode($child['slug']))) ?>" class="badge"><?= e($child['name']) ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <p class="cats__empty" data-cats-empty hidden>
                Такого раздела нет. Попробуйте поиск по сайту в шапке — он ищет и по предложениям.
            </p>

            <script>
                (function () {
                    var input = document.querySelector('[data-cats-filter]');
                    var cards = document.querySelectorAll('[data-cat-name]');
                    var empty = document.querySelector('[data-cats-empty]');

                    if (!input) return;

                    input.addEventListener('input', function () {
                        var q = input.value.trim().toLowerCase().replace(/ё/g, 'е');
                        var shown = 0;

                        cards.forEach(function (card) {
                            var hit = card.getAttribute('data-cat-name').replace(/ё/g, 'е').indexOf(q) !== -1;
                            card.hidden = !hit;
                            if (hit) shown++;
                        });

                        empty.hidden = shown > 0;
                    });
                })();
            </script>

        <?php elseif ($kind === 'merchants'): ?>
            <h1 style="font-size:22px"><?= e($heading) ?></h1>

            <div class="card"><div class="card__body">
                <?php foreach ($items as $m): ?>
                    <div class="row" style="padding:10px 0;border-bottom:1px solid var(--border)">
                        <span class="merchant-chip" style="background:<?= e($m['logo_color']) ?>;width:38px;height:38px;font-size:10px">
                            <?= e(mb_substr($m['name'], 0, 7)) ?>
                        </span>
                        <a href="<?= e(url('/magazin/' . rawurlencode($m['slug']))) ?>" style="font-weight:600"><?= e($m['name']) ?></a>
                        <span class="spacer"></span>
                        <span class="muted"><?= (int) $m['threads_count'] ?> публикаций</span>
                    </div>
                <?php endforeach; ?>
            </div></div>

        <?php else: ?>
            <h1 style="font-size:22px"><?= e($heading) ?></h1>
            <div class="grid-2">
                <?php foreach ($items as $g): ?>
                    <div class="card"><div class="card__body">
                        <h3 class="card__title"><a href="<?= e(url('/obsuzhdeniya/' . rawurlencode($g['slug']))) ?>"><?= e($g['name']) ?></a></h3>
                        <p class="muted" style="margin:0;font-size:13px"><?= e($g['description']) ?></p>
                        <p class="muted" style="margin:8px 0 0;font-size:13px"><?= (int) $g['threads_count'] ?> тем</p>
                    </div></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/partials/sidebar.php'; ?>
</div>
