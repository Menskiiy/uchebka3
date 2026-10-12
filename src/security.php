<?php
/**
 * Настройки сессии, защита от перебора и перехват фатальных ошибок.
 */

/**
 * Куку сессии закрываем от скриптов и чужих доменов.
 * Вызывается ДО session_start(), иначе настройки не применятся.
 */
/** Два месяца: столько живут кука сессии и запись «запомнить меня». */
const SESSION_LIFETIME = 5184000;

function secure_session(): void
{
    $https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    /*
     * Раньше кука жила до закрытия браузера, а файлы сессий лежали в общей
     * папке хостинга. Там их чистит сборщик мусора — свой у каждого сайта
     * на сервере, и настройки берутся от того, кто запустил уборку первым.
     * Поэтому люди «сами» выходили из аккаунта: файл сессии удаляли соседи.
     *
     * Лечим двумя способами: кука живёт два месяца, а файлы сессий храним
     * у себя в data/sessions, куда чужая уборка не дотянется.
     */
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,     // не уходит по незашифрованному соединению
        'httponly' => true,       // недоступна из JavaScript
        'samesite' => 'Lax',      // не отправляется на чужие сайты
    ]);

    $store = __DIR__ . '/../data/sessions';

    if (! is_dir($store)) {
        @mkdir($store, 0700, true);
    }

    if (is_dir($store) && is_writable($store)) {
        ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '200');
        session_save_path($store);
    }

    session_name('promiki_session');

    /*
     * PHP по умолчанию шлёт Cache-Control: no-store. Этот заголовок
     * выключает bfcache: «Назад» с публикации перезагружал ленту с нуля,
     * и человек терял и прокрутку, и догруженные страницы.
     *
     * Свои заголовки делают то же самое по безопасности (страница не ляжет
     * в общий кеш и всегда проверяется на сервере), но кеш «назад/вперёд»
     * оставляют работать. Ставим их до session_start(), иначе PHP успеет
     * отправить собственные.
     */
    session_cache_limiter('');

    if (! headers_sent()) {
        header('Cache-Control: private, no-cache, must-revalidate');
        header('Vary: Cookie');
    }

    session_start();

    /*
     * Продлеваем куку на каждом заходе: иначе через два месяца после входа
     * человека выкинет, даже если он заходил вчера.
     */
    if (! headers_sent() && isset($_COOKIE[session_name()])) {
        $params = session_get_cookie_params();

        setcookie(session_name(), session_id(), [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // раз в полчаса меняем идентификатор: даже перехваченная кука быстро протухнет
    if (empty($_SESSION['rotated_at'])) {
        $_SESSION['rotated_at'] = time();
    } elseif (time() - $_SESSION['rotated_at'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['rotated_at'] = time();
    }
}

/* ------------------------------------------------- защита от перебора */

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW       = 900;   // 15 минут

function login_blocked(): bool
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW);

    return (int) qv('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?',
        [client_ip(), $since]) >= LOGIN_MAX_ATTEMPTS;
}

function login_attempts_left(): int
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW);
    $used  = (int) qv('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?',
        [client_ip(), $since]);

    return max(0, LOGIN_MAX_ATTEMPTS - $used);
}

function login_failed(string $login): void
{
    insert('login_attempts', [
        'ip'         => client_ip(),
        'login'      => mb_substr($login, 0, 190),
        'created_at' => now(),
    ]);

    // старые записи не нужны и только копятся
    qx('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
}

function login_succeeded(): void
{
    qx('DELETE FROM login_attempts WHERE ip = ?', [client_ip()]);
}

/** Простое ограничение частоты действий на сессию: письма, жалобы и подобное. */
function throttle(string $key, int $seconds): bool
{
    $now  = time();
    $last = $_SESSION['throttle'][$key] ?? 0;

    if ($now - $last < $seconds) {
        return false;
    }

    $_SESSION['throttle'][$key] = $now;

    return true;
}

/* ------------------------------------------------- обработка ошибок */

/**
 * Вместо белого экрана — понятная страница, а подробности в журнал.
 * Показывать текст ошибки посетителю нельзя: в нём пути на сервере
 * и куски запросов.
 */
function register_error_handlers(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    set_exception_handler(function (Throwable $e) {
        log_failure($e);
        show_error_page();
    });

    set_error_handler(function ($severity, $message, $file, $line) {
        if (! (error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    register_shutdown_function(function () {
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            log_failure(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
            show_error_page();
        }
    });
}

function log_failure(Throwable $e): void
{
    $dir = __DIR__ . '/../data';

    if (! is_dir($dir)) {
        return;
    }

    $line = sprintf(
        "[%s] %s: %s в %s:%d%s  URL: %s%s",
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        PHP_EOL,
        $_SERVER['REQUEST_URI'] ?? 'cli',
        PHP_EOL . $e->getTraceAsString() . PHP_EOL . str_repeat('-', 60) . PHP_EOL
    );

    @file_put_contents($dir . '/errors.log', $line, FILE_APPEND | LOCK_EX);
}

function show_error_page(): void
{
    if (headers_sent()) {
        exit;
    }

    http_response_code(500);

    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Внутренняя ошибка. Попробуйте позже.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $file = __DIR__ . '/../views/error.php';

    if (is_readable($file)) {
        require $file;
    } else {
        echo 'Внутренняя ошибка сервера.';
    }

    exit;
}
