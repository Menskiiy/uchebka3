<div class="layout">
    <div>
        <div class="card" style="margin-bottom:14px"><div class="card__body">
            <div class="row" style="margin-bottom:4px">
                <h1 style="font-size:22px;margin:0;flex:1">Блоги</h1>
                <?php if (auth_id()): ?>
                    <a href="<?= e(url('/dobavit/blog')) ?>" class="btn btn--primary btn--sm">
                        <?= icon('plus') ?> Написать
                    </a>
                <?php endif; ?>
            </div>
            <p class="muted" style="margin:0;font-size:14px">
                Гайды, разборы кэшбэка и личный опыт — <?= (int) $total ?>
                <?= e(plural((int) $total, 'запись', 'записи', 'записей')) ?>.
            </p>
        </div></div>

        <?php if ($authors): ?>
            <div class="card" style="margin-bottom:14px"><div class="card__body">
                <h2 class="card__title">Топ блогеров</h2>

                <div class="bloggers">
                    <?php foreach ($authors as $i => $a): ?>
                        <a href="<?= e(url('/profile/' . rawurlencode($a['username']) . '/blog')) ?>" class="blogger">
                            <span class="blogger__place"><?= $i + 1 ?></span>
                            <span class="avatar blogger__avatar">
                                <?php if (! empty($a['avatar'])): ?>
                                    <img src="<?= e(avatar_url($a['avatar'])) ?>" alt="">
                                <?php else: ?>
                                    <?= e(mb_strtoupper(mb_substr($a['username'], 0, 1))) ?>
                                <?php endif; ?>
                            </span>
                            <span class="blogger__info">
                                <strong><?= e($a['username']) ?></strong>
                                <span class="muted">
                                    <?= (int) $a['posts'] ?> <?= e(plural((int) $a['posts'], 'запись', 'записи', 'записей')) ?>
                                </span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div></div>
        <?php endif; ?>

        <div class="feed-bar">
            <div class="tabs">
                <?php foreach (['hot' => 'Горячее', 'new' => 'Новое', 'discussed' => 'Обсуждаемое'] as $key => $label): ?>
                    <?php $qs = $_GET; $qs['tab'] = $key; unset($qs['page']); ?>
                    <a href="?<?= e(http_build_query($qs)) ?>" class="<?= $sort === $key ? 'is-active' : '' ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (! $result['items']): ?>
            <div class="card"><div class="empty">
                Записей пока нет.
                <?php if (auth_id()): ?>
                    <div style="margin-top:12px">
                        <a href="<?= e(url('/dobavit/blog')) ?>" class="btn btn--primary btn--sm">Написать первую</a>
                    </div>
                <?php endif; ?>
            </div></div>
        <?php endif; ?>

        <?php foreach ($result['items'] as $t) echo c_thread_card($t); ?>

        <?= c_pagination($result['pager']) ?>
    </div>

    <?php require __DIR__ . '/partials/sidebar.php'; ?>
</div>
