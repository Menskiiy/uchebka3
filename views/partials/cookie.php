<?php
/**
 * Баннер про cookie. Показывается, пока согласие не сохранено.
 * От выбора зависит, загрузится ли счётчик Метрики — согласие
 * должно на что-то влиять, иначе оно бессмысленно.
 */
?>
<div class="cookie" id="cookie-banner" hidden>
    <div class="cookie__body">
        <span class="cookie__icon"><?= icon('cookie') ?></span>
        <p class="cookie__text">
            Мы используем необходимые cookie для работы сайта и защиты сервисов.
            Аналитические cookie Яндекс Метрики включаются только после вашего согласия.
            Подробнее — в <a href="<?= e(legal_url('cookie')) ?>">политике использования файлов cookie</a>.
            Отказ не ограничивает доступ к функциям сайта.
        </p>
        <div class="cookie__actions">
            <button class="btn btn--sm" data-cookie="no">Отказаться</button>
            <button class="btn btn--primary btn--sm" data-cookie="yes">Принять</button>
        </div>
    </div>
</div>
