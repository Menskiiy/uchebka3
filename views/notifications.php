<div class="layout">
    <div>
        <div class="card" style="margin-bottom:14px"><div class="card__body">
            <div class="row">
                <h1 style="font-size:21px;margin:0;flex:1">Уведомления</h1>
                <?php if ($items): ?>
                    <form method="POST" action="<?= e(url('/uvedomleniya/prochitano')) ?>"><?= csrf_field() ?>
                        <button class="btn btn--sm">Отметить всё прочитанным</button>
                    </form>
                <?php endif; ?>
            </div>
        </div></div>

        <?php if (! $items): ?>
            <div class="card"><div class="empty">
                Пока тихо. Здесь появятся ответы на ваши публикации и комментарии.
            </div></div>
        <?php endif; ?>

        <?php if ($items): ?>
            <div class="card"><div class="card__body" style="padding:0">
                <?php foreach ($items as $n): ?>
                    <a href="<?= e(notification_url($n)) ?>" class="notif <?= empty($n['read_at']) ? 'is-unread' : '' ?>">
                        <span class="notif__icon"><?= notification_icon($n['type']) ?></span>
                        <span class="notif__body">
                            <span class="notif__text"><?= notification_text($n) ?></span>
                            <span class="notif__time"><?= e(ago($n['created_at'])) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div></div>
        <?php endif; ?>

        <?= c_pagination($pager) ?>
    </div>

    <?php require __DIR__ . '/partials/sidebar.php'; ?>
</div>
