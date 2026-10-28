-- Enable automated recent-value updates for DAX, EURO STOXX 50 and German HPI.
-- Run after 014_european_assets.sql.
UPDATE backtester_data_sources
SET provider_type = 'yahoo_chart_scraper',
    base_url = 'https://query1.finance.yahoo.com/v8/finance/chart/'
WHERE slug IN ('dax-price-manual-csv', 'eurostoxx50-price-manual-csv');

UPDATE backtester_data_sources
SET display_name = 'Eurostat Statistics API',
    provider_type = 'eurostat_statistics_api',
    base_url = 'https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hpi_q',
    license_note = 'Eurostat-Daten dürfen mit Quellenangabe weiterverwendet werden; die Reihe kann revidiert werden.',
    attribution_text = 'Eurostat House Price Index, Deutschland, Gesamtmarkt, prc_hpi_q, purchase=TOTAL, unit=I15_Q.'
WHERE slug = 'eurostat-de-house-price-index';

UPDATE backtester_assets
SET provider_dataset = CASE slug
        WHEN 'dax' THEN 'Yahoo chart v8: DAX Price Index (DAXK), recent daily close'
        WHEN 'eurostoxx50' THEN 'Yahoo chart v8: EURO STOXX 50 Price Index (SX5E), recent daily close'
        WHEN 'de_real_estate' THEN 'Eurostat Statistics API: prc_hpi_q, DE, TOTAL, I15_Q'
        ELSE provider_dataset
    END,
    methodology_note = CASE slug
        WHEN 'dax' THEN 'Die importierte Historie bleibt erhalten. Neue Kursindexstände werden börsentäglich über den Yahoo-Chart-Abruf ergänzt und überlappend revidiert. Die Jahresdividende wird vollständig am 31.12. gutgeschrieben.'
        WHEN 'eurostoxx50' THEN 'Die importierte Historie bleibt erhalten. Neue Kursindexstände werden börsentäglich über den Yahoo-Chart-Abruf ergänzt und überlappend revidiert. Die Jahresdividende wird vollständig am 31.12. gutgeschrieben.'
        WHEN 'de_real_estate' THEN 'Die offizielle Eurostat-Gesamtmarktreihe wird täglich auf neue oder revidierte Quartalswerte geprüft. Der Netto-Mietcashflow wird linear als nicht reinvestiertes Cash addiert.'
        ELSE methodology_note
    END
WHERE slug IN ('dax', 'eurostoxx50', 'de_real_estate');

UPDATE backtester_sync_state ss
INNER JOIN backtester_assets a ON a.id = ss.asset_id
SET ss.lock_token = NULL,
    ss.lock_until = NULL,
    ss.next_check_at = NULL,
    ss.consecutive_failures = 0,
    ss.last_error_at = NULL,
    ss.last_error_code = NULL,
    ss.last_error_message = NULL
WHERE a.slug IN ('dax', 'eurostoxx50', 'de_real_estate');
