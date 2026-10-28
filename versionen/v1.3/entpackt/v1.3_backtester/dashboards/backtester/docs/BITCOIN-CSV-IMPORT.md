# Aufbereitung der BTC/USD-Minutenhistorie

Die lokale Datei `btcusd_1-min_data.csv` stammt aus dem
[Kaggle-Datensatz „Bitcoin Historical Data“](https://www.kaggle.com/datasets/mczielinski/bitcoin-historical-data)
von Zielak. Kaggle weist den Datensatz als `CC BY-SA 4.0` aus und
beschreibt ihn als UTC-Minutenhistorie aus Bitcoincharts beziehungsweise
der Bitstamp-API. Die historischen USD/EUR-Referenzkurse werden über das
[ECB Data Portal](https://data.ecb.europa.eu/methodology/exchange-rates)
bezogen.

Die Rohdatei darf nicht in den öffentlichen Webordner hochgeladen werden. Für
den Backtester wird ausschließlich die kleine, abgeleitete Datei
`var/import/bitcoin.csv` benötigt. `var/` ist serverseitig durch `.htaccess`
gegen öffentliche Abrufe gesperrt.

## Reproduzierbare Tagesbildung

`cron/backtester/prepare-btcusd-daily.php` verarbeitet die große Datei
zeilenweise und hält nur einen Wert je Tag im Speicher:

1. Unix-Zeitstempel werden als UTC interpretiert.
2. Pro vollständig abgeschlossenem UTC-Kalendertag wird der letzte gültige,
   positive Minutenschlusskurs verwendet.
3. Der BTC/USD-Schlusskurs wird mit dem historischen USD/EUR-Referenzkurs der
   Europäischen Zentralbank umgerechnet: `BTC/EUR = BTC/USD ÷ USD je EUR`.
4. Für Wochenenden und EZB-Schließtage wird der letzte vorherige
   Referenzkurs verwendet. Es findet keine Umrechnung mit einem heutigen Kurs
   statt.
5. Die Ausgabedatei enthält neben dem EUR-Wert die verwendeten USD- und
   Wechselkurswerte sowie beide Quelldaten für die Prüfung.

Beispiel:

```bash
php cron/backtester/prepare-btcusd-daily.php \
  --input=btcusd_1-min_data.csv \
  --fx=ecb-usd-eur.csv \
  --output=var/import/bitcoin.csv \
  --from=2016-08-02 \
  --to=2026-08-02
```

Die EZB veröffentlicht Referenzkurse nur an Arbeitstagen. Die Referenzkurse
sind Informationswerte und keine garantierten Transaktionskurse. Bei Nutzung
oder Weitergabe sind die tatsächlichen Quellen, die Umrechnung und die
Lizenzbedingungen des Ausgangsdatensatzes offenzulegen.

## Geprüftes Ergebnis vom 03.08.2026

- Zeitraum: 02.08.2016 bis 02.08.2026 einschließlich
- Ausgabewerte: 3.653 plus Header
- gelesene Minutenzeilen: 7.672.423
- davon im Zielzeitraum: 5.260.320
- ungültige Zeilen, doppelte Zeitstempel, Sortierfehler und Tageslücken: 0
- alle Tageswerte stammen exakt von 23:59 UTC
- an 1.094 Wochenend-/Schließtagen wurde der letzte vorherige EZB-Kurs verwendet
- SHA-256 von `var/import/bitcoin.csv`:
  `38CE146B00E944AAED915CA4A51DF3B658393C6BE3C86F701071846716C5F865`

Für den kontrollierten Datenbankimport:

```bash
php cron/backtester/import-prepared-csv.php \
  --asset=bitcoin \
  --from=2016-08-02 \
  --to=2026-08-02
```

Die CoinGecko-Konfiguration setzt anschließend
`live_updates_from = 2026-08-03`. Dadurch darf der tägliche Überlappungsabruf
keine importierten Tage bis einschließlich 02.08.2026 überschreiben. Kurze
Liveabrufe verwenden Stundenwerte und wählen daraus wieder die letzte
gelieferte Beobachtung je UTC-Tag. Lange separate CoinGecko-Bereiche bleiben
bei Tagesgranularität.
