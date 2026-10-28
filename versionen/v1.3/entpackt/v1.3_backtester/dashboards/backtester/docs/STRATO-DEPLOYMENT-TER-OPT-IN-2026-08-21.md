# STRATO-Deployment: globale Schalter für TER und Steuern

Für dieses Update ist keine SQL-Datei nötig. `config/backtester.php` und die Marktdaten bleiben unverändert.

## 1. Mit FileZilla hochladen

Lokaler Ausgangsordner:

`/path/to/project`

Server-Zielordner:

`/mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester`

Diese Dateien mit gleicher Ordnerstruktur hochladen und vorhandene Dateien überschreiben:

- `index.html`
- `assets/css/asset-backtester.css`
- `assets/js/asset-backtester/app.js`
- `assets/js/asset-backtester/charts.js`
- `includes/backtester/Services/BacktestService.php`
- `tests/backtester/FeeTaxAdjustmentTest.php`
- `tests/js/msci-world-integration.test.mjs`
- `docs/FILE-INVENTORY.md`
- `docs/STRATO-DEPLOYMENT-TER-OPT-IN-2026-08-21.md`

Zusätzlich diese Projektseite hochladen:

- Lokal: `/path/to/project`
- Server: `/mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/projekte/backtester.html`

## 2. Per SSH prüfen

Jeden Befehl vollständig in eine Zeile kopieren:

```bash
cd /mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
/usr/bin/php -l includes/backtester/Services/BacktestService.php
/usr/bin/php -q tests/backtester/run.php
```

Erwartet werden `No syntax errors detected`, keine fehlgeschlagenen Tests und höchstens der bereits bekannte übersprungene MySQL-Test.

## 3. Im Browser prüfen

Die Seite mit `Strg + F5` neu laden.

- TER und Steuern sind unter `Gesamtvergleich > Einstellungen` zunächst ausgeschaltet.
- Ohne aktivierten TER-Schalter wird in den Einzelverläufen nichts zu TER angezeigt oder berechnet.
- Nach der Aktivierung erscheint unter jedem Einzelverlauf ein langes horizontales TER-Feld mit Text darüber.
- Auch Bitcoin, Gold und beide Immobilienverläufe erhalten dann ein eigenes TER-Feld.
- Ohne aktivierten Steuer-Schalter sind Steuerparameter, Steuer-Spalte und Steuerhinweise im Ergebnis ausgeblendet.
- Nach der Aktivierung erscheinen die Steuerparameter und die berechnete Modellsteuer.
- Die Projektseite nennt die neuen Anlageklassen, Dividenden, Mietcashflows, Inflation, TER, Steuern und automatischen Aktualisierungen im vorhandenen Layout.

Die Cache-Version dieses Updates lautet `20260821-nominal-default-v3`.
