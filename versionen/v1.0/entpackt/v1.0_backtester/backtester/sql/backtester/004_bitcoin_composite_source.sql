-- Dokumentiert die zehnjährige BTC/EUR-Historie aus der lokalen
-- BTC/USD-Minutenreihe plus historischen EZB-Referenzkursen. Laufende
-- Aktualisierungen erfolgen weiterhin über den CoinGecko-Adapter.

UPDATE backtester_data_sources AS source
JOIN backtester_assets AS asset ON asset.source_id = source.id
SET source.display_name = 'Bitstamp/Kaggle + EZB + CoinGecko',
    source.license_note = 'BTC/USD-Historie: Kaggle-Datensatz "Bitcoin Historical Data", CC BY-SA 4.0. Wechselkurse: EZB-Referenzkurse, Quelle nennen und abgeleitete Umrechnung kennzeichnen. Laufende BTC/EUR-Aktualisierung: CoinGecko-Bedingungen und API-Tarif beachten.',
    source.attribution_text = 'Historie: BTC/USD-Minutenwerte aus "Bitcoin Historical Data" (Zielak/Kaggle, CC BY-SA 4.0), historisch in EUR umgerechnet mit EZB-USD/EUR-Referenzkursen. Laufende Aktualisierung: CoinGecko BTC/EUR.'
WHERE asset.slug = 'bitcoin';

UPDATE backtester_assets
SET provider_dataset = 'Kaggle btcusd_1-min_data + ECB EXR; laufend CoinGecko market_chart/range',
    proxy_description = 'Bitcoin in Euro: zehnjährige Bitstamp/Kaggle-BTC/USD-Historie mit historischen EZB-USD/EUR-Referenzkursen; laufende BTC/EUR-Aktualisierung über CoinGecko.',
    methodology_note = 'Historie bis 02.08.2026: letzter gültiger BTC/USD-Minutenschluss je UTC-Tag, geteilt durch den am selben Tag oder zuletzt zuvor veröffentlichten EZB-Kurs USD je EUR. Ab 03.08.2026: letzte gelieferte CoinGecko-BTC/EUR-Beobachtung je abgeschlossenem UTC-Tag. Der Quellenwechsel ist offengelegt.',
    first_expected_date = '2016-08-02'
WHERE slug = 'bitcoin';
