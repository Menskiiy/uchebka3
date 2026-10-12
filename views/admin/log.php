<div class="card"><div class="card__body">
    <?php if (! $result['items']): ?><p class="muted">Журнал пуст.</p><?php endif; ?>

    <?php foreach ($result['items'] as $entry): ?>
        <div class="admin__row" style="display:block;padding:11px 0;border-bottom:1px solid var(--border)">
            <div class="row">
                <span class="badge"><?= e(action_label($entry['action'])) ?></span>
                <span class="muted"><?= e($entry['moderator_name'] ?? '—') ?></span>
                <span class="spacer"></span>
                <span class="muted" style="font-size:13px"><?= e(date('d.m.Y H:i', strtotime($entry['created_at']))) ?></span>
            </div>

            <?php if ($entry['payload']): $data = json_decode($entry['payload'], true) ?: []; ?>
                <p class="muted" style="margin:6px 0 0;font-size:13px">
                    <?php
                    $parts = [];
                    foreach ($data as $k => $v) {
                        if ($v !== null && $v !== '') {
                            $parts[] = e($k) . ': ' . e(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v);
                        }
                    }
                    echo implode(' · ', $parts);
                    ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div></div>

<?= c_pagination($result['pager']) ?>
