<div class="layout">
    <div>
        <div class="card merchant-head"><div class="card__body">
            <div class="merchant-head__top">
                <span class="merchant-head__logo" style="background:<?= e($merchant['logo_color'] ?? '#c9ced6') ?>">
                    <?php if (! empty($merchant['logo'])): ?>
                        <img src="<?= e(url('/storage/' . $merchant['logo'])) ?>" alt="<?= e($merchant['name']) ?>">
                    <?php else: ?>
                        <?= e(mb_substr($merchant['name'], 0, 6)) ?>
                    <?php endif; ?>
                </span>

                <div class="merchant-head__info">
                    <h1><?= e($heading) ?></h1>

                    <?php if (! empty($merchant['description'])): ?>
                        <p class="merchant-head__desc"><?= e($merchant['description']) ?></p>
                    <?php endif; ?>

                    <div class="row" style="margin-top:10px">
                        <form method="POST" action="<?= e(url('/magazin/' . rawurlencode($merchant['slug']) . '/like')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn--sm merchant-like <?= $liked ? 'is-active' : '' ?>"
                                    <?= auth_id() ? '' : 'disabled title="Войдите, чтобы отметить магазин"' ?>>
                                <?= icon('like') ?>
                                <span><?= (int) $merchant['likes_count'] ?></span>
                                <span class="no-mobile"><?= $liked ? 'Нравится' : 'Нравится' ?></span>
                            </button>
                        </form>

                        <?php if (auth_id()): ?>
                            <form method="POST" action="<?= e(url('/podpiska')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="type" value="merchant">
                                <input type="hidden" name="id" value="<?= (int) $merchant['id'] ?>">
                                <?php $subscribed = is_following('merchant', (int) $merchant['id']); ?>
                                <button class="btn btn--sm <?= $subscribed ? 'merchant-like is-active' : '' ?>">
                                    <?= icon($subscribed ? 'check' : 'bell') ?>
                                    <span class="no-mobile"><?= $subscribed ? 'Вы подписаны' : 'Подписаться' ?></span>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if (! empty($merchant['domain'])): ?>
                            <span class="muted" style="font-size:13px"><?= e($merchant['domain']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="tabs merchant-tabs">
                <?php foreach ($typeTabs as $key => $label): ?>
                    <?php $qs = $_GET; $qs['vid'] = $key; unset($qs['page']); ?>
                    <a href="?<?= e(http_build_query($qs)) ?>" class="<?= $vid === $key ? 'is-active' : '' ?>">
                        <?= e($label) ?> (<?= (int) $counts[$key] ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        </div></div>

        <div class="feed-bar">
            <div class="tabs">
                <?php foreach (['hot' => 'Горячее', 'new' => 'Новое', 'discussed' => 'Обсуждаемое'] as $key => $label): ?>
                    <?php $qs = $_GET; $qs['tab'] = $key; unset($qs['page']); ?>
                    <a href="?<?= e(http_build_query($qs)) ?>" class="<?= $sort === $key ? 'is-active' : '' ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>

            <?php if (! empty($filters)) { require __DIR__ . '/partials/filters.php'; } ?>
        </div>

        <?php if (! $result['items']): ?>
            <div class="card"><div class="empty">Для этого магазина пока нет публикаций.</div></div>
        <?php endif; ?>

        <?php foreach ($result['items'] as $t) echo c_thread_card($t); ?>

        <?= c_pagination($result['pager']) ?>
    </div>

    <?php require __DIR__ . '/partials/sidebar.php'; ?>
</div>
