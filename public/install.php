<?php
/**
 * Мастер установки: выбор базы, создание таблиц, аккаунт администратора.
 * Открыть в браузере: /install.php
 *
 * ВАЖНО: удалите файл сразу после установки.
 */

declare(strict_types=1);


$src = __DIR__ . '/../src';

require $src . '/security.php';

register_error_handlers();
secure_session();

require $src . '/helpers.php';
require $src . '/sanitize.php';
require $src . '/icons.php';
require $src . '/db.php';
require $src . '/seed.php';
require $src . '/mail.php';
require $src . '/auth.php';

$localFile = __DIR__ . '/../data/config.local.php';
$errors    = [];
$step      = file_exists($localFile) ? 2 : 1;
$done      = false;

/* ------------------------------------------- шаг 1: подключение к базе */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'db') {
    $driver = ($_POST['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';

    $db = [
        'driver'  => $driver,
        'host'    => trim((string) ($_POST['host'] ?? 'localhost')),
        'port'    => (int) ($_POST['port'] ?? 3306) ?: 3306,
        'name'    => trim((string) ($_POST['name'] ?? '')),
        'user'    => trim((string) ($_POST['user'] ?? '')),
        'pass'    => (string) ($_POST['pass'] ?? ''),
        'charset' => 'utf8mb4',
    ];

    if ($driver === 'mysql') {
        if ($db['name'] === '' || $db['user'] === '') {
            $errors[] = 'Укажите имя базы и пользователя — их создают в панели хостинга.';
        }

        if (! $errors) {
            try {
                new PDO(
                    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
                    $db['user'],
                    $db['pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            } catch (PDOException $e) {
                $errors[] = 'Не удалось подключиться: ' . $e->getMessage();
            }
        }
    } else {
        $dir = dirname($localFile);

        if (! is_writable($dir)) {
            $errors[] = 'Папка data недоступна для записи. Поставьте права 775.';
        }
    }

    if (! $errors) {
        $php = "<?php\n\nreturn [\n    'db' => " . var_export($db, true) . ",\n];\n";

        if (@file_put_contents($localFile, $php) === false) {
            $errors[] = 'Не удалось сохранить настройки в data/config.local.php. Проверьте права на папку.';
        } else {
            header('Location: install.php');
            exit;
        }
    }
}

/* --------------------------------------- шаг 2: аккаунт администратора */

$hasAdmin = false;

if ($step === 2) {
    try {
        db();
        apply_settings();
        $hasAdmin = (int) qv('SELECT COUNT(*) FROM users WHERE is_moderator = 1') > 0;
    } catch (Throwable $e) {
        $errors[] = 'Ошибка базы: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'admin' && ! $hasAdmin) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (! preg_match('/^[A-Za-z0-9_-]{3,30}$/', $username)) {
        $errors[] = 'Логин: латиница, цифры, дефис и подчёркивание, от 3 до 30 символов.';
    }

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Укажите корректный e-mail.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Пароль должен быть не короче 8 символов.';
    }

    if (! $errors && qv('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?', [$username, $email]) > 0) {
        $errors[] = 'Такой пользователь уже существует.';
    }

    if (! $errors) {
        insert('users', [
            'username'     => $username,
            'email'        => $email,
            'password'     => password_hash($password, PASSWORD_DEFAULT),
            'reputation'   => 100,
            'is_moderator' => 1,
            'created_at'   => now(),
        ]);

        $done = true;
    }
}

$checks = [
    'PHP 8.1 или новее'      => version_compare(PHP_VERSION, '8.1', '>='),
    'Драйвер pdo_sqlite'     => extension_loaded('pdo_sqlite'),
    'Драйвер pdo_mysql'      => extension_loaded('pdo_mysql'),
    'Папка data пишется'     => is_writable(__DIR__ . '/../data'),
    'Папка storage пишется'  => is_dir(__DIR__ . '/storage') ? is_writable(__DIR__ . '/storage') : is_writable(__DIR__),
];
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Установка</title>
    <link rel="stylesheet" href="css/app.css">
</head>
<body>
<div class="container" style="max-width:600px;padding-top:36px;padding-bottom:60px">
    <h1 style="font-size:24px">Установка сайта</h1>
    <p class="muted">Шаг <?= $step ?> из 2 · <?= $step === 1 ? 'подключение к базе' : 'аккаунт администратора' ?></p>

    <div class="card"><div class="card__body">
        <h2 class="card__title">Проверка сервера</h2>
        <?php foreach ($checks as $label => $ok): ?>
            <div class="row" style="padding:5px 0">
                <span style="color:<?= $ok ? 'var(--green)' : '#c62828' ?>;font-weight:700"><?= $ok ? '✔' : '✘' ?></span>
                <span><?= e($label) ?></span>
            </div>
        <?php endforeach; ?>
        <p class="hint">PHP <?= e(PHP_VERSION) ?>. Достаточно одного драйвера базы — того, который выберете.</p>
    </div></div>

    <?php if ($errors): ?>
        <div class="alert alert--error"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($done): ?>
        <div class="card"><div class="card__body">
            <div class="alert">Готово. Аккаунт администратора создан.</div>
            <p><strong>Удалите файл install.php прямо сейчас.</strong> Пока он на сервере,
               посторонний сможет получить доступ к панели, если база опустеет.</p>
            <a href="admin" class="btn btn--primary btn--block">Войти в панель управления</a>
            <a href="./" class="btn btn--block" style="margin-top:8px">Открыть сайт</a>
        </div></div>

    <?php elseif ($step === 1): ?>
        <div class="card"><div class="card__body">
            <h2 class="card__title">База данных</h2>

            <form method="POST">
                <input type="hidden" name="step" value="db">

                <div class="field">
                    <label for="driver">Тип базы</label>
                    <select name="driver" id="driver" class="input" onchange="document.getElementById('mysql-fields').style.display = this.value === 'mysql' ? 'block' : 'none'">
                        <option value="sqlite">SQLite — файл, ничего настраивать не нужно</option>
                        <option value="mysql">MySQL — база создаётся в панели хостинга</option>
                    </select>
                    <div class="hint">
                        SQLite проще: база лежит одним файлом в data/. MySQL привычнее и лучше держит
                        нагрузку — базу и пользователя создайте в панели хостинга, затем впишите данные ниже.
                    </div>
                </div>

                <div id="mysql-fields" style="display:none">
                    <div class="grid-2">
                        <div class="field">
                            <label for="host">Сервер</label>
                            <input type="text" id="host" name="host" class="input" value="localhost">
                        </div>
                        <div class="field">
                            <label for="port">Порт</label>
                            <input type="number" id="port" name="port" class="input" value="3306">
                        </div>
                    </div>
                    <div class="field">
                        <label for="name">Имя базы</label>
                        <input type="text" id="name" name="name" class="input" placeholder="user1248542_promiki">
                    </div>
                    <div class="field">
                        <label for="user">Пользователь</label>
                        <input type="text" id="user" name="user" class="input">
                    </div>
                    <div class="field">
                        <label for="pass">Пароль</label>
                        <input type="text" id="pass" name="pass" class="input">
                    </div>
                </div>

                <button class="btn btn--primary btn--block">Проверить и продолжить</button>
            </form>
        </div></div>

    <?php elseif ($hasAdmin): ?>
        <div class="card"><div class="card__body">
            <div class="alert alert--error">Администратор уже создан — установка закрыта.</div>
            <a href="admin" class="btn btn--primary btn--block">Войти в панель</a>
        </div></div>

    <?php else: ?>
        <div class="card"><div class="card__body">
            <h2 class="card__title">Аккаунт администратора</h2>
            <p class="hint" style="margin-top:0">Таблицы и справочники уже созданы. Осталось завести доступ в панель.</p>

            <form method="POST">
                <input type="hidden" name="step" value="admin">
                <div class="field">
                    <label for="username">Логин</label>
                    <input type="text" id="username" name="username" class="input" value="<?= e($_POST['username'] ?? '') ?>" required autofocus>
                </div>
                <div class="field">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" class="input" value="<?= e($_POST['email'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label for="password">Пароль</label>
                    <input type="password" id="password" name="password" class="input" required>
                    <div class="hint">Минимум 8 символов.</div>
                </div>
                <button class="btn btn--primary btn--block">Создать администратора</button>
            </form>
        </div></div>
    <?php endif; ?>
</div>
</body>
</html>
