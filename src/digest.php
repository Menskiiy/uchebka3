<?php
/**
 * Дайджест «скоро сгорает» для каналов сайта.
 *
 * Зачем: находка живёт ровно столько, сколько о ней знают, а срочность —
 * единственный повод, по которому человек открывает канал скидок каждый
 * день. Поэтому в канал уходит не всё подряд, а один пост в сутки с тем,
 * что кончается в ближайшие двое суток.
 *
 * Почему дайджестом, а не по одной публикации: пять отдельных сообщений
 * подряд читаются как спам и стоят отписки, а один список — как сводка.
 *
 * Транспорты отделены от текста намеренно. Текст собирается один раз,
 * а отправить его можно в MAX, в Telegram или в оба сразу: когда появится
 * третий мессенджер, писать придётся только функцию отправки.
 */

/** Каналы, в которые умеем отправлять. */
function digest_channels(): array
{
    return [
        'max' => [
            'label' => 'MAX',
            'token' => setting('max_bot_token', ''),
            'chat'  => setting('max_chat_id', ''),
        ],
        'telegram' => [
            'label' => 'Telegram',
            'token' => setting('tg_bot_token', ''),
            'chat'  => setting('tg_chat_id', ''),
        ],
    ];
}

function digest_channel_ready(string $key): bool
{
    $channel = digest_channels()[$key] ?? null;

    return $channel !== null && $channel['token'] !== '' && $channel['chat'] !== '';
}

/**
 * Что отправлять.
 *
 * Отсекаем уже отправленное в этот канал: предложение сгорает двое суток,
 * и без проверки оно попало бы в дайджест два дня подряд.
 */
function digest_items(string $channel, int $limit = 7): array
{
    $items = expiring_for_digest($limit * 3);

    if (! $items) {
        return [];
    }

    $sent = q('SELECT thread_id FROM digest_sent WHERE channel = ?', [$channel]);
    $sent = array_flip(array_map('intval', array_column($sent, 'thread_id')));

    $fresh = [];

    foreach ($items as $item) {
        if (isset($sent[(int) $item['id']])) {
            continue;
        }

        $fresh[] = $item;

        if (count($fresh) >= $limit) {
            break;
        }
    }

    return $fresh;
}

/** «меньше 3 часов», «сегодня до 23:59», «завтра». */
function digest_deadline(string $expiresAt): string
{
    $left = strtotime($expiresAt) - time();

    if ($left < 3 * 3600) {
        return 'осталось меньше 3 часов';
    }

    if (date('Y-m-d', strtotime($expiresAt)) === date('Y-m-d')) {
        return 'сегодня до ' . date('H:i', strtotime($expiresAt));
    }

    if (date('Y-m-d', strtotime($expiresAt)) === date('Y-m-d', time() + 86400)) {
        return 'завтра';
    }

    return 'до ' . date('d.m', strtotime($expiresAt));
}

/**
 * Текст поста.
 *
 * Обычный текст без разметки: у MAX и Telegram она разная, а ломаная
 * разметка выглядит хуже, чем её отсутствие. Ссылка ведёт на страницу
 * сайта, а не сразу в магазин: иначе сайт выпадает из цепочки и остаётся
 * без переходов, комментариев и проверок промокодов.
 */
function digest_text(array $items): string
{
    $lines = ['🔥 Скоро сгорает — ' . count($items) . ' ' . plural(count($items), 'находка', 'находки', 'находок'), ''];

    foreach ($items as $item) {
        $line = '• ' . $item['title'];

        if ($item['price'] !== null) {
            $line .= ' — ' . money((float) $item['price']);
        } elseif ((int) $item['discount_percent'] > 0) {
            $line .= ' — скидка ' . (int) $item['discount_percent'] . '%';
        }

        $lines[] = $line;

        $meta = [];

        if ($item['merchant_name']) {
            $meta[] = $item['merchant_name'];
        }

        $meta[] = digest_deadline($item['expires_at']);

        if ($item['type'] === 'voucher' && $item['code']) {
            $meta[] = 'промокод ' . $item['code'];
        }

        $lines[] = '  ' . implode(' · ', $meta);
        $lines[] = '  ' . site_url() . thread_url($item);
        $lines[] = '';
    }

    $lines[] = 'Все находки: ' . site_url() . url('/?tab=expiring');

    return implode("\n", $lines);
}

/**
 * Запрос к API канала.
 *
 * MAX и Telegram различаются мелочами, из-за которых теряется вечер:
 * в MAX токен идёт заголовком Authorization без слова Bearer, в Telegram —
 * прямо в адресе. Оба отвечают JSON, и оба молчат об ошибке, если смотреть
 * только на код ответа.
 */
/**
 * Свой набор корневых сертификатов, если он положен рядом с сайтом.
 *
 * Цепочка MAX завязана на корневой сертификат Минцифры, которого нет в
 * стандартном хранилище Ubuntu. На сервере с правами root его ставят
 * системно (update-ca-certificates), но на хостинге таких прав обычно нет.
 * Тогда файл кладут в data/ca-bundle.pem, и запросы используют его.
 */
function digest_ca_bundle(): ?string
{
    $path = setting('http_ca_bundle', '') ?: __DIR__ . '/../data/ca-bundle.pem';

    return is_readable($path) ? $path : null;
}

function digest_request(string $channel, string $text): array
{
    $config = digest_channels()[$channel] ?? null;

    if (! $config || $config['token'] === '' || $config['chat'] === '') {
        return ['ok' => false, 'error' => 'канал не настроен'];
    }

    /*
     * Адрес API вынесен в настройки не ради гибкости, а потому что MAX уже
     * один раз переезжал: platform-api.max.ru отключили, работает
     * platform-api2.max.ru. Следующий переезд не должен требовать правки кода.
     */
    if ($channel === 'max') {
        $base    = rtrim(setting('max_api_base', 'https://platform-api2.max.ru'), '/');
        $url     = $base . '/messages?chat_id=' . rawurlencode($config['chat']);
        $headers = ['Content-Type: application/json', 'Authorization: ' . $config['token']];
        $body    = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $base    = rtrim(setting('tg_api_base', 'https://api.telegram.org'), '/');
        $url     = $base . '/bot' . $config['token'] . '/sendMessage';
        $headers = ['Content-Type: application/json'];
        $body    = json_encode([
            'chat_id'                  => $config['chat'],
            'text'                     => $text,
            'disable_web_page_preview' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $bundle = digest_ca_bundle();

    // на части хостингов curl выключен — тогда шлём потоком, как остальной сайт
    if (function_exists('curl_init')) {
        $ch = curl_init($url);

        $options = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ];

        // проверку сертификата не отключаем ни при каких условиях: это канал,
        // по которому уходит токен бота. Вместо этого подсовываем нужный корень
        if ($bundle !== null) {
            $options[CURLOPT_CAINFO] = $bundle;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => 20,
                'ignore_errors' => true,
            ],
            'ssl' => $bundle !== null ? ['cafile' => $bundle] : [],
        ]);

        $response = @file_get_contents($url, false, $context);
        $error    = $response === false ? 'запрос не выполнен' : '';
        $code     = 0;

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $code = (int) $m[1];
            }
        }
    }

    if ($response === false) {
        $reason = $error ?: 'нет ответа от сервера';

        /*
         * У MAX цепочка сертификатов завязана на корневой сертификат Минцифры,
         * которого нет в стандартном хранилище системы. Ошибка приходит голой
         * строкой про local issuer certificate — дописываем, что с этим делать,
         * иначе причину ищут полдня.
         */
        if (str_contains(mb_strtolower($reason), 'certificate')) {
            $reason .= $bundle === null
                ? ' — не хватает корневого сертификата Минцифры. Положите его в data/ca-bundle.pem'
                  . ' или установите в систему (update-ca-certificates).'
                : ' — файл ' . basename($bundle) . ' найден, но сертификат в нём не подошёл.';
        }

        return ['ok' => false, 'error' => $reason];
    }

    $data = json_decode((string) $response, true) ?: [];

    if ($code >= 400 || (isset($data['ok']) && $data['ok'] === false)) {
        return [
            'ok'    => false,
            'error' => 'HTTP ' . $code . ': ' . mb_substr((string) ($data['description'] ?? $data['message'] ?? $response), 0, 200),
        ];
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Собрать и отправить дайджест.
 *
 * @param bool $dry только показать текст, ничего не отправляя
 */
function digest_send(?string $only = null, bool $dry = false): array
{
    $report = [];

    foreach (array_keys(digest_channels()) as $channel) {
        if ($only !== null && $only !== $channel) {
            continue;
        }

        if (! digest_channel_ready($channel)) {
            $report[$channel] = ['ok' => false, 'count' => 0, 'message' => 'канал не настроен'];
            continue;
        }

        $items = digest_items($channel);

        // молчать лучше, чем присылать «сегодня сгорает: ничего»
        if (count($items) < 2) {
            $report[$channel] = ['ok' => true, 'count' => 0, 'message' => 'нечего отправлять'];
            continue;
        }

        $text = digest_text($items);

        if ($dry) {
            $report[$channel] = ['ok' => true, 'count' => count($items), 'message' => 'черновик', 'text' => $text];
            continue;
        }

        $result = digest_request($channel, $text);

        if ($result['ok']) {
            foreach ($items as $item) {
                insert('digest_sent', [
                    'thread_id'  => (int) $item['id'],
                    'channel'    => $channel,
                    'created_at' => now(),
                ]);
            }
        }

        $report[$channel] = [
            'ok'      => $result['ok'],
            'count'   => $result['ok'] ? count($items) : 0,
            'message' => $result['ok'] ? 'отправлено' : $result['error'],
            'text'    => $text,
        ];

        digest_log($channel . ': ' . $report[$channel]['message'] . ' (' . count($items) . ')');
    }

    return $report;
}

function digest_log(string $message): void
{
    $dir = __DIR__ . '/../data';

    if (is_dir($dir)) {
        @file_put_contents($dir . '/digest.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

/** Последние строки журнала — для панели управления. */
function digest_log_tail(int $lines = 20): string
{
    $path = __DIR__ . '/../data/digest.log';

    if (! is_readable($path)) {
        return '';
    }

    $all = explode("\n", (string) file_get_contents($path));

    return trim(implode("\n", array_slice($all, -$lines)));
}

/* ------------------------------------------------- рассылка на почту */

/**
 * Письмо «скоро сгорает» подписчикам рекламной рассылки.
 *
 * Отдельно от служебных писем: согласие на рекламу человек даёт отдельной
 * галочкой, отзывается оно тоже отдельно, и отказ от рекламы не должен
 * отключать уведомления об ответах на его комментарии.
 *
 * Письма кладём в очередь, а не отправляем подряд: на сотне адресов
 * последовательная отправка упрётся в таймаут, а почтовый сервер решит,
 * что его заваливают.
 */
function digest_mail_send(bool $dry = false): array
{
    $items = digest_items('email');

    if (count($items) < 2) {
        return ['ok' => true, 'count' => 0, 'users' => 0, 'message' => 'нечего отправлять'];
    }

    $users = q("SELECT id, username, email, unsubscribe_token FROM users
                WHERE ads_subscribed = 1 AND is_banned = 0 AND email <> ''");

    if ($dry) {
        return [
            'ok'      => true,
            'count'   => count($items),
            'users'   => count($users),
            'message' => 'черновик',
            'text'    => digest_mail_preview($items),
        ];
    }

    $subject = digest_mail_subject($items);

    foreach ($users as $user) {
        send_mail($user['email'], $subject, $subject, digest_mail_blocks($items, (string) $user['unsubscribe_token']));
    }

    foreach ($items as $item) {
        insert('digest_sent', [
            'thread_id'  => (int) $item['id'],
            'channel'    => 'email',
            'created_at' => now(),
        ]);
    }

    digest_log('email: поставлено в очередь писем — ' . count($users) . ', находок — ' . count($items));

    return ['ok' => true, 'count' => count($items), 'users' => count($users), 'message' => 'в очереди'];
}

/**
 * Тема письма.
 *
 * Считаем самый горящий срок и пишем его прямо в тему: «осталось 6 часов»
 * в списке писем работает лучше, чем «подборка скидок» — человек видит,
 * что открывать нужно сейчас, а не вечером.
 */
function digest_mail_subject(array $items): string
{
    $soonest = min(array_map(static fn (array $i): int => strtotime($i['expires_at']), $items));
    $hours   = max(1, (int) floor(($soonest - time()) / 3600));

    $lead = $items[0];
    $name = $lead['merchant_name'] ?: 'находка';

    return 'Последний шанс: ' . $name . ' — осталось '
        . $hours . ' ' . plural($hours, 'час', 'часа', 'часов');
}

/** Блоки письма для send_mail(). */
function digest_mail_blocks(array $items, string $token): array
{
    $blocks = ['Эти находки скоро сгорают — успейте забрать:'];

    foreach ($items as $item) {
        $line = $item['title'];

        if ($item['price'] !== null) {
            $line .= ' — ' . money((float) $item['price']);
        } elseif ((int) $item['discount_percent'] > 0) {
            $line .= ' — скидка ' . (int) $item['discount_percent'] . '%';
        }

        $meta = array_filter([$item['merchant_name'], digest_deadline($item['expires_at'])]);

        $blocks[] = $line . ' (' . implode(', ', $meta) . '): '
            . site_url() . thread_url($item);
    }

    $blocks[] = ['button' => ['text' => 'Смотреть всё, что сгорает', 'url' => site_url() . url('/?tab=expiring')]];

    // рекламное письмо обязано объяснять, откуда оно и как отписаться
    $blocks[] = ['small' => 'Вы получили это письмо, потому что согласились на рекламную рассылку '
        . 'при регистрации. Отписаться: ' . site_url() . url('/otpiska-reklama/' . $token)];

    return $blocks;
}

/** Черновик письма для панели — как текст, чтобы было видно, что уйдёт. */
function digest_mail_preview(array $items): string
{
    $lines = [digest_mail_subject($items), ''];

    foreach (digest_mail_blocks($items, 'ТОКЕН') as $block) {
        if (is_string($block)) {
            $lines[] = $block;
        } elseif (isset($block['button'])) {
            $lines[] = '[ ' . $block['button']['text'] . ' ] ' . $block['button']['url'];
        } elseif (isset($block['small'])) {
            $lines[] = $block['small'];
        }
    }

    return implode("\n", $lines);
}
