-- Monthly S&P 500 dividend-yield observations from Multpl.
-- The existing daily S&P 500 price observations remain unchanged.

CREATE TABLE IF NOT EXISTS backtester_dividend_yields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    observation_month DATE NOT NULL,
    source_observation_date DATE NOT NULL,
    annual_yield_pct DECIMAL(10, 6) NOT NULL,
    is_estimate TINYINT(1) NOT NULL DEFAULT 0,
    source_url VARCHAR(500) NOT NULL,
    source_revision VARCHAR(100) NULL,
    raw_payload_hash CHAR(64) NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_backtester_dividend_yield_asset_month (asset_id, observation_month),
    KEY idx_backtester_dividend_yield_month (observation_month),
    CONSTRAINT fk_backtester_dividend_yield_asset
        FOREIGN KEY (asset_id) REFERENCES backtester_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backtester_auxiliary_sync_state (
    source_slug VARCHAR(100) PRIMARY KEY,
    latest_observation_month DATE NULL,
    last_checked_at DATETIME NULL,
    last_successful_sync_at DATETIME NULL,
    next_check_at DATETIME NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_error_at DATETIME NULL,
    last_error_code VARCHAR(100) NULL,
    last_error_message TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO backtester_data_sources
    (slug, display_name, provider_type, base_url, license_note, attribution_text, is_active)
VALUES
    ('multpl-sp500-dividend-yield', 'Multpl', 'html_table',
     'https://www.multpl.com/s-p-500-dividend-yield/table/by-month',
     'Nutzung nach Angabe des Betreibers mit dem Anbieter für den privaten Einsatz abgestimmt.',
     'Monatliche S&P-500-Dividendenrendite: Multpl; historische Quellen laut Multpl: Standard & Poor''s und Robert Shiller.',
     1)
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    provider_type = VALUES(provider_type),
    base_url = VALUES(base_url),
    license_note = VALUES(license_note),
    attribution_text = VALUES(attribution_text),
    is_active = VALUES(is_active);

INSERT INTO backtester_auxiliary_sync_state (source_slug, next_check_at)
VALUES ('multpl-sp500-dividend-yield', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE source_slug = VALUES(source_slug);

UPDATE backtester_data_sources
SET display_name = 'S&P DJI/FRED + EZB; Dividendenrendite: Multpl',
    attribution_text = 'S&P-500-Preisindex: S&P Dow Jones Indices LLC über FRED. EUR-Umrechnung: EZB. Monatliche Dividendenrendite: Multpl; historische Quellen laut Multpl: Standard & Poor''s und Robert Shiller.'
WHERE slug = 'fred-sp500-live';

UPDATE backtester_assets
SET proxy_description = 'S&P-500-Preisindex in EUR einschließlich reinvestierter monatlicher Dividenden auf Basis der annualisierten Multpl-Dividendenrendite.',
    methodology_note = 'Der tägliche Preisindex kommt über FRED und wird mit historischen EZB-Kursen in EUR umgerechnet. Für jeden Kalendermonat wird die von Multpl veröffentlichte annualisierte S&P-500-Dividendenrendite zeitanteilig zwischen echten Preisbeobachtungen aufgezinst. Das ist eine geglättete Modellrechnung und keine offizielle Total-Return-Indexreihe. Als Schätzung markierte Multpl-Werte bleiben gekennzeichnet und können später revidiert werden.'
WHERE slug = 'sp500';
