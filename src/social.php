<?php
/**
 * Подписки, «Огоньки» и заявки на вывод.
 *
 * Экономика устроена так: за опубликованный пост автор получает
 * репутацию и Огоньки. Начисление одноразовое — отметка стоит в самой
 * публикации, поэтому снятие и возврат с ленты не позволяют начислить
 * дважды.
 */

/* --------------------------------------------------------------- подписки */

/** Подписан ли текущий пользователь. $type: 'user' | 'merchant' */
function is_following(string $type, int $id): bool
{
    if (! auth_id()) {
        return false;
    }

    return qv('SELECT COUNT(*) FROM follows WHERE follower_id = ? AND followable_type = ? AND followable_id = ?',
        [auth_id(), $type, $id]) > 0;
}

function followers_count(string $type, int $id): int
{
    return (int) qv('SELECT COUNT(*) FROM follows WHERE followable_type = ? AND followable_id = ?', [$type, $id]);
}

/** @return array<int> id подписчиков */
function followers_of(string $type, int $id, int $limit = 500): array
{
    $rows = q('SELECT follower_id FROM follows WHERE followable_type = ? AND followable_id = ? LIMIT ' . (int) $limit,
        [$type, $id]);

    return array_map('intval', array_column($rows, 'follower_id'));
}

function act_follow(): void
{
    csrf_check();
    $me = require_auth();

    $type = (string) input('type');
    $id   = (int) input('id');

    if (! in_array($type, ['user', 'merchant'], true) || $id <= 0) {
        back();
    }

    // на себя подписаться нельзя — это бессмысленно и путает счётчики
    if ($type === 'user' && $id === (int) $me['id']) {
        flash('На себя подписаться нельзя.', 'error');
        back();
    }

    $exists = q1('SELECT id FROM follows WHERE follower_id = ? AND followable_type = ? AND followable_id = ?',
        [$me['id'], $type, $id]);

    if ($exists) {
        qx('DELETE FROM follows WHERE id = ?', [$exists['id']]);
    } else {
        insert('follows', [
            'follower_id'     => $me['id'],
            'followable_type' => $type,
            'followable_id'   => $id,
            'created_at'      => now(),
        ]);
    }

    if (is_ajax()) {
        json_out([
            'following' => ! $exists,
            'followers' => followers_count($type, $id),
        ]);
    }

    flash($exists ? 'Подписка отменена.' : 'Подписка оформлена — пришлём уведомление о новых публикациях.');
    back();
}

/**
 * Разослать уведомления подписчикам автора и магазина.
 * Вызывается один раз, когда публикация попадает в ленту.
 */
function notify_followers(int $threadId): void
{
    $thread = q1('SELECT id, user_id, merchant_id, title FROM threads WHERE id = ?', [$threadId]);

    if (! $thread) {
        return;
    }

    $targets = followers_of('user', (int) $thread['user_id']);

    if ($thread['merchant_id']) {
        $targets = array_merge($targets, followers_of('merchant', (int) $thread['merchant_id']));
    }

    // один человек мог подписаться и на автора, и на магазин — письмо шлём одно
    foreach (array_unique($targets) as $userId) {
        notify((int) $userId, 'follow_post', ['thread_id' => $threadId]);
    }
}

/* ---------------------------------------------------------------- Огоньки */

/**
 * Запись в блоге стоит дороже скидки: её дольше писать, она проходит
 * проверку и приносит сайту текст, который ищут поисковики. Скидку же
 * достаточно найти и оформить, а их и так публикуют охотно.
 */
function flames_per_post(string $type): float
{
    return match ($type) {
        'deal', 'voucher' => (float) setting('flames_per_deal', '3'),
        'blog'            => (float) setting('flames_per_blog', '5'),
        default           => 0.0,
    };
}

/** Аккуратный вывод: 1.5 показываем как «1,5», а 3.0 — как «3». */
function flames_format(float $value): string
{
    $value = round($value, 2);

    return rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
}

/**
 * Изменить баланс и записать это в журнал.
 *
 * Журнал нужен не для красоты: без него при удалении публикации нельзя
 * узнать, сколько именно Огоньков за неё начислили — ставка могла
 * поменяться, а голоса приходить по одному. С журналом списание точное.
 */
function add_flames(int $userId, float $amount, string $reason, ?int $threadId = null, ?int $refId = null): void
{
    if ($userId <= 0 || abs($amount) < 0.005) {
        return;
    }

    $amount = round($amount, 2);

    insert('flame_entries', [
        'user_id'    => $userId,
        'thread_id'  => $threadId,
        'ref_id'     => $refId,
        'amount'     => $amount,
        'reason'     => $reason,
        'created_at' => now(),
    ]);

    apply_balance($userId, $amount);
}

/** Баланс не уходит в минус: иначе отрицательный остаток блокирует вывод навсегда. */
function apply_balance(int $userId, float $amount): void
{
    if ($amount >= 0) {
        qx('UPDATE users SET balance = balance + ? WHERE id = ?', [$amount, $userId]);

        return;
    }

    qx('UPDATE users SET balance = CASE WHEN balance + ? < 0 THEN 0 ELSE balance + ? END WHERE id = ?',
        [$amount, $amount, $userId]);
}

/** Снять все начисления, связанные с публикацией: она удаляется. */
function revoke_thread_flames(int $threadId): void
{
    $rows = q('SELECT user_id, SUM(amount) AS total FROM flame_entries
               WHERE thread_id = ? GROUP BY user_id', [$threadId]);

    foreach ($rows as $row) {
        apply_balance((int) $row['user_id'], -(float) $row['total']);
    }

    qx('DELETE FROM flame_entries WHERE thread_id = ?', [$threadId]);
}

/** Снять начисление за конкретный голос — его отменили или переключили. */
function revoke_vote_flames(int $voteId): void
{
    $rows = q("SELECT user_id, SUM(amount) AS total FROM flame_entries
               WHERE reason = 'vote' AND ref_id = ? GROUP BY user_id", [$voteId]);

    foreach ($rows as $row) {
        apply_balance((int) $row['user_id'], -(float) $row['total']);
    }

    qx("DELETE FROM flame_entries WHERE reason = 'vote' AND ref_id = ?", [$voteId]);
}

/**
 * Начислить автору репутацию и Огоньки за публикацию.
 * Повторный вызов ничего не делает: отметка хранится в самой публикации.
 */
function award_publication(int $threadId): void
{
    $t = q1('SELECT * FROM threads WHERE id = ?', [$threadId]);

    if (! $t || (int) $t['flames_awarded'] === 1 || $t['status'] !== 'published') {
        return;
    }

    $flames = flames_per_post($t['type']);
    $rep    = (int) setting('reputation_per_post', (string) cfg('reputation.per_publication', 1));

    update('threads', $threadId, ['flames_awarded' => 1]);

    if ($flames > 0) {
        add_flames((int) $t['user_id'], $flames, 'post', $threadId);
    }

    if ($rep > 0) {
        add_reputation((int) $t['user_id'], $rep);
    }
}

/**
 * Огоньки автору за чужой голос.
 *
 * «Горячо» приносит, «холодно» списывает — но меньше, чем приносит плюс:
 * иначе пара недовольных обнуляла бы заработок за удачный пост.
 * В минус баланс не уходит, это гарантирует apply_balance().
 */
function award_vote(int $authorId, int $threadId, int $voteId, string $direction): void
{
    $amount = $direction === 'hot'
        ? (float) setting('flames_per_vote', '1')
        : -(float) setting('flames_per_dislike', '0.5');

    if (abs($amount) >= 0.005) {
        add_flames($authorId, $amount, 'vote', $threadId, $voteId);
    }
}

function flames_to_rubles(float $flames): float
{
    return round($flames * (float) setting('flames_rate', '1'), 2);
}

/* ------------------------------------------------------- заявки на вывод */

function page_balance(): void
{
    $me = require_auth();

    $history = q('SELECT * FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC LIMIT 20', [$me['id']]);

    $earned = (int) qv("SELECT COUNT(*) FROM threads WHERE user_id = ? AND flames_awarded = 1", [$me['id']]);

    render('balance', [
        'me'      => $me,
        'history' => $history,
        'earned'  => $earned,
        'errors'  => errors_take(),
        'meta'    => meta_simple('Баланс Огоньков — ' . setting('site_name', cfg('app_name')),
            'Баланс, история начислений и заявки на вывод.', false),
    ]);
}

function act_withdraw(): void
{
    csrf_check();
    $me = require_auth();

    $min     = (float) setting('flames_min_withdraw', '500');
    $balance  = (float) $me['balance'];

    $amount     = round((float) str_replace(',', '.', (string) input('amount', 0)), 2);
    $fullName   = trim((string) input('full_name'));
    $phone      = trim((string) input('phone'));
    $bank       = trim((string) input('bank'));
    $requisites = trim((string) input('requisites'));

    $errors = [];

    if ($amount < $min) {
        $errors[] = 'Минимальная сумма к выводу — ' . flames_format($min) . ' Огоньков.';
    }

    if ($amount > $balance + 0.001) {
        $errors[] = 'На балансе только ' . flames_format($balance) . ' Огоньков.';
    }

    if (mb_strlen($fullName) < 5) {
        $errors[] = 'Укажите фамилию, имя и отчество полностью.';
    }

    if (mb_strlen(preg_replace('/\D/', '', $phone)) < 10) {
        $errors[] = 'Укажите телефон.';
    }

    if (mb_strlen($bank) < 2) {
        $errors[] = 'Укажите банк.';
    }

    if (mb_strlen($requisites) < 8) {
        $errors[] = 'Укажите реквизиты для перевода.';
    }

    // одна заявка в обработке за раз: иначе можно подать несколько
    // на всю сумму сразу и вывести больше, чем есть
    if (qv("SELECT COUNT(*) FROM withdrawals WHERE user_id = ? AND status = 'pending'", [$me['id']]) > 0) {
        $errors[] = 'У вас уже есть заявка на рассмотрении. Дождитесь ответа.';
    }

    if ($errors) {
        errors_set($errors);
        redirect('/balans');
    }

    insert('withdrawals', [
        'user_id'    => $me['id'],
        'amount'     => $amount,
        'full_name'  => mb_substr($fullName, 0, 190),
        'phone'      => mb_substr($phone, 0, 40),
        'bank'       => mb_substr($bank, 0, 120),
        'requisites' => mb_substr($requisites, 0, 255),
        'comment'    => mb_substr((string) input('comment'), 0, 500) ?: null,
        'status'     => 'pending',
        'created_at' => now(),
    ]);

    // Огоньки списываем сразу, чтобы их нельзя было потратить дважды;
    // при отказе они возвращаются на баланс
    apply_balance((int) $me['id'], -$amount);

    flash('Заявка отправлена. Обычно рассматриваем в течение нескольких дней.');
    redirect('/balans');
}

function withdrawal_status_label(string $status): string
{
    return [
        'pending'  => 'На рассмотрении',
        'paid'     => 'Выплачено',
        'rejected' => 'Отклонено',
    ][$status] ?? $status;
}
