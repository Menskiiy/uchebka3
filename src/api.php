<?php
/**
 * Точки для обновления страницы без перезагрузки.
 *
 * Разметку карточек и комментариев собирает сервер теми же функциями,
 * что и обычные страницы. Это сознательно: иначе пришлось бы держать
 * вторую копию вёрстки на JavaScript и следить, чтобы они не разъехались.
 */

/** Параметры ленты из запроса — те же, что использует обычная страница. */
function api_feed_options(): array
{
    $filters = feed_filters();
    $options = $filters['options'];

    $type = (string) input('type', '');

    if ($type !== '' && array_key_exists($type, cfg('thread_types'))) {
        $options['type'] = $type;
    } elseif (input('scope') === 'blog') {
        $options['type'] = 'blog';
    } else {
        $options['commercial_only'] = true;
    }

    if ($slug = (string) input('merchant', '')) {
        $merchant = q1('SELECT id FROM merchants WHERE slug = ?', [$slug]);

        if ($merchant) {
            $options['merchant_id'] = (int) $merchant['id'];
            unset($options['commercial_only']);
        }
    }

    if ($slug = (string) input('category', '')) {
        $category = q1('SELECT id FROM categories WHERE slug = ?', [$slug]);

        if ($category) {
            $options['category_id'] = (int) $category['id'];
        }
    }

    if ($term = (string) input('q', '')) {
        $options['search'] = $term;
    }

    $options['sort'] = sort_param((string) input('default_tab', 'hot'));

    // вкладка «Сгорает» меняет не порядок, а сам набор: без этого догрузка
    // подсовывала бы в неё обычные предложения
    if ($options['sort'] === 'expiring') {
        $options['expiring_soon'] = true;
    }

    return $options;
}

/**
 * Следующая страница ленты: готовые карточки и признак, есть ли ещё.
 *
 * Параметр span — сколько страниц отдать за раз. Обычная догрузка просит
 * одну, а возврат по «Назад» — сразу все, что человек успел пролистать:
 * иначе восстановление ленты на десятой странице стоило бы девяти
 * запросов подряд и пары секунд ожидания.
 */
function api_feed(): void
{
    $options = api_feed_options();

    $page = max(1, (int) input('page', 1));
    $span = min(10, max(1, (int) input('span', 1)));
    $per  = (int) cfg('per_page');

    // страницу считаем сами через offset, поэтому пагинатору отдаём первую
    $options['page']     = 1;
    $options['per_page'] = $per * $span;
    $options['offset']   = ($page - 1) * $per;

    $result = feed_threads($options);

    $html = '';

    foreach ($result['items'] as $t) {
        $html .= c_thread_card($t);
    }

    $loaded = $options['offset'] + count($result['items']);

    json_out([
        'html'     => $html,
        'page'     => $page + $span - 1,
        'has_more' => $loaded < (int) $result['pager']['total'],
        'total'    => (int) $result['pager']['total'],
    ]);
}

/**
 * Сколько публикаций появилось после указанного момента.
 * Возвращаем только число: подгружать их сразу нельзя — лента прыгнет
 * под курсором у человека, который в этот момент читает.
 */
function api_feed_updates(): void
{
    $since = (string) input('since', '');

    if ($since === '' || ! strtotime($since)) {
        json_out(['count' => 0]);
    }

    $options = api_feed_options();
    $options['since'] = date('Y-m-d H:i:s', strtotime($since));
    $options['page']  = 1;

    json_out(['count' => feed_count_since($options)]);
}

/** Новые комментарии к публикации после известного идентификатора. */
function api_comments(string $slug): void
{
    $thread = find_thread($slug);

    if (! $thread) {
        json_out(['error' => 'Публикация не найдена'], 404);
    }

    $afterId = (int) input('after', 0);

    // Ответы тоже забираем: раньше условие parent_id IS NULL оставляло их
    // за бортом, и чужая реплика появлялась только после перезагрузки.
    $rows = q("SELECT c.*, u.username AS author_name, u.avatar AS author_avatar, u.reputation AS author_reputation,
           u.is_moderator AS author_moderator, u.is_editor AS author_editor, u.is_bot AS author_bot
               FROM comments c JOIN users u ON u.id = c.user_id
               WHERE c.thread_id = ? AND c.id > ?
               ORDER BY c.id ASC LIMIT 30", [$thread['id'], $afterId]);

    preload_votes('comment', array_column($rows, 'id'));

    $items = [];
    $last  = $afterId;

    // Отрисовываем той же функцией, что и обычная страница: дерево из одного
    // элемента даёт ровно ту же разметку, включая форму ответа и кнопки.
    // id и parent отдаём отдельно — по ним скрипт ставит комментарий в нужное
    // место ветки и не вставляет второй раз то, что уже показано.
    foreach ($rows as $row) {
        $id = (int) $row['id'];

        $items[] = [
            'id'     => $id,
            'parent' => (int) ($row['parent_id'] ?? 0),
            'html'   => c_comments([0 => [$row]], $thread, 0, comment_depth($id)),
        ];

        $last = max($last, $id);
    }

    json_out([
        'items' => $items,
        'count' => count($items),
        'last'  => $last,
        'total' => (int) qv('SELECT COUNT(*) FROM comments WHERE thread_id = ?', [$thread['id']]),
    ]);
}

/** Счётчик непрочитанных — для колокольчика. */
function api_notifications(): void
{
    if (! auth_id()) {
        json_out(['count' => 0]);
    }

    json_out(['count' => unread_count()]);
}

/**
 * Выбор в cookie-баннере.
 *
 * Записываем и согласие, и отказ: отказ подтверждает, что аналитика
 * не запускалась, и без него журнал согласий однобокий.
 */
function api_cookie_consent(): void
{
    csrf_check();

    $choice = input('choice') === 'yes' ? 'accepted' : 'declined';

    record_consent('cookie', ['cookie'], $choice);

    json_out(['ok' => true]);
}
