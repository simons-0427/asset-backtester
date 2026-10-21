-- Switch only future gold updates to GoldAPI XAU/USD.
--
-- Existing observations through 2026-08-07 remain untouched. GoldAPI XAU
-- values are calibrated in the application with the fixed ratio configured
-- in backtester.php, so the existing GLD-like EUR series continues without a
-- historical backfill. This is intentionally a forward-only source switch.

INSERT INTO backtester_data_sources
    (slug, display_name, provider_type, base_url, license_note, attribution_text, is_active)
VALUES
    ('goldapi-forward-calibrated', 'GoldAPI.io', 'goldapi', 'https://www.goldapi.io/api',
     'GoldAPI-Tarif, historische Abrufe, Speicherung und öffentliche Darstellung vor dem produktiven Einsatz anhand des aktiven Kontos und der Anbieterbedingungen prüfen.',
     'Goldpreis: GoldAPI.io. Zukünftige USD-Werte werden mit dem im Backtester hinterlegten Kalibrierungsverhältnis in die bestehende Goldreihe überführt; EUR-Umrechnung: EZB-Referenzkurs USD je EUR.',
     1)
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    provider_type = VALUES(provider_type),
    base_url = VALUES(base_url),
    license_note = VALUES(license_note),
    attribution_text = VALUES(attribution_text),
    is_active = VALUES(is_active);

UPDATE backtester_assets
SET source_id = (SELECT id FROM backtester_data_sources WHERE slug = 'goldapi-forward-calibrated'),
    provider_symbol = 'XAU',
    provider_dataset = 'USD',
    identifier_type = 'metal_pair',
    identifier_value = 'XAU/USD',
    raw_currency = 'USD',
    display_currency = 'EUR',
    value_type = 'close',
    native_frequency = 'daily',
    calendar_type = 'market_weekdays',
    timezone_name = 'UTC',
    proxy_description = 'Gold in EUR als tägliche Vergleichsreihe.',
    methodology_note = 'Historische Werte bis 07.08.2026 bleiben unverändert. Neue GoldAPI-XAU/USD-Tageswerte werden mit dem festgelegten Verhältnis 398,47 / 4.341,91 in die bestehende Goldreihe überführt und anschließend mit dem EZB-Referenzkurs in EUR umgerechnet.',
    first_expected_date = '2016-08-02'
WHERE slug = 'gold';

UPDATE backtester_sync_state ss
JOIN backtester_assets a ON a.id = ss.asset_id
SET ss.next_check_at = UTC_TIMESTAMP(),
    ss.consecutive_failures = 0,
    ss.last_error_at = NULL,
    ss.last_error_code = NULL,
    ss.last_error_message = NULL
WHERE a.slug = 'gold';
