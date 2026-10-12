<?php
/**
 * Каналы сайта: куда уходит дайджест «скоро сгорает» и что именно уйдёт.
 *
 * Черновик показан прямо здесь: вечерний пост должен быть предсказуемым,
 * и видеть его заранее важнее, чем экономить место на экране.
 */
?>
<div class="admin__cols">
    <div>
        <div class="card"><div class="card__body">
            <h2 class="card__title">Что уйдёт в канал</h2>

            <p class="hint" style="margin-top:0">
                Сгорает в ближайшие двое суток: <strong><?= (int) $expiring ?></strong>
                <?= e(plural((int) $expiring, 'предложение', 'предложения', 'предложений')) ?>.
                В дайджест попадает до семи, кроме уже отправленных и тех, что кончаются
                меньше чем через три часа — их человек всё равно не успеет открыть.
            </p>

            <?php if ($draftText): ?>
                <pre class="mail-log"><?= e($draftText) ?></pre>
            <?php else: ?>
                <p class="empty" style="margin:0">
                    Сейчас отправлять нечего: нет находок со сроком в ближайшие двое суток.
                </p>
            <?php endif; ?>

            <div class="row" style="margin-top:12px;gap:10px">
                <strong>Почта</strong>
                <span class="muted" style="font-size:13px">
                    <?= e($mail['message']) ?><?= $mail['count'] ? ' · ' . (int) $mail['count'] . ' находок, подписчиков ' . (int) $mail['users'] : '' ?>
                </span>
                <span class="spacer"></span>

                <?php if ($mail['count'] > 0 && $mail['users'] > 0): ?>
                    <form method="POST" action="<?= e(url('/admin/kanaly/otpravit')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="channel" value="email">
                        <button class="btn btn--sm btn--primary">Разослать сейчас</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (! empty($mail['text'])): ?>
                <pre class="mail-log" style="margin-top:8px"><?= e($mail['text']) ?></pre>
            <?php endif; ?>

            <?php foreach ($preview as $key => $result): ?>
                <div class="row" style="margin-top:12px;gap:10px">
                    <strong><?= e($channels[$key]['label'] ?? $key) ?></strong>
                    <span class="muted" style="font-size:13px">
                        <?= e($result['message']) ?><?= $result['count'] ? ' · ' . (int) $result['count'] . ' шт.' : '' ?>
                    </span>
                    <span class="spacer"></span>

                    <?php if ($result['count'] > 0): ?>
                        <form method="POST" action="<?= e(url('/admin/kanaly/otpravit')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="channel" value="<?= e($key) ?>">
                            <button class="btn btn--sm btn--primary">Отправить сейчас</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div></div>

        <div class="card"><div class="card__body">
            <h2 class="card__title">Подключение</h2>

            <form method="POST" action="<?= e(url('/admin/kanaly')) ?>">
                <?= csrf_field() ?>

                <h3 class="card__title" style="font-size:15px">MAX</h3>

                <div class="grid-2">
                    <div class="field">
                        <label for="max_bot_token">Токен бота</label>
                        <input type="password" id="max_bot_token" name="max_bot_token" class="input"
                               placeholder="<?= setting('max_bot_token', '') !== '' ? 'сохранён — оставьте пустым' : 'из @MasterBot' ?>"
                               autocomplete="new-password">
                    </div>

                    <div class="field">
                        <label for="max_chat_id">Идентификатор канала</label>
                        <input type="text" id="max_chat_id" name="max_chat_id" class="input"
                               value="<?= e(setting('max_chat_id', '')) ?>" placeholder="-100...">
                    </div>
                </div>

                <label class="check" style="margin:4px 0 16px">
                    <input type="checkbox" name="max_off" value="1"> Отключить отправку в MAX
                </label>

                <h3 class="card__title" style="font-size:15px">Telegram</h3>

                <div class="grid-2">
                    <div class="field">
                        <label for="tg_bot_token">Токен бота</label>
                        <input type="password" id="tg_bot_token" name="tg_bot_token" class="input"
                               placeholder="<?= setting('tg_bot_token', '') !== '' ? 'сохранён — оставьте пустым' : 'из @BotFather' ?>"
                               autocomplete="new-password">
                    </div>

                    <div class="field">
                        <label for="tg_chat_id">Идентификатор канала</label>
                        <input type="text" id="tg_chat_id" name="tg_chat_id" class="input"
                               value="<?= e(setting('tg_chat_id', '')) ?>" placeholder="@hothunt или -100...">
                    </div>
                </div>

                <label class="check" style="margin:4px 0 16px">
                    <input type="checkbox" name="tg_off" value="1"> Отключить отправку в Telegram
                </label>

                <button class="btn btn--primary">Сохранить</button>
            </form>
        </div></div>

        <?php if ($log): ?>
            <div class="card"><div class="card__body">
                <h2 class="card__title">Журнал отправок</h2>
                <pre class="mail-log"><?= e($log) ?></pre>
            </div></div>
        <?php endif; ?>
    </div>

    <div class="card admin__sticky"><div class="card__body">
        <h2 class="card__title">Как это работает</h2>

        <p class="hint" style="margin-top:0">
            Раз в сутки планировщик запускает <code>bin/digest.php</code> и отправляет
            один пост со списком того, что скоро сгорает. Задача в ISPmanager:
        </p>

        <pre class="mail-log">0 19 * * * /usr/bin/php \
  /путь/к/сайту/bin/digest.php</pre>

        <p class="hint">
            Вечер выбран не случайно: у человека должно остаться время
            воспользоваться находкой до конца срока.
        </p>

        <p class="hint">
            Одно предложение уходит в канал один раз — повторов не будет,
            даже если оно сгорает не сегодня. Если находок меньше двух,
            пост не отправляется: «сегодня сгорает: ничего» стоит отписок.
        </p>

        <p class="hint">
            <strong>Почта.</strong> Письма уходят только тем, кто отметил согласие
            на рекламную рассылку при регистрации или включил её в настройках профиля.
            В каждом письме есть ссылка отписки, и она отключает только рекламу —
            уведомления об ответах остаются.
        </p>

        <p class="hint">
            Ссылки в посте ведут на страницы сайта, а не сразу в магазин —
            иначе канал живёт сам по себе, а сайт остаётся без переходов
            и комментариев.
        </p>

        <p class="hint">
            <strong>Если MAX отвечает ошибкой сертификата</strong> («unable to get local
            issuer certificate») — его цепочка завязана на корневой сертификат Минцифры,
            которого нет в стандартном хранилище системы.
        </p>

        <p class="hint">
            Сейчас свой набор сертификатов
            <?= is_readable(__DIR__ . '/../../data/ca-bundle.pem') ? '<strong>найден</strong> (data/ca-bundle.pem)' : 'не найден' ?>.
            Чтобы добавить: скачать корневой и выпускающий сертификаты с gu-st.ru,
            склеить в один файл и положить как <code>data/ca-bundle.pem</code>.
            Права root для этого не нужны.
        </p>

        <pre class="mail-log">cd /путь/к/сайту/data
curl -o root.crt https://gu-st.ru/content/lending/russian_trusted_root_ca_pem.crt
curl -o sub.crt  https://gu-st.ru/content/lending/russian_trusted_sub_ca_pem.crt
cat root.crt sub.crt > ca-bundle.pem</pre>

        <p class="hint">
            На сервере с правами root то же самое делается системно:
            положить оба файла в <code>/usr/local/share/ca-certificates/</code>
            и выполнить <code>update-ca-certificates</code> — тогда сертификаты
            заработают для всего сервера, а не только для этого сайта.
        </p>
    </div></div>
</div>
