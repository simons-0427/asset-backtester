-- Weltmarkt finished-data export

-- Requested range: 2010-01-01 through 2026-09-01 inclusive.

-- Native frequencies are retained. No interpolation, weekend fill or synthetic values is performed.

CREATE TABLE IF NOT EXISTS `weltmarkt_data_sources` (

  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  slug VARCHAR(96) NOT NULL,

  display_name VARCHAR(255) NOT NULL,

  provider_type VARCHAR(64) NOT NULL,

  base_url VARCHAR(1024) NULL,

  license_note TEXT NOT NULL,

  retrieved_at DATETIME NOT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  UNIQUE KEY uq_weltmarkt_source_slug (slug)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weltmarkt_metrics` (

  metric_id VARCHAR(96) NOT NULL,

  source_id BIGINT UNSIGNED NOT NULL,

  section VARCHAR(32) NOT NULL,

  display_name VARCHAR(255) NOT NULL,

  dashboard_csv_id VARCHAR(128) NULL,

  unit VARCHAR(64) NOT NULL,

  adjustment VARCHAR(32) NOT NULL,

  native_frequency ENUM('daily','weekly','monthly','quarterly','annual') NOT NULL,

  calendar_type VARCHAR(64) NOT NULL,

  raw_currency VARCHAR(16) NULL,

  source_series VARCHAR(512) NULL,

  source_type ENUM('official','unavailable','market_proxy','derived') NOT NULL,

  availability_status ENUM('ready','partial','unavailable') NOT NULL,

  coverage_start DATE NULL,

  coverage_end DATE NULL,

  observation_count INT UNSIGNED NOT NULL DEFAULT 0,

  methodology_note TEXT NOT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (metric_id),

  KEY ix_weltmarkt_metric_section (section),

  KEY ix_weltmarkt_metric_dashboard_id (dashboard_csv_id),

  CONSTRAINT fk_weltmarkt_metric_source FOREIGN KEY (source_id) REFERENCES `weltmarkt_data_sources` (id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weltmarkt_observations` (

  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  metric_id VARCHAR(96) NOT NULL,

  observation_date DATE NOT NULL,

  value DECIMAL(24,10) NOT NULL,

  source_timestamp DATETIME NULL,

  fetched_at DATETIME NOT NULL,

  quality_status ENUM('valid','provisional','revised','flagged') NOT NULL,

  source_revision VARCHAR(128) NOT NULL,

  raw_payload_hash CHAR(64) NOT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  UNIQUE KEY uq_weltmarkt_metric_date (metric_id, observation_date),

  KEY ix_weltmarkt_observation_date (observation_date),

  CONSTRAINT fk_weltmarkt_observation_metric FOREIGN KEY (metric_id) REFERENCES `weltmarkt_metrics` (metric_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weltmarkt_data_quality` (

  metric_id VARCHAR(96) NOT NULL,

  expected_start DATE NOT NULL,

  expected_end DATE NOT NULL,

  native_frequency VARCHAR(16) NOT NULL,

  observation_count INT UNSIGNED NOT NULL,

  duplicate_count INT UNSIGNED NOT NULL,

  out_of_range_count INT UNSIGNED NOT NULL,

  valid_value_count INT UNSIGNED NOT NULL,

  coverage_start DATE NULL,

  coverage_end DATE NULL,

  availability_status ENUM('ready','partial','unavailable') NOT NULL,

  notes TEXT NOT NULL,

  checked_at DATETIME NOT NULL,

  PRIMARY KEY (metric_id),

  CONSTRAINT fk_weltmarkt_quality_metric FOREIGN KEY (metric_id) REFERENCES `weltmarkt_metrics` (metric_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weltmarkt_export_manifest` (

  manifest_key VARCHAR(128) NOT NULL,

  manifest_value TEXT NOT NULL,

  generated_at DATETIME NOT NULL,

  PRIMARY KEY (manifest_key)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
