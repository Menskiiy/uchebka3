<div class="admin__cols">
    <div>
        <div class="card"><div class="card__body">
            <h2 class="card__title">Проверка окружения</h2>
            <?php foreach ($checks as $label => $row): ?>
                <div class="row" style="padding:7px 0;border-bottom:1px solid var(--border)">
                    <span style="color:<?= $row['ok'] ? 'var(--green)' : '#c62828' ?>;font-weight:700;width:16px">
                        <?= $row['ok'] ? '✔' : '✘' ?>
                    </span>
                    <span style="flex:1"><?= e($label) ?></span>
                    <span class="muted" style="font-size:13px;text-align:right"><?= e($row['value']) ?></span>
                </div>
            <?php endforeach; ?>
        </div></div>

        <div class="card"><div class="card__body">
            <h2 class="card__title">Отправить пробное письмо</h2>

            <form method="POST" action="<?= e(url('/admin/pochta')) ?>" class="row">
                <?= csrf_field() ?>
                <input type="email" name="to" class="input" style="flex:1;min-width:200px"
                       value="<?= e($me['email']) ?>" required>
                <select name="kind" class="input" style="width:auto">
                    <option value="full">Как обычно (с картинкой)</option>
                    <option value="plain">Простым текстом</option>
                </select>
                <button class="btn btn--primary"><?= icon('mail') ?> Отправить</button>
            </form>

            <p class="hint">
                Проверьте и папку «Спам» — письма с новых доменов часто попадают туда.
                Если письмо не дошло совсем, смотрите журнал почты в панели хостинга:
                там видно, покидало ли оно сервер.
            </p>
        </div></div>

        <div class="card"><div class="card__body">
            <h2 class="card__title">Отправка через SMTP</h2>

            <p class="hint" style="margin-top:0">
                Функция <code>mail()</code> отдаёт письмо локальному sendmail и почти
                всегда отвечает «успешно» — даже когда Яндекс и Gmail молча его
                выбрасывают. Отправка через ящик домена решает это: письмо идёт
                с реального адреса и проходит SPF и DKIM.
            </p>

            <form method="POST" action="<?= e(url('/admin/pochta/smtp')) ?>">
                <?= csrf_field() ?>

                <div class="grid-2">
                    <div class="field">
                        <label for="smtp_host">Сервер</label>
                        <input type="text" id="smtp_host" name="smtp_host" class="input"
                               value="<?= e(setting('smtp_host', '')) ?>" placeholder="smtp.yandex.ru">
                    </div>

                    <div class="field">
                        <label for="smtp_port">Порт</label>
                        <input type="number" id="smtp_port" name="smtp_port" class="input"
                               value="<?= e(setting('smtp_port', '465')) ?>">
                    </div>

                    <div class="field">
                        <label for="smtp_user">Ящик</label>
                        <input type="text" id="smtp_user" name="smtp_user" class="input"
                               value="<?= e(setting('smtp_user', '')) ?>" placeholder="noreply@hothunt.ru">
                        <div class="hint">Должен совпадать с адресом отправителя в настройках сайта.</div>
                    </div>

                    <div class="field">
                        <label for="smtp_pass">Пароль</label>
                        <input type="password" id="smtp_pass" name="smtp_pass" class="input"
                               placeholder="<?= setting('smtp_pass', '') !== '' ? 'сохранён — оставьте пустым' : '' ?>"
                               autocomplete="new-password">
                    </div>

                    <div class="field">
                        <label for="smtp_secure">Шифрование</label>
                        <select id="smtp_secure" name="smtp_secure" class="input">
                            <?php foreach (['ssl' => 'SSL (обычно порт 465)', 'tls' => 'STARTTLS (обычно 587)', 'none' => 'без шифрования'] as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= setting('smtp_secure', 'ssl') === $key ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <label class="check" style="margin:4px 0 12px">
                    <input type="checkbox" name="smtp_off" value="1">
                    Выключить SMTP и вернуться к функции mail()
                </label>

                <button class="btn btn--primary">Сохранить</button>
            </form>
        </div></div>

        <?php if ($log): ?>
            <div class="card"><div class="card__body">
                <h2 class="card__title">Журнал почты</h2>
                <pre class="mail-log"><?= e($log) ?></pre>
                <p class="hint">Сюда пишутся отказы SMTP-сервера: по ним видно, на какой команде всё встало.</p>
            </div></div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="card"><div class="card__body">
                <h2 class="card__title">Последние ошибки сайта</h2>
                <pre class="mail-log"><?= e($errors) ?></pre>
                <form method="POST" action="<?= e(url('/admin/pochta/ochistit')) ?>" style="margin-top:10px">
                    <?= csrf_field() ?>
                    <button class="btn btn--sm">Очистить</button>
                </form>
            </div></div>
        <?php endif; ?>
    </div>

    <div class="card admin__sticky"><div class="card__body">
        <h2 class="card__title">Если письма не доходят</h2>

        <p class="hint" style="margin-top:0">
            <strong>1. Функция отключена.</strong> Если выше красным помечена
            строка про <code>mail()</code>, хостер её запретил. Решение —
            отправка через SMTP ящика домена.
        </p>

        <p class="hint">
            <strong>2. Нет ящика отправителя.</strong> Многие серверы отвергают
            письма от несуществующего адреса. Заведите реальный
            <code>noreply@hothunt.ru</code> в разделе «Почтовые ящики»
            и укажите его в настройках сайта.
        </p>

        <p class="hint">
            <strong>3. Нет записей SPF и DKIM.</strong> Тогда письмо уходит,
            но Яндекс и Gmail его отбрасывают молча — в журнале при этом
            будет пусто, а получатель ничего не увидит.
        </p>

        <p class="hint">
            <strong>4. Смотрите журнал хостинга.</strong> В панели раздел
            «Почта» → журнал. Там видно, ушло письмо с сервера или нет.
            Если ушло, а не дошло — дело в записях DNS.
        </p>
    </div></div>
</div>
