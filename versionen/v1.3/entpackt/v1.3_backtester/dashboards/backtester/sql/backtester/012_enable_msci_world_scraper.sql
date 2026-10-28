-- OPTIONAL: import only after the operator has confirmed the required rights.
-- Switches MSCI World from the manual CSV source to the recent-value scraper.
INSERT INTO backtester_data_sources
    (slug, display_name, provider_type, base_url, license_note, attribution_text, is_active)
VALUES
    ('msci-world-yahoo-current', 'MSCI World via Yahoo Finance', 'yahoo_chart_scraper',
     'https://finance.yahoo.com/quote/%5E990100-USD-STRD/',
     'Nur aktivieren, wenn die Nutzungs- und Weitergaberechte für den konkreten Einsatz geklärt sind. Der verwendete Endpunkt ist nicht vertraglich stabil.',
     'MSCI World Price Index (USD), Indexcode 990100; jüngster Wert via Yahoo Finance. EUR-Umrechnung mit EZB-Referenzkurs.',
     1)
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    provider_type = VALUES(provider_type),
    base_url = VALUES(base_url),
    license_note = VALUES(license_note),
    attribution_text = VALUES(attribution_text),
    is_active = VALUES(is_active);

UPDATE backtester_assets
SET source_id = (SELECT id FROM backtester_data_sources WHERE slug = 'msci-world-yahoo-current'),
    provider_symbol = '^990100-USD-STRD',
    provider_dataset = 'MSCI World Price Index USD; recent daily values only',
    identifier_type = 'msci_index_code',
    identifier_value = '990100',
    methodology_note = 'Die bestehende MSCI-World-Preisindexhistorie bleibt erhalten. Neue USD-Preisindexwerte werden für einen kurzen Überlappungszeitraum abgerufen und mit dem letzten verfügbaren EZB-Referenzkurs in EUR umgerechnet.'
WHERE slug = 'msci_world';

UPDATE backtester_sync_state
SET next_check_at = NULL
WHERE asset_id = (SELECT id FROM backtester_assets WHERE slug = 'msci_world');
