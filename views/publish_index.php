<div class="page">
    <div class="card" style="max-width:720px"><div class="card__body">
        <h1 style="font-size:22px;margin-top:0">Что вы хотите опубликовать?</h1>
        <div class="stack" style="margin-top:18px">
            <?php foreach (publishable_types() as $typeMeta): ?>
                <a href="<?= e(url('/dobavit/' . $typeMeta['slug'])) ?>" class="btn btn--ghost btn--block" style="justify-content:flex-start">
                    <?= e($typeMeta['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div></div>
</div>
