<?php
/**
 * Отправка писем.
 *
 * Письмо собирается в три слоя:
 *   multipart/related          — оболочка, чтобы картинка жила внутри письма
 *     multipart/alternative    — две версии текста
 *       text/plain             — для клиентов без HTML и для антиспам-фильтров
 *       text/html              — оформленная версия
 *     image/png                — логотип, встроен как вложение (cid)
 *
 * Логотип именно вложением, а не ссылкой: почтовые клиенты по умолчанию
 * блокируют картинки с чужих серверов, и шапка письма осталась бы пустой.
 */

function site_url(): string
{
    $configured = setting('site_url', '');

    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function mail_from(): array
{
    $host = preg_replace('/^www\./', '', parse_url(site_url(), PHP_URL_HOST) ?: 'localhost');

    return [
        'address' => setting('mail_from', 'noreply@' . $host),
        'name'    => setting('site_name', cfg('app_name')),
    ];
}

/** Кодирование заголовка: без него кириллица в теме превращается в кашу. */
function mail_encode(string $text): string
{
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * Оформленное письмо. $blocks — массив абзацев; элемент вида
 * ['button' => ['text' => '...', 'url' => '...']] превращается в кнопку.
 */
function mail_template(string $heading, array $blocks): array
{
    $site = setting('site_name', cfg('app_name'));
    $url  = site_url();

    $html = [];
    $text = [];

    foreach ($blocks as $block) {
        if (is_array($block) && isset($block['button'])) {
            $html[] = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0">'
                . '<tr><td style="border-radius:10px;background:#f5661e">'
                . '<a href="' . e($block['button']['url']) . '" style="display:inline-block;padding:14px 30px;'
                . 'font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:15px;font-weight:700;'
                . 'color:#ffffff;text-decoration:none;border-radius:10px">'
                . e($block['button']['text']) . '</a></td></tr></table>';

            $text[] = $block['button']['text'] . ': ' . $block['button']['url'];
            continue;
        }

        if (is_array($block) && isset($block['small'])) {
            $html[] = '<p style="margin:0 0 14px;font-size:13px;line-height:1.6;color:#8a919b">'
                . nl2br(e($block['small'])) . '</p>';
            $text[] = $block['small'];
            continue;
        }

        $html[] = '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;color:#1c1f24">'
            . nl2br(e((string) $block)) . '</p>';
        $text[] = (string) $block;
    }

    $bodyHtml = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($heading) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f1f3f6">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f3f6;padding:28px 12px">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden">'

        // шапка с логотипом
        . '<tr><td style="padding:26px 32px 0" align="left">'
        . '<img src="cid:sitelogo" alt="' . e($site) . '" width="180" style="display:block;border:0;height:auto">'
        . '</td></tr>'

        // заголовок и текст
        . '<tr><td style="padding:22px 32px 28px;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif">'
        . '<h1 style="margin:0 0 18px;font-size:21px;line-height:1.3;color:#1c1f24">' . e($heading) . '</h1>'
        . implode('', $html)
        . '</td></tr>'

        // подвал
        . '<tr><td style="padding:18px 32px 26px;border-top:1px solid #e3e6eb;'
        . 'font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:12px;line-height:1.6;color:#8a919b">'
        . '<a href="' . e($url) . '" style="color:#f5661e;text-decoration:none">' . e($site) . '</a>'
        . ' — сообщество охотников за скидками.<br>'
        . 'Письмо отправлено автоматически, отвечать на него не нужно.'
        . '</td></tr>'

        . '</table></td></tr></table></body></html>';

    $bodyText = $heading . "\n\n" . implode("\n\n", $text) . "\n\n—\n" . $site . "\n" . $url . "\n";

    return ['html' => $bodyHtml, 'text' => $bodyText];
}

/** Собрать MIME и отправить. Возвращает результат mail(). */
/**
 * Поставить письмо в очередь.
 *
 * Отправка через mail() упирается в почтовый сервер и занимает секунды —
 * всё это время человек ждёт ответа на свой комментарий. Поэтому письмо
 * складывается в очередь, а уходит уже после того, как страница отдана.
 */
function queue_mail(string $to, string $subject, string $heading, array $blocks): bool
{
    if (setting('mail_queue', '1') !== '1') {
        return send_mail_now($to, $subject, $heading, $blocks);
    }

    insert('mail_queue', [
        'recipient'  => mb_substr($to, 0, 190),
        'subject'    => mb_substr($subject, 0, 250),
        'heading'    => mb_substr($heading, 0, 250),
        'blocks'     => json_encode($blocks, JSON_UNESCAPED_UNICODE),
        'attempts'   => 0,
        'created_at' => now(),
    ]);

    return true;
}

/**
 * Разобрать очередь. Вызывается после отдачи страницы и из планировщика.
 * Три неудачные попытки — письмо больше не берём, чтобы очередь
 * не крутилась вхолостую на несуществующем адресе.
 */
function mail_queue_process(int $limit = 5): int
{
    $rows = q('SELECT * FROM mail_queue WHERE sent_at IS NULL AND attempts < 3
               ORDER BY id LIMIT ' . max(1, $limit));

    $sent = 0;

    foreach ($rows as $row) {
        qx('UPDATE mail_queue SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);

        $blocks = json_decode((string) $row['blocks'], true);

        if (! is_array($blocks)) {
            qx('UPDATE mail_queue SET sent_at = ? WHERE id = ?', [now(), $row['id']]);
            continue;
        }

        if (send_mail_now($row['recipient'], $row['subject'], $row['heading'], $blocks)) {
            qx('UPDATE mail_queue SET sent_at = ? WHERE id = ?', [now(), $row['id']]);
            $sent++;
        }
    }

    // отправленные держим неделю — на случай разбора жалоб, потом чистим
    qx('DELETE FROM mail_queue WHERE sent_at IS NOT NULL AND sent_at < ?',
        [date('Y-m-d H:i:s', time() - 7 * 86400)]);

    return $sent;
}

function send_mail_now(string $to, string $subject, string $heading, array $blocks): bool
{
    $body = mail_template($heading, $blocks);
    $from = mail_from();

    $logo     = __DIR__ . '/../public/img/email-logo.png';
    $hasLogo  = is_readable($logo);
    $boundary = 'b' . bin2hex(random_bytes(12));
    $altBound = 'a' . bin2hex(random_bytes(12));

    $headers = [
        'From: ' . mail_encode($from['name']) . ' <' . $from['address'] . '>',
        'Reply-To: ' . $from['address'],
        'MIME-Version: 1.0',
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . (parse_url(site_url(), PHP_URL_HOST) ?: 'localhost') . '>',
        'X-Mailer: PROMIKI',
        'Auto-Submitted: auto-generated',
    ];

    $headers[] = $hasLogo
        ? 'Content-Type: multipart/related; boundary="' . $boundary . '"'
        : 'Content-Type: multipart/alternative; boundary="' . $altBound . '"';

    $alternative = "--{$altBound}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body['text'])) . "\r\n"
        . "--{$altBound}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body['html'])) . "\r\n"
        . "--{$altBound}--\r\n";

    if (! $hasLogo) {
        return mail_send_raw($to, mail_encode($subject), $alternative, $headers);
    }

    $message = "--{$boundary}\r\n"
        . "Content-Type: multipart/alternative; boundary=\"{$altBound}\"\r\n\r\n"
        . $alternative
        . "--{$boundary}\r\n"
        . "Content-Type: image/png; name=\"logo.png\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "Content-ID: <sitelogo>\r\n"
        . "Content-Disposition: inline; filename=\"logo.png\"\r\n\r\n"
        . chunk_split(base64_encode(file_get_contents($logo))) . "\r\n"
        . "--{$boundary}--\r\n";

    return mail_send_raw($to, mail_encode($subject), $message, $headers);
}

/**
 * Единственное место, где письмо уходит наружу.
 *
 * mail() на виртуальном хостинге отдаёт письмо локальному sendmail, и дальше
 * всё зависит от того, как настроен сервер: часто письмо уходит с адреса
 * вида user1248542@sXX.hosting и Яндекс с Gmail его молча выбрасывают —
 * функция при этом честно вернула true. Поэтому при заполненных настройках
 * SMTP отправляем сами, через ящик домена: тогда письмо идёт от реального
 * адреса, проходит SPF и DKIM, а об отказе мы узнаём сразу, из ответа сервера.
 *
 * @param array<int,string> $headers уже собранные заголовки письма
 */
function mail_send_raw(string $to, string $subjectEncoded, string $message, array $headers): bool
{
    if (smtp_configured()) {
        return smtp_send($to, $subjectEncoded, $message, $headers);
    }

    return @mail($to, $subjectEncoded, $message, implode("\r\n", $headers));
}

function smtp_configured(): bool
{
    return setting('smtp_host', '') !== '' && setting('smtp_user', '') !== '';
}

/**
 * Отправка через SMTP-ящик домена.
 *
 * Минимальный клиент: EHLO, при необходимости STARTTLS, AUTH LOGIN, письмо.
 * Библиотеку не тянем — здесь нужны пять команд, а зависимость пришлось бы
 * обновлять и возить вместе с сайтом.
 */
function smtp_send(string $to, string $subjectEncoded, string $message, array $headers): bool
{
    $host   = setting('smtp_host', '');
    $port   = (int) setting('smtp_port', '465');
    $user   = setting('smtp_user', '');
    $pass   = setting('smtp_pass', '');
    $secure = setting('smtp_secure', 'ssl');          // ssl | tls | none
    $from   = mail_from()['address'];

    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . ($port ?: 465);

    /*
     * Имя для проверки сертификата задаём явно: сертификат выписан на домен,
     * и подключение к другому имени того же сервера обязано падать —
     * иначе проверка ничего не значит.
     */
    $context = stream_context_create(['ssl' => [
        'peer_name'       => $host,
        'verify_peer'     => true,
        'verify_peer_name' => true,
        'SNI_enabled'     => true,
    ]]);

    /*
     * Ошибку TLS собираем обработчиком, а не глушим собакой: при провале
     * проверки сертификата $errstr приходит пустым, и в журнале оставалась
     * строка «не удалось подключиться» без причины. Настоящий текст
     * («certificate verify failed», «name mismatch») приходит предупреждением.
     */
    $warnings = [];

    set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
        $warnings[] = trim(preg_replace('/^stream_socket_client\(\)[:]?\s*/', '', $message));

        return true;
    });

    $socket = stream_socket_client($target, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);

    restore_error_handler();

    if (! $socket) {
        $reason = $errstr !== '' ? $errstr : 'причина не указана';

        // предупреждений бывает несколько подряд — в журнал кладём последние
        if ($warnings) {
            $reason .= ' | ' . implode(' | ', array_slice(array_unique($warnings), -3));
        }

        mail_log('SMTP: не удалось подключиться к ' . $target . ' — ' . $reason);

        return false;
    }

    stream_set_timeout($socket, 20);

    // Ответ сервера: код в начале строки, у многострочных ответов
    // у всех строк кроме последней после кода стоит дефис.
    $read = static function () use ($socket): string {
        $out = '';

        while (($line = fgets($socket, 1024)) !== false) {
            $out .= $line;

            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        return $out;
    };

    /*
     * $label — то, что попадёт в журнал при отказе. Саму команду писать
     * нельзя: логин и пароль уходят на сервер отдельными строками в base64,
     * и в журнале оказался бы пароль в читаемом виде.
     */
    $say = static function (string $command, string $expect, string $label) use ($socket, $read): bool {
        if ($command !== '') {
            fwrite($socket, $command . "\r\n");
        }

        $answer = $read();

        if (! str_starts_with(trim($answer), $expect)) {
            mail_log('SMTP: ' . $label . ' → ' . trim($answer));

            return false;
        }

        return true;
    };

    $ehlo = 'EHLO ' . (parse_url(site_url(), PHP_URL_HOST) ?: 'localhost');

    $ok = $say('', '220', 'приветствие сервера')
        && $say($ehlo, '250', 'EHLO');

    if ($ok && $secure === 'tls') {
        $ok = $say('STARTTLS', '220', 'STARTTLS');

        if ($ok) {
            // тот же приём: причина отказа TLS приходит предупреждением
            $warnings = [];

            set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
                $warnings[] = trim($message);

                return true;
            });

            $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            restore_error_handler();

            if (! $crypto) {
                mail_log('SMTP: STARTTLS не прошёл — '
                    . ($warnings ? implode(' | ', array_slice(array_unique($warnings), -3)) : 'причина не указана'));
            }

            $ok = $crypto && $say($ehlo, '250', 'EHLO после STARTTLS');
        }
    }

    $ok = $ok
        && $say('AUTH LOGIN', '334', 'запрос авторизации')
        && $say(base64_encode($user), '334', 'логин')
        && $say(base64_encode($pass), '235', 'пароль — сервер не принял ящик или пароль')
        && $say('MAIL FROM:<' . $from . '>', '250', 'MAIL FROM ' . $from)
        && $say('RCPT TO:<' . $to . '>', '250', 'RCPT TO ' . $to)
        && $say('DATA', '354', 'DATA');

    if ($ok) {
        $data = 'To: ' . $to . "\r\n"
            . 'Subject: ' . $subjectEncoded . "\r\n"
            . implode("\r\n", $headers) . "\r\n\r\n"
            . $message;

        // точка в начале строки — конец письма для SMTP, поэтому удваиваем
        $data = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $data)));

        fwrite($socket, $data . "\r\n.\r\n");

        $ok = str_starts_with(trim($read()), '250');

        if (! $ok) {
            mail_log('SMTP: сервер не принял письмо для ' . $to);
        }
    }

    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);

    return $ok;
}

/** Совместимость: везде по коду письма отправляются через send_mail(). */
function send_mail(string $to, string $subject, string $heading, array $blocks): bool
{
    return queue_mail($to, $subject, $heading, $blocks);
}

/**
 * Разобрать очередь после того, как ответ ушёл в браузер.
 *
 * На PHP-FPM есть fastcgi_finish_request(): он закрывает соединение,
 * и дальше скрипт работает уже без ожидающего человека. На обычном
 * mod_php его нет — тогда просто выталкиваем буфер, страница уже
 * отрисована, а письма уходят следом.
 */
function mail_queue_flush(): void
{
    if (setting('mail_queue', '1') !== '1') {
        return;
    }

    ignore_user_abort(true);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        flush();
    }

    try {
        mail_queue_process(5);
    } catch (Throwable $e) {
        // страница уже отдана: падать тут некуда и незачем
        mail_log('очередь: ' . $e->getMessage());
    }
}

function mail_log(string $message): void
{
    $dir = __DIR__ . '/../data';

    if (is_dir($dir)) {
        @file_put_contents($dir . '/mail.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
