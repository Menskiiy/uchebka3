<form method="POST" action="<?= e(url('/admin/publikacii/' . (int) $t['id'])) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="admin__cols">
        <div class="card"><div class="card__body">
            <h2 class="card__title">Содержимое</h2>

            <div class="field">
                <label for="title">Заголовок</label>
                <input type="text" id="title" name="title" class="input" value="<?= e($t['title']) ?>" required>
            </div>

            <div class="field">
                <label>Описание</label>
                <?php
                // модератору — полный набор блоков при любом типе публикации
                $blocksJson  = blocks_editor_json($t);
                $preset      = 'article';
                $placeholder = 'Описание…';
                require __DIR__ . '/../partials/blocks-editor.php';
                ?>
            </div>

            <div class="field">
                <label for="url">Ссылка</label>
                <input type="url" id="url" name="url" class="input" value="<?= e($t['url']) ?>" placeholder="https://...">
                <div class="hint">Магазин определится по домену автоматически.</div>
            </div>

            <div class="field">
                <label for="code">Промокод</label>
                <input type="text" id="code" name="code" class="input" value="<?= e($t['code']) ?>"
                       data-code-check data-code-check-exclude="<?= (int) $t['id'] ?>">
                <div class="code-check" data-code-check-result></div>
            </div>

            <div class="field">
                <label for="merchant_name">Магазин</label>
                <input type="text" id="merchant_name" name="merchant_name" class="input"
                       list="merchants-list" autocomplete="off"
                       value="<?= e($t['merchant_name'] ?? '') ?>"
                       placeholder="Начните вводить название">
                <datalist id="merchants-list">
                    <?php foreach ($merchants as $m): ?>
                        <option value="<?= e($m['name']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <div class="hint">
                    Оставьте пустым — определим по ссылке. Для партнёрских ссылок
                    выберите магазин из подсказок.
                </div>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="price">Цена, ₽</label>
                    <input type="number" step="0.01" id="price" name="price" class="input" value="<?= e($t['price']) ?>">
                </div>
                <div class="field">
                    <label for="old_price">Старая цена, ₽</label>
                    <input type="number" step="0.01" id="old_price" name="old_price" class="input" value="<?= e($t['old_price']) ?>">
                </div>
                <div class="field">
                    <label for="discount_percent">Скидка, %</label>
                    <input type="number" id="discount_percent" name="discount_percent" class="input" value="<?= e($t['discount_percent']) ?>">
                </div>
            </div>

            <div class="field">
                <label for="ad_marking">Маркировка рекламы</label>
                <input type="text" id="ad_marking" name="ad_marking" class="input"
                       value="<?= e($t['ad_marking'] ?? '') ?>"
                       placeholder="«Реклама», ООО «Яндекс», ИНН 7736207543, erid: ...">
                <div class="hint">Выводится мелким шрифтом внизу публикации.</div>
            </div>

            <div class="field">
                <label>Изображение</label>
                <?php if ($t['image_path'] || $t['image_url']): ?>
                    <div class="admin__preview"><?= c_thumb($t) ?></div>
                <?php endif; ?>
                <input type="url" name="image_url" class="input" value="<?= e($t['image_url']) ?>" placeholder="URL изображения">
                <div class="hint">или загрузите файл:</div>
                <input type="file" name="image" accept="image/*" style="margin-top:8px">
            </div>
        </div></div>

        <div>
            <div class="card"><div class="card__body">
                <h2 class="card__title">Публикация</h2>

                <div class="field">
                    <label for="status">Статус</label>
                    <select name="status" id="status" class="input">
                        <?php foreach (['published', 'rejected', 'expired', 'draft'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="type">Тип</label>
                    <select name="type" id="type" class="input">
                        <?php foreach (cfg('thread_types') as $key => $typeMeta): ?>
                            <option value="<?= e($key) ?>" <?= $t['type'] === $key ? 'selected' : '' ?>><?= e($typeMeta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="group_id">Раздел обсуждений</label>
                    <select name="group_id" id="group_id" class="input">
                        <option value="">—</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int) $g['id'] ?>" <?= (int) $t['group_id'] === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="expires_at">Действует до</label>
                    <input type="date" id="expires_at" name="expires_at" class="input"
                           value="<?= e($t['expires_at'] ? date('Y-m-d', strtotime($t['expires_at'])) : '') ?>">
                </div>

                <div class="field">
                    <label>Категории</label>
                    <?php require __DIR__ . '/../partials/categories.php'; ?>
                </div>

                <button class="btn btn--primary btn--block">Сохранить</button>
            </div></div>

            <div class="card"><div class="card__body">
                <h2 class="card__title">Сведения</h2>
                <div class="admin__meta">
                    <span>Автор</span><strong><?= e($t['author_name']) ?></strong>
                    <span>Градус</span><strong><?= (int) $t['temperature'] ?>&deg;</strong>
                    <span>Просмотры</span><strong><?= (int) $t['views_count'] ?></strong>
                    <span>Переходы</span><strong><?= (int) $t['clicks_count'] ?></strong>
                    <span>Комментарии</span><strong><?= (int) $t['comments_count'] ?></strong>
                    <span>Создано</span><strong><?= e(date('d.m.Y H:i', strtotime($t['created_at']))) ?></strong>
                    <span>Адрес</span><strong style="word-break:break-all"><?= e($t['slug']) ?></strong>
                    <span>Проверено</span>
                    <strong>
                        <?php if ($t['verified_at']): ?>
                            <span style="color:var(--green)"><?= e(date('d.m.Y', strtotime($t['verified_at']))) ?></span>
                        <?php else: ?>
                            <span class="muted">нет</span>
                        <?php endif; ?>
                    </strong>
                </div>

                <button type="submit" form="thread-verify"
                        class="btn btn--block <?= $t['verified_at'] ? '' : 'btn--primary' ?>" style="margin-top:12px">
                    <?= icon('shield') ?>
                    <?= $t['verified_at'] ? 'Снять отметку о проверке' : 'Отметить проверенной' ?>
                </button>
                <p class="hint">Адрес страницы не меняется при редактировании — иначе сломаются уже опубликованные ссылки.</p>
            </div></div>

            <div class="card"><div class="card__body">
                <h2 class="card__title" style="color:#c62828">Опасная зона</h2>
                <?php /* кнопка ссылается на форму ниже: форму нельзя вложить в форму —
                         браузер выбрасывает внутреннюю, и кнопка отправляла бы сохранение */ ?>
                <button type="submit" form="thread-delete" class="btn btn--block btn--danger">
                    <?= icon('trash') ?> Удалить публикацию
                </button>
            </div></div>
        </div>
    </div>
</form>

<form method="POST" id="thread-delete"
      action="<?= e(url('/admin/publikacii/' . (int) $t['id'] . '/udalit')) ?>"
      onsubmit="return confirm('Удалить публикацию безвозвратно? Комментарии и голоса тоже пропадут.')">
    <?= csrf_field() ?>
</form>

<form method="POST" id="thread-verify" action="<?= e(url('/admin/publikacii/' . (int) $t['id'] . '/proverit')) ?>">
    <?= csrf_field() ?>
</form>
