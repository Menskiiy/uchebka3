<?php
/**
 * Резервная копия базы и загруженных файлов.
 *
 * Задача в планировщике, раз в сутки:
 *   30 4 * * *  /usr/bin/php /путь/к/сайту/bin/backup.php
 *
 * Копии складываются в data/backups и хранятся две недели. Папка закрыта
 * от веба тем же .htaccess, что и остальная data.
 *
 * Важно: копия на том же сервере спасает от своей ошибки — снёс не ту
 * запись, уронил миграцию, — но не спасает от потери сервера. Раз в неделю
 * копию нужно забирать наружу: в панели хостинга или просто скачивая файл.
 * И хотя бы один раз её нужно развернуть: копия, которую не разворачивали,
 * копией не считается.
 *
 * Запуск вручную:
 *   php bin/backup.php            обычная копия
 *   php bin/backup.php --files    вместе с папкой storage (тяжелее, реже)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Только из командной строки.');
}

$src = __DIR__ . '/../src';

require $src . '/helpers.php';
require $src . '/db.php';

$config = require $src . '/config.php';
$db     = $config['db'] ?? [];

$dir = __DIR__ . '/../data/backups';

if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
    fwrite(STDERR, "Не удалось создать папку для копий: {$dir}\n");
    exit(1);
}

$stamp = date('Y-m-d_H-i');
$keep  = 14;          // дней хранения
$made  = [];

/* ------------------------------------------------------------------ база */

if (($db['driver'] ?? 'mysql') === 'sqlite') {
    $path = $db['path'] ?? __DIR__ . '/../data/promiki.sqlite';
    $out  = $dir . '/db_' . $stamp . '.sqlite.gz';

    // копируем через API sqlite, а не файлом: иначе можно поймать
    // базу в момент записи и получить битую копию
    $pdo  = new PDO('sqlite:' . $path);
    $temp = $dir . '/.tmp.sqlite';

    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $temp) . "'");

    file_put_contents($out, gzencode((string) file_get_contents($temp), 6));
    @unlink($temp);

    $made[] = $out;
} else {
    $out = $dir . '/db_' . $stamp . '.sql.gz';

    $command = sprintf(
        'mysqldump --single-transaction --quick --default-character-set=utf8mb4 -h%s -P%d -u%s -p%s %s 2>/dev/null | gzip -6 > %s',
        escapeshellarg($db['host'] ?? '127.0.0.1'),
        (int) ($db['port'] ?? 3306),
        escapeshellarg($db['user'] ?? ''),
        escapeshellarg($db['pass'] ?? ''),
        escapeshellarg($db['name'] ?? ''),
        escapeshellarg($out)
    );

    exec($command, $ignored, $code);

    if ($code !== 0 || ! is_file($out) || filesize($out) < 1024) {
        fwrite(STDERR, "mysqldump не отработал (код {$code}). Проверьте, доступна ли утилита на хостинге.\n");
        @unlink($out);
        exit(1);
    }

    $made[] = $out;
}

/* -------------------------------------------------------------- картинки */

if (in_array('--files', $argv, true)) {
    $storage = __DIR__ . '/../public/storage';
    $out     = $dir . '/storage_' . $stamp . '.tar.gz';

    if (is_dir($storage)) {
        exec(sprintf('tar -czf %s -C %s .', escapeshellarg($out), escapeshellarg($storage)), $ignored, $code);

        if ($code === 0) {
            $made[] = $out;
        } else {
            fwrite(STDERR, "Не удалось упаковать storage (код {$code}).\n");
        }
    }
}

/* ---------------------------------------------------------------- уборка */

$removed = 0;

foreach (glob($dir . '/*.gz') ?: [] as $file) {
    if (filemtime($file) < time() - $keep * 86400) {
        @unlink($file);
        $removed++;
    }
}

foreach ($made as $file) {
    echo date('Y-m-d H:i:s') . ' ' . basename($file) . ' — '
        . round(filesize($file) / 1024 / 1024, 2) . " МБ\n";
}

if ($removed) {
    echo "Удалено старых копий: {$removed}\n";
}

exit(0);
