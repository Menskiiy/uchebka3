<div class="tabs" style="margin-bottom:14px">
    <?php foreach (['open' => 'На рассмотрении', 'accepted' => 'Принятые', 'dismissed' => 'Отклонённые'] as $key => $label): ?>
        <a href="<?= e(url('/admin/zhaloby?status=' . $key)) ?>" class="<?= $status === $key ? 'is-active' : '' ?>">
            <?= e($label) ?> (<?= (int) $counts[$key] ?>)
        </a>
    <?php endforeach; ?>
</div>

<?php if (! $reports): ?>
    <div class="card"><div class="empty">Жалоб в этой категории нет.</div></div>
<?php endif; ?>

<?php foreach ($reports as $r): ?>
    <div class="card"><div class="card__body">
        <div class="row" style="margin-bottom:8px">
            <span class="badge badge--merchant"><?= e(cfg('report_reasons')[$r['reason']] ?? $r['reason']) ?></span>
            <span class="muted">от <?= e($r['reporter_name']) ?></span>
            <span class="spacer"></span>
            <span class="muted" style="font-size:13px"><?= e(ago($r['created_at'])) ?></span>
        </div>

        <p style="margin:0 0 6px;font-weight:600">
            <?php if ($r['subject_url']): ?>
                <a href="<?= e($r['subject_url']) ?>" target="_blank"><?= e($r['subject_title']) ?></a>
            <?php else: ?>
                <span class="muted"><?= e($r['subject_title']) ?></span>
            <?php endif; ?>
        </p>

        <?php if ($r['comment']): ?>
            <p class="muted" style="margin:0 0 10px;font-size:14px">«<?= e($r['comment']) ?>»</p>
        <?php endif; ?>

        <?php if ($r['status'] === 'open'): ?>
            <div class="row">
                <form method="POST" action="<?= e(url('/admin/zhaloby/' . (int) $r['id'] . '/prinyat')) ?>"><?= csrf_field() ?>
                    <button class="btn btn--primary btn--sm">Принять</button>
                </form>
                <form method="POST" action="<?= e(url('/admin/zhaloby/' . (int) $r['id'] . '/otklonit')) ?>"><?= csrf_field() ?>
                    <button class="btn btn--sm">Отклонить</button>
                </form>
                <?php if ($r['reportable_type'] === 'thread' && $r['subject_id']): ?>
                    <a href="<?= e(url('/admin/publikacii/' . (int) $r['subject_id'])) ?>" class="btn btn--sm btn--ghost">Открыть в редакторе</a>
                    <form method="POST" action="<?= e(url('/admin/publikacii/' . (int) $r['subject_id'] . '/snyat')) ?>"><?= csrf_field() ?>
                        <button class="btn btn--sm">Снять публикацию</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="muted" style="margin:0;font-size:13px">
                <?= $r['status'] === 'accepted' ? 'Принята' : 'Отклонена' ?>
                · <?= e($r['resolver_name'] ?? '—') ?> · <?= e(ago($r['resolved_at'])) ?>
            </p>
        <?php endif; ?>
    </div></div>
<?php endforeach; ?>
