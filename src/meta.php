<?php
/**
 * Мета-теги для соцсетей и поисковиков.
 *
 * Без них ссылка на скидку в Telegram или ВК выглядит голой строкой.
 * Для сайта, где люди делятся находками, это прямая потеря трафика:
 * каждая расшаренная ссылка — бесплатная реклама, и она должна
 * показывать товар, цену и картинку.
 *
 * Второе назначение файла — уникальность title и description.
 * Вебмастер ругается ровно на одно: страницы магазинов, категорий и
 * профилей отдавали общее описание сайта, а вкладки профиля вдобавок
 * и общий заголовок. Поэтому описание здесь собирается из того, что на
 * странице реально есть: количество предложений, тип, месяц, имя автора.
 */

function meta_defaults(): array
{
    return [
        'title'       => setting('site_name', cfg('app_name')),
        'description' => setting('site_description', cfg('app_description')),
        'image'       => site_url() . url('/img/og-default.png'),
        'type'        => 'website',
        'url'         => site_url() . ($_SERVER['REQUEST_URI'] ?? '/'),
        // canonical заполняют функции ниже; если его нет, шаблон отрежет запрос
        'canonical'   => null,
        'robots'      => 'index, follow',
        'prev'        => null,
        'next'        => null,
    ];
}

/* ------------------------------------------------------------ утилиты */

/** Обрезать описание по границе слова: обрубок посреди слова выглядит мусором. */
function meta_clip(?string $text, int $limit = 180): string
{
    // html_to_text уже склеивает пробелы и чинит кодировку
    $text = html_to_text($text);

    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    $cut   = mb_substr($text, 0, $limit);
    $space = mb_strrpos($cut, ' ');

    return rtrim($space ? mb_substr($cut, 0, $space) : $cut, " ,.;:—-") . '…';
}

/** Склеить куски описания, пропуская пустые. */
function meta_join(array $parts, string $glue = ' '): string
{
    return implode($glue, array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
}

/** «сентябрь 2026» — месяц в описании даёт поисковику сигнал свежести. */
function month_ru(?int $ts = null): string
{
    $months = ['январь','февраль','март','апрель','май','июнь',
               'июль','август','сентябрь','октябрь','ноябрь','декабрь'];

    $ts = $ts ?? time();

    return $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

/** «12 промокодов» — число со склонением. */
function meta_count(int $n, string $one, string $few, string $many): string
{
    return $n . ' ' . plural($n, $one, $few, $many);
}

/**
 * Канонический адрес.
 *
 * Раньше canonical собирался как strtok(REQUEST_URI, '?') — то есть у
 * второй страницы канонической объявлялась первая, и робот выкидывал
 * всю пагинацию. Теперь параметры, меняющие состав страницы (page, vid),
 * остаются, а сортировка, фильтры и utm-метки отбрасываются.
 *
 * @param array<int,string> $keep имена параметров, которые нужно сохранить
 */
function canonical_url(array $keep = ['page']): string
{
    $path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $query = [];

    foreach ($keep as $key) {
        $value = (string) input($key, '');

        if ($value === '') {
            continue;
        }

        // page=1 в адресе не нужен: это та же страница, что и без параметра
        if ($key === 'page' && (int) $value <= 1) {
            continue;
        }

        $query[$key] = $value;
    }

    return site_url() . $path . ($query ? '?' . http_build_query($query) : '');
}

/** Адрес соседней страницы пагинации для rel="prev" / rel="next". */
function paged_url(int $page, array $keep = []): string
{
    $path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $query = [];

    foreach ($keep as $key) {
        $value = (string) input($key, '');

        if ($value !== '') {
            $query[$key] = $value;
        }
    }

    if ($page > 1) {
        $query['page'] = $page;
    }

    return site_url() . $path . ($query ? '?' . http_build_query($query) : '');
}

/**
 * Собрать мета-данные списочной страницы.
 *
 * $pager нужен, чтобы вторая и последующие страницы получили свой
 * заголовок: без пометки «страница 2» они дубли первой.
 *
 * @param array{title:string,description:string,image?:string,keep?:array,robots?:string} $data
 */
function meta_listing(array $data, ?array $pager = null): array
{
    $keep = $data['keep'] ?? ['page'];
    $page = $pager['page'] ?? 1;

    $url = canonical_url($keep);

    $meta = array_merge(meta_defaults(), [
        'title'       => $data['title'],
        'description' => meta_clip($data['description']),
        'url'         => $url,
        'canonical'   => $url,
    ]);

    if (! empty($data['image'])) {
        $meta['image'] = $data['image'];
    }

    if ($page > 1) {
        $meta['title']       = $data['title'] . ' — страница ' . $page;
        $meta['description'] = meta_clip('Страница ' . $page . '. ' . $data['description']);
    }

    if ($pager) {
        $keepNav = array_values(array_filter($keep, static fn ($k) => $k !== 'page'));

        $meta['prev'] = $page > 1 ? paged_url($page - 1, $keepNav) : null;
        $meta['next'] = $page < ($pager['pages'] ?? 1) ? paged_url($page + 1, $keepNav) : null;
    }

    if (! empty($data['robots'])) {
        $meta['robots'] = $data['robots'];
    }

    return $meta;
}

/**
 * Служебные и отфильтрованные страницы в индекс не отдаём.
 *
 * Лента с фильтрами и сортировкой — тот же набор публикаций в другом
 * порядке. Пускать такие адреса в поиск значит плодить дубли, но ссылки
 * с них должны работать, поэтому follow остаётся.
 */
function meta_noindex(): string
{
    return 'noindex, follow';
}

/** Фильтры или сортировка изменили выдачу — страница не для индекса. */
function meta_robots_for_feed(array $filters = [], string $sort = '', string $defaultSort = ''): string
{
    $filtered = ! empty($filters['count']);
    $resorted = $sort !== '' && $defaultSort !== '' && $sort !== $defaultSort;

    return ($filtered || $resorted) ? meta_noindex() : 'index, follow';
}

/* --------------------------------------------------------- публикация */

/** Мета-данные конкретной публикации. */
function meta_for_thread(array $t): array
{
    $site  = setting('site_name', cfg('app_name'));
    $parts = [];

    if ($t['price'] !== null) {
        $parts[] = money((float) $t['price']);
    }

    if ($t['code']) {
        $parts[] = 'промокод ' . $t['code'];
    }

    if ($t['merchant_name']) {
        $parts[] = $t['merchant_name'];
    }

    $description = html_to_text($t['body']);
    $description = $description !== ''
        ? meta_clip($description, 200)
        : meta_join([implode(' · ', $parts), $t['title']], ' — ');

    $image = $t['image_path']
        ? site_url() . url('/storage/' . $t['image_path'])
        : ($t['image_url'] ?: ($t['merchant_logo']
            ? site_url() . url('/storage/' . $t['merchant_logo'])
            : site_url() . url('/img/og-default.png')));

    return array_merge(meta_defaults(), [
        // черновик, публикация на проверке или снятая с ленты в поиске не нужна
        'robots'      => ($t['status'] ?? 'published') === 'published' ? 'index, follow' : meta_noindex(),
        'title'       => $t['title'] . ($parts ? ' — ' . implode(' · ', $parts) : '') . ' | ' . $site,
        'description' => $description ?: $t['title'],
        'image'       => $image,
        'type'        => 'article',
        'url'         => site_url() . thread_url($t),
        'canonical'   => site_url() . thread_url($t),
        'thread'      => $t,
    ]);
}

/* ------------------------------------------------------------ магазин */

/**
 * Магазин — главный источник дублей: таких страниц больше всего,
 * и до этого все они отдавали общее описание сайта.
 *
 * Описание собирается из чисел самого магазина: сколько промокодов,
 * сколько скидок, за какой месяц. Даже у двух пустых магазинов
 * описания будут разными — в них попадает название.
 */
function meta_for_merchant(array $merchant, array $counts, string $vid, array $pager): array
{
    $name  = $merchant['name'];
    $month = month_ru();

    $vouchers = (int) ($counts['voucher'] ?? 0);
    $deals    = (int) ($counts['deal'] ?? 0);
    $all      = (int) ($counts['all'] ?? 0);

    $own = ! empty($merchant['description']) ? meta_clip((string) $merchant['description'], 90) : '';

    if ($vid === 'voucher') {
        $title = 'Промокоды ' . $name . ' — ' . $month;

        $description = $vouchers > 0
            ? meta_count($vouchers, 'действующий промокод', 'действующих промокода', 'действующих промокодов')
              . ' ' . $name . ' на ' . $month . '. Коды проверяет сообщество: работает — ставим «горячо».'
            : 'Промокоды ' . $name . ' на ' . $month . '. Сейчас активных кодов нет — подпишитесь, и мы сообщим, как только появятся.';
    } elseif ($vid === 'deal') {
        $title = 'Скидки и акции ' . $name . ' — ' . $month;

        $description = $deals > 0
            ? meta_count($deals, 'актуальная скидка', 'актуальные скидки', 'актуальных скидок')
              . ' ' . $name . ' на ' . $month . '. Цены и условия проверены участниками сообщества.'
            : 'Скидки ' . $name . ' на ' . $month . '. Пока предложений нет — подпишитесь на магазин, чтобы не пропустить новые.';
    } else {
        $title = 'Промокоды и скидки ' . $name . ' — ' . $month;

        $description = $all > 0
            ? meta_join([
                meta_count($vouchers, 'промокод', 'промокода', 'промокодов'),
                'и',
                meta_count($deals, 'скидка', 'скидки', 'скидок'),
                $name . ' на ' . $month . '.',
                $own !== '' ? $own : 'Предложения находят и проверяют участники сообщества.',
            ])
            : meta_join([
                'Промокоды и скидки ' . $name . ' на ' . $month . '.',
                $own !== '' ? $own : 'Пока предложений нет — подпишитесь на магазин или добавьте находку первым.',
            ]);
    }

    $image = ! empty($merchant['logo'])
        ? site_url() . url('/storage/' . $merchant['logo'])
        : site_url() . url('/img/og-default.png');

    return meta_listing([
        'title'       => $title . ' | ' . setting('site_name', cfg('app_name')),
        'description' => $description,
        'image'       => $image,
        'keep'        => ['vid', 'page'],
    ], $pager);
}

/* ---------------------------------------------------------- категория */

function meta_for_category(array $category, int $total, array $children, array $pager): array
{
    $name  = $category['name'];
    $month = month_ru();

    $kids = array_slice(array_column($children, 'name'), 0, 5);

    $description = meta_join([
        $total > 0
            ? meta_count($total, 'предложение', 'предложения', 'предложений') . ' в категории «' . $name . '» на ' . $month . '.'
            : 'Категория «' . $name . '»: скидки и промокоды на ' . $month . '.',
        ! empty($category['description']) ? meta_clip((string) $category['description'], 80) : '',
        $kids ? 'Разделы: ' . implode(', ', $kids) . '.' : '',
        'Находки и проверенные купоны от сообщества.',
    ]);

    return meta_listing([
        'title'       => $name . ' — скидки и промокоды ' . $month . ' | ' . setting('site_name', cfg('app_name')),
        'description' => $description,
        'keep'        => ['page'],
    ], $pager);
}

/* ------------------------------------------------------------ профиль */

/**
 * Профиль и его вкладки.
 *
 * Вебмастер показывал шесть одинаковых «Rustam — HOTHUNT.RU»: вкладки
 * отдавали один заголовок на всех. Теперь вкладка входит и в title,
 * и в описание, а пустые вкладки закрыты от индекса — страница без
 * содержимого в выдаче не нужна ни нам, ни человеку.
 */
function meta_for_profile(array $user, string $tab, array $counts, array $pager): array
{
    $site = setting('site_name', cfg('app_name'));
    $name = $user['username'];

    $deals    = (int) ($counts['deals'] ?? 0);
    $vouchers = (int) ($counts['vouchers'] ?? 0);
    $blog     = (int) ($counts['blog'] ?? 0);
    $comments = (int) ($counts['comments'] ?? 0);
    $rank     = rank_for((int) $user['reputation'])['title'];

    $tabTitles = [
        'deals'    => 'Скидки',
        'vouchers' => 'Промокоды',
        'blog'     => 'Блог',
        'comments' => 'Комментарии',
        'ranks'    => 'Ранги',
    ];

    $tabCounts = [
        'deals'    => $deals,
        'vouchers' => $vouchers,
        'blog'     => $blog,
        'comments' => $comments,
        'ranks'    => 1,
    ];

    $bio = ! empty($user['bio']) ? meta_clip((string) $user['bio'], 80) : '';

    $descriptions = [
        'deals'    => $deals > 0
            ? meta_count($deals, 'скидка', 'скидки', 'скидок') . ' от ' . $name . ' на ' . setting('site_name', cfg('app_name')) . '.'
            : $name . ' пока не публиковал скидок.',
        'vouchers' => $vouchers > 0
            ? meta_count($vouchers, 'промокод', 'промокода', 'промокодов') . ' от ' . $name . ', проверенных сообществом.'
            : $name . ' пока не публиковал промокодов.',
        'blog'     => $blog > 0
            ? meta_count($blog, 'запись', 'записи', 'записей') . ' в блоге ' . $name . ': обзоры, находки и разборы предложений.'
            : 'Блог ' . $name . ' пока пуст.',
        'comments' => meta_count($comments, 'комментарий', 'комментария', 'комментариев') . ' участника ' . $name . ' в обсуждениях скидок.',
        'ranks'    => 'Ранги и достижения ' . $name . ': ' . $rank . ', ' . (int) $user['reputation'] . ' репутации.',
    ];

    $title = $name . ' — ' . ($tabTitles[$tab] ?? 'Профиль') . ' | ' . $site;

    $description = meta_join([
        $descriptions[$tab] ?? '',
        $bio,
        'Ранг: ' . $rank . ', репутация ' . (int) $user['reputation'] . '.',
    ]);

    $image = ! empty($user['avatar'])
        ? site_url() . url('/storage/' . $user['avatar'])
        : site_url() . url('/img/og-default.png');

    return meta_listing([
        'title'       => $title,
        'description' => $description,
        'image'       => $image,
        'keep'        => ['page'],
        // пустая вкладка — тонкая страница, в индексе ей делать нечего
        'robots'      => ($tabCounts[$tab] ?? 0) > 0 ? 'index, follow' : meta_noindex(),
    ], $pager);
}

/* ------------------------------------------------------------- разделы */

function meta_for_group(array $group, int $total, array $pager): array
{
    $description = meta_join([
        ! empty($group['description']) ? meta_clip((string) $group['description'], 110) : '',
        $total > 0
            ? meta_count($total, 'тема', 'темы', 'тем') . ' в разделе «' . $group['name'] . '».'
            : 'В разделе «' . $group['name'] . '» пока нет тем — начните обсуждение первым.',
    ]);

    return meta_listing([
        'title'       => $group['name'] . ' — обсуждения | ' . setting('site_name', cfg('app_name')),
        'description' => $description,
        'keep'        => ['page'],
    ], $pager);
}

/* --------------------------------------------------------------- лента */

function meta_for_feed(?string $type, array $pager, array $filters = [], string $sort = '', string $defaultSort = ''): array
{
    $site  = setting('site_name', cfg('app_name'));
    $month = month_ru();
    $total = (int) ($pager['total'] ?? 0);

    if ($type === 'deal') {
        $title       = 'Скидки и акции — ' . $month . ' | ' . $site;
        $description = meta_count($total, 'актуальная скидка', 'актуальные скидки', 'актуальных скидок')
            . ' на ' . $month . ': техника, одежда, продукты, игры. Находки участников сообщества с проверкой цен и условий.';
    } elseif ($type === 'voucher') {
        $title       = 'Промокоды — ' . $month . ' | ' . $site;
        $description = meta_count($total, 'рабочий промокод', 'рабочих промокода', 'рабочих промокодов')
            . ' на ' . $month . ': купоны магазинов, коды на доставку и первый заказ. Каждый код проверяют участники сообщества.';
    } else {
        $title       = $site . ' — лучшие скидки и промокоды ' . $month;
        $description = 'Скидки, акции и промокоды, которые нашли и проверили люди, а не робот. '
            . meta_count($total, 'предложение', 'предложения', 'предложений') . ' в ленте на ' . $month . '.';
    }

    return meta_listing([
        'title'       => $title,
        'description' => $description,
        'keep'        => ['page'],
        'robots'      => meta_robots_for_feed($filters, $sort, $defaultSort),
    ], $pager);
}

function meta_for_blogs(int $total, array $pager): array
{
    return meta_listing([
        'title'       => 'Блоги — обзоры и разборы предложений | ' . setting('site_name', cfg('app_name')),
        'description' => meta_count($total, 'запись', 'записи', 'записей')
            . ' в блогах сообщества: обзоры покупок, разборы акций, опыт возвратов и кэшбэка. ' . month_ru() . '.',
        'keep'        => ['page'],
    ], $pager);
}

/* ------------------------------------------------- простые страницы */

/** Заголовок и описание для страницы без собственной выборки. */
function meta_simple(string $title, string $description, bool $index = true): array
{
    return array_merge(meta_defaults(), [
        'title'       => $title,
        'description' => meta_clip($description),
        'url'         => canonical_url([]),
        'canonical'   => canonical_url([]),
        'robots'      => $index ? 'index, follow' : meta_noindex(),
    ]);
}

/** Разметка Schema.org: по ней Яндекс и Google показывают цену прямо в выдаче. */
function meta_offer_json(array $t): string
{
    if (! is_commercial($t['type']) || $t['price'] === null) {
        return '';
    }

    $data = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Offer',
        'name'        => $t['title'],
        'url'         => site_url() . thread_url($t),
        'price'       => (float) $t['price'],
        'priceCurrency' => 'RUB',
        'availability'  => 'https://schema.org/InStock',
    ];

    if ($t['expires_at']) {
        $data['priceValidUntil'] = date('Y-m-d', strtotime($t['expires_at']));
    }

    if ($t['merchant_name']) {
        $data['seller'] = ['@type' => 'Organization', 'name' => $t['merchant_name']];
    }

    if ($t['image_path']) {
        $data['image'] = site_url() . url('/storage/' . $t['image_path']);
    }

    return '<script type="application/ld+json">'
        . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . '</script>';
}
