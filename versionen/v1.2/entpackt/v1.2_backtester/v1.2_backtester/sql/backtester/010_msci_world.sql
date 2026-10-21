-- MSCI World price-index history and annual dividend schedule.
-- Run after 008_multpl_sp500_dividend_yields.sql.

-- STRATO production database. This also makes phpMyAdmin imports started from
-- the server-level Import tab deterministic.
INSERT INTO backtester_data_sources
    (slug, display_name, provider_type, base_url, license_note, attribution_text, is_active)
VALUES
    ('msci-world-manual-csv', 'MSCI + EZB', 'manual_csv',
     'https://www.msci.com/indexes/index/990100/msci-world-index',
     'MSCI-Nutzungsbedingungen gelten; bereitgestellte Exportdateien werden lokal für die private Auswertung verwendet.',
     'MSCI-World-Preisindex: bereitgestellte MSCI-Exportdateien. EUR-Umrechnung: historische EZB-Referenzkurse.',
     1)
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    provider_type = VALUES(provider_type),
    base_url = VALUES(base_url),
    license_note = VALUES(license_note),
    attribution_text = VALUES(attribution_text),
    is_active = VALUES(is_active);

INSERT INTO backtester_assets
    (source_id, slug, display_name, short_name, asset_class, region,
     provider_symbol, provider_dataset, identifier_type, identifier_value,
     raw_currency, display_currency, value_type, native_frequency, calendar_type,
     timezone_name, proxy_description, methodology_note, first_expected_date,
     sort_order, is_default_selected, is_active)
VALUES
    ((SELECT id FROM backtester_data_sources WHERE slug = 'msci-world-manual-csv'),
     'msci_world', 'MSCI World', 'MSCI World', 'equity', 'Developed Markets',
     'MSCI_WORLD_PRICE_USD', 'MSCI World Price Index; Monats- und Tagesexport',
     'msci_index_code', '990100',
     'USD', 'EUR', 'index_level', 'daily', 'market_weekdays', 'UTC',
     'MSCI-World-Preisindex in EUR einschließlich am 31.12. reinvestierter Jahresdividenden.',
     'Monatswerte bis Juli 2022 und Tageswerte ab August 2022 werden mit dem historischen EZB-USD/EUR-Referenzkurs umgerechnet. Die hinterlegte Jahresdividendenrendite wird vollständig am 31.12. gutgeschrieben.',
     '2016-07-31', 40, 0, 1)
ON DUPLICATE KEY UPDATE
    source_id = VALUES(source_id),
    display_name = VALUES(display_name),
    short_name = VALUES(short_name),
    asset_class = VALUES(asset_class),
    region = VALUES(region),
    provider_symbol = VALUES(provider_symbol),
    provider_dataset = VALUES(provider_dataset),
    identifier_type = VALUES(identifier_type),
    identifier_value = VALUES(identifier_value),
    raw_currency = VALUES(raw_currency),
    display_currency = VALUES(display_currency),
    value_type = VALUES(value_type),
    native_frequency = VALUES(native_frequency),
    calendar_type = VALUES(calendar_type),
    timezone_name = VALUES(timezone_name),
    proxy_description = VALUES(proxy_description),
    methodology_note = VALUES(methodology_note),
    first_expected_date = VALUES(first_expected_date),
    sort_order = VALUES(sort_order),
    is_default_selected = VALUES(is_default_selected),
    is_active = VALUES(is_active);

INSERT INTO backtester_sync_state (asset_id, next_check_at)
SELECT id, NULL
FROM backtester_assets
WHERE slug = 'msci_world'
ON DUPLICATE KEY UPDATE asset_id = VALUES(asset_id);

INSERT INTO backtester_dividend_yields
    (asset_id, observation_month, source_observation_date, annual_yield_pct,
     is_estimate, source_url, source_revision, raw_payload_hash)
VALUES
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2016-12-01', '2016-12-31', 2.690000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2016|2.69', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2017-12-01', '2017-12-31', 2.460000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2017|2.46', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2018-12-01', '2018-12-31', 2.500000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2018|2.50', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2019-12-01', '2019-12-31', 2.560000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2019|2.56', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2020-12-01', '2020-12-31', 2.140000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2020|2.14', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2021-12-01', '2021-12-31', 1.840000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2021|1.84', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2022-12-01', '2022-12-31', 2.150000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2022|2.15', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2023-12-01', '2023-12-31', 2.180000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2023|2.18', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2024-12-01', '2024-12-31', 1.870000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2024|1.87', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2025-12-01', '2025-12-31', 1.770000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2025|1.77', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2026-12-01', '2026-12-31', 1.650000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2026|1.65', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2027-12-01', '2027-12-31', 1.700000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2027|1.70', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2028-12-01', '2028-12-31', 1.750000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2028|1.75', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2029-12-01', '2029-12-31', 1.800000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2029|1.80', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'msci_world'), '2030-12-01', '2030-12-31', 1.850000, 0, '', 'manual-annual-schedule-v1', SHA2('msci_world|2030|1.85', 256))
ON DUPLICATE KEY UPDATE
    source_observation_date = VALUES(source_observation_date),
    annual_yield_pct = VALUES(annual_yield_pct),
    is_estimate = VALUES(is_estimate),
    source_url = VALUES(source_url),
    source_revision = VALUES(source_revision),
    raw_payload_hash = VALUES(raw_payload_hash),
    fetched_at = UTC_TIMESTAMP();
