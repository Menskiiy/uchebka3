<form method="POST" action="<?= e(url('/admin/nastroyki')) ?>">
    <?= csrf_field() ?>

    <div class="admin__cols">
        <div class="card"><div class="card__body">
            <h2 class="card__title">Сайт</h2>

            <div class="field">
                <label for="site_name">Название сайта</label>
                <input type="text" id="site_name" name="site_name" class="input" value="<?= e(setting('site_name', cfg('app_name'))) ?>" required>
                <div class="hint">Показывается в шапке, заголовке вкладки и письмах.</div>
            </div>

            <div class="field">
                <label for="site_description">Описание сайта</label>
                <textarea id="site_description" name="site_description" class="input" rows="3"><?= e(setting('site_description', cfg('app_description'))) ?></textarea>
                <div class="hint">Используется в мета-теге description для поисковиков.</div>
            </div>

            <div class="field">
                <label for="per_page">Публикаций на странице</label>
                <input type="number" id="per_page" name="per_page" class="input" min="5" max="50" value="<?= (int) setting('per_page', cfg('per_page')) ?>">
            </div>

            <label class="check" style="margin-bottom:14px">
                <input type="checkbox" name="registration_open" value="1" <?= setting('registration_open', '1') === '1' ? 'checked' : '' ?>>
                Разрешить регистрацию новых пользователей
            </label>

            <div class="field">
                <label for="site_url">Адрес сайта</label>
                <input type="url" id="site_url" name="site_url" class="input"
                       value="<?= e(setting('site_url', '')) ?>" placeholder="https://hothunt.ru">
                <div class="hint">Используется в ссылках внутри писем. Без него адрес берётся из текущего запроса.</div>
            </div>

            <div class="field">
                <label for="mail_from">Адрес отправителя писем</label>
                <input type="email" id="mail_from" name="mail_from" class="input"
                       value="<?= e(setting('mail_from', '')) ?>" placeholder="noreply@hothunt.ru">
                <div class="hint">Лучше завести реальный ящик на домене — письма с несуществующих адресов чаще уходят в спам.</div>
            </div>

            <div class="field">
                <label for="yandex_client_id">Вход через Яндекс: ClientID</label>
                <input type="text" id="yandex_client_id" name="yandex_client_id" class="input"
                       value="<?= e(setting('yandex_client_id', '')) ?>" autocomplete="off">
            </div>

            <div class="field">
                <label for="yandex_client_secret">Вход через Яндекс: Client secret</label>
                <input type="text" id="yandex_client_secret" name="yandex_client_secret" class="input"
                       value="<?= e(setting('yandex_client_secret', '')) ?>" autocomplete="off">
                <div class="hint">
                    Ключи берутся на oauth.yandex.ru. В настройках приложения
                    Redirect URI должен быть ровно таким:<br>
                    <code><?= e(site_url() . url('/auth/yandex/callback')) ?></code><br>
                    Секрет можно менять здесь в любой момент — код трогать не нужно.
                    Оба поля пустые — кнопка входа через Яндекс не показывается.
                </div>
            </div>

            <div class="field">
                <label for="metrika_id">Номер счётчика Яндекс.Метрики</label>
                <input type="number" id="metrika_id" name="metrika_id" class="input"
                       value="<?= e(setting('metrika_id', '')) ?>" placeholder="например, 111769631">
                <div class="hint">Оставьте пустым, чтобы отключить аналитику.</div>
            </div>

            <div class="hint" style="margin:-8px 0 14px">
                Счётчик запускается только после согласия в cookie-баннере — так написано
                в политике использования файлов cookie, и отключить это нельзя.
                В статистику попадут не все посетители, это нормально.
            </div>

            <div class="field">
                <label for="admin_email">Почта администратора</label>
                <input type="email" id="admin_email" name="admin_email" class="input"
                       value="<?= e(setting('admin_email', '')) ?>" placeholder="rustam@hothunt.ru">
                <div class="hint">Сюда приходят письма о новых ошибках в журнале.</div>
            </div>

            <label class="check" style="margin-bottom:14px">
                <input type="checkbox" name="captcha_enabled" value="1" <?= setting('captcha_enabled', '1') === '1' ? 'checked' : '' ?>>
                Проверять формы входа и регистрации капчей
            </label>

            <div class="grid-2">
                <div class="field">
                    <label for="smartcaptcha_key">Ключ клиента SmartCaptcha</label>
                    <input type="text" id="smartcaptcha_key" name="smartcaptcha_key" class="input"
                           value="<?= e(setting('smartcaptcha_key', '')) ?>">
                </div>

                <div class="field">
                    <label for="smartcaptcha_secret">Серверный ключ SmartCaptcha</label>
                    <input type="password" id="smartcaptcha_secret" name="smartcaptcha_secret" class="input"
                           placeholder="<?= setting('smartcaptcha_secret', '') !== '' ? 'сохранён — оставьте пустым' : '' ?>"
                           autocomplete="new-password">
                </div>
            </div>

            <div class="hint" style="margin:-8px 0 14px">
                Без ключей работает собственная проверка — вопрос с ответом цифрой, без
                внешних скриптов. С ключами из Яндекс Облака включается SmartCaptcha:
                она сильнее, но подгружает виджет со стороны. Именно она описана
                в политике использования файлов cookie.
            </div>

            <label class="check" style="margin-bottom:14px">
                <input type="checkbox" name="verify_email" value="1" <?= setting('verify_email', '0') === '1' ? 'checked' : '' ?>>
                Требовать подтверждение почты при регистрации
            </label>
            <div class="hint" style="margin:-8px 0 14px">
                Включайте только когда убедитесь, что письма доходят —
                иначе регистрация окажется закрытой: письмо со ссылкой
                просто не придёт.
            </div>

            <label class="check" style="margin-bottom:14px">
                <input type="checkbox" name="moderate_all" value="1" <?= setting('moderate_all', '1') === '1' ? 'checked' : '' ?>>
                Проверять скидки, промокоды и записи блога перед публикацией
            </label>
            <div class="hint" style="margin:-8px 0 14px">
                Включено: всё, что попадает в ленту и в поиск, сначала уходит в очередь
                «Ждут проверки». Редакторы и модераторы публикуются сразу, обсуждения
                проверку не проходят. Выключайте, только пока сайт наполняете сами.
            </div>
            <div class="hint" style="margin:-8px 0 14px">
                По умолчанию счётчик работает сразу. Со строгим режимом в статистику
                попадут только нажавшие «Принять» — обычно это меньше трети посетителей.
            </div>
        </div></div>

        <div class="card"><div class="card__body">
            <h2 class="card__title">Температура</h2>
            <p class="hint" style="margin-top:0">
                Градус публикации — сумма голосов с учётом веса голосующего.
                Порог определяет, с какого значения предложение считается горячим.
            </p>

            <div class="field">
                <label for="hot_threshold">Порог «горячего», градусов</label>
                <input type="number" id="hot_threshold" name="hot_threshold" class="input" value="<?= (int) setting('hot_threshold', cfg('temperature.hot_threshold')) ?>">
            </div>

            <div class="field">
                <label for="reputation_per_post">Репутация за публикацию</label>
                <input type="number" id="reputation_per_post" name="reputation_per_post" class="input" min="0" max="50"
                       value="<?= (int) setting('reputation_per_post', '1') ?>">
                <div class="hint">
                    Раньше репутация росла только от чужих голосов, и у активных
                    авторов почти не двигалась. Небольшое начисление за публикацию
                    это исправляет.
                </div>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="flames_per_deal">Огоньков за скидку или промокод</label>
                    <input type="number" step="0.1" id="flames_per_deal" name="flames_per_deal" class="input" min="0" max="500"
                           value="<?= e(setting('flames_per_deal', '3')) ?>">
                    <div class="hint">Держите ниже начисления за блог: находку опубликовать быстрее, чем написать разбор.</div>
                </div>

                <div class="field">
                    <label for="flames_per_blog">Огоньков за запись в блоге</label>
                    <input type="number" step="0.1" id="flames_per_blog" name="flames_per_blog" class="input" min="0" max="500"
                           value="<?= e(setting('flames_per_blog', '5')) ?>">
                </div>

                <div class="field">
                    <label for="flames_per_vote">Огоньков за голос «горячо»</label>
                    <input type="number" step="0.1" id="flames_per_vote" name="flames_per_vote" class="input" min="0" max="100"
                           value="<?= e(setting('flames_per_vote', '1')) ?>">
                    <div class="hint">Получает автор публикации. При отмене голоса списывается обратно.</div>
                </div>

                <div class="field">
                    <label for="flames_per_dislike">Списывать за голос «холодно»</label>
                    <input type="number" step="0.1" id="flames_per_dislike" name="flames_per_dislike" class="input" min="0" max="100"
                           value="<?= e(setting('flames_per_dislike', '0.5')) ?>">
                    <div class="hint">Держите ниже начисления за «горячо» — иначе пара недовольных обнулит заработок за удачный пост. В минус баланс не уйдёт.</div>
                </div>

                <div class="field">
                    <label for="flames_rate">Рублей за один Огонёк</label>
                    <input type="number" step="0.01" id="flames_rate" name="flames_rate" class="input" min="0"
                           value="<?= e(setting('flames_rate', '1')) ?>">
                </div>

                <div class="field">
                    <label for="flames_min_withdraw">Минимум к выводу, Огоньков</label>
                    <input type="number" step="0.1" id="flames_min_withdraw" name="flames_min_withdraw" class="input" min="1"
                           value="<?= e(setting('flames_min_withdraw', '500')) ?>">
                </div>
            </div>

            <div class="field">
                <label for="lifetime_days">Срок жизни скидки, дней</label>
                <input type="number" id="lifetime_days" name="lifetime_days" class="input" value="<?= (int) setting('lifetime_days', cfg('lifetime_days')) ?>">
            </div>

            <button class="btn btn--primary btn--block">Сохранить настройки</button>
        </div></div>
    </div>
</form>

<div class="card"><div class="card__body">
    <h2 class="card__title">Система</h2>
    <div class="admin__meta">
        <span>База данных</span><strong><?= e(db_driver() === 'mysql' ? 'MySQL' : 'SQLite') ?></strong>
        <span>Версия PHP</span><strong><?= e(PHP_VERSION) ?></strong>
        <span>Публикаций</span><strong><?= (int) qv('SELECT COUNT(*) FROM threads') ?></strong>
        <span>Пользователей</span><strong><?= (int) qv('SELECT COUNT(*) FROM users') ?></strong>
    </div>

    <form method="POST" action="<?= e(url('/admin/nastroyki/obsluzhivanie')) ?>" style="margin-top:14px">
        <?= csrf_field() ?>
        <button class="btn">Пересчитать градусы сейчас</button>
    </form>
    <p class="hint">
        Нужно после изменения весов голосов или порога. Заодно снимает истёкшие
        предложения и просроченные блокировки.
    </p>
</div></div>
