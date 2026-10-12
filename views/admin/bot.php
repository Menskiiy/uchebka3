<div class="admin__cols">
    <div>
        <div class="stat-grid" style="margin-bottom:14px">
            <span class="stat">
                <span class="stat__value" data-count="<?= (int) $stats['total'] ?>">0</span>
                <span class="stat__label">всего опубликовано</span>
            </span>
            <span class="stat">
                <span class="stat__value" data-count="<?= (int) $stats['week'] ?>">0</span>
                <span class="stat__label">за неделю</span>
            </span>
            <a href="<?= e(url('/admin/publikacii?status=pending')) ?>" class="stat <?= $stats['pending'] > 0 ? 'stat--alert' : '' ?>">
                <span class="stat__value" data-count="<?= (int) $stats['pending'] ?>">0</span>
                <span class="stat__label">ждут проверки</span>
            </a>
        </div>

        <div class="card"><div class="card__body">
            <div class="row" style="margin-bottom:14px">
                <h2 class="card__title" style="margin:0;flex:1">Настройки</h2>
                <?php if ($author): ?>
                    <a href="<?= e(url('/profile/' . rawurlencode($author['username']))) ?>" class="btn btn--sm btn--ghost" target="_blank">
                        Профиль бота
                    </a>
                <?php endif; ?>
            </div>

            <form method="POST" action="<?= e(url('/admin/bot')) ?>">
                <?= csrf_field() ?>

                <label class="check" style="margin-bottom:14px">
                    <input type="checkbox" name="bot_enabled" value="1" <?= setting('bot_enabled', '0') === '1' ? 'checked' : '' ?>>
                    Забирать скидки по расписанию
                </label>
                <div class="hint" style="margin:-8px 0 16px">
                    Выключено — бот работает только по кнопке ниже. Для расписания
                    нужна задача в планировщике, команда указана справа.
                </div>

                <label class="check" style="margin-bottom:14px">
                    <input type="checkbox" name="bot_moderate" value="1" <?= setting('bot_moderate', '1') === '1' ? 'checked' : '' ?>>
                    Отправлять находки на проверку, а не сразу в ленту
                </label>
                <div class="hint" style="margin:-8px 0 16px">
                    Пока присматриваетесь — держите включённым: посты попадут
                    в очередь «Ждут проверки», и вы решите, что публиковать.
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label for="bot_min_discount">Минимальная скидка, %</label>
                        <input type="number" id="bot_min_discount" name="bot_min_discount" class="input" min="0" max="95"
                               value="<?= (int) setting('bot_min_discount', '40') ?>">
                        <div class="hint">Ниже этого порога игры не берутся.</div>
                    </div>

                    <div class="field">
                        <label for="bot_limit">Публикаций за запуск</label>
                        <input type="number" id="bot_limit" name="bot_limit" class="input" min="1" max="20"
                               value="<?= (int) setting('bot_limit', '5') ?>">
                        <div class="hint">Больше пяти за раз — лента станет похожа на выгрузку.</div>
                    </div>

                    <div class="field">
                        <label for="bot_region">Регион цен</label>
                        <input type="text" id="bot_region" name="bot_region" class="input" maxlength="2"
                               value="<?= e(setting('bot_region', 'ru')) ?>">
                        <div class="hint">Двухбуквенный код: ru, kz, by, us.</div>
                    </div>

                    <div class="field">
                        <label for="bot_username">Имя аккаунта</label>
                        <input type="text" id="bot_username" name="bot_username" class="input"
                               value="<?= e(setting('bot_username', 'HotHuntBot')) ?>">
                        <div class="hint">Создастся при первом запуске.</div>
                    </div>
                </div>

                <button class="btn btn--primary">Сохранить</button>
            </form>
        </div></div>

        <div class="card"><div class="card__body">
            <h2 class="card__title">Запустить вручную</h2>
            <p class="hint" style="margin-top:0">
                Один заход: бот заберёт витрину скидок Steam и опубликует
                до указанного числа игр. Занимает несколько секунд.
            </p>
            <form method="POST" action="<?= e(url('/admin/bot/zapustit')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn--primary">Забрать скидки сейчас</button>
            </form>
        </div></div>

        <?php if ($recent): ?>
            <div class="card"><div class="card__body" style="padding:0">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Последние находки</th>
                            <th style="width:90px">Скидка</th>
                            <th style="width:110px">Цена</th>
                            <th style="width:120px">Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td class="td--main">
                                    <a href="<?= e(url('/post/' . rawurlencode($r['slug']))) ?>" target="_blank"><?= e($r['title']) ?></a>
                                    <div class="muted" style="font-size:12px"><?= e(ago($r['created_at'])) ?></div>
                                </td>
                                <td data-label="Скидка"><strong style="color:var(--green)">−<?= (int) $r['discount_percent'] ?>%</strong></td>
                                <td data-label="Цена"><?= e(money((float) $r['price'])) ?></td>
                                <td data-label="Статус"><span class="badge"><?= e(status_label($r['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        <?php endif; ?>

        <?php if ($log): ?>
            <div class="card"><div class="card__body">
                <h2 class="card__title">Журнал</h2>
                <pre class="mail-log"><?= e($log) ?></pre>
            </div></div>
        <?php endif; ?>
    </div>

    <div class="card admin__sticky"><div class="card__body">
        <h2 class="card__title">Расписание</h2>
        <p class="hint" style="margin-top:0">
            Чтобы бот работал сам, добавьте задачу в планировщик ISPmanager.
            Раз в день достаточно:
        </p>
        <pre class="mail-log">/usr/bin/php <?= e(realpath(__DIR__ . '/../../bin/steam-bot.php') ?: '/путь/к/сайту/bin/steam-bot.php') ?></pre>

        <h2 class="card__title" style="margin-top:18px">О чём стоит помнить</h2>

        <p class="hint">
            <strong>Эндпоинты неофициальные.</strong> Valve их не документирует
            и может изменить без предупреждения. Если однажды бот перестанет
            находить скидки — смотрите журнал, скорее всего изменился формат ответа.
        </p>

        <p class="hint">
            <strong>Не увлекайтесь количеством.</strong> Бот, публикующий по двадцать
            игр в день, забьёт ленту и отобьёт желание постить у живых людей.
            Пять в день — уже заметное наполнение.
        </p>

        <p class="hint">
            <strong>Цены региональные.</strong> Скидка берётся для указанного региона.
            Проверьте, что цифры совпадают с тем, что видят ваши посетители.
        </p>

        <p class="hint">
            <strong>Берутся только игры.</strong> Дополнения и наборы пропускаются —
            иначе лента заполнится DLC к одной игре.
        </p>
    </div></div>
</div>
