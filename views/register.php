<div class="page">
    <div class="card" style="max-width:420px;margin:20px auto"><div class="card__body">
        <h1 style="font-size:22px;margin-top:0">Создать аккаунт</h1>

        <?php if ($errors): ?>
            <div class="alert alert--error"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= e(url('/registraciya')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="username">Имя пользователя</label>
                <input type="text" id="username" name="username" class="input" value="<?= e($old['username'] ?? '') ?>" required autofocus>
                <div class="hint">Латиница, цифры, дефис и подчёркивание. От 3 до 30 символов.</div>
            </div>
            <div class="field">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" class="input" value="<?= e($old['email'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label for="password">Пароль</label>
                <input type="password" id="password" name="password" class="input" required>
                <div class="hint">Минимум 8 символов.</div>
            </div>
            <div class="field">
                <label for="password_confirmation">Повторите пароль</label>
                <input type="password" id="password_confirmation" name="password_confirmation" class="input" required>
            </div>
<?php
            /*
             * Три отдельных отметки, ни одна не проставлена заранее.
             * Соглашение и согласие на обработку данных — разные действия,
             * их нельзя объединять в одну галочку; реклама необязательна
             * и на регистрацию не влияет.
             */
            ?>
            <?= c_captcha() ?>

            <div class="consents">
                <label class="check">
                    <input type="checkbox" name="accept_terms" value="1" required>
                    <span>Я принимаю <?= legal_link('terms', 'пользовательское соглашение') ?>.</span>
                </label>

                <label class="check">
                    <input type="checkbox" name="accept_personal_data" value="1" required>
                    <span>
                        Я согласен(на) на обработку персональных данных в соответствии с
                        <?= legal_link('consent', 'согласием на обработку персональных данных') ?> и
                        <?= legal_link('privacy', 'политикой обработки персональных данных') ?>.
                    </span>
                </label>

                <label class="check">
                    <input type="checkbox" name="accept_ads" value="1">
                    <span>
                        Хочу получать рекламные и маркетинговые рассылки — по
                        <?= legal_link('ads', 'отдельному согласию') ?>. Необязательно,
                        на регистрацию не влияет.
                    </span>
                </label>
            </div>

            <button class="btn btn--primary btn--block">Зарегистрироваться</button>
        </form>


        <?php if (yandex_enabled()): ?>
            <div class="oauth-divider"><span>или</span></div>

            <a href="<?= e(url('/auth/yandex')) ?>" class="btn btn--block btn--yandex">
                <span class="btn--yandex__mark">Я</span> Войти с Яндекс ID
            </a>

            <?php
            /*
             * У входа через Яндекс ID формы нет, поэтому отметки поставить
             * негде: те же условия показываем текстом рядом с кнопкой.
             * Он остаётся на экране вместе с ней, так что человек видит
             * документы до нажатия, а не после регистрации.
             */
            ?>
            <p class="hint" style="margin:10px 0 0">
                Продолжая через Яндекс ID, вы принимаете
                <?= legal_link('terms', 'пользовательское соглашение') ?>,
                подтверждаете ознакомление с
                <?= legal_link('privacy', 'политикой обработки персональных данных') ?>
                и даёте <?= legal_link('consent', 'согласие на обработку персональных данных') ?>.
                Сайт получает от Яндекса адрес электронной почты и технический идентификатор;
                пароль от Яндекс ID нам не передаётся.
            </p>
        <?php endif; ?>
        <p class="muted" style="margin-top:16px;text-align:center">
            Уже есть аккаунт? <a href="<?= e(url('/vhod')) ?>">Войти</a>
        </p>
    </div></div>
</div>
