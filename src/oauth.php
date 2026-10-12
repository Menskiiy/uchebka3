<?php
/**
 * Вход через Яндекс ID.
 *
 * Схема обычная для OAuth: уводим человека на Яндекс, оттуда он
 * возвращается с одноразовым кодом, код меняем на токен, токеном
 * забираем профиль. Секрет живёт в настройках сайта, а не в коде —
 * поэтому его можно сменить без правки файлов.
 */

const YANDEX_AUTH  = 'https://oauth.yandex.ru/authorize';
const YANDEX_TOKEN = 'https://oauth.yandex.ru/token';
const YANDEX_INFO  = 'https://login.yandex.ru/info';

function yandex_enabled(): bool
{
    return setting('yandex_client_id', '') !== '' && setting('yandex_client_secret', '') !== '';
}

function yandex_redirect_uri(): string
{
    return site_url() . url('/auth/yandex/callback');
}

/* ----------------------------------------------------------- запросы */

/**
 * HTTP-запрос. Возвращает разобранный JSON или null.
 * Своя функция, а не общая с ботом: здесь нужны POST и заголовки.
 */
function oauth_request(string $url, array $post = [], array $headers = []): ?array
{
    $raw = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        ];

        if ($post) {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($post);
        }

        curl_setopt_array($ch, $options);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        curl_close($ch);

        if ($raw === false || $code >= 400) {
            oauth_log('запрос ' . $url . ' вернул ' . $code . ' ' . ($err ?: mb_substr((string) $raw, 0, 200)));

            return null;
        }
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create(['http' => [
            'method'        => $post ? 'POST' : 'GET',
            'header'        => implode("\r\n", array_merge(
                ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
                $headers
            )),
            'content'       => $post ? http_build_query($post) : null,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);

        $raw = @file_get_contents($url, false, $context);
    } else {
        oauth_log('на сервере нет ни cURL, ни allow_url_fopen');

        return null;
    }

    $data = json_decode((string) $raw, true);

    return is_array($data) ? $data : null;
}

function oauth_log(string $message): void
{
    $dir = __DIR__ . '/../data';

    if (is_dir($dir)) {
        @file_put_contents($dir . '/oauth.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

/* -------------------------------------------------------------- вход */

function page_yandex_start(): void
{
    if (! yandex_enabled()) {
        flash('Вход через Яндекс не настроен.', 'error');
        redirect('/vhod');
    }

    if (auth_id()) {
        redirect('/');
    }

    // state защищает от подмены ответа: чужой запрос без нашей метки не пройдёт
    $state = bin2hex(random_bytes(16));
    $_SESSION['yandex_state'] = $state;

    $url = YANDEX_AUTH . '?' . http_build_query([
        'response_type' => 'code',
        'client_id'     => setting('yandex_client_id'),
        'redirect_uri'  => yandex_redirect_uri(),
        'state'         => $state,
        /*
         * Всегда показываем экран подтверждения. С 'no' Яндекс молча
         * подставляет аккаунт, под которым человек уже вошёл у себя,
         * и сменить его невозможно — а у многих аккаунтов несколько.
         * Лишний клик дешевле, чем вход не под тем аккаунтом.
         */
        'force_confirm' => 'yes',
    ]);

    header('Location: ' . $url);
    exit;
}

function page_yandex_callback(): void
{
    if (! yandex_enabled()) {
        redirect('/vhod');
    }

    $expected = $_SESSION['yandex_state'] ?? '';
    unset($_SESSION['yandex_state']);

    if ($expected === '' || ! hash_equals($expected, (string) input('state'))) {
        flash('Не удалось подтвердить переход от Яндекса. Попробуйте ещё раз.', 'error');
        redirect('/vhod');
    }

    if (input('error')) {
        // человек нажал «Отказать» — это не ошибка, просто молча возвращаем
        redirect('/vhod');
    }

    $code = (string) input('code');

    if ($code === '') {
        redirect('/vhod');
    }

    $token = oauth_request(YANDEX_TOKEN, [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => setting('yandex_client_id'),
        'client_secret' => setting('yandex_client_secret'),
        'redirect_uri'  => yandex_redirect_uri(),
    ]);

    if (empty($token['access_token'])) {
        oauth_log('токен не получен: ' . json_encode($token, JSON_UNESCAPED_UNICODE));
        flash('Яндекс не выдал доступ. Подробности в журнале.', 'error');
        redirect('/vhod');
    }

    $profile = oauth_request(YANDEX_INFO . '?format=json', [], [
        'Authorization: OAuth ' . $token['access_token'],
    ]);

    if (empty($profile['id'])) {
        oauth_log('профиль не получен');
        flash('Не удалось получить данные профиля.', 'error');
        redirect('/vhod');
    }

    $user = yandex_find_or_create($profile);

    if (! $user) {
        redirect('/vhod');
    }

    if (is_banned($user)) {
        flash('Аккаунт заблокирован.', 'error');
        redirect('/vhod');
    }

    login_user((int) $user['id']);

    flash('Вы вошли через Яндекс.');
    redirect('/');
}

/**
 * Найти аккаунт или завести новый.
 *
 * Порядок важен: сначала по идентификатору Яндекса, потом по почте.
 * Второй шаг привязывает Яндекс к аккаунту, который человек завёл
 * раньше обычной регистрацией на тот же адрес.
 */
function yandex_find_or_create(array $profile): ?array
{
    $yandexId = (string) $profile['id'];
    $email    = trim((string) ($profile['default_email'] ?? ''));

    $user = q1('SELECT * FROM users WHERE yandex_id = ?', [$yandexId]);

    if ($user) {
        return $user;
    }

    if ($email !== '') {
        $user = q1('SELECT * FROM users WHERE email = ?', [$email]);

        if ($user) {
            update('users', (int) $user['id'], [
                'yandex_id'         => $yandexId,
                'email_verified_at' => $user['email_verified_at'] ?: now(),
            ]);

            return q1('SELECT * FROM users WHERE id = ?', [$user['id']]);
        }
    }

    if (setting('registration_open', '1') !== '1') {
        flash('Регистрация временно закрыта.', 'error');

        return null;
    }

    if ($email === '') {
        flash('Яндекс не передал адрес почты. Разрешите доступ к почте и повторите.', 'error');

        return null;
    }

    $id = insert('users', [
        'username'          => yandex_username($profile),
        'email'             => $email,
        'password'          => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'avatar'            => yandex_avatar($profile),
        'yandex_id'         => $yandexId,
        'email_verified_at' => now(),
        'unsubscribe_token' => bin2hex(random_bytes(16)),
        'created_at'        => now(),
    ]);

    return q1('SELECT * FROM users WHERE id = ?', [$id]);
}

/** Подобрать свободное имя: логин Яндекса может быть занят или содержать точки. */
function yandex_username(array $profile): string
{
    $base = (string) ($profile['login'] ?? '');
    $base = preg_replace('/[^A-Za-z0-9_-]/', '', $base);
    $base = mb_substr((string) $base, 0, 24);

    if (mb_strlen($base) < 3) {
        $base = 'user' . random_int(1000, 9999);
    }

    $name = $base;
    $i    = 1;

    while (qv('SELECT COUNT(*) FROM users WHERE username = ?', [$name]) > 0) {
        $name = mb_substr($base, 0, 24) . (++$i);
    }

    return $name;
}

/** Портрет из Яндекса. Храним ссылкой — файл лежит у них и не занимает наш диск. */
function yandex_avatar(array $profile): ?string
{
    if (! empty($profile['is_avatar_empty']) || empty($profile['default_avatar_id'])) {
        return null;
    }

    return 'https://avatars.yandex.net/get-yapic/' . $profile['default_avatar_id'] . '/islands-200';
}
