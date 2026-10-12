<?php
/**
 * Ядро сайта.
 *
 *   T = сумма(голоса × вес голосующего) − остывание(часы с публикации)
 *
 * Вес зависит от репутации: пачка свежих аккаунтов даёт меньше,
 * чем несколько ветеранов.
 *
 * Вес считается в PHP, а не в SQL: у SQLite и MySQL по-разному
 * называются MIN/LEAST и деление целых, а так код работает одинаково
 * на обеих базах.
 */

function weight_of(int $reputation): int
{
    $weight = (int) cfg('temperature.vote_base')
        + intdiv(max(0, $reputation), max(1, (int) cfg('temperature.vote_reputation_divisor')));

    return (int) min($weight, (int) cfg('temperature.vote_max'));
}

/**
 * Градус — это просто сумма голосов с их весом.
 *
 * Остывание убрано: оно усложняло понимание («почему у поста 0°, если
 * шесть человек нажали "горячо"?») и требовало объяснений. Свежесть
 * ленты обеспечивают вкладка «Новое» и срок действия предложения —
 * истёкшие уходят сами.
 */
function calc_temperature(array $thread): int
{
    $votes = q("SELECT v.direction, u.reputation
                FROM votes v JOIN users u ON u.id = v.user_id
                WHERE v.votable_type = 'thread' AND v.votable_id = ?", [$thread['id']]);

    $sum = 0;

    foreach ($votes as $vote) {
        $sum += ($vote['direction'] === 'hot' ? 1 : -1) * weight_of((int) $vote['reputation']);
    }

    return $sum;
}

function refresh_temperature(int $threadId, bool $logPoint = true): int
{
    $thread = q1('SELECT * FROM threads WHERE id = ?', [$threadId]);

    if (! $thread) {
        return 0;
    }

    $value  = calc_temperature($thread);
    $fields = ['temperature' => $value];

    if ($value >= (int) cfg('temperature.hot_threshold') && empty($thread['hot_at'])) {
        $fields['hot_at'] = now();

        // порог пройден впервые — сообщаем автору
        notify((int) $thread['user_id'], 'thread_hot', [
            'thread_id' => $threadId,
            'actor_id'  => null,
            'payload'   => ['temperature' => $value],
        ]);
    }

    update('threads', $threadId, $fields);

    if ($logPoint) {
        insert('temperature_points', [
            'thread_id'   => $threadId,
            'value'       => $value,
            'recorded_at' => now(),
        ]);
    }

    return $value;
}

/**
 * Обслуживание: остывание, снятие истёкших скидок, снятие блокировок.
 * Запускается лениво при заходе на сайт, но не чаще раза в час.
 */
function run_maintenance(bool $force = false): void
{
    $stamp = __DIR__ . '/../data/.maintenance';

    if (! $force && file_exists($stamp) && (time() - (int) filemtime($stamp)) < 3600) {
        return;
    }

    @touch($stamp);

    // Все голоса одним запросом, чтобы не дёргать базу по разу на пост
    $weights = [];

    foreach (q("SELECT v.votable_id AS thread_id, v.direction, u.reputation
                FROM votes v JOIN users u ON u.id = v.user_id
                WHERE v.votable_type = 'thread'") as $vote) {
        $id = (int) $vote['thread_id'];
        $weights[$id] = ($weights[$id] ?? 0)
            + ($vote['direction'] === 'hot' ? 1 : -1) * weight_of((int) $vote['reputation']);
    }

    $threshold = (int) cfg('temperature.hot_threshold');

    foreach (q("SELECT * FROM threads WHERE status = 'published'") as $thread) {
        $id    = (int) $thread['id'];
        $value = (int) ($weights[$id] ?? 0);

        $becameHot = $value >= $threshold && empty($thread['hot_at']);

        // без остывания градус меняется только при голосовании,
        // поэтому пересчёт почти всегда ничего не переписывает
        if ($value === (int) $thread['temperature'] && ! $becameHot) {
            continue;
        }

        $fields = ['temperature' => $value];

        if ($becameHot) {
            $fields['hot_at'] = now();
        }

        update('threads', $id, $fields);
    }

    qx("UPDATE threads SET status = 'expired'
        WHERE status = 'published' AND expires_at IS NOT NULL AND expires_at < ?", [now()]);

    foreach (q('SELECT * FROM bans WHERE lifted_at IS NULL AND expires_at IS NOT NULL AND expires_at < ?', [now()]) as $ban) {
        update('bans', (int) $ban['id'], ['lifted_at' => now()]);
        update('users', (int) $ban['user_id'], ['is_banned' => 0, 'banned_until' => null]);
    }
}
