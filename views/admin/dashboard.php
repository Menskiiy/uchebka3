<div class="stat-grid">
    <a href="<?= e(url('/admin/zhaloby')) ?>" class="stat <?= $stats['open'] > 0 ? 'stat--alert' : '' ?>">
        <span class="stat__value" data-count="<?= (int) $stats['open'] ?>">0</span>
        <span class="stat__label">жалоб на рассмотрении</span>
    </a>
    <a href="<?= e(url('/admin/publikacii?status=pending')) ?>" class="stat <?= $stats['pending'] > 0 ? 'stat--alert' : '' ?>">
        <span class="stat__value" data-count="<?= (int) $stats['pending'] ?>">0</span>
        <span class="stat__label">ждут проверки</span>
    </a>
    <a href="<?= e(url('/admin/publikacii')) ?>" class="stat">
        <span class="stat__value" data-count="<?= (int) $stats['threads'] ?>">0</span>
        <span class="stat__label">всего публикаций</span>
    </a>
    <a href="<?= e(url('/admin/polzovateli')) ?>" class="stat">
        <span class="stat__value" data-count="<?= (int) $stats['users'] ?>">0</span>
        <span class="stat__label">пользователей</span>
    </a>
    <a href="<?= e(url('/admin/publikacii?status=rejected')) ?>" class="stat">
        <span class="stat__value" data-count="<?= (int) $stats['rejected'] ?>">0</span>
        <span class="stat__label">снятых публикаций</span>
    </a>
    <a href="<?= e(url('/admin/polzovateli?filter=banned')) ?>" class="stat">
        <span class="stat__value" data-count="<?= (int) $stats['banned'] ?>">0</span>
        <span class="stat__label">заблокировано</span>
    </a>
    <span class="stat">
        <span class="stat__value" data-count="<?= (int) $stats['threads_today'] ?>">0</span>
        <span class="stat__label">публикаций сегодня</span>
    </span>
</div>

<div class="admin__cols">
    <div class="card"><div class="card__body">
        <h2 class="card__title">Последние публикации</h2>
        <?php if (! $latest): ?><p class="muted">Пока пусто.</p><?php endif; ?>
        <?php foreach ($latest as $t): ?>
            <div class="row admin__row">
                <span class="badge"><?= e(type_label($t['type'])) ?></span>
                <a href="<?= e(url('/admin/publikacii/' . (int) $t['id'])) ?>" style="font-weight:600;flex:1;min-width:0">
                    <?= e(excerpt($t['title'], 60)) ?>
                </a>
                <span class="side-item__temp"><?= (int) $t['temperature'] ?>&deg;</span>
                <span class="muted" style="font-size:12px"><?= e(ago($t['created_at'])) ?></span>
            </div>
        <?php endforeach; ?>
    </div></div>

    <div class="card"><div class="card__body">
        <h2 class="card__title">Действия модераторов</h2>
        <?php if (! $log): ?><p class="muted">Действий пока не было.</p><?php endif; ?>
        <?php foreach ($log as $entry): ?>
            <div class="row admin__row">
                <span class="badge"><?= e(action_label($entry['action'])) ?></span>
                <span class="muted" style="flex:1"><?= e($entry['moderator_name'] ?? 'система') ?></span>
                <span class="muted" style="font-size:12px"><?= e(ago($entry['created_at'])) ?></span>
            </div>
        <?php endforeach; ?>
    </div></div>
</div>
