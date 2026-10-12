<div class="card" style="margin-bottom:14px"><div class="card__body">
    <form method="GET" class="row">
        <input type="search" name="q" value="<?= e($term) ?>" class="input" style="flex:1;min-width:180px" placeholder="Заголовок или промокод">
        <select name="type" class="input" style="width:auto">
            <option value="">Все типы</option>
            <?php foreach (cfg('thread_types') as $key => $typeMeta): ?>
                <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($typeMeta['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="input" style="width:auto">
            <option value="">Все статусы</option>
            <?php foreach (['pending', 'published', 'rejected', 'expired'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="author" value="<?= e($author) ?>" class="input" style="width:150px" placeholder="Автор">
        <button class="btn btn--primary">Фильтр</button>
        <a href="<?= e(url('/admin/publikacii')) ?>" class="btn">Сбросить</a>
    </form>
</div></div>

<div class="card"><div class="card__body" style="padding:0">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Публикация</th>
                <th style="width:110px">Тип</th>
                <th style="width:110px">Автор</th>
                <th style="width:80px">Градус</th>
                <th style="width:110px">Статус</th>
                <th style="width:210px"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (! $result['items']): ?>
            <tr><td colspan="6" class="empty">Ничего не найдено.</td></tr>
        <?php endif; ?>

        <?php foreach ($result['items'] as $t): ?>
            <tr>
                <td class="td--main">
                    <a href="<?= e(url('/admin/publikacii/' . (int) $t['id'])) ?>" style="font-weight:600"><?= e($t['title']) ?></a>
                    <div class="muted" style="font-size:12px">
                        <?= e(ago($t['created_at'])) ?>
                        <?php if ($t['code']): ?> · код: <code><?= e($t['code']) ?></code><?php endif; ?>
                        <?php if ((int) $t['reports_count'] > 0): ?>
                            · <span style="color:#c62828">жалоб: <?= (int) $t['reports_count'] ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td data-label="Тип"><span class="badge"><?= e(type_label($t['type'])) ?></span></td>
                <td data-label="Автор" class="muted"><?= e($t['author_name']) ?></td>
                <td data-label="Градус"><strong style="color:var(--brand)"><?= (int) $t['temperature'] ?>&deg;</strong></td>
                <td data-label="Статус"><span class="badge <?= $t['status'] === 'rejected' ? 'badge--expired' : '' ?>"><?= e(status_label($t['status'])) ?></span></td>
                <td class="td--actions">
                    <div class="row" style="gap:6px">
                        <a href="<?= e(url('/admin/publikacii/' . (int) $t['id'])) ?>" class="btn btn--sm">Изменить</a>
                        <a href="<?= e(url('/post/' . rawurlencode($t['slug']))) ?>" class="btn btn--sm btn--ghost" target="_blank">Открыть</a>
                        <form method="POST" action="<?= e(url('/admin/publikacii/' . (int) $t['id'] . '/proverit')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn--sm <?= $t['verified_at'] ? 'btn--primary' : '' ?>"
                                    title="<?= $t['verified_at'] ? 'Снять отметку' : 'Отметить проверенной' ?>">
                                <?= icon('shield') ?>
                            </button>
                        </form>
                        <?php if ($t['status'] === 'published'): ?>
                            <details class="mod-reject">
                                <summary class="btn btn--sm">Снять</summary>
                                <?php require __DIR__ . '/_reject_form.php'; ?>
                            </details>
                        <?php else: ?>
                            <form method="POST" action="<?= e(url('/admin/publikacii/' . (int) $t['id'] . '/vernut')) ?>"><?= csrf_field() ?>
                                <button class="btn btn--sm btn--primary">
                                    <?= $t['status'] === 'pending' ? 'Одобрить' : 'Вернуть' ?>
                                </button>
                            </form>

                            <?php if ($t['status'] === 'pending'): ?>
                                <details class="mod-reject">
                                    <summary class="btn btn--sm">Отклонить</summary>
                                    <?php require __DIR__ . '/_reject_form.php'; ?>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($t['status'] === 'rejected' && ! empty($t['reject_reason'])): ?>
                        <div class="muted" style="font-size:12px;margin-top:6px">
                            Отклонено: <?= e($t['reject_reason']) ?>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<?= c_pagination($result['pager']) ?>
