<div class="layout">
    <div>
<?php $isExpired = $t['expires_at'] && strtotime($t['expires_at']) <= time(); ?>

<?php
/*
 * Публикация на проверке или отклонённая видна только автору и модератору,
 * поэтому её состояние нужно объяснить прямо здесь: иначе человек видит
 * свой пост, не находит его в ленте и решает, что сайт сломался.
 */
$mine = auth_id() === (int) $t['user_id'];
?>
        <?php if ($mine && $t['status'] === 'pending'): ?>
            <div class="notice notice--wait">
                <strong>Публикация на проверке.</strong>
                Пока её видите только вы. Обычно проверка занимает несколько часов —
                когда модератор одобрит, придёт уведомление, и пост появится в ленте.
            </div>
        <?php endif; ?>

        <?php if ($mine && $t['status'] === 'rejected'): ?>
            <div class="notice notice--stop">
                <strong>Публикация не прошла проверку.</strong>

                <?php if (! empty($t['reject_reason'])): ?>
                    <p style="margin:8px 0 0">Причина: <?= e($t['reject_reason']) ?></p>
                <?php endif; ?>

                <?php if (! empty($t['reject_link'])): ?>
                    <p style="margin:6px 0 0">
                        <a href="<?= e($t['reject_link']) ?>" target="_blank" rel="noopener">Как оформить публикацию</a>
                    </p>
                <?php endif; ?>

                <p style="margin:8px 0 0">
                    Исправьте и сохраните — публикация сама уйдёт на повторную проверку.
                </p>

                <div class="row" style="margin-top:10px">
                    <a href="<?= e(url('/post/' . rawurlencode($t['slug']) . '/redaktirovat')) ?>" class="btn btn--sm btn--primary">
                        Исправить
                    </a>

                    <form method="POST" action="<?= e(url('/post/' . rawurlencode($t['slug']) . '/na-proverku')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn--sm">Отправить как есть</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($t['expires_at'] && is_commercial($t['type'])): ?>
            <div class="expiry-banner <?= $isExpired ? 'expiry-banner--over' : '' ?>">
                <?= c_expiry($t, true) ?>
                <?php
                // дату дублируем только когда в метке идёт обратный отсчёт,
                // иначе «Действует до 18.09» покажется дважды подряд
                $showDate = ! $isExpired && (strtotime($t['expires_at']) - time()) <= 7 * 86400;
                ?>
                <?php if ($showDate): ?>
                    <span class="expiry-banner__date">до <?= e(date('d.m.Y', strtotime($t['expires_at']))) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

<?php if ($t['type'] === 'blog'): ?>
<?php
        /*
         * Запись блога — одна карточка-статья, как на vc.ru: автор и время,
         * заголовок, обложка во всю ширину, текст блоками, подвал с оценкой.
         * Раньше обложка стояла в половину ширины рядом с «Поделился(-ась)»,
         * а текст уезжал в отдельную карточку ниже — запись разваливалась
         * на куски.
         */
        $cover  = $t['image_path'] ? url('/storage/' . $t['image_path']) : ($t['image_url'] ?: '');
        $extra  = (int) qv('SELECT COUNT(*) FROM thread_images WHERE thread_id = ?', [$t['id']]);
        $avatar = avatar_url($t['author_avatar']);
?>
        <article class="card article">
            <header class="article__head">
                <a href="<?= e(url('/profile/' . rawurlencode($t['author_name']))) ?>" class="byline">
                    <span class="avatar byline__avatar">
                        <?php if ($avatar): ?>
                            <img src="<?= e($avatar) ?>" alt="">
                        <?php else: ?>
                            <?= e(mb_strtoupper(mb_substr($t['author_name'], 0, 1))) ?>
                        <?php endif; ?>
                    </span>
                    <span class="byline__name"><?= e($t['author_name']) ?></span>
                </a>
                <time class="byline__time" datetime="<?= e(date('c', strtotime($t['published_at']))) ?>"
                      title="<?= e(date('d.m.Y H:i', strtotime($t['published_at']))) ?>"><?= e(ago($t['published_at'])) ?></time>

                <span class="spacer"></span>

                <?php if (auth_id() === (int) $t['user_id'] || is_moderator()): ?>
                    <a href="<?= e(url('/post/' . rawurlencode($t['slug']) . '/redaktirovat')) ?>" class="btn btn--sm btn--ghost">
                        <?= icon('edit') ?> Редактировать
                    </a>
                <?php endif; ?>

                <?php if (auth_id()): ?>
                    <form method="POST" action="<?= e(url('/post/' . rawurlencode($t['slug']) . '/zakladka')) ?>"><?= csrf_field() ?>
                        <button class="btn btn--sm btn--ghost"><?= icon('bookmark') ?> <?= is_bookmarked((int) $t['id']) ? 'В закладках' : 'В закладки' ?></button>
                    </form>
                <?php endif; ?>
            </header>

            <h1 class="article__title"><?= e($t['title']) ?></h1>

            <?php if ($extra > 0): ?>
                <div class="article__media"><?= c_gallery($t) ?></div>
            <?php elseif ($cover !== ''): ?>
                <figure class="article__cover"><img src="<?= e($cover) ?>" alt=""></figure>
            <?php endif; ?>

            <div class="prose prose--blocks prose--article"><?= thread_body_html($t) ?></div>

            <?php if ($categories): ?>
                <div class="row" style="margin-top:22px">
                    <span class="muted">Категории:</span>
                    <?php foreach ($categories as $c): ?>
                        <a href="<?= e(url('/kategoriya/' . rawurlencode($c['slug']))) ?>" class="badge"><?= e($c['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (! empty($t['ad_marking'])): ?>
                <p class="ad-marking"><?= e($t['ad_marking']) ?></p>
            <?php endif; ?>

            <footer class="article__foot">
                <?= c_temperature($t, my_vote('thread', (int) $t['id'])) ?>
                <a href="#comments" class="article__stat"><?= icon('comment') ?> <span data-comments-count><?= (int) $t['comments_count'] ?></span></a>
                <span class="article__stat article__stat--quiet" title="Просмотры"><?= icon('eye') ?> <?= (int) $t['views_count'] ?></span>
                <span class="spacer"></span>
                <?= c_report_form('thread', (int) $t['id']) ?>
            </footer>
        </article>
<?php else: ?>
            <div class="card"><div class="card__body">
                <div class="row" style="margin-bottom:14px">
                    <?= c_temperature($t, my_vote('thread', (int) $t['id'])) ?>
                    <span class="muted"><?= icon('comment') ?> <span data-comments-count><?= (int) $t['comments_count'] ?></span></span>
                    <span class="muted"><?= icon('eye') ?> <?= (int) $t['views_count'] ?></span>
                    <span class="spacer"></span>
                    <?php if (auth_id() === (int) $t['user_id'] || is_moderator()): ?>
                        <a href="<?= e(url('/post/' . rawurlencode($t['slug']) . '/redaktirovat')) ?>" class="btn btn--sm btn--ghost">
                            <?= icon('edit') ?> Редактировать
                        </a>
                    <?php endif; ?>

                    <?php if (auth_id()): ?>
                        <form method="POST" action="<?= e(url('/post/' . rawurlencode($t['slug']) . '/zakladka')) ?>"><?= csrf_field() ?>
                            <button class="btn btn--sm btn--ghost"><?= icon('bookmark') ?> <?= is_bookmarked((int) $t['id']) ? 'В закладках' : 'В закладки' ?></button>
                        </form>
                    <?php endif; ?>
                </div>

                <p class="muted" style="font-size:13px;margin:0">Опубликовано <?= e(ago($t['published_at'])) ?></p>
                <h1 style="font-size:24px;margin:8px 0 14px"><?= e($t['title']) ?></h1>

    <?php
                // Картинку показываем только когда она есть или тип коммерческий
                // (у скидки и промокода нужна цветная плашка-заглушка вместо неё).
                // Для блога без изображения пустой серый квадрат только съедал бы
                // половину карточки — вместо него текст сразу идёт во всю ширину.
                $hasOwnImage = ! empty($t['image_path']) || ! empty($t['image_url']);
                $showMedia   = $hasOwnImage || is_commercial($t['type']);
    ?>
                <div class="<?= $showMedia ? 'grid-2' : '' ?>" style="align-items:start">
                    <?php if ($showMedia): ?>
                        <?= c_gallery($t) ?>
                    <?php endif; ?>

                    <div>
                        <?= c_price($t) ?>

                        <?php if ($t['merchant_name']): ?>
                            <p style="margin:10px 0">
                                <a href="<?= e(url('/magazin/' . rawurlencode($t['merchant_slug']))) ?>" class="badge badge--merchant"><?= e($t['merchant_name']) ?></a>
                            </p>
                        <?php endif; ?>

                        <?php if ($t['type'] === 'voucher' && $t['code']): ?>
                            <div style="margin:14px 0"><?= c_code_box($t, true) ?></div>
                        <?php endif; ?>

                        <?php if ($t['url'] && ! $isExpired): ?>
                            <a href="<?= e(url('/go/' . rawurlencode($t['slug']))) ?>" target="_blank" rel="nofollow noopener"
                               class="btn btn--primary btn--block" style="margin-top:12px">Перейти в магазин &rarr;</a>
                        <?php elseif ($t['url']): ?>
                            <p class="muted" style="margin-top:12px;font-size:13px">
                                Срок предложения истёк, ссылка может больше не работать.
                            </p>
                        <?php endif; ?>

                        <div style="margin-top:16px">
                            <span class="muted" style="font-size:13px">Поделился(-ась)</span><br>
                            <?= c_user($t['author_name'], $t['author_avatar'], $t) ?>
                        </div>

                        <?php if (is_commercial($t['type'])): ?>
                            <?= c_share($t) ?>
                        <?php endif; ?>

                        <?= c_report_form('thread', (int) $t['id']) ?>
                    </div>
                </div>
            </div></div>

            <div class="card"><div class="card__body">
                <h2 class="card__title">Подробнее</h2>

                <?php // текст всегда через блоки: одинаковые отступы у старых и новых записей ?>
                <div class="prose prose--blocks"><?= thread_body_html($t) ?></div>

                <?php if ($categories): ?>
                    <div class="row" style="margin-top:16px">
                        <span class="muted">Категории:</span>
                        <?php foreach ($categories as $c): ?>
                            <a href="<?= e(url('/kategoriya/' . rawurlencode($c['slug']))) ?>" class="badge"><?= e($c['name']) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (! empty($t['ad_marking'])): ?>
                    <p class="ad-marking"><?= e($t['ad_marking']) ?></p>
                <?php endif; ?>

                <?php if ($t['group_name']): ?>
                    <p style="margin-top:14px">Раздел:
                        <a href="<?= e(url('/obsuzhdeniya/' . rawurlencode($t['group_slug']))) ?>" class="badge"><?= e($t['group_name']) ?></a>
                    </p>
                <?php endif; ?>
            </div></div>
<?php endif; ?>

        <div class="card" id="comments"><div class="card__body">
            <h2 class="card__title"><span data-comments-count><?= (int) $t['comments_count'] ?></span> комментариев</h2>

            <?php if (auth_id()): ?>
                <form method="POST" action="<?= e(url('/post/' . rawurlencode($t['slug']) . '/kommentarii')) ?>" style="margin-bottom:18px">
                    <?= csrf_field() ?>
                    <?= c_editor('body', '', 'bold italic underline strike link image emoji clear', 3, 'Написать комментарий…') ?>
                    <button class="btn btn--primary" style="margin-top:10px">Отправить</button>
                </form>
            <?php else: ?>
                <p class="muted" style="margin-bottom:18px"><a href="<?= e(url('/vhod')) ?>">Войдите</a>, чтобы оставить комментарий.</p>
            <?php endif; ?>

            <?php if (empty($comments[0])): ?>
                <p class="muted">Комментариев пока нет.</p>
            <?php endif; ?>

<?php
            $lastComment = 0;

            foreach ($comments as $group) {
                foreach ($group as $c) {
                    $lastComment = max($lastComment, (int) $c['id']);
                }
            }
?>
            <div data-comments data-slug="<?= e($t['slug']) ?>" data-last="<?= $lastComment ?>">
                <?= c_comments($comments, $t) ?>
            </div>
        </div></div>
    </div>

    <?php require __DIR__ . '/partials/sidebar.php'; ?>
</div>
