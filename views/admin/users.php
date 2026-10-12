<div class="card" style="margin-bottom:14px"><div class="card__body">
    <form method="GET" class="row">
        <input type="search" name="q" value="<?= e($term) ?>" class="input" style="flex:1;min-width:180px" placeholder="Логин или e-mail">
        <select name="filter" class="input" style="width:auto">
            <option value="">Все</option>
            <option value="banned" <?= $filter === 'banned' ? 'selected' : '' ?>>Заблокированные</option>
            <option value="moderators" <?= $filter === 'moderators' ? 'selected' : '' ?>>Модераторы</option>
            <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>С публикациями</option>
        </select>
        <select name="sort" class="input" style="width:auto">
            <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>Сначала новые</option>
            <option value="reputation" <?= $sort === 'reputation' ? 'selected' : '' ?>>По репутации</option>
            <option value="threads" <?= $sort === 'threads' ? 'selected' : '' ?>>По публикациям</option>
        </select>
        <button class="btn btn--primary">Фильтр</button>
        <a href="<?= e(url('/admin/polzovateli')) ?>" class="btn">Сбросить</a>
    </form>
</div></div>

<div class="card"><div class="card__body" style="padding:0">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Пользователь</th>
                <th style="width:120px">Репутация</th>
                <th style="width:90px">Постов</th>
                <th style="width:110px">Комментов</th>
                <th style="width:110px">Регистрация</th>
                <th style="width:300px"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (! $users): ?>
            <tr><td colspan="6" class="empty">Никого не найдено.</td></tr>
        <?php endif; ?>

        <?php foreach ($users as $u): $banned = is_banned($u); ?>
            <tr>
                <td class="td--main">
                    <div class="row" style="gap:8px;flex-wrap:nowrap">
                        <span class="avatar">
                            <?php if ($u['avatar']): ?><img src="<?= e(url('/storage/' . $u['avatar'])) ?>" alt="">
                            <?php else: ?><?= e(initials($u)) ?><?php endif; ?>
                        </span>
                        <div style="min-width:0">
                            <a href="<?= e(url('/profile/' . rawurlencode($u['username']))) ?>" target="_blank" style="font-weight:600"><?= e($u['username']) ?></a>
                            <?php if ((int) $u['is_moderator'] === 1): ?><span class="badge badge--merchant">модератор</span><?php endif; ?>
                            <?php if ((int) ($u['is_editor'] ?? 0) === 1 && (int) $u['is_moderator'] !== 1): ?><span class="badge">редактор</span><?php endif; ?>
                            <?php if ($banned): ?><span class="badge badge--expired">заблокирован</span><?php endif; ?>
                            <?php if (is_bot_account($u)): ?><span class="badge">бот</span><?php endif; ?>
                            <div class="muted" style="font-size:12px"><?= e($u['email']) ?></div>
                        </div>
                    </div>
                </td>
                <td data-label="Репутация"><?= (int) $u['reputation'] ?> <span class="muted" style="font-size:12px"><?= e(rank_for((int) $u['reputation'])['title']) ?></span></td>
                <td data-label="Публикаций" class="muted"><?= (int) $u['threads_count'] ?></td>
                <td data-label="Комментов" class="muted"><?= (int) $u['comments_count'] ?></td>
                <td data-label="Регистрация" class="muted" style="font-size:12px"><?= e(date('d.m.Y', strtotime($u['created_at']))) ?></td>
                <td class="td--actions">
                    <?php if (is_bot_account($u)): ?>
                        <div class="row" style="gap:6px;margin-bottom:6px">
                            <a href="<?= e(url('/admin/polzovateli/' . (int) $u['id'] . '/bot')) ?>"
                               class="btn btn--sm btn--primary">Редактировать</a>

                            <form method="POST" action="<?= e(url('/admin/polzovateli/' . (int) $u['id'] . '/tehnicheskiy')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn--sm btn--ghost">Не бот</button>
                            </form>
                        </div>
                    <?php elseif ((int) $u['id'] !== auth_id() && (int) $u['is_moderator'] !== 1): ?>
                        <form method="POST" action="<?= e(url('/admin/polzovateli/' . (int) $u['id'] . '/tehnicheskiy')) ?>"
                              style="margin-bottom:6px">
                            <?= csrf_field() ?>
                            <button class="btn btn--sm btn--ghost">Сделать ботом</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($banned): ?>
                        <?php if ($u['ban_reason']): ?>
                            <div class="muted" style="font-size:12px;margin-bottom:6px">Причина: <?= e($u['ban_reason']) ?></div>
                        <?php endif; ?>
                        <form method="POST" action="<?= e(url('/admin/polzovateli/' . (int) $u['id'] . '/razblokirovat')) ?>"><?= csrf_field() ?>
                            <button class="btn btn--sm btn--primary">Снять блокировку</button>
                        </form>
                    <?php elseif ((int) $u['id'] !== auth_id()): ?>
                        <div class="row" style="gap:6px">
                            <?php if ((int) $u['is_moderator'] !== 1): ?>
                                <form method="POST" action="<?= e(url('/admin/polzovateli/' . (int) $u['id'] . '/blokirovat')) ?>" class="row" style="gap:6px">
                                    <?= csrf_field() ?>
                                    <input type="text" name="reason" class="input" style="width:130px" placeholder="Причина" required>
                                    <input type="number" name="days" class="input" style="width:70px" placeholder="дней" min="1">
                                    <button class="btn btn--sm">Бан</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" action="<?= e(url('/admin/polzovateli/' . (int) $u['id'] . '/prava')) ?>"><?= csrf_field() ?>
                                <button class="btn btn--sm btn--ghost">
                                    <?= (int) $u['is_moderator'] === 1 ? 'Снять модератора' : 'Сделать модератором' ?>
                                </button>
                            </form>
                            <?php if ((int) $u['is_moderator'] !== 1): ?>
                                <form method="POST" action="<?= e(url('/admin/polzovateli/' . (int) $u['id'] . '/redaktor')) ?>"><?= csrf_field() ?>
                                    <button class="btn btn--sm btn--ghost">
                                        <?= (int) ($u['is_editor'] ?? 0) === 1 ? 'Снять редактора' : 'Сделать редактором' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="muted" style="font-size:12px">это вы</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<?= c_pagination($result['pager']) ?>
