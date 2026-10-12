<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход в панель управления</title>
    <link rel="stylesheet" href="<?= e(url('/css/app.css')) ?>?v=4">
</head>
<body class="admin-login">
    <div class="admin-login__box card">
        <div class="card__body">
            <div class="admin-login__logo">
                <img src="<?= e(url('/img/logo-fire.svg')) ?>" alt="<?= e(setting('site_name', cfg('app_name'))) ?>" class="logo__img logo__img--light">
                <img src="<?= e(url('/img/logo-fire-dark.svg')) ?>" alt="" class="logo__img logo__img--dark">
            </div>
            <p class="muted" style="margin:0 0 18px;font-size:13px">Панель управления сайтом</p>

            <?php if ($errors): ?>
                <div class="alert alert--error"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
            <?php endif; ?>

            <form method="POST" action="<?= e(url('/admin/vhod')) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="login">Логин или e-mail</label>
                    <input type="text" id="login" name="login" class="input" required autofocus>
                </div>
                <div class="field">
                    <label for="password">Пароль</label>
                    <input type="password" id="password" name="password" class="input" required>
                </div>
                <button class="btn btn--primary btn--block">Войти в панель</button>
            </form>

            <p class="muted" style="margin-top:16px;text-align:center;font-size:13px">
                <a href="<?= e(url('/')) ?>">Вернуться на сайт</a>
            </p>
        </div>
    </div>
</body>
</html>
