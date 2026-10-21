-- Assetklassen-Backtester V1: core schema
-- MySQL 8.0+ / MariaDB 10.5+, InnoDB, utf8mb4

CREATE TABLE IF NOT EXISTS backtester_data_sources (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    provider_type VARCHAR(80) NOT NULL,
    base_url VARCHAR(500) NULL,
    license_note TEXT NULL,
    attribution_text TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_backtester_data_sources_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backtester_assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id INT UNSIGNED NOT NULL,
    slug VARCHAR(100) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    short_name VARCHAR(100) NOT NULL,
    asset_class VARCHAR(50) NOT NULL,
    region VARCHAR(100) NULL,
    provider_symbol VARCHAR(255) NOT NULL,
    provider_dataset VARCHAR(255) NULL,
    identifier_type VARCHAR(50) NULL,
    identifier_value VARCHAR(255) NULL,
    raw_currency CHAR(3) NOT NULL,
    display_currency CHAR(3) NOT NULL DEFAULT 'EUR',
    value_type ENUM('adjusted_close', 'close', 'spot_reference', 'index_level') NOT NULL,
    native_frequency ENUM('daily', 'monthly', 'quarterly', 'annual') NOT NULL,
    calendar_type ENUM('market_weekdays', 'calendar_daily', 'native_periods') NOT NULL DEFAULT 'market_weekdays',
    timezone_name VARCHAR(100) NULL,
    proxy_description TEXT NULL,
    methodology_note TEXT NULL,
    first_expected_date DATE NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_default_selected TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_backtester_assets_slug (slug),
    CONSTRAINT fk_backtester_assets_source
        FOREIGN KEY (source_id) REFERENCES backtester_data_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backtester_observations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    observation_date DATE NOT NULL,
    raw_value DECIMAL(24, 10) NOT NULL,
    raw_currency CHAR(3) NOT NULL,
    eur_value DECIMAL(24, 10) NOT NULL,
    fx_rate_to_eur DECIMAL(24, 12) NULL,
    fx_observation_date DATE NULL,
    source_timestamp DATETIME NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    quality_status ENUM('valid', 'provisional', 'revised', 'flagged') NOT NULL DEFAULT 'valid',
    source_revision VARCHAR(100) NULL,
    raw_payload_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_backtester_observation_asset_date (asset_id, observation_date),
    CONSTRAINT fk_backtester_observation_asset
        FOREIGN KEY (asset_id) REFERENCES backtester_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backtester_sync_state (
    asset_id INT UNSIGNED PRIMARY KEY,
    latest_observation_date DATE NULL,
    backfill_cursor_date DATE NULL,
    last_checked_at DATETIME NULL,
    last_successful_sync_at DATETIME NULL,
    next_check_at DATETIME NULL,
    lock_token CHAR(36) NULL,
    lock_until DATETIME NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_error_at DATETIME NULL,
    last_error_code VARCHAR(100) NULL,
    last_error_message TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_backtester_sync_state_asset
        FOREIGN KEY (asset_id) REFERENCES backtester_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backtester_sync_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trigger_type ENUM('cron', 'page_visit', 'manual', 'initial_backfill', 'gap_repair') NOT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    status ENUM('running', 'success', 'partial', 'failed') NOT NULL,
    assets_checked INT UNSIGNED NOT NULL DEFAULT 0,
    assets_succeeded INT UNSIGNED NOT NULL DEFAULT 0,
    assets_failed INT UNSIGNED NOT NULL DEFAULT 0,
    rows_inserted INT UNSIGNED NOT NULL DEFAULT 0,
    rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

