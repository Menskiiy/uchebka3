<?php
/**
 * Компактный редактор. $name — имя поля, $value — текущий HTML,
 * $tools — набор кнопок, $rows — высота.
 *
 * Возможностей намеренно мало: чем меньше свободы, тем меньше шансов,
 * что чужой пост развалит вёрстку на телефоне.
 */
$tools = $tools ?? 'bold italic underline strike link';
$rows  = $rows ?? 8;
$id    = 'ed-' . preg_replace('/[^a-z0-9]/i', '', $name);

$buttons = [
    'h2'        => ['H2',     'Крупный заголовок'],
    'heading'   => ['H3',     'Подзаголовок'],
    'bold'      => ['B',      'Жирный'],
    'italic'    => ['I',      'Курсив'],
    'underline' => ['U',      'Подчёркнутый'],
    'strike'    => ['S',      'Зачёркнутый'],
    'list'      => ['•',      'Маркированный список'],
    'numlist'   => ['1.',     'Нумерованный список'],
    'quote'     => ['«»',     'Цитата'],
    'link'      => ['link',   'Ссылка'],
    'unlink'    => ['unlink', 'Убрать ссылку'],
    'image'     => ['image',  'Картинка'],
    'undo'      => ['undo',   'Отменить'],
    'redo'      => ['redo',   'Вернуть'],
    'emoji'     => ['emoji',  'Смайлик'],
    'clear'     => ['clear',  'Убрать оформление'],
];

// Ровно столько, сколько помещается в панель целиком: сорок штук не влезали
// и панель обзаводилась полосой прокрутки — листать смайлики внутри выпадашки
// неудобно, проще не показывать лишнего. Полный набор всё равно даёт
// системная клавиатура, а на компьютере — Win+. и Ctrl+Cmd+Space.
$emojiSet = editor_emoji_set();
?>
<div class="editor" data-editor>
    <div class="editor__tools">
        <?php foreach (explode(' ', $tools) as $tool): ?>
            <?php if (! isset($buttons[$tool])) continue; ?>
            <?php [$label, $title] = $buttons[$tool]; ?>
            <button type="button" class="editor__btn editor__btn--<?= e($tool) ?>"
                    data-cmd="<?= e($tool) ?>" title="<?= e($title) ?>" tabindex="-1">
                <?php if ($tool === 'emoji'): ?><?= icon('smile') ?>
                <?php elseif ($tool === 'undo'): ?>&#8630;
                <?php elseif ($tool === 'redo'): ?>&#8631;
                <?php elseif ($tool === 'unlink'): ?>&#9007;
                <?php elseif ($tool === 'link'): ?><?= icon('share') ?>
                <?php elseif ($tool === 'image'): ?><?= icon('picture') ?>
                <?php elseif ($tool === 'clear'): ?><?= icon('eraser') ?>
                <?php else: ?><?= e($label) ?><?php endif; ?>
            </button>
        <?php endforeach; ?>

        <?php if (str_contains($tools, 'emoji')): ?>
            <div class="emoji-pick" hidden role="dialog" aria-label="Смайлики">
                <?php foreach ($emojiSet as $emoji): ?>
                    <button type="button" class="emoji-pick__item" data-emoji="<?= e($emoji) ?>"><?= $emoji ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="editor__area input" contenteditable="true" role="textbox" aria-multiline="true"
         id="<?= e($id) ?>" data-placeholder="<?= e($placeholder ?? 'Начните писать…') ?>"
         style="min-height:<?= (int) $rows * 24 ?>px"><?= render_body($value ?? '') ?></div>

    <textarea name="<?= e($name) ?>" hidden><?= e($value ?? '') ?></textarea>
</div>
