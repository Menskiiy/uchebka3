<?php
/** Выборки данных. Одно место на все страницы. */

const THREAD_SELECT = "
    SELECT t.*,
           u.username AS author_name, u.avatar AS author_avatar, u.reputation AS author_reputation,
           u.is_moderator AS author_moderator, u.is_editor AS author_editor, u.is_bot AS author_bot,
           m.name AS merchant_name, m.slug AS merchant_slug,
           m.logo_color AS merchant_color, m.logo AS merchant_logo,
           g.name AS group_name, g.slug AS group_slug
    FROM threads t
    JOIN users u ON u.id = t.user_id
    LEFT JOIN merchants m ON m.id = t.merchant_id
    LEFT JOIN groups_list g ON g.id = t.group_id
";

function feed_threads(array $options = []): array
{
    $where  = ["t.status = 'published'"];
    $params = [];

    if (! empty($options['type'])) {
        $where[] = 't.type = :type';
        $params['type'] = $options['type'];
    }

    // Главная лента — только скидки и промокоды. Обсуждения и обратная
    // связь живут в своём разделе: на главной они забивают ленту темами,
    // за которыми люди сюда не приходят.
    if (! empty($options['commercial_only'])) {
        $where[] = "t.type IN ('deal', 'voucher')";
    }

    // Истёкшие предложения по умолчанию скрыты. Статус переключается
    // раз в час обслуживанием, поэтому проверяем ещё и саму дату —
    // иначе просроченная скидка висела бы в ленте до следующего запуска.
    if (empty($options['include_expired'])) {
        $where[] = '(t.expires_at IS NULL OR t.expires_at > :nowts)';
        $params['nowts'] = now();
    } else {
        $where[0] = "t.status IN ('published', 'expired')";
    }

    // свои публикации автор видит всегда, включая ожидающие проверки —
    // иначе пост будто пропадает, и человек публикует его заново
    if (! empty($options['include_pending'])) {
        $where[0] = "t.status IN ('published', 'expired', 'pending')";
    }

    // используется счётчиком новых публикаций
    if (! empty($options['count_since'])) {
        $where[] = 't.published_at > :since_ts';
        $params['since_ts'] = $options['count_since'];
    }

    if (! empty($options['only_expired'])) {
        $where[] = '(t.expires_at IS NOT NULL AND t.expires_at <= :nowts2)';
        $params['nowts2'] = now();
    }

    /*
     * Вкладка «Сгорает»: предложения, у которых срок кончается в ближайшие
     * двое суток. Именно окно, а не «завтра»: акция, истекающая сегодня
     * ночью, и акция, истекающая завтра вечером, для человека одинаково
     * срочные, а календарная граница разнесла бы их по разным дням.
     */
    if (! empty($options['expiring_soon'])) {
        $where[] = '(t.expires_at IS NOT NULL AND t.expires_at > :exp_from AND t.expires_at <= :exp_to)';
        $params['exp_from'] = now();
        $params['exp_to']   = date('Y-m-d H:i:s', time() + EXPIRING_WINDOW);
    }

    if (! empty($options['merchant_id'])) {
        $where[] = 't.merchant_id = :mid';
        $params['mid'] = $options['merchant_id'];
    }

    if (! empty($options['group_id'])) {
        $where[] = 't.group_id = :gid';
        $params['gid'] = $options['group_id'];
    }

    if (! empty($options['category_id'])) {
        $where[] = 't.id IN (SELECT thread_id FROM category_thread WHERE category_id = :cid)';
        $params['cid'] = $options['category_id'];
    }

    if (! empty($options['user_id'])) {
        $where[] = 't.user_id = :uid';
        $params['uid'] = $options['user_id'];
    }

    if (! empty($options['ids'])) {
        $ids = array_map('intval', $options['ids']);
        $where[] = 't.id IN (' . ($ids ? implode(',', $ids) : '0') . ')';
    }

    if (! empty($options['search'])) {
        // у каждого вхождения свой плейсхолдер: при нативных запросах MySQL
        // повторное использование одного имени вызывает ошибку PDO
        $where[] = '(t.title LIKE :s1 OR t.body LIKE :s2 OR t.code LIKE :s3)';

        $like = '%' . $options['search'] . '%';
        $params['s1'] = $like;
        $params['s2'] = $like;
        $params['s3'] = $like;
    }

    /* ------------------------------------------------------------ фильтры */

    if (isset($options['price_min']) && $options['price_min'] !== '') {
        $where[] = 't.price >= :pmin';
        $params['pmin'] = (float) $options['price_min'];
    }

    if (isset($options['price_max']) && $options['price_max'] !== '') {
        $where[] = 't.price <= :pmax';
        $params['pmax'] = (float) $options['price_max'];
    }

    if (! empty($options['min_discount'])) {
        // скидка бывает записана явно или считается из пары цен;
        // old_price > 0 обязателен, иначе деление на ноль
        $where[] = '(t.discount_percent >= :disc1
                     OR (t.old_price > 0 AND t.price IS NOT NULL
                         AND (1 - t.price / t.old_price) * 100 >= :disc2))';

        $params['disc1'] = (int) $options['min_discount'];
        $params['disc2'] = (int) $options['min_discount'];
    }

    if (! empty($options['only_hot'])) {
        $where[] = 't.temperature >= :hot';
        $params['hot'] = (int) cfg('temperature.hot_threshold');
    }

    if (! empty($options['has_code'])) {
        $where[] = "t.code IS NOT NULL AND t.code <> ''";
    }

    if (! empty($options['free'])) {
        $where[] = 't.price = 0';
    }

    if (! empty($options['with_image'])) {
        $where[] = "(t.image_path IS NOT NULL OR (t.image_url IS NOT NULL AND t.image_url <> ''))";
    }

    $order = match ($options['sort'] ?? 'hot') {
        'new'       => 't.published_at DESC',
        'discussed' => 't.comments_count DESC, t.published_at DESC',
        // во вкладке «Сгорает» градусы не главное: сверху то, что кончается
        // раньше всех, иначе смысл срочности теряется
        'expiring'  => 't.expires_at ASC',
        default     => 't.temperature DESC, t.published_at DESC',
    };

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $total    = (int) qv("SELECT COUNT(*) FROM threads t {$whereSql}", $params);

    $perPage = (int) ($options['per_page'] ?? cfg('per_page'));
    $pager   = paginate($total, $perPage, (int) ($options['page'] ?? 1));

    // Восстановление ленты после «Назад» просит сразу несколько страниц
    // одним запросом, поэтому смещение можно задать явно.
    $offset = isset($options['offset']) ? max(0, (int) $options['offset']) : (int) $pager['offset'];

    $pager['offset'] = $offset;

    $rows = q(
        THREAD_SELECT . " {$whereSql} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}",
        $params
    );

    preload_votes('thread', array_column($rows, 'id'));

    return ['items' => $rows, 'pager' => $pager];
}

function find_thread(string $slug): ?array
{
    return q1(THREAD_SELECT . ' WHERE t.slug = ?', [$slug]);
}

function thread_categories(int $threadId): array
{
    return q('SELECT c.* FROM categories c
              JOIN category_thread ct ON ct.category_id = c.id
              WHERE ct.thread_id = ?', [$threadId]);
}

/** Общий кеш голосов на время запроса — доступ по ссылке, чтобы делили одну память. */
function &vote_cache(): array
{
    static $cache = [];

    return $cache;
}

/**
 * Голос текущего пользователя: 'hot' | 'cold' | null.
 *
 * Значения берутся из кеша, который заполняется одним запросом на всю
 * страницу. Без него каждая карточка ленты делала свой запрос —
 * пятнадцать штук там, где хватает одного.
 */
function my_vote(string $type, int $id): ?string
{
    if (! auth_id()) {
        return null;
    }

    $cache = &vote_cache();
    $key   = $type . ':' . $id;

    if (! array_key_exists($key, $cache)) {
        $cache[$key] = qv('SELECT direction FROM votes WHERE user_id = ? AND votable_type = ? AND votable_id = ?',
            [auth_id(), $type, $id]) ?: null;
    }

    return $cache[$key];
}

/** Заранее забрать голоса за перечисленные объекты одним запросом. */
function preload_votes(string $type, array $ids): void
{
    if (! auth_id() || ! $ids) {
        return;
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));

    if (! $ids) {
        return;
    }

    $rows = q('SELECT votable_id, direction FROM votes
               WHERE user_id = ? AND votable_type = ? AND votable_id IN (' . implode(',', $ids) . ')',
        [auth_id(), $type]);

    $found = [];

    foreach ($rows as $row) {
        $found[(int) $row['votable_id']] = $row['direction'];
    }

    $cache = &vote_cache();

    // отсутствующие голоса тоже кладём в кеш, иначе по ним пойдут отдельные запросы
    foreach ($ids as $id) {
        $cache[$type . ':' . $id] = $found[$id] ?? null;
    }
}

function is_bookmarked(int $threadId): bool
{
    if (! auth_id()) {
        return false;
    }

    return qv('SELECT COUNT(*) FROM bookmarks WHERE user_id = ? AND thread_id = ?',
        [auth_id(), $threadId]) > 0;
}

/** Комментарии деревом: корневые с вложенными ответами. */
function thread_comments(int $threadId): array
{
    $rows = q('SELECT c.*, u.username AS author_name, u.avatar AS author_avatar,
           u.is_moderator AS author_moderator, u.is_editor AS author_editor, u.is_bot AS author_bot
               FROM comments c JOIN users u ON u.id = c.user_id
               WHERE c.thread_id = ? ORDER BY c.created_at ASC', [$threadId]);

    preload_votes('comment', array_column($rows, 'id'));

    $byParent = [];

    foreach ($rows as $row) {
        $byParent[$row['parent_id'] ?? 0][] = $row;
    }

    return $byParent;
}

function sidebar_data(): array
{
    // в «горячем» и «новых» только скидки и промокоды: блог и обсуждения
    // живут в своих блоках, иначе они вытесняли предложения
    $commercial = "t.status = 'published' AND t.type IN ('deal', 'voucher')
                   AND (t.expires_at IS NULL OR t.expires_at > :nowts)";

    return [
        /*
         * «Новое» живёт в колонке, а не полосой над лентой: карусель наверху
         * показывает лучшее за период, и свежие находки в неё не попадают,
         * но второй полосой это занимало пол-экрана на телефоне.
         */
        'fresh'   => q(THREAD_SELECT . " WHERE {$commercial}
                        ORDER BY t.published_at DESC LIMIT 5", ['nowts' => now()]),
        'discussions' => q(THREAD_SELECT . " WHERE t.status = 'published' AND t.type = 'discussion'
                            ORDER BY t.published_at DESC LIMIT 5"),
        'blogs'   => q(THREAD_SELECT . " WHERE t.status = 'published' AND t.type = 'blog'
                        ORDER BY t.published_at DESC LIMIT 4"),
        'comments' => q("SELECT c.id, c.body, c.created_at,
                                u.username AS author_name, u.avatar AS author_avatar,
           u.is_moderator AS author_moderator, u.is_editor AS author_editor, u.is_bot AS author_bot,
                                t.title AS thread_title, t.slug AS thread_slug
                         FROM comments c
                         JOIN users u ON u.id = c.user_id
                         JOIN threads t ON t.id = c.thread_id
                         WHERE t.status = 'published'
                         ORDER BY c.created_at DESC LIMIT 5"),
        // порядок по лайкам: блок «Топ магазины» отражает выбор посетителей,
        // флаг is_top остаётся ручной страховкой для новых магазинов без оценок
        'top'     => q('SELECT * FROM merchants ORDER BY likes_count DESC, is_top DESC, name LIMIT 12'),
    ];
}

function find_user(string $username): ?array
{
    // удалённый профиль на сайте не показываем: человек его закрыл
    return q1('SELECT * FROM users WHERE username = ? AND deleted_at IS NULL', [$username]);
}

function merchant_by_domain(?string $url): ?array
{
    if (! $url) {
        return null;
    }

    $host = parse_url($url, PHP_URL_HOST);

    if (! $host) {
        return null;
    }

    $host = preg_replace('/^www\./', '', strtolower($host));

    return q1('SELECT * FROM merchants WHERE domain = ?', [$host]);
}

function mod_log(string $action, array $payload = []): void
{
    insert('moderation_logs', [
        'moderator_id' => auth_id(),
        'action'       => $action,
        'payload'      => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        'created_at'   => now(),
    ]);
}

function action_label(string $action): string
{
    return [
        'thread_rejected'  => 'Публикация снята',
        'thread_restored'  => 'Публикация восстановлена',
        'thread_deleted'   => 'Публикация удалена',
        'comment_deleted'  => 'Комментарий удалён',
        'user_banned'      => 'Пользователь заблокирован',
        'user_unbanned'    => 'Блокировка снята',
        'report_accepted'  => 'Жалоба принята',
        'report_dismissed' => 'Жалоба отклонена',
        'thread_verified'  => 'Публикация проверена',
        'thread_unverified'=> 'Отметка о проверке снята',
        'taxonomy_saved'   => 'Справочник изменён',
        'taxonomy_deleted' => 'Запись справочника удалена',
        'thread_edited'    => 'Публикация отредактирована',
        'role_granted'     => 'Выданы права модератора',
        'role_revoked'     => 'Сняты права модератора',
        'page_saved'       => 'Текст страницы изменён',
    ][$action] ?? $action;
}

/* --------------------------------------------------------- уведомления */

/**
 * Создать уведомление. Себе уведомления не шлём — это шум.
 */
function notify(int $userId, string $type, array $data = []): void
{
    if ($userId <= 0 || $userId === auth_id()) {
        return;
    }

    // письмо возвращает человека на сайт; без отписки это был бы спам
    mail_notification($userId, $type, $data);

    insert('notifications', [
        'user_id'    => $userId,
        'actor_id'   => $data['actor_id'] ?? auth_id(),
        'type'       => $type,
        'thread_id'  => $data['thread_id'] ?? null,
        'comment_id' => $data['comment_id'] ?? null,
        'payload'    => isset($data['payload']) ? json_encode($data['payload'], JSON_UNESCAPED_UNICODE) : null,
        'read_at'    => null,
        'created_at' => now(),
    ]);
}

function unread_count(): int
{
    static $count = null;

    if ($count !== null) {
        return $count;
    }

    if (! auth_id()) {
        return $count = 0;
    }

    return $count = (int) qv('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [auth_id()]);
}

function notifications_for(int $userId, int $limit = 30, int $offset = 0): array
{
    return q('SELECT n.*, u.username AS actor_name, u.avatar AS actor_avatar,
                     t.title AS thread_title, t.slug AS thread_slug
              FROM notifications n
              LEFT JOIN users u ON u.id = n.actor_id
              LEFT JOIN threads t ON t.id = n.thread_id
              WHERE n.user_id = ?
              ORDER BY n.created_at DESC
              LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, [$userId]);
}

function mark_notifications_read(?int $id = null): void
{
    if (! auth_id()) {
        return;
    }

    if ($id) {
        qx('UPDATE notifications SET read_at = ? WHERE id = ? AND user_id = ? AND read_at IS NULL',
            [now(), $id, auth_id()]);

        return;
    }

    qx('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now(), auth_id()]);
}

/** Текст уведомления собирается из типа — в базе хранятся только связи. */
function notification_text(array $n): string
{
    $actor  = $n['actor_name'] ?: 'Кто-то';
    $title  = $n['thread_title'] ? excerpt($n['thread_title'], 55) : 'публикацию';
    $extra  = $n['payload'] ? (json_decode($n['payload'], true) ?: []) : [];

    return match ($n['type']) {
        'comment'         => e($actor) . ' прокомментировал(а) «' . e($title) . '»',
        'reply'           => e($actor) . ' ответил(а) на ваш комментарий в «' . e($title) . '»',
        'comment_like'    => e($actor) . ' оценил(а) ваш комментарий в «' . e($title) . '»',
        'thread_hot'      => 'Ваша публикация «' . e($title) . '» стала горячей: '
                             . (int) ($extra['temperature'] ?? 0) . '&deg;',
        'thread_rejected' => 'Публикация «' . e($title) . '» не прошла проверку'
                             . (! empty($extra['reason']) ? ': ' . e($extra['reason']) : '')
                             . (! empty($extra['link'])
                                ? ' — <a href="' . e($extra['link']) . '" target="_blank" rel="noopener nofollow">как надо оформить</a>'
                                : ''),
        'thread_restored' => 'Публикация «' . e($title) . '» возвращена в ленту',
        'thread_approved' => 'Публикация «' . e($title) . '» одобрена и появилась в ленте',
        'thread_edited'   => 'Модератор отредактировал «' . e($title) . '»',
        'thread_verified' => 'Ваша публикация «' . e($title) . '» отмечена как проверенная',
        'follow_post'     => e($actor) . ' опубликовал(а): «' . e($title) . '»',
        default           => 'Обновление по «' . e($title) . '»',
    };
}

function notification_icon(string $type): string
{
    $name = match ($type) {
        'comment', 'reply'  => 'comment',
        'comment_like'      => 'like',
        'thread_hot'        => 'flame',
        'thread_rejected'   => 'flag',
        'thread_restored'   => 'check',
        'thread_approved'   => 'check',
        'thread_verified'   => 'shield',
        'thread_edited'     => 'edit',
        'follow_post'       => 'user',
        default             => 'bell',
    };

    return icon($name);
}

function notification_url(array $n): string
{
    if (! $n['thread_slug']) {
        return url('/uvedomleniya');
    }

    $link = url('/post/' . rawurlencode($n['thread_slug']));

    return $n['comment_id'] ? $link . '#comment-' . (int) $n['comment_id'] : $link;
}

/**
 * Категории для выбора при публикации: верхние уровни вместе с вложенными.
 * Раньше в форму попадали только родительские, и «Еда» или «Смартфоны»
 * выбрать было нельзя — подкатегории существовали, но не использовались.
 *
 * @return array<int,array{id:int,name:string,children:array}>
 */
function category_tree(): array
{
    $all = q('SELECT id, parent_id, name FROM categories ORDER BY position, name');

    $tree = [];

    foreach ($all as $row) {
        if ($row['parent_id'] === null) {
            $tree[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => $row['name'], 'children' => []];
        }
    }

    foreach ($all as $row) {
        $parent = (int) ($row['parent_id'] ?? 0);

        if ($parent && isset($tree[$parent])) {
            $tree[$parent]['children'][] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }
    }

    return array_values($tree);
}

/** Письмо о событии — только если человек его не отключил. */
function mail_notification(int $userId, string $type, array $data): void
{
    // о мелочах не пишем: лайк комментария письма не стоит
    if (! in_array($type, ['comment', 'reply', 'thread_approved', 'thread_rejected', 'thread_verified', 'follow_post'], true)) {
        return;
    }

    $user = q1('SELECT * FROM users WHERE id = ?', [$userId]);

    if (! $user || (int) $user['email_notifications'] !== 1 || empty($user['email_verified_at'])) {
        return;
    }

    $thread = ! empty($data['thread_id'])
        ? q1('SELECT title, slug FROM threads WHERE id = ?', [$data['thread_id']])
        : null;

    if (! $thread) {
        return;
    }

    $link    = site_url() . thread_url($thread);
    $actor   = auth() ? auth()['username'] : 'Модератор';
    $payload = $data['payload'] ?? [];

    $subject = match ($type) {
        'comment'         => 'Новый комментарий к вашей публикации',
        'reply'           => 'Вам ответили',
        'thread_approved' => 'Публикация одобрена',
        'thread_rejected' => 'Публикация снята с ленты',
        'follow_post'     => 'Новая публикация по вашей подписке',
        default           => 'Публикация проверена',
    };

    $intro = match ($type) {
        'comment'         => $actor . ' прокомментировал(а) «' . $thread['title'] . '».',
        'reply'           => $actor . ' ответил(а) на ваш комментарий в «' . $thread['title'] . '».',
        'thread_approved' => 'Ваша публикация «' . $thread['title'] . '» прошла проверку и появилась в ленте.',
        'thread_rejected' => 'Публикация «' . $thread['title'] . '» не прошла проверку.',
        'follow_post'     => $actor . ' опубликовал(а) «' . $thread['title'] . '» — по вашей подписке.',
        default           => 'Публикация «' . $thread['title'] . '» отмечена как проверенная.',
    };

    if (empty($user['unsubscribe_token'])) {
        $token = bin2hex(random_bytes(16));
        update('users', $userId, ['unsubscribe_token' => $token]);
    } else {
        $token = $user['unsubscribe_token'];
    }

    $blocks = [$intro];

    // причина отказа нужна прямо в письме: иначе человек идёт на сайт
    // выяснять, что не так, и часть просто не возвращается
    if ($type === 'thread_rejected' && ! empty($payload['reason'])) {
        $blocks[] = 'Причина: ' . $payload['reason'];
    }

    if ($type === 'thread_rejected' && ! empty($payload['link'])) {
        $blocks[] = 'Как оформить публикацию: ' . $payload['link'];
    }

    if ($type === 'thread_rejected') {
        $blocks[] = 'Исправьте публикацию и сохраните — она автоматически уйдёт на повторную проверку.';
    }

    $blocks[] = ['button' => ['text' => 'Открыть публикацию', 'url' => $link]];
    $blocks[] = ['small' => 'Не хотите получать такие письма? '
        . site_url() . url('/otpiska/' . $token)];

    send_mail($user['email'], $subject, $subject, $blocks);
}

/**
 * Магазины, подходящие под поисковый запрос — по названию и по домену.
 * Показываются отдельным блоком над обычными результатами: если ищут
 * «2ГИС», человек ждёт увидеть сам магазин, а не только посты про него.
 */
function search_merchants(string $term, int $limit = 6): array
{
    if (mb_strlen(trim($term)) < 2) {
        return [];
    }

    // отдельный плейсхолдер на каждое вхождение: MySQL с нативными
    // запросами не допускает повторного использования одного имени
    $like = '%' . $term . '%';

    return q('SELECT * FROM merchants WHERE name LIKE :q1 OR domain LIKE :q2
              ORDER BY likes_count DESC, is_top DESC, name LIMIT ' . (int) $limit,
        ['q1' => $like, 'q2' => $like]);
}

/**
 * Публикация с таким же промокодом, если она уже есть.
 * Регистр не важен: PROMO2026 и promo2026 — один и тот же код для
 * покупателя, и дублировать публикацию с ним смысла нет.
 */
function find_code_owner(string $code, ?int $excludeId = null): ?array
{
    $code = trim($code);

    if ($code === '') {
        return null;
    }

    $sql = "SELECT title, slug FROM threads
            WHERE type = 'voucher' AND status = 'published' AND UPPER(code) = UPPER(:code)";
    $params = ['code' => $code];

    if ($excludeId) {
        $sql .= ' AND id != :id';
        $params['id'] = $excludeId;
    }

    return q1($sql . ' LIMIT 1', $params);
}

/** Найти магазин по названию из поля с подсказками. Регистр не важен. */
function merchant_by_name(?string $name): ?array
{
    $name = trim((string) $name);

    if ($name === '') {
        return null;
    }

    return q1('SELECT * FROM merchants WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1', [$name]);
}

/**
 * Подборка самых горячих предложений для карусели.
 *
 * Период задаётся отдельно от ленты: на молодом сайте за сутки может
 * не набраться ничего, поэтому при пустой выборке берём более широкий
 * промежуток — карусель не должна показывать пустоту.
 *
 * @return array{items: array, period: string, fallback: bool}
 */
function hot_carousel(string $period = 'week', int $limit = 10): array
{
    $windows = ['day' => 1, 'week' => 7, 'month' => 30, 'all' => 0];

    if (! isset($windows[$period])) {
        $period = 'week';
    }

    $fetch = static function (int $days) use ($limit): array {
        $where  = "t.status = 'published'
                   AND t.type IN ('deal', 'voucher')
                   AND (t.expires_at IS NULL OR t.expires_at > :nowts)
                   AND t.temperature > 0";
        $params = ['nowts' => now()];

        if ($days > 0) {
            $where .= ' AND t.published_at > :since';
            $params['since'] = date('Y-m-d H:i:s', time() - $days * 86400);
        }

        return q(THREAD_SELECT . " WHERE {$where}
                  ORDER BY t.temperature DESC, t.published_at DESC LIMIT " . (int) $limit, $params);
    };

    $items    = $fetch($windows[$period]);
    $fallback = false;

    // мало данных за выбранный период — расширяем, но честно помечаем это
    if (count($items) < 3 && $period !== 'all') {
        $wider = $fetch(0);

        if (count($wider) > count($items)) {
            $items    = $wider;
            $fallback = true;
        }
    }

    preload_votes('thread', array_column($items, 'id'));

    return ['items' => $items, 'period' => $period, 'fallback' => $fallback];
}

/**
 * Сколько публикаций появилось после указанного момента при тех же условиях,
 * что и в открытой ленте. Считаем через тот же сборщик условий, иначе
 * счётчик обещал бы новинки, которых человек в своей ленте не увидит.
 */
function feed_count_since(array $options): int
{
    $since = $options['since'] ?? null;

    if (! $since) {
        return 0;
    }

    unset($options['since'], $options['page'], $options['sort']);

    $options['count_since'] = $since;

    return feed_threads($options)['pager']['total'];
}

/**
 * Сколько предложений сгорает в ближайшие двое суток.
 *
 * По этому числу вкладка «Сгорает» показывается или прячется: пустая
 * вкладка выглядит как сломанный сайт, а срок у публикации — поле
 * необязательное, и в тихий день там может не быть ничего.
 */
function expiring_count(): int
{
    return (int) qv("SELECT COUNT(*) FROM threads
                     WHERE status = 'published' AND type IN ('deal', 'voucher')
                       AND expires_at IS NOT NULL AND expires_at > ? AND expires_at <= ?",
        [now(), date('Y-m-d H:i:s', time() + EXPIRING_WINDOW)]);
}

/**
 * Предложения для дайджеста «сегодня сгорает».
 *
 * $notBefore отсекает то, что кончится через час-другой: дайджест человек
 * прочитает не в момент отправки, и присылать ему заведомо протухшее —
 * верный способ получить отписку.
 */
function expiring_for_digest(int $limit = 7, int $notBefore = 10800): array
{
    return q(THREAD_SELECT . " WHERE t.status = 'published' AND t.type IN ('deal', 'voucher')
             AND t.expires_at IS NOT NULL
             AND t.expires_at > :soon AND t.expires_at <= :until
             ORDER BY t.expires_at ASC, t.temperature DESC LIMIT " . (int) $limit,
        [
            'soon'  => date('Y-m-d H:i:s', time() + $notBefore),
            'until' => date('Y-m-d H:i:s', time() + EXPIRING_WINDOW),
        ]);
}

/**
 * Время самой свежей публикации в этой же выборке.
 *
 * От него отсчитывается плашка «новые публикации». Раньше отсчёт брали от
 * первой карточки на экране — но первая она только во вкладке «Новое».
 * В «Горячем», «Обсуждаемом» и «Сгорает» сверху стоит что угодно, только
 * не самое свежее, поэтому плашка считала новыми давно опубликованные
 * посты, не исчезала после перезагрузки и появлялась снова по кругу.
 */
function feed_newest_at(array $options): ?string
{
    $options['sort']     = 'new';
    $options['page']     = 1;
    $options['per_page'] = 1;

    unset($options['offset'], $options['count_since'], $options['since']);

    return feed_threads($options)['items'][0]['published_at'] ?? null;
}

/**
 * Идентификаторы всей ветки комментария, включая его самого.
 *
 * Удалять ответы обязательно: без родителя они не попадают в дерево и
 * пропадают со страницы, но остаются в базе и продолжают считаться
 * в счётчике комментариев. Внешний ключ с каскадом есть не во всех
 * установках, поэтому не полагаемся на него и собираем ветку сами.
 *
 * @return array<int,int>
 */
function comment_branch_ids(int $id): array
{
    $ids   = [$id];
    $level = [$id];

    // ограничение на глубину — страховка от зацикливания на битых данных
    for ($depth = 0; $depth < 20 && $level; $depth++) {
        $marks    = implode(',', array_fill(0, count($level), '?'));
        $children = q("SELECT id FROM comments WHERE parent_id IN ({$marks})", $level);

        $level = array_map('intval', array_column($children, 'id'));
        $ids   = array_merge($ids, $level);
    }

    return array_values(array_unique($ids));
}

/**
 * Пересчитать счётчик комментариев публикации по факту.
 *
 * Не «минус один», а именно пересчёт: так счётчик чинится сам, даже если
 * раньше он успел разъехаться с реальностью.
 */
function refresh_comments_count(int $threadId): int
{
    $count = (int) qv('SELECT COUNT(*) FROM comments WHERE thread_id = ?', [$threadId]);

    qx('UPDATE threads SET comments_count = ? WHERE id = ?', [$count, $threadId]);

    return $count;
}
