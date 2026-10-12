<?php
/**
 * Перечень страниц сайта. Сама правка — на отдельном экране: две
 * простыни юридического текста на одной странице листать невозможно.
 */
?>
<div class="admin__cols">
    <div>
        <div class="card"><div class="card__body" style="padding:0">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Страница</th>
                        <th style="width:130px">Редакция</th>
                        <th style="width:110px">Объём</th>
                        <th style="width:190px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pages as $page): ?>
                    <tr>
                        <td class="td--main">
                            <a href="<?= e(url('/admin/stranicy/' . $page['key'])) ?>" style="font-weight:600">
                                <?= e($page['title']) ?>
                            </a>
                            <div class="muted" style="font-size:12px"><?= e($page['label']) ?></div>
                        </td>
                        <td data-label="Редакция" class="muted"><?= e($page['updated']) ?></td>
                        <td data-label="Объём" class="muted"><?= (int) $page['length'] ?> симв.</td>
                        <td class="td--actions">
                            <div class="row" style="gap:6px">
                                <a href="<?= e(url('/admin/stranicy/' . $page['key'])) ?>" class="btn btn--sm btn--primary">
                                    Редактировать
                                </a>
                                <a href="<?= e(url($page['url'])) ?>" class="btn btn--sm btn--ghost" target="_blank">Открыть</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>

    <div class="card admin__sticky"><div class="card__body">
        <h2 class="card__title">Про эти тексты</h2>
        <p class="hint" style="margin-top:0">
            В полях лежат заготовки, написанные под этот сайт: сообщество скидок,
            публикации от пользователей, партнёрские ссылки, аналитика.
        </p>
        <p class="hint">
            Это <strong>болванки, а не юридический документ</strong>. Перед запуском
            на реальную аудиторию их стоит показать юристу — особенно если планируется
            собирать платежи или работать с рекламодателями.
        </p>
        <p class="hint">
            Ссылки на обе страницы уже стоят в подвале сайта и в баннере про cookie.
        </p>
    </div></div>
</div>
