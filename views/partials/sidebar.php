<?php $side = sidebar_data(); ?>
<aside class="sidebar">

    <?php if ($side['fresh']): ?>
        <div class="card">
            <div class="card__body">
                <h3 class="card__title">Новые скидки</h3>
                <?php foreach ($side['fresh'] as $t): ?>
                    <a href="<?= e(thread_url($t)) ?>" class="side-item">
                        <span class="side-item__thumb"><?= c_thumb($t) ?></span>
                        <span class="side-item__title">
                            <?= e(excerpt($t['title'], 52)) ?>
                            <br><span class="muted" style="font-size:12px">
                                <?= e(ago($t['published_at'])) ?><?= $t['merchant_name'] ? ' · ' . e($t['merchant_name']) : '' ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($side['blogs']): ?>
        <div class="card">
            <div class="card__body">
                <h3 class="card__title">Новое в блогах</h3>
                <?php foreach ($side['blogs'] as $t): ?>
                    <a href="<?= e(thread_url($t)) ?>" class="side-item side-item--blog">
                        <span class="side-item__thumb"><?= c_thumb($t) ?></span>
                        <span class="side-item__title">
                            <?= e(excerpt($t['title'], 52)) ?>
                            <br><span class="muted" style="font-size:12px"><?= e($t['author_name']) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($side['comments']): ?>
        <div class="card">
            <div class="card__body">
                <h3 class="card__title">Свежие комментарии</h3>
                <?php foreach ($side['comments'] as $c): ?>
                    <a href="<?= e(url('/post/' . rawurlencode($c['thread_slug'])) . '#comment-' . (int) $c['id']) ?>" class="side-item side-item--comment">
                        <span class="side-item__thumb side-item__thumb--avatar">
                            <?php if (! empty($c['author_avatar'])): ?>
                                <img src="<?= e(avatar_url($c['author_avatar'])) ?>" alt="">
                            <?php else: ?>
                                <?= e(mb_strtoupper(mb_substr($c['author_name'], 0, 1))) ?>
                            <?php endif; ?>
                        </span>
                        <span class="side-item__title">
                            <strong><?= e($c['author_name']) ?></strong>: <?= e(excerpt($c['body'], 60)) ?>
                            <br><span class="muted" style="font-size:12px">к «<?= e(excerpt($c['thread_title'], 30)) ?>»</span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($side['discussions']): ?>
        <div class="card">
            <div class="card__body">
                <div class="row" style="margin-bottom:8px">
                    <h3 class="card__title" style="margin:0;flex:1">Обсуждения</h3>
                    <a href="<?= e(url('/obsuzhdeniya')) ?>" class="muted" style="font-size:12px">Все</a>
                </div>

                <?php foreach ($side['discussions'] as $t): ?>
                    <a href="<?= e(thread_url($t)) ?>" class="side-item side-item--talk">
                        <span class="side-item__thumb side-item__thumb--talk"><?= icon('discussion') ?></span>
                        <span class="side-item__title">
                            <?= e(excerpt($t['title'], 52)) ?>
                            <br><span class="muted" style="font-size:12px">
                                <?= (int) $t['comments_count'] ?>
                                <?= e(plural((int) $t['comments_count'], 'ответ', 'ответа', 'ответов')) ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card__body">
            <h3 class="card__title">Топ магазины</h3>
            <div class="merchant-grid">
                <?php foreach ($side['top'] as $m): ?>
                    <a href="<?= e(url('/magazin/' . rawurlencode($m['slug']))) ?>"
                       class="merchant-chip <?= ! empty($m['logo']) ? 'merchant-chip--logo' : '' ?>"
                       style="background:<?= e(! empty($m['logo']) ? '#fff' : $m['logo_color']) ?>"
                       title="<?= e($m['name']) ?>">
                        <?php if (! empty($m['logo'])): ?>
                            <img src="<?= e(url('/storage/' . $m['logo'])) ?>" alt="<?= e($m['name']) ?>">
                        <?php else: ?>
                            <?= e(mb_substr($m['name'], 0, 9)) ?>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</aside>
