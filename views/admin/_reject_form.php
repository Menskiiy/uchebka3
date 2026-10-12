<?php
/**
 * Форма отказа: причина и ссылка на правила.
 *
 * Без причины отказ выглядит как произвол, а модератор потом не помнит,
 * за что снял. Ссылка нужна, чтобы не пересказывать правила оформления
 * в каждом отказе — достаточно приложить адрес памятки.
 *
 * Открывается через <details>, без скриптов: в таблице модерации это
 * самый дешёвый способ спрятать форму до нажатия.
 */
?>
<form method="POST" action="<?= e(url('/admin/publikacii/' . (int) $t['id'] . '/snyat')) ?>" class="mod-reject__form">
    <?= csrf_field() ?>

    <textarea name="reason" class="input" rows="3" maxlength="1000"
              placeholder="Что не так: нет ссылки на товар, скопированный текст, истёкшая акция…"></textarea>

    <input type="url" name="reject_link" class="input" style="margin-top:6px"
           placeholder="Ссылка на правила оформления (необязательно)"
           value="<?= e(setting('rules_url', '')) ?>">

    <button class="btn btn--sm btn--primary" style="margin-top:8px">Отклонить и отправить автору</button>
</form>
