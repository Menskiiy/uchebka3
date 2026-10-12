<div class="page">

    <?php if ($top): ?>
        <h1 class="shops__title">Топ магазины</h1>

        <div class="shops__top">
            <?php foreach ($top as $m): ?>
                <a href="<?= e(url('/magazin/' . rawurlencode($m['slug']))) ?>" class="shop-tile">
                    <span class="shop-tile__logo" style="background:<?= e(empty($m['logo']) ? ($m['logo_color'] ?: '#c9ced6') : '#fff') ?>">
                        <?php if (! empty($m['logo'])): ?>
                            <img src="<?= e(url('/storage/' . $m['logo'])) ?>" alt="<?= e($m['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="shop-tile__letters"><?= e(mb_substr($m['name'], 0, 8)) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="shop-tile__name"><?= e($m['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card shops__all"><div class="card__body">
        <h2 class="shops__title shops__title--inner">Все магазины</h2>

        <?php if (! $groups): ?>
            <p class="muted">Магазинов пока нет. Добавьте их в панели управления.</p>
        <?php else: ?>
            <nav class="shops__alphabet">
                <?php foreach (array_keys($groups) as $letter): ?>
                    <a href="#letter-<?= e(urlencode($letter)) ?>"><?= e($letter) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php foreach ($groups as $letter => $items): ?>
                <section class="shops__group" id="letter-<?= e(urlencode($letter)) ?>">
                    <span class="shops__letter"><?= e($letter) ?></span>

                    <div class="shops__list">
                        <?php foreach ($items as $m): ?>
                            <a href="<?= e(url('/magazin/' . rawurlencode($m['slug']))) ?>" class="shops__item">
                                <?= e($m['name']) ?>
                                <?php if ((int) $m['threads_count'] > 0): ?>
                                    <span class="shops__count"><?= (int) $m['threads_count'] ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <p class="muted shops__total">Всего магазинов: <?= (int) $total ?></p>
        <?php endif; ?>
    </div></div>
</div>
