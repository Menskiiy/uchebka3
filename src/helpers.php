<?php
/** Общие функции: экранирование, ссылки, CSRF, формат дат и цен. */

/** Хранилище настроек по ссылке, чтобы админка могла их переопределять. */
function &config_store(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }

    return $config;
}

function cfg(string $key, $default = null)
{
    $config = &config_store();

    if (str_contains($key, '.')) {
        [$a, $b] = explode('.', $key, 2);

        return $config[$a][$b] ?? $default;
    }

    return $config[$key] ?? $default;
}

function cfg_set(string $key, $value): void
{
    $config = &config_store();

    if (str_contains($key, '.')) {
        [$a, $b] = explode('.', $key, 2);
        $config[$a][$b] = $value;

        return;
    }

    $config[$key] = $value;
}

/**
 * Настройки из админки перекрывают значения из config.php.
 * Вызывается один раз после подключения к базе.
 */
function apply_settings(): void
{
    $map = [
        'site_name'                => 'app_name',
        'site_description'         => 'app_description',
        'per_page'                 => 'per_page',
        'lifetime_days'            => 'lifetime_days',
        'hot_threshold'            => 'temperature.hot_threshold',
    ];

    foreach ($map as $name => $path) {
        $value = setting($name);

        if ($value !== null && $value !== '') {
            cfg_set($path, is_numeric($value) ? $value + 0 : $value);
        }
    }
}

/** Экранирование. Использовать ВЕЗДЕ при выводе пользовательских данных. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/'): string
{
    return cfg('base_url') . $path;
}

function redirect(string $path): void
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

function back(): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? url('/');
    header('Location: ' . $ref);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function input(string $key, $default = null)
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;

    return is_string($value) ? trim($value) : $value;
}

/* ------------------------------------------------------------------ CSRF */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

/** Проверка токена на любом POST. Несовпадение — 419. */
/** «8M», «512K» из php.ini — в байты. */
function bytes_from_ini(string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    $number = (int) $value;
    $suffix = strtolower(substr($value, -1));

    return match ($suffix) {
        'g'     => $number * 1024 * 1024 * 1024,
        'm'     => $number * 1024 * 1024,
        'k'     => $number * 1024,
        default => $number,
    };
}

/**
 * Сколько байт реально можно загрузить.
 *
 * Ограничений два, и меньшее из них решает: upload_max_filesize режет сам
 * файл, post_max_size — весь запрос вместе с полями формы.
 */
function upload_limit_bytes(): int
{
    $post = bytes_from_ini((string) ini_get('post_max_size'));
    $file = bytes_from_ini((string) ini_get('upload_max_filesize'));

    $limits = array_filter([$post, $file]);

    return $limits ? (int) min($limits) : 2 * 1024 * 1024;
}

function size_human(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return round($bytes / 1024 / 1024, 1) . ' МБ';
    }

    return max(1, (int) round($bytes / 1024)) . ' КБ';
}

function csrf_check(): void
{
    $sent = $_POST['_token'] ?? '';

    /*
     * Пустой POST при ненулевом размере запроса — это не устаревшая страница,
     * а слишком большой файл: PHP отбрасывает тело целиком, вместе с токеном,
     * и проверка падает. Без этой развилки человек видит «обновите страницу»
     * и обновляет её по кругу с тем же результатом.
     */
    if ($sent === '' && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('Файл слишком большой: сервер принимает не больше '
            . size_human(upload_limit_bytes()) . ' за раз. Уменьшите изображение и попробуйте ещё раз.', 'error');

        back();
    }

    if (! hash_equals(csrf_token(), (string) $sent)) {
        http_response_code(419);
        exit('Срок действия страницы истёк. Обновите страницу и повторите.');
    }
}

/* ------------------------------------------------------------- сообщения */

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function take_flash(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $items;
}

function errors_set(array $errors, array $old = []): void
{
    $_SESSION['errors'] = $errors;
    $_SESSION['old']    = $old;
}

function errors_take(): array
{
    $errors = $_SESSION['errors'] ?? [];
    unset($_SESSION['errors']);

    return $errors;
}

function old_take(): array
{
    $old = $_SESSION['old'] ?? [];
    unset($_SESSION['old']);

    return $old;
}

/* ---------------------------------------------------------------- формат */

/** Русские склонения: 1 час, 2 часа, 5 часов. */
function plural(int $n, string $one, string $few, string $many): string
{
    $n = abs($n) % 100;
    $n1 = $n % 10;

    if ($n > 10 && $n < 20) return $many;
    if ($n1 > 1 && $n1 < 5)  return $few;
    if ($n1 === 1)           return $one;

    return $many;
}

function ago(?string $datetime): string
{
    if (! $datetime) {
        return '—';
    }

    $diff = time() - strtotime($datetime);

    if ($diff < 60)    return 'только что';
    if ($diff < 3600) {
        $m = (int) ($diff / 60);
        return $m . ' ' . plural($m, 'минуту', 'минуты', 'минут') . ' назад';
    }
    if ($diff < 86400) {
        $h = (int) ($diff / 3600);
        return 'около ' . $h . ' ' . plural($h, 'часа', 'часов', 'часов') . ' назад';
    }
    if ($diff < 2592000) {
        $d = (int) ($diff / 86400);
        return $d . ' ' . plural($d, 'день', 'дня', 'дней') . ' назад';
    }

    return date('d.m.Y', strtotime($datetime));
}

function until(?string $datetime): string
{
    if (! $datetime) {
        return '';
    }

    $diff = strtotime($datetime) - time();

    if ($diff <= 0) {
        return 'истёк';
    }

    $d = (int) ($diff / 86400);

    if ($d > 0) {
        return 'через ' . $d . ' ' . plural($d, 'день', 'дня', 'дней');
    }

    $h = max(1, (int) ($diff / 3600));

    return 'через ' . $h . ' ' . plural($h, 'час', 'часа', 'часов');
}

function money(?float $value): string
{
    if ($value === null) {
        return '';
    }

    if ((float) $value === 0.0) {
        return 'Бесплатно';
    }

    $decimals = fmod($value, 1) == 0 ? 0 : 2;

    return number_format($value, $decimals, ',', ' ') . '₽';
}

/**
 * HTML в читаемый плоский текст.
 *
 * strip_tags() сам по себе вырезает теги без следа: «текст</div><div>ещё»
 * превращается в «текстещё» — абзацы склеиваются в одно слово без пробела.
 * Поэтому границы блоков сначала размечаются, и только потом вырезаются
 * оставшиеся теги.
 *
 * Одного пробела на границе мало: «…от Яндекса</h3><p>Появилась…» читалось
 * как одно предложение «…от Яндекса Появилась…». Если кусок перед границей
 * не заканчивается знаком препинания, ставим точку — так заголовок, пункт
 * списка и абзац остаются отдельными фразами и в анонсе, и в описании для
 * поисковика, и в дайджесте.
 */
function html_to_text(?string $html): string
{
    $html = (string) $html;

    if ($html === '') {
        return '';
    }

    $cut = "\x1E";   // разделитель блоков: в обычном тексте его не бывает

    // двойной перенос — граница абзаца, одиночный — просто пробел
    $html = preg_replace('#<br\s*/?>\s*(?:<br\s*/?>\s*)+#i', $cut, $html) ?? $html;
    $html = preg_replace('#</?(?:p|div|h[1-6]|blockquote|li|ul|ol|figure|figcaption|hr|pre|tr|table|cite)\b[^>]*>#i', $cut, $html) ?? $html;
    $html = preg_replace('#<br\s*/?>#i', ' ', $html) ?? $html;

    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    /*
     * Битую кодировку выбрасываем до склейки пробелов: preg_replace с
     * модификатором /u возвращает null, если в строке невалидный UTF-8,
     * и дальше trim() падал с ошибкой. Такое приезжает из описаний,
     * залитых с чужой кодировкой, — на странице магазина это роняло
     * сборку description.
     */
    if (! mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    $parts = [];

    foreach (explode($cut, $text) as $part) {
        $part = preg_replace('/\s+/u', ' ', $part) ?? preg_replace('/\s+/', ' ', $part);
        $part = trim((string) $part);

        if ($part !== '') {
            $parts[] = $part;
        }
    }

    $last = count($parts) - 1;

    foreach ($parts as $i => $part) {
        // точка нужна только между кусками: конец текста оставляем как есть
        if ($i < $last && ! preg_match('/[.!?…:;,—–\-]["»”)\]]*$/u', $part)) {
            $parts[$i] = $part . '.';
        }
    }

    return implode(' ', $parts);
}

function excerpt(?string $text, int $length = 180): string
{
    $text = html_to_text($text);

    if (mb_strlen($text) <= $length) {
        return $text;
    }

    return mb_substr($text, 0, $length) . '…';
}

function slugify(string $title, string $table = 'threads'): string
{
    $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh',
        'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
        'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c',
        'ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
    ];

    $slug = strtr(mb_strtolower($title, 'UTF-8'), $map);
    $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug);
    $slug = trim((string) $slug, '-');
    $slug = mb_substr($slug, 0, 80);

    if ($slug === '') {
        $slug = 'post';
    }

    $base = $slug;
    $i    = 1;

    while (qv("SELECT COUNT(*) FROM {$table} WHERE slug = ?", [$slug]) > 0) {
        $slug = $base . '-' . (++$i);
    }

    return $slug;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function type_label(string $type): string
{
    return cfg('thread_types')[$type]['label'] ?? $type;
}

function type_by_slug(string $slug): ?string
{
    foreach (cfg('thread_types') as $key => $meta) {
        if ($meta['slug'] === $slug) {
            return $key;
        }
    }

    return null;
}

function is_commercial(string $type): bool
{
    return (bool) (cfg('thread_types')[$type]['commercial'] ?? false);
}

function status_label(string $status): string
{
    return [
        'draft'     => 'Черновик',
        'pending'   => 'На проверке',
        'published' => 'Опубликовано',
        'expired'   => 'Истекло',
        'rejected'  => 'Отклонено',
    ][$status] ?? $status;
}

function rank_for(int $reputation): array
{
    $current = cfg('ranks')[0];

    foreach (cfg('ranks') as $rank) {
        if ($reputation >= $rank['min']) {
            $current = $rank;
        }
    }

    return $current;
}

function rank_progress(int $reputation): int
{
    $current = rank_for($reputation);
    $next    = null;

    foreach (cfg('ranks') as $rank) {
        if ($rank['min'] > $current['min']) { $next = $rank; break; }
    }

    if (! $next) {
        return 100;
    }

    $span = $next['min'] - $current['min'];

    return (int) max(0, min(100, round(($reputation - $current['min']) / max(1, $span) * 100)));
}

/** Постраничная навигация: массив ссылок для вывода. */
function paginate(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = max(1, min($page, $pages));

    return [
        'page'   => $page,
        'pages'  => $pages,
        'offset' => ($page - 1) * $perPage,
        'total'  => $total,
    ];
}

function page_url(int $page): string
{
    $query = $_GET;
    $query['page'] = $page;

    return '?' . http_build_query($query);
}

/** Типы, доступные в форме публикации. Скрытые остаются в базе, но не предлагаются. */
function publishable_types(): array
{
    return array_filter(cfg('thread_types'), static fn ($meta) => empty($meta['hidden']));
}

/**
 * Привести ссылку к виду, который понимают и браузер, и проверка PHP.
 *
 * Партнёрские ссылки часто содержат кириллицу в параметрах — например,
 * промокод: ...&kc=ИНВЕСТОР. По стандарту так можно, браузер такую ссылку
 * открывает, а filter_var() считает её неверной, и публикация отбивалась
 * с «Ссылка указана неверно». Поэтому сначала кодируем всё, что за
 * пределами ASCII, и только потом проверяем.
 *
 * Возвращает готовую к сохранению ссылку или null, если это не адрес.
 */
function normalize_url(?string $url): ?string
{
    $url = trim((string) $url);

    if ($url === '') {
        return null;
    }

    // человек копирует адрес без схемы — дописываем, а не отказываем
    if (! preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    $encoded = preg_replace_callback(
        '/[^\x20-\x7E]/u',
        static fn (array $m): string => rawurlencode($m[0]),
        $url
    ) ?? $url;

    $encoded = str_replace(' ', '%20', $encoded);

    if (! filter_var($encoded, FILTER_VALIDATE_URL)) {
        return null;
    }

    // только http и https: javascript: и data: в ссылке предложения не место
    $scheme = strtolower((string) parse_url($encoded, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https'], true) ? $encoded : null;
}

/**
 * Похоже ли это вообще на ссылку.
 *
 * Нужно, чтобы отличить опечатку в адресе от строки «завтра позвоню»:
 * первую отправляем модератору, на вторую отвечаем ошибкой.
 */
function looks_like_url(string $value): bool
{
    return (bool) preg_match('~^[a-z][a-z0-9+.-]*://~i', trim($value))
        || (bool) preg_match('~^[\w.-]+\.[a-z]{2,}(/|$)~iu', trim($value));
}

/**
 * Адрес css или js с меткой версии.
 *
 * Метка — время последнего изменения самого файла. Раньше номер версии
 * правился руками, и достаточно было один раз о нём забыть, чтобы у людей
 * со старым кешем осталась прежняя вёрстка при новой разметке. Теперь
 * адрес меняется сам вместе с файлом, а если файл не менялся — браузер
 * продолжает брать его из кеша.
 */
function asset(string $path): string
{
    $file = __DIR__ . '/../public' . $path;
    $time = @filemtime($file);

    return url($path) . '?v=' . ($time !== false ? $time : date('Ymd'));
}

/**
 * Адрес страницы публикации.
 *
 * Если магазин известен, адрес получается вида
 * /magazin/ozon/skidka-na-noutbuk — так он сам рассказывает поисковику,
 * о чём страница, и попадает в выдачу по запросам «промокод ozon».
 * Публикации без магазина (блоги, обсуждения) остаются на /post/.
 *
 * @param array|string $thread строка публикации или слаг
 */
function thread_path($thread, string $suffix = ''): string
{
    if (is_array($thread)) {
        $slug     = (string) $thread['slug'];
        $merchant = (string) ($thread['merchant_slug'] ?? '');
    } else {
        $slug     = (string) $thread;
        $merchant = '';
    }

    $path = $merchant !== ''
        ? '/magazin/' . rawurlencode($merchant) . '/' . rawurlencode($slug)
        : '/post/' . rawurlencode($slug);

    return $path . $suffix;
}

/** Готовая ссылка на публикацию. */
function thread_url($thread, string $suffix = ''): string
{
    return url(thread_path($thread, $suffix));
}

/**
 * Метка перехода для партнёрской сети.
 *
 * По ней в отчётах сети видно, какая публикация и какой автор привели
 * заказ: без метки приходит общая сумма, и понять, что именно работает,
 * невозможно. Формат простой и короткий — длинные метки часть сетей режет.
 */
function affiliate_subid(array $thread, ?int $userId = null): string
{
    return 'hh' . (int) $thread['id'] . '-' . (int) ($userId ?: 0);
}

/**
 * Собрать партнёрскую ссылку.
 *
 * Два режима, оба задаются у магазина в панели:
 *   1. Шаблон — адрес партнёрской сети с {url} и {subid}. Подходит для
 *      Admitad, ePN, GdeSlon: обычную ссылку на товар оборачиваем в свою.
 *   2. Параметр — ссылка уже партнёрская, к ней достаточно дописать метку
 *      (у сетей она называется по-разному: subid, sub_id, utm_term).
 *
 * Если у магазина не настроено ничего, возвращаем адрес как есть:
 * переход всё равно должен работать.
 */
function affiliate_link(string $url, array $merchant, string $subid): string
{
    $template = trim((string) ($merchant['affiliate_url'] ?? ''));

    if ($template !== '') {
        return str_replace(
            ['{url}', '{urlenc}', '{subid}'],
            [$url, rawurlencode($url), rawurlencode($subid)],
            $template
        );
    }

    $param = trim((string) ($merchant['affiliate_param'] ?? ''));

    if ($param === '') {
        return $url;
    }

    // метку дописываем аккуратно: у ссылки уже может быть запрос и якорь
    $parts    = parse_url($url);
    $query    = [];

    if (! empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $query[$param] = $subid;

    $rebuilt = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
        . ($parts['port'] ?? '' ? ':' . $parts['port'] : '')
        . ($parts['path'] ?? '')
        . '?' . http_build_query($query)
        . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

    return $rebuilt;
}

/**
 * Дата по-русски для отбивки в ленте блогов: «27 сентября», а у прошлых
 * лет — с годом. Сегодняшний и вчерашний день называем словами: так
 * человеку понятнее, чем дата, которую он и так знает.
 */
function day_ru(?string $datetime): string
{
    $ts = $datetime ? strtotime($datetime) : time();

    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return 'сегодня';
    }

    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) {
        return 'вчера';
    }

    $months = ['января','февраля','марта','апреля','мая','июня',
               'июля','августа','сентября','октября','ноября','декабря'];

    $day = (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1];

    return date('Y', $ts) === date('Y') ? $day : $day . ' ' . date('Y', $ts);
}
