<?php
/**
 * Личный кабинет.
 *
 * Одна колонка постоянной ширины: раньше форма была узкой, а карточки под
 * ней — во всю страницу, и раздел выглядел собранным из кусков. Порядок
 * блоков от частого к редкому: профиль, письма, документы, удаление.
 */
$myConsents = user_consents((int) $me['id']);
?>
<div class="page settings">

    <div class="card"><div class="card__body">
        <h1 class="settings__title">Настройки профиля</h1>

        <?php if ($errors): ?>
            <div class="alert alert--error"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= e(url('/nastroyki')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="avatar-edit">
                <span class="avatar avatar--lg" id="avatar-preview">
                    <?php if ($me['avatar']): ?>
                        <img src="<?= e(url('/storage/' . $me['avatar'])) ?>" alt="">
                    <?php else: ?>
                        <?= e(initials($me)) ?>
                    <?php endif; ?>
                </span>

                <div class="avatar-edit__side">
                    <label class="btn btn--sm" for="avatar-input">Загрузить фото</label>
                    <input type="file" name="avatar" id="avatar-input" class="visually-hidden"
                           accept="image/png,image/jpeg,image/gif,image/webp"
                           data-max-size="<?= min(2 * 1024 * 1024, upload_limit_bytes()) ?>">

                    <div class="hint">PNG, JPG, GIF или WEBP до 2 МБ. Квадратная картинка смотрится лучше.</div>

                    <?php if ($me['avatar']): ?>
                        <label class="switch">
                            <input type="checkbox" name="remove_avatar" value="1">
                            <span>Удалить текущий аватар</span>
                        </label>
                    <?php endif; ?>
                </div>
            </div>

            <div class="field">
                <label for="username">Имя пользователя</label>
                <input type="text" id="username" name="username" class="input" value="<?= e($me['username']) ?>" required>
            </div>

            <div class="field">
                <label for="bio">О себе</label>
                <textarea id="bio" name="bio" class="input" rows="3" maxlength="500"><?= e($me['bio'] ?? '') ?></textarea>
                <div class="hint">Видно в вашем профиле. До 500 символов.</div>
            </div>

            <h2 class="settings__subtitle">Письма</h2>

            <label class="switch">
                <input type="checkbox" name="email_notifications" value="1"
                       <?= (int) ($me['email_notifications'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span>
                    Ответы и решения модератора
                    <small>Служебные письма: кто-то ответил, публикацию одобрили или сняли.</small>
                </span>
            </label>

            <label class="switch">
                <input type="checkbox" name="ads_subscribed" value="1"
                       <?= (int) ($me['ads_subscribed'] ?? 0) === 1 ? 'checked' : '' ?>>
                <span>
                    Скидки, которые скоро сгорают
                    <small>
                        Рекламная рассылка, не чаще раза в день. Согласие отзывается здесь
                        или ссылкой в письме — на остальные функции сайта это не влияет.
                    </small>
                </span>
            </label>

            <button class="btn btn--primary" style="margin-top:18px">Сохранить</button>
        </form>
    </div></div>

    <div class="card"><div class="card__body">
        <h2 class="card__title">Документы и согласия</h2>

        <p class="hint" style="margin:-4px 0 14px">
            Действующие редакции всех документов — в разделе
            <a href="<?= e(url('/dokumenty')) ?>">«Документы»</a>.
        </p>

        <?php foreach (['terms', 'privacy', 'consent', 'loyalty', 'blocking', 'content'] as $key): ?>
            <?php $doc = legal_page($key); ?>
            <a href="<?= e(url($doc['url'])) ?>" class="doc-item">
                <span class="doc-item__title"><?= e($doc['title']) ?></span>
                <span class="doc-item__meta">Редакция от <?= e($doc['updated']) ?></span>
            </a>
        <?php endforeach; ?>

        <?php if ($myConsents): ?>
            <h3 class="settings__subtitle">Что вы приняли</h3>

            <?php foreach ($myConsents as $c): ?>
                <div class="doc-item">
                    <span class="doc-item__title">
                        <?= e(consent_label($c['kind'], $c['doc_key'])) ?>
                        <?php if ($c['choice'] === 'declined'): ?>
                            <span class="role-declined">отказ</span>
                        <?php endif; ?>
                    </span>
                    <span class="doc-item__meta">
                        <?= e(date('d.m.Y', strtotime($c['created_at']))) ?>
                        <?php if ($c['doc_version']): ?> · редакция <?= e($c['doc_version']) ?><?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div></div>

    <div class="card card--danger"><div class="card__body">
        <h2 class="card__title">Удаление аккаунта</h2>

        <p class="hint" style="margin:-4px 0 14px">
            Имя, почта и аватар будут удалены безвозвратно, вход прекратится, накопленные
            Огоньки сгорают. Опубликованные скидки, промокоды и комментарии останутся на
            сайте, но перестанут быть связаны с вами: их читают другие люди, и удалять
            чужие обсуждения вместе с аккаунтом неправильно.
        </p>

        <details class="danger-zone">
            <summary class="btn btn--sm btn--danger">Удалить аккаунт</summary>

            <form method="POST" action="<?= e(url('/nastroyki/udalit-akkaunt')) ?>" class="danger-zone__form">
                <?= csrf_field() ?>

                <div class="field">
                    <label for="confirm">Введите слово УДАЛИТЬ, чтобы подтвердить</label>
                    <input type="text" id="confirm" name="confirm" class="input" style="max-width:220px"
                           autocomplete="off" placeholder="УДАЛИТЬ">
                </div>

                <button class="btn btn--sm btn--danger">Удалить аккаунт навсегда</button>
            </form>
        </details>
    </div></div>

</div>
