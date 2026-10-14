-- MySQL / MariaDB schema (XAMPP works fine).
-- utf8mb4 so emoji don't break messages.

CREATE TABLE agents (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_admin      TINYINT(1)   NOT NULL DEFAULT 0,
    last_seen_at  INT UNSIGNED NOT NULL DEFAULT 0     -- unix time, used for the online check
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE conversations (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_hash        CHAR(64)     NOT NULL UNIQUE,   -- sha256 of the visitor token, never the token itself
    status            VARCHAR(10)  NOT NULL DEFAULT 'open',
    visitor_meta      TEXT         NOT NULL,          -- json: name, email, page url...
    assigned_agent_id INT UNSIGNED NULL,
    created_at        DATETIME     NOT NULL,          -- UTC
    last_activity_at  DATETIME     NOT NULL,
    KEY idx_conversations_status (status, last_activity_at),
    CONSTRAINT fk_conv_agent FOREIGN KEY (assigned_agent_id) REFERENCES agents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE messages (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED    NOT NULL,
    sender_type     VARCHAR(10)     NOT NULL,
    agent_id        INT UNSIGNED    NULL,
    body            TEXT            NOT NULL,
    client_id       VARCHAR(64)     NULL,
    created_at      DATETIME        NOT NULL,
    -- the database itself refuses duplicate retries (NULL client_ids are fine, they don't collide)
    UNIQUE KEY uq_conversation_client (conversation_id, client_id),
    KEY idx_messages_conversation (conversation_id, id),
    CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rate_limits (
    bucket       VARCHAR(190) NOT NULL PRIMARY KEY,   -- e.g. "msg:203.0.113.5"
    hits         INT UNSIGNED NOT NULL,
    window_start INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;