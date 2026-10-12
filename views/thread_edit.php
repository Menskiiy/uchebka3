<div class="page">
    <div class="card" style="max-width:720px;margin:0 auto"><div class="card__body">
        <div class="row" style="margin-bottom:14px">
            <h1 style="font-size:21px;margin:0;flex:1">Редактирование</h1>
            <a href="<?= e(thread_url($t)) ?>" class="btn btn--sm btn--ghost">Открыть публикацию</a>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert--error"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <p class="hint" style="margin-top:0">
            Тип публикации — <?= e(type_label($t['type'])) ?>. Адрес страницы не меняется,
            чтобы уже отправленные ссылки продолжали работать.
        </p>

        <?php if ($t['status'] === 'rejected' && ! empty($t['reject_reason'])): ?>
            <div class="notice notice--stop" style="margin-bottom:14px">
                <strong>Что просил исправить модератор:</strong>
                <p style="margin:8px 0 0"><?= e($t['reject_reason']) ?></p>
                <?php if (! empty($t['reject_link'])): ?>
                    <p style="margin:6px 0 0">
                        <a href="<?= e($t['reject_link']) ?>" target="_blank" rel="noopener">Правила оформления</a>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= e(url('/post/' . rawurlencode($t['slug']) . '/redaktirovat')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <?php if ($t['type'] !== 'blog'): ?>
                <div class="field">
                    <label for="title">Заголовок</label>
                    <input type="text" id="title" name="title" class="input" value="<?= e($old['title'] ?? $t['title']) ?>" required>
                </div>
            <?php endif; ?>

            <?php if ($t['type'] === 'voucher'): ?>
                <div class="field">
                    <label for="code">Промокод</label>
                    <input type="text" id="code" name="code" class="input" value="<?= e($t['code']) ?>"
                           data-code-check data-code-check-exclude="<?= (int) $t['id'] ?>" required>
                    <div class="code-check" data-code-check-result></div>
                </div>
            <?php endif; ?>

            <?php if (is_commercial($t['type'])): ?>
                <div class="field">
                    <label for="url">Ссылка на предложение</label>
                    <?php if (thread_url_editable($t)): ?>
                        <input type="url" id="url" name="url" class="input" value="<?= e($t['url']) ?>" placeholder="https://...">
                    <?php else: ?>
                        <input type="url" id="url" class="input" value="<?= e($t['url']) ?>" readonly disabled>
                        <div class="hint">
                            Ссылку у опубликованного предложения менять нельзя: её уже проверил модератор,
                            и люди идут именно по ней. Если ссылка устарела — напишите модератору,
                            он поправит.
                        </div>
                    <?php endif; ?>
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
            <?php endif; ?>

            <?php if ($t['type'] === 'discussion'): ?>
                <div class="field">
                    <label for="group_id">Раздел</label>
                    <select name="group_id" id="group_id" class="input">
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int) $g['id'] ?>" <?= (int) $t['group_id'] === (int) $g['id'] ? 'selected' : '' ?>>
                                <?= e($g['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="field">
                <?php if ($t['type'] !== 'blog'): ?><label>Описание</label><?php endif; ?>
                <?php
                // старые записи без блоков раскладываются на блоки при открытии
                $blocksJson  = blocks_editor_json($t, $old['body_json'] ?? null);
                $preset      = $t['type'] === 'blog' ? 'article' : 'offer';
                $placeholder = 'Описание…';

                if ($t['type'] === 'blog') {
                    $titleValue = $old['title'] ?? $t['title'];
                }

                require __DIR__ . '/partials/blocks-editor.php';
                unset($titleValue);
                ?>
            </div>

            <?php if (is_commercial($t['type']) || $t['type'] === 'blog'): ?>
                <div class="field">
                    <label>Изображение</label>
                    <?php if ($t['image_path'] || $t['image_url']): ?>
                        <div class="admin__preview"><?= c_thumb($t) ?></div>
                        <label class="check" style="margin-bottom:8px">
                            <input type="checkbox" name="remove_image" value="1"> Удалить изображение
                        </label>
                    <?php endif; ?>
                    <input type="url" name="image_url" class="input" value="<?= e($t['image_url']) ?>" placeholder="URL изображения">
                    <div class="hint">или загрузите файл:</div>
                    <input type="file" name="image" accept="image/*" style="margin-top:8px"
                           data-max-size="<?= upload_limit_bytes() ?>">

                    <?php $shots = q('SELECT id, path FROM thread_images WHERE thread_id = ? ORDER BY position, id', [$t['id']]); ?>

                    <div class="hint" style="margin-top:14px">Дополнительные снимки</div>

                    <?php if ($shots): ?>
                        <div class="gallery__thumbs" style="margin-top:6px">
                            <?php foreach ($shots as $shot): ?>
                                <label class="gallery__thumb gallery__thumb--drop" title="Отметьте, чтобы удалить">
                                    <img src="<?= e(url('/storage/' . $shot['path'])) ?>" alt="">
                                    <input type="checkbox" name="drop_images[]" value="<?= (int) $shot['id'] ?>">
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <input type="file" name="images[]" accept="image/*" multiple style="margin-top:8px"
                           data-max-size="<?= upload_limit_bytes() ?>">
                    <div class="hint">
                        Новые снимки добавляются к существующим, отмеченные галочкой — удаляются.
                        Всего не больше восьми.
                    </div>
                </div>
            <?php endif; ?>

            <?php if (is_commercial($t['type'])): ?>
                <?php if ($t['type'] === 'deal'): ?>
                <div class="grid-2">
                    <div class="field">
                        <label for="price">Цена, ₽</label>
                        <input type="number" step="0.01" id="price" name="price" class="input" value="<?= e($t['price']) ?>">
                    </div>
                    <div class="field">
                        <label for="old_price">Цена в других магазинах, ₽</label>
                        <input type="number" step="0.01" id="old_price" name="old_price" class="input" value="<?= e($t['old_price']) ?>">
                    </div>
                    <div class="field">
                        <label for="discount_percent">Процент скидки</label>
                        <input type="number" id="discount_percent" name="discount_percent" class="input" value="<?= e($t['discount_percent']) ?>">
                    </div>
                </div>
                <?php endif; ?>

                <div class="field">
                    <label for="expires_at">Действует до</label>
                    <input type="date" id="expires_at" name="expires_at" class="input"
                           value="<?= e($t['expires_at'] ? date('Y-m-d', strtotime($t['expires_at'])) : '') ?>"
                           max="<?= e(date('Y-m-d', time() + 730 * 86400)) ?>">

                    <div class="date-presets">
                        <button type="button" class="btn btn--sm" data-days="1" data-target="expires_at">1 день</button>
                        <button type="button" class="btn btn--sm" data-days="7" data-target="expires_at">Неделя</button>
                        <button type="button" class="btn btn--sm" data-days="30" data-target="expires_at">Месяц</button>
                        <button type="button" class="btn btn--sm" data-days="" data-target="expires_at">Без срока</button>
                    </div>

                    <div class="hint">Прошедшую дату можно поставить, чтобы закрыть предложение вручную.</div>
                </div>

                <div class="field">
                    <label>Категории</label>
                    <?php require __DIR__ . '/partials/categories.php'; ?>
                </div>
            <?php endif; ?>


            <?php if (is_moderator()): ?>
                <div class="field">
                    <label for="ad_marking">Маркировка рекламы</label>
                    <input type="text" id="ad_marking" name="ad_marking" class="input"
                           value="<?= e($t['ad_marking'] ?? '') ?>"
                           placeholder="«Реклама», ООО «Яндекс», ИНН 7736207543, erid: ...">
                    <div class="hint">
                        Видно только модераторам. Текст выводится мелким шрифтом
                        внизу публикации, не мешая описанию.
                    </div>
                </div>
            <?php endif; ?>
            <div class="row" style="margin-top:20px">
                <a href="<?= e(thread_url($t)) ?>" class="btn btn--ghost">Отменить</a>
                <button class="btn btn--primary">Сохранить</button>
            </div>
        </form>
    </div></div>
</div>
