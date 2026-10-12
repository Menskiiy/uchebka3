<?php
/**
 * Выбор категорий: родительские заголовками, вложенные — отмечаемыми.
 * Родительскую тоже можно выбрать: не у каждой скидки есть подходящая
 * подкатегория.
 */
$selected = $selected ?? [];
?>
<div class="cat-picker">
    <?php foreach ($categories as $parent): ?>
        <div class="cat-group">
            <label class="check check--parent">
                <input type="checkbox" name="categories[]" value="<?= (int) $parent['id'] ?>"
                       <?= in_array((int) $parent['id'], $selected, true) ? 'checked' : '' ?>>
                <?= e($parent['name']) ?>
            </label>

            <?php if ($parent['children']): ?>
                <div class="cat-group__children">
                    <?php foreach ($parent['children'] as $child): ?>
                        <label class="check">
                            <input type="checkbox" name="categories[]" value="<?= (int) $child['id'] ?>"
                                   <?= in_array((int) $child['id'], $selected, true) ? 'checked' : '' ?>>
                            <?= e($child['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
