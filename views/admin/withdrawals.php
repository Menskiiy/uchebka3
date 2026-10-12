<div class="tabs" style="margin-bottom:14px">
    <?php foreach (['pending' => 'На рассмотрении', 'paid' => 'Выплаченные', 'rejected' => 'Отклонённые'] as $key => $label): ?>
        <a href="<?= e(url('/admin/vyplaty?status=' . $key)) ?>" class="<?= $status === $key ? 'is-active' : '' ?>">
            <?= e($label) ?> (<?= (int) $counts[$key] ?>)
        </a>
    <?php endforeach; ?>
</div>

<?php if (! $items): ?>
    <div class="card"><div class="empty">Заявок в этой категории нет.</div></div>
<?php endif; ?>

<?php foreach ($items as $w): ?>
    <div class="card"><div class="card__body">
        <div class="row" style="margin-bottom:10px">
            <strong style="font-size:19px;color:var(--brand)"><?= e(flames_format((float) $w['amount'])) ?> <?= icon('flame') ?></strong>
            <span class="muted">≈ <?= e(money(flames_to_rubles((float) $w['amount']))) ?></span>
            <span class="spacer"></span>
            <span class="muted" style="font-size:13px"><?= e(ago($w['created_at'])) ?></span>
        </div>

        <div class="admin__meta" style="margin-bottom:12px">
            <span>Пользователь</span>
            <strong>
                <a href="<?= e(url('/profile/' . rawurlencode($w['username']))) ?>" target="_blank"><?= e($w['username']) ?></a>
                <span class="muted">(на балансе <?= e(flames_format((float) $w['balance'])) ?>)</span>
            </strong>

            <span>ФИО</span><strong><?= e($w['full_name']) ?></strong>
            <span>Телефон</span><strong><?= e($w['phone']) ?></strong>
            <span>Банк</span><strong><?= e($w['bank']) ?></strong>
            <span>Реквизиты</span><strong style="word-break:break-all"><?= e($w['requisites']) ?></strong>

            <?php if ($w['comment']): ?>
                <span>Комментарий</span><strong><?= e($w['comment']) ?></strong>
            <?php endif; ?>
        </div>

        <?php if ($w['status'] === 'pending'): ?>
            <div class="row">
                <form method="POST" action="<?= e(url('/admin/vyplaty/' . (int) $w['id'] . '/vyplacheno')) ?>"
                      onsubmit="return confirm('Отметить как выплаченное? Огоньки уже списаны.')">
                    <?= csrf_field() ?>
                    <button class="btn btn--primary btn--sm"><?= icon('check') ?> Выплачено</button>
                </form>
                <form method="POST" action="<?= e(url('/admin/vyplaty/' . (int) $w['id'] . '/otklonit')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--sm">Отклонить и вернуть Огоньки</button>
                </form>
            </div>
        <?php else: ?>
            <p class="muted" style="margin:0;font-size:13px">
                <?= e(withdrawal_status_label($w['status'])) ?> · <?= e(ago($w['resolved_at'])) ?>
            </p>
        <?php endif; ?>
    </div></div>
<?php endforeach; ?>
