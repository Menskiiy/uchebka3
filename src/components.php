<?php
/** Переиспользуемые куски разметки — аналог Blade-компонентов. */

function c_temperature(array $t, ?string $vote = null): string
{
    $canVote = auth_id() && auth_id() !== (int) $t['user_id'];
    $temp    = (int) $t['temperature'];
    $class   = $temp > 0 ? 'is-hot' : ($temp < 0 ? 'is-cold' : '');
    $dis     = (auth_id() && ! $canVote) ? ' disabled' : '';

    $url = $canVote ? url('/post/' . rawurlencode($t['slug']) . '/golos') : '';

    $blazing = $temp >= (int) cfg('temperature.hot_threshold') ? ' is-blazing' : '';

    return '<div class="temp' . $blazing . '" data-url="' . e($url) . '" data-login="' . e(url('/vhod')) . '">'
        . '<button type="button" data-vote="cold" class="temp__cold ' . ($vote === 'cold' ? 'is-active' : '') . '"' . $dis . ' title="Холодно">' . icon('down') . '</button>'
        . '<span class="temp__value ' . $class . '">' . $temp . '&deg;</span>'
        . '<button type="button" data-vote="hot" class="temp__hot ' . ($vote === 'hot' ? 'is-active' : '') . '"' . $dis . ' title="Горячо">' . icon('up') . '</button>'
        . '</div>';
}

function c_price(array $t): string
{
    if ($t['price'] === null && $t['old_price'] === null) {
        return '';
    }

    $discount = $t['discount_percent'];

    if (! $discount && $t['price'] !== null && $t['old_price'] > 0) {
        $discount = (int) round((1 - $t['price'] / $t['old_price']) * 100);
    }

    $html = '<div class="price">';

    if ($t['price'] !== null) {
        $html .= '<span class="price__now">' . e(money((float) $t['price'])) . '</span>';
    }

    if ($t['old_price']) {
        $html .= '<span class="price__old">' . e(money((float) $t['old_price'])) . '</span>';
    }

    if ($discount > 0) {
        $html .= '<span class="price__off">&minus;' . (int) $discount . '%</span>';
    }

    return $html . '</div>';
}

function avatar_url(?string $avatar): ?string
{
    if (! $avatar) {
        return null;
    }

    return str_starts_with($avatar, 'http') ? $avatar : url('/storage/' . $avatar);
}

/**
 * Имя автора с аватаром.
 *
 * Третий аргумент остался для совместимости с вызовами, которые передают
 * строку выборки: пометки ролей у имени решили не показывать.
 */
function c_user(string $username, ?string $avatar = null, array $roles = []): string
{
    $initial = mb_strtoupper(mb_substr($username, 0, 1));
    $src     = avatar_url($avatar);

    $inner = $src
        ? '<img src="' . e($src) . '" alt="">'
        : e($initial);

    return '<a href="' . e(url('/profile/' . rawurlencode($username))) . '" class="user">'
        . '<span class="avatar">' . $inner . '</span>' . e($username) . '</a>';
}

function c_thumb(array $t): string
{
    $src = $t['image_path'] ? url('/storage/' . $t['image_path']) : $t['image_url'];

    if ($src) {
        return '<img src="' . e($src) . '" alt="' . e($t['title']) . '" loading="lazy">';
    }

    // своей картинки нет — берём логотип магазина, если он загружен
    if (! empty($t['merchant_logo'])) {
        return '<span class="thread__logo"><img src="' . e(url('/storage/' . $t['merchant_logo']))
            . '" alt="' . e($t['merchant_name'] ?? '') . '" loading="lazy"></span>';
    }

    $color = $t['merchant_color'] ?? '#c9ced6';
    $label = $t['merchant_name'] ?? type_label($t['type']);

    return '<span class="thread__placeholder" style="background:' . e($color) . '">' . e($label) . '</span>';
}

function c_thread_card(array $t): string
{
    $link    = thread_url($t);
    $expired = $t['expires_at'] && strtotime($t['expires_at']) < time();

    // data-thread — якорь для возврата: после «Назад» скрипт ищет карточку,
    // возле которой человек остановился, и ставит ленту ровно на неё.
    $html = '<article class="thread' . ($expired ? ' thread--expired' : '')
        . '" data-thread="' . e($t['slug']) . '">'
        . '<a href="' . e($link) . '" class="thread__media">' . c_thumb($t) . '</a><div class="thread__main">';

    $html .= '<div class="thread__head"><span>' . e(ago($t['published_at'])) . '</span>';
    $html .= '<span style="margin-left:auto">' . c_temperature($t, my_vote('thread', (int) $t['id'])) . '</span></div>';

    $html .= '<h2 class="thread__title"><a href="' . e($link) . '">' . e($t['title']) . '</a></h2>';

    $html .= '<div class="row">' . c_price($t) . c_expiry($t) . c_verified($t);

    if ($t['type'] === 'voucher' && $t['code']) {
        $html .= '<span class="badge badge--code">' . icon('tag') . ' Промокод</span>';
    }

    if ($t['merchant_name']) {
        $html .= '<a href="' . e(url('/magazin/' . rawurlencode($t['merchant_slug']))) . '" class="badge badge--merchant">' . e($t['merchant_name']) . '</a>';
    }

    // у скидки и промокода тип и так очевиден по цене или коду,
    // отдельная метка только дублировала соседнюю
    if (! is_commercial($t['type'])) {
        $html .= '<span class="badge">' . e(type_label($t['type'])) . '</span>';
    }

    $html .= '</div>';

    if ($t['body']) {
        /*
         * У записи блога — первые абзацы, каждый с новой строки (blocks_lead):
         * раньше подзаголовок сливался с абзацем в одно предложение. У скидок
         * оставляем плоский текст — в нём важны условия, в том числе из списков.
         */
        $lead = $t['type'] === 'blog' ? blocks_lead(thread_blocks($t), 200) : [];

        $html .= '<p class="thread__excerpt">'
            . ($lead ? implode('<br>', array_map('e', $lead)) : e(excerpt($t['body'])))
            . '</p>';
    }

    $html .= '<div class="thread__footer">' . c_user($t['author_name'], $t['author_avatar'], $t) . '<span class="spacer"></span>';
    $html .= '<a href="' . e($link) . '" class="btn btn--sm">' . icon('comment') . ' ' . (int) $t['comments_count'] . '</a>';

    if (auth_id() === (int) $t['user_id']) {
        $html .= '<a href="' . e(url('/post/' . rawurlencode($t['slug']) . '/redaktirovat')) . '" class="btn btn--sm btn--ghost">' . icon('edit') . ' Изменить</a>';
    }

    if ($t['status'] === 'pending') {
        $html .= '<span class="badge badge--pending">На проверке</span>';
    } elseif ($expired) {
        $html .= '<span class="badge badge--expired">Истекло</span>';
    } elseif ($t['type'] === 'voucher' && $t['code']) {
        $html .= c_code_box($t);
    } elseif ($t['url']) {
        $html .= '<a href="' . e(url('/go/' . rawurlencode($t['slug']))) . '" class="btn btn--primary btn--sm" target="_blank" rel="nofollow noopener">К скидке</a>';
    }

    return $html . '</div></div></article>';
}

function c_pagination(array $pager): string
{
    if ($pager['pages'] <= 1) {
        return '';
    }

    $html = '<nav class="pagination">';

    $html .= $pager['page'] > 1
        ? '<a href="' . e(page_url($pager['page'] - 1)) . '">&larr;</a>'
        : '<span class="is-disabled">&larr;</span>';

    $from = max(1, $pager['page'] - 2);
    $to   = min($pager['pages'], $pager['page'] + 2);

    if ($from > 1) {
        $html .= '<a href="' . e(page_url(1)) . '">1</a><span class="is-disabled">…</span>';
    }

    for ($i = $from; $i <= $to; $i++) {
        $html .= $i === $pager['page']
            ? '<span class="is-current">' . $i . '</span>'
            : '<a href="' . e(page_url($i)) . '">' . $i . '</a>';
    }

    if ($to < $pager['pages']) {
        $html .= '<span class="is-disabled">…</span><a href="' . e(page_url($pager['pages'])) . '">' . $pager['pages'] . '</a>';
    }

    $html .= $pager['page'] < $pager['pages']
        ? '<a href="' . e(page_url($pager['page'] + 1)) . '">&rarr;</a>'
        : '<span class="is-disabled">&rarr;</span>';

    return $html . '</nav>';
}

function c_report_form(string $type, int $id): string
{
    if (! auth_id()) {
        return '';
    }

    $options = '';

    foreach (cfg('report_reasons') as $key => $label) {
        $options .= '<option value="' . e($key) . '">' . e($label) . '</option>';
    }

    return '<details class="report"><summary>' . icon('flag') . ' Пожаловаться</summary>'
        . '<form method="POST" action="' . e(url('/zhaloba')) . '" class="report__form">'
        . csrf_field()
        . '<input type="hidden" name="type" value="' . e($type) . '">'
        . '<input type="hidden" name="id" value="' . (int) $id . '">'
        . '<select name="reason" class="input" required>' . $options . '</select>'
        . '<input type="text" name="comment" class="input" maxlength="500" placeholder="Комментарий (не обязательно)">'
        . '<button class="btn btn--sm btn--primary">Отправить</button>'
        . '</form></details>';
}

/** Рекурсивный вывод дерева комментариев. */
/**
 * Глубина комментария в ветке.
 *
 * Нужна, когда отрисовывается один свежий ответ: без неё он получал
 * разметку верхнего уровня и до перезагрузки висел без отступа, как
 * отдельный комментарий к публикации.
 */
function comment_depth(int $commentId): int
{
    $depth  = 0;
    $parent = (int) qv('SELECT parent_id FROM comments WHERE id = ?', [$commentId]);

    while ($parent > 0 && $depth < 3) {
        $depth++;
        $parent = (int) qv('SELECT parent_id FROM comments WHERE id = ?', [$parent]);
    }

    return $depth;
}

function c_comments(array $tree, array $thread, int $parent = 0, int $depth = 0): string
{
    if (empty($tree[$parent])) {
        return '';
    }

    $html = '';

    foreach ($tree[$parent] as $comment) {
        $id      = (int) $comment['id'];
        $hasKids = ! empty($tree[$id]);

        /*
         * Уровень вложенности пишем в разметку: ответы лежат соседними
         * блоками, а не внутри родителя, и без него второй и третий уровень
         * выглядели одинаково — по отступу было не понять, кто кому отвечает.
         */
        $cls = $depth > 0 ? ' comment--reply comment--d' . min($depth, 3) : '';

        $html .= '<div class="comment' . $cls . '" id="comment-' . $id . '" data-depth="' . $depth . '">';

        $html .= '<div class="row comment__head">';

        // ветку с ответами можно свернуть — длинные обсуждения иначе
        // приходится пролистывать целиком
        if ($hasKids) {
            $html .= '<button type="button" class="comment__toggle" data-collapse'
                  . ' aria-label="Свернуть ветку" title="Свернуть ветку">&minus;</button>';
        }

        $html .= c_user($comment['author_name'], $comment['author_avatar'], $comment)
              . '<span class="muted" style="font-size:13px">' . e(ago($comment['created_at'])) . '</span>'
              . (! empty($comment['edited_at'])
                  ? '<span class="muted comment__edited" title="Отредактировано '
                    . e(date('d.m.Y H:i', strtotime($comment['edited_at']))) . '">изменено</span>'
                  : '')
              . '<span class="comment__folded" hidden></span>'
              . '</div>';
        $html .= '<div class="comment__body prose">' . render_body($comment['body']) . '</div>';

        $html .= '<div class="comment__actions">';

        if (auth_id()) {
            $liked = my_vote('comment', $id) ? ' style="color:var(--brand)"' : '';
            $html .= '<form method="POST" action="' . e(url('/kommentarii/' . $id . '/like')) . '">' . csrf_field()
                  . '<button type="submit"' . $liked . '>' . icon('like') . ' ' . (int) $comment['likes_count'] . '</button></form>';

            /*
             * «Ответить» есть на любой глубине. Раньше кнопка пропадала
             * с четвёртого уровня — разговор обрывался на полуслове.
             * Вложенность при этом дальше третьего уровня не растёт:
             * отступ упирается в потолок, а ответ остаётся в той же ветке.
             */
            $html .= '<button type="button" data-reply="' . $id . '">Ответить</button>';

            if ((int) $comment['user_id'] === auth_id() || is_moderator()) {
                $html .= '<button type="button" data-edit="' . $id . '">Изменить</button>';
            }

            if (auth_id() === (int) $comment['user_id'] || is_moderator()) {
                $html .= '<form method="POST" action="' . e(url('/kommentarii/' . $id . '/udalit')) . '">' . csrf_field()
                      . '<button type="submit">Удалить</button></form>';
            }
        } else {
            $html .= '<span>' . icon('like') . ' ' . (int) $comment['likes_count'] . '</span>';
        }

        $html .= '</div>';

        if (auth_id()) {
            $html .= c_report_form('comment', $id);

            if ((int) $comment['user_id'] === auth_id() || is_moderator()) {
                $html .= '<form method="POST" action="' . e(url('/kommentarii/' . $id . '/redaktirovat')) . '"'
                      . ' class="reply-form" id="edit-' . $id . '">' . csrf_field()
                      . c_editor('body', (string) $comment['body'], 'bold italic underline strike link image emoji clear', 3, 'Текст комментария')
                      . '<button class="btn btn--primary btn--sm" style="margin-top:8px">Сохранить</button></form>';
            }

            $html .= '<form method="POST" action="' . e(url('/post/' . rawurlencode($thread['slug']) . '/kommentarii')) . '"'
                  . ' class="reply-form" id="reply-' . $id . '">' . csrf_field()
                  . '<input type="hidden" name="parent_id" value="' . $id . '">'
                  . c_editor('body', '', 'bold italic underline strike link image emoji clear', 3, 'Ваш ответ…')
                  . '<button class="btn btn--primary btn--sm" style="margin-top:8px">Ответить</button></form>';
        }

        $html .= '</div>';
        $html .= c_comments($tree, $thread, $id, $depth + 1);
    }

    return $html;
}

/**
 * Метка срока действия. До семи дней показывает обратный отсчёт,
 * дальше — просто дату. Живое обновление делает JS по data-expires.
 */
function c_expiry(array $t, bool $large = false): string
{
    if (empty($t['expires_at']) || ! is_commercial($t['type'])) {
        return '';
    }

    $left    = strtotime($t['expires_at']) - time();
    $classes = 'expiry' . ($large ? ' expiry--large' : '');

    if ($left <= 0) {
        return '<span class="' . $classes . ' expiry--over">'
            . icon('clock')
            . 'Предложение истекло</span>';
    }

    $soon = $left <= 86400;                       // меньше суток — подсвечиваем
    $text = $left <= 7 * 86400
        ? 'Осталось ' . time_left($left)
        : 'Действует до ' . date('d.m.Y', strtotime($t['expires_at']));

    return '<span class="' . $classes . ($soon ? ' expiry--soon' : '') . '"'
        . ' data-expires="' . e($t['expires_at']) . '">'
        . icon('clock')
        . '<span class="expiry__text">' . e($text) . '</span></span>';
}

/** «2 дня 4 ч», «3 ч 12 мин», «8 мин» */
function time_left(int $seconds): string
{
    $days  = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $mins  = intdiv($seconds % 3600, 60);

    if ($days > 0) {
        return $days . ' ' . plural($days, 'день', 'дня', 'дней')
            . ($hours > 0 ? ' ' . $hours . ' ч' : '');
    }

    if ($hours > 0) {
        return $hours . ' ч' . ($mins > 0 ? ' ' . $mins . ' мин' : '');
    }

    return max(1, $mins) . ' ' . plural(max(1, $mins), 'минута', 'минуты', 'минут');
}

/**
 * Метка «Проверено» — её ставит модератор, проверив предложение вручную.
 *
 * Рядом знак вопроса: наведение (на компьютере) или нажатие (на телефоне)
 * показывает, кто и когда проверял. Без даты было непонятно, актуальна ли
 * проверка сегодня, — магазины меняют условия без предупреждения.
 */
function c_verified(array $t): string
{
    if (empty($t['verified_at'])) {
        return '';
    }

    $at   = strtotime($t['verified_at']);
    $site = setting('site_name', cfg('app_name'));

    $tip = 'Эта публикация одобрена командой модераторов ' . $site . '. '
        . 'Проверка проводилась ' . date('d.m.Y', $at) . ' в ' . date('H:i', $at) . ' (МСК): '
        . 'ссылка работала, условия совпадали с описанием. '
        . 'Магазин может изменить их в любой момент — проверяйте на кассе.';

    return '<span class="badge badge--verified">' . icon('check') . ' Проверено'
        . '<button type="button" class="vtip" data-vtip aria-label="Когда проверено" aria-expanded="false">'
        . '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><circle cx="8" cy="8" r="7" fill="none" stroke="currentColor" stroke-width="1.5"/>'
        . '<path d="M6.2 6.2a1.9 1.9 0 0 1 3.7.5c0 1.3-1.9 1.6-1.9 2.8" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
        . '<circle cx="8" cy="11.8" r=".9" fill="currentColor"/></svg>'
        . '<span class="vtip__bubble" role="tooltip">' . e($tip) . '</span>'
        . '</button></span>';
}

/** Редактор как строка — для мест, где разметка собирается в PHP. */
function c_editor(string $name, string $value = '', string $tools = 'bold italic underline strike link emoji clear',
                  int $rows = 4, string $placeholder = 'Начните писать…'): string
{
    ob_start();
    require __DIR__ . '/../views/partials/editor.php';

    return (string) ob_get_clean();
}

/**
 * Кнопки «поделиться» для скидок и промокодов.
 *
 * Находка живёт ровно столько, сколько о ней знают: человек нашёл скидку,
 * и первое желание — скинуть её в чат. Без кнопок он копирует адрес из
 * строки браузера, а с телефона это делают единицы.
 *
 * Кнопки — обычные ссылки на страницы шаринга соцсетей: никаких чужих
 * скриптов на сайте, значит, ни лишних cookie, ни слежки за посетителем.
 * Это важно и юридически — в политике cookie рекламные и сторонние
 * счётчики не заявлены, и добавлять их через виджеты нельзя.
 */
function c_share(array $t): string
{
    $url   = site_url() . thread_url($t);
    $title = $t['title'];

    // в текст добавляем цену: в ленте чата важно, ради чего открывать ссылку
    if ($t['price'] !== null) {
        $title .= ' — ' . money((float) $t['price']);
    } elseif ((int) $t['discount_percent'] > 0) {
        $title .= ' — скидка ' . (int) $t['discount_percent'] . '%';
    }

    $image = $t['image_path'] ? site_url() . url('/storage/' . $t['image_path']) : ($t['image_url'] ?: '');

    /*
     * К каждой соцсети идёт свой адрес с меткой источника.
     *
     * Во-первых, это показывает в Метрике, откуда пришли люди. Во-вторых,
     * решает проблему ВКонтакте: он запоминает разбор страницы по адресу
     * и не перечитывает её сутками. Один раз получив пустую карточку, он
     * отдавал её и после исправления. Своя метка — свой адрес, разбор
     * начинается заново.
     *
     * Поисковой выдаче это не вредит: на странице стоит canonical без
     * параметров, а utm_source перечислен в Clean-param в robots.txt.
     */
    $tagged = static function (string $source) use ($url): string {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'utm_source=' . $source;
    };

    $links = [
        /*
         * ВКонтакте: передаём только адрес.
         *
         * Параметр noparse=true запрещает ВК читать страницу и заставляет
         * строить пост строго из переданных полей — а там обязательны
         * и заголовок, и описание, и картинка. Описания мы не передавали,
         * картинки у многих находок нет вовсе, и пост выходил пустым.
         *
         * Без noparse ВК сам читает страницу и берёт разметку Open Graph,
         * которая у нас заполнена: заголовок с ценой, описание и картинка
         * (у публикаций без своей — логотип магазина или обложка сайта).
         */
        'vk' => [
            'label' => 'ВКонтакте',
            'short' => 'ВК',
            'url'   => 'https://vk.com/share.php?' . http_build_query(['url' => $tagged('vk')]),
        ],
        'ok' => [
            'label' => 'Одноклассники',
            'short' => 'ОК',
            'url'   => 'https://connect.ok.ru/offer?' . http_build_query(array_filter([
                'url'       => $tagged('ok'),
                'title'     => $title,
                'imageUrl'  => $image,
            ])),
        ],
        'tg' => [
            'label' => 'Telegram',
            'short' => 'Telegram',
            'url'   => 'https://t.me/share/url?' . http_build_query([
                'url'  => $tagged('telegram'),
                'text' => $title,
            ]),
        ],
        /*
         * У MAX нет документированной страницы шеринга, как vk.com/share.php:
         * рабочий маршрут /:share держится на разборе приложения и открывается
         * только в мобильном клиенте. Поэтому кнопка показывается лишь на
         * устройствах с касанием — на компьютере она вела бы в никуда.
         */
        'max' => [
            'label' => 'MAX',
            'short' => 'MAX',
            'url'   => 'https://max.ru/:share?' . http_build_query([
                'text' => $title . ' ' . $tagged('max'),
            ]),
        ],
    ];

    $html = '<div class="share"><span class="share__label">Поделиться находкой</span><div class="share__row">';

    foreach ($links as $key => $link) {
        $html .= '<a class="share__btn share__btn--' . $key . '"'
            . ' href="' . e($link['url']) . '"'
            . ' target="_blank" rel="noopener nofollow"'
            . ' title="Поделиться в «' . e($link['label']) . '»">'
            . '<span class="share__mark">' . c_share_mark($key) . '</span>'
            . '<span class="share__name">' . e($link['short']) . '</span>'
            . '</a>';
    }

    // ссылку копируем без соцсетей: чаще всего её просто кидают в чат
    $html .= '<button type="button" class="share__btn share__btn--copy" data-share-copy="' . e($url) . '"'
        . ' title="Скопировать ссылку">'
        . '<span class="share__mark">' . icon('copy') . '</span>'
        . '<span class="share__name">Ссылка</span>'
        . '</button>';

    return $html . '</div></div>';
}

/**
 * Значок соцсети.
 *
 * Официальные иконки лежат картинками в /img/social: рисовать логотипы
 * своими руками — гарантированные кривые пропорции, а набор глифов даёт
 * не тот вид, к которому люди привыкли. Картинки уменьшены до 40 пикселей
 * (20 на экране с двойной плотностью) и весят меньше двух килобайт.
 */
function c_share_mark(string $key): string
{
    $names = ['vk' => 'ВКонтакте', 'ok' => 'Одноклассники', 'tg' => 'Telegram', 'max' => 'MAX'];

    if (! isset($names[$key])) {
        return '';
    }

    return '<img src="' . e(url('/img/social/' . $key . '.png')) . '"'
        . ' width="20" height="20" alt="" loading="lazy" decoding="async">';
}

/** Показать первые символы промокода, остальное скрыть. */
function mask_code(string $code): string
{
    $visible = 3;

    if (mb_strlen($code) <= $visible + 1) {
        return mb_substr($code, 0, 1) . str_repeat('*', max(2, mb_strlen($code) - 1));
    }

    return mb_substr($code, 0, $visible) . str_repeat('*', min(6, mb_strlen($code) - $visible));
}

/**
 * Блок промокода.
 *
 * Код показывается частично, пока не нажали «Показать». По нажатию код
 * раскрывается целиком прямо здесь, копируется в буфер, а магазин
 * открывается в новой вкладке — страница сайта остаётся на месте, и
 * человек может вернуться к коду и условиям, если что-то не сошлось.
 *
 * Кнопка сделана ссылкой на страницу кода: без скриптов она всё равно
 * работает, а с ними переход перехватывается.
 */
function c_code_box(array $t, bool $large = false): string
{
    $code = (string) $t['code'];
    $go   = $t['url'] ? url('/go/' . rawurlencode($t['slug'])) : '';

    return '<div class="code-box' . ($large ? ' code-box--lg' : '') . '"'
        . ' data-code="' . e($code) . '"'
        . ' data-coupon="' . e(url('/promokod/' . rawurlencode($t['slug']))) . '"'
        . ' data-go="' . e($go) . '">'
        . '<code class="code-box__value">' . e(mask_code($code)) . '</code>'
        . '<a class="code-box__btn" rel="nofollow"'
        . ' href="' . e(url('/promokod/' . rawurlencode($t['slug']))) . '">Показать промокод</a>'
        . '</div>';
}

/**
 * Галерея публикации: крупный снимок и лента миниатюр под ним.
 *
 * Первым идёт обложка — то же изображение, что в ленте, чтобы человек
 * узнал карточку, на которую нажал. Дальше дополнительные снимки.
 * Если снимок один, миниатюры не показываем: полоска из одной картинки
 * только занимает место.
 */
function c_gallery(array $t): string
{
    $shots = [];

    $cover = $t['image_path'] ? url('/storage/' . $t['image_path']) : ($t['image_url'] ?: '');

    if ($cover !== '') {
        $shots[] = $cover;
    }

    foreach (q('SELECT path FROM thread_images WHERE thread_id = ? ORDER BY position, id', [$t['id']]) as $row) {
        $shots[] = url('/storage/' . $row['path']);
    }

    if (! $shots) {
        return '<div class="thread__media" style="aspect-ratio:auto;min-height:200px">' . c_thumb($t) . '</div>';
    }

    $html = '<div class="gallery" data-gallery>'
        . '<div class="gallery__main"><img src="' . e($shots[0]) . '" alt="' . e($t['title']) . '"></div>';

    if (count($shots) > 1) {
        $html .= '<div class="gallery__thumbs">';

        foreach ($shots as $i => $src) {
            $html .= '<button type="button" class="gallery__thumb' . ($i === 0 ? ' is-active' : '') . '"'
                . ' data-shot="' . e($src) . '" aria-label="Снимок ' . ($i + 1) . '">'
                . '<img src="' . e($src) . '" alt="" loading="lazy"></button>';
        }

        $html .= '</div>';
    }

    return $html . '</div>';
}

/**
 * Превью категории: картинка, загруженная в админке (Таксономия → значок),
 * а если её нет — смайлик по смыслу раздела.
 *
 * Смайлик ищется сначала по адресу раздела, потом по словам в названии —
 * так новый раздел из админки получит значок без правки кода. Ничего не
 * подошло — первая буква названия.
 *
 * @return array{html: string, image: bool}
 */
function category_icon(array $c): array
{
    if (! empty($c['icon'])) {
        return ['html' => '<img src="' . e(url('/storage/' . $c['icon'])) . '" alt="" loading="lazy">', 'image' => true];
    }

    $bySlug = [
        'gadzhety-i-tehnika' => '🛒', 'krasota-i-zdorove' => '💊', 'eda-i-napitki' => '🍔',
        'elektronika-i-bytovaya-tehnika' => '📱', 'odezhda-obuv-i-aksessuary' => '👟',
        'turizm-i-puteshestviya' => '✈️', 'obuchenie-i-onlayn-kursy' => '🎓',
        'razvlecheniya-i-dosug' => '🎡', 'zootovary' => '🐾', 'avtotovary' => '🚗',
        'uslugi-i-finansy' => '🏦', 'raznoe' => '📦', 'gipermarkety' => '🏬',
        'detskie-tovary' => '🧸', 'kino-i-koncerty' => '🎟️', 'knigi' => '📚',
        'kosmetika-i-parfyumeriya' => '💄', 'ofis-i-rabota' => '💼', 'sport' => '⚽',
        'stroyka' => '🔨', 'tovary-dlya-vzroslyh-18' => '🔞', 'tovary-dlya-doma' => '🛋️',
        'ukrasheniya' => '💍', 'uslugi-i-servisy' => '🛠️', 'filmy-i-muzyka' => '🎬',
        'hosting-internet' => '🌐', 'cvety-i-podarki' => '💐', 'chasy-i-aksessuary' => '⌚',
    ];

    $glyph = $bySlug[$c['slug']] ?? null;

    if ($glyph === null) {
        $byWord = [
            'маркет' => '🛒', 'аптек' => '💊', 'здоров' => '💊', 'еда' => '🍔', 'продукт' => '🥦',
            'электрон' => '📱', 'техник' => '📱', 'одежд' => '👟', 'обув' => '👟', 'путеш' => '✈️',
            'туризм' => '✈️', 'образов' => '🎓', 'курс' => '🎓', 'игр' => '🎮', 'живот' => '🐾',
            'авто' => '🚗', 'банк' => '🏦', 'финанс' => '🏦', 'дет' => '🧸', 'кино' => '🎬',
            'книг' => '📚', 'космет' => '💄', 'спорт' => '⚽', 'дом' => '🛋️', 'ремонт' => '🔨',
            'подар' => '🎁', 'цвет' => '💐', 'связь' => '📶', 'интернет' => '🌐',
        ];

        $name = mb_strtolower($c['name']);

        foreach ($byWord as $word => $candidate) {
            if (str_contains($name, $word)) {
                $glyph = $candidate;
                break;
            }
        }
    }

    return ['html' => $glyph ?? e(mb_strtoupper(mb_substr($c['name'], 0, 1))), 'image' => false];
}

/** Смайлики для редакторов — один набор и в комментариях, и в публикациях. */
function editor_emoji_set(): array
{
    return ['😀', '😂', '😍', '😎', '🤔', '🙄', '😢', '😡',
            '👍', '👎', '👏', '🙏', '💪', '🔥', '⚡', '💰',
            '💸', '🎁', '🛒', '🏷️', '✅', '❌', '❗', '❓',
            '💡', '🚀', '🎉', '❤️', '💔', '👀', '🤝', '🤷'];
}
