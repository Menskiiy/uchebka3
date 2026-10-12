<?php
/**
 * Справочники. Без них нельзя опубликовать обсуждение (нужен раздел)
 * и не с чем связать скидку. Демо-публикаций здесь нет — сайт стартует пустым.
 */

function seed_reference(PDO $pdo): void
{
    $slug = static function (string $title): string {
        $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh',
                'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
                'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c',
                'ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
        $s = strtr(mb_strtolower($title, 'UTF-8'), $map);
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s);

        return trim((string) $s, '-');
    };

    $categories = [
        'Электроника'            => ['Смартфоны', 'Ноутбуки', 'Телевизоры', 'Аудио'],
        'Продукты питания'       => ['Бакалея', 'Напитки', 'Готовая еда'],
        'Дом и Квартира'         => ['Мебель', 'Техника для дома', 'Ремонт'],
        'Мода'                   => ['Одежда', 'Обувь', 'Аксессуары'],
        'Консоли и Видеоигры'    => ['PC', 'PlayStation', 'Xbox', 'Nintendo'],
        'Услуги и Подписки'      => ['Подписки', 'Связь', 'Банки'],
        'Здоровье и Красота'     => ['Аптека', 'Косметика'],
        'Семья и Дети'           => ['Игрушки', 'Детское питание'],
        'Путешествия'            => ['Авиабилеты', 'Отели'],
        'Автомобили'             => ['Запчасти', 'Шины'],
        'Спорт и Активный отдых' => ['Тренажёры', 'Туризм'],
        'Разное'                 => [],
    ];

    $st = $pdo->prepare('INSERT INTO categories (parent_id, name, slug, position) VALUES (?, ?, ?, ?)');
    $pos = 0;

    foreach ($categories as $parent => $children) {
        $st->execute([null, $parent, $slug($parent), $pos++]);
        $parentId = (int) $pdo->lastInsertId();
        $cpos = 0;

        foreach ($children as $child) {
            $st->execute([$parentId, $child, $slug($child), $cpos++]);
        }
    }

    // Цветные плашки вместо чужих логотипов: товарные знаки в проект не кладём.
    $merchants = [
        ['OZON', 'ozon.ru', '#005bff', 1],
        ['Wildberries', 'wildberries.ru', '#7b1fa2', 1],
        ['Магнит', 'magnit.ru', '#e30613', 1],
        ['DNS', 'dns-shop.ru', '#f9a825', 1],
        ['М.Видео', 'mvideo.ru', '#e30613', 1],
        ['Мегамаркет', 'megamarket.ru', '#7c4dff', 1],
        ['Яндекс Маркет', 'market.yandex.ru', '#fc3f1d', 1],
        ['Ситилинк', 'citilink.ru', '#f57c00', 0],
        ['Эльдорадо', 'eldorado.ru', '#0d47a1', 0],
        ['Пятёрочка', '5ka.ru', '#00a651', 1],
        ['Лента', 'lenta.com', '#0057a8', 0],
        ['Ашан', 'auchan.ru', '#d32f2f', 0],
        ['Спортмастер', 'sportmaster.ru', '#0d47a1', 0],
        ['Лемана Про', 'lemanapro.ru', '#43a047', 0],
        ['Детский Мир', 'detmir.ru', '#0288d1', 0],
        ['Steam', 'store.steampowered.com', '#1b2838', 1],
    ];

    $st = $pdo->prepare('INSERT INTO merchants (name, slug, domain, logo_color, is_top, position) VALUES (?, ?, ?, ?, ?, ?)');
    $pos = 0;

    foreach ($merchants as [$name, $domain, $color, $top]) {
        $st->execute([$name, $slug($name), $domain, $color, $top, $pos++]);
    }

    $groups = [
        ['Разные обсуждения', 'Всё, что не попало в другие разделы.'],
        ['Рассказать о себе', 'Знакомство с сообществом.'],
        ['Вопросы и проблемы с магазинами', 'Спорные заказы, возвраты, поддержка.'],
        ['Задать вопрос', 'Нужен совет — спрашивайте здесь.'],
        ['Покупка из-за границы', 'Доставка, посредники, таможня.'],
        ['Советы и лайфхаки по экономии денег', 'Как платить меньше.'],
        ['Конкурсы и розыгрыши', 'Активности сообщества.'],
    ];

    $st = $pdo->prepare('INSERT INTO groups_list (name, slug, description, position) VALUES (?, ?, ?, ?)');
    $pos = 0;

    foreach ($groups as [$name, $description]) {
        $st->execute([$name, $slug($name), $description, $pos++]);
    }

    // свежая база уже содержит всё, миграции ей не нужны
    $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute(['schema_version', '6']);
}
