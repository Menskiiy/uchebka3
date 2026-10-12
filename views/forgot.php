<div class="page">
    <div class="card" style="max-width:420px;margin:20px auto"><div class="card__body">
        <h1 style="font-size:22px;margin-top:0">Восстановление пароля</h1>

        <?php if ($errors): ?>
            <div class="alert alert--error"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <?php if (! empty($sent)): ?>
            <div class="alert">
                Если такой адрес зарегистрирован, письмо со ссылкой уже отправлено.
                Ссылка действует один час.
            </div>
            <p class="hint">Не пришло? Проверьте папку «Спам» — письма с новых доменов часто попадают туда.</p>
            <a href="<?= e(url('/vhod')) ?>" class="btn btn--block" style="margin-top:10px">Вернуться ко входу</a>
        <?php else: ?>
            <p class="hint" style="margin-top:0">
                Укажите адрес, на который зарегистрирован аккаунт — пришлём ссылку для смены пароля.
            </p>

            <form method="POST" action="<?= e(url('/vosstanovlenie')) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" class="input" required autofocus>
                </div>
                <button class="btn btn--primary btn--block"><?= icon('mail') ?> Отправить ссылку</button>
            </form>

            <p class="muted" style="margin-top:16px;text-align:center">
                <a href="<?= e(url('/vhod')) ?>">Вспомнил пароль</a>
            </p>
        <?php endif; ?>
    </div></div>
</div>
