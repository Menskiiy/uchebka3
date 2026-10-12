<div class="page">
    <div class="card" style="max-width:420px;margin:20px auto"><div class="card__body">
        <h1 style="font-size:22px;margin-top:0">Войти</h1>

        <?php if ($errors): ?>
            <div class="alert alert--error"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= e(url('/vhod')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="login">Имя пользователя или e-mail</label>
                <input type="text" id="login" name="login" class="input" value="<?= e($old['login'] ?? '') ?>" required autofocus>
            </div>
            <div class="field">
                <label for="password">Пароль</label>
                <input type="password" id="password" name="password" class="input" required>
            </div>
            <?= c_captcha() ?>

            <button class="btn btn--primary btn--block">Войти</button>
        </form>


        <?php if (yandex_enabled()): ?>
            <div class="oauth-divider"><span>или</span></div>

            <a href="<?= e(url('/auth/yandex')) ?>" class="btn btn--block btn--yandex">
                <span class="btn--yandex__mark">Я</span> Войти с Яндекс ID
            </a>
        <?php endif; ?>
        <p class="hint" style="margin-top:16px">
            Пользуясь сайтом, вы соглашаетесь с <?= legal_link('terms', 'пользовательским соглашением') ?>
            и <?= legal_link('privacy', 'политикой обработки персональных данных') ?>.
            Все документы сайта собраны в разделе
            <a href="<?= e(url('/dokumenty')) ?>">«Документы»</a>.
        </p>

        <p class="muted" style="margin-top:16px;text-align:center">
            <a href="<?= e(url('/vosstanovlenie')) ?>">Забыли пароль?</a>
        </p>
        <p class="muted" style="margin-top:8px;text-align:center">
            Нет аккаунта? <a href="<?= e(url('/registraciya')) ?>">Создать</a>
        </p>
    </div></div>
</div>
