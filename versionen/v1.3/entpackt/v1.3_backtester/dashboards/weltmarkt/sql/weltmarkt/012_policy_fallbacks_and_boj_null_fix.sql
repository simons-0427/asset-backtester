START TRANSACTION;

DELETE FROM `weltmarkt_observations`
WHERE `metric_id` = 'boj_bs'
  AND `observation_date` = '2026-09-01'
  AND `value` = 0;

UPDATE `weltmarkt_metrics` AS m
SET m.`coverage_start` = (
        SELECT MIN(o.`observation_date`)
        FROM `weltmarkt_observations` AS o
        WHERE o.`metric_id` = m.`metric_id`
    ),
    m.`coverage_end` = (
        SELECT MAX(o.`observation_date`)
        FROM `weltmarkt_observations` AS o
        WHERE o.`metric_id` = m.`metric_id`
    ),
    m.`observation_count` = (
        SELECT COUNT(*)
        FROM `weltmarkt_observations` AS o
        WHERE o.`metric_id` = m.`metric_id`
    ),
    m.`availability_status` = CASE
        WHEN (
            SELECT COUNT(*)
            FROM `weltmarkt_observations` AS o
            WHERE o.`metric_id` = m.`metric_id`
        ) = 0 THEN 'unavailable'
        WHEN (
            SELECT MAX(o.`observation_date`)
            FROM `weltmarkt_observations` AS o
            WHERE o.`metric_id` = m.`metric_id`
        ) >= UTC_DATE() - INTERVAL 45 DAY THEN 'ready'
        ELSE 'partial'
    END,
    m.`methodology_note` = 'BOJ Accounts total assets in 100 million yen divided by 10000; unpublished null placeholders are omitted.'
WHERE m.`metric_id` = 'boj_bs';

UPDATE `weltmarkt_data_quality` AS q
INNER JOIN `weltmarkt_metrics` AS m ON m.`metric_id` = q.`metric_id`
SET q.`expected_end` = m.`coverage_end`,
    q.`observation_count` = m.`observation_count`,
    q.`valid_value_count` = (
        SELECT COUNT(*)
        FROM `weltmarkt_observations` AS o
        WHERE o.`metric_id` = m.`metric_id`
          AND o.`quality_status` <> 'flagged'
    ),
    q.`coverage_start` = m.`coverage_start`,
    q.`coverage_end` = m.`coverage_end`,
    q.`availability_status` = m.`availability_status`,
    q.`notes` = CASE
        WHEN q.`notes` LIKE '%unpublished null placeholders are omitted%'
            THEN q.`notes`
        ELSE CONCAT(q.`notes`, ' BOJ unpublished null placeholders are omitted.')
    END,
    q.`checked_at` = UTC_TIMESTAMP()
WHERE q.`metric_id` = 'boj_bs';

INSERT INTO `weltmarkt_export_manifest` (`manifest_key`, `manifest_value`, `generated_at`)
SELECT 'observation_count', CAST(COUNT(*) AS CHAR), UTC_TIMESTAMP()
FROM `weltmarkt_observations`
ON DUPLICATE KEY UPDATE
  `manifest_value` = VALUES(`manifest_value`),
  `generated_at` = VALUES(`generated_at`);

INSERT INTO `weltmarkt_export_manifest` (`manifest_key`, `manifest_value`, `generated_at`) VALUES
  ('provider_request_policy', 'Daily policy rates use the free official sources first. If the ECB CSV endpoint fails, the same ECB series is read from DBnomics; if the Bank of England CSV export fails, the official HTML data table for IUDBEDR is used. Unpublished BOJ balance-sheet nulls are ignored.', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `manifest_value` = VALUES(`manifest_value`),
  `generated_at` = VALUES(`generated_at`);

UPDATE `weltmarkt_sync_jobs`
SET `next_check_at` = UTC_TIMESTAMP()
WHERE `job_key` = 'policy_daily';

COMMIT;
