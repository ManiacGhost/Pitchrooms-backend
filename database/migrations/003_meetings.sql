-- ===========================================================================
-- 003_meetings — pitch events, the live room, entry gate, evaluation, decisions
-- ===========================================================================

CREATE TABLE IF NOT EXISTS events (
    id                    VARCHAR(64)  NOT NULL PRIMARY KEY,
    opportunity_id        VARCHAR(64)  NULL,
    vertical              VARCHAR(8)   NOT NULL,
    buyer_id              VARCHAR(64)  NOT NULL,
    seller_id             VARCHAR(64)  NULL,       -- 1:1 meetings (EE interview, SI pitch)
    proposal_id           VARCHAR(64)  NULL,
    name                  VARCHAR(255) NOT NULL,
    agenda                TEXT         NULL,
    status                VARCHAR(32)  NOT NULL DEFAULT 'requested',
    start_at              DATETIME     NULL,
    end_at                DATETIME     NULL,
    presentation_duration INT          NOT NULL DEFAULT 30,
    qa_duration           INT          NOT NULL DEFAULT 30,
    room_name             VARCHAR(120) NOT NULL,
    meeting_code          VARCHAR(12)  NOT NULL,
    created_by            VARCHAR(64)  NOT NULL,
    accepted_by           VARCHAR(64)  NULL,
    accepted_at           DATETIME     NULL,
    started_at            DATETIME     NULL,
    ended_at              DATETIME     NULL,
    cancelled_at          DATETIME     NULL,
    cancel_reason         VARCHAR(500) NULL,
    decision              VARCHAR(40)  NULL,
    recording_file_id     VARCHAR(64)  NULL,
    created_at            DATETIME     NOT NULL,
    updated_at            DATETIME     NOT NULL,
    UNIQUE KEY uq_event_room (room_name),
    KEY idx_event_schedule (status, start_at),
    KEY idx_event_buyer (buyer_id, status),
    KEY idx_event_seller (seller_id, status),
    KEY idx_event_opp (opportunity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_participants (
    event_id           VARCHAR(64) NOT NULL,
    user_id            VARCHAR(64) NOT NULL,
    role               VARCHAR(24) NOT NULL,
    side               VARCHAR(12) NOT NULL DEFAULT 'seller',  -- buyer | seller | observer
    slot_position      INT         NULL,
    fee_minor          BIGINT      NOT NULL DEFAULT 0,
    currency           VARCHAR(8)  NULL,
    rsvp_status        VARCHAR(24) NOT NULL DEFAULT 'Pending',
    joined_at          DATETIME    NULL,
    left_at            DATETIME    NULL,
    attendance_seconds INT         NOT NULL DEFAULT 0,
    created_at         DATETIME    NOT NULL,
    PRIMARY KEY (event_id, user_id),
    KEY idx_participant_user (user_id),
    UNIQUE KEY uq_event_slot (event_id, slot_position),
    CONSTRAINT fk_participant_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_agenda (
    id                   VARCHAR(64)  NOT NULL PRIMARY KEY,
    event_id             VARCHAR(64)  NOT NULL,
    position             INT          NOT NULL,
    seller_id            VARCHAR(64)  NULL,
    title                VARCHAR(255) NOT NULL,
    type                 VARCHAR(24)  NOT NULL DEFAULT 'seller',
    presentation_seconds INT          NOT NULL DEFAULT 30,
    qa_seconds           INT          NOT NULL DEFAULT 30,
    is_premium           TINYINT(1)   NOT NULL DEFAULT 0,
    fee_minor            BIGINT       NOT NULL DEFAULT 0,
    UNIQUE KEY uq_agenda_position (event_id, position),
    CONSTRAINT fk_agenda_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Server-authoritative room clock. Clients render from this, not from their
-- own setInterval, so every participant sees the same countdown.
CREATE TABLE IF NOT EXISTS meeting_state (
    event_id        VARCHAR(64) NOT NULL PRIMARY KEY,
    stage           VARCHAR(24) NOT NULL DEFAULT 'waiting',
    presenter_index INT         NOT NULL DEFAULT 0,
    presenter_id    VARCHAR(64) NULL,
    stage_started_at DATETIME   NULL,
    stage_ends_at   DATETIME    NULL,
    paused_at       DATETIME    NULL,
    updated_at      DATETIME    NOT NULL,
    CONSTRAINT fk_state_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_access (
    id                 VARCHAR(64)  NOT NULL PRIMARY KEY,
    event_id           VARCHAR(64)  NOT NULL,
    user_id            VARCHAR(64)  NOT NULL,
    code_ok            TINYINT(1)   NOT NULL DEFAULT 0,
    email_otp_ok       TINYINT(1)   NOT NULL DEFAULT 0,
    mobile_otp_ok      TINYINT(1)   NOT NULL DEFAULT 0,
    camera_ok          TINYINT(1)   NOT NULL DEFAULT 0,
    device_fingerprint VARCHAR(120) NULL,
    ip                 VARCHAR(64)  NULL,
    granted_at         DATETIME     NULL,
    expires_at         DATETIME     NULL,
    revoked_at         DATETIME     NULL,
    revoke_reason      VARCHAR(255) NULL,
    created_at         DATETIME     NOT NULL,
    updated_at         DATETIME     NOT NULL,
    UNIQUE KEY uq_access (event_id, user_id),
    CONSTRAINT fk_access_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_messages (
    id         VARCHAR(64)  NOT NULL PRIMARY KEY,
    event_id   VARCHAR(64)  NOT NULL,
    from_id    VARCHAR(64)  NULL,
    sender     VARCHAR(160) NULL,
    sender_role VARCHAR(24) NULL,
    body       TEXT         NOT NULL,
    type       VARCHAR(16)  NOT NULL DEFAULT 'user',
    created_at DATETIME     NOT NULL,
    KEY idx_room_chat (event_id, created_at),
    CONSTRAINT fk_roomchat_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_questions (
    id           VARCHAR(64)  NOT NULL PRIMARY KEY,
    event_id     VARCHAR(64)  NOT NULL,
    asked_by     VARCHAR(64)  NOT NULL,
    asker_name   VARCHAR(160) NULL,
    presenter_id VARCHAR(64)  NULL,
    category     VARCHAR(60)  NULL,
    question     VARCHAR(1000) NOT NULL,
    answer       TEXT         NULL,
    answered_by  VARCHAR(64)  NULL,
    answered_at  DATETIME     NULL,
    upvotes      INT          NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL,
    KEY idx_question_event (event_id, created_at),
    CONSTRAINT fk_question_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_question_votes (
    question_id VARCHAR(64) NOT NULL,
    user_id     VARCHAR(64) NOT NULL,
    created_at  DATETIME    NOT NULL,
    PRIMARY KEY (question_id, user_id),
    CONSTRAINT fk_qvote_question FOREIGN KEY (question_id) REFERENCES meeting_questions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluations (
    id                 VARCHAR(64) NOT NULL PRIMARY KEY,
    event_id           VARCHAR(64) NOT NULL,
    evaluator_id       VARCHAR(64) NOT NULL,
    seller_id          VARCHAR(64) NOT NULL,
    industry_expertise TINYINT     NOT NULL DEFAULT 0,
    creativity         TINYINT     NOT NULL DEFAULT 0,
    team_confidence    TINYINT     NOT NULL DEFAULT 0,
    communication      TINYINT     NOT NULL DEFAULT 0,
    case_studies       TINYINT     NOT NULL DEFAULT 0,
    commercial_fit     TINYINT     NOT NULL DEFAULT 0,
    total_score        DECIMAL(5,2) NOT NULL DEFAULT 0,
    notes              TEXT        NULL,
    submitted_at       DATETIME    NOT NULL,
    UNIQUE KEY uq_evaluation (event_id, evaluator_id, seller_id),
    KEY idx_evaluation_seller (event_id, seller_id),
    CONSTRAINT fk_evaluation_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS decisions (
    id           VARCHAR(64)  NOT NULL PRIMARY KEY,
    event_id     VARCHAR(64)  NOT NULL,
    opportunity_id VARCHAR(64) NULL,
    winner_id    VARCHAR(64)  NULL,
    outcome      VARCHAR(40)  NOT NULL,
    backup_ids   JSON         NULL,
    note         TEXT         NULL,
    created_by   VARCHAR(64)  NOT NULL,
    published_at DATETIME     NULL,
    created_at   DATETIME     NOT NULL,
    UNIQUE KEY uq_decision_event (event_id),
    CONSTRAINT fk_decision_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS follow_ups (
    id         VARCHAR(64)  NOT NULL PRIMARY KEY,
    event_id   VARCHAR(64)  NOT NULL,
    seller_id  VARCHAR(64)  NULL,
    scheduled_at DATETIME   NULL,
    notes      TEXT         NULL,
    created_by VARCHAR(64)  NOT NULL,
    created_at DATETIME     NOT NULL,
    KEY idx_followup_event (event_id),
    CONSTRAINT fk_followup_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investments (
    id           VARCHAR(64)  NOT NULL PRIMARY KEY,
    event_id     VARCHAR(64)  NULL,
    opportunity_id VARCHAR(64) NULL,
    investor_id  VARCHAR(64)  NOT NULL,
    startup_id   VARCHAR(64)  NOT NULL,
    amount_minor BIGINT       NOT NULL DEFAULT 0,
    currency     VARCHAR(8)   NOT NULL DEFAULT 'USD',
    instrument   VARCHAR(60)  NULL,
    terms        TEXT         NULL,
    status       VARCHAR(40)  NOT NULL DEFAULT 'Decision Pending',
    created_at   DATETIME     NOT NULL,
    updated_at   DATETIME     NOT NULL,
    KEY idx_investment_investor (investor_id, status),
    KEY idx_investment_startup (startup_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ratings (
    id         VARCHAR(64) NOT NULL PRIMARY KEY,
    from_id    VARCHAR(64) NOT NULL,
    to_id      VARCHAR(64) NOT NULL,
    event_id   VARCHAR(64) NULL,
    opportunity_id VARCHAR(64) NULL,
    score      DECIMAL(3,2) NOT NULL,
    comment    TEXT        NULL,
    created_at DATETIME    NOT NULL,
    KEY idx_rating_to (to_id, created_at),
    UNIQUE KEY uq_rating (from_id, to_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feedback (
    id         VARCHAR(64) NOT NULL PRIMARY KEY,
    event_id   VARCHAR(64) NULL,
    seller_id  VARCHAR(64) NOT NULL,
    buyer_id   VARCHAR(64) NOT NULL,
    body       TEXT        NOT NULL,
    strengths  TEXT        NULL,
    improvements TEXT      NULL,
    visibility VARCHAR(16) NOT NULL DEFAULT 'seller',
    created_at DATETIME    NOT NULL,
    KEY idx_feedback_seller (seller_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
