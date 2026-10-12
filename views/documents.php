<?php
/**
 * Раздел «Документы»: один адрес, с которого видно все правовые тексты.
 * На него ссылаются подвал, личный кабинет и формы — человеку не нужно
 * помнить одиннадцать адресов, а нам есть куда поставить одну ссылку.
 */
$groups = [
    'main'      => ['Основные документы', 'Как мы работаем с вашими данными и на каких условиях пользуетесь сайтом.'],
    'rules'     => ['Правила сайта', 'Что можно публиковать, как начисляются «Огоньки» и за что ограничивают аккаунт.'],
    'copyright' => ['Правообладателям', 'Порядок обращения, если материал нарушает ваши права.'],
];
?>
<div class="page">
    <div class="card"><div class="card__body">
        <h1 style="font-size:24px;margin:0 0 6px">Документы и правовая информация</h1>
        <p class="muted" style="margin:0">
            Все документы действуют в редакциях, указанных рядом с названием. Изменения
            публикуются на этой же странице.
        </p>
    </div></div>

    <?php foreach ($groups as $group => [$heading, $hint]): ?>
        <div class="card" style="margin-top:14px"><div class="card__body">
            <h2 class="card__title"><?= e($heading) ?></h2>
            <p class="hint" style="margin:-4px 0 12px"><?= e($hint) ?></p>

            <?php foreach ($items as $doc): ?>
                <?php if ($doc['group'] !== $group) continue; ?>
                <a href="<?= e(url($doc['url'])) ?>" class="doc-item">
                    <span class="doc-item__title"><?= e($doc['title']) ?></span>
                    <span class="doc-item__meta">Редакция от <?= e($doc['updated']) ?></span>
                </a>
            <?php endforeach; ?>
        </div></div>
    <?php endforeach; ?>

    <div class="card" style="margin-top:14px"><div class="card__body">
        <h2 class="card__title">Связаться</h2>
        <p style="margin:0">
            Вопросы по документам, обработке данных и претензии правообладателей —
            <a href="mailto:rustam@hothunt.ru">rustam@hothunt.ru</a>.
            Для претензий правообладателей тема письма: «Претензия правообладателя — Hothunt».
        </p>
    </div></div>
</div>
