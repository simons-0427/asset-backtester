# STRATO-Deployment: Immobilien USA und 13-Uhr-Job

Diese Anleitung gilt für:

```text
/mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
```

## 1. Upload mit FileZilla

SSH wird für den Upload noch nicht benötigt. In FileZilla rechts in den obigen
Serverordner wechseln und die lokalen Dateien mit derselben Ordnerstruktur
hochladen. Vorhandene Dateien überschreiben, Ordner nicht ineinander
verschachteln.

Notwendige Laufzeitdateien:

```text
index.html
assets/css/asset-backtester.css
assets/js/asset-backtester/api.js
assets/js/asset-backtester/app.js
assets/js/asset-backtester/charts.js
assets/js/asset-backtester/formatters.js
api/backtester/series.php
api/backtester/summary.php
api/backtester/sync-if-stale.php
config/backtester.php
includes/backtester/App.php
includes/backtester/Http/RequestValidator.php
includes/backtester/Providers/CsvImportProvider.php
includes/backtester/Providers/FredCsvProvider.php
includes/backtester/Providers/HttpClient.php
includes/backtester/Providers/MsciWorldProvider.php
includes/backtester/Services/BacktestService.php
includes/backtester/Services/RentalCashflowAdjustment.php
includes/backtester/Services/SummaryService.php
sql/backtester/011_us_real_estate.sql
sql/backtester/012_enable_msci_world_scraper.sql
var/import/us_real_estate.csv
```

Für den Server-Test zusätzlich den gesamten Ordner `tests/backtester` hochladen.
Die Dokumentationsdateien sind für den Betrieb nicht erforderlich.

## 2. Migration in phpMyAdmin

1. In phpMyAdmin die Datenbank `REDACTED_PRIVATE_VALUE` anklicken.
2. Oben `Importieren` öffnen.
3. `sql/backtester/011_us_real_estate.sql` auswählen.
4. Import starten.

Die Datei enthält bereits `USE REDACTED_PRIVATE_VALUE;`; der frühere Fehler
`#1046 - Keine Datenbank ausgewählt` kann deshalb nicht auftreten.

`012_enable_msci_world_scraper.sql` **noch nicht importieren**. Diese Migration
ist erst nach Klärung der MSCI-/Yahoo-Nutzungsrechte vorgesehen.

Kontrolle in phpMyAdmin:

```sql
SELECT a.slug, a.is_active, a.native_frequency,
       ds.slug AS source_slug, ds.provider_type
FROM REDACTED_PRIVATE_VALUE.backtester_assets a
JOIN REDACTED_PRIVATE_VALUE.backtester_data_sources ds ON ds.id = a.source_id
WHERE a.slug = 'us_real_estate';
```

Nach Migration `013_us_real_estate_fred_api.sql` wird eine aktive Zeile mit
`native_frequency = monthly` und `provider_type = fred` erwartet.

## 3. Nach dem Upload per SSH

```bash
cd /mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
pwd
```

Dann die geänderten PHP-Dateien prüfen:

```bash
/usr/bin/php -l includes/backtester/Providers/FredCsvProvider.php
/usr/bin/php -l includes/backtester/Providers/MsciWorldProvider.php
/usr/bin/php -l includes/backtester/Services/RentalCashflowAdjustment.php
/usr/bin/php -l includes/backtester/Services/BacktestService.php
/usr/bin/php -l includes/backtester/Services/SummaryService.php
/usr/bin/php -l includes/backtester/Http/RequestValidator.php
/usr/bin/php -l config/backtester.php
```

Alle Ausgaben müssen `No syntax errors detected` enthalten.

## 4. Servertests und einmaliger US-Backfill

```bash
/usr/bin/php -q tests/backtester/run.php
```

Danach die vorbereiteten 125 FHFA-Monatswerte importieren:

```bash
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=us_real_estate --from=2016-01-01 --to=2026-05-01
```

Beim ersten Lauf wird `inserted: 125` erwartet. Ein erneuter Lauf darf
`unchanged: 125` melden.

Den echten FRED-Abruf separat prüfen:

```bash
/usr/bin/php -q cron/backtester/backfill-history.php --asset=us_real_estate --from=2025-06-01 --to=2026-08-18
```

Die Werte können wegen offizieller Revisionen als `updated` erscheinen. Das ist
bei der FHFA-Reihe zulässig.

## 5. Täglichen Job testen und auf 13 Uhr stellen

```bash
/usr/bin/php -q cron/backtester/update-daily.php
tail -n 25 logs/backtester.log
```

`assets_checked: 0` ist möglich, wenn der separate Live-Test gerade erst einen
neuen `next_check_at` gesetzt hat. Das bedeutet nicht, dass der Provider fehlt.

Im externen Scheduler den vorhandenen HTTPS-Aufruf auf folgende Zeit ändern:

```text
Zeit: täglich 13:00
Zeitzone: Europe/Berlin
URL: https://smstark-projekt.de/dashboards/backtester/cron/backtester/update-daily.php
Header: X-Backtester-Cron-Token: vorhandenes Cron-Geheimnis
```

Das Geheimnis nicht in Screenshots, Chatnachrichten oder öffentliche Dateien
kopieren. Der Seitenbesuch-Fallback ist deaktiviert; Providerabrufe kommen damit
nur aus dem geplanten Job oder aus ausdrücklich gestarteten SSH-Tests.

## 6. Browserkontrolle

Browser-Cache mit `Strg + F5` leeren und prüfen:

1. Marktübersicht enthält `Immobilien USA`.
2. Einzelverläufe enthalten eine Immobilienkarte mit zwei Linien.
3. `5,0 %` ist der Standardwert für den Netto-Mietcashflow.
4. Eine Änderung auf beispielsweise `1,5 %` ändert nur die Cashflow-Linie und
   den Immobilien-Endwert, nicht den Preisindex.
5. Der Link `Immobilienkosten berechnen` führt zu
   `/projekte/immobilienkalkulation`.
6. Im Gesamtvergleich bleiben standardmäßig nur S&P 500, Bitcoin und Gold aktiv;
   Immobilien USA ist auswählbar, aber nicht vorausgewählt.

## 7. MSCI World später aktivieren

Erst nach Rechteklärung:

1. `BACKTESTER_MSCI_WORLD_SCRAPER_ENABLED=true` serverseitig setzen; wenn STRATO
   keine Umgebungsvariable anbietet, in `config/backtester.php` den Fallback für
   diese eine Einstellung von `false` auf `true` ändern.
2. `sql/backtester/012_enable_msci_world_scraper.sql` in `REDACTED_PRIVATE_VALUE`
   importieren.
3. Nur den kurzen Anschlussbereich testen:

```bash
/usr/bin/php -q cron/backtester/backfill-history.php --asset=msci_world --from=2026-08-14 --to=2026-08-18
```

Keinen neuen MSCI-Historienbackfill starten. Die 1.115 vorhandenen Werte bleiben
die historische Basis.
