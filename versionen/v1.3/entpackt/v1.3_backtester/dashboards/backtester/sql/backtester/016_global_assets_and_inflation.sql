-- Global investment-grade bonds, broad commodities, money markets and inflation indices.
-- Historical observations are imported separately from var/import/*.csv.
INSERT INTO backtester_data_sources
    (slug, display_name, provider_type, base_url, license_note, attribution_text, is_active)
VALUES
    ('ishares-global-aggregate-eur-hedged', 'iShares / BlackRock', 'manual_csv',
     'https://www.ishares.com/uk/individual/en/products/291770/ishares-core-global-aggregate-bond-ucits-etf',
     'Private Auswertung. Produkt- und Marktdaten unterliegen den Bedingungen von iShares und der jeweiligen Börse.',
     'iShares Core Global Aggregate Bond UCITS ETF EUR Hedged Acc, ISIN IE00BDBRDM35.', 1),
    ('ishares-global-aggregate-unhedged', 'iShares / BlackRock', 'manual_csv',
     'https://www.ishares.com/uk/individual/en/products/291773/ishares-core-global-aggregate-bond-ucits-etf',
     'Private Auswertung. Produkt-, Markt- und Wechselkursdaten unterliegen den Bedingungen der jeweiligen Anbieter.',
     'iShares Core Global Aggregate Bond UCITS ETF USD Dist, ISIN IE00B3F81409; EUR-Sicht mit historischen EZB-Wechselkursen.', 1),
    ('wisdomtree-broad-commodities', 'WisdomTree / Yahoo Finance / EZB', 'manual_csv',
     'https://www.wisdomtree.com/ie/products/commodities/wisdomtree-broad-commodities',
     'ETC-Marktdaten für die private Auswertung. Kein lizenzierter Bloomberg-Commodity-Indexdatensatz.',
     'WisdomTree Broad Commodities, GB00B15KY989, adjustierter AIGC.L-Marktpreis; EUR-Sicht mit historischen EZB-Wechselkursen.', 1),
    ('ecb-euro-money-market-model', 'Europäische Zentralbank', 'manual_csv',
     'https://data.ecb.europa.eu/data/datasets/EST',
     'Aus offiziellen EZB-Zinssätzen modellierte Reihe; Quellenangabe bei Weiterverwendung.',
     'EONIA bis 30.09.2019, danach Euro Short-Term Rate (€STR), taggenau nach ACT/360 aufgezinst.', 1),
    ('nyfed-us-money-market-model', 'Federal Reserve Bank of New York / EZB', 'manual_csv',
     'https://www.newyorkfed.org/markets/reference-rates',
     'Private Auswertung. NY-Fed-Referenzraten und EZB-Wechselkurse unterliegen den jeweiligen Nutzungsbedingungen.',
     'EFFR, danach SOFR, taggenau nach ACT/360 aufgezinst und mit historischen EZB-Wechselkursen in EUR umgerechnet.', 1),
    ('eurostat-hicp-germany', 'Eurostat', 'manual_csv',
     'https://ec.europa.eu/eurostat/web/hicp/information-data',
     'Offizielle Statistik; Weiterverwendung mit Quellenangabe.',
     'Harmonisierter Verbraucherpreisindex Deutschland, Gesamtindex, prc_hicp_minr, I25.', 1),
    ('eurostat-hicp-euro-area', 'Eurostat', 'manual_csv',
     'https://ec.europa.eu/eurostat/web/hicp/information-data',
     'Offizielle Statistik; Weiterverwendung mit Quellenangabe.',
     'Harmonisierter Verbraucherpreisindex Euroraum, Gesamtindex, prc_hicp_minr, I25.', 1),
    ('bls-cpi-us', 'U.S. Bureau of Labor Statistics', 'manual_csv',
     'https://www.bls.gov/cpi/factsheets/cpi-series-ids.htm',
     'Amtliche US-Regierungsstatistik; Quellenangabe erwünscht.',
     'Consumer Price Index for All Urban Consumers, U.S. city average, not seasonally adjusted, CUUR0000SA0.', 1)
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
    ((SELECT id FROM backtester_data_sources WHERE slug = 'ishares-global-aggregate-eur-hedged'),
     'global_ig_bonds', 'Globale IG-Anleihen (EUR-gesichert)', 'Global IG EUR-Hedged', 'bond', 'Global',
     'EUNA.DE', 'iShares Core Global Aggregate Bond UCITS ETF EUR Hedged Acc, adjusted market value',
     'isin', 'IE00BDBRDM35', 'EUR', 'EUR', 'adjusted_close', 'daily', 'market_weekdays', 'Europe/Berlin',
     'Thesaurierender globaler Investment-Grade-Anleihen-Proxy, gegen EUR abgesichert.',
     'Globale Investment-Grade-Anleihen als Gesamtrenditereihe in EUR mit Währungsabsicherung.',
     '2016-01-04', 80, 0, 1),
    ((SELECT id FROM backtester_data_sources WHERE slug = 'ishares-global-aggregate-unhedged'),
     'global_ig_bonds_unhedged', 'Globale IG-Anleihen (ungesichert)', 'Global IG ungesichert', 'bond', 'Global',
     'AGGG.L', 'iShares Core Global Aggregate Bond UCITS ETF USD Dist, EUR investor view',
     'isin', 'IE00B3F81409', 'PTS', 'PTS', 'adjusted_close', 'daily', 'market_weekdays', 'Europe/London',
     'Globaler Investment-Grade-Anleihen-Proxy ohne EUR-Währungsabsicherung.',
     'Globale Investment-Grade-Anleihen als Gesamtrenditereihe in EUR ohne Währungsabsicherung.',
     '2016-01-04', 81, 0, 1),
    ((SELECT id FROM backtester_data_sources WHERE slug = 'wisdomtree-broad-commodities'),
     'commodities', 'Breite Rohstoffe', 'Rohstoffe', 'commodity', 'Global',
     'AIGC.L', 'WisdomTree Broad Commodities adjusted market value, EUR investor view',
     'isin', 'GB00B15KY989', 'PTS', 'PTS', 'adjusted_close', 'daily', 'market_weekdays', 'Europe/London',
     'Breiter Rohstoffkorb als ETC-Gesamtrendite-Proxy in EUR.',
     'Die Reihe nutzt adjustierte Marktpreise eines breit gestreuten Rohstoff-ETCs und historische EZB-Wechselkurse. Sie ist kein Spotpreisindex und enthält dadurch auch Terminmarkt- und Besicherungseffekte des Proxys.',
     '2016-01-04', 90, 0, 1),
    ((SELECT id FROM backtester_data_sources WHERE slug = 'ecb-euro-money-market-model'),
     'euro_money_market', 'Euro-Geldmarkt', 'Euro-Geldmarkt', 'cash', 'Euro Area',
     'EONIA-ESTR-ACT360', 'Modeled EONIA then €STR wealth index',
     'model', 'EONIA-ESTR-ACT360', 'PTS', 'PTS', 'index_level', 'daily', 'market_weekdays', 'Europe/Frankfurt',
     'Euro-Geldmarktvermögen aus EONIA und anschließend €STR.',
     'Die offiziellen Tageszinssätze werden ohne Kosten nach ACT/360 aufgezinst. Es werden keine künstlichen Wochenendbeobachtungen gespeichert.',
     '2016-01-04', 100, 0, 1),
    ((SELECT id FROM backtester_data_sources WHERE slug = 'nyfed-us-money-market-model'),
     'us_money_market', 'US-Geldmarkt (EUR)', 'US-Geldmarkt', 'cash', 'United States',
     'EFFR-SOFR-ACT360-EUR', 'Modeled EFFR then SOFR wealth index, unhedged EUR view',
     'model', 'EFFR-SOFR-ACT360-EUR', 'PTS', 'PTS', 'index_level', 'daily', 'market_weekdays', 'America/New_York',
     'US-Dollar-Geldmarktvermögen ohne Währungsabsicherung, aus EUR-Anlegersicht.',
     'EFFR und anschließend SOFR werden nach ACT/360 aufgezinst. Die EUR-Reihe enthält die historische USD/EUR-Entwicklung und ist deshalb kein reiner US-Zinsvergleich.',
     '2016-01-04', 110, 0, 1),
    ((SELECT id FROM backtester_data_sources WHERE slug = 'eurostat-hicp-germany'),
     'inflation_de', 'Inflation Deutschland', 'Inflation DE', 'inflation', 'Germany',
     'prc_hicp_minr:DE:TOTAL:I25', 'Eurostat HICP Germany, monthly, 2025=100',
     'eurostat_series', 'prc_hicp_minr', 'PTS', 'PTS', 'index_level', 'monthly', 'native_periods', 'UTC',
     'Harmonisierter Verbraucherpreisindex für Deutschland.',
     'Monatliche amtliche Beobachtungen. Zwischen Veröffentlichungen wird der letzte Indexstand für die Kaufkraftberechnung fortgeführt.',
     '2016-01-31', 200, 0, 1),
    ((SELECT id FROM backtester_data_sources WHERE slug = 'eurostat-hicp-euro-area'),
     'inflation_euro_area', 'Inflation Euroraum', 'Inflation Euroraum', 'inflation', 'Euro Area',
     'prc_hicp_minr:EA:TOTAL:I25', 'Eurostat HICP Euro Area, monthly, 2025=100',
     'eurostat_series', 'prc_hicp_minr', 'PTS', 'PTS', 'index_level', 'monthly', 'native_periods', 'UTC',
     'Harmonisierter Verbraucherpreisindex für den Euroraum.',
     'Monatliche amtliche Beobachtungen. Zwischen Veröffentlichungen wird der letzte Indexstand für die Kaufkraftberechnung fortgeführt.',
     '2016-01-31', 210, 0, 1),
    ((SELECT id FROM backtester_data_sources WHERE slug = 'bls-cpi-us'),
     'inflation_us', 'Inflation USA', 'Inflation USA', 'inflation', 'United States',
     'CUUR0000SA0', 'BLS CPI-U U.S. city average, not seasonally adjusted',
     'bls_series', 'CUUR0000SA0', 'PTS', 'PTS', 'index_level', 'monthly', 'native_periods', 'UTC',
     'US-Verbraucherpreisindex CPI-U, nicht saisonbereinigt.',
     'Monatliche amtliche Beobachtungen. Zwischen Veröffentlichungen wird der letzte Indexstand für die Kaufkraftberechnung fortgeführt.',
     '2016-01-31', 220, 0, 1)
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
SET proxy_description = CASE slug
        WHEN 'de_real_estate' THEN 'Deutscher Wohnimmobilienpreisindex; Netto-Mietcashflow nur im Einzelverlauf.'
        WHEN 'us_real_estate' THEN 'US-Wohnimmobilienpreisindex; Netto-Mietcashflow nur im Einzelverlauf.'
        ELSE proxy_description
    END,
    methodology_note = CASE slug
        WHEN 'de_real_estate' THEN 'Die offiziellen Eurostat-Quartalswerte werden bis zur nächsten Veröffentlichung fortgeführt. Nur im Einzelverlauf kann ein Netto-Mietcashflow linear als nicht reinvestiertes Cash ergänzt werden; Marktübersicht und Gesamtvergleich zeigen ausschließlich den Preisindex.'
        WHEN 'us_real_estate' THEN 'Die offiziellen FHFA-Monatswerte werden bis zur nächsten Veröffentlichung fortgeführt. Nur im Einzelverlauf kann ein Netto-Mietcashflow linear als nicht reinvestiertes Cash ergänzt werden; Marktübersicht und Gesamtvergleich zeigen ausschließlich den Preisindex.'
        ELSE methodology_note
    END
WHERE slug IN ('de_real_estate', 'us_real_estate');

INSERT INTO backtester_sync_state (asset_id, next_check_at)
SELECT id, NULL
FROM backtester_assets
WHERE slug IN (
    'global_ig_bonds', 'global_ig_bonds_unhedged', 'commodities',
    'euro_money_market', 'us_money_market',
    'inflation_de', 'inflation_euro_area', 'inflation_us'
)
ON DUPLICATE KEY UPDATE next_check_at = VALUES(next_check_at);

-- Fresh installations use the same live providers as migration 019. The
-- controlled CSV files remain the source for the older history.
UPDATE backtester_data_sources
SET provider_type = CASE slug
        WHEN 'ishares-global-aggregate-eur-hedged' THEN 'yahoo_fund_scraper'
        WHEN 'ishares-global-aggregate-unhedged' THEN 'yahoo_fund_scraper'
        WHEN 'wisdomtree-broad-commodities' THEN 'yahoo_fund_scraper'
        WHEN 'ecb-euro-money-market-model' THEN 'ecb_compounded_index'
        WHEN 'nyfed-us-money-market-model' THEN 'nyfed_sofr_index_eur'
        WHEN 'eurostat-hicp-germany' THEN 'eurostat_hicp_api'
        WHEN 'eurostat-hicp-euro-area' THEN 'eurostat_hicp_api'
        WHEN 'bls-cpi-us' THEN 'bls_public_api'
        ELSE provider_type
    END
WHERE slug IN (
    'ishares-global-aggregate-eur-hedged',
    'ishares-global-aggregate-unhedged',
    'wisdomtree-broad-commodities',
    'ecb-euro-money-market-model',
    'nyfed-us-money-market-model',
    'eurostat-hicp-germany',
    'eurostat-hicp-euro-area',
    'bls-cpi-us'
);

UPDATE backtester_assets
SET provider_symbol = CASE slug
        WHEN 'euro_money_market' THEN 'EST.B.EU000A2QQF08.CI'
        WHEN 'us_money_market' THEN 'NYFED:SOFRAI'
        ELSE provider_symbol
    END,
    identifier_type = CASE slug
        WHEN 'euro_money_market' THEN 'ecb_series'
        WHEN 'us_money_market' THEN 'nyfed_series'
        ELSE identifier_type
    END,
    identifier_value = CASE slug
        WHEN 'euro_money_market' THEN 'EST.B.EU000A2QQF08.CI'
        WHEN 'us_money_market' THEN 'SOFRAI'
        ELSE identifier_value
    END
WHERE slug IN ('euro_money_market', 'us_money_market');
