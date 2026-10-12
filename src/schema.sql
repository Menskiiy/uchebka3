CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    email         TEXT NOT NULL UNIQUE,
    password      TEXT NOT NULL,
    avatar        TEXT,
    bio           TEXT,
    reputation    INTEGER NOT NULL DEFAULT 0,
    is_moderator  INTEGER NOT NULL DEFAULT 0,
    is_banned     INTEGER NOT NULL DEFAULT 0,
    banned_until  TEXT,
    email_verified_at   TEXT,
    verify_token        TEXT,
    unsubscribe_token   TEXT,
    email_notifications INTEGER NOT NULL DEFAULT 1,
    is_editor     INTEGER NOT NULL DEFAULT 0,
    yandex_id     TEXT UNIQUE,
    balance       REAL NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL,
    last_seen_at  TEXT
);

CREATE TABLE categories (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id   INTEGER REFERENCES categories(id) ON DELETE SET NULL,
    name        TEXT NOT NULL,
    slug        TEXT NOT NULL UNIQUE,
    description TEXT,
    icon        TEXT,
    position    INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE merchants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    slug        TEXT NOT NULL UNIQUE,
    domain      TEXT,
    description TEXT,
    logo        TEXT,
    logo_color  TEXT,
    is_top      INTEGER NOT NULL DEFAULT 0,
    likes_count INTEGER NOT NULL DEFAULT 0,
    position    INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE groups_list (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    slug        TEXT NOT NULL UNIQUE,
    description TEXT,
    logo        TEXT,
    position    INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE threads (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    type             TEXT NOT NULL,
    status           TEXT NOT NULL DEFAULT 'published',
    user_id          INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    merchant_id      INTEGER REFERENCES merchants(id) ON DELETE SET NULL,
    group_id         INTEGER REFERENCES groups_list(id) ON DELETE SET NULL,
    title            TEXT NOT NULL,
    slug             TEXT NOT NULL UNIQUE,
    body             TEXT,
    body_json        TEXT,
    url              TEXT,
    code             TEXT,
    image_url        TEXT,
    image_path       TEXT,
    price            REAL,
    old_price        REAL,
    discount_percent INTEGER,
    temperature      INTEGER NOT NULL DEFAULT 0,
    hot_at           TEXT,
    expires_at       TEXT,
    published_at     TEXT,
    comments_count   INTEGER NOT NULL DEFAULT 0,
    views_count      INTEGER NOT NULL DEFAULT 0,
    clicks_count     INTEGER NOT NULL DEFAULT 0,
    verified_at      TEXT,
    verified_by      INTEGER,
    flames_awarded   INTEGER NOT NULL DEFAULT 0,
    ad_marking       TEXT,
    created_at       TEXT NOT NULL
);

CREATE INDEX idx_threads_status_pub  ON threads(status, published_at);
CREATE INDEX idx_threads_status_temp ON threads(status, temperature);
CREATE INDEX idx_threads_type        ON threads(type, status, published_at);
CREATE INDEX idx_thread_merchant      ON threads(merchant_id);
CREATE INDEX idx_thread_verified      ON threads(verified_at);

CREATE TABLE category_thread (
    thread_id   INTEGER NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    PRIMARY KEY (thread_id, category_id)
);

CREATE TABLE comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_id   INTEGER NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    parent_id   INTEGER REFERENCES comments(id) ON DELETE CASCADE,
    body        TEXT NOT NULL,
    likes_count INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL
);

CREATE INDEX idx_comments_thread ON comments(thread_id, created_at);

CREATE TABLE votes (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    votable_type TEXT NOT NULL,
    votable_id   INTEGER NOT NULL,
    direction    TEXT NOT NULL,
    created_at   TEXT NOT NULL,
    UNIQUE (user_id, votable_type, votable_id)
);

CREATE TABLE bookmarks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    thread_id  INTEGER NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    UNIQUE (user_id, thread_id)
);

CREATE TABLE reports (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    reportable_type TEXT NOT NULL,
    reportable_id   INTEGER NOT NULL,
    reason          TEXT NOT NULL,
    comment         TEXT,
    status          TEXT NOT NULL DEFAULT 'open',
    resolved_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    resolved_at     TEXT,
    created_at      TEXT NOT NULL,
    UNIQUE (user_id, reportable_type, reportable_id)
);

CREATE TABLE moderation_logs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    moderator_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    action       TEXT NOT NULL,
    payload      TEXT,
    created_at   TEXT NOT NULL
);

CREATE TABLE bans (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    moderator_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    reason       TEXT NOT NULL,
    expires_at   TEXT,
    lifted_at    TEXT,
    created_at   TEXT NOT NULL
);

CREATE TABLE temperature_points (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_id   INTEGER NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    value       INTEGER NOT NULL,
    recorded_at TEXT NOT NULL
);

CREATE TABLE settings (
    name  TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    actor_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
    type       TEXT NOT NULL,
    thread_id  INTEGER REFERENCES threads(id) ON DELETE CASCADE,
    comment_id INTEGER,
    payload    TEXT,
    read_at    TEXT,
    created_at TEXT NOT NULL
);

CREATE INDEX idx_notif_user ON notifications(user_id, read_at, created_at);

CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX idx_reset_token ON password_resets(token_hash);

CREATE TABLE merchant_likes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    merchant_id INTEGER NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
    created_at  TEXT NOT NULL,
    UNIQUE (user_id, merchant_id)
);

CREATE TABLE login_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ip         TEXT NOT NULL,
    login      TEXT,
    created_at TEXT NOT NULL
);

CREATE INDEX idx_attempt ON login_attempts(ip, created_at);

CREATE TABLE bot_imports (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    source      TEXT NOT NULL,
    external_id TEXT NOT NULL,
    thread_id   INTEGER,
    created_at  TEXT NOT NULL,
    UNIQUE (source, external_id)
);

CREATE TABLE follows (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    followable_type TEXT NOT NULL,
    followable_id   INTEGER NOT NULL,
    created_at      TEXT NOT NULL,
    UNIQUE (follower_id, followable_type, followable_id)
);

CREATE INDEX idx_follow_target ON follows(followable_type, followable_id);

CREATE TABLE withdrawals (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    amount       REAL NOT NULL,
    full_name    TEXT NOT NULL,
    phone        TEXT NOT NULL,
    bank         TEXT NOT NULL,
    requisites   TEXT NOT NULL,
    comment      TEXT,
    status       TEXT NOT NULL DEFAULT 'pending',
    resolved_by  INTEGER,
    resolved_at  TEXT,
    created_at   TEXT NOT NULL
);

CREATE INDEX idx_withdrawal_status ON withdrawals(status, created_at);

CREATE TABLE flame_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    thread_id  INTEGER,
    ref_id     INTEGER,
    amount     REAL NOT NULL,
    reason     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX idx_flame_user ON flame_entries(user_id, created_at);
CREATE INDEX idx_flame_thread ON flame_entries(thread_id);
CREATE INDEX idx_flame_ref ON flame_entries(reason, ref_id);

CREATE TABLE mail_queue (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient  TEXT NOT NULL,
    subject    TEXT NOT NULL,
    heading    TEXT NOT NULL,
    blocks     TEXT NOT NULL,
    attempts   INTEGER NOT NULL DEFAULT 0,
    sent_at    TEXT,
    created_at TEXT NOT NULL
);

CREATE INDEX idx_mail_pending ON mail_queue(sent_at, attempts);
