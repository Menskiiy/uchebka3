<?php
/**
 * Панель фильтров. На десктопе — выпадающая, на телефоне превращается
 * в шторку снизу (тем же механизмом, что и остальные меню).
 */
$values     = $filters['values'] ?? [];
$count      = $filters['count'] ?? 0;
$lockedType = $lockedType ?? null;
$lockedCat  = $lockedCat ?? false;
$lockedShop = $lockedShop ?? false;

// то, что нужно сохранить при отправке формы
$keep = [];

if (! empty($_GET['tab'])) { $keep['tab'] = $_GET['tab']; }
if (! empty($_GET['q']))   { $keep['q']   = $_GET['q']; }
?>
<div class="dropdown filters">
    <button class="btn btn--ghost filters__btn <?= $count > 0 ? 'is-active' : '' ?>" data-dropdown aria-label="Фильтры">
        <?= icon('filter') ?>
        <span class="no-mobile">Фильтры</span>
        <?php if ($count > 0): ?><span class="filters__count"><?= (int) $count ?></span><?php endif; ?>
    </button>

    <div class="dropdown__menu dropdown__menu--filters">
        <form method="GET" class="filters__form">
            <?php foreach ($keep as $name => $value): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
            <?php endforeach; ?>

            <div class="filters__body">

            <div class="filters__head">
                <strong>Фильтры</strong>
                <?php if ($count > 0): ?>
                    <a href="?<?= e(http_build_query($keep)) ?>" class="muted" style="font-size:13px">Сбросить</a>
                <?php endif; ?>
            </div>

            <?php if (! $lockedType): ?>
                <div class="field">
                    <label for="f-type">Тип публикации</label>
                    <select name="type" id="f-type" class="input">
                        <option value="">Любой</option>
                        <?php foreach (cfg('thread_types') as $key => $typeMeta): ?>
                            <option value="<?= e($key) ?>" <?= ($values['type'] ?? '') === $key ? 'selected' : '' ?>>
                                <?= e($typeMeta['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if (! $lockedCat): ?>
                <div class="field">
                    <label for="f-cat">Категория</label>
                    <select name="cat" id="f-cat" class="input">
                        <option value="">Все категории</option>
                        <?php foreach ($sources['categories'] as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= (int) ($values['cat'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if (! $lockedShop): ?>
                <div class="field">
                    <label for="f-shop">Магазин</label>
                    <select name="shop" id="f-shop" class="input">
                        <option value="">Все магазины</option>
                        <?php foreach ($sources['merchants'] as $m): ?>
                            <option value="<?= (int) $m['id'] ?>" <?= (int) ($values['shop'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
                                <?= e($m['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="field">
                <label>Цена, ₽</label>
                <div class="filters__range">
                    <input type="number" name="pmin" class="input" placeholder="от" min="0" value="<?= e($values['pmin'] ?? '') ?>">
                    <span class="muted">—</span>
                    <input type="number" name="pmax" class="input" placeholder="до" min="0" value="<?= e($values['pmax'] ?? '') ?>">
                </div>
            </div>

            <div class="field">
                <label for="f-disc">Скидка от, %</label>
                <input type="number" name="disc" id="f-disc" class="input" min="0" max="99"
                       placeholder="например, 30" value="<?= e($values['disc'] ?? '') ?>">
            </div>

            <div class="filters__checks">
                <label class="check">
                    <input type="checkbox" name="hot" value="1" <?= ! empty($values['hot']) ? 'checked' : '' ?>>
                    Только горячие
                </label>
                <label class="check">
                    <input type="checkbox" name="code" value="1" <?= ! empty($values['code']) ? 'checked' : '' ?>>
                    С промокодом
                </label>
                <label class="check">
                    <input type="checkbox" name="free" value="1" <?= ! empty($values['free']) ? 'checked' : '' ?>>
                    Бесплатно
                </label>
                <label class="check">
                    <input type="checkbox" name="img" value="1" <?= ! empty($values['img']) ? 'checked' : '' ?>>
                    С картинкой
                </label>
                <label class="check">
                    <input type="checkbox" name="exp" value="1" <?= ! empty($values['exp']) ? 'checked' : '' ?>>
                    Истёкшие
                </label>
            </div>

            </div>

            <div class="filters__actions">
                <button class="btn btn--primary btn--block">Показать</button>
            </div>
        </form>
    </div>
</div>
