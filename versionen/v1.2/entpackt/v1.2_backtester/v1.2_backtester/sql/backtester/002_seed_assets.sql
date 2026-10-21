-- V1 seed data. Replace both CONFIGURE_* symbols after checking provider
-- coverage, licensing and the chosen EUR proxy products.

INSERT INTO backtester_data_sources
    (slug, display_name, provider_type, base_url, license_note, attribution_text, is_active)
VALUES
    ('alpha-vantage-market', 'Alpha Vantage', 'alpha_vantage', 'https://www.alphavantage.co/',
     'Preis, Historientiefe, Speicherung und öffentliche Darstellung vor Produktionsfreigabe vertraglich prüfen.',
     'Marktdaten: Alpha Vantage; Proxy-Produkt und Börsenplatz werden vor Veröffentlichung festgelegt.', 1),
    ('coingecko-bitcoin', 'Bitstamp/Kaggle + EZB + CoinGecko', 'coingecko', 'https://www.coingecko.com/',
     'BTC/USD-Historie: Kaggle-Datensatz "Bitcoin Historical Data", CC BY-SA 4.0. Wechselkurse: EZB-Referenzkurse, Quelle nennen und abgeleitete Umrechnung kennzeichnen. Laufende BTC/EUR-Aktualisierung: CoinGecko-Bedingungen und API-Tarif beachten.',
     'Historie: BTC/USD-Minutenwerte aus "Bitcoin Historical Data" (Zielak/Kaggle, CC BY-SA 4.0), historisch in EUR umgerechnet mit EZB-USD/EUR-Referenzkursen. Laufende Aktualisierung: CoinGecko BTC/EUR.', 1)
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
    ((SELECT id FROM backtester_data_sources WHERE slug = 'alpha-vantage-market'),
     'sp500', 'S&P 500', 'S&P 500', 'equity', 'USA',
     'CONFIGURE_SP500_EUR_TOTAL_RETURN_PROXY', 'TIME_SERIES_DAILY_ADJUSTED', NULL, NULL,
     'EUR', 'EUR', 'adjusted_close', 'daily', 'market_weekdays', 'Europe/Berlin',
     'Thesaurierender, in EUR notierender UCITS-ETF-Proxy; konkretes Produkt vor Release festlegen.',
     'Adjusted Close soll Ausschüttungen und Splits berücksichtigen. Kein lizenzierter Originalindexstand.',
     NULL, 10, 1, 1),
    ((SELECT id FROM backtester_data_sources WHERE slug = 'coingecko-bitcoin'),
     'bitcoin', 'Bitcoin', 'Bitcoin', 'crypto', 'Global',
     'bitcoin', 'Kaggle btcusd_1-min_data + ECB EXR; laufend CoinGecko market_chart/range', 'coingecko_id', 'bitcoin',
     'EUR', 'EUR', 'spot_reference', 'daily', 'calendar_daily', 'UTC',
     'Bitcoin in Euro: zehnjährige Bitstamp/Kaggle-BTC/USD-Historie mit historischen EZB-USD/EUR-Referenzkursen; laufende BTC/EUR-Aktualisierung über CoinGecko.',
     'Historie bis 02.08.2026: letzter gültiger BTC/USD-Minutenschluss je UTC-Tag, geteilt durch den am selben Tag oder zuletzt zuvor veröffentlichten EZB-Kurs USD je EUR. Ab 03.08.2026: letzte gelieferte CoinGecko-BTC/EUR-Beobachtung je abgeschlossenem UTC-Tag. Der Quellenwechsel ist offengelegt.',
     '2016-08-02', 20, 1, 1),
    ((SELECT id FROM backtester_data_sources WHERE slug = 'alpha-vantage-market'),
     'gold', 'Gold', 'Gold', 'commodity', 'Global',
     'CONFIGURE_GOLD_EUR_PROXY', 'TIME_SERIES_DAILY_ADJUSTED', NULL, NULL,
     'EUR', 'EUR', 'adjusted_close', 'daily', 'market_weekdays', 'Europe/Berlin',
     'Investierbarer, in EUR notierender Gold-ETC-Proxy; konkretes Produkt vor Release festlegen.',
     'V1 nutzt genau einen dokumentierten EUR-Gold-Proxy. Spotpreis und Proxy dürfen nicht vermischt werden.',
     NULL, 30, 1, 1)
ON DUPLICATE KEY UPDATE
    source_id = VALUES(source_id),
    display_name = VALUES(display_name),
    short_name = VALUES(short_name),
    asset_class = VALUES(asset_class),
    region = VALUES(region),
    provider_dataset = VALUES(provider_dataset),
    raw_currency = VALUES(raw_currency),
    display_currency = VALUES(display_currency),
    value_type = VALUES(value_type),
    native_frequency = VALUES(native_frequency),
    calendar_type = VALUES(calendar_type),
    timezone_name = VALUES(timezone_name),
    proxy_description = VALUES(proxy_description),
    methodology_note = VALUES(methodology_note),
    sort_order = VALUES(sort_order),
    is_default_selected = VALUES(is_default_selected),
    is_active = VALUES(is_active);

INSERT INTO backtester_sync_state (asset_id, next_check_at)
SELECT id, UTC_TIMESTAMP()
FROM backtester_assets
WHERE slug IN ('sp500', 'bitcoin', 'gold')
ON DUPLICATE KEY UPDATE asset_id = VALUES(asset_id);
