-- Reduce long-term provider requests after automatic imports are installed.
-- Safe to run repeatedly after 009_automatic_imports.sql.

START TRANSACTION;

UPDATE `weltmarkt_sync_jobs`
SET `frequency` = 'weekly',
    `label` = 'Leitzinsen (wöchentliche Prüfung)'
WHERE `job_key` = 'policy_daily';

INSERT INTO `weltmarkt_export_manifest` (`manifest_key`, `manifest_value`, `generated_at`) VALUES
  ('provider_request_policy', 'Yahoo markets use two batched requests; market and yield jobs run Tuesday through Saturday; policy rates run weekly; monthly and quarterly series are checked once per month.', UTC_TIMESTAMP()),
  ('import_schedule', 'Tuesday-Saturday market/yield jobs, weekly policy/Fed jobs and three staggered monthly jobs', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `manifest_value` = VALUES(`manifest_value`),
  `generated_at` = VALUES(`generated_at`);

COMMIT;
