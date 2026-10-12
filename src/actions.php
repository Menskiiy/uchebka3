<?php
/** Обработчики форм: голоса, комментарии, публикация, авторизация, модерация. */

function act_vote(string $slug): void
{
    csrf_check();
    $me = require_auth();

    $thread = q1('SELECT * FROM threads WHERE slug = ?', [$slug]);

    if (! $thread) {
        json_out(['error' => 'Публикация не найдена'], 404);
    }

    if ((int) $thread['user_id'] === (int) $me['id']) {
        json_out(['error' => 'Нельзя голосовать за собственную публикацию.'], 422);
    }

    $direction = input('direction');

    if (! in_array($direction, ['hot', 'cold'], true)) {
        json_out(['error' => 'Неверное направление'], 422);
    }

    $result = cast_vote((int) $me['id'], 'thread', (int) $thread['id'], $direction, (int) $thread['user_id']);
    $temp   = refresh_temperature((int) $thread['id']);

    if (is_ajax()) {
        json_out(['temperature' => $temp, 'direction' => $result]);
    }

    back();
}

/**
 * Идемпотентно: повторный клик по той же кнопке снимает голос,
 * клик по противоположной — переключает.
 */
function cast_vote(int $userId, string $type, int $id, string $direction, int $authorId): ?string
{
    $existing = q1('SELECT * FROM votes WHERE user_id = ? AND votable_type = ? AND votable_id = ?',
        [$userId, $type, $id]);

    $reward = static function (string $dir, int $sign) use ($type, $authorId) {
        $amount = $type === 'thread'
            ? ($dir === 'hot' ? cfg('reputation.per_hot_vote') : cfg('reputation.per_cold_vote'))
            : cfg('reputation.per_comment_like');

        add_reputation($authorId, (int) $amount * $sign);
    };

    if ($existing && $existing['direction'] === $direction) {
        qx('DELETE FROM votes WHERE id = ?', [$existing['id']]);
        $reward($direction, -1);
        revoke_vote_flames((int) $existing['id']);

        return null;
    }

    if ($existing) {
        $reward($existing['direction'], -1);

        // голос переключили: прежнее начисление за него снимаем
        revoke_vote_flames((int) $existing['id']);
        update('votes', (int) $existing['id'], ['direction' => $direction]);
        $voteId = (int) $existing['id'];
    } else {
        $voteId = insert('votes', [
            'user_id'      => $userId,
            'votable_type' => $type,
            'votable_id'   => $id,
            'direction'    => $direction,
            'created_at'   => now(),
        ]);
    }

    $reward($direction, 1);

    // Огоньки считаем только по голосам за публикации: лайки комментариев
    // дают репутацию, но не деньги
    if ($type === 'thread') {
        award_vote($authorId, $id, $voteId, $direction);
    }

    return $direction;
}

function act_comment_like(int $id): void
{
    csrf_check();
    $me = require_auth();

    $comment = q1('SELECT * FROM comments WHERE id = ?', [$id]);

    if (! $comment) {
        back();
    }

    cast_vote((int) $me['id'], 'comment', $id, 'hot', (int) $comment['user_id']);

    $likes = (int) qv("SELECT COUNT(*) FROM votes WHERE votable_type = 'comment' AND votable_id = ?", [$id]);
    update('comments', $id, ['likes_count' => $likes]);

    notify((int) $comment['user_id'], 'comment_like', [
        'thread_id'  => (int) $comment['thread_id'],
        'comment_id' => $id,
    ]);

    if (is_ajax()) {
        json_out(['likes' => $likes, 'liked' => my_vote('comment', $id) !== null]);
    }

    back();
}

function act_bookmark(string $slug): void
{
    csrf_check();
    $me = require_auth();

    $thread = q1('SELECT id FROM threads WHERE slug = ?', [$slug]);

    if (! $thread) {
        back();
    }

    $existing = q1('SELECT id FROM bookmarks WHERE user_id = ? AND thread_id = ?', [$me['id'], $thread['id']]);

    if ($existing) {
        qx('DELETE FROM bookmarks WHERE id = ?', [$existing['id']]);
    } else {
        insert('bookmarks', ['user_id' => $me['id'], 'thread_id' => $thread['id'], 'created_at' => now()]);
    }

    if (is_ajax()) {
        json_out(['saved' => ! $existing]);
    }

    back();
}

function act_comment_store(string $slug): void
{
    csrf_check();
    $me = require_auth();

    $thread = q1('SELECT * FROM threads WHERE slug = ?', [$slug]);

    if (! $thread) {
        redirect('/');
    }

    $body = (string) input('body');

    if (mb_strlen(trim(strip_tags($body, '<img>'))) < 2) {
        if (is_ajax()) {
            json_out(['error' => 'Комментарий не может быть пустым.'], 422);
        }

        flash('Комментарий не может быть пустым.', 'error');
        back();
    }

    $parentId = (int) input('parent_id', 0);

    if ($parentId > 0) {
        $parent = q1('SELECT id FROM comments WHERE id = ? AND thread_id = ?', [$parentId, $thread['id']]);
        if (! $parent) { $parentId = 0; }
    }

    $id = insert('comments', [
        'thread_id'  => $thread['id'],
        'user_id'    => $me['id'],
        'parent_id'  => $parentId ?: null,
        'body'       => clean_html(mb_substr($body, 0, 8000)),
        'created_at' => now(),
    ]);

    qx('UPDATE threads SET comments_count = comments_count + 1 WHERE id = ?', [$thread['id']]);

    // автору темы — про комментарий, автору комментария — про ответ
    if ($parentId > 0) {
        $parentAuthor = (int) qv('SELECT user_id FROM comments WHERE id = ?', [$parentId]);
        notify($parentAuthor, 'reply', ['thread_id' => (int) $thread['id'], 'comment_id' => $id]);

        if ((int) $thread['user_id'] !== $parentAuthor) {
            notify((int) $thread['user_id'], 'comment', ['thread_id' => (int) $thread['id'], 'comment_id' => $id]);
        }
    } else {
        notify((int) $thread['user_id'], 'comment', ['thread_id' => (int) $thread['id'], 'comment_id' => $id]);
    }

    if (is_ajax()) {
        $comment = q1('SELECT c.*, u.username AS author_name, u.avatar AS author_avatar, u.reputation AS author_reputation,
           u.is_moderator AS author_moderator, u.is_editor AS author_editor, u.is_bot AS author_bot
                       FROM comments c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$id]);

        json_out([
            'id'       => $id,
            'parent'   => $parentId,
            // глубина нужна, чтобы свежий ответ сразу встал с отступом
            'html'     => c_comments([0 => [$comment]], $thread, 0, comment_depth($id)),
            'total'    => (int) qv('SELECT comments_count FROM threads WHERE id = ?', [$thread['id']]),
        ]);
    }

    redirect(thread_path($thread, '#comment-' . $id));
}

function act_comment_delete(int $id): void
{
    csrf_check();
    $me = require_auth();

    $comment = q1('SELECT * FROM comments WHERE id = ?', [$id]);

    if (! $comment) {
        back();
    }

    if ((int) $comment['user_id'] !== (int) $me['id'] && ! is_moderator()) {
        http_response_code(403);
        exit('Нет прав на удаление.');
    }

    // Чужой комментарий удаляет модератор — действие уходит в журнал
    if ((int) $comment['user_id'] !== (int) $me['id']) {
        mod_log('comment_deleted', [
            'текст'  => mb_substr($comment['body'], 0, 200),
            'автор'  => qv('SELECT username FROM users WHERE id = ?', [$comment['user_id']]),
        ]);
    }

    /*
     * Удаляем всю ветку: ответы без родителя не строятся в дерево и
     * исчезают со страницы, но остаются в базе и продолжают числиться
     * в счётчике. Заодно убираем их лайки — голоса за несуществующий
     * комментарий не нужны никому.
     */
    $ids   = comment_branch_ids($id);
    $marks = implode(',', array_fill(0, count($ids), '?'));

    qx("DELETE FROM votes WHERE votable_type = 'comment' AND votable_id IN ({$marks})", $ids);
    qx("DELETE FROM notifications WHERE comment_id IN ({$marks})", $ids);
    qx("DELETE FROM comments WHERE id IN ({$marks})", $ids);

    $total = refresh_comments_count((int) $comment['thread_id']);

    if (is_ajax()) {
        json_out([
            'deleted' => $id,
            'ids'     => $ids,
            'total'   => $total,
        ]);
    }

    flash(count($ids) > 1
        ? 'Комментарий удалён вместе с ' . (count($ids) - 1) . ' ' . plural(count($ids) - 1, 'ответом', 'ответами', 'ответами') . '.'
        : 'Комментарий удалён.');

    back();
}

function act_publish(string $typeSlug): void
{
    csrf_check();
    $me   = require_auth();
    $type = type_by_slug($typeSlug);

    if (! $type) {
        http_response_code(404);
        exit('Неизвестный тип публикации.');
    }

    /*
     * Текст приходит блоками из редактора (body_json). HTML для колонки body
     * собирает сервер — разметке из браузера не доверяем. Сырой JSON кладём
     * в $data, чтобы после ошибки форма открылась с тем же текстом.
     */
    $bodyInput = blocks_input();

    $data = [
        'title'            => (string) input('title'),
        'body'             => $bodyInput['html'],
        'body_json'        => (string) ($_POST['body_json'] ?? ''),
        'url'              => (string) input('url'),
        'code'             => (string) input('code'),
        'image_url'        => (string) input('image_url'),
        'price'            => input('price'),
        'old_price'        => input('old_price'),
        'discount_percent' => input('discount_percent'),
        'group_id'         => input('group_id'),
        'merchant_name'    => input('merchant_name'),
    ];

    $errors = [];

    if (mb_strlen($data['title']) < 8) {
        $errors[] = 'Заголовок слишком короткий — минимум 8 символов.';
    }

    if ($type === 'voucher' && $data['code'] === '') {
        $errors[] = 'Введите промокод.';
    }

    if ($bodyInput['error']) {
        $errors[] = $bodyInput['error'];
    } elseif (in_array($type, ['discussion', 'feedback', 'blog'], true) && mb_strlen(trim(strip_tags($data['body']))) < 10) {
        $errors[] = 'Опишите подробнее — без описания пост не поймут.';
    }

    if ($type === 'discussion' && ! $data['group_id']) {
        $errors[] = 'Выберите раздел обсуждения.';
    }

    /*
     * Ссылку сначала приводим к нормальному виду: кириллица в параметрах
     * (промокод вида &kc=ИНВЕСТОР) законна, браузер её открывает, а строгая
     * проверка PHP — нет.
     *
     * Если после этого адрес всё равно не проходит, но на ссылку похож —
     * не отказываем, а отправляем публикацию модератору: странный адрес это
     * повод посмотреть глазами, а не повод терять готовый пост.
     */
    $suspiciousUrl = false;

    if ($data['url'] !== '') {
        $clean = normalize_url($data['url']);

        if ($clean !== null) {
            $data['url'] = $clean;
        } elseif (looks_like_url($data['url'])) {
            $suspiciousUrl = true;
        } else {
            $errors[] = 'Ссылка указана неверно — нужен адрес вида https://magazin.ru/tovar.';
        }
    }

    // Ограничение частоты — против спама от новых аккаунтов.
    // Модераторов не касается: они наполняют сайт и упирались в него постоянно.
    if (! is_moderator()) {
        $recent = (int) qv('SELECT COUNT(*) FROM threads WHERE user_id = ? AND created_at > ?',
            [$me['id'], date('Y-m-d H:i:s', time() - 3600)]);

        if ($recent >= 5) {
            $errors[] = 'Слишком много публикаций за час. Попробуйте позже.';
        }

        // Предупреждаем один раз: если человек отправит форму снова,
        // значит он уверен, что предложение другое, — пропускаем.
        if (empty($_SESSION['dup_warned']) && ($twin = find_duplicate($data['url'], $data['code'], $data['title']))) {
            $errors[] = 'Похоже, такое уже публиковали: «' . excerpt($twin['title'], 60)
                . '». Если ваше предложение отличается — нажмите «Опубликовать» ещё раз.';

            $_SESSION['dup_warned'] = true;
        }
    }

    if ($errors) {
        errors_set($errors, $data);
        redirect('/dobavit/' . $typeSlug);
    }

    $hasPrice     = $type === 'deal';
    $supportsImage = is_commercial($type) || $type === 'blog';
    $imagePath    = null;

    if (! empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $imagePath = store_image($_FILES['image'], 'threads');
    }

    $commercial = is_commercial($type);

    /*
     * Магазин: сначала явный выбор из списка. Автоопределение по домену
     * остаётся запасным вариантом — партнёрские ссылки вида t-cpa.ru/xxx
     * ведут на редиректор, и по ним магазин не угадать.
     */
    $merchant = null;

    if ($commercial) {
        // порядок важен: явно названный магазин важнее догадки по домену
        $chosen = (int) input('merchant_id', 0);

        $merchant = $chosen > 0
            ? q1('SELECT * FROM merchants WHERE id = ?', [$chosen])
            : (merchant_by_name(input('merchant_name')) ?: merchant_by_domain($data['url'] ?: null));
    }

    // срок задаёт автор; пустое поле — предложение без срока
    $expires = null;

    if ($commercial) {
        $raw = (string) input('expires_at');

        if ($raw !== '' && ($ts = strtotime($raw)) !== false) {
            $ts      = min($ts, time() + 730 * 86400);   // не дальше двух лет
            $expires = date('Y-m-d', $ts) . ' 23:59:59';
        }
    }

    /*
     * Всё, что попадает в ленту и в поиск — скидки, промокоды, блоги —
     * уходит на проверку. Обсуждения не трогаем: это переписка внутри
     * сайта, премодерация убила бы в ней всякий смысл.
     *
     * Редакторы и модераторы публикуются сразу: им проверять нечего,
     * они сами и есть проверка. Настройкой moderate_all очередь можно
     * выключить целиком, пока сайт наполняется своими силами.
     */
    // отметки из формы проверяем на сервере: скрытое поле в браузере
    // отключается за пять секунд, а отвечать за содержание всё равно автору
    if (! input('confirm_accuracy') || ! input('confirm_not_ad')) {
        errors_set(['Подтвердите достоверность сведений и рекламный статус материала.'], $data);
        redirect('/dobavit/' . (cfg('thread_types')[$type]['slug'] ?? ''));
    }

    $status = 'published';

    $needsReview = in_array($type, ['deal', 'voucher', 'blog'], true)
        && setting('moderate_all', '1') === '1'
        && ! is_editor()
        && ! is_moderator();

    // адрес, который не удалось разобрать, проверяет человек — даже у редактора
    if ($suspiciousUrl) {
        $needsReview = true;
    }

    if ($needsReview) {
        $status = 'pending';
    }

    $id = insert('threads', [
        'type'             => $type,
        'status'           => $status,
        'user_id'          => $me['id'],
        'merchant_id'      => $merchant['id'] ?? null,
        'group_id'         => $type === 'discussion' ? (int) $data['group_id'] : null,
        'title'            => mb_substr($data['title'], 0, 180),
        'slug'             => slugify($data['title']),
        'body'             => clean_html($data['body']) ?: null,
        'body_json'        => $bodyInput['json'],
        'url'              => $commercial && $data['url'] ? $data['url'] : null,
        'code'             => $type === 'voucher' ? $data['code'] : null,
        'image_url'        => $supportsImage && $data['image_url'] ? $data['image_url'] : null,
        'image_path'       => $imagePath,
        // цены имеют смысл только у скидки: у промокода выгода в самом коде
        'price'            => $hasPrice && $data['price'] !== '' && $data['price'] !== null ? (float) $data['price'] : null,
        'old_price'        => $hasPrice && $data['old_price'] !== '' && $data['old_price'] !== null ? (float) $data['old_price'] : null,
        'discount_percent' => $hasPrice && $data['discount_percent'] ? (int) $data['discount_percent'] : null,
        'temperature'      => 0,
        'expires_at'       => $expires,
        // маркировку проставляет только модератор: это юридическая пометка,
        // а не поле для произвольного текста
        'ad_marking'       => is_moderator() ? (mb_substr((string) input('ad_marking'), 0, 500) ?: null) : null,
        'published_at'     => now(),
        'created_at'       => now(),
    ]);

    foreach ((array) ($_POST['categories'] ?? []) as $categoryId) {
        if (ctype_digit((string) $categoryId)) {
            insert('category_thread', ['thread_id' => $id, 'category_id' => (int) $categoryId]);
        }
    }

    store_thread_images($_FILES['images'] ?? null, $id);

    $slug = qv('SELECT slug FROM threads WHERE id = ?', [$id]);

    unset($_SESSION['dup_warned']);

    // репутация и Огоньки — только за то, что реально попало в ленту
    if ($status === 'published') {
        award_publication($id);
        notify_followers($id);
    }

    if ($status === 'pending') {
        flash($suspiciousUrl
            ? 'Публикация отправлена на проверку: ссылку не удалось разобрать автоматически, '
              . 'её посмотрит модератор. Проверьте, что адрес скопирован целиком.'
            : 'Спасибо! Публикация отправлена на проверку. Пока идёт проверка, её видите только вы — '
              . 'ссылку на неё можно открыть из профиля.');
    } else {
        // «Скидка опубликована», но «Блог опубликован» и «Промокод опубликован»
        $done = in_array($type, ['voucher', 'blog'], true) ? ' опубликован' : ' опубликована';
        flash(type_label($type) . $done . '. Спасибо!');
    }

    redirect(thread_path(find_thread((string) $slug) ?: (string) $slug));
}

/** Сохранение картинки. Тип проверяем по содержимому, а не по расширению. */
function store_image(array $file, string $folder = 'threads', int $maxBytes = 4194304): ?string
{
    if ($file['size'] > $maxBytes) {
        return null;
    }

    $info = @getimagesize($file['tmp_name']);

    if ($info === false) {
        return null;
    }

    $ext = match ($info[2]) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
        default        => null,
    };

    if ($ext === null) {
        return null;
    }

    $relative = $folder . '/' . date('Y/m');
    $dir      = cfg('uploads') . '/' . $relative;

    if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
        return null;
    }

    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $name;

    if (! move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }

    // Фотография с телефона весит 3–5 МБ и в таком виде уходит каждому
    // посетителю. Уменьшаем до разумного размера — обычно это 4 МБ в 200 КБ.
    shrink_image($dest, $info[2]);

    return $relative . '/' . $name;
}

/** Уменьшить картинку по длинной стороне и пережать. Без GD просто ничего не делает. */
function shrink_image(string $path, int $type, int $maxSide = 1400): void
{
    if (! function_exists('imagecreatetruecolor')) {
        return;
    }

    $size = @getimagesize($path);

    if (! $size || ($size[0] <= $maxSide && $size[1] <= $maxSide && filesize($path) < 400 * 1024)) {
        return;
    }

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_GIF  => @imagecreatefromgif($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default        => false,
    };

    if (! $src) {
        return;
    }

    [$w, $h] = $size;
    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);

    // прозрачность у PNG и GIF иначе станет чёрной заливкой
    if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    match ($type) {
        IMAGETYPE_JPEG => imagejpeg($dst, $path, 82),
        IMAGETYPE_PNG  => imagepng($dst, $path, 7),
        IMAGETYPE_GIF  => imagegif($dst, $path),
        IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($dst, $path, 82) : null,
        default        => null,
    };

    imagedestroy($src);
    imagedestroy($dst);
}

function act_login(): void
{
    csrf_check();

    /*
     * Капча на входе нужна не меньше, чем на регистрации: именно форму входа
     * перебирают паролями. Ответ одноразовый, поэтому повторить один и тот же
     * запрос скриптом не выйдет.
     */
    if (! captcha_check()) {
        errors_set(['Проверка не пройдена. Попробуйте ещё раз.'], ['login' => input('login')]);
        redirect('/vhod');
    }

    // перебор пароля — самый простой способ забрать чужой аккаунт,
    // поэтому после пяти промахов адрес отдыхает четверть часа
    if (login_blocked()) {
        errors_set(['Слишком много неудачных попыток. Повторите через 15 минут.']);
        redirect('/vhod');
    }

    $login    = (string) input('login');
    $password = (string) input('password');

    $user = q1('SELECT * FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL', [$login, $login]);

    if (! $user || ! password_verify($password, $user['password'])) {
        login_failed($login);

        $left = login_attempts_left();

        errors_set([
            'Неверное имя пользователя или пароль.'
            . ($left <= 2 && $left > 0 ? ' Осталось попыток: ' . $left . '.' : ''),
        ], ['login' => $login]);

        redirect('/vhod');
    }

    if (setting('verify_email', '0') === '1' && empty($user['email_verified_at'])) {
        errors_set(['Подтвердите адрес почты — письмо со ссылкой отправлено при регистрации.'],
            ['login' => $login]);
        redirect('/vhod');
    }

    if (is_banned($user)) {
        errors_set(['Аккаунт заблокирован.'], []);
        redirect('/vhod');
    }

    login_succeeded();
    login_user((int) $user['id']);

    redirect('/');
}

function act_register(): void
{
    csrf_check();

    if (setting('registration_open', '1') !== '1') {
        flash('Регистрация временно закрыта.', 'error');
        redirect('/vhod');
    }

    $username = (string) input('username');
    $email    = (string) input('email');
    $password = (string) input('password');
    $confirm  = (string) input('password_confirmation');

    $errors = [];

    if (! preg_match('/^[A-Za-z0-9_-]{3,30}$/', $username)) {
        $errors[] = 'Имя пользователя: латиница, цифры, дефис и подчёркивание, от 3 до 30 символов.';
    }

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Укажите корректный e-mail.';
    }

    if (mb_strlen($password) < 8) {
        $errors[] = 'Пароль должен быть не короче 8 символов.';
    }

    if ($password !== $confirm) {
        $errors[] = 'Пароли не совпадают.';
    }

    /*
     * Согласия — два отдельных действия, как требует закон: принятие
     * соглашения и согласие на обработку данных нельзя «склеивать» в одну
     * галочку, а рекламная рассылка вообще идёт третьей и необязательной.
     */
    if (! captcha_check()) {
        $errors[] = 'Проверка не пройдена. Попробуйте ещё раз.';
    }

    if (! input('accept_terms')) {
        $errors[] = 'Нужно принять пользовательское соглашение.';
    }

    if (! input('accept_personal_data')) {
        $errors[] = 'Без согласия на обработку персональных данных регистрация невозможна.';
    }

    if (! $errors && qv('SELECT COUNT(*) FROM users WHERE username = ?', [$username]) > 0) {
        $errors[] = 'Это имя пользователя уже занято.';
    }

    if (! $errors && qv('SELECT COUNT(*) FROM users WHERE email = ?', [$email]) > 0) {
        $errors[] = 'Этот e-mail уже зарегистрирован.';
    }

    if ($errors) {
        errors_set($errors, ['username' => $username, 'email' => $email]);
        redirect('/registraciya');
    }

    $token = bin2hex(random_bytes(32));

    // Пока почта на сервере не настроена, подтверждение выключено:
    // иначе регистрация закрыта наглухо — письмо просто не дойдёт.
    // Включается в настройках сайта, когда письма заработают.
    $needsVerify = setting('verify_email', '0') === '1';

    $id = insert('users', [
        'username'          => $username,
        'email'             => $email,
        'password'          => password_hash($password, PASSWORD_DEFAULT),
        'verify_token'      => $needsVerify ? hash('sha256', $token) : null,
        'email_verified_at' => $needsVerify ? null : now(),
        'unsubscribe_token' => bin2hex(random_bytes(16)),
        'created_at'        => now(),
    ]);

    /*
     * Фиксируем согласия сразу после создания учётной записи: дата, время,
     * ключ документа и дата его редакции. Отдельной строкой — согласие на
     * рекламу, если человек его дал; по умолчанию оно не проставлено.
     */
    record_consent('registration', ['terms'], 'accepted', $id);
    record_consent('registration', ['consent', 'privacy'], 'accepted', $id);

    if (input('accept_ads')) {
        record_consent('advertising', ['ads'], 'accepted', $id);
        update('users', $id, ['ads_subscribed' => 1]);
    }

    if ($needsVerify) {
        send_verification($email, $username, $token);

        flash('Мы отправили письмо на ' . $email . '. Перейдите по ссылке из него, чтобы войти.');
        redirect('/vhod');
    }

    login_user($id);

    flash('Добро пожаловать в ' . setting('site_name', cfg('app_name')) . '!');
    redirect('/');
}

function act_settings(): void
{
    csrf_check();
    $me = require_auth();

    $username = (string) input('username');
    $bio      = (string) input('bio');
    $errors   = [];

    if (! preg_match('/^[A-Za-z0-9_-]{3,30}$/', $username)) {
        $errors[] = 'Имя пользователя: латиница, цифры, дефис и подчёркивание, от 3 до 30 символов.';
    }

    $taken = qv('SELECT COUNT(*) FROM users WHERE username = ? AND id != ?', [$username, $me['id']]);

    if ($taken > 0) {
        $errors[] = 'Это имя пользователя уже занято.';
    }

    if ($errors) {
        errors_set($errors);
        redirect('/nastroyki');
    }

    $fields = [
        'username'            => $username,
        'bio'                 => mb_substr($bio, 0, 500) ?: null,
        'email_notifications' => input('email_notifications') ? 1 : 0,
        'ads_subscribed'      => input('ads_subscribed') ? 1 : 0,
    ];

    if (! empty($_FILES['avatar']['tmp_name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
        $path = store_image($_FILES['avatar'], 'avatars', 2 * 1024 * 1024);

        if ($path) {
            $fields['avatar'] = $path;
        } else {
            flash('Аватар не сохранён: нужен PNG, JPG, GIF или WEBP до 2 МБ.', 'error');
        }
    } elseif (input('remove_avatar')) {
        $fields['avatar'] = null;
    }

    update('users', (int) $me['id'], $fields);

    flash('Профиль обновлён.');
    redirect('/nastroyki');
}

function act_report(): void
{
    csrf_check();
    $me = require_auth();

    $type = input('type');
    $id   = (int) input('id');

    if (! in_array($type, ['thread', 'comment'], true)) {
        back();
    }

    $table  = $type === 'thread' ? 'threads' : 'comments';
    $target = q1("SELECT * FROM {$table} WHERE id = ?", [$id]);

    if (! $target) {
        back();
    }

    if ((int) $target['user_id'] === (int) $me['id']) {
        flash('На собственную публикацию пожаловаться нельзя.', 'error');
        back();
    }

    $reason = input('reason');

    if (! array_key_exists($reason, cfg('report_reasons'))) {
        back();
    }

    // Повторная жалоба обновляет прежнюю, а не плодит дубли
    $existing = q1('SELECT id FROM reports WHERE user_id = ? AND reportable_type = ? AND reportable_id = ?',
        [$me['id'], $type, $id]);

    if ($existing) {
        update('reports', (int) $existing['id'], [
            'reason'      => $reason,
            'comment'     => mb_substr((string) input('comment'), 0, 500) ?: null,
            'status'      => 'open',
            'resolved_by' => null,
            'resolved_at' => null,
        ]);
    } else {
        insert('reports', [
            'user_id'         => $me['id'],
            'reportable_type' => $type,
            'reportable_id'   => $id,
            'reason'          => $reason,
            'comment'         => mb_substr((string) input('comment'), 0, 500) ?: null,
            'status'          => 'open',
            'created_at'      => now(),
        ]);
    }

    flash('Жалоба отправлена модераторам. Спасибо.');
    back();
}

/**
 * Редактирование своей публикации.
 * Автор правит содержимое, но не статус и не тип — это остаётся за модератором.
 */
function act_thread_update(string $slug): void
{
    csrf_check();
    $me = require_auth();

    $t = q1('SELECT * FROM threads WHERE slug = ?', [$slug]);

    if (! $t) {
        redirect('/');
    }

    if ((int) $t['user_id'] !== (int) $me['id']) {
        http_response_code(403);
        exit('Можно редактировать только свои публикации.');
    }

    $type      = $t['type'];
    $title     = (string) input('title');
    $bodyInput = blocks_input();
    $body      = $bodyInput['html'];
    $errors    = [];

    if (mb_strlen($title) < 8) {
        $errors[] = 'Заголовок слишком короткий — минимум 8 символов.';
    }

    if ($type === 'voucher' && trim((string) input('code')) === '') {
        $errors[] = 'Введите промокод.';
    }

    if ($bodyInput['error']) {
        $errors[] = $bodyInput['error'];
    } elseif (in_array($type, ['discussion', 'feedback', 'blog'], true) && mb_strlen(trim(strip_tags($body))) < 10) {
        $errors[] = 'Описание не может быть короче 10 символов.';
    }

    $url = (string) input('url');

    if ($url !== '') {
        $clean = normalize_url($url);

        if ($clean !== null) {
            $url = $clean;
        } elseif (! looks_like_url($url)) {
            $errors[] = 'Ссылка указана неверно — нужен адрес вида https://magazin.ru/tovar.';
        }
    }

    if ($errors) {
        // текст из редактора возвращаем в форму: переписывать длинный
        // пост заново из-за короткого заголовка никто не станет
        errors_set($errors, ['title' => $title, 'body_json' => (string) ($_POST['body_json'] ?? '')]);
        redirect('/post/' . rawurlencode($slug) . '/redaktirovat');
    }

    $commercial     = is_commercial($type);
    $supportsImage  = $commercial || $type === 'blog';

    // slug не трогаем: уже расшаренные ссылки должны работать
    $fields = [
        'title'     => mb_substr($title, 0, 255),
        'body'      => clean_html($body) ?: null,
        'body_json' => $bodyInput['json'],
    ];

    if (is_moderator()) {
        $fields['ad_marking'] = mb_substr((string) input('ad_marking'), 0, 500) ?: null;
    }

    if ($supportsImage) {
        $fields['image_url'] = (string) input('image_url') ?: null;
    }

    if ($commercial) {
        $chosen = (int) input('merchant_id', 0);

        if ($chosen > 0) {
            $merchant = q1('SELECT id FROM merchants WHERE id = ?', [$chosen]);
        } else {
            $merchant = merchant_by_name(input('merchant_name'))
                ?: ($url !== '' ? merchant_by_domain($url) : null);
        }

        /*
         * Ссылку можно менять, только пока публикация не в ленте, —
         * и модератору. Иначе одобренный пост переписывается на что угодно:
         * проверяли одно, а люди пойдут на другое, вплоть до фишинга.
         */
        if (thread_url_editable($t)) {
            $fields['url'] = $url ?: null;
        }

        $fields['merchant_id'] = $merchant['id'] ?? null;

        if ($type === 'deal') {
            $fields['price']            = input('price') !== '' ? (float) input('price') : null;
            $fields['old_price']        = input('old_price') !== '' ? (float) input('old_price') : null;
            $fields['discount_percent'] = input('discount_percent') !== '' ? (int) input('discount_percent') : null;
        }
    }

    if ($type === 'voucher') {
        $fields['code'] = (string) input('code') ?: null;
    }

    if ($type === 'discussion' && input('group_id') !== '') {
        $fields['group_id'] = (int) input('group_id');
    }

    if ($commercial) {
        $raw = (string) input('expires_at');

        if ($raw !== '' && ($ts = strtotime($raw)) !== false) {
            $ts = min($ts, time() + 730 * 86400);
            $fields['expires_at'] = date('Y-m-d', $ts) . ' 23:59:59';
        } else {
            $fields['expires_at'] = null;
        }
    }

    if (! empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $path = store_image($_FILES['image'], 'threads');

        if ($path) {
            $fields['image_path'] = $path;
        }
    } elseif (input('remove_image')) {
        $fields['image_path'] = null;
        $fields['image_url']  = null;
    }

    // исправил после отказа — значит снова на проверку, отдельной кнопкой
    // при этом пользоваться не нужно
    if ($t['status'] === 'rejected' && ! is_moderator()) {
        $fields['status']        = 'pending';
        $fields['reject_reason'] = null;
        $fields['reject_link']   = null;
    }

    update('threads', (int) $t['id'], $fields);

    if ($commercial) {
        qx('DELETE FROM category_thread WHERE thread_id = ?', [$t['id']]);

        foreach ((array) ($_POST['categories'] ?? []) as $categoryId) {
            if (ctype_digit((string) $categoryId)) {
                insert('category_thread', ['thread_id' => (int) $t['id'], 'category_id' => (int) $categoryId]);
            }
        }
    }

    // снятые галочки — удаление снимков; порядок остальных не трогаем
    foreach ((array) ($_POST['drop_images'] ?? []) as $imageId) {
        if (ctype_digit((string) $imageId)) {
            qx('DELETE FROM thread_images WHERE id = ? AND thread_id = ?', [(int) $imageId, (int) $t['id']]);
        }
    }

    store_thread_images($_FILES['images'] ?? null, (int) $t['id']);

    flash(($fields['status'] ?? $t['status']) === 'pending' && $t['status'] === 'rejected'
        ? 'Изменения сохранены, публикация отправлена на повторную проверку.'
        : 'Изменения сохранены.');

    // ведём на канонический адрес: старый ответил бы редиректом лишним шагом
    redirect(thread_path(find_thread($slug) ?: $slug));
}

/** Ссылку правит автор до попадания в ленту, дальше — только модератор. */
function thread_url_editable(array $t): bool
{
    return is_moderator() || $t['status'] !== 'published';
}

/**
 * Отправить публикацию на повторную проверку.
 *
 * Кнопка для случая, когда автор считает, что правок не требуется, —
 * например, модератор ошибся или причина уже неактуальна.
 */
function act_thread_resubmit(string $slug): void
{
    csrf_check();
    $me = require_auth();

    $t = q1('SELECT * FROM threads WHERE slug = ?', [$slug]);

    if (! $t) {
        redirect('/');
    }

    if ((int) $t['user_id'] !== (int) $me['id']) {
        http_response_code(403);
        exit('Можно отправлять только свои публикации.');
    }

    if ($t['status'] !== 'rejected') {
        flash('Эта публикация не отклонена — отправлять нечего.', 'error');
        back();
    }

    update('threads', (int) $t['id'], [
        'status'        => 'pending',
        'reject_reason' => null,
        'reject_link'   => null,
    ]);

    flash('Публикация снова в очереди на проверку.');
    redirect(thread_path($t));
}

function act_notifications_read(): void
{
    csrf_check();
    require_auth();

    mark_notifications_read();

    flash('Уведомления отмечены прочитанными.');
    back();
}

/** Лайк магазина. Счётчик денормализован — по нему сортируется блок «Топ магазины». */
function act_merchant_like(string $slug): void
{
    csrf_check();
    $me = require_auth();

    $merchant = q1('SELECT * FROM merchants WHERE slug = ?', [$slug]);

    if (! $merchant) {
        back();
    }

    $existing = q1('SELECT id FROM merchant_likes WHERE user_id = ? AND merchant_id = ?',
        [$me['id'], $merchant['id']]);

    if ($existing) {
        qx('DELETE FROM merchant_likes WHERE id = ?', [$existing['id']]);
    } else {
        insert('merchant_likes', [
            'user_id'     => $me['id'],
            'merchant_id' => $merchant['id'],
            'created_at'  => now(),
        ]);
    }

    $count = (int) qv('SELECT COUNT(*) FROM merchant_likes WHERE merchant_id = ?', [$merchant['id']]);
    update('merchants', (int) $merchant['id'], ['likes_count' => $count]);

    if (is_ajax()) {
        json_out(['likes' => $count, 'liked' => ! $existing]);
    }

    back();
}

/** Загрузка картинки из редактора. Отвечает адресом файла. */
function act_upload(): void
{
    csrf_check();
    $me = require_auth();

    if (empty($_FILES['image']['tmp_name']) || ! is_uploaded_file($_FILES['image']['tmp_name'])) {
        json_out(['error' => 'Файл не получен.'], 422);
    }

    $path = store_image($_FILES['image'], 'inline', 4 * 1024 * 1024);

    if (! $path) {
        json_out(['error' => 'Нужен PNG, JPG, GIF или WEBP размером до 4 МБ.'], 422);
    }

    json_out(['url' => url('/storage/' . $path)]);
}

/**
 * Поиск похожей публикации за последний месяц.
 * Совпадение ссылки или промокода — почти наверняка дубль;
 * заголовок сверяем по нормализованному виду, чтобы поймать пересказ.
 */
function find_duplicate(?string $url, ?string $code, string $title): ?array
{
    $since = date('Y-m-d H:i:s', time() - 30 * 86400);

    if ($url) {
        $found = q1("SELECT title, slug FROM threads
                     WHERE url = ? AND status = 'published' AND created_at > ?
                     LIMIT 1", [$url, $since]);

        if ($found) {
            return $found;
        }
    }

    if ($code) {
        $found = q1("SELECT title, slug FROM threads
                     WHERE code = ? AND status = 'published' AND created_at > ?
                     LIMIT 1", [$code, $since]);

        if ($found) {
            return $found;
        }
    }

    $normalized = preg_replace('/[^a-zа-яё0-9]+/iu', '', mb_strtolower($title, 'UTF-8'));

    if (mb_strlen((string) $normalized) < 10) {
        return null;
    }

    foreach (q("SELECT title, slug FROM threads WHERE status = 'published' AND created_at > ? LIMIT 300", [$since]) as $row) {
        $other = preg_replace('/[^a-zа-яё0-9]+/iu', '', mb_strtolower($row['title'], 'UTF-8'));

        if ($other === $normalized) {
            return $row;
        }
    }

    return null;
}

/* --------------------------------------------- подтверждение почты */

function send_verification(string $email, string $username, string $token): void
{
    $site = setting('site_name', cfg('app_name'));
    $link = site_url() . url('/podtverzhdenie/' . $token);

    send_mail(
        $email,
        'Подтвердите почту — ' . $site,
        'Здравствуйте, ' . $username . '!',
        [
            'Вы зарегистрировались на ' . $site . '. Остался один шаг — подтвердите адрес почты.',
            ['button' => ['text' => 'Подтвердить почту', 'url' => $link]],
            ['small' => 'Если регистрировались не вы, просто удалите это письмо — аккаунт останется неактивным.'],
            ['small' => 'Кнопка не нажимается? Скопируйте адрес: ' . $link],
        ]
    );
}

function act_verify(string $token): void
{
    $user = q1('SELECT * FROM users WHERE verify_token = ?', [hash('sha256', $token)]);

    if (! $user) {
        flash('Ссылка недействительна или почта уже подтверждена.', 'error');
        redirect('/vhod');
    }

    update('users', (int) $user['id'], [
        'email_verified_at' => now(),
        'verify_token'      => null,
    ]);

    login_user((int) $user['id']);

    flash('Почта подтверждена. Добро пожаловать!');
    redirect('/');
}

/**
 * Удаление аккаунта.
 *
 * Строку пользователя не удаляем: на ней висят публикации, комментарии
 * и журнал согласий — снос ряда утащил бы за собой чужие обсуждения.
 * Вместо этого обезличиваем: имя, почта, аватар и описание стираются,
 * связь между человеком и его следами на сайте разрывается.
 *
 * Так выполняется отзыв согласия по закону и при этом не рушится
 * содержимое, которое читают другие люди.
 */
function act_account_delete(): void
{
    csrf_check();
    $me = require_auth();

    // подтверждение словом: одна случайная кнопка не должна стирать аккаунт
    if (mb_strtoupper(trim((string) input('confirm'))) !== 'УДАЛИТЬ') {
        flash('Чтобы удалить аккаунт, введите слово УДАЛИТЬ.', 'error');
        redirect('/nastroyki');
    }

    $id = (int) $me['id'];

    record_consent('account_deleted', [], 'accepted', $id);

    update('users', $id, [
        'username'            => 'deleted_' . $id,
        'email'               => 'deleted_' . $id . '@hothunt.invalid',
        'password'            => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'avatar'              => null,
        'bio'                 => null,
        'yandex_id'           => null,
        'email_notifications' => 0,
        'ads_subscribed'      => 0,
        'unsubscribe_token'   => null,
        'verify_token'        => null,
        'deleted_at'          => now(),
    ]);

    // подписки и долгие метки входа гасим отдельно: они живут своими таблицами
    qx('DELETE FROM remember_tokens WHERE user_id = ?', [$id]);
    qx('DELETE FROM bookmarks WHERE user_id = ?', [$id]);

    logout_user();

    flash('Аккаунт удалён. Публикации остались на сайте, но больше не связаны с вашим профилем.');
    redirect('/');
}

/**
 * Правка комментария.
 *
 * Без ограничения по времени: опечатку замечают и через сутки, а запрет
 * править старое лишь заставляет писать «*исправляю» отдельным сообщением.
 * Зато правка всегда видна — рядом с датой появляется «изменено», чтобы
 * нельзя было незаметно переписать сказанное под чужим ответом.
 */
function act_comment_update(int $id): void
{
    csrf_check();
    $me = require_auth();

    $comment = q1('SELECT * FROM comments WHERE id = ?', [$id]);

    if (! $comment) {
        back();
    }

    if ((int) $comment['user_id'] !== (int) $me['id'] && ! is_moderator()) {
        http_response_code(403);
        exit('Можно править только свои комментарии.');
    }

    $body = clean_html((string) input('body'));

    if (html_to_text($body) === '') {
        flash('Комментарий не может быть пустым.', 'error');
        back();
    }

    update('comments', $id, [
        'body'      => $body,
        'edited_at' => now(),
    ]);

    $thread = q1('SELECT t.slug, m.slug AS merchant_slug FROM threads t
                  LEFT JOIN merchants m ON m.id = t.merchant_id WHERE t.id = ?', [$comment['thread_id']]);

    if (is_ajax()) {
        json_out(['id' => $id, 'html' => render_comment_body($body)]);
    }

    flash('Комментарий изменён.');
    redirect(thread_path($thread, '#comment-' . $id));
}

/** Разметка тела комментария — та же, что при выводе списка. */
function render_comment_body(string $body): string
{
    return clean_html($body);
}

/**
 * Сохранить дополнительные снимки публикации.
 *
 * Обложка остаётся отдельным полем: по ней строятся карточки в ленте
 * и превью для соцсетей. Здесь — то, что листают на странице: условия
 * акции, чек, вид товара с других сторон.
 *
 * @param array|null $files срез $_FILES с полем-массивом
 */
function store_thread_images(?array $files, int $threadId, int $limit = 8): int
{
    if (! $files || empty($files['tmp_name']) || ! is_array($files['tmp_name'])) {
        return 0;
    }

    $position = (int) qv('SELECT COALESCE(MAX(position), 0) FROM thread_images WHERE thread_id = ?', [$threadId]);
    $already  = (int) qv('SELECT COUNT(*) FROM thread_images WHERE thread_id = ?', [$threadId]);
    $saved    = 0;

    foreach ($files['tmp_name'] as $i => $tmp) {
        if ($already + $saved >= $limit) {
            break;
        }

        if (! $tmp || ! is_uploaded_file($tmp)) {
            continue;
        }

        $path = store_image([
            'tmp_name' => $tmp,
            'name'     => $files['name'][$i] ?? 'image',
            'size'     => $files['size'][$i] ?? 0,
            'error'    => $files['error'][$i] ?? UPLOAD_ERR_OK,
        ], 'threads');

        if (! $path) {
            continue;
        }

        insert('thread_images', [
            'thread_id' => $threadId,
            'path'      => $path,
            'position'  => ++$position,
        ]);

        $saved++;
    }

    return $saved;
}
