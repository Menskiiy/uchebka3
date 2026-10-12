<?php
/**
 * Бот-публикатор скидок Steam.
 *
 * Работает через витринные эндпоинты магазина Steam. Они неофициальные:
 * Valve их не документирует и может изменить или ограничить в любой момент.
 * Поэтому все обращения обёрнуты в проверки, а любой сбой означает
 * «в этот раз ничего не публикуем», а не падение сайта.
 */

const STEAM_FEATURED = 'https://store.steampowered.com/api/featuredcategories';
const STEAM_DETAILS  = 'https://store.steampowered.com/api/appdetails';
const STEAM_APP_URL  = 'https://store.steampowered.com/app/';

/* ------------------------------------------------------------ HTTP-запрос */

/**
 * Загрузка JSON. Возвращает массив или null — вызывающий код
 * обязан проверить результат.
 */
function bot_get_json(string $url, array $query = []): ?array
{
    $full = $url . ($query ? '?' . http_build_query($query) : '');
    $raw  = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($full);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'HotHuntBot/1.0 (+' . site_url() . ')',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        curl_close($ch);

        if ($raw === false || $code !== 200) {
            bot_log('запрос не удался (' . $code . '): ' . ($err ?: $full));

            return null;
        }
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create(['http' => [
            'timeout' => 20,
            'header'  => "Accept: application/json\r\nUser-Agent: HotHuntBot/1.0\r\n",
        ]]);

        $raw = @file_get_contents($full, false, $context);

        if ($raw === false) {
            bot_log('не удалось загрузить: ' . $full);

            return null;
        }
    } else {
        bot_log('на сервере нет ни cURL, ни allow_url_fopen — бот работать не сможет');

        return null;
    }

    $data = json_decode((string) $raw, true);

    if (! is_array($data)) {
        bot_log('в ответе не JSON: ' . mb_substr((string) $raw, 0, 120));

        return null;
    }

    return $data;
}

function bot_log(string $message): void
{
    $dir = __DIR__ . '/../data';

    if (is_dir($dir)) {
        @file_put_contents($dir . '/bot.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function bot_log_tail(int $lines = 40): string
{
    $path = __DIR__ . '/../data/bot.log';

    if (! is_readable($path)) {
        return '';
    }

    $all = explode("\n", (string) file_get_contents($path));

    return trim(implode("\n", array_slice($all, -$lines)));
}

/* ------------------------------------------------------- аккаунт публикатора */

/** Аккаунт, от имени которого публикуются находки бота. Создаётся один раз. */
function bot_user(): ?array
{
    $name = setting('bot_username', 'HotHuntBot');
    $user = q1('SELECT * FROM users WHERE username = ?', [$name]);

    if ($user) {
        return $user;
    }

    $id = insert('users', [
        'username'            => $name,
        'email'               => 'bot@' . (parse_url(site_url(), PHP_URL_HOST) ?: 'localhost'),
        'password'            => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'bio'                 => 'Публикую скидки из Steam автоматически. Проверяйте условия на странице игры.',
        'reputation'          => 0,
        'is_moderator'        => 0,
        'email_verified_at'   => now(),
        'email_notifications' => 0,
        'unsubscribe_token'   => bin2hex(random_bytes(16)),
        'created_at'          => now(),
    ]);

    bot_log('создан аккаунт публикатора: ' . $name);

    return q1('SELECT * FROM users WHERE id = ?', [$id]);
}

/** Магазин Steam в справочнике — к нему привязываются публикации. */
function bot_merchant(): ?array
{
    $merchant = q1("SELECT * FROM merchants WHERE domain = 'store.steampowered.com' OR slug = 'steam' LIMIT 1");

    if ($merchant) {
        return $merchant;
    }

    $id = insert('merchants', [
        'name'        => 'Steam',
        'slug'        => slugify('Steam', 'merchants'),
        'domain'      => 'store.steampowered.com',
        'description' => 'Крупнейший магазин компьютерных игр.',
        'logo_color'  => '#1b2838',
        'is_top'      => 1,
        'likes_count' => 0,
        'position'    => 0,
    ]);

    return q1('SELECT * FROM merchants WHERE id = ?', [$id]);
}

/* ------------------------------------------------------------- импорт скидок */

/**
 * Забрать скидки и опубликовать.
 *
 * @return array{added:int,skipped:int,errors:int,message:string}
 */
function bot_import_steam(bool $manual = false): array
{
    $result = ['added' => 0, 'skipped' => 0, 'errors' => 0, 'message' => ''];

    if (setting('bot_enabled', '0') !== '1' && ! $manual) {
        $result['message'] = 'Бот выключен в настройках.';

        return $result;
    }

    $region      = setting('bot_region', 'ru');
    $minDiscount = (int) setting('bot_min_discount', '40');
    $limit       = max(1, min(20, (int) setting('bot_limit', '5')));
    $status      = setting('bot_moderate', '1') === '1' ? 'pending' : 'published';

    $featured = bot_get_json(STEAM_FEATURED, ['cc' => $region, 'l' => 'russian']);

    if (! $featured || empty($featured['specials']['items'])) {
        $result['errors']++;
        $result['message'] = 'Steam не вернул список скидок. Подробности в журнале.';
        bot_log('витрина пуста или недоступна');

        return $result;
    }

    $author   = bot_user();
    $merchant = bot_merchant();

    if (! $author) {
        $result['errors']++;
        $result['message'] = 'Не удалось создать аккаунт публикатора.';

        return $result;
    }

    $items = $featured['specials']['items'];
    shuffle($items);   // чтобы каждый запуск брал разные игры, а не первые по списку

    foreach ($items as $item) {
        if ($result['added'] >= $limit) {
            break;
        }

        $appId    = (int) ($item['id'] ?? 0);
        $discount = (int) ($item['discount_percent'] ?? 0);

        if ($appId <= 0 || $discount < $minDiscount) {
            $result['skipped']++;
            continue;
        }

        if (qv('SELECT COUNT(*) FROM bot_imports WHERE source = ? AND external_id = ?', ['steam', $appId]) > 0) {
            $result['skipped']++;
            continue;
        }

        $thread = bot_publish_app($appId, $region, $author, $merchant, $status);

        if ($thread === null) {
            $result['errors']++;
        } else {
            $result['added']++;
        }

        // Steam ограничивает частоту обращений, поэтому между играми делаем паузу
        usleep(1200000);
    }

    $result['message'] = sprintf('Опубликовано: %d, пропущено: %d, ошибок: %d.',
        $result['added'], $result['skipped'], $result['errors']);

    bot_log($result['message']);

    return $result;
}

/** Получить подробности игры и создать публикацию. Возвращает id или null. */
function bot_publish_app(int $appId, string $region, array $author, ?array $merchant, string $status): ?int
{
    $details = bot_get_json(STEAM_DETAILS, [
        'appids'  => $appId,
        'cc'      => $region,
        'l'       => 'russian',
    ]);

    $data = $details[$appId]['data'] ?? null;

    if (! $data || ($details[$appId]['success'] ?? false) !== true) {
        bot_log('нет данных по игре ' . $appId);

        return null;
    }

    // берём только игры: DLC и наборы засоряют ленту
    if (($data['type'] ?? '') !== 'game') {
        return null;
    }

    $price = $data['price_overview'] ?? null;

    if (! $price || empty($price['discount_percent'])) {
        return null;
    }

    $discount = (int) $price['discount_percent'];
    $final    = (int) ($price['final'] ?? 0) / 100;
    $initial  = (int) ($price['initial'] ?? 0) / 100;
    $name     = trim((string) ($data['name'] ?? ''));

    if ($name === '' || $final <= 0) {
        return null;
    }

    $title = mb_substr($name . ' — скидка ' . $discount . '%', 0, 200);

    $body = html_to_text($data['short_description'] ?? '');
    $body = $body !== '' ? excerpt($body, 400) : 'Скидка в Steam.';

    if (! empty($data['genres'])) {
        $genres = array_slice(array_column($data['genres'], 'description'), 0, 4);
        $body .= "\n\nЖанры: " . implode(', ', $genres) . '.';
    }

    $body .= "\n\nЦена и наличие скидки проверяются на странице игры — условия могут измениться.";

    $slug = slugify($name . '-steam-' . $discount, 'threads');

    $threadId = insert('threads', [
        'type'             => 'deal',
        'status'           => $status,
        'user_id'          => $author['id'],
        'merchant_id'      => $merchant['id'] ?? null,
        'title'            => $title,
        'slug'             => $slug,
        'body'             => clean_html(nl2br(e($body))),
        'url'              => STEAM_APP_URL . $appId . '/',
        'image_url'        => $data['header_image'] ?? null,
        'price'            => $final,
        'old_price'        => $initial > $final ? $initial : null,
        'discount_percent' => $discount,
        'temperature'      => 0,
        'expires_at'       => date('Y-m-d H:i:s', time() + 7 * 86400),
        'published_at'     => now(),
        'created_at'       => now(),
    ]);

    bot_attach_category($threadId);

    insert('bot_imports', [
        'source'      => 'steam',
        'external_id' => $appId,
        'thread_id'   => $threadId,
        'created_at'  => now(),
    ]);

    bot_log(sprintf('добавлено: %s (%d%%, %s)', $name, $discount, money($final)));

    return $threadId;
}

/** Привязать к игровой категории, если она есть в справочнике. */
function bot_attach_category(int $threadId): void
{
    $category = q1("SELECT id FROM categories WHERE slug IN ('pc', 'videoigry', 'konsoli-i-videoigry') ORDER BY parent_id IS NULL LIMIT 1");

    if ($category) {
        insert('category_thread', ['thread_id' => $threadId, 'category_id' => (int) $category['id']]);
    }
}
