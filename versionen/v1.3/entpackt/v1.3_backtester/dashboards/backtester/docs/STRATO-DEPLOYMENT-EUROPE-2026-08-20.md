# STRATO-Upload: DAX, EURO STOXX 50 und Immobilien DE

Diese Anleitung verändert den Server erst, wenn die unten genannten Dateien
mit FileZilla hochgeladen und die Migration in phpMyAdmin ausgeführt wird. Die
lokalen Dateien sind bereits vorbereitet; auf dem lokalen Rechner ist kein
Build-Schritt erforderlich.

Zielordner auf dem Server:

```text
/mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
```

## 1. Nur diese Dateien mit FileZilla hochladen

In FileZilla rechts den Zielordner öffnen. Danach jede lokale Datei in den
gleichnamigen Unterordner auf dem Server kopieren und vorhandene Dateien
überschreiben:

```text
index.html
README.md
assets/css/asset-backtester.css
assets/js/asset-backtester/api.js
assets/js/asset-backtester/app.js
assets/js/asset-backtester/charts.js
api/backtester/series.php
api/backtester/summary.php
config/backtester.php
includes/backtester/Http/RequestValidator.php
includes/backtester/Services/AnnualDividendAdjustment.php
includes/backtester/Services/BacktestService.php
includes/backtester/Services/SummaryService.php
sql/backtester/014_european_assets.sql
var/import/dax.csv
var/import/eurostoxx50.csv
var/import/de_real_estate.csv
tests/backtester/PreparedMarketCsvTest.php
tests/backtester/RequestValidatorTest.php
tests/backtester/SummaryTest.php
tests/backtester/TestFixtures.php
```

Die Ordner `data/`, `data/raw/`, `data/forecasts/` und die Rohstoff- oder
Anleihedateien nicht hochladen. Sie werden von der Website nicht verwendet.

## 2. Eine SQL-Datei in phpMyAdmin importieren

1. In phpMyAdmin links die Datenbank `REDACTED_PRIVATE_VALUE` anklicken.
2. Oben `Importieren` wählen.
3. Die Datei `sql/backtester/014_european_assets.sql` auswählen.
4. Den Import starten.

Die Migration enthält bereits `USE REDACTED_PRIVATE_VALUE;`. Warnungen zur veralteten
MySQL-Funktion `VALUES()` sind auf diesem Server nur Hinweise; entscheidend ist
die Meldung, dass alle Abfragen ausgeführt wurden.

Die Migration legt genau drei Assets an:

```text
dax
eurostoxx50
de_real_estate
```

Außerdem hinterlegt sie die jährlichen Dividendenfaktoren für DAX und EURO
STOXX 50 bis einschließlich 2030. Kurswerte für die Zukunft werden nicht
erzeugt.

## 3. Per SSH verbinden und in den Backtester wechseln

In PowerShell:

```powershell
ssh stu969337242@5221695.ssh.w1.strato.hosting
```

Nach der Anmeldung:

```bash
cd /mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
pwd
```

`pwd` muss diesen Ordner ausgeben:

```text
/mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
```

## 4. PHP-Syntax und Tests prüfen

Die Befehle einzeln ausführen:

```bash
/usr/bin/php -l includes/backtester/Http/RequestValidator.php
/usr/bin/php -l includes/backtester/Services/AnnualDividendAdjustment.php
/usr/bin/php -l includes/backtester/Services/BacktestService.php
/usr/bin/php -l includes/backtester/Services/SummaryService.php
/usr/bin/php -l api/backtester/series.php
/usr/bin/php -l api/backtester/summary.php
/usr/bin/php -l config/backtester.php
/usr/bin/php -q tests/backtester/run.php
```

Jeder Syntaxcheck muss `No syntax errors detected` melden. Beim Testlauf werden
`50 passed, 0 failed, 1 skipped` erwartet. Der eine MySQL-Test bleibt ohne eine
separate Testdatenbank absichtlich übersprungen.

## 5. Die drei vorbereiteten CSV-Dateien importieren

Jeden Befehl vollständig in genau einer Zeile ausführen:

```bash
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=dax --from=2016-01-04 --to=2026-08-20
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=eurostoxx50 --from=2016-01-04 --to=2026-08-20
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=de_real_estate --from=2016-03-31 --to=2026-03-31
```

Beim ersten Import werden diese Zahlen erwartet:

```text
dax:             inserted 2696
eurostoxx50:     inserted 2672
de_real_estate:  inserted 41
```

Ein erneuter Import ist ungefährlich und darf dieselben Zahlen unter
`unchanged` melden.

## 6. Datenbankbestand kontrollieren

In phpMyAdmin in der Datenbank `REDACTED_PRIVATE_VALUE` diese Abfrage ausführen:

```sql
SELECT
    a.slug,
    a.display_name,
    a.native_frequency,
    a.is_default_selected,
    COUNT(o.id) AS observations,
    MIN(o.observation_date) AS first_date,
    MAX(o.observation_date) AS last_date
FROM REDACTED_PRIVATE_VALUE.backtester_assets a
LEFT JOIN REDACTED_PRIVATE_VALUE.backtester_observations o ON o.asset_id = a.id
WHERE a.slug IN ('dax', 'eurostoxx50', 'de_real_estate')
GROUP BY a.id, a.slug, a.display_name, a.native_frequency, a.is_default_selected
ORDER BY a.sort_order;
```

Erwartet werden drei Zeilen. Nur `de_real_estate` hat
`is_default_selected = 1`.

## 7. Browserkontrolle

Die Seite öffnen und mit `Strg + F5` vollständig neu laden. Danach prüfen:

1. Einzelverläufe zeigen DAX, EURO STOXX 50 und Immobilien DE mit gefüllten
   Graphen.
2. Immobilien DE steht standardmäßig auf `3 %`; Immobilien USA bleibt auf
   `5 %`.
3. Die beiden Eingaben verändern nur die jeweilige Immobilienreihe.
4. Im Gesamtvergleich sind S&P 500, Bitcoin, Gold, Immobilien DE und MSCI
   World ausgewählt.
5. DAX, EURO STOXX 50, Immobilien USA und alle Reihen ohne Dividenden oder
   Miete stehen unter `Sonstige` und sind nicht vorausgewählt.

## Spätere Datenpflege

Nach der zusätzlichen Migration `015_automate_european_assets.sql` ergänzt der
13-Uhr-Cronjob DAX und EURO STOXX 50 automatisch über den jüngsten
Yahoo-Chart-Zeitraum. Immobilien DE wird über die offizielle Eurostat
Statistics API täglich auf neue oder revidierte Quartalswerte geprüft. Die
CSV-Dateien bleiben die unveränderte historische Basis und müssen für normale
laufende Aktualisierungen nicht mehr ersetzt werden. Die bis 2030 hinterlegten
jährlichen Dividendenfaktoren benötigen keinen monatlichen Import.
