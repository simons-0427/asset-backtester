START TRANSACTION;

INSERT INTO `weltmarkt_data_sources` (`slug`, `display_name`, `provider_type`, `base_url`, `license_note`, `retrieved_at`) VALUES
  ('ecb-api', 'European Central Bank Data Portal API', 'official_api', 'https://data-api.ecb.europa.eu/service/data/', 'Official ECB Data Portal observations; retain the full FM series key and native daily dates.', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `display_name` = VALUES(`display_name`),
  `provider_type` = VALUES(`provider_type`),
  `base_url` = VALUES(`base_url`),
  `license_note` = VALUES(`license_note`);

UPDATE `weltmarkt_metrics`
SET `source_id` = (SELECT `id` FROM `weltmarkt_data_sources` WHERE `slug` = 'ecb-api'),
    `methodology_note` = 'ECB main refinancing minimum bid or fixed rate from the official ECB Data Portal, with native daily dates retained.'
WHERE `metric_id` = 'ecb_rate';

UPDATE `weltmarkt_sync_jobs`
SET `frequency` = 'daily',
    `label` = 'Leitzinsen (tägliche Reihen)',
    `next_check_at` = UTC_TIMESTAMP()
WHERE `job_key` = 'policy_daily';

UPDATE `weltmarkt_sync_jobs`
SET `label` = 'BIP, Welthandel, PBoC-LPR, Notenbankbilanzen und Bewertung'
WHERE `job_key` = 'monthly_late';

UPDATE `weltmarkt_metrics`
SET `native_frequency` = 'monthly'
WHERE `metric_id` = 'pboc_rate';

INSERT INTO `weltmarkt_export_manifest` (`manifest_key`, `manifest_value`, `generated_at`) VALUES
  ('source_count', '38', UTC_TIMESTAMP()),
  ('provider_request_policy', 'Yahoo markets use two batched requests; market, yield and four daily policy-rate series run Tuesday through Saturday; ECB policy rates come directly from the official ECB Data Portal; PBoC LPR and other monthly/quarterly series are checked once per month.', UTC_TIMESTAMP()),
  ('import_schedule', 'Tuesday-Saturday market/yield/daily-policy jobs, weekly Fed job and three staggered monthly jobs', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `manifest_value` = VALUES(`manifest_value`),
  `generated_at` = VALUES(`generated_at`);

COMMIT;
