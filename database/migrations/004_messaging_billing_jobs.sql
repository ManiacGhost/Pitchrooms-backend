-- ===========================================================================
-- 004 — messaging, notifications, billing, and the in-code job scheduler
-- ===========================================================================

CREATE TABLE IF NOT EXISTS threads (
    id                   VARCHAR(64) NOT NULL PRIMARY KEY,
    vertical             VARCHAR(8)  NOT NULL,
    party_a_id           VARCHAR(64) NOT NULL,   -- always the lexicographically smaller id
    party_b_id           VARCHAR(64) NOT NULL,
    opportunity_id       VARCHAR(64) NULL,
    unlocked_by_event_id VARCHAR(64) NULL,
    last_message_at      DATETIME    NULL,
    last_message_preview VARCHAR(255) NULL,
    archived_by          JSON        NULL,
    muted_by             JSON        NULL,
    created_at           DATETIME    NOT NULL,
    UNIQUE KEY uq_thread_pair (party_a_id, party_b_id, opportunity_id),
    KEY idx_thread_a (party_a_id, last_message_at),
    KEY idx_thread_b (party_b_id, last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id            VARCHAR(64) NOT NULL PRIMARY KEY,
    thread_id     VARCHAR(64) NOT NULL,
    from_id       VARCHAR(64) NULL,
    to_id         VARCHAR(64) NULL,
    body          TEXT        NOT NULL,
    type          VARCHAR(16) NOT NULL DEFAULT 'user',
    client_msg_id VARCHAR(80) NULL,
    read_at       DATETIME    NULL,
    created_at    DATETIME    NOT NULL,
    UNIQUE KEY uq_client_msg (thread_id, client_msg_id),
    KEY idx_message_thread (thread_id, created_at),
    KEY idx_message_unread (to_id, read_at),
    CONSTRAINT fk_message_thread FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_attachments (
    message_id VARCHAR(64) NOT NULL,
    file_id    VARCHAR(64) NOT NULL,
    PRIMARY KEY (message_id, file_id),
    CONSTRAINT fk_attachment_message FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id           VARCHAR(64)  NOT NULL PRIMARY KEY,
    user_id      VARCHAR(64)  NOT NULL,
    role         VARCHAR(24)  NULL,
    type         VARCHAR(40)  NOT NULL,
    title        VARCHAR(255) NOT NULL,
    body         VARCHAR(1000) NULL,
    link         VARCHAR(500) NULL,
    action_label VARCHAR(80)  NULL,
    entity_type  VARCHAR(40)  NULL,
    entity_id    VARCHAR(64)  NULL,
    read_at      DATETIME     NULL,
    created_at   DATETIME     NOT NULL,
    KEY idx_notification_user (user_id, read_at, created_at),
    KEY idx_notification_type (user_id, type),
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id VARCHAR(64) NOT NULL,
    type    VARCHAR(40) NOT NULL,
    email   TINYINT(1)  NOT NULL DEFAULT 1,
    sms     TINYINT(1)  NOT NULL DEFAULT 0,
    in_app  TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id, type),
    CONSTRAINT fk_notifpref_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Billing
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS plans (
    id            VARCHAR(40)  NOT NULL PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    tag           VARCHAR(80)  NULL,
    badge         VARCHAR(80)  NULL,
    description   VARCHAR(500) NULL,
    price_minor   BIGINT       NOT NULL,
    currency      VARCHAR(8)   NOT NULL DEFAULT 'USD',
    period        VARCHAR(40)  NULL,
    duration_days INT          NOT NULL DEFAULT 30,
    unlimited     TINYINT(1)   NOT NULL DEFAULT 0,
    features      JSON         NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order    INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS passes (
    id           VARCHAR(64)  NOT NULL PRIMARY KEY,
    user_id      VARCHAR(64)  NOT NULL,
    plan_id      VARCHAR(40)  NOT NULL,
    payment_id   VARCHAR(64)  NULL,
    status       VARCHAR(24)  NOT NULL DEFAULT 'pending',
    price_minor  BIGINT       NOT NULL DEFAULT 0,
    currency     VARCHAR(8)   NOT NULL DEFAULT 'USD',
    unlimited    TINYINT(1)   NOT NULL DEFAULT 0,
    purchased_at DATETIME     NULL,
    expires_at   DATETIME     NULL,
    cancelled_at DATETIME     NULL,
    created_at   DATETIME     NOT NULL,
    KEY idx_pass_user (user_id, status, expires_at),
    CONSTRAINT fk_pass_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pass_events (
    pass_id  VARCHAR(64) NOT NULL,
    event_id VARCHAR(64) NOT NULL,
    PRIMARY KEY (pass_id, event_id),
    CONSTRAINT fk_passevent_pass FOREIGN KEY (pass_id) REFERENCES passes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS slot_pricing (
    vertical   VARCHAR(8)   NOT NULL,
    position   INT          NOT NULL,
    title      VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    fee_minor  BIGINT       NOT NULL DEFAULT 0,
    currency   VARCHAR(8)   NOT NULL DEFAULT 'INR',
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (vertical, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id           VARCHAR(64)  NOT NULL PRIMARY KEY,
    user_id      VARCHAR(64)  NOT NULL,
    provider     VARCHAR(40)  NOT NULL DEFAULT 'manual',
    provider_ref VARCHAR(190) NULL,
    kind         VARCHAR(40)  NOT NULL,          -- pass | slot
    plan_id      VARCHAR(40)  NULL,
    event_id     VARCHAR(64)  NULL,
    slot_position INT         NULL,
    amount_minor BIGINT       NOT NULL,
    currency     VARCHAR(8)   NOT NULL DEFAULT 'USD',
    status       VARCHAR(24)  NOT NULL DEFAULT 'created',
    metadata     JSON         NULL,
    created_at   DATETIME     NOT NULL,
    updated_at   DATETIME     NOT NULL,
    UNIQUE KEY uq_payment_provider_ref (provider, provider_ref),
    KEY idx_payment_user (user_id, status),
    CONSTRAINT fk_payment_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipts (
    id             VARCHAR(64)  NOT NULL PRIMARY KEY,
    payment_id     VARCHAR(64)  NOT NULL,
    user_id        VARCHAR(64)  NOT NULL,
    receipt_number VARCHAR(60)  NOT NULL,
    description    VARCHAR(255) NULL,
    amount_minor   BIGINT       NOT NULL,
    tax_minor      BIGINT       NOT NULL DEFAULT 0,
    total_minor    BIGINT       NOT NULL,
    currency       VARCHAR(8)   NOT NULL DEFAULT 'USD',
    issued_at      DATETIME     NOT NULL,
    pdf_file_id    VARCHAR(64)  NULL,
    UNIQUE KEY uq_receipt_number (receipt_number),
    KEY idx_receipt_user (user_id, issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Scheduler — cron lives in code, not in the hosting panel.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS scheduled_jobs (
    id            VARCHAR(64)  NOT NULL PRIMARY KEY,
    handler       VARCHAR(80)  NOT NULL,
    payload       JSON         NULL,
    unique_key    VARCHAR(190) NULL,
    run_at        DATETIME     NOT NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
    attempts      INT          NOT NULL DEFAULT 0,
    max_attempts  INT          NOT NULL DEFAULT 3,
    locked_at     DATETIME     NULL,
    locked_by     VARCHAR(64)  NULL,
    last_error    VARCHAR(1000) NULL,
    completed_at  DATETIME     NULL,
    created_at    DATETIME     NOT NULL,
    UNIQUE KEY uq_job_unique (unique_key),
    KEY idx_job_due (status, run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recurring definitions, evaluated on every tick.
CREATE TABLE IF NOT EXISTS scheduled_tasks (
    id                VARCHAR(64)  NOT NULL PRIMARY KEY,
    handler           VARCHAR(80)  NOT NULL,
    description       VARCHAR(255) NULL,
    interval_seconds  INT          NOT NULL DEFAULT 300,
    payload           JSON         NULL,
    enabled           TINYINT(1)   NOT NULL DEFAULT 1,
    last_run_at       DATETIME     NULL,
    next_run_at       DATETIME     NULL,
    last_status       VARCHAR(24)  NULL,
    last_error        VARCHAR(1000) NULL,
    runs              INT          NOT NULL DEFAULT 0,
    KEY idx_task_due (enabled, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single row that throttles inline ticks across concurrent requests.
CREATE TABLE IF NOT EXISTS scheduler_locks (
    name        VARCHAR(60) NOT NULL PRIMARY KEY,
    locked_at   DATETIME    NULL,
    locked_by   VARCHAR(64) NULL,
    last_tick_at DATETIME   NULL,
    ticks       BIGINT      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
