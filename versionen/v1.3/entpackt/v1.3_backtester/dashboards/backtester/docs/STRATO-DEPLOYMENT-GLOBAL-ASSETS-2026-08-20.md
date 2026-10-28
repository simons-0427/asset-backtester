# STRATO-Deployment: globale Assets und reale Renditen

Stand: 20. August 2026

## 1. Diese Dateien mit FileZilla hochladen

Die Ordnerstruktur unter `dashboards/backtester` beibehalten und ausschließlich die folgenden Dateien überschreiben oder ergänzen:

```text
index.html
assets/css/asset-backtester.css
assets/js/asset-backtester/api.js
assets/js/asset-backtester/app.js
assets/js/asset-backtester/calculations.js
assets/js/asset-backtester/charts.js
api/backtester/series.php
api/backtester/summary.php
includes/backtester/Http/RequestValidator.php
includes/backtester/Services/BacktestService.php
sql/backtester/016_global_assets_and_inflation.sql
sql/backtester/017_global_ig_bonds_ten_year_history.sql
var/import/global_ig_bonds.csv
var/import/global_ig_bonds_unhedged.csv
var/import/commodities.csv
var/import/euro_money_market.csv
var/import/us_money_market.csv
var/import/inflation_de.csv
var/import/inflation_euro_area.csv
var/import/inflation_us.csv
tests/backtester/CalculationTest.php
tests/backtester/PreparedMarketCsvTest.php
tests/backtester/RequestValidatorTest.php
tests/js/charts.test.mjs
tests/js/calculations.test.mjs
tests/js/msci-world-integration.test.mjs
```

`config/backtester.php` nicht hochladen und nicht überschreiben.

## 2. Datenbankmigration importieren

In phpMyAdmin zuerst links die Datenbank `REDACTED_PRIVATE_VALUE` anklicken. Danach im Reiter **Importieren** diese Datei ausführen:

```text
sql/backtester/016_global_assets_and_inflation.sql
sql/backtester/017_global_ig_bonds_ten_year_history.sql
```

Die MySQL-Hinweise zur veralteten `VALUES()`-Schreibweise sind Warnungen und verhindern den Import nicht.

## 3. Per SSH in den Backtester wechseln

```bash
ssh stu969337242@5221695.ssh.w1.strato.hosting
cd /mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
pwd
```

Erwartete letzte Ausgabe:

```text
/mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
```

## 4. PHP-Dateien prüfen

Jeden Befehl vollständig in einer Zeile ausführen:

```bash
/usr/bin/php -l includes/backtester/Http/RequestValidator.php
/usr/bin/php -l includes/backtester/Services/BacktestService.php
/usr/bin/php -l api/backtester/series.php
/usr/bin/php -l api/backtester/summary.php
```

Jeder Befehl muss mit `No syntax errors detected` enden.

## 5. CSV-Dateien importieren

Die acht Befehle einzeln und jeweils vollständig in einer Zeile ausführen:

```bash
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=global_ig_bonds --from=2016-01-04 --to=2026-08-20
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=global_ig_bonds_unhedged --from=2016-01-04 --to=2026-08-20
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=commodities --from=2016-01-04 --to=2026-08-20
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=euro_money_market --from=2016-01-04 --to=2026-08-19
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=us_money_market --from=2016-01-04 --to=2026-08-19
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=inflation_de --from=2016-01-31 --to=2026-07-31
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=inflation_euro_area --from=2016-01-31 --to=2026-07-31
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=inflation_us --from=2016-01-31 --to=2026-07-31
```

Erwartete Zahl neu eingefügter Zeilen beim ersten Import:

| Asset | Erwartet |
|---|---:|
| `global_ig_bonds` | 2705 |
| `global_ig_bonds_unhedged` | 2704 |
| `commodities` | 2686 |
| `euro_money_market` | 2721 |
| `us_money_market` | 2658 |
| `inflation_de` | 127 |
| `inflation_euro_area` | 127 |
| `inflation_us` | 126 |

Bei einer Wiederholung dürfen die Zeilen als `updated` oder `unchanged` erscheinen.

## 6. Gesamten PHP-Testlauf starten

```bash
/usr/bin/php -q tests/backtester/run.php
```

Erwartet werden 55 bestandene, 0 fehlgeschlagene und 1 planmäßig übersprungene Prüfung. Der MySQL-Integrationstest bleibt ohne separate Testdatenbank übersprungen.

## 7. Browserabnahme

Die Seite mit `Strg+F5` neu laden und prüfen:

- Alle 13 Anlageklassen besitzen einen Einzelverlauf.
- Nur die Immobilien-Einzelverläufe enthalten Netto-Mietcashflow: USA 5,0 %, Deutschland 3,0 %.
- Marktübersicht und Gesamtvergleich enthalten keinen Mietcashflow.
- Im Gesamtvergleich sind standardmäßig S&P 500, Bitcoin, Gold, Immobilien DE und MSCI World aktiv.
- **Einstellungen** enthält alle 17 Linien sowie **Alle auswählen** und **Alle abwählen**.
- Deutschland, Euroraum, USA und eine eigene Rate können als Inflation gewählt werden.
- Bei aktiver Inflation steht am Vergleich und am Zeitraum deutlich `Reale Rendite` beziehungsweise `real`.

Die kontrollierten CSV-Dateien bleiben die historische Basis. Nach dem zusätzlichen Deployment aus `STRATO-DEPLOYMENT-GLOBAL-LIVE-2026-08-20.md` werden neue und revidierte Werte vom bestehenden täglichen Provider-Cron ergänzt.
