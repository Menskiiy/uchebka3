<?php
/**
 * Защита форм от роботов.
 *
 * Два режима. Свой, простой — работает сразу, ничего не грузит со стороны
 * и выглядит частью сайта. И Яндекс SmartCaptcha — включается, когда в
 * настройках появляются ключи; она сильнее против настоящего автоматизатора,
 * но тянет чужой скрипт.
 *
 * Свой режим не претендует на непробиваемость: он отсекает массовую
 * регистрацию скриптом, который просто постит форму. Против человека,
 * который сядет и напишет разбор конкретно под этот сайт, помогает только
 * SmartCaptcha — на этот случай она и предусмотрена.
 *
 * Важно для документов: в политике cookie SmartCaptcha уже описана как
 * используемый сервис, а сторонние счётчики — нет. Поэтому никаких других
 * виджетов здесь появляться не должно.
 */

const CAPTCHA_SESSION_KEY = 'captcha_answer';
const CAPTCHA_TTL         = 900;

/** Какой режим сейчас работает: smart, simple или off. */
function captcha_mode(): string
{
    if (setting('captcha_enabled', '1') !== '1') {
        return 'off';
    }

    return (setting('smartcaptcha_key', '') !== '' && setting('smartcaptcha_secret', '') !== '')
        ? 'smart'
        : 'simple';
}

/**
 * Задание для простой капчи.
 *
 * Считать нужно в уме, но ответ всегда однозначный: складываем два
 * небольших числа, записанных словами. Словами — чтобы задачу нельзя было
 * решить регулярным выражением в две строки.
 */
function captcha_question(): array
{
    $words = [
        1 => 'один', 2 => 'два', 3 => 'три', 4 => 'четыре', 5 => 'пять',
        6 => 'шесть', 7 => 'семь', 8 => 'восемь', 9 => 'девять',
    ];

    $a = random_int(1, 5);
    $b = random_int(1, 4);

    $_SESSION[CAPTCHA_SESSION_KEY] = ['answer' => $a + $b, 'time' => time()];

    return [
        'text'   => 'Сколько будет ' . $words[$a] . ' плюс ' . $words[$b] . '?',
        'answer' => $a + $b,
    ];
}

/** Разметка капчи для формы. */
function c_captcha(): string
{
    $mode = captcha_mode();

    if ($mode === 'off') {
        return '';
    }

    if ($mode === 'smart') {
        /*
         * Виджет подхватывает тему сайта: на тёмной теме светлая капча
         * выглядит как заплатка. Скрипт подключается только на страницах
         * с формами, а не на всём сайте.
         */
        return '<div class="field captcha">'
            . '<label>Проверка</label>'
            . '<div id="captcha-widget" class="smart-captcha"'
            . ' data-sitekey="' . e(setting('smartcaptcha_key', '')) . '"'
            . ' data-hl="ru"></div>'
            . '<script src="https://smartcaptcha.yandexcloud.net/captcha.js?render=onload" defer></script>'
            . '</div>';
    }

    $question = captcha_question();

    return '<div class="field captcha">'
        . '<label for="captcha">Проверка: ' . e($question['text']) . '</label>'
        . '<div class="captcha__row">'
        . '<input type="text" id="captcha" name="captcha" class="input captcha__input"'
        . ' inputmode="numeric" autocomplete="off" required placeholder="Ответ цифрой">'
        . '</div>'
        . '</div>';
}

/** Проверка ответа. Ответ одноразовый: повтор того же запроса не пройдёт. */
function captcha_check(): bool
{
    $mode = captcha_mode();

    if ($mode === 'off') {
        return true;
    }

    if ($mode === 'smart') {
        return captcha_check_smart((string) input('smart-token'));
    }

    $expected = $_SESSION[CAPTCHA_SESSION_KEY] ?? null;

    unset($_SESSION[CAPTCHA_SESSION_KEY]);

    if (! is_array($expected) || time() - (int) $expected['time'] > CAPTCHA_TTL) {
        return false;
    }

    return (string) input('captcha') !== ''
        && (int) input('captcha') === (int) $expected['answer'];
}

/** Проверка токена SmartCaptcha на стороне Яндекса. */
function captcha_check_smart(string $token): bool
{
    if ($token === '') {
        return false;
    }

    $url = 'https://smartcaptcha.yandexcloud.net/validate?' . http_build_query([
        'secret' => setting('smartcaptcha_secret', ''),
        'token'  => $token,
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);

        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);

        $response = curl_exec($ch);
        curl_close($ch);
    } else {
        $response = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 5, 'ignore_errors' => true],
        ]));
    }

    /*
     * Сервис не ответил — пропускаем. Иначе падение чужого сервиса
     * закрывает регистрацию на сайте наглухо, а это хуже, чем один
     * проскочивший робот.
     */
    if ($response === false) {
        captcha_log('SmartCaptcha не ответила — форма пропущена без проверки');

        return true;
    }

    $data = json_decode((string) $response, true) ?: [];

    return ($data['status'] ?? '') === 'ok';
}

function captcha_log(string $message): void
{
    $dir = __DIR__ . '/../data';

    if (is_dir($dir)) {
        @file_put_contents($dir . '/captcha.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
