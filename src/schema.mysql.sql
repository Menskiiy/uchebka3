CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(30) NOT NULL UNIQUE,
    email         VARCHAR(191) NOT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,
    avatar        VARCHAR(255) NULL,
    bio           TEXT NULL,
    reputation    INT NOT NULL DEFAULT 0,
    is_moderator  TINYINT NOT NULL DEFAULT 0,
    is_banned     TINYINT NOT NULL DEFAULT 0,
    banned_until  DATETIME NULL,
    email_verified_at   DATETIME NULL,
    verify_token        VARCHAR(64) NULL,
    unsubscribe_token   VARCHAR(64) NULL,
    email_notifications TINYINT NOT NULL DEFAULT 1,
    is_editor     TINYINT NOT NULL DEFAULT 0,
    is_bot        TINYINT NOT NULL DEFAULT 0,
    ads_subscribed TINYINT NOT NULL DEFAULT 0,
    deleted_at    DATETIME NULL,
    yandex_id     VARCHAR(40) NULL UNIQUE,
    balance       DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL,
    last_seen_at  DATETIME NULL,
    INDEX idx_users_reputation (reputation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    parent_id   INT NULL,
    name        VARCHAR(120) NOT NULL,
    slug        VARCHAR(120) NOT NULL UNIQUE,
    description TEXT NULL,
    icon        VARCHAR(255) NULL,
    position    INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE merchants (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    slug        VARCHAR(120) NOT NULL UNIQUE,
    domain      VARCHAR(191) NULL,
    description TEXT NULL,
    logo        VARCHAR(255) NULL,
    logo_color  VARCHAR(20) NULL,
    affiliate_url   VARCHAR(255) NULL,
    affiliate_param VARCHAR(40) NULL,
    is_top      TINYINT NOT NULL DEFAULT 0,
    likes_count INT NOT NULL DEFAULT 0,
    position    INT NOT NULL DEFAULT 0,
    INDEX idx_merchant_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE groups_list (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(160) NOT NULL,
    slug        VARCHAR(160) NOT NULL UNIQUE,
    description TEXT NULL,
    logo        VARCHAR(255) NULL,
    position    INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE threads (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    type             VARCHAR(20) NOT NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'published',
    reject_reason    TEXT NULL,
    reject_link      VARCHAR(255) NULL,
    moderated_at     DATETIME NULL,
    moderated_by     INT NULL,
    user_id          INT NOT NULL,
    merchant_id      INT NULL,
    group_id         INT NULL,
    title            VARCHAR(255) NOT NULL,
    slug             VARCHAR(191) NOT NULL UNIQUE,
    body             MEDIUMTEXT NULL,
    body_json        MEDIUMTEXT NULL,
    url              VARCHAR(2048) NULL,
    code             VARCHAR(80) NULL,
    image_url        VARCHAR(2048) NULL,
    image_path       VARCHAR(255) NULL,
    price            DECIMAL(12,2) NULL,
    old_price        DECIMAL(12,2) NULL,
    discount_percent INT NULL,
    temperature      INT NOT NULL DEFAULT 0,
    hot_at           DATETIME NULL,
    expires_at       DATETIME NULL,
    published_at     DATETIME NULL,
    comments_count   INT NOT NULL DEFAULT 0,
    views_count      INT NOT NULL DEFAULT 0,
    clicks_count     INT NOT NULL DEFAULT 0,
    verified_at      DATETIME NULL,
    verified_by      INT NULL,
    flames_awarded   TINYINT NOT NULL DEFAULT 0,
    ad_marking       TEXT NULL,
    created_at       DATETIME NOT NULL,
    INDEX idx_threads_status_pub (status, published_at),
    INDEX idx_threads_status_temp (status, temperature),
    INDEX idx_threads_type (type, status, published_at),
    INDEX idx_thread_merchant (merchant_id),
    INDEX idx_thread_verified (verified_at),
    CONSTRAINT fk_thread_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE SET NULL,
    CONSTRAINT fk_thread_group FOREIGN KEY (group_id) REFERENCES groups_list(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE category_merchant (
    merchant_id INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (merchant_id, category_id),
    CONSTRAINT fk_cm_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE thread_images (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    thread_id INT NOT NULL,
    path      VARCHAR(255) NOT NULL,
    position  INT NOT NULL DEFAULT 0,
    INDEX idx_thread_images (thread_id, position),
    CONSTRAINT fk_ti_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE category_thread (
    thread_id   INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (thread_id, category_id),
    CONSTRAINT fk_ct_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_ct_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    thread_id   INT NOT NULL,
    user_id     INT NOT NULL,
    parent_id   INT NULL,
    body        TEXT NOT NULL,
    likes_count INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    edited_at   DATETIME NULL,
    INDEX idx_comments_thread (thread_id, created_at),
    CONSTRAINT fk_comment_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_comment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_comment_parent FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE votes (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    votable_type VARCHAR(20) NOT NULL,
    votable_id   INT NOT NULL,
    direction    VARCHAR(10) NOT NULL,
    created_at   DATETIME NOT NULL,
    UNIQUE KEY uniq_vote (user_id, votable_type, votable_id),
    INDEX idx_votable (votable_type, votable_id),
    CONSTRAINT fk_vote_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookmarks (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    thread_id  INT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_bookmark (user_id, thread_id),
    CONSTRAINT fk_bm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_bm_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reports (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    reportable_type VARCHAR(20) NOT NULL,
    reportable_id   INT NOT NULL,
    reason          VARCHAR(30) NOT NULL,
    comment         TEXT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'open',
    resolved_by     INT NULL,
    resolved_at     DATETIME NULL,
    created_at      DATETIME NOT NULL,
    UNIQUE KEY uniq_report (user_id, reportable_type, reportable_id),
    INDEX idx_report_status (status, created_at),
    CONSTRAINT fk_report_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_report_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE moderation_logs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    moderator_id INT NOT NULL,
    action       VARCHAR(40) NOT NULL,
    payload      TEXT NULL,
    created_at   DATETIME NOT NULL,
    INDEX idx_modlog (moderator_id, created_at),
    CONSTRAINT fk_modlog_user FOREIGN KEY (moderator_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bans (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    moderator_id INT NULL,
    reason       TEXT NOT NULL,
    expires_at   DATETIME NULL,
    lifted_at    DATETIME NULL,
    created_at   DATETIME NOT NULL,
    INDEX idx_ban_user (user_id, lifted_at),
    CONSTRAINT fk_ban_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ban_moderator FOREIGN KEY (moderator_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE temperature_points (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    thread_id   INT NOT NULL,
    value       INT NOT NULL,
    recorded_at DATETIME NOT NULL,
    INDEX idx_temp_point (thread_id, recorded_at),
    CONSTRAINT fk_tp_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    name  VARCHAR(60) PRIMARY KEY,
    value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    actor_id   INT NULL,
    type       VARCHAR(40) NOT NULL,
    thread_id  INT NULL,
    comment_id INT NULL,
    payload    TEXT NULL,
    read_at    DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_notif_user (user_id, read_at, created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_notif_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_reset_token (token_hash),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE merchant_likes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    merchant_id INT NOT NULL,
    created_at  DATETIME NOT NULL,
    UNIQUE KEY uniq_merchant_like (user_id, merchant_id),
    CONSTRAINT fk_mlike_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mlike_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    ip         VARCHAR(45) NOT NULL,
    login      VARCHAR(191) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_attempt (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bot_imports (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    source      VARCHAR(30) NOT NULL,
    external_id VARCHAR(60) NOT NULL,
    thread_id   INT NULL,
    created_at  DATETIME NOT NULL,
    UNIQUE KEY uniq_import (source, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE follows (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    follower_id     INT NOT NULL,
    followable_type VARCHAR(20) NOT NULL,
    followable_id   INT NOT NULL,
    created_at      DATETIME NOT NULL,
    UNIQUE KEY uniq_follow (follower_id, followable_type, followable_id),
    INDEX idx_follow_target (followable_type, followable_id),
    CONSTRAINT fk_follow_user FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE withdrawals (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    amount       DECIMAL(12,2) NOT NULL,
    full_name    VARCHAR(191) NOT NULL,
    phone        VARCHAR(40) NOT NULL,
    bank         VARCHAR(120) NOT NULL,
    requisites   VARCHAR(255) NOT NULL,
    comment      TEXT NULL,
    status       VARCHAR(20) NOT NULL DEFAULT 'pending',
    resolved_by  INT NULL,
    resolved_at  DATETIME NULL,
    created_at   DATETIME NOT NULL,
    INDEX idx_withdrawal_status (status, created_at),
    CONSTRAINT fk_withdrawal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE flame_entries (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    thread_id  INT NULL,
    ref_id     INT NULL,
    amount     DECIMAL(12,2) NOT NULL,
    reason     VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_flame_user (user_id, created_at),
    INDEX idx_flame_thread (thread_id),
    INDEX idx_flame_ref (reason, ref_id),
    CONSTRAINT fk_flame_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mail_queue (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    recipient  VARCHAR(191) NOT NULL,
    subject    VARCHAR(255) NOT NULL,
    heading    VARCHAR(255) NOT NULL,
    blocks     TEXT NOT NULL,
    attempts   TINYINT NOT NULL DEFAULT 0,
    sent_at    DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_mail_pending (sent_at, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
