<?php
/**
 * Присмотр за сайтом.
 *
 * Задача в планировщике каждые 15 минут: в поле «минуты» ставим интервал,
 * команда — /usr/bin/php /путь/к/сайту/bin/watch.php
 *
 * Что делает: смотрит журнал ошибок и, если там появились новые записи,
 * шлёт письмо администратору. Отметку последнего просмотра хранит
 * в настройках, поэтому одна и та же ошибка не приходит дважды.
 *
 * Чего не делает: не заменяет внешний пинг. Если сайт лежит целиком,
 * этот скрипт тоже не запустится — о недоступности должен сообщать
 * сервис снаружи (UptimeRobot и подобные), а проверять ему нужно
 * адрес /health.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Только из командной строки.');
}

$src = __DIR__ . '/../src';

require $src . '/helpers.php';
require $src . '/sanitize.php';
require $src . '/icons.php';
require $src . '/db.php';
require $src . '/seed.php';
require $src . '/mail.php';
require $src . '/auth.php';
require $src . '/queries.php';

db();
apply_settings();

$log = __DIR__ . '/../data/errors.log';

if (! is_readable($log)) {
    echo date('Y-m-d H:i:s') . " журнал ошибок пуст\n";
    exit(0);
}

$size = (int) filesize($log);
$seen = (int) setting('watch_log_offset', '0');

/*
 * Журнал могли очистить из панели — тогда он стал короче отметки.
 * В этом случае читаем с начала, иначе пропустим свежие ошибки.
 */
if ($size < $seen) {
    $seen = 0;
}

if ($size === $seen) {
    echo date('Y-m-d H:i:s') . " новых ошибок нет\n";
    exit(0);
}

$handle = fopen($log, 'rb');
fseek($handle, $seen);
$fresh = (string) fread($handle, min($size - $seen, 200000));
fclose($handle);

setting_save('watch_log_offset', (string) $size);

$count = substr_count($fresh, '] ');

// в письмо кладём последние записи: полный журнал в письме читать невозможно
$tail = implode("\n", array_slice(explode("\n", trim($fresh)), -40));

$to = setting('admin_email', '') ?: setting('mail_from', '');

if ($to === '') {
    echo "Некому писать: укажите адрес администратора в настройках сайта.\n";
    exit(1);
}

send_mail(
    $to,
    'Ошибки на сайте: ' . $count . ' ' . plural($count, 'запись', 'записи', 'записей'),
    'Новые ошибки в журнале',
    [
        'В журнале ошибок появились новые записи. Последние из них:',
        $tail,
        ['button' => ['text' => 'Открыть панель', 'url' => site_url() . url('/admin/pochta')]],
    ]
);

mail_queue_process(5);

echo date('Y-m-d H:i:s') . " отправлено письмо о {$count} ошибках\n";

exit(0);
