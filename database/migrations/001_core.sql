-- ===========================================================================
-- 001_core — users, profiles, auth, files, audit
-- ===========================================================================

CREATE TABLE IF NOT EXISTS users (
    id                  VARCHAR(64)  NOT NULL PRIMARY KEY,
    panel_id            VARCHAR(32)  NOT NULL,
    role                VARCHAR(24)  NOT NULL,
    first_name          VARCHAR(80)  NULL,
    last_name           VARCHAR(80)  NULL,
    name                VARCHAR(160) NOT NULL,
    email               VARCHAR(190) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    phone               VARCHAR(32)  NULL,
    country             VARCHAR(80)  NULL,
    company             VARCHAR(160) NULL,
    title               VARCHAR(120) NULL,
    avatar_file_id      VARCHAR(64)  NULL,
    email_verified_at   DATETIME     NULL,
    phone_verified_at   DATETIME     NULL,
    verification_status VARCHAR(24)  NOT NULL DEFAULT 'Pending',
    account_status      VARCHAR(24)  NOT NULL DEFAULT 'Active',
    last_login_at       DATETIME     NULL,
    created_at          DATETIME     NOT NULL,
    updated_at          DATETIME     NOT NULL,
    deleted_at          DATETIME     NULL,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role, account_status),
    KEY idx_users_panel (panel_id, role),
    KEY idx_users_verification (verification_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One profile row per user. `payload` holds the role-specific shape the
-- frontend already renders; the indexed columns are the ones we filter on.
CREATE TABLE IF NOT EXISTS profiles (
    user_id      VARCHAR(64)  NOT NULL PRIMARY KEY,
    type         VARCHAR(24)  NOT NULL,
    display_name VARCHAR(160) NULL,
    headline     VARCHAR(255) NULL,
    bio          TEXT         NULL,
    location     VARCHAR(160) NULL,
    website      VARCHAR(255) NULL,
    linkedin     VARCHAR(255) NULL,
    logo_file_id VARCHAR(64)  NULL,
    sector       VARCHAR(80)  NULL,
    stage        VARCHAR(80)  NULL,
    specialty    VARCHAR(160) NULL,
    experience   VARCHAR(80)  NULL,
    rating       DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    reviews      INT          NOT NULL DEFAULT 0,
    trust_score  INT          NOT NULL DEFAULT 0,
    verified     TINYINT(1)   NOT NULL DEFAULT 0,
    payload      JSON         NULL,
    created_at   DATETIME     NOT NULL,
    updated_at   DATETIME     NOT NULL,
    KEY idx_profiles_type (type),
    KEY idx_profiles_sector (sector, stage),
    CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profile_skills (
    user_id VARCHAR(64) NOT NULL,
    skill   VARCHAR(80) NOT NULL,
    PRIMARY KEY (user_id, skill),
    KEY idx_profile_skills_skill (skill),
    CONSTRAINT fk_profile_skills_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id         VARCHAR(64)  NOT NULL PRIMARY KEY,
    user_id    VARCHAR(64)  NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    user_agent VARCHAR(255) NULL,
    ip         VARCHAR(64)  NULL,
    expires_at DATETIME     NOT NULL,
    revoked_at DATETIME     NULL,
    created_at DATETIME     NOT NULL,
    UNIQUE KEY uq_refresh_hash (token_hash),
    KEY idx_refresh_user (user_id, revoked_at),
    CONSTRAINT fk_refresh_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_codes (
    id          VARCHAR(64)  NOT NULL PRIMARY KEY,
    user_id     VARCHAR(64)  NULL,
    destination VARCHAR(190) NOT NULL,
    channel     VARCHAR(16)  NOT NULL,
    purpose     VARCHAR(40)  NOT NULL,
    entity_id   VARCHAR(64)  NULL,
    code_hash   CHAR(64)     NOT NULL,
    attempts    INT          NOT NULL DEFAULT 0,
    expires_at  DATETIME     NOT NULL,
    consumed_at DATETIME     NULL,
    created_at  DATETIME     NOT NULL,
    KEY idx_otp_lookup (destination, purpose, consumed_at),
    KEY idx_otp_user (user_id, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id         VARCHAR(64)  NOT NULL PRIMARY KEY,
    user_id    VARCHAR(64)  NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    created_at DATETIME     NOT NULL,
    UNIQUE KEY uq_reset_hash (token_hash),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS files (
    id           VARCHAR(64)  NOT NULL PRIMARY KEY,
    owner_id     VARCHAR(64)  NULL,
    kind         VARCHAR(40)  NOT NULL DEFAULT 'document',
    name         VARCHAR(255) NOT NULL,
    mime_type    VARCHAR(120) NOT NULL,
    size_bytes   BIGINT       NOT NULL DEFAULT 0,
    storage_path VARCHAR(255) NOT NULL,
    checksum     CHAR(64)     NULL,
    visibility   VARCHAR(16)  NOT NULL DEFAULT 'private',
    created_at   DATETIME     NOT NULL,
    deleted_at   DATETIME     NULL,
    KEY idx_files_owner (owner_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_log (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    actor_id    VARCHAR(64)  NULL,
    entity_type VARCHAR(40)  NOT NULL,
    entity_id   VARCHAR(64)  NULL,
    action      VARCHAR(60)  NOT NULL,
    diff        JSON         NULL,
    ip          VARCHAR(64)  NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL,
    KEY idx_activity_entity (entity_type, entity_id, created_at),
    KEY idx_activity_actor (actor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket      VARCHAR(190) NOT NULL PRIMARY KEY,
    hits        INT          NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    updated_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    `key`      VARCHAR(120) NOT NULL PRIMARY KEY,
    value      JSON         NULL,
    updated_at DATETIME     NOT NULL,
    updated_by VARCHAR(64)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
