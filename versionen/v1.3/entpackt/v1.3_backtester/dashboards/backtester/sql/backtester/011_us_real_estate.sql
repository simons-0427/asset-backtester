-- United States residential-property price index for the backtester.
-- Official FHFA monthly purchase-only HPI, seasonally adjusted.
INSERT INTO backtester_data_sources
    (slug, display_name, provider_type, base_url, license_note, attribution_text, is_active)
VALUES
    ('fhfa-us-house-price-index', 'U.S. Federal Housing Finance Agency via FRED', 'fred_csv',
     'https://fred.stlouisfed.org/series/HPIPONM226S',
     'Public Domain: Quellenangabe erwünscht. Die Reihe kann revidiert werden.',
     'U.S. Federal Housing Finance Agency, Purchase Only House Price Index for the United States [HPIPONM226S], via FRED, Federal Reserve Bank of St. Louis.',
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
    ((SELECT id FROM backtester_data_sources WHERE slug = 'fhfa-us-house-price-index'),
     'us_real_estate', 'Immobilien USA', 'Immobilien USA', 'real_estate', 'United States',
     'HPIPONM226S', 'FHFA Purchase Only House Price Index, seasonally adjusted, monthly',
     'fred_series', 'HPIPONM226S',
     'PTS', 'PTS', 'index_level', 'monthly', 'native_periods', 'UTC',
     'US-Wohnimmobilienpreisindex plus einstellbarer Netto-Mietcashflow.',
     'Die FHFA-Monatswerte werden bis zum nächsten echten Monatswert fortgeführt. Der Netto-Mietcashflow wird linear auf das Startkapital gerechnet, als Cash gehalten und nicht reinvestiert.',
     '2016-01-01', 50, 0, 1)
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
WHERE slug = 'us_real_estate'
ON DUPLICATE KEY UPDATE next_check_at = NULL;
