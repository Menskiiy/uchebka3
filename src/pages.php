<?php
/** Страницы, не относящиеся к модерации. */

function render(string $view, array $data = [], ?string $title = null, ?string $description = null): void
{
    $meta = $data['meta'] ?? null;

    extract($data, EXTR_SKIP);

    ob_start();
    require __DIR__ . '/../views/' . $view . '.php';
    $content = ob_get_clean();

    require __DIR__ . '/../views/layout.php';
    exit;
}

function sort_param(string $default = 'hot'): string
{
    $tab = input('tab', $default);

    return in_array($tab, ['hot', 'new', 'discussed', 'expiring'], true) ? $tab : $default;
}

function page_feed(?string $type = null): void
{
    // По умолчанию «Горячее»: человек приходит за лучшими находками, а не
    // за хронологией. Свежее никуда не делось — оно во вкладке «Новое»
    // и блоком в боковой колонке.
    $sort    = sort_param('hot');
    $filters = feed_filters();

    // на /skidki и /promokody тип задан адресом и фильтром не переопределяется
    $options = $filters['options'];

    if ($type) {
        $options['type'] = $type;
        unset($filters['values']['type']);
        $filters['count'] = count($filters['values']);
    }

    // без явного типа показываем только скидки и промокоды
    if (! $type && empty($options['type'])) {
        $options['commercial_only'] = true;
    }

    $options['sort'] = $sort;
    $options['page'] = (int) input('page', 1);

    // вкладка «Сгорает» — не другая сортировка, а другая выборка:
    // в ней только то, у чего срок кончается в ближайшие двое суток
    if ($sort === 'expiring') {
        $options['expiring_soon'] = true;
    }

    $result = feed_threads($options);

    /*
     * Вкладку показываем, только когда в ней что-то есть: срок у публикации —
     * поле необязательное, и пустая вкладка выглядит как поломка. Порог
     * в три позиции, чтобы она не мигала из-за одной акции.
     */
    $expiring = expiring_count();

    // Карусель только на чистой ленте: при поиске или фильтрах она
    // сбивает с толку — сверху одно, ниже совсем другое.
    // параметр называется period, а не hot: имя hot уже занято фильтром
    // «только горячие», и переключение периода включало фильтр
    $carousel = ($filters['count'] === 0)
        ? hot_carousel((string) input('period', 'week'))
        : null;


    // Заголовок и описание собирает meta.php: в них попадают тип ленты,
    // число предложений и месяц — иначе все страницы ленты выглядят
    // для поисковика одинаково.
    $meta = meta_for_feed($type, $result['pager'], $filters, $sort, 'hot');

    render('feed', [
        'meta'        => $meta,
        'result'      => $result,
        'expiring'    => $expiring,
        'newest'      => feed_newest_at($options),
        'sort'        => $sort,
        'showTabs'    => true,
        'heading'     => $type ? type_label($type) : null,
        'filters'     => $filters,
        'sources'     => filter_sources(),
        'lockedType'  => $type,
        'carousel'    => $carousel,
    ]);
}

/**
 * Страница публикации.
 *
 * Адрес канонический — /magazin/{магазин}/{публикация}. Прежний /post/{слаг}
 * и адрес с чужим магазином отвечают постоянным редиректом: ссылки из писем,
 * каналов и поисковой выдачи должны работать, но вести в одно место —
 * иначе для поисковика это разные страницы с одинаковым содержимым.
 */
function page_thread(string $slug, ?string $merchant = null): void
{
    $t = find_thread($slug);

    if (! $t) {
        page_404();
    }

    $requested = $merchant !== null
        ? '/magazin/' . rawurlencode($merchant) . '/' . rawurlencode($slug)
        : '/post/' . rawurlencode($slug);

    $canonical = thread_path($t);

    if ($canonical !== $requested) {
        http_response_code(301);
        header('Location: ' . url($canonical));
        exit;
    }

    // истёкшее предложение остаётся доступным по ссылке — просто помечено;
    // скрываем только черновики и снятое модератором
    $hidden = in_array($t['status'], ['draft', 'rejected', 'pending'], true);

    if ($hidden && auth_id() !== (int) $t['user_id'] && ! is_moderator()) {
        page_404();
    }

    qx('UPDATE threads SET views_count = views_count + 1 WHERE id = ?', [$t['id']]);
    $t['views_count'] = (int) $t['views_count'] + 1;

    $meta = meta_for_thread($t);

    render('thread', [
        't'          => $t,
        'categories' => thread_categories((int) $t['id']),
        'comments'   => thread_comments((int) $t['id']),
        'meta'       => $meta,
    ], $meta['title'], $meta['description']);
}

/**
 * Переход в магазин.
 *
 * Здесь же ссылка превращается в партнёрскую и получает метку subID —
 * по ней в отчётах сети видно, какая публикация принесла заказ. Делать
 * это на самой странице нельзя: партнёрская ссылка в разметке попадает
 * в поисковую выдачу и в чужие репосты, а метка должна ставиться в момент
 * перехода конкретного человека.
 */
function page_go(string $slug): void
{
    $t = q1('SELECT t.*, m.domain, m.affiliate_url, m.affiliate_param
             FROM threads t LEFT JOIN merchants m ON m.id = t.merchant_id
             WHERE t.slug = ?', [$slug]);

    if (! $t || ! $t['url']) {
        page_404();
    }

    qx('UPDATE threads SET clicks_count = clicks_count + 1 WHERE id = ?', [$t['id']]);

    $target = affiliate_link((string) $t['url'], $t, affiliate_subid($t, auth_id()));

    // партнёрские ссылки в поиске не нужны — они ведут в сеть, а не на товар
    header('X-Robots-Tag: noindex, nofollow');
    header('Location: ' . $target);
    exit;
}

function page_search(): void
{
    $term = (string) input('q', '');

    $filters = feed_filters();
    $options = $filters['options'];

    $options['search'] = $term;
    $options['sort']   = sort_param();
    $options['page']   = (int) input('page', 1);

    $result    = feed_threads($options);
    $merchants = search_merchants($term);

    // магазины показываются первыми: тот, кто ищет бренд, ждёт сам магазин,
    // а уже под ним — посты, где это название встречается в тексте
    render('feed', [
        'result'     => $result,
        'merchants'  => $merchants,
        'newest'     => feed_newest_at($options),
        'sort'       => sort_param(),
        'showTabs'   => true,
        'filters'    => $filters,
        'sources'    => filter_sources(),
        'heading'    => 'Поиск: «' . $term . '»',
        'subheading' => 'Найдено публикаций: ' . $result['pager']['total'],
        'emptyText'  => $merchants ? 'Публикаций с таким текстом не нашлось, но ниже есть подходящий магазин.' : 'Ничего не нашлось. Попробуйте другой запрос.',
        // выдача поиска в индексе не нужна: тот же контент, что и в ленте
        'meta'       => meta_simple(
            'Поиск: ' . $term . ' — ' . setting('site_name', cfg('app_name')),
            'Результаты поиска по запросу «' . $term . '»: найдено публикаций — ' . $result['pager']['total'] . '.',
            false
        ),
    ]);
}

function page_categories(): void
{
    $all = q('SELECT * FROM categories ORDER BY position, name');

    /*
     * Живые предложения по разделам — опубликованные и не истёкшие, то есть
     * ровно то, что человек увидит, открыв раздел. Пустая плитка без цифр
     * ничего не говорит; «34 предложения, свежее — час назад» говорит всё.
     */
    $live = [];

    foreach (q("SELECT ct.category_id, COUNT(*) AS n, MAX(t.published_at) AS last_at
                FROM category_thread ct
                JOIN threads t ON t.id = ct.thread_id
                WHERE t.status = 'published' AND (t.expires_at IS NULL OR t.expires_at > ?)
                GROUP BY ct.category_id", [now()]) as $row) {
        $live[(int) $row['category_id']] = $row;
    }

    // магазины раздела — узнаваемые логотипы понятнее любого описания
    $shops = [];

    foreach (q('SELECT cm.category_id, m.name, m.slug, m.logo, m.logo_color
                FROM category_merchant cm
                JOIN merchants m ON m.id = cm.merchant_id
                ORDER BY m.is_top DESC, m.name') as $row) {
        $shops[(int) $row['category_id']][] = $row;
    }

    $parents  = [];
    $children = [];

    foreach ($all as $c) {
        $id = (int) $c['id'];

        $c['live']    = (int) ($live[$id]['n'] ?? 0);
        $c['last_at'] = $live[$id]['last_at'] ?? null;
        $c['shops']   = $shops[$id] ?? [];

        if ($c['parent_id'] === null) {
            $parents[$id] = $c + ['children' => []];
        } else {
            $children[] = $c;
        }
    }

    foreach ($children as $c) {
        if (isset($parents[(int) $c['parent_id']])) {
            $parents[(int) $c['parent_id']]['children'][] = $c;
            $parents[(int) $c['parent_id']]['live'] += $c['live'];
        }
    }

    // порядок из админки главнее; при равном — сначала живые разделы
    $parents = array_values($parents);
    usort($parents, static fn ($a, $b) => [(int) $a['position'], -$a['live'], $a['name']]
        <=> [(int) $b['position'], -$b['live'], $b['name']]);

    render('catalog', [
        'items'   => $parents,
        'kind'    => 'categories',
        'heading' => 'Категории',
        'meta'    => meta_simple(
            'Категории скидок и промокодов | ' . setting('site_name', cfg('app_name')),
            'Все категории сообщества: ' . implode(', ', array_slice(array_column($parents, 'name'), 0, 8))
            . '. Скидки, акции и промокоды по разделам на ' . month_ru() . '.'
        ),
    ]);
}

function page_category(string $slug): void
{
    $category = q1('SELECT * FROM categories WHERE slug = ?', [$slug]);

    if (! $category) {
        page_404();
    }

    $filters = feed_filters();
    $options = $filters['options'];
    unset($filters['values']['cat']);
    $filters['count'] = count($filters['values']);

    $options['category_id'] = (int) $category['id'];
    $options['sort']        = sort_param();
    $options['page']        = (int) input('page', 1);

    $result   = feed_threads($options);
    $children = q('SELECT name FROM categories WHERE parent_id = ? ORDER BY position', [$category['id']]);

    render('feed', [
        'categorySlug' => $slug,
        'result'       => $result,
        'newest'       => feed_newest_at($options),
        'meta'         => meta_for_category($category, (int) $result['pager']['total'], $children, $result['pager']),
        'sort'         => sort_param(),
        'showTabs'     => true,
        'heading'      => $category['name'],
        'emptyText'    => 'В этой категории пока нет публикаций.',
        'filters'      => $filters,
        'sources'      => filter_sources(),
        'lockedCat'    => true,
    ]);
}

function page_merchants(): void
{
    $all = q('SELECT m.*, (SELECT COUNT(*) FROM threads t
                           WHERE t.merchant_id = m.id AND t.status = \'published\') AS threads_count
              FROM merchants m ORDER BY name');

    // Наверху — витрина: те, что отмечены в панели, плюс набравшие лайки.
    // Остальные уходят в общий список по алфавиту.
    $top = array_values(array_filter($all, static fn ($m) => (int) $m['is_top'] === 1 || (int) $m['likes_count'] > 0));

    usort($top, static function ($a, $b) {
        return [(int) $b['likes_count'], (int) $b['is_top'], $a['name']]
            <=> [(int) $a['likes_count'], (int) $a['is_top'], $b['name']];
    });

    $top = array_slice($top, 0, 16);

    render('merchants', [
        'top'     => $top,
        'groups'  => group_merchants($all),
        'total'   => count($all),
        'meta'    => meta_simple(
            'Магазины — промокоды и скидки ' . count($all) . ' брендов | ' . setting('site_name', cfg('app_name')),
            'Все магазины сообщества: ' . count($all) . ' ' . plural(count($all), 'бренд', 'бренда', 'брендов')
            . ' с проверенными промокодами и акциями на ' . month_ru()
            . '. Популярные: ' . implode(', ', array_slice(array_column($top, 'name'), 0, 6)) . '.'
        ),
    ]);
}

/**
 * Разложить магазины по буквам алфавита.
 * Цифры собираются в «0-9», всё прочее — в «#», чтобы ни один магазин
 * не потерялся из-за необычного первого символа.
 *
 * @return array<string,array<int,array<string,mixed>>>
 */
function group_merchants(array $merchants): array
{
    $groups = [];

    foreach ($merchants as $m) {
        $first = mb_strtoupper(mb_substr(trim($m['name']), 0, 1, 'UTF-8'), 'UTF-8');

        if (preg_match('/[0-9]/u', $first)) {
            $key = '0-9';
        } elseif (preg_match('/[A-ZА-ЯЁ]/u', $first)) {
            $key = $first === 'Ё' ? 'Е' : $first;
        } else {
            $key = '#';
        }

        $groups[$key][] = $m;
    }

    // порядок: спецсимволы, цифры, кириллица, латиница
    $order = array_merge(
        ['#', '0-9'],
        preg_split('//u', 'АБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЫЭЮЯ', -1, PREG_SPLIT_NO_EMPTY),
        range('A', 'Z')
    );

    $sorted = [];

    foreach ($order as $key) {
        if (! empty($groups[$key])) {
            usort($groups[$key], static fn ($a, $b) => strcoll($a['name'], $b['name']) ?: strcmp($a['name'], $b['name']));
            $sorted[$key] = $groups[$key];
        }
    }

    return $sorted;
}

function page_merchant(string $slug): void
{
    $merchant = q1('SELECT * FROM merchants WHERE slug = ?', [$slug]);

    if (! $merchant) {
        page_404();
    }

    $id = (int) $merchant['id'];

    // вкладки по типу: «Все / Промокоды / Скидки» с числом публикаций
    $typeTabs = ['all' => 'Все', 'voucher' => 'Промокоды', 'deal' => 'Скидки'];
    $vid = (string) input('vid', 'all');

    if (! array_key_exists($vid, $typeTabs)) {
        $vid = 'all';
    }

    $base = "status = 'published' AND merchant_id = ? AND (expires_at IS NULL OR expires_at > ?)";

    $counts = [
        'all'     => (int) qv("SELECT COUNT(*) FROM threads WHERE {$base}", [$id, now()]),
        'voucher' => (int) qv("SELECT COUNT(*) FROM threads WHERE {$base} AND type = 'voucher'", [$id, now()]),
        'deal'    => (int) qv("SELECT COUNT(*) FROM threads WHERE {$base} AND type = 'deal'", [$id, now()]),
    ];

    $filters = feed_filters();
    $options = $filters['options'];
    unset($filters['values']['shop']);
    $filters['count'] = count($filters['values']);

    $options['merchant_id'] = $id;
    $options['sort']        = sort_param();
    $options['page']        = (int) input('page', 1);

    if ($vid !== 'all') {
        $options['type'] = $vid;
        unset($filters['values']['type']);
        $filters['count'] = count($filters['values']);
    } else {
        $options['commercial_only'] = true;
    }

    $heading = match ($vid) {
        'voucher' => 'Промокоды ' . $merchant['name'],
        'deal'    => 'Скидки ' . $merchant['name'],
        default   => $merchant['name'],
    };

    $liked = auth_id() && qv('SELECT COUNT(*) FROM merchant_likes WHERE user_id = ? AND merchant_id = ?',
        [auth_id(), $id]) > 0;

    $result = feed_threads($options);

    render('merchant', [
        'merchant'   => $merchant,
        'heading'    => $heading,
        'result'     => $result,
        'meta'       => meta_for_merchant($merchant, $counts, $vid, $result['pager']),
        'sort'       => sort_param(),
        'filters'    => $filters,
        'sources'    => filter_sources(),
        'lockedShop' => true,
        'lockedType' => $vid !== 'all' ? $vid : null,
        'typeTabs'   => $typeTabs,
        'counts'     => $counts,
        'vid'        => $vid,
        'liked'      => $liked,
    ]);
}

function page_groups(): void
{
    $groups = q('SELECT g.*, (SELECT COUNT(*) FROM threads t WHERE t.group_id = g.id) AS threads_count
                 FROM groups_list g ORDER BY position');

    render('catalog', [
        'items'   => $groups,
        'kind'    => 'groups',
        'heading' => 'Обсуждения',
        'meta'    => meta_simple(
            'Обсуждения — разделы сообщества | ' . setting('site_name', cfg('app_name')),
            'Разделы для обсуждений: ' . implode(', ', array_slice(array_column($groups, 'name'), 0, 8))
            . '. Вопросы о покупках, доставке, возвратах и кэшбэке.'
        ),
    ]);
}

function page_group(string $slug): void
{
    $group = q1('SELECT * FROM groups_list WHERE slug = ?', [$slug]);

    if (! $group) {
        page_404();
    }

    $filters = feed_filters();
    $options = $filters['options'];

    $options['group_id'] = (int) $group['id'];
    $options['sort']     = sort_param();
    $options['page']     = (int) input('page', 1);

    $result = feed_threads($options);

    render('feed', [
        'noAutoload' => true,
        'result'      => $result,
        'meta'        => meta_for_group($group, (int) $result['pager']['total'], $result['pager']),
        'sort'        => sort_param(),
        'showTabs'    => true,
        'heading'     => $group['name'],
        'subheading'  => $group['description'],
        'actionUrl'   => url('/dobavit/obsuzhdenie'),
        'actionLabel' => 'Новое обсуждение',
        'emptyText'   => 'В разделе пока нет тем.',
        'filters'     => $filters,
        'sources'     => filter_sources(),
        'lockedType'  => 'discussion',
    ]);
}

function page_profile(string $username, string $tab = 'deals'): void
{
    $user = find_user($username);

    if (! $user) {
        page_404();
    }

    $tabs = ['deals' => 'Скидки', 'vouchers' => 'Промокоды', 'blog' => 'Блог',
             'comments' => 'Комментарии', 'ranks' => 'Ранги'];

    if (! array_key_exists($tab, $tabs)) {
        $tab = 'deals';
    }

    $uid = (int) $user['id'];

    $counts = [
        'threads'  => (int) qv('SELECT COUNT(*) FROM threads WHERE user_id = ?', [$uid]),
        'comments' => (int) qv('SELECT COUNT(*) FROM comments WHERE user_id = ?', [$uid]),
        'deals'    => (int) qv("SELECT COUNT(*) FROM threads WHERE user_id = ? AND type = 'deal' AND status = 'published'", [$uid]),
        'vouchers' => (int) qv("SELECT COUNT(*) FROM threads WHERE user_id = ? AND type = 'voucher' AND status = 'published'", [$uid]),
        'blog'     => (int) qv("SELECT COUNT(*) FROM threads WHERE user_id = ? AND type = 'blog' AND status = 'published'", [$uid]),
    ];

    $typeByTab = ['deals' => 'deal', 'vouchers' => 'voucher', 'blog' => 'blog'];

    $result = ['items' => [], 'pager' => paginate(0, cfg('per_page'), 1)];

    if (isset($typeByTab[$tab])) {
        $result = feed_threads([
            'user_id'         => $uid,
            'type'            => $typeByTab[$tab],
            'sort'            => 'new',
            'page'            => (int) input('page', 1),
            'include_expired' => true,
            'include_pending' => auth_id() === $uid || is_moderator(),
        ]);
    }

    $comments = $tab === 'comments'
        ? q('SELECT c.*, t.title AS thread_title, t.slug AS thread_slug
             FROM comments c JOIN threads t ON t.id = c.thread_id
             WHERE c.user_id = ? ORDER BY c.created_at DESC LIMIT 30', [$uid])
        : [];

    // У вкладок профиля были одинаковые title и description — вкладка
    // теперь попадает и туда, и туда, а пустые вкладки закрыты от индекса.
    $meta = meta_for_profile($user, $tab, $counts, $result['pager']);

    render('profile', compact('user', 'tab', 'tabs', 'counts', 'result', 'comments', 'meta'));
}

function page_bookmarks(): void
{
    $me  = require_auth();
    $ids = array_column(q('SELECT thread_id FROM bookmarks WHERE user_id = ? ORDER BY created_at DESC', [$me['id']]), 'thread_id');

    $result = $ids
        ? feed_threads(['ids' => $ids, 'sort' => 'new', 'page' => (int) input('page', 1)])
        : ['items' => [], 'pager' => paginate(0, cfg('per_page'), 1)];

    render('feed', [
        'noAutoload' => true,
        'result'    => $result,
        'sort'      => 'new',
        'showTabs'  => false,
        'heading'   => 'Закладки',
        'emptyText' => 'В закладках пока пусто.',
        'meta'      => meta_simple('Закладки — ' . setting('site_name', cfg('app_name')),
            'Сохранённые скидки и промокоды.', false),
    ]);
}

function page_settings(): void
{
    $me = require_auth();

    render('settings', [
        'me'     => $me,
        'errors' => errors_take(),
        'meta'   => meta_simple('Настройки профиля — ' . setting('site_name', cfg('app_name')),
            'Имя, аватар, описание и уведомления.', false),
    ]);
}

function page_login(): void
{
    if (auth_id()) {
        redirect('/');
    }

    render('login', [
        'errors' => errors_take(),
        'old'    => old_take(),
        'meta'   => meta_simple('Вход — ' . setting('site_name', cfg('app_name')),
            'Вход в аккаунт: публикуйте находки, копите Огоньки и следите за любимыми магазинами.'),
    ]);
}

function page_register(): void
{
    if (auth_id()) {
        redirect('/');
    }

    if (setting('registration_open', '1') !== '1') {
        flash('Регистрация временно закрыта администратором.', 'error');
        redirect('/vhod');
    }

    render('register', [
        'errors' => errors_take(),
        'old'    => old_take(),
        'meta'   => meta_simple('Регистрация — ' . setting('site_name', cfg('app_name')),
            'Регистрация в сообществе охотников за скидками: публикации, голоса, Огоньки за находки.'),
    ]);
}

function page_publish_index(): void
{
    require_auth();

    render('publish_index', [
        'meta' => meta_simple('Что опубликовать? — ' . setting('site_name', cfg('app_name')),
            'Скидка, промокод, запись в блоге или обсуждение.', false),
    ]);
}

function page_publish_form(string $typeSlug): void
{
    require_auth();

    $type = type_by_slug($typeSlug);

    if (! $type) {
        page_404();
    }

    render('publish_form', [
        'type'       => $type,
        'categories' => category_tree(),
        'groups'     => q('SELECT * FROM groups_list ORDER BY position'),
        'merchants'  => q('SELECT id, name FROM merchants ORDER BY is_top DESC, name'),
        'errors'     => errors_take(),
        'old'        => old_take(),
        'meta'       => meta_simple('Добавить: ' . type_label($type) . ' — ' . setting('site_name', cfg('app_name')),
            'Форма публикации.', false),
    ]);
}

function page_404(): void
{
    http_response_code(404);

    render('feed', [
        'noAutoload' => true,
        'result'    => ['items' => [], 'pager' => paginate(0, 15, 1)],
        'sort'      => 'hot',
        'showTabs'  => false,
        'heading'   => 'Страница не найдена',
        'emptyText' => 'Такой страницы нет. Возможно, публикацию удалили.',
        'meta'      => meta_simple('Страница не найдена — ' . setting('site_name', cfg('app_name')),
            'Такой страницы нет. Возможно, публикацию удалили или адрес набран с ошибкой.', false),
    ]);
}

function page_thread_edit(string $slug): void
{
    $me = require_auth();
    $t  = find_thread($slug);

    if (! $t) {
        page_404();
    }

    if ((int) $t['user_id'] !== (int) $me['id']) {
        // модератору — полный редактор в панели
        if (is_moderator()) {
            redirect('/admin/publikacii/' . (int) $t['id']);
        }

        http_response_code(403);
        exit('Можно редактировать только свои публикации.');
    }

    $selected = array_map('intval', array_column(
        q('SELECT category_id FROM category_thread WHERE thread_id = ?', [$t['id']]), 'category_id'));

    render('thread_edit', [
        't'          => $t,
        'categories' => category_tree(),
        'groups'     => q('SELECT * FROM groups_list ORDER BY position'),
        'merchants'  => q('SELECT id, name FROM merchants ORDER BY is_top DESC, name'),
        'selected'   => $selected,
        'errors'     => errors_take(),
        'old'        => old_take(),
        'meta'       => meta_simple('Редактирование: ' . excerpt($t['title'], 50), 'Редактирование публикации.', false),
    ]);
}

function page_notifications(): void
{
    $me = require_auth();

    $total = (int) qv('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$me['id']]);
    $pager = paginate($total, 30, (int) input('page', 1));

    $items = notifications_for((int) $me['id'], 30, $pager['offset']);

    // открыли список — значит прочитали
    mark_notifications_read();

    render('notifications', [
        'items' => $items,
        'pager' => $pager,
        'meta'  => meta_simple('Уведомления — ' . setting('site_name', cfg('app_name')),
            'Ответы на публикации, комментарии и подписки.', false),
    ]);
}

/**
 * Разбор фильтров из адреса. Возвращает и опции для выборки,
 * и исходные значения — чтобы форма показывала выбранное.
 *
 * @return array{options: array, values: array, count: int}
 */
function feed_filters(): array
{
    $options = [];
    $values  = [];

    $type = (string) input('type', '');

    if (array_key_exists($type, cfg('thread_types'))) {
        $options['type'] = $type;
        $values['type']  = $type;
    }

    foreach (['cat' => 'category_id', 'shop' => 'merchant_id'] as $key => $option) {
        $id = (int) input($key, 0);

        if ($id > 0) {
            $options[$option] = $id;
            $values[$key]     = $id;
        }
    }

    foreach (['pmin' => 'price_min', 'pmax' => 'price_max'] as $key => $option) {
        $raw = input($key, '');

        if ($raw !== '' && is_numeric($raw)) {
            $options[$option] = (float) $raw;
            $values[$key]     = $raw;
        }
    }

    $discount = (int) input('disc', 0);

    if ($discount > 0) {
        $options['min_discount'] = min(99, $discount);
        $values['disc']          = $options['min_discount'];
    }

    if (input('exp')) {
        $options['include_expired'] = true;
        $options['only_expired']    = true;
        $values['exp']              = 1;
    }

    foreach (['hot' => 'only_hot', 'code' => 'has_code', 'free' => 'free', 'img' => 'with_image'] as $key => $option) {
        if (input($key)) {
            $options[$option] = true;
            $values[$key]     = 1;
        }
    }

    return ['options' => $options, 'values' => $values, 'count' => count($values)];
}

/** Списки для выпадающих полей фильтра. */
function filter_sources(): array
{
    return [
        'categories' => q('SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY position'),
        'merchants'  => q('SELECT id, name FROM merchants ORDER BY is_top DESC, name'),
    ];
}

/* ------------------------------------------------- восстановление пароля */

function page_forgot(): void
{
    if (auth_id()) {
        redirect('/');
    }

    render('forgot', [
        'errors' => errors_take(),
        'sent'   => ! empty($_SESSION['reset_sent']),
        'meta'   => meta_simple('Восстановление пароля — ' . setting('site_name', cfg('app_name')),
            'Восстановление доступа к аккаунту по электронной почте.', false),
    ]);
}

function act_forgot(): void
{
    csrf_check();

    $email = (string) input('email');

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        errors_set(['Укажите корректный e-mail.']);
        redirect('/vosstanovlenie');
    }

    // не чаще одного письма в пять минут: иначе формой можно завалить
    // чужой ящик сотней сообщений
    if (! throttle('reset', 60)) {
        flash('Письмо уже отправлено. Повторить можно через минуту.', 'error');
        redirect('/vosstanovlenie');
    }

    // Ответ одинаков независимо от того, есть такой адрес или нет:
    // иначе форму можно использовать для проверки чужой почты на регистрацию.
    $token = password_reset_create($email);

    if ($token) {
        password_reset_send($email, $token);
    }

    $_SESSION['reset_sent'] = true;
    redirect('/vosstanovlenie');
}

function page_reset(string $token): void
{
    unset($_SESSION['reset_sent']);

    $user = password_reset_user($token);

    if (! $user) {
        flash('Ссылка недействительна или устарела. Запросите новую.', 'error');
        redirect('/vosstanovlenie');
    }

    render('reset', [
        'errors' => errors_take(),
        'token'  => $token,
        'user'   => $user,
        'meta'   => meta_simple('Новый пароль — ' . setting('site_name', cfg('app_name')),
            'Установка нового пароля.', false),
    ]);
}

function act_reset(string $token): void
{
    csrf_check();

    $password = (string) input('password');
    $confirm  = (string) input('password_confirmation');
    $errors   = [];

    if (mb_strlen($password) < 8) {
        $errors[] = 'Пароль должен быть не короче 8 символов.';
    }

    if ($password !== $confirm) {
        $errors[] = 'Пароли не совпадают.';
    }

    if ($errors) {
        errors_set($errors);
        redirect('/vosstanovlenie/' . rawurlencode($token));
    }

    if (! password_reset_apply($token, $password)) {
        flash('Ссылка недействительна или устарела. Запросите новую.', 'error');
        redirect('/vosstanovlenie');
    }

    unset($_SESSION['reset_sent']);

    flash('Пароль изменён. Теперь можно войти.');
    redirect('/vhod');
}

function page_unsubscribe(string $token): void
{
    $user = q1('SELECT * FROM users WHERE unsubscribe_token = ?', [$token]);

    if ($user) {
        update('users', (int) $user['id'], ['email_notifications' => 0]);
        flash('Письма отключены. Уведомления останутся на сайте, в колокольчике.');
    } else {
        flash('Ссылка недействительна.', 'error');
    }

    redirect('/');
}

/**
 * Отписка от рекламной рассылки.
 *
 * Отдельная ссылка, а не общая: человек, которому надоели письма о скидках,
 * не должен заодно терять уведомления об ответах на свои комментарии.
 */
function page_unsubscribe_ads(string $token): void
{
    $user = q1('SELECT * FROM users WHERE unsubscribe_token = ?', [$token]);

    if ($user) {
        update('users', (int) $user['id'], ['ads_subscribed' => 0]);
        record_consent('advertising', ['ads'], 'declined', (int) $user['id']);

        flash('Рассылка о скидках отключена. Уведомления об ответах и решениях модератора остаются.');
    } else {
        flash('Ссылка недействительна.', 'error');
    }

    redirect('/');
}

function page_help(): void
{
    render('help', [
        'meta' => meta_simple(
            'Как это работает — правила, Огоньки и градусы | ' . setting('site_name', cfg('app_name')),
            'Как устроено сообщество: публикация скидок и промокодов, голоса «горячо» и «холодно», '
            . 'градусы, репутация, Огоньки и вывод. Правила модерации и советы новичкам.'
        ),
    ]);
}

/** Проверка промокода на дубль — дергается из формы публикации по мере ввода. */
function page_check_code(): void
{
    $code    = (string) input('code', '');
    $exclude = (int) input('exclude', 0);

    $owner = $code !== '' ? find_code_owner($code, $exclude ?: null) : null;

    json_out([
        'exists' => $owner !== null,
        'title'  => $owner['title'] ?? null,
        'url'    => $owner ? url('/post/' . rawurlencode($owner['slug'])) : null,
    ]);
}

/**
 * Раздел «Блоги»: витрина авторов и лента записей.
 *
 * Топ считается по числу опубликованных записей и сумме градусов:
 * один популярный пост не должен выносить автора выше того, кто
 * пишет регулярно.
 */
function page_blogs(): void
{
    $authors = q("SELECT u.id, u.username, u.avatar, u.reputation,
                         COUNT(t.id) AS posts,
                         COALESCE(SUM(CASE WHEN t.temperature > 0 THEN t.temperature ELSE 0 END), 0) AS heat
                  FROM users u
                  JOIN threads t ON t.user_id = u.id AND t.type = 'blog' AND t.status = 'published'
                  GROUP BY u.id, u.username, u.avatar, u.reputation
                  ORDER BY posts DESC, heat DESC, u.username
                  LIMIT 10");

    $sort = sort_param();

    $result = feed_threads([
        'type' => 'blog',
        'sort' => $sort,
        'page' => (int) input('page', 1),
    ]);

    $total = (int) qv("SELECT COUNT(*) FROM threads WHERE type = 'blog' AND status = 'published'");

    render('blogs', [
        'authors'   => $authors,
        'result'    => $result,
        'sort'      => $sort,
        'total'     => $total,
        'meta'      => meta_for_blogs($total, $result['pager']),
    ]);
}

/**
 * Страница промокода — её открывает новая вкладка при нажатии «Показать».
 * Здесь код виден целиком и сразу лежит в буфере обмена; исходная вкладка
 * в это время уходит в магазин.
 */
function page_coupon(string $slug): void
{
    $t = find_thread($slug);

    if (! $t || $t['type'] !== 'voucher' || ! $t['code']) {
        page_404();
    }

    if (in_array($t['status'], ['draft', 'rejected', 'pending'], true)
        && auth_id() !== (int) $t['user_id'] && ! is_moderator()) {
        page_404();
    }

    // переход считаем здесь же: человек добрался до кода, значит предложение сработало
    qx('UPDATE threads SET clicks_count = clicks_count + 1 WHERE id = ?', [$t['id']]);

    render('coupon', [
        't'    => $t,
        // страница-пустышка для копирования кода: в индексе не нужна
        'meta' => meta_simple($t['title'] . ' — промокод ' . ($t['code'] ?? ''),
            'Промокод ' . ($t['merchant_name'] ?: '') . ': ' . excerpt($t['title'], 100), false),
    ]);
}

/**
 * Проверка живости для внешнего монитора.
 *
 * Отдаёт 200, пока сайт способен работать: база отвечает, папка данных
 * пишется. Если что-то отвалилось — 503, и сервис мониторинга присылает
 * уведомление. Проверять главную страницу для этого мало: она может
 * открыться и тогда, когда база уже недоступна.
 */
function page_health(): void
{
    $checks = ['db' => false, 'data' => false];

    try {
        $checks['db'] = (int) qv('SELECT 1') === 1;
    } catch (Throwable $e) {
        $checks['db'] = false;
    }

    $checks['data'] = is_writable(__DIR__ . '/../data');

    $ok = ! in_array(false, $checks, true);

    http_response_code($ok ? 200 : 503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode(['ok' => $ok, 'checks' => $checks, 'time' => now()], JSON_UNESCAPED_UNICODE);
    exit;
}
