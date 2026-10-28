# STRATO-Deployment: Sortierung, TER und Steuermodell

Für dieses Update ist keine SQL-Datei nötig. Die vorhandenen Markt- und Inflationsdaten bleiben unverändert.

## 1. Mit FileZilla hochladen

Lokaler Ausgangsordner:

`/path/to/project`

Server-Zielordner:

`/mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester`

Diese Dateien mit gleicher Ordnerstruktur hochladen und vorhandene Dateien überschreiben:

- `index.html`
- `assets/css/asset-backtester.css`
- `assets/js/asset-backtester/app.js`
- `assets/js/asset-backtester/api.js`
- `assets/js/asset-backtester/calculations.js`
- `assets/js/asset-backtester/charts.js`
- `api/backtester/series.php`
- `includes/backtester/Http/RequestValidator.php`
- `includes/backtester/Services/BacktestService.php`
- `includes/backtester/Services/TerAdjustment.php`
- `includes/backtester/Services/TaxAdjustment.php`
- `tests/backtester/FeeTaxAdjustmentTest.php`
- `tests/backtester/RequestValidatorTest.php`
- `tests/js/calculations.test.mjs`
- `tests/js/charts.test.mjs`
- `tests/js/msci-world-integration.test.mjs`
- `docs/FILE-INVENTORY.md`
- `docs/STRATO-DEPLOYMENT-TER-STEUERN-2026-08-20.md`

`config/backtester.php` nicht überschreiben. Es gibt für dieses Update auch keine SQL-Migration.

## 2. Per SSH in den Backtester wechseln

```bash
cd /mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
```

## 3. PHP-Syntax prüfen

Jeden Befehl vollständig in eine Zeile kopieren:

```bash
/usr/bin/php -l includes/backtester/Services/TerAdjustment.php
/usr/bin/php -l includes/backtester/Services/TaxAdjustment.php
/usr/bin/php -l includes/backtester/Services/BacktestService.php
/usr/bin/php -l includes/backtester/Http/RequestValidator.php
/usr/bin/php -l api/backtester/series.php
```

Bei jeder Datei muss `No syntax errors detected` erscheinen.

## 4. Tests auf STRATO ausführen

```bash
/usr/bin/php -q tests/backtester/run.php
```

Erwartet werden keine fehlgeschlagenen Tests. Der MySQL-Test darf weiterhin übersprungen werden, solange `BACKTESTER_TEST_CONFIG` nicht gesetzt ist.

## 5. Browser prüfen

Die Seite mit `Strg + F5` neu laden und kontrollieren:

1. In der Marktübersicht lässt sich jede Zeitraumspalte durch Klick auf die Überschrift ab- und aufsteigend sortieren.
2. Jeder geeignete Einzelverlauf zeigt ein TER-Feld; Bitcoin, Gold und beide Immobilienkarten zeigen stattdessen den Direktanlage-Hinweis.
3. Die TER verändert nur den jeweiligen Einzelverlauf, nicht den Gesamtvergleich.
4. In `Einstellungen` des Gesamtvergleichs sind Steuern standardmäßig aktiv und Inflation Deutschland standardmäßig ausgewählt.
5. Im Chart-Tooltip steht die Linie mit der höchsten Rendite oben.
6. Bei Bitcoin zeigt ein Hover innerhalb des ersten Jahres das Einkommensteuer-Modell und nach Ablauf eines Jahres die Steuerfreiheit nach Haltedauer.
7. Die Ergebnistabelle ist nach Gesamtrendite absteigend sortiert und zeigt die modellierte Steuer.

Falls die Seite leer bleibt, zuerst den PHP-Test wiederholen und anschließend die Browser-Konsole öffnen. Durch die neue Versionskennung `20260820-ter-tax-v1` sollten alte CSS- und JavaScript-Dateien nicht aus dem Browser-Cache geladen werden.
