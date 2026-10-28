-- Automatic, idempotent imports for the Weltmarkt dashboard.
-- Safe to run repeatedly after 001 through 008.

CREATE TABLE IF NOT EXISTS `weltmarkt_sync_jobs` (
  `job_key` VARCHAR(96) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `frequency` ENUM('daily','weekly','monthly') NOT NULL,
  `enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('idle','running','success','partial','failed') NOT NULL DEFAULT 'idle',
  `next_check_at` DATETIME NOT NULL,
  `last_attempt_at` DATETIME NULL,
  `last_success_at` DATETIME NULL,
  `lock_token` CHAR(36) NULL,
  `lock_until` DATETIME NULL,
  `consecutive_failures` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error_code` VARCHAR(64) NULL,
  `last_error_message` TEXT NULL,
  `last_rows_inserted` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_rows_updated` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_rows_unchanged` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`job_key`),
  KEY `ix_weltmarkt_sync_due` (`enabled`, `next_check_at`, `lock_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weltmarkt_sync_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_key` VARCHAR(96) NOT NULL,
  `trigger_type` VARCHAR(32) NOT NULL,
  `status` ENUM('running','success','partial','failed') NOT NULL,
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME NULL,
  `metrics_touched` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_received` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_inserted` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_updated` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_unchanged` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_summary` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_weltmarkt_sync_runs_job_started` (`job_key`, `started_at`),
  CONSTRAINT `fk_weltmarkt_sync_runs_job`
    FOREIGN KEY (`job_key`) REFERENCES `weltmarkt_sync_jobs` (`job_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `weltmarkt_sync_jobs` (`job_key`, `label`, `frequency`, `enabled`, `next_check_at`) VALUES
  ('markets_daily', 'Börsen, Rohstoffe, Volatilität und Devisen', 'daily', 1, UTC_TIMESTAMP()),
  ('rates_daily', 'Staatsanleiherenditen und Credit Spread', 'daily', 1, UTC_TIMESTAMP()),
  ('policy_daily', 'Leitzinsen (tägliche Reihen)', 'daily', 1, UTC_TIMESTAMP()),
  ('fed_balance_weekly', 'Bilanzsumme der Federal Reserve', 'weekly', 1, UTC_TIMESTAMP()),
  ('monthly_early', 'PMI, Konsumklima und frühe Monatsdaten', 'monthly', 1, UTC_TIMESTAMP()),
  ('monthly_mid', 'Arbeitsmarkt, Inflation, Industrie und Konsum', 'monthly', 1, UTC_TIMESTAMP()),
  ('monthly_late', 'BIP, Welthandel, PBoC-LPR, Notenbankbilanzen und Bewertung', 'monthly', 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `label` = VALUES(`label`),
  `frequency` = VALUES(`frequency`),
  `enabled` = VALUES(`enabled`);

-- Replace the one-time local Bundesbank snapshot with its official live API.
INSERT INTO `weltmarkt_data_sources`
  (`slug`, `display_name`, `provider_type`, `base_url`, `license_note`, `retrieved_at`)
VALUES
  ('bundesbank-api', 'Deutsche Bundesbank SDMX Web Service', 'official_api',
   'https://api.statistiken.bundesbank.de/rest/data/BBSSY/',
   'Official Bundesbank BBSSY observations; published business-day gaps and source identifiers are retained.',
   UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `display_name` = VALUES(`display_name`),
  `provider_type` = VALUES(`provider_type`),
  `base_url` = VALUES(`base_url`),
  `license_note` = VALUES(`license_note`);

UPDATE `weltmarkt_metrics`
SET `source_id` = (SELECT `id` FROM `weltmarkt_data_sources` WHERE `slug` = 'bundesbank-api'),
    `source_series` = CASE `metric_id`
      WHEN 'de_2y' THEN 'BBSSY/D.REN.EUR.A610.000000WT0202.A'
      WHEN 'de_10y' THEN 'BBSSY/D.REN.EUR.A610.000000WT1010.A'
      WHEN 'de_30y' THEN 'BBSSY/D.REN.EUR.A610.000000WT3030.A'
      ELSE `source_series`
    END,
    `methodology_note` = CASE `metric_id`
      WHEN 'de_2y' THEN 'Official Bundesbank on-the-run 2-year yield fetched from the BBSSY SDMX web service; rows without values are omitted.'
      WHEN 'de_10y' THEN 'Official Bundesbank on-the-run 10-year yield fetched from the BBSSY SDMX web service; rows without values are omitted.'
      WHEN 'de_30y' THEN 'Official Bundesbank on-the-run 30-year yield fetched from the BBSSY SDMX web service; rows without values are omitted.'
      ELSE `methodology_note`
    END
WHERE `metric_id` IN ('de_2y', 'de_10y', 'de_30y');

DELETE old_source
FROM `weltmarkt_data_sources` AS old_source
LEFT JOIN `weltmarkt_metrics` AS metric ON metric.`source_id` = old_source.`id`
WHERE old_source.`slug` = 'bundesbank-local-snapshot'
  AND metric.`metric_id` IS NULL;

-- Fetch the IMF commodity indices directly instead of through the slower FRED mirror.
INSERT INTO `weltmarkt_data_sources`
  (`slug`, `display_name`, `provider_type`, `base_url`, `license_note`, `retrieved_at`)
VALUES
  ('imf-commodity-xlsx', 'IMF Primary Commodity Price System workbook', 'official_xlsx',
   'https://www.imf.org/-/media/files/research/commodityprices/monthly/external-data.xlsx',
   'Official IMF monthly commodity indices; the published workbook may revise recent observations and must retain IMF attribution.',
   UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `display_name` = VALUES(`display_name`),
  `provider_type` = VALUES(`provider_type`),
  `base_url` = VALUES(`base_url`),
  `license_note` = VALUES(`license_note`);

UPDATE `weltmarkt_metrics`
SET `source_id` = (SELECT `id` FROM `weltmarkt_data_sources` WHERE `slug` = 'imf-commodity-xlsx'),
    `source_series` = CASE `metric_id`
      WHEN 'world_commodity_index' THEN 'PALLFNF'
      WHEN 'world_energy_index' THEN 'PNRG'
      WHEN 'world_food_index' THEN 'PFOOD'
      WHEN 'world_metals_index' THEN 'PMETA'
      ELSE `source_series`
    END,
    `methodology_note` = CASE `metric_id`
      WHEN 'world_commodity_index' THEN 'IMF Primary Commodity Prices all-commodities index from the official monthly workbook, 2016=100; native monthly observations only.'
      WHEN 'world_energy_index' THEN 'IMF Primary Commodity Prices energy index from the official monthly workbook, 2016=100; native monthly observations only.'
      WHEN 'world_food_index' THEN 'IMF Primary Commodity Prices food index from the official monthly workbook, 2016=100; native monthly observations only.'
      WHEN 'world_metals_index' THEN 'IMF Primary Commodity Prices metals index from the official monthly workbook, 2016=100; native monthly observations only.'
      ELSE `methodology_note`
    END
WHERE `metric_id` IN ('world_commodity_index', 'world_energy_index', 'world_food_index', 'world_metals_index');

UPDATE `weltmarkt_data_quality`
SET `notes` = REPLACE(`notes`, 'via public FRED CSV', 'from the official monthly IMF workbook')
WHERE `metric_id` IN ('world_commodity_index', 'world_energy_index', 'world_food_index', 'world_metals_index');

INSERT INTO `weltmarkt_export_manifest` (`manifest_key`, `manifest_value`, `generated_at`) VALUES
  ('automatic_imports', 'enabled', UTC_TIMESTAMP()),
  ('import_schedule', 'Tuesday-Saturday market/yield/daily-policy jobs, weekly Fed job and three staggered monthly jobs', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `manifest_value` = VALUES(`manifest_value`),
  `generated_at` = VALUES(`generated_at`);
