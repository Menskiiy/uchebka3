<?php
/*
 * Подвал по макету: три колонки ссылок слева, подпись сайта справа.
 *
 * Раскладка задана прямо здесь, а не в app.css, чтобы правка приезжала
 * одним файлом и не требовала поднимать версию стилей. Колонки переносятся
 * сами за счёт flex-wrap — отдельного правила для телефона не нужно.
 *
 * Документы в подвале обязаны быть на каждой странице: на них ссылаются
 * формы регистрации и публикации, и человек должен находить их откуда
 * угодно, а не только из личного кабинета.
 */
$docs = legal_pages();
?>
<footer class="footer">
    <div class="container">
        <div class="row" style="justify-content:space-between;align-items:flex-start;gap:28px;flex-wrap:wrap">

            <div class="row" style="gap:44px;align-items:flex-start;flex-wrap:wrap">
                <div class="stack" style="gap:8px">
                    <strong style="font-size:13px">Правила</strong>
                    <?php foreach ($docs as $doc): ?>
                        <?php if ($doc['group'] === 'rules'): ?>
                            <a href="<?= e(url($doc['url'])) ?>"><?= e($doc['short']) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <a href="<?= e(url('/copyright-claims')) ?>">Правообладателям</a>
                    <button type="button" class="footer__link" data-cookie-settings>Настройки cookie</button>
                </div>

                <div class="stack" style="gap:8px">
                    <strong style="font-size:13px">Документы</strong>
                    <?php foreach ($docs as $doc): ?>
                        <?php if ($doc['group'] === 'main'): ?>
                            <a href="<?= e(url($doc['url'])) ?>"><?= e($doc['short']) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <a href="<?= e(url('/dokumenty')) ?>">Все документы</a>
                </div>

                <div class="stack" style="gap:8px">
                    <strong style="font-size:13px">Меню</strong>
                    <a href="<?= e(url('/kategorii')) ?>">Категории</a>
                    <a href="<?= e(url('/magaziny')) ?>">Магазины</a>
                    <a href="<?= e(url('/blogi')) ?>">Блоги</a>
                    <a href="<?= e(url('/obsuzhdeniya')) ?>">Обсуждения</a>
                    <a href="<?= e(url('/kak-eto-rabotaet')) ?>">Как это работает</a>
                </div>
            </div>

            <div style="max-width:340px;margin-left:auto;text-align:right">
                <p style="margin:0;color:var(--text)">
                    &copy; <?= date('Y') ?> <?= e(setting('site_name', cfg('app_name'))) ?>
                    — <?= e(setting('site_description', cfg('app_description'))) ?>
                </p>
            </div>

        </div>
    </div>
</footer>