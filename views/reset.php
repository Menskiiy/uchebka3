<div class="page">
    <div class="card" style="max-width:420px;margin:20px auto"><div class="card__body">
        <h1 style="font-size:22px;margin-top:0">Новый пароль</h1>

        <?php if ($errors): ?>
            <div class="alert alert--error"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <p class="hint" style="margin-top:0">Аккаунт: <strong><?= e($user['username']) ?></strong></p>

        <form method="POST" action="<?= e(url('/vosstanovlenie/' . rawurlencode($token))) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="password">Новый пароль</label>
                <input type="password" id="password" name="password" class="input" required autofocus>
                <div class="hint">Минимум 8 символов.</div>
            </div>
            <div class="field">
                <label for="password_confirmation">Повторите пароль</label>
                <input type="password" id="password_confirmation" name="password_confirmation" class="input" required>
            </div>
            <button class="btn btn--primary btn--block"><?= icon('key') ?> Сменить пароль</button>
        </form>
    </div></div>
</div>
