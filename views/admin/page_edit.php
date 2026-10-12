<?php
/**
 * Редактор одной страницы. Текст правится тем же визуальным редактором,
 * что и публикации: заголовки, списки и ссылки в соглашении нужны,
 * а руками расставлять теги никто не будет.
 */
?>
<div class="admin__cols">
    <div>
        <div class="card"><div class="card__body">
            <form method="POST" action="<?= e(url('/admin/stranicy')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="key" value="<?= e($key) ?>">

                <div class="row" style="margin-bottom:12px">
                    <a href="<?= e(url('/admin/stranicy')) ?>" class="btn btn--sm btn--ghost">&#8249; Все страницы</a>
                    <span class="spacer"></span>
                    <a href="<?= e(url($info['url'])) ?>" class="btn btn--sm btn--ghost" target="_blank">Открыть на сайте</a>
                </div>

                <div class="field">
                    <label for="title">Заголовок</label>
                    <input type="text" id="title" name="title" class="input" value="<?= e($page['title']) ?>">
                </div>

                <div class="field">
                    <label>Текст</label>
                    <?= c_editor('body', $page['body'], 'h2 heading bold italic underline list numlist quote link unlink undo redo clear', 22) ?>
                </div>

                <div class="field">
                    <label for="updated">Дата редакции</label>
                    <input type="text" id="updated" name="updated" class="input" style="max-width:180px" value="<?= e($page['updated']) ?>">
                </div>

                <button class="btn btn--primary">Сохранить</button>
            </form>
        </div></div>
    </div>

    <div class="card admin__sticky"><div class="card__body">
        <h2 class="card__title">Что важно</h2>
        <p class="hint" style="margin-top:0">
            Дата редакции показывается на странице. Меняете текст по существу —
            поставьте новую дату, это и есть след изменений для пользователя.
        </p>
        <p class="hint">
            Старые тексты хранились простым текстом и выводятся как есть.
            Как только страница сохранена через этот редактор, она хранится
            разметкой — заголовки и списки останутся на месте.
        </p>
    </div></div>
</div>
