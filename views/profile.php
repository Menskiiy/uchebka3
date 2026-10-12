<div class="layout layout--aside-sm">
    <aside class="card"><div class="card__body" style="text-align:center">
        <div class="avatar avatar--lg" style="margin:0 auto 12px">
            <?php if ($user['avatar']): ?>
                <img src="<?= e(url('/storage/' . $user['avatar'])) ?>" alt="">
            <?php else: ?>
                <?= e(initials($user)) ?>
            <?php endif; ?>
        </div>

        <h1 style="font-size:19px;margin:0"><?= e($user['username']) ?></h1>
        <p class="muted" style="margin:4px 0 12px"><?= (int) $user['reputation'] ?> репутации</p>

        <div class="progress"><span style="width:<?= rank_progress((int) $user['reputation']) ?>%"></span></div>
        <p style="margin:8px 0 0;font-size:14px">Ранг: <strong><?= e(rank_for((int) $user['reputation'])['title']) ?></strong></p>

        <?php if ($user['bio']): ?>
            <p class="muted" style="margin-top:14px;font-size:13px"><?= e($user['bio']) ?></p>
        <?php endif; ?>

        <p class="muted" style="margin-top:14px;font-size:13px">Зарегистрирован(а) <?= e(ago($user['created_at'])) ?></p>

        <div class="row" style="justify-content:center;margin-top:14px">
            <span class="badge">Публикаций: <?= (int) $counts['threads'] ?></span>
            <span class="badge">Комментариев: <?= (int) $counts['comments'] ?></span>
            <?php if ((int) $user['is_moderator'] === 1): ?><span class="badge badge--merchant">модератор</span><?php endif; ?>
            <?php if ((int) ($user['is_editor'] ?? 0) === 1): ?><span class="badge">редактор</span><?php endif; ?>
            <?php if (is_bot_account($user)): ?><span class="badge">бот</span><?php endif; ?>
        </div>

        <div class="row" style="justify-content:center;margin-top:10px;gap:14px">
            <span class="muted" style="font-size:13px">
                Подписчиков: <strong><?= followers_count('user', (int) $user['id']) ?></strong>
            </span>
        </div>

        <?php if (auth_id() === (int) $user['id']): ?>
            <a href="<?= e(url('/balans')) ?>" class="btn btn--primary btn--block" style="margin-top:14px">
                <?= icon('flame') ?> Баланс: <?= e(flames_format((float) $user['balance'])) ?>
            </a>
            <a href="<?= e(url('/nastroyki')) ?>" class="btn btn--ghost btn--block" style="margin-top:8px">Редактировать профиль</a>
        <?php elseif (auth_id()): ?>
            <form method="POST" action="<?= e(url('/podpiska')) ?>" style="margin-top:14px">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="user">
                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                <?php $following = is_following('user', (int) $user['id']); ?>
                <button class="btn btn--block <?= $following ? 'btn--ghost' : 'btn--primary' ?>">
                    <?= icon($following ? 'check' : 'plus') ?>
                    <?= $following ? 'Вы подписаны' : 'Подписаться' ?>
                </button>
            </form>
            <p class="hint" style="text-align:center;margin-top:6px">
                <?= $following ? 'Уведомим о новых публикациях' : 'Уведомления о новых публикациях' ?>
            </p>
        <?php endif; ?>
    </div></aside>

    <div>
        <div class="tabs tabs--sticky">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="<?= e(url('/profile/' . rawurlencode($user['username']) . '/' . $key)) ?>"
                   class="<?= $tab === $key ? 'is-active' : '' ?>">
                    <?= e($label) ?><?= isset($counts[$key]) ? ' (' . (int) $counts[$key] . ')' : '' ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($tab === 'ranks'): ?>
            <div class="card"><div class="card__body">
                <h3 class="card__title">Система рангов</h3>
                <?php foreach (cfg('ranks') as $rank): ?>
                    <div class="row" style="padding:10px 0;border-bottom:1px solid var(--border)">
                        <strong <?= rank_for((int) $user['reputation'])['key'] === $rank['key'] ? 'style="color:var(--brand)"' : '' ?>><?= e($rank['title']) ?></strong>
                        <span class="spacer"></span>
                        <span class="muted">от <?= (int) $rank['min'] ?> репутации</span>
                    </div>
                <?php endforeach; ?>
            </div></div>

        <?php elseif ($tab === 'comments'): ?>
            <div class="card"><div class="card__body">
                <?php if (! $comments): ?><p class="muted">Комментариев пока нет.</p><?php endif; ?>
                <?php foreach ($comments as $c): ?>
                    <div class="comment">
                        <a href="<?= e(url('/post/' . rawurlencode($c['thread_slug']))) ?>" style="font-weight:600"><?= e($c['thread_title']) ?></a>
                        <div class="comment__body prose"><?= render_body($c['body']) ?></div>
                        <span class="muted" style="font-size:13px"><?= e(ago($c['created_at'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div></div>

        <?php else: ?>
            <?php if (! $result['items']): ?>
                <div class="card"><div class="empty">
                    <?php if ($tab === 'blog'): ?>
                        Записей в блоге пока нет.
                        <?php if (auth_id() === (int) $user['id']): ?>
                            <div style="margin-top:12px">
                                <a href="<?= e(url('/dobavit/blog')) ?>" class="btn btn--primary btn--sm">Написать первую</a>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        Здесь пока пусто.
                    <?php endif; ?>
                </div></div>
            <?php endif; ?>

            <?php foreach ($result['items'] as $t) echo c_thread_card($t); ?>
            <?= c_pagination($result['pager']) ?>
        <?php endif; ?>
    </div>
</div>
