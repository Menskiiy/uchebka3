<?php
/**
 * Запуск бота из планировщика.
 *
 * Пример задачи в ISPmanager (раз в сутки):
 *   /usr/bin/php /var/www/user1248542/data/www/hothunt.ru/bin/steam-bot.php
 *
 * Из браузера файл недоступен: он лежит вне public.
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
require $src . '/temperature.php';
require $src . '/bot.php';

db();
db_migrate();
apply_settings();

// заодно разбираем накопившиеся письма: если на сайт давно не заходили,
// очередь могла остаться неразобранной
$mails = mail_queue_process(30);

$result = bot_import_steam();

echo date('Y-m-d H:i:s') . ' ' . $result['message'] . ' Писем отправлено: ' . $mails . PHP_EOL;

exit($result['errors'] > 0 && $result['added'] === 0 ? 1 : 0);
