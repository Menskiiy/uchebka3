<?php
/**
 * Дайджест «скоро сгорает» в каналы сайта.
 *
 * Задача в планировщике (раз в сутки, лучше вечером — когда люди
 * успевают воспользоваться находкой до конца срока):
 *   0 19 * * *  /usr/bin/php /var/www/user1248542/data/www/hothunt.ru/bin/digest.php
 *
 * Посмотреть текст, ничего не отправляя:
 *   php bin/digest.php --dry
 *
 * Только один канал:
 *   php bin/digest.php --only=telegram
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
require $src . '/legal.php';
require $src . '/digest.php';

db();
db_migrate();
apply_settings();

$dry  = in_array('--dry', $argv, true);
$only = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, 7);
    }
}

$report = digest_send($only, $dry);

// почта идёт тем же расписанием: список получателей свой, текст — тот же
if ($only === null || $only === 'email') {
    $report['email'] = digest_mail_send($dry);
}

$failed = 0;

foreach ($report as $channel => $result) {
    echo date('Y-m-d H:i:s') . ' ' . $channel . ': ' . $result['message']
        . ' (' . $result['count'] . ')' . PHP_EOL;

    if ($dry && ! empty($result['text'])) {
        echo PHP_EOL . $result['text'] . PHP_EOL . PHP_EOL;
    }

    if (! $result['ok']) {
        $failed++;
    }
}

// ненулевой код нужен планировщику: по нему видно, что задача падает
exit($failed > 0 ? 1 : 0);
