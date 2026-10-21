-- Aktiviert die kostenlose laufende Fortschreibung der importierten
-- S&P-500- und GLD-Historien ab dem 03.08.2026.
-- S&P 500: FRED SP500. Gold: Alpha Vantage GLD. EUR: EZB-Referenzkurs.

INSERT INTO backtester_data_sources
    (slug, display_name, provider_type, base_url, license_note, attribution_text, is_active)
SELECT
    'fred-sp500-live',
    'S&P DJI/FRED + EZB',
    'fred',
    'https://fred.stlouisfed.org/',
    'Schriftliche Rechte für die veröffentlichte historische und laufende S&P-500-Reihe liegen beim Betreiber vor; vertrauliche Nachweise bleiben außerhalb des Webroots. Zusätzlich gelten die FRED-API-Bedingungen. EZB-Referenzkurse werden als Quelle gekennzeichnet.',
    'S&P-500-Preisindex: S&P Dow Jones Indices LLC, abgerufen über FRED, Federal Reserve Bank of St. Louis. EUR-Umrechnung: EZB-Referenzkurs USD je EUR, am selben Tag oder zuletzt zuvor veröffentlicht.',
    1
WHERE NOT EXISTS (
    SELECT 1 FROM backtester_data_sources WHERE slug = 'fred-sp500-live'
);

UPDATE backtester_data_sources
SET display_name = 'S&P DJI/FRED + EZB',
    provider_type = 'fred',
    base_url = 'https://fred.stlouisfed.org/',
    license_note = 'Schriftliche Rechte für die veröffentlichte historische und laufende S&P-500-Reihe liegen beim Betreiber vor; vertrauliche Nachweise bleiben außerhalb des Webroots. Zusätzlich gelten die FRED-API-Bedingungen. EZB-Referenzkurse werden als Quelle gekennzeichnet.',
    attribution_text = 'S&P-500-Preisindex: S&P Dow Jones Indices LLC, abgerufen über FRED, Federal Reserve Bank of St. Louis. EUR-Umrechnung: EZB-Referenzkurs USD je EUR, am selben Tag oder zuletzt zuvor veröffentlicht.',
    is_active = 1
WHERE slug = 'fred-sp500-live';

UPDATE backtester_data_sources
SET display_name = 'SPDR + Alpha Vantage + EZB',
    base_url = 'https://www.alphavantage.co/',
    license_note = 'Schriftliche Rechte für die veröffentlichte historische GLD-Reihe liegen beim Betreiber vor; vertrauliche Nachweise bleiben außerhalb des Webroots. Für laufende Abrufe gelten zusätzlich die jeweils geltenden Alpha-Vantage-Bedingungen. EZB-Referenzkurse werden als Quelle gekennzeichnet.',
    attribution_text = 'GLD-Historie: SPDR Gold Shares. Laufende GLD-Schlusswerte: Alpha Vantage. EUR-Umrechnung: EZB-Referenzkurs USD je EUR, am selben Tag oder zuletzt zuvor veröffentlicht.'
WHERE slug = 'alpha-vantage-market';

UPDATE backtester_assets
SET source_id = (SELECT id FROM backtester_data_sources WHERE slug = 'fred-sp500-live'),
    provider_symbol = 'SP500',
    provider_dataset = 'fred/series/observations',
    identifier_type = 'fred_series_id',
    identifier_value = 'SP500',
    raw_currency = 'USD',
    display_currency = 'EUR',
    value_type = 'index_level',
    timezone_name = 'America/New_York',
    proxy_description = 'S&P-500-Preisindex ohne Dividenden, über FRED abgerufen und mit EZB-Referenzkursen in Euro umgerechnet.',
    methodology_note = 'Durchgängige Reihe: täglicher S&P-500-Preisindexschluss SP500 von S&P Dow Jones Indices LLC über FRED. USD-Werte werden durch den EZB-Kurs USD je EUR desselben Tages oder den zuletzt zuvor veröffentlichten Kurs geteilt.',
    first_expected_date = '2016-08-02'
WHERE slug = 'sp500';

UPDATE backtester_assets
SET provider_symbol = 'GLD',
    provider_dataset = 'TIME_SERIES_DAILY',
    identifier_type = 'ticker',
    identifier_value = 'GLD',
    raw_currency = 'USD',
    display_currency = 'EUR',
    value_type = 'close',
    timezone_name = 'America/New_York',
    proxy_description = 'SPDR Gold Shares (GLD), NYSE Arca, Schlusskurs in USD und mit EZB-Referenzkursen in Euro umgerechnet.',
    methodology_note = 'Historie bis 02.08.2026: offizieller GLD-Schlusskurs aus dem SPDR-Archiv. Ab 03.08.2026: täglicher unbereinigter GLD-Schluss aus Alpha Vantage TIME_SERIES_DAILY. USD-Werte werden durch den EZB-Kurs USD je EUR desselben Tages oder den zuletzt zuvor veröffentlichten Kurs geteilt.',
    first_expected_date = '2016-08-02'
WHERE slug = 'gold';
