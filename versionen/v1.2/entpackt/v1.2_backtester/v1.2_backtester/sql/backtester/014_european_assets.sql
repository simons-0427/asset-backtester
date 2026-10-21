-- DAX price index, EURO STOXX 50 price index and German residential-property prices.
-- Historical price observations are imported separately from var/import/*.csv.
-- Annual equity dividend factors are credited in full on 31 December.
INSERT INTO backtester_data_sources
    (slug, display_name, provider_type, base_url, license_note, attribution_text, is_active)
VALUES
    ('dax-price-manual-csv', 'DAX-Kursindex via Yahoo Finance', 'manual_csv',
     'https://finance.yahoo.com/quote/%5EGDAXIP',
     'Öffentlich abrufbare, weiterveröffentlichte Indexstände; private Auswertung. Kein lizenzierter Deutsche-Börse-Datensatz.',
     'DAX-Kursindex (DAXK), ISIN DE0008467440, öffentlich weiterveröffentlichte Schlussstände via Yahoo Finance.', 1),
    ('eurostoxx50-price-manual-csv', 'EURO STOXX 50 Kursindex via Yahoo Finance', 'manual_csv',
     'https://finance.yahoo.com/quote/%5ESTOXX50E',
     'Öffentlich abrufbare, weiterveröffentlichte Indexstände; private Auswertung. Kein lizenzierter STOXX-Datensatz.',
     'EURO STOXX 50 Price Index (SX5E), öffentlich weiterveröffentlichte Schlussstände via Yahoo Finance.', 1),
    ('eurostat-de-house-price-index', 'Eurostat', 'manual_csv',
     'https://ec.europa.eu/eurostat/web/housing-price-statistics/information-data',
     'Eurostat-Daten dürfen mit Quellenangabe weiterverwendet werden; die Reihe kann revidiert werden.',
     'Eurostat House Price Index, Deutschland, Gesamtmarkt, prc_hpi_q, purchase=TOTAL, unit=I15_Q.', 1)
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
    ((SELECT id FROM backtester_data_sources WHERE slug = 'dax-price-manual-csv'),
     'dax', 'DAX', 'DAX', 'equity', 'Germany',
     '^GDAXIP', 'DAX Price Index (DAXK), daily close',
     'isin', 'DE0008467440',
     'PTS', 'PTS', 'index_level', 'daily', 'market_weekdays', 'Europe/Berlin',
     'DAX-Kursindex plus am 31.12. reinvestierter Jahresdividende.',
     'Die Preislinie verwendet den DAX-Kursindex. Für 2016 bis 2025 wird der Jahresfaktor aus DAX-Performanceindex und DAX-Kursindex abgeleitet. Ab 2026 wird der hinterlegte Basiswert der Jahresplanung verwendet. Die Gutschrift erfolgt vollständig am 31.12.',
     '2016-01-04', 60, 0, 1),
    ((SELECT id FROM backtester_data_sources WHERE slug = 'eurostoxx50-price-manual-csv'),
     'eurostoxx50', 'EURO STOXX 50', 'EURO STOXX 50', 'equity', 'Euro Area',
     '^STOXX50E', 'EURO STOXX 50 Price Index (SX5E), daily close',
     'index_code', 'SX5E',
     'PTS', 'PTS', 'index_level', 'daily', 'market_weekdays', 'Europe/Berlin',
     'EURO-STOXX-50-Kursindex plus am 31.12. reinvestierter Jahresdividende.',
     'Die Preislinie verwendet den EURO STOXX 50 Price Index. Der historische Netto-Dividendenfaktor ist aus der Preisreihe und einem thesaurierenden UCITS-ETF-Proxy abgeleitet; er ist kein offizieller SX5T-Index. Ab 2026 wird der hinterlegte Basiswert der Jahresplanung verwendet. Die Gutschrift erfolgt vollständig am 31.12.',
     '2016-01-04', 70, 0, 1),
    ((SELECT id FROM backtester_data_sources WHERE slug = 'eurostat-de-house-price-index'),
     'de_real_estate', 'Immobilien DE', 'Immobilien DE', 'real_estate', 'Germany',
     'prc_hpi_q:DE:TOTAL:I15_Q', 'Eurostat House Price Index, total dwellings, quarterly',
     'eurostat_series', 'prc_hpi_q',
     'PTS', 'PTS', 'index_level', 'quarterly', 'native_periods', 'UTC',
     'Deutscher Wohnimmobilienpreisindex plus einstellbarer Netto-Mietcashflow.',
     'Die offiziellen Eurostat-Quartalswerte werden bis zur nächsten Veröffentlichung fortgeführt. Der frei einstellbare Netto-Mietcashflow wird linear auf das Startkapital gerechnet, als Cash gehalten und nicht reinvestiert.',
     '2016-03-31', 45, 1, 1)
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

UPDATE backtester_assets
SET is_default_selected = CASE
    WHEN slug IN ('sp500', 'bitcoin', 'gold', 'msci_world', 'de_real_estate') THEN 1
    ELSE 0
END
WHERE slug IN ('sp500', 'bitcoin', 'gold', 'msci_world', 'de_real_estate',
               'us_real_estate', 'dax', 'eurostoxx50');

INSERT INTO backtester_sync_state (asset_id, next_check_at)
SELECT id, NULL
FROM backtester_assets
WHERE slug IN ('dax', 'eurostoxx50', 'de_real_estate')
ON DUPLICATE KEY UPDATE next_check_at = VALUES(next_check_at);

INSERT INTO backtester_dividend_yields
    (asset_id, observation_month, source_observation_date, annual_yield_pct,
     is_estimate, source_url, source_revision, raw_payload_hash)
VALUES
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2016-12-01', '2016-12-31', 3.084200, 0, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'dax-vs-daxk-annual-v1', SHA2('dax|2016|3.0842', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2017-12-01', '2017-12-31', 2.699100, 0, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'dax-vs-daxk-annual-v1', SHA2('dax|2017|2.6991', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2018-12-01', '2018-12-31', 2.912600, 0, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'dax-vs-daxk-annual-v1', SHA2('dax|2018|2.9126', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2019-12-01', '2019-12-31', 3.247800, 0, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'dax-vs-daxk-annual-v1', SHA2('dax|2019|3.2478', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2020-12-01', '2020-12-31', 3.099800, 0, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'dax-vs-daxk-annual-v1', SHA2('dax|2020|3.0998', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2021-12-01', '2021-12-31', 2.424600, 0, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'dax-vs-daxk-annual-v1', SHA2('dax|2021|2.4246', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2022-12-01', '2022-12-31', 3.324000, 0, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'dax-vs-daxk-annual-v1', SHA2('dax|2022|3.3240', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2023-12-01', '2023-12-31', 3.308300, 0, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'dax-vs-daxk-annual-v1', SHA2('dax|2023|3.3083', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2024-12-01', '2024-12-31', 3.004000, 0, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'dax-vs-daxk-annual-v1', SHA2('dax|2024|3.0040', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2025-12-01', '2025-12-31', 2.544000, 0, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'dax-vs-daxk-annual-v1', SHA2('dax|2025|2.5440', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2026-12-01', '2026-12-31', 2.832100, 1, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'base-plan-2026-2030-v1', SHA2('dax|2026|2.8321', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2027-12-01', '2027-12-31', 2.832100, 1, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'base-plan-2026-2030-v1', SHA2('dax|2027|2.8321', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2028-12-01', '2028-12-31', 2.832100, 1, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'base-plan-2026-2030-v1', SHA2('dax|2028|2.8321', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2029-12-01', '2029-12-31', 2.832100, 1, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'base-plan-2026-2030-v1', SHA2('dax|2029|2.8321', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'dax'), '2030-12-01', '2030-12-31', 2.832100, 1, 'https://finance.yahoo.com/quote/%5EGDAXIP', 'base-plan-2026-2030-v1', SHA2('dax|2030|2.8321', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2016-12-01', '2016-12-31', 3.491000, 0, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'sx5e-vs-etf-proxy-annual-v1', SHA2('eurostoxx50|2016|3.4910', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2017-12-01', '2017-12-31', 3.495200, 0, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'sx5e-vs-etf-proxy-annual-v1', SHA2('eurostoxx50|2017|3.4952', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2018-12-01', '2018-12-31', 3.086300, 0, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'sx5e-vs-etf-proxy-annual-v1', SHA2('eurostoxx50|2018|3.0863', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2019-12-01', '2019-12-31', 3.686100, 0, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'sx5e-vs-etf-proxy-annual-v1', SHA2('eurostoxx50|2019|3.6861', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2020-12-01', '2020-12-31', 1.881800, 0, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'sx5e-vs-etf-proxy-annual-v1', SHA2('eurostoxx50|2020|1.8818', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2021-12-01', '2021-12-31', 2.450100, 0, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'sx5e-vs-etf-proxy-annual-v1', SHA2('eurostoxx50|2021|2.4501', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2022-12-01', '2022-12-31', 3.518900, 0, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'sx5e-vs-etf-proxy-annual-v1', SHA2('eurostoxx50|2022|3.5189', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2023-12-01', '2023-12-31', 2.764600, 0, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'sx5e-vs-etf-proxy-annual-v1', SHA2('eurostoxx50|2023|2.7646', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2024-12-01', '2024-12-31', 3.150800, 0, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'sx5e-vs-etf-proxy-annual-v1', SHA2('eurostoxx50|2024|3.1508', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2025-12-01', '2025-12-31', 2.666500, 0, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'sx5e-vs-etf-proxy-annual-v1', SHA2('eurostoxx50|2025|2.6665', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2026-12-01', '2026-12-31', 3.000000, 1, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'base-plan-2026-2030-v1', SHA2('eurostoxx50|2026|3.0000', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2027-12-01', '2027-12-31', 3.000000, 1, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'base-plan-2026-2030-v1', SHA2('eurostoxx50|2027|3.0000', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2028-12-01', '2028-12-31', 3.000000, 1, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'base-plan-2026-2030-v1', SHA2('eurostoxx50|2028|3.0000', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2029-12-01', '2029-12-31', 3.000000, 1, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'base-plan-2026-2030-v1', SHA2('eurostoxx50|2029|3.0000', 256)),
    ((SELECT id FROM backtester_assets WHERE slug = 'eurostoxx50'), '2030-12-01', '2030-12-31', 3.000000, 1, 'https://finance.yahoo.com/quote/%5ESTOXX50E', 'base-plan-2026-2030-v1', SHA2('eurostoxx50|2030|3.0000', 256))
ON DUPLICATE KEY UPDATE
    source_observation_date = VALUES(source_observation_date),
    annual_yield_pct = VALUES(annual_yield_pct),
    is_estimate = VALUES(is_estimate),
    source_url = VALUES(source_url),
    source_revision = VALUES(source_revision),
    raw_payload_hash = VALUES(raw_payload_hash),
    fetched_at = UTC_TIMESTAMP();
