-- Extend both Global IG bond definitions to the available 2016 history.
UPDATE backtester_assets
SET first_expected_date = '2016-01-04',
    methodology_note = 'Globale Investment-Grade-Anleihen als Gesamtrenditereihe in EUR mit Währungsabsicherung.'
WHERE slug = 'global_ig_bonds';

UPDATE backtester_assets
SET first_expected_date = '2016-01-04',
    methodology_note = 'Globale Investment-Grade-Anleihen als Gesamtrenditereihe in EUR ohne Währungsabsicherung.'
WHERE slug = 'global_ig_bonds_unhedged';
