-- Emergency backfill for the existing GLD proxy, 04.-07.08.2026.
--
-- This deliberately writes GLD closes, not XAU spot prices.  The existing
-- backtester gold series is SPDR Gold Shares (GLD), converted from USD to EUR
-- with the ECB USD/EUR reference rate.  Do not replace these values with
-- GoldAPI XAU/USD values without creating/rebasing a separate spot series.

INSERT INTO backtester_observations (
    asset_id, observation_date, raw_value, raw_currency, eur_value,
    fx_rate_to_eur, fx_observation_date, source_timestamp, fetched_at,
    quality_status, source_revision, raw_payload_hash
)
SELECT a.id, v.observation_date, v.raw_value, 'USD', v.eur_value,
       v.fx_rate_to_eur, v.fx_observation_date, NULL, UTC_TIMESTAMP(),
       'valid', v.source_revision, v.raw_payload_hash
FROM backtester_assets a
JOIN (
    SELECT CAST('2026-08-04' AS DATE) AS observation_date,
           374.16 AS raw_value,
           324.9326964828 AS eur_value,
           0.868432479375 AS fx_rate_to_eur,
           CAST('2026-08-04' AS DATE) AS fx_observation_date,
           'spdr-gld-official:2026-08-04;ecb-usd-eur:2026-08-04' AS source_revision,
           '46c0a6bfb5d2fd8ee50d273a1e3ef128154eede6d059041317fe523f8acb6fa8' AS raw_payload_hash
    UNION ALL SELECT CAST('2026-08-05' AS DATE), 389.64, 337.2338584040,
           0.865501125151, CAST('2026-08-05' AS DATE),
           'spdr-gld-official:2026-08-05;ecb-usd-eur:2026-08-05',
           'adcdd302f966f5f3a11e7e9ad21843ce356cde023ba1b8ae1952317ce3612f07'
    UNION ALL SELECT CAST('2026-08-06' AS DATE), 389.67, 337.6104661237,
           0.866400970369, CAST('2026-08-06' AS DATE),
           'spdr-gld-official:2026-08-06;ecb-usd-eur:2026-08-06',
           '5dbfa9365ce5fac07edb0078a48281f49ef67abd981636236313f22c73b4aad5'
    UNION ALL SELECT CAST('2026-08-07' AS DATE), 398.47, 345.4442999567,
           0.866926744690, CAST('2026-08-07' AS DATE),
           'yahoo-finance-gld:2026-08-07;ecb-usd-eur:2026-08-07',
           '1fc4fbdb7a8fb9e53f2e4b742810b2170f0da85c3f442d68389c8fd35693cd03'
) v ON 1 = 1
WHERE a.slug = 'gold'
ON DUPLICATE KEY UPDATE
    raw_value = VALUES(raw_value),
    raw_currency = VALUES(raw_currency),
    eur_value = VALUES(eur_value),
    fx_rate_to_eur = VALUES(fx_rate_to_eur),
    fx_observation_date = VALUES(fx_observation_date),
    source_timestamp = VALUES(source_timestamp),
    fetched_at = UTC_TIMESTAMP(),
    quality_status = VALUES(quality_status),
    source_revision = VALUES(source_revision),
    raw_payload_hash = VALUES(raw_payload_hash);

UPDATE backtester_sync_state ss
JOIN backtester_assets a ON a.id = ss.asset_id
SET ss.latest_observation_date = GREATEST(
        COALESCE(ss.latest_observation_date, '1000-01-01'),
        '2026-08-07'
    ),
    ss.backfill_cursor_date = GREATEST(
        COALESCE(ss.backfill_cursor_date, '1000-01-01'),
        '2026-08-07'
    )
WHERE a.slug = 'gold';
