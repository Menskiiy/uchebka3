<?php
/**
 * Правка технического аккаунта.
 *
 * Под ботом нельзя войти: пароль ему генерируется случайным и нигде
 * не сохраняется. Поэтому единственный способ поменять ему имя, описание
 * или аватар — этот экран.
 */
?>
<div class="admin__cols">
    <div>
        <div class="card"><div class="card__body">
            <?php if ($errors): ?>
                <div class="alert alert--error">
                    <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="row" style="margin-bottom:14px">
                <a href="<?= e(url('/admin/polzovateli')) ?>" class="btn btn--sm btn--ghost">&#8249; Пользователи</a>
                <span class="spacer"></span>
                <a href="<?= e(url('/profile/' . rawurlencode($user['username']))) ?>" class="btn btn--sm btn--ghost" target="_blank">
                    Профиль на сайте
                </a>
            </div>

            <form method="POST" action="<?= e(url('/admin/polzovateli/' . (int) $user['id'] . '/bot')) ?>"
                  enctype="multipart/form-data">
                <?= csrf_field() ?>

                <div class="row" style="gap:16px;align-items:center;margin-bottom:16px">
                    <span class="avatar avatar--lg">
                        <?php if ($user['avatar']): ?>
                            <img src="<?= e(url('/storage/' . $user['avatar'])) ?>" alt="">
                        <?php else: ?>
                            <?= e(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?>
                        <?php endif; ?>
                    </span>

                    <div class="stack" style="gap:6px">
                        <input type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp"
                               data-max-size="<?= min(2 * 1024 * 1024, upload_limit_bytes()) ?>">
                        <div class="hint">PNG, JPG, GIF или WEBP до 2 МБ.</div>

                        <?php if ($user['avatar']): ?>
                            <label class="check">
                                <input type="checkbox" name="remove_avatar" value="1"> Убрать аватар
                            </label>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="field">
                    <label for="username">Имя</label>
                    <input type="text" id="username" name="username" class="input" required
                           value="<?= e($user['username']) ?>">
                    <div class="hint">
                        Если это публикатор Steam, имя в настройках бота поменяется вместе с ним —
                        иначе при следующем запуске бот завёл бы себе новый аккаунт.
                    </div>
                </div>

                <div class="field">
                    <label for="bio">Описание</label>
                    <textarea id="bio" name="bio" class="input" rows="3"><?= e($user['bio'] ?? '') ?></textarea>
                    <div class="hint">Видно в профиле. Честно напишите, что публикации автоматические.</div>
                </div>

                <div class="field">
                    <label for="reputation">Репутация</label>
                    <input type="number" id="reputation" name="reputation" class="input" min="0"
                           style="max-width:160px" value="<?= (int) $user['reputation'] ?>">
                </div>

                <button class="btn btn--primary">Сохранить</button>
            </form>
        </div></div>
    </div>

    <div class="card admin__sticky"><div class="card__body">
        <h2 class="card__title">Технические аккаунты</h2>

        <p class="hint" style="margin-top:0">
            Под таким аккаунтом невозможно войти: пароль ему выдан случайный и нигде
            не сохранён. Поэтому его профиль правится здесь.
        </p>

        <p class="hint">
            Человеческие профили из панели не редактируются намеренно. Это чужие данные,
            а не настройка сайта: у человека есть свои «Настройки профиля».
        </p>

        <p class="hint">
            Пометить или снять пометку можно в списке пользователей.
        </p>
    </div></div>
</div>
