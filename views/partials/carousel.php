<?php
/**
 * Карусель самых горячих предложений.
 * Прокрутка нативная (со свайпом на телефоне), стрелки — только помощь мышью.
 */
$periods = ['day' => 'Сегодня', 'week' => 'Неделя', 'month' => 'Месяц', 'all' => 'За всё время'];
?>
<section class="card hotline"><div class="card__body">
    <div class="hotline__head">
        <h2 class="card__title" style="margin:0"><?= icon('flame') ?> Самое горячее</h2>

        <div class="dropdown hotline__period">
            <button class="btn btn--sm btn--ghost" data-dropdown>
                <?= e($periods[$carousel['period']] ?? 'Неделя') ?> &#9662;
            </button>
            <div class="dropdown__menu">
                <?php foreach ($periods as $key => $label): ?>
                    <?php $qs = $_GET; $qs['period'] = $key; unset($qs['page']); ?>
                    <a href="?<?= e(http_build_query($qs)) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="hotline__nav no-mobile">
            <button type="button" class="hotline__arrow" data-scroll="-1" aria-label="Назад">&#8249;</button>
            <button type="button" class="hotline__arrow" data-scroll="1" aria-label="Вперёд">&#8250;</button>
        </div>
    </div>

    <?php if ($carousel['fallback']): ?>
        <p class="hint" style="margin:0 0 10px">
            За выбранный период предложений мало — показываем лучшее за всё время.
        </p>
    <?php endif; ?>

    <div class="hotline__track" data-carousel>
        <?php foreach ($carousel['items'] as $h): ?>
            <a href="<?= e(thread_url($h)) ?>" class="hotline__item">
                <span class="hotline__media"><?= c_thumb($h) ?></span>

                <span class="hotline__info">
                    <span class="hotline__temp"><?= (int) $h['temperature'] ?>&deg;</span>
                    <span class="hotline__title"><?= e(excerpt($h['title'], 62)) ?></span>

                    <?php if ($h['price'] !== null): ?>
                        <span class="hotline__price"><?= e(money((float) $h['price'])) ?></span>
                    <?php elseif ((int) $h['discount_percent'] > 0): ?>
                        <span class="hotline__price">&minus;<?= (int) $h['discount_percent'] ?>%</span>
                    <?php elseif (! empty($h['merchant_name'])): ?>
                        <span class="hotline__shop"><?= e($h['merchant_name']) ?></span>
                    <?php endif; ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</div></section>
