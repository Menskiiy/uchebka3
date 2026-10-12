<?php
/** Сессии, вход, регистрация, права. */

function auth(): ?array
{
    static $user = null;
    static $loaded = false;

    if ($loaded) {
        return $user;
    }

    $loaded = true;

    // сессия могла не пережить уборку на хостинге — пробуем долгую метку
    if (empty($_SESSION['user_id']) && ! empty($_COOKIE[REMEMBER_COOKIE])) {
        remember_restore();
    }

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $user = q1('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$_SESSION['user_id']]);

    if ($user === null) {
        unset($_SESSION['user_id']);
    }

    return $user;
}

function auth_id(): ?int
{
    $user = auth();

    return $user ? (int) $user['id'] : null;
}

/**
 * Редактор — урезанная роль: пускаем в панель, но только в «Магазины».
 * Нужна, чтобы наполнять справочник чужими руками, не раздавая
 * доступ к жалобам, пользователям и настройкам.
 */
function is_editor(): bool
{
    $user = auth();

    return $user !== null && (int) ($user['is_editor'] ?? 0) === 1;
}

/** Доступ в панель вообще — у модератора и у редактора. */
/**
 * Технический аккаунт: публикует бот, живого владельца у него нет.
 *
 * Пароль такому аккаунту генерируется случайным и нигде не сохраняется,
 * войти под ним нельзя — поэтому его профиль правится из панели.
 */
function is_bot_account(array $user): bool
{
    return (int) ($user['is_bot'] ?? 0) === 1;
}

function can_open_admin(): bool
{
    return is_moderator() || is_editor();
}

function is_moderator(): bool
{
    $user = auth();

    return $user !== null && (int) $user['is_moderator'] === 1;
}

/** Активная блокировка: флаг стоит и срок ещё не вышел. */
function is_banned(?array $user): bool
{
    if (! $user || (int) $user['is_banned'] !== 1) {
        return false;
    }

    return empty($user['banned_until']) || strtotime($user['banned_until']) > time();
}

function require_auth(): array
{
    $user = auth();

    if (! $user) {
        flash('Войдите, чтобы продолжить.', 'error');
        redirect('/vhod');
    }

    if (is_banned($user)) {
        flash('Ваш аккаунт заблокирован.', 'error');
        redirect('/');
    }

    return $user;
}

function require_moderator(): array
{
    $user = require_auth();

    if (! is_moderator()) {
        http_response_code(403);
        exit('Доступ только для модераторов.');
    }

    return $user;
}

const REMEMBER_COOKIE = 'promiki_remember';

function login_user(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    update('users', $id, ['last_seen_at' => now()]);

    remember_issue($id);
}

/**
 * Выдать долгую метку входа.
 *
 * В куке лежит «метка:секрет», в базе — только хеш секрета. Утечка базы
 * не даёт войти: по хешу секрет не восстановить. Метка нужна, чтобы найти
 * запись одним запросом, не перебирая все.
 */
function remember_issue(int $userId): void
{
    if (headers_sent()) {
        return;
    }

    $selector  = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));

    insert('remember_tokens', [
        'user_id'    => $userId,
        'selector'   => $selector,
        'validator'  => hash('sha256', $validator),
        'expires_at' => date('Y-m-d H:i:s', time() + SESSION_LIFETIME),
        'created_at' => now(),
    ]);

    // подчищаем протухшее, чтобы таблица не росла бесконечно
    qx('DELETE FROM remember_tokens WHERE expires_at < ?', [now()]);

    remember_cookie($selector . ':' . $validator, time() + SESSION_LIFETIME);
}

function remember_cookie(string $value, int $expires): void
{
    $https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Восстановить вход по метке, если сессии больше нет.
 *
 * Метка одноразовая: после успешной проверки старая удаляется и выдаётся
 * новая. Перехваченную куку это обесценивает — ею можно воспользоваться
 * только до ближайшего захода владельца.
 */
function remember_restore(): ?int
{
    $raw = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');

    if (! str_contains($raw, ':')) {
        return null;
    }

    [$selector, $validator] = explode(':', $raw, 2);

    $row = q1('SELECT * FROM remember_tokens WHERE selector = ?', [$selector]);

    if (! $row || strtotime($row['expires_at']) < time()) {
        remember_cookie('', time() - 3600);

        return null;
    }

    // сравнение с постоянным временем: иначе по скорости ответа можно
    // подбирать секрет посимвольно
    if (! hash_equals((string) $row['validator'], hash('sha256', $validator))) {
        // чужая попытка по нашей метке — гасим всю запись
        qx('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
        remember_cookie('', time() - 3600);

        return null;
    }

    qx('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);

    $userId = (int) $row['user_id'];
    $user   = q1('SELECT id, is_banned FROM users WHERE id = ?', [$userId]);

    if (! $user || (int) $user['is_banned'] === 1) {
        remember_cookie('', time() - 3600);

        return null;
    }

    $_SESSION['user_id'] = $userId;
    remember_issue($userId);

    return $userId;
}

function logout_user(): void
{
    // метку тоже гасим: иначе следующий заход снова поднимет вход
    if (! empty($_COOKIE[REMEMBER_COOKIE])) {
        $selector = explode(':', (string) $_COOKIE[REMEMBER_COOKIE])[0];

        qx('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
        remember_cookie('', time() - 3600);
    }

    $_SESSION = [];

    // Порядок важен. Сначала пересоздаём идентификатор с удалением старого
    // файла сессии, и только потом гасим куку: regenerate сам шлёт Set-Cookie
    // и затёр бы наше удаление, сделай мы наоборот.
    //
    // session_destroy() здесь не подходит: после него любое обращение
    // к $_SESSION поднимает предупреждение, а наш перехватчик ошибок
    // превращает предупреждения в исключения — выход падал бы с ошибкой.
    session_regenerate_id(true);

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'] ?: '/',
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
}

function initials(array $user): string
{
    return mb_strtoupper(mb_substr($user['username'], 0, 1));
}

/** Вес голоса растёт с репутацией — простейший барьер против накруток. */
function vote_weight(array $user): int
{
    $weight = cfg('temperature.vote_base')
        + intdiv(max(0, (int) $user['reputation']), cfg('temperature.vote_reputation_divisor'));

    return (int) min($weight, cfg('temperature.vote_max'));
}

function add_reputation(int $userId, int $amount): void
{
    qx('UPDATE users SET reputation = reputation + ? WHERE id = ?', [$amount, $userId]);
}

/* ------------------------------------------------- восстановление пароля */

/**
 * Создать запрос на сброс. В базе хранится только хеш токена —
 * если базу утащат, по ней нельзя будет сбросить чужие пароли.
 * Возвращает сам токен для письма или null, если такого адреса нет.
 */
function password_reset_create(string $email): ?string
{
    $user = q1('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);

    if (! $user) {
        return null;
    }

    // старые запросы этого пользователя больше не нужны
    qx('DELETE FROM password_resets WHERE user_id = ?', [$user['id']]);

    $token = bin2hex(random_bytes(32));

    insert('password_resets', [
        'user_id'    => $user['id'],
        'token_hash' => hash('sha256', $token),
        'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        'created_at' => now(),
    ]);

    return $token;
}

/** @return array<string,mixed>|null пользователь, если токен годный */
function password_reset_user(string $token): ?array
{
    $row = q1('SELECT * FROM password_resets WHERE token_hash = ? AND expires_at > ?',
        [hash('sha256', $token), now()]);

    if (! $row) {
        return null;
    }

    return q1('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
}

function password_reset_apply(string $token, string $password): bool
{
    $user = password_reset_user($token);

    if (! $user) {
        return false;
    }

    update('users', (int) $user['id'], ['password' => password_hash($password, PASSWORD_DEFAULT)]);
    qx('DELETE FROM password_resets WHERE user_id = ?', [$user['id']]);

    return true;
}

/** Письмо со ссылкой на смену пароля. */
function password_reset_send(string $email, string $token): bool
{
    $site = setting('site_name', cfg('app_name'));
    $link = site_url() . url('/vosstanovlenie/' . $token);

    return send_mail(
        $email,
        'Восстановление пароля — ' . $site,
        'Восстановление пароля',
        [
            'Кто-то запросил смену пароля на сайте ' . $site . '. Если это были вы, нажмите кнопку ниже.',
            ['button' => ['text' => 'Задать новый пароль', 'url' => $link]],
            ['small' => 'Ссылка действует один час и сработает только один раз.'],
            ['small' => 'Если вы не запрашивали восстановление, просто удалите это письмо — пароль останется прежним.'],
            ['small' => 'Кнопка не нажимается? Скопируйте адрес в браузер: ' . $link],
        ]
    );
}
