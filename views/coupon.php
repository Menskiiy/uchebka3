<div class="page">
    <div class="coupon">
        <div class="card"><div class="card__body coupon__body">
            <?php if (! empty($t['merchant_name'])): ?>
                <p class="coupon__shop"><?= e($t['merchant_name']) ?></p>
            <?php endif; ?>

            <h1 class="coupon__title"><?= e($t['title']) ?></h1>

            <p class="coupon__hint">Промокод скопирован — вставьте его в корзине</p>

            <div class="coupon__code" id="coupon-code" data-code="<?= e($t['code']) ?>">
                <code><?= e($t['code']) ?></code>
                <button type="button" class="coupon__copy" data-coupon-copy>Копировать ещё раз</button>
            </div>

            <?php if ($t['url']): ?>
                <a href="<?= e(url('/go/' . rawurlencode($t['slug']))) ?>" class="btn btn--primary btn--block coupon__go" target="_blank" rel="nofollow noopener">
                    Перейти в магазин &rarr;
                </a>
            <?php endif; ?>

            <?php if ($t['expires_at']): ?>
                <p class="coupon__meta"><?= c_expiry($t, false) ?></p>
            <?php endif; ?>

            <a href="<?= e(thread_url($t)) ?>" class="coupon__back">
                Открыть публикацию и условия
            </a>
        </div></div>

        <?php if (! empty($t['ad_marking'])): ?>
            <p class="ad-marking" style="max-width:480px;margin:14px auto 0"><?= e($t['ad_marking']) ?></p>
        <?php endif; ?>
    </div>
</div>
