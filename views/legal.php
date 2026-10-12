<div class="page">
    <div class="card legal"><div class="card__body">
        <h1><?= e($pageTitle) ?></h1>
        <p class="legal__updated">Редакция от <?= e($updated) ?></p>
        <div class="legal__text prose"><?= legal_body_html($body) ?></div>
    </div></div>
</div>
