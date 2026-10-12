<?php
/**
 * Подключение к базе: SQLite или MySQL.
 * Схема создаётся автоматически при первом запуске.
 */

function db_configured(): bool
{
    $db = cfg('db');

    if ($db['driver'] === 'mysql') {
        return $db['name'] !== '' && $db['user'] !== '';
    }

    return true;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $db = cfg('db');

    if ($db['driver'] === 'mysql') {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'], (int) $db['port'], $db['name'], $db['charset']);

        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $fresh = ! db_has_table($pdo, 'users');
    } else {
        $path = $db['path'];
        $dir  = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fresh = ! file_exists($path) || filesize($path) === 0;

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }

    if ($fresh) {
        db_install($pdo, $db['driver']);
    }

    return $pdo;
}

function db_driver(): string
{
    return cfg('db')['driver'];
}

function db_has_table(PDO $pdo, string $table): bool
{
    try {
        $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');

        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function db_install(PDO $pdo, string $driver): void
{
    $file = $driver === 'mysql'
        ? __DIR__ . '/schema.mysql.sql'
        : __DIR__ . '/schema.sql';

    // MySQL не выполняет несколько выражений одним exec на всех сборках,
    // поэтому разбиваем файл по точке с запятой в конце строки.
    foreach (preg_split('/;\s*\n/', file_get_contents($file)) as $statement) {
        $statement = trim($statement);

        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }

    seed_reference($pdo);
}

/** @return array<int,array<string,mixed>> */
function q(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);

    return $st->fetchAll();
}

/** @return array<string,mixed>|null */
function q1(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();

    return $row === false ? null : $row;
}

function qv(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);

    return $st->fetchColumn();
}

function qx(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);

    return $st->rowCount();
}

function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $ph   = array_map(fn ($c) => ':' . $c, $cols);

    $st = db()->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')');
    $st->execute($data);

    return (int) db()->lastInsertId();
}

function update(string $table, int $id, array $data): void
{
    $sets = implode(', ', array_map(fn ($c) => $c . ' = :' . $c, array_keys($data)));
    $data['__id'] = $id;

    $st = db()->prepare('UPDATE ' . $table . ' SET ' . $sets . ' WHERE id = :__id');
    $st->execute($data);
}

function delete_row(string $table, int $id): void
{
    qx('DELETE FROM ' . $table . ' WHERE id = ?', [$id]);
}

/* --------------------------------------------------------- настройки сайта */

function settings_all(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];

    try {
        foreach (q('SELECT name, value FROM settings') as $row) {
            $cache[$row['name']] = $row['value'];
        }
    } catch (PDOException $e) {
        // таблицы ещё нет — до установки это нормально
    }

    return $cache;
}

function setting(string $name, $default = null)
{
    $all = settings_all();

    return ($all[$name] ?? '') !== '' ? $all[$name] : $default;
}

function setting_save(string $name, string $value): void
{
    $exists = qv('SELECT COUNT(*) FROM settings WHERE name = ?', [$name]);

    if ($exists > 0) {
        qx('UPDATE settings SET value = ? WHERE name = ?', [$value, $name]);
    } else {
        qx('INSERT INTO settings (name, value) VALUES (?, ?)', [$name, $value]);
    }
}

/* ------------------------------------------------------------- миграции */

/**
 * Догоняющие изменения схемы для сайтов, установленных раньше.
 * Версия хранится в settings, поэтому проверка стоит один запрос.
 */
function db_migrate(): void
{
    $version = (int) setting('schema_version', 0);
    $target  = 27;

    if ($version >= $target) {
        return;
    }

    $pdo    = db();
    $mysql  = db_driver() === 'mysql';

    // v2 — уведомления
    if (! db_has_table($pdo, 'notifications')) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE notifications (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                user_id    INT NOT NULL,
                actor_id   INT NULL,
                type       VARCHAR(40) NOT NULL,
                thread_id  INT NULL,
                comment_id INT NULL,
                payload    TEXT NULL,
                read_at    DATETIME NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_notif_user (user_id, read_at, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE notifications (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                actor_id   INTEGER,
                type       TEXT NOT NULL,
                thread_id  INTEGER,
                comment_id INTEGER,
                payload    TEXT,
                read_at    TEXT,
                created_at TEXT NOT NULL
            )");
            $pdo->exec('CREATE INDEX idx_notif_user ON notifications(user_id, read_at, created_at)');
        }
    }

    // v3 — у магазинов не хватало описания и порядка сортировки,
    // из-за чего раздел «Магазины» в панели падал с ошибкой
    if ($version < 3) {
        foreach (['description', 'position'] as $column) {
            if (! db_has_column($pdo, 'merchants', $column)) {
                $type = $column === 'position'
                    ? ($mysql ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0')
                    : ($mysql ? 'TEXT NULL' : 'TEXT');

                $pdo->exec("ALTER TABLE merchants ADD COLUMN {$column} {$type}");
            }
        }
    }

    // v4 — восстановление пароля
    if ($version < 4 && ! db_has_table($pdo, 'password_resets')) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE password_resets (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                user_id    INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_reset_token (token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE password_resets (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL
            )");
            $pdo->exec('CREATE INDEX idx_reset_token ON password_resets(token_hash)');
        }
    }

    // v5 — отметка «проверено» и лайки магазинов
    if ($version < 5) {
        foreach (['verified_at' => $mysql ? 'DATETIME NULL' : 'TEXT',
                  'verified_by' => $mysql ? 'INT NULL' : 'INTEGER'] as $col => $type) {
            if (! db_has_column($pdo, 'threads', $col)) {
                $pdo->exec("ALTER TABLE threads ADD COLUMN {$col} {$type}");
            }
        }

        if (! db_has_column($pdo, 'merchants', 'likes_count')) {
            $pdo->exec('ALTER TABLE merchants ADD COLUMN likes_count '
                . ($mysql ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0'));
        }

        if (! db_has_table($pdo, 'merchant_likes')) {
            if ($mysql) {
                $pdo->exec("CREATE TABLE merchant_likes (
                    id          INT AUTO_INCREMENT PRIMARY KEY,
                    user_id     INT NOT NULL,
                    merchant_id INT NOT NULL,
                    created_at  DATETIME NOT NULL,
                    UNIQUE KEY uniq_merchant_like (user_id, merchant_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } else {
                $pdo->exec("CREATE TABLE merchant_likes (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id     INTEGER NOT NULL,
                    merchant_id INTEGER NOT NULL,
                    created_at  TEXT NOT NULL,
                    UNIQUE (user_id, merchant_id)
                )");
            }
        }
    }

    // v6 — подтверждение почты, защита от перебора, отписка от писем
    if ($version < 6) {
        $userCols = [
            'email_verified_at'   => $mysql ? 'DATETIME NULL' : 'TEXT',
            'verify_token'        => $mysql ? 'VARCHAR(64) NULL' : 'TEXT',
            'unsubscribe_token'   => $mysql ? 'VARCHAR(64) NULL' : 'TEXT',
            'email_notifications' => $mysql ? 'TINYINT NOT NULL DEFAULT 1' : 'INTEGER NOT NULL DEFAULT 1',
        ];

        foreach ($userCols as $col => $type) {
            if (! db_has_column($pdo, 'users', $col)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN {$col} {$type}");
            }
        }

        // уже зарегистрированные считаются подтверждёнными:
        // нельзя вдруг заблокировать людей, которые пользуются сайтом
        $pdo->exec('UPDATE users SET email_verified_at = ' . ($mysql ? 'NOW()' : "datetime('now')")
            . ' WHERE email_verified_at IS NULL');

        if (! db_has_table($pdo, 'login_attempts')) {
            if ($mysql) {
                $pdo->exec("CREATE TABLE login_attempts (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    ip         VARCHAR(45) NOT NULL,
                    login      VARCHAR(191) NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_attempt (ip, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } else {
                $pdo->exec("CREATE TABLE login_attempts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip TEXT NOT NULL, login TEXT, created_at TEXT NOT NULL)");
                $pdo->exec('CREATE INDEX idx_attempt ON login_attempts(ip, created_at)');
            }
        }

        // индексы под страницу магазина и фильтр проверенных
        foreach ([['threads', 'merchant_id', 'idx_thread_merchant'],
                  ['threads', 'verified_at', 'idx_thread_verified']] as [$tbl, $col, $name]) {
            try {
                $pdo->exec("CREATE INDEX {$name} ON {$tbl}({$col})");
            } catch (PDOException $e) {
                // индекс уже есть — это нормально
            }
        }
    }

    // v7 — публикации, которые неожиданно ушли на проверку, возвращаем в ленту.
    // Премодерация теперь включается осознанно, а не сама собой.
    if ($version < 7) {
        $pdo->exec("UPDATE threads SET status = 'published' WHERE status = 'pending'");
    }

    // v8 — аккаунты, зарегистрированные при включённом подтверждении,
    // но не подтверждённые из-за неработающей почты, разблокируем
    if ($version < 8) {
        $pdo->exec('UPDATE users SET email_verified_at = ' . ($mysql ? 'NOW()' : "datetime('now')")
            . ', verify_token = NULL WHERE email_verified_at IS NULL');
    }

    // v9 — учёт того, что бот уже опубликовал, чтобы не плодить дубли
    if ($version < 9 && ! db_has_table($pdo, 'bot_imports')) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE bot_imports (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                source      VARCHAR(30) NOT NULL,
                external_id VARCHAR(60) NOT NULL,
                thread_id   INT NULL,
                created_at  DATETIME NOT NULL,
                UNIQUE KEY uniq_import (source, external_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE bot_imports (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                source      TEXT NOT NULL,
                external_id TEXT NOT NULL,
                thread_id   INTEGER,
                created_at  TEXT NOT NULL,
                UNIQUE (source, external_id)
            )");
        }
    }

    // v10 — подписки на людей и магазины, баланс «Огоньков», заявки на вывод
    if ($version < 10) {
        if (! db_has_column($pdo, 'users', 'is_editor')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_editor '
                . ($mysql ? 'TINYINT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0'));
        }

        if (! db_has_column($pdo, 'users', 'balance')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN balance '
                . ($mysql ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0'));
        }

        if (! db_has_column($pdo, 'threads', 'flames_awarded')) {
            $pdo->exec('ALTER TABLE threads ADD COLUMN flames_awarded '
                . ($mysql ? 'TINYINT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0'));
        }

        if (! db_has_table($pdo, 'follows')) {
            if ($mysql) {
                $pdo->exec("CREATE TABLE follows (
                    id              INT AUTO_INCREMENT PRIMARY KEY,
                    follower_id     INT NOT NULL,
                    followable_type VARCHAR(20) NOT NULL,
                    followable_id   INT NOT NULL,
                    created_at      DATETIME NOT NULL,
                    UNIQUE KEY uniq_follow (follower_id, followable_type, followable_id),
                    INDEX idx_follow_target (followable_type, followable_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } else {
                $pdo->exec("CREATE TABLE follows (
                    id              INTEGER PRIMARY KEY AUTOINCREMENT,
                    follower_id     INTEGER NOT NULL,
                    followable_type TEXT NOT NULL,
                    followable_id   INTEGER NOT NULL,
                    created_at      TEXT NOT NULL,
                    UNIQUE (follower_id, followable_type, followable_id)
                )");
                $pdo->exec('CREATE INDEX idx_follow_target ON follows(followable_type, followable_id)');
            }
        }

        if (! db_has_table($pdo, 'withdrawals')) {
            if ($mysql) {
                $pdo->exec("CREATE TABLE withdrawals (
                    id           INT AUTO_INCREMENT PRIMARY KEY,
                    user_id      INT NOT NULL,
                    amount       INT NOT NULL,
                    full_name    VARCHAR(191) NOT NULL,
                    phone        VARCHAR(40) NOT NULL,
                    bank         VARCHAR(120) NOT NULL,
                    requisites   VARCHAR(255) NOT NULL,
                    comment      TEXT NULL,
                    status       VARCHAR(20) NOT NULL DEFAULT 'pending',
                    resolved_by  INT NULL,
                    resolved_at  DATETIME NULL,
                    created_at   DATETIME NOT NULL,
                    INDEX idx_withdrawal_status (status, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } else {
                $pdo->exec("CREATE TABLE withdrawals (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id      INTEGER NOT NULL,
                    amount       INTEGER NOT NULL,
                    full_name    TEXT NOT NULL,
                    phone        TEXT NOT NULL,
                    bank         TEXT NOT NULL,
                    requisites   TEXT NOT NULL,
                    comment      TEXT,
                    status       TEXT NOT NULL DEFAULT 'pending',
                    resolved_by  INTEGER,
                    resolved_at  TEXT,
                    created_at   TEXT NOT NULL
                )");
                $pdo->exec('CREATE INDEX idx_withdrawal_status ON withdrawals(status, created_at)');
            }
        }
    }

    // v11 — журнал начислений: без него нельзя точно списать Огоньки
    // при удалении публикации или отмене голоса
    if ($version < 11 && ! db_has_table($pdo, 'flame_entries')) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE flame_entries (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                user_id    INT NOT NULL,
                thread_id  INT NULL,
                ref_id     INT NULL,
                amount     INT NOT NULL,
                reason     VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_flame_user (user_id, created_at),
                INDEX idx_flame_thread (thread_id),
                INDEX idx_flame_ref (reason, ref_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE flame_entries (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                thread_id  INTEGER,
                ref_id     INTEGER,
                amount     INTEGER NOT NULL,
                reason     TEXT NOT NULL,
                created_at TEXT NOT NULL
            )");
            $pdo->exec('CREATE INDEX idx_flame_user ON flame_entries(user_id, created_at)');
            $pdo->exec('CREATE INDEX idx_flame_thread ON flame_entries(thread_id)');
            $pdo->exec('CREATE INDEX idx_flame_ref ON flame_entries(reason, ref_id)');
        }
    }

    // v12 — Огоньки стали дробными: 1.5 за скидку, 0.5 списания за «холодно».
    // Целая колонка округляла бы всё в ноль или в единицу.
    if ($version < 12) {
        if ($mysql) {
            $pdo->exec('ALTER TABLE users MODIFY balance DECIMAL(12,2) NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE flame_entries MODIFY amount DECIMAL(12,2) NOT NULL');
            $pdo->exec('ALTER TABLE withdrawals MODIFY amount DECIMAL(12,2) NOT NULL');
        }
        // в SQLite тип колонки не фиксирован — дробные значения пишутся как есть
    }

    // v13 — юридическая маркировка рекламы (ИНН, erid). Отдельное поле,
    // а не текст в описании: так его можно показать мелко и не портить карточку
    if ($version < 13 && ! db_has_column($pdo, 'threads', 'ad_marking')) {
        $pdo->exec('ALTER TABLE threads ADD COLUMN ad_marking ' . ($mysql ? 'TEXT NULL' : 'TEXT'));
    }

    // v14 — вход через Яндекс: храним идентификатор аккаунта у них
    if ($version < 14 && ! db_has_column($pdo, 'users', 'yandex_id')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN yandex_id ' . ($mysql ? 'VARCHAR(40) NULL' : 'TEXT'));

        try {
            $pdo->exec('CREATE UNIQUE INDEX idx_user_yandex ON users(yandex_id)');
        } catch (PDOException $e) {
            // индекс мог остаться от прошлой попытки — это не ошибка
        }
    }

    // v15 — очередь писем. Отправка через mail() занимает секунды, и человек
    // всё это время ждёт ответа на свой комментарий. Кладём письмо в очередь
    // и отправляем после того, как страница уже ушла в браузер.
    if ($version < 15 && ! db_has_table($pdo, 'mail_queue')) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE mail_queue (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                recipient  VARCHAR(191) NOT NULL,
                subject    VARCHAR(255) NOT NULL,
                heading    VARCHAR(255) NOT NULL,
                blocks     TEXT NOT NULL,
                attempts   TINYINT NOT NULL DEFAULT 0,
                sent_at    DATETIME NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_mail_pending (sent_at, attempts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE mail_queue (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                recipient  TEXT NOT NULL,
                subject    TEXT NOT NULL,
                heading    TEXT NOT NULL,
                blocks     TEXT NOT NULL,
                attempts   INTEGER NOT NULL DEFAULT 0,
                sent_at    TEXT,
                created_at TEXT NOT NULL
            )");
            $pdo->exec('CREATE INDEX idx_mail_pending ON mail_queue(sent_at, attempts)');
        }
    }

    // v16 — причина отклонения публикации
    if ($version < 16) {
        $columns = $mysql
            ? [
                'reject_reason' => 'TEXT NULL',
                'reject_link'   => 'VARCHAR(255) NULL',
                'moderated_at'  => 'DATETIME NULL',
                'moderated_by'  => 'INT NULL',
            ]
            : [
                'reject_reason' => 'TEXT',
                'reject_link'   => 'TEXT',
                'moderated_at'  => 'TEXT',
                'moderated_by'  => 'INTEGER',
            ];

        foreach ($columns as $column => $type) {
            if (! db_has_column($pdo, 'threads', $column)) {
                $pdo->exec("ALTER TABLE threads ADD COLUMN {$column} {$type}");
            }
        }
    }

    /*
     * v17 — журнал согласий.
     *
     * Закон требует не только получить согласие, но и уметь показать, когда
     * и на какую редакцию документа человек его дал. Поэтому пишем отметку
     * времени, ключ и дату редакции документа, вариант выбора и технические
     * данные запроса.
     */
    if ($version < 17 && ! db_has_table($pdo, 'consents')) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE consents (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                user_id     INT NULL,
                kind        VARCHAR(40) NOT NULL,
                doc_key     VARCHAR(40) NULL,
                doc_version VARCHAR(40) NULL,
                choice      VARCHAR(20) NOT NULL,
                ip          VARBINARY(16) NULL,
                user_agent  VARCHAR(255) NULL,
                created_at  DATETIME NOT NULL,
                INDEX idx_consents_user (user_id, created_at),
                INDEX idx_consents_kind (kind, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE consents (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER,
                kind        TEXT NOT NULL,
                doc_key     TEXT,
                doc_version TEXT,
                choice      TEXT NOT NULL,
                ip          BLOB,
                user_agent  TEXT,
                created_at  TEXT NOT NULL
            )");
            $pdo->exec('CREATE INDEX idx_consents_user ON consents(user_id, created_at)');
            $pdo->exec('CREATE INDEX idx_consents_kind ON consents(kind, created_at)');
        }
    }

    /*
     * v18 — что уже уходило в каналы. Предложение сгорает двое суток,
     * и без этой таблицы оно попадало бы в дайджест два дня подряд.
     */
    if ($version < 18 && ! db_has_table($pdo, 'digest_sent')) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE digest_sent (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                thread_id  INT NOT NULL,
                channel    VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_digest (thread_id, channel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE digest_sent (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id  INTEGER NOT NULL,
                channel    TEXT NOT NULL,
                created_at TEXT NOT NULL
            )");
            $pdo->exec('CREATE UNIQUE INDEX uniq_digest ON digest_sent(thread_id, channel)');
        }
    }

    /*
     * v19 — техническая роль «бот».
     *
     * Такие аккаунты никто не создаёт руками: пароль у них случайный и нигде
     * не хранится, войти под ними нельзя. Поэтому их профиль правится
     * из панели — но только их, чужие профили админ не трогает.
     */
    if ($version < 19) {
        if (! db_has_column($pdo, 'users', 'is_bot')) {
            $pdo->exec($mysql
                ? 'ALTER TABLE users ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0'
                : 'ALTER TABLE users ADD COLUMN is_bot INTEGER NOT NULL DEFAULT 0');
        }

        // уже созданный публикатор Steam помечается ботом
        $botName = null;

        try {
            $st = $pdo->prepare("SELECT value FROM settings WHERE name = 'bot_username'");
            $st->execute();
            $botName = $st->fetchColumn() ?: 'HotHuntBot';
        } catch (PDOException $e) {
            $botName = 'HotHuntBot';
        }

        $st = $pdo->prepare('UPDATE users SET is_bot = 1 WHERE username = ?');
        $st->execute([$botName]);
    }

    /*
     * v20 — уборка осиротевших комментариев.
     *
     * Раньше удаление ветки убирало только сам комментарий, а ответы
     * оставались в базе с указанием на несуществующего родителя: на странице
     * их не видно, но в счётчике они есть. Вычищаем их и пересчитываем
     * счётчики по факту.
     */
    if ($version < 20) {
        // за проход отваливается один уровень вложенности, глубже трёх не бывает
        for ($pass = 0; $pass < 10; $pass++) {
            $removed = $pdo->exec('DELETE FROM comments
                WHERE parent_id IS NOT NULL
                  AND parent_id NOT IN (SELECT id FROM (SELECT id FROM comments) AS existing)');

            if (! $removed) {
                break;
            }
        }

        $pdo->exec('UPDATE threads SET comments_count =
            (SELECT COUNT(*) FROM comments WHERE comments.thread_id = threads.id)');
    }

    /*
     * v21 — подписка на рекламную рассылку.
     *
     * Отдельно от email_notifications: служебные письма (ответ на комментарий,
     * решение модератора) и реклама — разные вещи, и по закону согласие на них
     * нельзя объединять. Кто уже дал согласие при регистрации — подписан.
     */
    if ($version < 21) {
        if (! db_has_column($pdo, 'users', 'ads_subscribed')) {
            $pdo->exec($mysql
                ? 'ALTER TABLE users ADD COLUMN ads_subscribed TINYINT(1) NOT NULL DEFAULT 0'
                : 'ALTER TABLE users ADD COLUMN ads_subscribed INTEGER NOT NULL DEFAULT 0');
        }

        if (db_has_table($pdo, 'consents')) {
            $pdo->exec("UPDATE users SET ads_subscribed = 1 WHERE id IN (
                SELECT user_id FROM consents WHERE kind = 'advertising' AND choice = 'accepted'
            )");
        }
    }

    /*
     * v22 — долгая память о входе.
     *
     * Файл сессии может исчезнуть (уборка на хостинге, переезд, перезапуск),
     * и человек оказывается разлогинен. Отдельная запись в базе позволяет
     * восстановить вход: в куке лежит пара «метка:секрет», в базе — только
     * хеш секрета, поэтому по утёкшей базе войти нельзя.
     */
    if ($version < 22 && ! db_has_table($pdo, 'remember_tokens')) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE remember_tokens (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                user_id    INT NOT NULL,
                selector   VARCHAR(32) NOT NULL UNIQUE,
                validator  CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_remember_user (user_id),
                CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE remember_tokens (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                selector   TEXT NOT NULL UNIQUE,
                validator  TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL
            )");
        }
    }

    /*
     * v23 — партнёрские ссылки и удаление аккаунта.
     *
     * affiliate_url  — шаблон партнёрской сети с {url} и {subid};
     * affiliate_param — имя параметра метки, если ссылка уже партнёрская;
     * deleted_at     — момент удаления профиля. Сам ряд остаётся: на нём
     *                  висят публикации и комментарии, а связь с человеком
     *                  разрывается обезличиванием.
     */
    if ($version < 23) {
        foreach (['affiliate_url' => 'VARCHAR(255) NULL', 'affiliate_param' => 'VARCHAR(40) NULL'] as $column => $type) {
            if (! db_has_column($pdo, 'merchants', $column)) {
                $pdo->exec('ALTER TABLE merchants ADD COLUMN ' . $column . ' ' . ($mysql ? $type : 'TEXT'));
            }
        }

        if (! db_has_column($pdo, 'users', 'deleted_at')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN deleted_at ' . ($mysql ? 'DATETIME NULL' : 'TEXT'));
        }
    }

    // v24 — отметка о правке комментария; по ней рисуется «изменено»
    if ($version < 24 && ! db_has_column($pdo, 'comments', 'edited_at')) {
        $pdo->exec('ALTER TABLE comments ADD COLUMN edited_at ' . ($mysql ? 'DATETIME NULL' : 'TEXT'));
    }

    /*
     * v25 — категории магазина.
     *
     * Категория у магазина почти всегда одна и та же: у «Пятёрочки» это
     * продукты, у DNS — электроника. Заставлять человека отмечать их
     * руками в каждой публикации значит получать то пустые категории,
     * то десяток отмеченных наугад.
     */
    if ($version < 25 && ! db_has_table($pdo, 'category_merchant')) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE category_merchant (
                merchant_id INT NOT NULL,
                category_id INT NOT NULL,
                PRIMARY KEY (merchant_id, category_id),
                CONSTRAINT fk_cm_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
                CONSTRAINT fk_cm_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE category_merchant (
                merchant_id INTEGER NOT NULL,
                category_id INTEGER NOT NULL,
                PRIMARY KEY (merchant_id, category_id)
            )");
        }
    }

    /*
     * v26 — галерея публикации.
     *
     * Обложка по-прежнему живёт в самой публикации (image_path/image_url):
     * по ней строятся карточки в ленте и превью для соцсетей. Здесь лежат
     * дополнительные снимки — условия акции, чек, вид товара с других сторон.
     */
    if ($version < 26 && ! db_has_table($pdo, 'thread_images')) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE thread_images (
                id        INT AUTO_INCREMENT PRIMARY KEY,
                thread_id INT NOT NULL,
                path      VARCHAR(255) NOT NULL,
                position  INT NOT NULL DEFAULT 0,
                INDEX idx_thread_images (thread_id, position),
                CONSTRAINT fk_ti_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE thread_images (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NOT NULL,
                path      TEXT NOT NULL,
                position  INTEGER NOT NULL DEFAULT 0
            )");
        }
    }

    /*
     * v27 — текст публикации блоками (формат редактора Editor.js).
     *
     * body остаётся как был: в нём HTML, собранный сервером из блоков, —
     * его читают анонсы, описания для поисковиков и дайджест. Блоки нужны
     * редактору, чтобы открыть текст ровно в том виде, в каком его сохранили.
     * MEDIUMTEXT, потому что JSON заметно длиннее готового HTML.
     */
    if ($version < 27) {
        if (! db_has_column($pdo, 'threads', 'body_json')) {
            $pdo->exec('ALTER TABLE threads ADD COLUMN body_json ' . ($mysql ? 'MEDIUMTEXT NULL' : 'TEXT'));
        }

        // TEXT в MySQL — 64 КБ, а кириллица занимает два байта на букву:
        // длинная статья с разметкой в него уже не влезает
        if ($mysql) {
            $pdo->exec('ALTER TABLE threads MODIFY body MEDIUMTEXT NULL');
        }
    }

    setting_save('schema_version', (string) $target);
}

function db_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $pdo->query('SELECT ' . $column . ' FROM ' . $table . ' LIMIT 1');

        return true;
    } catch (PDOException $e) {
        return false;
    }
}
