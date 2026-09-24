-- ===========================================================================
-- 002_marketplace — opportunities, proposals, shortlists, invitations
-- One schema for all three verticals; `vertical` is the discriminator:
--   ab = brief (brand -> agency), ee = job (employer -> candidate),
--   si = raise (startup -> investor)
-- ===========================================================================

CREATE TABLE IF NOT EXISTS opportunities (
    id                    VARCHAR(64)  NOT NULL PRIMARY KEY,
    vertical              VARCHAR(8)   NOT NULL,
    owner_id              VARCHAR(64)  NOT NULL,
    owner_name            VARCHAR(160) NULL,
    company               VARCHAR(160) NULL,
    title                 VARCHAR(255) NOT NULL,
    category              VARCHAR(120) NULL,
    subcategory           VARCHAR(120) NULL,
    industry              VARCHAR(120) NULL,
    description           TEXT         NULL,
    requirements          TEXT         NULL,
    benefits              TEXT         NULL,
    budget                VARCHAR(80)  NULL,
    budget_type           VARCHAR(60)  NULL,
    duration              VARCHAR(80)  NULL,
    location              VARCHAR(160) NULL,
    work_type             VARCHAR(60)  NULL,
    experience            VARCHAR(80)  NULL,
    department            VARCHAR(120) NULL,
    job_type              VARCHAR(60)  NULL,
    openings              INT          NOT NULL DEFAULT 1,
    deadline              DATETIME     NULL,
    expected_start_date   DATETIME     NULL,
    preferred_sellers     VARCHAR(24)  NOT NULL DEFAULT 'Top 5',
    presentation_duration INT          NOT NULL DEFAULT 30,
    qa_duration           INT          NOT NULL DEFAULT 30,
    visibility            VARCHAR(24)  NOT NULL DEFAULT 'open',
    featured              TINYINT(1)   NOT NULL DEFAULT 0,
    cover_file_id         VARCHAR(64)  NULL,
    cover_url             VARCHAR(500) NULL,
    -- SI-only fields
    round                 VARCHAR(60)  NULL,
    amount                VARCHAR(80)  NULL,
    valuation             VARCHAR(80)  NULL,
    stage                 VARCHAR(60)  NULL,
    use_of_funds          TEXT         NULL,
    deck_file_id          VARCHAR(64)  NULL,
    status                VARCHAR(40)  NOT NULL DEFAULT 'Draft',
    event_id              VARCHAR(64)  NULL,
    created_at            DATETIME     NOT NULL,
    updated_at            DATETIME     NOT NULL,
    deleted_at            DATETIME     NULL,
    KEY idx_opp_listing (vertical, status, created_at),
    KEY idx_opp_owner (owner_id, status),
    KEY idx_opp_category (category, subcategory),
    KEY idx_opp_deadline (deadline),
    CONSTRAINT fk_opp_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opportunity_skills (
    opportunity_id VARCHAR(64) NOT NULL,
    skill          VARCHAR(80) NOT NULL,
    PRIMARY KEY (opportunity_id, skill),
    KEY idx_opp_skill (skill),
    CONSTRAINT fk_opp_skill FOREIGN KEY (opportunity_id) REFERENCES opportunities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opportunity_files (
    opportunity_id VARCHAR(64) NOT NULL,
    file_id        VARCHAR(64) NOT NULL,
    kind           VARCHAR(40) NOT NULL DEFAULT 'attachment',
    PRIMARY KEY (opportunity_id, file_id),
    CONSTRAINT fk_oppfile_opp FOREIGN KEY (opportunity_id) REFERENCES opportunities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opportunity_timeline (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    opportunity_id VARCHAR(64) NOT NULL,
    stage          VARCHAR(60) NOT NULL,
    note           VARCHAR(500) NULL,
    actor_id       VARCHAR(64) NULL,
    at             DATETIME    NOT NULL,
    KEY idx_opp_timeline (opportunity_id, at),
    CONSTRAINT fk_opp_timeline FOREIGN KEY (opportunity_id) REFERENCES opportunities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- pitch (ab) / application (ee) / interest (si)
CREATE TABLE IF NOT EXISTS proposals (
    id              VARCHAR(64)  NOT NULL PRIMARY KEY,
    opportunity_id  VARCHAR(64)  NOT NULL,
    vertical        VARCHAR(8)   NOT NULL,
    seller_id       VARCHAR(64)  NOT NULL,
    buyer_id        VARCHAR(64)  NOT NULL,
    seller_name     VARCHAR(160) NULL,
    company         VARCHAR(160) NULL,
    cover_letter    TEXT         NULL,
    bid             VARCHAR(80)  NULL,
    timeline_text   VARCHAR(120) NULL,
    expected_salary VARCHAR(80)  NULL,
    notice_period   VARCHAR(80)  NULL,
    resume_file_id  VARCHAR(64)  NULL,
    deck_file_id    VARCHAR(64)  NULL,
    note            VARCHAR(500) NULL,
    match_score     INT          NULL,
    status          VARCHAR(40)  NOT NULL DEFAULT 'submitted',
    rejection_reason VARCHAR(500) NULL,
    event_id        VARCHAR(64)  NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NOT NULL,
    UNIQUE KEY uq_proposal_seller (opportunity_id, seller_id),
    KEY idx_proposal_status (opportunity_id, status),
    KEY idx_proposal_seller (seller_id, status),
    KEY idx_proposal_buyer (buyer_id, status),
    CONSTRAINT fk_proposal_opp FOREIGN KEY (opportunity_id) REFERENCES opportunities (id) ON DELETE CASCADE,
    CONSTRAINT fk_proposal_seller FOREIGN KEY (seller_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposal_timeline (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    proposal_id VARCHAR(64)  NOT NULL,
    stage       VARCHAR(60)  NOT NULL,
    note        VARCHAR(500) NULL,
    actor_id    VARCHAR(64)  NULL,
    at          DATETIME     NOT NULL,
    KEY idx_proposal_timeline (proposal_id, at),
    CONSTRAINT fk_proposal_timeline FOREIGN KEY (proposal_id) REFERENCES proposals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shortlists (
    opportunity_id VARCHAR(64) NOT NULL,
    seller_id      VARCHAR(64) NOT NULL,
    proposal_id    VARCHAR(64) NULL,
    position       INT         NULL,
    match_score    INT         NULL,
    locked_at      DATETIME    NULL,
    created_by     VARCHAR(64) NULL,
    created_at     DATETIME    NOT NULL,
    PRIMARY KEY (opportunity_id, seller_id),
    KEY idx_shortlist_seller (seller_id),
    CONSTRAINT fk_shortlist_opp FOREIGN KEY (opportunity_id) REFERENCES opportunities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Investor/buyer watchlist — the "save for later" shortlist, distinct from
-- the binding event shortlist above.
CREATE TABLE IF NOT EXISTS watchlists (
    user_id        VARCHAR(64) NOT NULL,
    opportunity_id VARCHAR(64) NOT NULL,
    note           VARCHAR(500) NULL,
    created_at     DATETIME    NOT NULL,
    PRIMARY KEY (user_id, opportunity_id),
    CONSTRAINT fk_watchlist_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_watchlist_opp FOREIGN KEY (opportunity_id) REFERENCES opportunities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invitations (
    id             VARCHAR(64)  NOT NULL PRIMARY KEY,
    opportunity_id VARCHAR(64)  NOT NULL,
    event_id       VARCHAR(64)  NULL,
    seller_id      VARCHAR(64)  NOT NULL,
    buyer_id       VARCHAR(64)  NOT NULL,
    proposal_id    VARCHAR(64)  NULL,
    status         VARCHAR(40)  NOT NULL DEFAULT 'Sent',
    rsvp_status    VARCHAR(40)  NOT NULL DEFAULT 'Pending',
    selected_position INT       NULL,
    message        VARCHAR(1000) NULL,
    responded_at   DATETIME     NULL,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NOT NULL,
    UNIQUE KEY uq_invitation (opportunity_id, seller_id),
    KEY idx_invitation_seller (seller_id, status),
    CONSTRAINT fk_invitation_opp FOREIGN KEY (opportunity_id) REFERENCES opportunities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
