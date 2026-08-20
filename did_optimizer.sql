-- DID Optimizer custom schema for VICIdial.
-- These tables must use InnoDB: the AGI relies on transactions and row locks.

CREATE TABLE IF NOT EXISTS did_optimizer_pool (
    did_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    did_number VARCHAR(32) NOT NULL,
    campaign_id VARCHAR(20) NOT NULL,
    country_code VARCHAR(8) NOT NULL DEFAULT '1',
    local_key VARCHAR(16) NOT NULL DEFAULT '',
    enabled ENUM('Y','N') NOT NULL DEFAULT 'Y',
    admin_priority SMALLINT NOT NULL DEFAULT 0,
    total_assignments BIGINT UNSIGNED NOT NULL DEFAULT 0,
    calls_today INT UNSIGNED NOT NULL DEFAULT 0,
    usage_date DATE DEFAULT NULL,
    daily_limit INT UNSIGNED NOT NULL DEFAULT 0,
    last_used DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (did_id),
    UNIQUE KEY uq_didopt_pool_campaign_did (campaign_id, did_number),
    KEY idx_didopt_pool_eligible
        (campaign_id, enabled, local_key, usage_date, calls_today, daily_limit),
    KEY idx_didopt_pool_lru (campaign_id, last_used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS did_optimizer_assignments (
    assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    unique_call_id VARCHAR(64) NOT NULL,
    campaign_id VARCHAR(20) NOT NULL,
    server_ip VARCHAR(45) NOT NULL DEFAULT '',
    lead_id BIGINT UNSIGNED DEFAULT NULL,
    auto_call_id BIGINT UNSIGNED DEFAULT NULL,
    did_number VARCHAR(32) NOT NULL,
    destination VARCHAR(32) NOT NULL,
    local_key VARCHAR(16) NOT NULL DEFAULT '',
    selection_reason VARCHAR(64) NOT NULL,
    callerid_applied ENUM('Y','N') NOT NULL DEFAULT 'N',
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (assignment_id),
    UNIQUE KEY uq_didopt_assignment_call (unique_call_id),
    KEY idx_didopt_assignment_campaign_recent
        (campaign_id, assigned_at, assignment_id),
    KEY idx_didopt_assignment_did_recent
        (campaign_id, did_number, assigned_at, assignment_id),
    KEY idx_didopt_assignment_lead (lead_id, assigned_at),
    KEY idx_didopt_assignment_server (server_ip, assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- One row per campaign is the narrow concurrency lock. Calls in unrelated
-- campaigns lock different rows and therefore do not block one another.
CREATE TABLE IF NOT EXISTS did_optimizer_campaign_state (
    campaign_id VARCHAR(20) NOT NULL,
    last_did VARCHAR(32) DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- Optional enrichment stores. Empty tables are valid: the AGI falls back to
-- VICIdial's vicidial_phone_codes data and a neutral reputation score.
CREATE TABLE IF NOT EXISTS did_optimizer_geo_prefixes (
    npanxx CHAR(6) NOT NULL,
    npa CHAR(3) NOT NULL,
    city VARCHAR(80) NOT NULL DEFAULT '',
    state_iso VARCHAR(8) NOT NULL DEFAULT '',
    country_iso VARCHAR(8) NOT NULL DEFAULT '',
    PRIMARY KEY (npanxx, city, state_iso, country_iso),
    KEY idx_didopt_geo_npa (npa, state_iso, country_iso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS did_optimizer_reputation_cache (
    did_number VARCHAR(32) NOT NULL,
    reputation ENUM('positive','neutral','negative','unknown') NOT NULL DEFAULT 'unknown',
    checked_at DATETIME NOT NULL,
    PRIMARY KEY (did_number),
    KEY idx_didopt_reputation_freshness (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
