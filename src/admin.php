<?php
/**
 * Панель управления. Отдельный вход, свой макет, свои маршруты.
 * Все изменяющие действия пишутся в журнал: без него нельзя
 * разобрать, кто и почему снёс публикацию.
 */

function admin_render(string $view, array $data = [], ?string $title = null, ?array $headAction = null): void
{
    $GLOBALS['open_reports'] = (int) qv("SELECT COUNT(*) FROM reports WHERE status = 'open'");

    extract($data, EXTR_SKIP);

    ob_start();
    require __DIR__ . '/../views/admin/' . $view . '.php';
    $content = ob_get_clean();

    $heading = $title;
    require __DIR__ . '/../views/admin/layout.php';
    exit;
}

/** Не пускаем в панель, а не редиректим на пользовательский вход. */
function admin_guard(): array
{
    $user = auth();

    if (! $user || ! can_open_admin()) {
        admin_login_page();
    }

    return $user;
}

/**
 * Доступ к конкретному разделу. Редактору открыты только магазины —
 * всё остальное отдаёт 403, даже если он наберёт адрес руками.
 */
function admin_guard_section(string $section): array
{
    $user = admin_guard();

    if (is_moderator()) {
        return $user;
    }

    if ($section !== 'magaziny') {
        http_response_code(403);
        exit('Этот раздел доступен только модераторам.');
    }

    return $user;
}

function admin_login_page(array $errors = []): void
{
    require __DIR__ . '/../views/admin/login.php';
    exit;
}

function admin_login(): void
{
    csrf_check();

    if (login_blocked()) {
        admin_login_page(['Слишком много неудачных попыток. Повторите через 15 минут.']);
    }

    $user = q1('SELECT * FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL', [input('login'), input('login')]);

    if (! $user || ! password_verify((string) input('password'), $user['password'])) {
        login_failed((string) input('login'));
        admin_login_page(['Неверный логин или пароль.']);
    }

    if ((int) $user['is_moderator'] !== 1 && (int) ($user['is_editor'] ?? 0) !== 1) {
        admin_login_page(['У этого аккаунта нет доступа к панели управления.']);
    }

    if (is_banned($user)) {
        admin_login_page(['Аккаунт заблокирован.']);
    }

    login_succeeded();
    login_user((int) $user['id']);

    redirect('/admin');
}

/* ------------------------------------------------------------------ сводка */

function admin_dashboard(): void
{
    admin_guard();

    if (! is_moderator()) {
        redirect('/admin/magaziny');
    }

    $stats = [
        'open'          => (int) qv("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
        'pending'       => (int) qv("SELECT COUNT(*) FROM threads WHERE status = 'pending'"),
        'threads'       => (int) qv('SELECT COUNT(*) FROM threads'),
        'users'         => (int) qv('SELECT COUNT(*) FROM users'),
        'rejected'      => (int) qv("SELECT COUNT(*) FROM threads WHERE status = 'rejected'"),
        'banned'        => (int) qv('SELECT COUNT(*) FROM users WHERE is_banned = 1'),
        'threads_today' => (int) qv('SELECT COUNT(*) FROM threads WHERE DATE(created_at) = DATE(?)', [now()]),
    ];

    $latest = q('SELECT * FROM threads ORDER BY created_at DESC LIMIT 8');

    $log = q('SELECT l.*, u.username AS moderator_name FROM moderation_logs l
              LEFT JOIN users u ON u.id = l.moderator_id
              ORDER BY l.created_at DESC LIMIT 8');

    admin_render('dashboard', compact('stats', 'latest', 'log'), 'Сводка');
}

/* ------------------------------------------------------------- публикации */

function admin_threads(): void
{
    admin_guard_section('publikacii');

    $term   = (string) input('q', '');
    $type   = (string) input('type', '');
    $status = (string) input('status', '');
    $author = (string) input('author', '');

    $where  = [];
    $params = [];

    if ($term !== '') {
        // отдельный плейсхолдер на каждое вхождение — иначе MySQL
        // с нативными запросами отвергает повтор имени
        $where[] = '(t.title LIKE :q1 OR t.code LIKE :q2)';
        $params['q1'] = '%' . $term . '%';
        $params['q2'] = '%' . $term . '%';
    }

    if (array_key_exists($type, cfg('thread_types'))) {
        $where[] = 't.type = :type';
        $params['type'] = $type;
    }

    if (in_array($status, ['published', 'rejected', 'expired', 'draft', 'pending'], true)) {
        $where[] = 't.status = :status';
        $params['status'] = $status;
    }

    if ($author !== '') {
        $where[] = 'u.username LIKE :author';
        $params['author'] = '%' . $author . '%';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total = (int) qv("SELECT COUNT(*) FROM threads t JOIN users u ON u.id = t.user_id {$whereSql}", $params);
    $pager = paginate($total, 25, (int) input('page', 1));

    $items = q(THREAD_SELECT . " {$whereSql} ORDER BY t.created_at DESC LIMIT 25 OFFSET {$pager['offset']}", $params);

    /*
     * Число жалоб добираем одним запросом по уже выбранным публикациям.
     * Подзапрос внутрь THREAD_SELECT не вставить — он заканчивается
     * джойнами, и приписанный следом подзапрос база читает как ещё одну
     * таблицу, где столбец t.id уже недоступен.
     */
    $ids = array_map('intval', array_column($items, 'id'));

    $reports = [];

    if ($ids) {
        $rows = q("SELECT reportable_id, COUNT(*) AS n FROM reports
                   WHERE reportable_type = 'thread' AND reportable_id IN (" . implode(',', $ids) . ")
                   GROUP BY reportable_id");

        foreach ($rows as $row) {
            $reports[(int) $row['reportable_id']] = (int) $row['n'];
        }
    }

    foreach ($items as $i => $row) {
        $items[$i]['reports_count'] = $reports[(int) $row['id']] ?? 0;
    }

    $result = ['items' => $items, 'pager' => $pager];

    admin_render('threads', compact('result', 'term', 'type', 'status', 'author'), 'Публикации');
}

function admin_thread_edit(int $id): void
{
    admin_guard();

    $t = q1(THREAD_SELECT . ' WHERE t.id = ?', [$id]);

    if (! $t) {
        flash('Публикация не найдена.', 'error');
        redirect('/admin/publikacii');
    }

    $selected = array_map('intval', array_column(
        q('SELECT category_id FROM category_thread WHERE thread_id = ?', [$id]), 'category_id'));

    admin_render('thread_edit', [
        't'          => $t,
        'categories' => category_tree(),
        'groups'     => q('SELECT * FROM groups_list ORDER BY position'),
        'merchants'  => q('SELECT id, name FROM merchants ORDER BY is_top DESC, name'),
        'selected'   => $selected,
    ], 'Публикация: ' . excerpt($t['title'], 50));
}

function admin_thread_save(int $id): void
{
    csrf_check();
    admin_guard();

    $t = q1('SELECT * FROM threads WHERE id = ?', [$id]);

    if (! $t) {
        redirect('/admin/publikacii');
    }

    $type = (string) input('type');

    if (! array_key_exists($type, cfg('thread_types'))) {
        $type = $t['type'];
    }

    $status = (string) input('status');

    if (! in_array($status, ['published', 'rejected', 'expired', 'draft', 'pending'], true)) {
        $status = $t['status'];
    }

    $url    = (string) input('url');
    $chosen = (int) input('merchant_id', 0);

    $merchant = $chosen > 0
        ? q1('SELECT id FROM merchants WHERE id = ?', [$chosen])
        : (merchant_by_name(input('merchant_name'))
            ?: ($url !== '' ? merchant_by_domain($url) : null));

    // текст — блоками из редактора, HTML собирает сервер (blocks.php)
    $bodyInput = blocks_input();

    if ($bodyInput['error']) {
        flash($bodyInput['error'], 'error');
        redirect('/admin/publikacii/' . $id);
    }

    $fields = [
        // slug намеренно не трогаем: уже опубликованные ссылки должны работать
        'type'             => $type,
        'status'           => $status,
        'title'            => mb_substr((string) input('title'), 0, 255),
        'body'             => clean_html($bodyInput['html']) ?: null,
        'body_json'        => $bodyInput['json'],
        'url'              => $url ?: null,
        'code'             => (string) input('code') ?: null,
        'image_url'        => (string) input('image_url') ?: null,
        'price'            => input('price') !== '' ? (float) input('price') : null,
        'old_price'        => input('old_price') !== '' ? (float) input('old_price') : null,
        'discount_percent' => input('discount_percent') !== '' ? (int) input('discount_percent') : null,
        'group_id'         => input('group_id') !== '' ? (int) input('group_id') : null,
        'merchant_id'      => $merchant['id'] ?? ($chosen > 0 ? null : $t['merchant_id']),
        'expires_at'       => input('expires_at') ? input('expires_at') . ' 23:59:59' : null,
        'ad_marking'       => mb_substr((string) input('ad_marking'), 0, 500) ?: null,
    ];

    if (! empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $path = store_image($_FILES['image'], 'threads');

        if ($path) {
            $fields['image_path'] = $path;
        }
    }

    update('threads', $id, $fields);

    qx('DELETE FROM category_thread WHERE thread_id = ?', [$id]);

    foreach ((array) ($_POST['categories'] ?? []) as $categoryId) {
        if (ctype_digit((string) $categoryId)) {
            insert('category_thread', ['thread_id' => $id, 'category_id' => (int) $categoryId]);
        }
    }

    mod_log('thread_edited', ['заголовок' => $fields['title']]);

    if ((int) $t['user_id'] !== auth_id()) {
        notify((int) $t['user_id'], 'thread_edited', ['thread_id' => $id]);
    }

    flash('Публикация сохранена.');
    redirect('/admin/publikacii/' . $id);
}

/**
 * Одобрить, отклонить или вернуть публикацию.
 *
 * При отказе сохраняем причину и ссылку прямо в публикации: автор увидит
 * их на своей странице, а не только в уведомлении, которое легко потерять.
 * Ссылка нужна, чтобы отправить человека к правилам оформления, а не
 * пересказывать их в каждом отказе.
 */
function admin_thread_status(int $id, string $status): void
{
    csrf_check();
    $me = admin_guard();

    $t = q1('SELECT * FROM threads WHERE id = ?', [$id]);

    if (! $t) {
        back();
    }

    $wasPending = $t['status'] === 'pending';
    $reason     = mb_substr(trim((string) input('reason')), 0, 1000) ?: null;
    $link       = trim((string) input('reject_link'));
    $link       = $link !== '' ? mb_substr((string) normalize_url($link), 0, 255) ?: null : null;

    update('threads', $id, [
        'status'        => $status,
        'reject_reason' => $status === 'rejected' ? $reason : null,
        'reject_link'   => $status === 'rejected' ? $link : null,
        'moderated_at'  => now(),
        'moderated_by'  => $me['id'],
    ]);

    mod_log($status === 'rejected' ? 'thread_rejected' : 'thread_restored', [
        'заголовок' => $t['title'],
        'причина'   => $reason,
    ]);

    notify((int) $t['user_id'], $status === 'rejected' ? 'thread_rejected' : 'thread_approved', [
        'thread_id' => $id,
        'payload'   => ['reason' => $reason, 'link' => $link],
    ]);

    // публикация дошла до ленты только сейчас — начисляем и оповещаем подписчиков
    if ($status === 'published' && $t['status'] !== 'published') {
        award_publication($id);
        notify_followers($id);
    }

    if ($status === 'rejected') {
        flash($wasPending ? 'Публикация отклонена, автору отправлено уведомление.' : 'Публикация снята с ленты.');
    } else {
        flash($wasPending ? 'Публикация одобрена и появилась в ленте.' : 'Публикация возвращена в ленту.');
    }

    back();
}

function admin_thread_delete(int $id): void
{
    csrf_check();
    admin_guard();

    $t = q1('SELECT * FROM threads WHERE id = ?', [$id]);

    if ($t) {
        mod_log('thread_deleted', [
            'заголовок' => $t['title'],
            'автор'     => qv('SELECT username FROM users WHERE id = ?', [$t['user_id']]),
        ]);

        // Огоньки за публикацию и за голоса к ней снимаем ДО удаления:
        // после каскадного удаления связей журнал уже не найдёт, за что списывать
        revoke_thread_flames($id);

        delete_row('threads', $id);
        flash('Публикация удалена безвозвратно.');
    }

    redirect('/admin/publikacii');
}

/* ----------------------------------------------------------- пользователи */

function admin_users(): void
{
    admin_guard_section('polzovateli');

    $term   = (string) input('q', '');
    $filter = (string) input('filter', '');
    $sort   = (string) input('sort', 'new');

    $where  = [];
    $params = [];

    if ($term !== '') {
        $where[] = '(username LIKE :q1 OR email LIKE :q2)';
        $params['q1'] = '%' . $term . '%';
        $params['q2'] = '%' . $term . '%';
    }

    if ($filter === 'banned')     { $where[] = 'is_banned = 1'; }
    if ($filter === 'moderators') { $where[] = 'is_moderator = 1'; }
    if ($filter === 'active')     { $where[] = 'id IN (SELECT user_id FROM threads)'; }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $order = match ($sort) {
        'reputation' => 'reputation DESC',
        'threads'    => '(SELECT COUNT(*) FROM threads t WHERE t.user_id = users.id) DESC',
        default      => 'created_at DESC',
    };

    $total = (int) qv("SELECT COUNT(*) FROM users {$whereSql}", $params);
    $pager = paginate($total, 25, (int) input('page', 1));

    // Три отдельных запроса на каждую строку превращались в 75 запросов
    // на страницу. Подзапросы считают то же самое за один проход.
    $users = q("SELECT users.*,
                       (SELECT COUNT(*) FROM threads t WHERE t.user_id = users.id) AS threads_count,
                       (SELECT COUNT(*) FROM comments c WHERE c.user_id = users.id) AS comments_count,
                       (SELECT b.reason FROM bans b
                        WHERE b.user_id = users.id AND b.lifted_at IS NULL
                        ORDER BY b.created_at DESC LIMIT 1) AS ban_reason
                FROM users {$whereSql} ORDER BY {$order} LIMIT 25 OFFSET {$pager['offset']}", $params);

    $result = ['items' => $users, 'pager' => $pager];

    admin_render('users', compact('users', 'result', 'term', 'filter', 'sort'), 'Пользователи');
}

function admin_user_ban(int $id): void
{
    csrf_check();
    $me = admin_guard();

    $user = q1('SELECT * FROM users WHERE id = ?', [$id]);

    if (! $user || (int) $user['id'] === (int) $me['id'] || (int) $user['is_moderator'] === 1) {
        flash('Этого пользователя заблокировать нельзя.', 'error');
        back();
    }

    $reason = (string) input('reason');

    if (mb_strlen($reason) < 3) {
        flash('Укажите причину блокировки.', 'error');
        back();
    }

    $days    = (int) input('days', 0);
    $expires = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;

    insert('bans', [
        'user_id'      => $id,
        'moderator_id' => $me['id'],
        'reason'       => mb_substr($reason, 0, 300),
        'expires_at'   => $expires,
        'created_at'   => now(),
    ]);

    update('users', $id, ['is_banned' => 1, 'banned_until' => $expires]);
    mod_log('user_banned', ['пользователь' => $user['username'], 'причина' => $reason, 'срок' => $days ?: 'бессрочно']);

    flash('Пользователь ' . $user['username'] . ' заблокирован.');
    back();
}

function admin_user_unban(int $id): void
{
    csrf_check();
    admin_guard();

    $user = q1('SELECT * FROM users WHERE id = ?', [$id]);

    if ($user) {
        qx('UPDATE bans SET lifted_at = ? WHERE user_id = ? AND lifted_at IS NULL', [now(), $id]);
        update('users', $id, ['is_banned' => 0, 'banned_until' => null]);
        mod_log('user_unbanned', ['пользователь' => $user['username']]);
        flash('Блокировка снята.');
    }

    back();
}

function admin_user_role(int $id, string $role = 'moderator'): void
{
    csrf_check();
    $me = admin_guard_section('polzovateli');

    $user = q1('SELECT * FROM users WHERE id = ?', [$id]);

    if (! $user || (int) $user['id'] === (int) $me['id']) {
        flash('Нельзя менять собственные права.', 'error');
        back();
    }

    if ($role === 'editor') {
        $new = (int) ($user['is_editor'] ?? 0) === 1 ? 0 : 1;

        // модератору роль редактора не нужна: у него и так всё открыто
        update('users', $id, ['is_editor' => $new]);

        mod_log($new ? 'editor_granted' : 'editor_revoked', ['пользователь' => $user['username']]);
        flash($new ? 'Права редактора выданы: доступен только раздел «Магазины».' : 'Права редактора сняты.');

        back();
    }

    $new = (int) $user['is_moderator'] === 1 ? 0 : 1;
    update('users', $id, ['is_moderator' => $new]);

    mod_log($new ? 'role_granted' : 'role_revoked', ['пользователь' => $user['username']]);
    flash($new ? 'Права модератора выданы.' : 'Права модератора сняты.');

    back();
}

/* ----------------------------------------- разделы, категории, магазины */

/** @return array{table:string,kind:string,label:string,usage:string} */
function taxonomy_meta(string $routeSlug): array
{
    return match ($routeSlug) {
        'razdely'   => ['table' => 'groups_list', 'kind' => 'groups',     'label' => 'Разделы обсуждений', 'usage' => 'SELECT COUNT(*) FROM threads WHERE group_id = ?'],
        'kategorii' => ['table' => 'categories',  'kind' => 'categories', 'label' => 'Категории',          'usage' => 'SELECT COUNT(*) FROM category_thread WHERE category_id = ?'],
        'magaziny'  => ['table' => 'merchants',   'kind' => 'merchants',  'label' => 'Магазины',           'usage' => 'SELECT COUNT(*) FROM threads WHERE merchant_id = ?'],
        default     => abort_404(),
    };
}

function abort_404()
{
    http_response_code(404);
    exit('Не найдено');
}

function admin_taxonomy(string $routeSlug): void
{
    admin_guard_section($routeSlug);

    $meta  = taxonomy_meta($routeSlug);
    $table = $meta['table'];
    $term  = trim((string) input('q', ''));

    $order = $table === 'categories' ? 'position, name' : 'position, name';

    if ($term !== '') {
        // ищем и по названию, и по домену — для магазинов проверка на дубль
        // упирается именно в то, что человек ищет по одному, а совпадает по другому
        $like = '%' . $term . '%';

        $items = $table === 'merchants'
            ? q("SELECT * FROM {$table} WHERE name LIKE :q1 OR domain LIKE :q2 ORDER BY {$order}",
                ['q1' => $like, 'q2' => $like])
            : q("SELECT * FROM {$table} WHERE name LIKE :q1 ORDER BY {$order}", ['q1' => $like]);
    } else {
        $items = q("SELECT * FROM {$table} ORDER BY {$order}");
    }

    foreach ($items as $i => $item) {
        $items[$i]['usage_count'] = (int) qv($meta['usage'], [$item['id']]);

        if ($table === 'categories' && $item['parent_id']) {
            $items[$i]['parent_name'] = qv('SELECT name FROM categories WHERE id = ?', [$item['parent_id']]);
        }
    }

    $editing = null;

    if (input('edit')) {
        $editing = q1("SELECT * FROM {$table} WHERE id = ?", [(int) input('edit')]);
    }

    $parents = $table === 'categories'
        ? q('SELECT * FROM categories WHERE parent_id IS NULL ORDER BY position')
        : [];

    admin_render('taxonomy', [
        'items'     => $items,
        'editing'   => $editing,
        'parents'   => $parents,
        'kind'      => $meta['kind'],
        'routeSlug' => $routeSlug,
        'term'      => $term,
    ], $meta['label']);
}

function admin_taxonomy_save(string $routeSlug, ?int $id = null): void
{
    csrf_check();
    admin_guard_section($routeSlug);

    $meta  = taxonomy_meta($routeSlug);
    $table = $meta['table'];

    $name = trim((string) input('name'));

    if ($name === '') {
        flash('Название не может быть пустым.', 'error');
        back();
    }

    $fields = [
        'name'     => mb_substr($name, 0, 160),
        'position' => (int) input('position', 0),
    ];

    if ($table !== 'categories') {
        $fields['description'] = (string) input('description') ?: null;
    }

    if ($table === 'categories') {
        $parent = (int) input('parent_id', 0);
        $fields['parent_id'] = ($parent > 0 && $parent !== $id) ? $parent : null;
    }

    if ($table === 'merchants') {
        $domain = strtolower(trim((string) input('domain')));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', (string) $domain);
        $domain = explode('/', (string) $domain)[0];

        $fields['domain']     = $domain ?: null;
        $fields['logo_color'] = (string) input('logo_color') ?: '#c9ced6';
        $fields['is_top']     = input('is_top') ? 1 : 0;

        // партнёрская обёртка: либо шаблон сети, либо имя параметра метки
        $fields['affiliate_url']   = mb_substr(trim((string) input('affiliate_url')), 0, 255) ?: null;
        $fields['affiliate_param'] = preg_replace('/[^A-Za-z0-9_-]/', '',
            (string) input('affiliate_param')) ?: null;


        /*
         * Проверка на дубль. Ищем и по названию, и по домену отдельно:
         * магазин мог быть уже добавлен под слегка другим названием
         * («Озон» вместо «OZON»), но с тем же доменом — совпадение
         * по одному признаку уже повод остановиться.
         */
        $nameMatch = q1('SELECT id, name FROM merchants
                          WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))' . ($id ? ' AND id != :id' : ''),
            array_filter(['name' => $name, 'id' => $id], fn ($v) => $v !== null));

        $domainMatch = ($fields['domain'] && ! $nameMatch)
            ? q1('SELECT id, name FROM merchants WHERE domain = :domain' . ($id ? ' AND id != :id' : ''),
                array_filter(['domain' => $fields['domain'], 'id' => $id], fn ($v) => $v !== null))
            : null;

        $dup = $nameMatch ?: $domainMatch;

        if ($dup) {
            $reason = $nameMatch ? 'с таким названием' : 'с этим доменом';

            flash('Магазин ' . $reason . ' уже есть: «' . $dup['name'] . '». '
                . 'Найдите его через поиск выше и отредактируйте, если нужно.', 'error');
            redirect('/admin/' . $routeSlug . '?q=' . rawurlencode($dup['name']));
        }
    }

    $logoField = $table === 'categories' ? 'icon' : 'logo';

    if (! empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $path = store_image($_FILES['logo'], 'logos', 1024 * 1024);

        if ($path) {
            $fields[$logoField] = $path;
        } else {
            flash('Логотип не сохранён: нужен PNG, JPG, GIF или WEBP до 1 МБ.', 'error');
        }
    } elseif (input('remove_logo')) {
        $fields[$logoField] = null;
    }

    if ($id) {
        update($table, $id, $fields);
        flash('Сохранено.');
    } else {
        $fields['slug'] = slugify($name, $table);
        $id = insert($table, $fields);
        flash('Добавлено.');
    }

    mod_log('taxonomy_saved', ['раздел' => $meta['label'], 'название' => $fields['name']]);

    redirect('/admin/' . $routeSlug);
}

function admin_taxonomy_delete(string $routeSlug, int $id): void
{
    csrf_check();
    admin_guard_section($routeSlug);

    $meta  = taxonomy_meta($routeSlug);
    $name  = qv("SELECT name FROM {$meta['table']} WHERE id = ?", [$id]);

    delete_row($meta['table'], $id);
    mod_log('taxonomy_deleted', ['раздел' => $meta['label'], 'название' => $name]);

    flash('Удалено.');
    redirect('/admin/' . $routeSlug);
}

/* ------------------------------------------------------------------ жалобы */

function admin_reports(): void
{
    admin_guard_section('zhaloby');

    $status = (string) input('status', 'open');

    if (! in_array($status, ['open', 'accepted', 'dismissed'], true)) {
        $status = 'open';
    }

    $rows = q('SELECT r.*, u.username AS reporter_name, m.username AS resolver_name
               FROM reports r
               JOIN users u ON u.id = r.user_id
               LEFT JOIN users m ON m.id = r.resolved_by
               WHERE r.status = ? ORDER BY r.created_at DESC LIMIT 50', [$status]);

    // Объект жалобы мог быть уже удалён — подставляем заглушку
    $reports = array_map(static function (array $r): array {
        $r['subject_title'] = 'Объект удалён';
        $r['subject_url']   = null;
        $r['subject_id']    = null;

        if ($r['reportable_type'] === 'thread') {
            $t = q1('SELECT id, title, slug FROM threads WHERE id = ?', [$r['reportable_id']]);

            if ($t) {
                $r['subject_title'] = $t['title'];
                $r['subject_id']    = (int) $t['id'];
                $r['subject_url']   = url('/post/' . rawurlencode($t['slug']));
            }
        } else {
            $c = q1('SELECT c.body, t.slug FROM comments c JOIN threads t ON t.id = c.thread_id WHERE c.id = ?',
                [$r['reportable_id']]);

            if ($c) {
                $r['subject_title'] = excerpt($c['body'], 90);
                $r['subject_url']   = url('/post/' . rawurlencode($c['slug'])) . '#comment-' . $r['reportable_id'];
            }
        }

        return $r;
    }, $rows);

    $counts = [
        'open'      => (int) qv("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
        'accepted'  => (int) qv("SELECT COUNT(*) FROM reports WHERE status = 'accepted'"),
        'dismissed' => (int) qv("SELECT COUNT(*) FROM reports WHERE status = 'dismissed'"),
    ];

    admin_render('reports', compact('reports', 'status', 'counts'), 'Жалобы');
}

function admin_report_resolve(int $id, bool $accepted): void
{
    csrf_check();
    $me = admin_guard();

    $report = q1('SELECT * FROM reports WHERE id = ?', [$id]);

    if ($report) {
        update('reports', $id, [
            'status'      => $accepted ? 'accepted' : 'dismissed',
            'resolved_by' => $me['id'],
            'resolved_at' => now(),
        ]);

        mod_log($accepted ? 'report_accepted' : 'report_dismissed', ['причина' => $report['reason']]);
        flash($accepted ? 'Жалоба принята.' : 'Жалоба отклонена.');
    }

    back();
}

/* ------------------------------------------------------------------ журнал */

function admin_log(): void
{
    admin_guard_section('zhurnal');

    $total = (int) qv('SELECT COUNT(*) FROM moderation_logs');
    $pager = paginate($total, 40, (int) input('page', 1));

    $items = q('SELECT l.*, u.username AS moderator_name FROM moderation_logs l
                LEFT JOIN users u ON u.id = l.moderator_id
                ORDER BY l.created_at DESC LIMIT 40 OFFSET ' . $pager['offset']);

    $result = ['items' => $items, 'pager' => $pager];

    admin_render('log', compact('result'), 'Журнал действий');
}

/* --------------------------------------------------------------- настройки */

function admin_settings(): void
{
    admin_guard_section('nastroyki');

    admin_render('settings', [], 'Настройки сайта');
}

function admin_settings_save(): void
{
    csrf_check();
    admin_guard();

    $map = [
        'site_name'                => 'string',
        'site_description'         => 'string',
        'per_page'                 => 'int',
        'hot_threshold'            => 'int',
        'lifetime_days'            => 'int',
        'metrika_id'               => 'int',
        'flames_per_deal'          => 'float',
        'flames_per_blog'          => 'float',
        'flames_per_vote'          => 'float',
        'flames_per_dislike'       => 'float',
        'flames_rate'              => 'float',
        'flames_min_withdraw'      => 'float',
        'reputation_per_post'      => 'int',
        'site_url'                 => 'string',
        'mail_from'                => 'string',
        'yandex_client_id'         => 'string',
        'yandex_client_secret'     => 'string',
    ];

    foreach ($map as $key => $type) {
        $value = (string) input($key, '');

        if ($value === '') {
            continue;
        }

        $value = match ($type) {
            'int'   => (string) max(0, (int) $value),
            'float' => (string) max(0, (float) $value),
            default => mb_substr($value, 0, 300),
        };

        setting_save($key, $value);
    }

    setting_save('registration_open', input('registration_open') ? '1' : '0');
    setting_save('moderate_all', input('moderate_all') ? '1' : '0');
    setting_save('verify_email', input('verify_email') ? '1' : '0');
    setting_save('admin_email', mb_substr(trim((string) input('admin_email')), 0, 191));
    setting_save('captcha_enabled', input('captcha_enabled') ? '1' : '0');
    setting_save('smartcaptcha_key', mb_substr(trim((string) input('smartcaptcha_key')), 0, 191));

    // пустое поле секретного ключа означает «оставить прежний»
    if ((string) input('smartcaptcha_secret') !== '') {
        setting_save('smartcaptcha_secret', trim((string) input('smartcaptcha_secret')));
    }

    flash('Настройки сохранены.');
    redirect('/admin/nastroyki');
}

function admin_maintenance(): void
{
    csrf_check();
    admin_guard();

    run_maintenance(true);

    flash('Градусы пересчитаны.');
    redirect('/admin/nastroyki');
}

/* ------------------------------------------------ правовые страницы */

/**
 * Список страниц.
 *
 * Раньше обе страницы висели на одном экране двумя простынями текста:
 * чтобы добраться до второй, приходилось прокручивать всю первую.
 * Теперь здесь только перечень, а правка — на отдельном экране.
 */
function admin_pages(): void
{
    admin_guard_section('stranicy');

    $pages = [];

    foreach (legal_pages() as $key => $info) {
        $page = legal_page($key);

        $pages[$key] = [
            'key'     => $key,
            'label'   => $info['short'],
            'url'     => $info['url'],
            'title'   => $page['title'],
            'updated' => $page['updated'],
            'length'  => mb_strlen(html_to_text($page['body'])),
        ];
    }

    admin_render('pages', ['pages' => $pages], 'Страницы сайта');
}

/** Редактор одной страницы. */
function admin_page_edit(string $key): void
{
    admin_guard_section('stranicy');

    $list = legal_pages();

    if (! isset($list[$key])) {
        redirect('/admin/stranicy');
    }

    admin_render('page_edit', [
        'key'  => $key,
        'info' => $list[$key],
        'page' => legal_page($key),
    ], $list[$key]['short']);
}

function admin_pages_save(): void
{
    csrf_check();
    admin_guard();

    $key = (string) input('key');

    if (! isset(legal_pages()[$key])) {
        back();
    }

    // текст приходит из визуального редактора, поэтому чистим разметку
    // тем же фильтром, что и публикации: голый HTML из формы доверия не заслуживает
    setting_save('page_' . $key, clean_html((string) input('body')));
    setting_save('page_' . $key . '_title', mb_substr((string) input('title'), 0, 160));
    setting_save('page_' . $key . '_updated', mb_substr((string) input('updated'), 0, 40));

    mod_log('page_saved', ['страница' => $key]);

    flash('Текст страницы сохранён.');
    redirect('/admin/stranicy/' . $key);
}

/** Пометить публикацию проверенной (или снять отметку). */
function admin_thread_verify(int $id): void
{
    csrf_check();
    $me = admin_guard();

    $t = q1('SELECT * FROM threads WHERE id = ?', [$id]);

    if (! $t) {
        back();
    }

    $verified = empty($t['verified_at']);

    update('threads', $id, [
        'verified_at' => $verified ? now() : null,
        'verified_by' => $verified ? $me['id'] : null,
    ]);

    mod_log($verified ? 'thread_verified' : 'thread_unverified', ['заголовок' => $t['title']]);

    if ($verified) {
        notify((int) $t['user_id'], 'thread_verified', ['thread_id' => $id]);
    }

    flash($verified ? 'Публикация отмечена как проверенная.' : 'Отметка снята.');
    back();
}

/* ------------------------------------------------------ диагностика почты */

/**
 * Страница проверки. Систему отправки не трогает — только показывает
 * состояние окружения и умеет отправить пробное письмо напрямую.
 */
function admin_mail(): void
{
    $me = admin_guard();

    $from = mail_from();

    $tail = static function (string $file, int $lines = 30): string {
        $path = __DIR__ . '/../data/' . $file;

        if (! is_readable($path)) {
            return '';
        }

        $all = explode("\n", (string) file_get_contents($path));

        return trim(implode("\n", array_slice($all, -$lines)));
    };

    $logo = __DIR__ . '/../public/img/email-logo.png';

    $checks = [
        'Как отправляются письма' => [
            'ok'    => smtp_configured() || function_exists('mail'),
            'value' => smtp_configured()
                ? 'SMTP: ' . setting('smtp_host', '') . ':' . setting('smtp_port', '465')
                : 'функция mail() сервера',
        ],
        'Функция mail() доступна' => [
            'ok'    => function_exists('mail'),
            'value' => function_exists('mail') ? 'да' : 'отключена хостером',
        ],
        'Адрес отправителя' => [
            'ok'    => filter_var($from['address'], FILTER_VALIDATE_EMAIL) !== false,
            'value' => $from['address'],
        ],
        'Адрес сайта в настройках' => [
            'ok'    => setting('site_url', '') !== '',
            'value' => setting('site_url', 'не задан — берётся из запроса'),
        ],
        'Логотип для письма' => [
            'ok'    => is_readable($logo),
            'value' => is_readable($logo) ? 'на месте' : 'нет файла',
        ],
        'Очередь писем' => [
            'ok'    => true,
            'value' => (int) qv('SELECT COUNT(*) FROM mail_queue WHERE sent_at IS NULL AND attempts < 3')
                . ' ждёт отправки',
        ],
        'Папка data доступна для записи' => [
            'ok'    => is_writable(__DIR__ . '/../data'),
            'value' => is_writable(__DIR__ . '/../data') ? 'да' : 'нет',
        ],
    ];

    admin_render('mail', [
        'checks' => $checks,
        'log'    => $tail('mail.log', 40),
        'errors' => $tail('errors.log'),
        'me'     => $me,
    ], 'Почта');
}

function admin_mail_test(): void
{
    csrf_check();
    $me = admin_guard();

    $to = (string) input('to');

    if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
        flash('Укажите корректный адрес.', 'error');
        back();
    }

    $site = setting('site_name', cfg('app_name'));

    if (input('kind') === 'plain') {
        // самая простая отправка: если дойдёт это, значит сервер письма шлёт
        $ok = mail_send_raw(
            $to,
            mail_encode('Пробное письмо — ' . $site),
            "Это пробное письмо простым текстом.\r\nЕсли оно дошло, отправка работает.\r\n",
            [
                'From: ' . mail_encode($site) . ' <' . mail_from()['address'] . '>',
                'Content-Type: text/plain; charset=UTF-8',
                'MIME-Version: 1.0',
            ]
        );
    } else {
        // именно send_mail_now: через очередь тест не показал бы,
        // доходит ли письмо на самом деле
        $ok = send_mail_now($to, 'Пробное письмо — ' . $site, 'Проверка отправки', [
            'Если вы видите это письмо с логотипом и кнопкой — оформленные письма доходят.',
            ['button' => ['text' => 'Открыть сайт', 'url' => site_url()]],
            ['small' => 'Отправлено вручную из панели управления.'],
        ]);
    }

    flash($ok
        ? (smtp_configured()
            ? 'Письмо принято SMTP-сервером. Проверьте ящик и папку «Спам».'
            : 'Письмо передано функции mail(). Она почти всегда отвечает «успешно», '
              . 'даже если письмо потом отбросят: если не дойдёт — настройте SMTP ниже.')
        : 'Письмо отправить не удалось. Подробности — в журнале ниже.',
        $ok ? 'ok' : 'error');

    redirect('/admin/pochta');
}

function admin_mail_smtp(): void
{
    csrf_check();
    admin_guard();

    setting_save('smtp_host',   mb_substr(trim((string) input('smtp_host')), 0, 191));
    setting_save('smtp_port',   (string) max(0, (int) input('smtp_port', 465)));
    setting_save('smtp_user',   mb_substr(trim((string) input('smtp_user')), 0, 191));
    setting_save('smtp_secure', in_array(input('smtp_secure'), ['ssl', 'tls', 'none'], true)
        ? (string) input('smtp_secure') : 'ssl');

    // пустое поле пароля means «оставить прежний»: иначе пароль стирался бы
    // при каждом сохранении формы, где он не показывается
    if ((string) input('smtp_pass') !== '') {
        setting_save('smtp_pass', (string) input('smtp_pass'));
    }

    if (input('smtp_off')) {
        setting_save('smtp_host', '');
    }

    mod_log('smtp_saved', ['хост' => setting('smtp_host', '—')]);

    flash('Настройки SMTP сохранены. Отправьте пробное письмо, чтобы проверить.');
    redirect('/admin/pochta');
}

function admin_mail_clear(): void
{
    csrf_check();
    admin_guard();

    @unlink(__DIR__ . '/../data/errors.log');

    flash('Журнал ошибок очищен.');
    redirect('/admin/pochta');
}

/* ------------------------------------------------------------------ бот */

function admin_bot(): void
{
    admin_guard_section('bot');

    $author = q1('SELECT * FROM users WHERE username = ?', [setting('bot_username', 'HotHuntBot')]);

    $stats = [
        'total'   => (int) qv("SELECT COUNT(*) FROM bot_imports WHERE source = 'steam'"),
        'week'    => (int) qv("SELECT COUNT(*) FROM bot_imports WHERE source = 'steam' AND created_at > ?",
                        [date('Y-m-d H:i:s', time() - 7 * 86400)]),
        'pending' => $author ? (int) qv("SELECT COUNT(*) FROM threads WHERE user_id = ? AND status = 'pending'", [$author['id']]) : 0,
    ];

    $recent = q("SELECT t.title, t.slug, t.status, t.discount_percent, t.price, b.created_at
                 FROM bot_imports b JOIN threads t ON t.id = b.thread_id
                 WHERE b.source = 'steam' ORDER BY b.created_at DESC LIMIT 15");

    admin_render('bot', [
        'author' => $author,
        'stats'  => $stats,
        'recent' => $recent,
        'log'    => bot_log_tail(),
    ], 'Бот Steam');
}

function admin_bot_save(): void
{
    csrf_check();
    admin_guard();

    setting_save('bot_enabled',      input('bot_enabled') ? '1' : '0');
    setting_save('bot_moderate',     input('bot_moderate') ? '1' : '0');
    setting_save('bot_region',       preg_replace('/[^a-z]/', '', (string) input('bot_region')) ?: 'ru');
    setting_save('bot_min_discount', (string) max(0, min(95, (int) input('bot_min_discount', 40))));
    setting_save('bot_limit',        (string) max(1, min(20, (int) input('bot_limit', 5))));

    $name = trim((string) input('bot_username'));

    if (preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $name)) {
        setting_save('bot_username', $name);
    }

    flash('Настройки бота сохранены.');
    redirect('/admin/bot');
}

function admin_bot_run(): void
{
    csrf_check();
    admin_guard();

    $result = bot_import_steam(true);

    mod_log('bot_run', ['добавлено' => $result['added'], 'ошибок' => $result['errors']]);

    flash($result['message'], $result['errors'] > 0 && $result['added'] === 0 ? 'error' : 'ok');
    redirect('/admin/bot');
}

/* ------------------------------------------------------------- выплаты */

function admin_withdrawals(): void
{
    admin_guard_section('vyplaty');

    $status = (string) input('status', 'pending');

    if (! in_array($status, ['pending', 'paid', 'rejected'], true)) {
        $status = 'pending';
    }

    $items = q('SELECT w.*, u.username, u.balance
                FROM withdrawals w JOIN users u ON u.id = w.user_id
                WHERE w.status = ? ORDER BY w.created_at DESC LIMIT 100', [$status]);

    $counts = [
        'pending'  => (int) qv("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'"),
        'paid'     => (int) qv("SELECT COUNT(*) FROM withdrawals WHERE status = 'paid'"),
        'rejected' => (int) qv("SELECT COUNT(*) FROM withdrawals WHERE status = 'rejected'"),
    ];

    admin_render('withdrawals', compact('items', 'status', 'counts'), 'Заявки на вывод');
}

function admin_withdrawal_resolve(int $id, string $status): void
{
    csrf_check();
    $me = admin_guard_section('vyplaty');

    $w = q1('SELECT * FROM withdrawals WHERE id = ?', [$id]);

    if (! $w || $w['status'] !== 'pending') {
        flash('Заявка уже обработана.', 'error');
        back();
    }

    update('withdrawals', $id, [
        'status'      => $status,
        'resolved_by' => $me['id'],
        'resolved_at' => now(),
    ]);

    // при отказе Огоньки возвращаются: списали их в момент подачи заявки
    if ($status === 'rejected') {
        apply_balance((int) $w['user_id'], (float) $w['amount']);
    }

    mod_log($status === 'paid' ? 'withdrawal_paid' : 'withdrawal_rejected', [
        'пользователь' => qv('SELECT username FROM users WHERE id = ?', [$w['user_id']]),
        'сумма'        => $w['amount'],
    ]);

    flash($status === 'paid' ? 'Отмечено как выплаченное.' : 'Заявка отклонена, Огоньки возвращены.');
    back();
}

/* --------------------------------------------------------------- каналы */

/**
 * Настройки каналов и ручная отправка дайджеста.
 *
 * Токены хранятся в настройках сайта, поэтому в форме они не показываются:
 * пустое поле означает «оставить прежний». Иначе токен утекал бы в любой
 * скриншот панели.
 */
function admin_channels(): void
{
    $me = admin_guard_section('kanaly');

    /*
     * Черновик собираем отдельно от каналов: он должен быть виден и до
     * того, как подключён первый канал, — иначе непонятно, ради чего
     * вообще возиться с токенами.
     */
    $draft = expiring_for_digest();

    admin_render('channels', [
        'mail'     => digest_mail_send(true),
        'channels' => digest_channels(),
        'draft'    => $draft,
        'draftText'=> $draft ? digest_text($draft) : '',
        'preview'  => digest_send(null, true),
        'expiring' => expiring_count(),
        'log'      => digest_log_tail(),
        'me'       => $me,
    ], 'Каналы');
}

function admin_channels_save(): void
{
    csrf_check();
    admin_guard();

    setting_save('max_chat_id', mb_substr(trim((string) input('max_chat_id')), 0, 100));
    setting_save('tg_chat_id',  mb_substr(trim((string) input('tg_chat_id')), 0, 100));

    foreach (['max_bot_token', 'tg_bot_token'] as $key) {
        if ((string) input($key) !== '') {
            setting_save($key, trim((string) input($key)));
        }
    }

    if (input('max_off')) {
        setting_save('max_bot_token', '');
    }

    if (input('tg_off')) {
        setting_save('tg_bot_token', '');
    }

    mod_log('channels_saved', ['MAX' => setting('max_chat_id', '—'), 'Telegram' => setting('tg_chat_id', '—')]);

    flash('Настройки каналов сохранены.');
    redirect('/admin/kanaly');
}

/** Отправить дайджест прямо сейчас — кнопкой, не дожидаясь планировщика. */
function admin_channels_run(): void
{
    csrf_check();
    admin_guard();

    // почта — отдельный канал: у неё свой список получателей и своё согласие
    if (input('channel') === 'email') {
        $mail = digest_mail_send();

        flash('Рассылка: ' . $mail['message'] . ' (писем — ' . $mail['users'] . ', находок — ' . $mail['count'] . ')');
        redirect('/admin/kanaly');
    }

    $report = digest_send((string) input('channel') ?: null);
    $parts  = [];

    foreach ($report as $channel => $result) {
        $parts[] = $channel . ': ' . $result['message'] . ' (' . $result['count'] . ')';
    }

    flash('Дайджест — ' . implode('; ', $parts), array_filter($report, fn ($r) => ! $r['ok']) ? 'error' : 'ok');
    redirect('/admin/kanaly');
}

/* ------------------------------------------------ технические аккаунты */

/**
 * Правка бот-аккаунта.
 *
 * Только для технических аккаунтов: под ними не входят (пароль случайный
 * и нигде не сохранён), поэтому иначе их профиль не изменить вообще.
 * Чужие человеческие профили из панели не редактируются намеренно —
 * это чужие данные, а не настройка сайта.
 */
function admin_bot_account(int $id): void
{
    admin_guard_section('polzovateli');

    $user = q1('SELECT * FROM users WHERE id = ?', [$id]);

    if (! $user || ! is_bot_account($user)) {
        flash('Редактировать из панели можно только технические аккаунты.', 'error');
        redirect('/admin/polzovateli');
    }

    admin_render('bot_edit', ['user' => $user, 'errors' => errors_take()], 'Аккаунт бота');
}

function admin_bot_account_save(int $id): void
{
    csrf_check();
    admin_guard();

    $user = q1('SELECT * FROM users WHERE id = ?', [$id]);

    if (! $user || ! is_bot_account($user)) {
        flash('Редактировать из панели можно только технические аккаунты.', 'error');
        redirect('/admin/polzovateli');
    }

    $username = trim((string) input('username'));
    $errors   = [];

    if (mb_strlen($username) < 2 || mb_strlen($username) > 32) {
        $errors[] = 'Имя должно быть от 2 до 32 символов.';
    }

    $taken = q1('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id]);

    if ($taken) {
        $errors[] = 'Такое имя уже занято.';
    }

    if ($errors) {
        errors_set($errors);
        redirect('/admin/polzovateli/' . $id . '/bot');
    }

    $fields = [
        'username'   => $username,
        'bio'        => mb_substr(trim((string) input('bio')), 0, 500) ?: null,
        'reputation' => max(0, (int) input('reputation')),
    ];

    if (! empty($_FILES['avatar']['tmp_name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
        $path = store_image($_FILES['avatar'], 'avatars', 2 * 1024 * 1024);

        if ($path) {
            $fields['avatar'] = $path;
        } else {
            flash('Аватар не сохранён: нужен PNG, JPG, GIF или WEBP до 2 МБ.', 'error');
        }
    } elseif (input('remove_avatar')) {
        $fields['avatar'] = null;
    }

    /*
     * Имя бота хранится ещё и в настройках: по нему бот находит свой аккаунт
     * при следующем запуске. Без этой строки переименование создало бы
     * второго бота вместо переименования первого.
     */
    if ($user['username'] !== $username && setting('bot_username', 'HotHuntBot') === $user['username']) {
        setting_save('bot_username', $username);
    }

    update('users', $id, $fields);
    mod_log('bot_updated', ['аккаунт' => $username]);

    flash('Аккаунт бота обновлён.');
    redirect('/admin/polzovateli/' . $id . '/bot');
}

/** Пометить аккаунт техническим или снять пометку. */
function admin_user_bot_toggle(int $id): void
{
    csrf_check();
    admin_guard();

    $user = q1('SELECT * FROM users WHERE id = ?', [$id]);

    if (! $user) {
        back();
    }

    $now = is_bot_account($user) ? 0 : 1;

    update('users', $id, ['is_bot' => $now]);
    mod_log($now ? 'bot_marked' : 'bot_unmarked', ['аккаунт' => $user['username']]);

    flash($now
        ? 'Аккаунт помечен как технический: его профиль теперь правится из панели.'
        : 'Пометка снята.');

    back();
}
