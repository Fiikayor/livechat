-- SQLite schema. Good default for small sites: no database server to set up.

CREATE TABLE agents (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT    NOT NULL,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    is_admin      INTEGER NOT NULL DEFAULT 0,
    last_seen_at  INTEGER NOT NULL DEFAULT 0    -- unix time, used for the online check
);

CREATE TABLE conversations (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash        TEXT    NOT NULL UNIQUE,  -- sha256 of the visitor token, never the token itself
    status            TEXT    NOT NULL DEFAULT 'open',
    visitor_meta      TEXT    NOT NULL DEFAULT '{}',   -- json: name, email, page url...
    assigned_agent_id INTEGER NULL REFERENCES agents(id) ON DELETE SET NULL,
    created_at        TEXT    NOT NULL,         -- UTC, 'Y-m-d H:i:s'
    last_activity_at  TEXT    NOT NULL
);
CREATE INDEX idx_conversations_status ON conversations (status, last_activity_at);

CREATE TABLE messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    sender_type     TEXT    NOT NULL,
    agent_id        INTEGER NULL,
    body            TEXT    NOT NULL,
    client_id       TEXT    NULL,
    created_at      TEXT    NOT NULL,
    -- the database itself refuses duplicate retries (NULL client_ids are fine, they don't collide)
    UNIQUE (conversation_id, client_id)
);
CREATE INDEX idx_messages_conversation ON messages (conversation_id, id);

CREATE TABLE rate_limits (
    bucket       TEXT    PRIMARY KEY,           -- e.g. "msg:203.0.113.5"
    hits         INTEGER NOT NULL,
    window_start INTEGER NOT NULL
);