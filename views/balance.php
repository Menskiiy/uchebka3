<div class="page">
    <div style="max-width:720px;margin:0 auto">
        <?php
        /*
         * Ссылка на правила рядом с балансом обязательна: «Огоньки» — это
         * внутренние бонусные единицы, а не деньги, и человек должен видеть
         * условия начисления и списания до того, как что-то потратит.
         */
        ?>
        <p class="hint" style="margin:0 0 20px;line-height:1.6">
            «Огоньки» — внутренние бонусные единицы сайта. Начисление, списание и
            аннулирование описаны в <?= legal_link('loyalty', 'правилах программы лояльности') ?>;
            ограничения за накрутки — в <?= legal_link('blocking', 'правилах блокировки аккаунтов') ?>.
        </p>

        <div class="card balance-card"><div class="card__body">
            <div class="balance-card__top">
                <div>
                    <p class="muted" style="margin:0 0 4px;font-size:13px">Ваш баланс</p>
                    <div class="balance-card__value">
                        <?= icon('flame') ?>
                        <span><?= e(flames_format((float) $me['balance'])) ?></span>
                    </div>
                    <?php if ((float) setting('flames_rate', '1') > 0): ?>
                        <p class="balance-card__rub">
                            ≈ <?= e(number_format(flames_to_rubles((float) $me['balance']), 2, ',', ' ')) ?> ₽
                            при обмене по заявке
                        </p>
                    <?php endif; ?>
                </div>

                <div class="balance-card__stats">
                    <span class="muted">Начислено за публикаций</span>
                    <strong><?= (int) $earned ?></strong>
                </div>
            </div>

            <ul class="balance-rules">
                <li>
                    <strong><?= e(flames_format((float) setting('flames_per_deal', '3'))) ?></strong>
                    за скидку или промокод, попавшие в ленту
                </li>
                <li>
                    <strong><?= e(flames_format((float) setting('flames_per_blog', '5'))) ?></strong>
                    за запись в блоге — её сложнее написать и она проходит проверку
                </li>
                <li>
                    <strong>+<?= e(flames_format((float) setting('flames_per_vote', '1'))) ?></strong>
                    за каждый голос «горячо» к вашей публикации
                </li>
                <li>
                    <strong>−<?= e(flames_format((float) setting('flames_per_dislike', '0.5'))) ?></strong>
                    за голос «холодно». Баланс при этом не уходит в минус
                </li>
                <li class="muted">
                    Если публикацию удалить, начисленное за неё списывается обратно.
                </li>
            </ul>
        </div></div>

        <?php
        /*
         * Огоньки и рубли. Формулировки — по Пользовательскому соглашению
         * (разделы 6 и 7): Огоньки не деньги, обмен — по заявке и после
         * проверки, выплата не гарантирована, налоги — по статусу получателя.
         * Курс и минимум берутся из настроек, чтобы текст не расходился
         * с тем, что реально произойдёт при подаче заявки.
         */
        $minWithdraw = (float) setting('flames_min_withdraw', '500');
        ?>
        <div class="card"><div class="card__body">
            <h2 class="card__title">Огоньки и рубли</h2>

            <div class="balance-rub">
                <div class="balance-rub__rate">
                    <span class="muted">Курс обмена сейчас</span>
                    <strong>1 Огонёк = <?= e(number_format(flames_to_rubles(1), 2, ',', ' ')) ?> ₽</strong>
                </div>
                <div class="balance-rub__rate">
                    <span class="muted">Обмен — от</span>
                    <strong><?= e(flames_format($minWithdraw)) ?> Огоньков</strong>
                </div>
            </div>

            <ul class="balance-rules balance-rules--plain">
                <li>
                    Огоньки — бонусные единицы программы лояльности, а не деньги: ими нельзя
                    расплатиться, их нельзя передать или продать другому пользователю.
                </li>
                <li>
                    Когда на балансе наберётся минимум, Огоньки можно обменять на рубли —
                    подать заявку на вывод ниже. Обмен происходит по курсу на момент подачи
                    заявки; курс и минимальную сумму устанавливает администрация и может их
                    изменить.
                </li>
                <li>
                    Перед выплатой администрация проверяет заявку, аккаунт и публикации,
                    за которые начислены Огоньки. Заявку могут приостановить или отклонить
                    при признаках накрутки, недостоверных данных или нарушения правил —
                    тогда Огоньки вернутся на баланс.
                </li>
                <li>
                    Выплата — поощрение за активность, а не зарплата и не оплата услуг,
                    и не гарантирована, если нарушены правила программы или нет правовых
                    либо технических оснований для выплаты.
                </li>
                <li>
                    Налоги зависят от вашего статуса. Для выплаты могут запросить сведения
                    о налоговом статусе, а у самозанятых — чек из «Мой налог».
                </li>
            </ul>

            <p class="hint" style="margin:10px 0 0">
                Подробно — в разделах 6 и 7 <?= legal_link('terms', 'Пользовательского соглашения') ?>
                и в <?= legal_link('loyalty', 'правилах программы лояльности') ?>.
            </p>
        </div></div>

        <div class="card"><div class="card__body">
            <h2 class="card__title">Заявка на вывод</h2>

            <?php if ($errors): ?>
                <div class="alert alert--error"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
            <?php endif; ?>

            <?php $min = (float) setting('flames_min_withdraw', '500'); ?>

            <?php if ((float) $me['balance'] < $min): ?>
                <p class="muted" style="margin:0">
                    Минимальная сумма к выводу — <?= e(flames_format($min)) ?> Огоньков
                    (<?= e(number_format(flames_to_rubles($min), 0, ',', ' ')) ?> ₽ по текущему курсу).
                    Вам осталось накопить <?= e(flames_format($min - (float) $me['balance'])) ?>.
                </p>
            <?php else: ?>
                <form method="POST" action="<?= e(url('/balans')) ?>">
                    <?= csrf_field() ?>

                    <div class="field">
                        <label for="amount">Сумма, Огоньков</label>
                        <input type="number" step="0.01" id="amount" name="amount" class="input"
                               min="<?= e((string) $min) ?>" max="<?= e((string) round((float) $me['balance'], 2)) ?>"
                               value="<?= e((string) round((float) $me['balance'], 2)) ?>" required>
                        <div class="hint">
                            От <?= e(flames_format($min)) ?> до <?= e(flames_format((float) $me['balance'])) ?>.
                            К выплате по текущему курсу:
                            <strong data-rub-out data-rate="<?= e((string) flames_to_rubles(1)) ?>"><?= e(number_format(flames_to_rubles((float) $me['balance']), 2, ',', ' ')) ?></strong> ₽.
                        </div>
                    </div>

                    <div class="field">
                        <label for="full_name">Фамилия, имя, отчество</label>
                        <input type="text" id="full_name" name="full_name" class="input" required>
                    </div>

                    <div class="grid-2">
                        <div class="field">
                            <label for="phone">Телефон</label>
                            <input type="tel" id="phone" name="phone" class="input" placeholder="+7 999 123-45-67" required>
                        </div>

                        <div class="field">
                            <label for="bank">Банк</label>
                            <input type="text" id="bank" name="bank" class="input" placeholder="Т-Банк, Сбер…" required>
                        </div>
                    </div>

                    <div class="field">
                        <label for="requisites">Реквизиты</label>
                        <input type="text" id="requisites" name="requisites" class="input"
                               placeholder="Номер карты или счёта" required>
                    </div>

                    <div class="field">
                        <label for="comment">Комментарий</label>
                        <input type="text" id="comment" name="comment" class="input" placeholder="Не обязательно">
                    </div>

                    <button class="btn btn--primary">Отправить заявку</button>
                </form>

                <p class="hint" style="margin-top:12px">
                    Огоньки списываются сразу при подаче заявки. Если её отклонят —
                    вернутся на баланс.
                </p>
            <?php endif; ?>
        </div></div>

        <?php if ($history): ?>
            <div class="card"><div class="card__body">
                <h2 class="card__title">История заявок</h2>

                <?php foreach ($history as $w): ?>
                    <div class="row" style="padding:10px 0;border-bottom:1px solid var(--border)">
                        <strong><?= e(flames_format((float) $w['amount'])) ?> <?= icon('flame') ?></strong>
                        <span class="spacer"></span>
                        <span class="badge <?= $w['status'] === 'paid' ? 'badge--verified' : ($w['status'] === 'rejected' ? 'badge--expired' : 'badge--pending') ?>">
                            <?= e(withdrawal_status_label($w['status'])) ?>
                        </span>
                        <span class="muted" style="font-size:12px"><?= e(ago($w['created_at'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div></div>
        <?php endif; ?>
    </div>
</div>
