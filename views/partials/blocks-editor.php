<?php
/**
 * Блочный редактор публикации — Editor.js, как на vc.ru.
 *
 * Переменные:
 *   $blocksJson  — JSON для редактора (blocks_editor_json());
 *   $preset      — 'article' для блога: подзаголовки, списки, цитаты, фото,
 *                  разделитель; 'offer' для остального: без подзаголовков
 *                  и разделителя — описанию скидки они не нужны;
 *   $placeholder — подсказка в пустом первом абзаце;
 *   $titleValue  — если задано, поле «Заголовок» рисуется прямо в редакторе,
 *                  крупно и без рамки, как на vc.ru (name="title").
 *
 * Текст уходит на сервер скрытым полем body_json. Оно заполнено заранее,
 * поэтому, даже если скрипт редактора не загрузится, сохранение формы
 * ничего не сотрёт — просто не будет правок в тексте.
 */
$preset      = ($preset ?? 'article') === 'offer' ? 'offer' : 'article';
$placeholder = $placeholder ?? 'Начните писать…';
$hasTitle    = isset($titleValue);
?>
<div class="bed<?= $hasTitle ? ' bed--article' : '' ?>" data-blocks-editor
     data-preset="<?= e($preset) ?>"
     data-placeholder="<?= e($placeholder) ?>"
     data-upload-url="<?= e(url('/zagruzka')) ?>"
     data-upload-limit="<?= min(4 * 1024 * 1024, upload_limit_bytes()) ?>">

    <?php if ($hasTitle): ?>
        <textarea name="title" class="bed__title" rows="1" maxlength="180" required
                  placeholder="Заголовок" aria-label="Заголовок" data-bed-title><?= e((string) $titleValue) ?></textarea>
    <?php endif; ?>

    <div class="bed__holder" data-bed-holder></div>

    <input type="hidden" name="body_json" value="<?= e($blocksJson ?? '{"blocks":[]}') ?>" data-bed-field>

    <?php // смайлики — как в старом редакторе: вставляются туда, где стоял курсор ?>
    <div class="bed__bar">
        <button type="button" class="bed__emoji" data-bed-emoji aria-haspopup="dialog" aria-expanded="false">
            <?= icon('smile') ?> Смайлик
        </button>
        <div class="emoji-pick" hidden role="dialog" aria-label="Смайлики" data-bed-emoji-pick>
            <?php foreach (editor_emoji_set() as $emoji): ?>
                <button type="button" class="emoji-pick__item" data-emoji="<?= e($emoji) ?>"><?= $emoji ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <p class="bed__failed" data-bed-failed hidden>
        Редактор не загрузился — проверьте соединение и обновите страницу.
        Сохранить форму можно и сейчас: текст публикации останется прежним.
    </p>
</div>

<?php if (empty($GLOBALS['blocks_editor_assets'])): $GLOBALS['blocks_editor_assets'] = true; ?>
    <script src="<?= e(asset('/js/vendor/editorjs.bundle.js')) ?>" defer
            onerror="document.querySelectorAll('[data-bed-failed]').forEach(function(n){n.hidden=false})"></script>
    <script src="<?= e(asset('/js/blocks-editor.js')) ?>" defer></script>
<?php endif; ?>
