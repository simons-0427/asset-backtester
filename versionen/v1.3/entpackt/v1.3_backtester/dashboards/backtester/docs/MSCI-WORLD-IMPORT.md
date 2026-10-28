# MSCI World einrichten

## Enthaltene Daten

- zusätzlicher Startwert: 31.07.2016 mit einem MSCI-World-Preiswert von 1.716 USD
- `olddata.xls`: 72 Monatswerte vom 31.08.2016 bis 29.07.2022
- `recentdata.xls`: 1.042 Tageswerte vom 15.08.2022 bis 14.08.2026
- `var/import/msci_world_monthly.csv`: 73 Monatswerte einschließlich Startwert in USD
- `var/import/msci_world_daily.csv`: konvertierte Tagesdatei in USD
- `var/import/msci_world.csv`: 1.115 zusammengeführte Importzeilen in EUR

Für die EUR-Reihe wird je MSCI-Datum der letzte verfügbare USD/EUR-Referenzkurs
der EZB verwendet. Die ursprüngliche MSCI-World-Preisreihe in USD und das
verwendete FX-Datum bleiben als eigene CSV-Spalten erhalten.

Die hinterlegten Jahresdividendenrenditen für 2016 bis 2030 werden vollständig
am jeweiligen 31.12. reinvestiert. Der Preisindex selbst wird dabei nicht
verändert und bleibt im Einzelverlauf sowie optional im Gesamtvergleich als
eigene Linie sichtbar.

## CSV-Dateien erneut erzeugen

Im lokalen Backtester-Ordner in PowerShell ausführen:

```powershell
node scripts\prepare-msci-world-data.mjs
```

Das Skript bricht ab, wenn Dateikopf, erwartete Zeiträume, Zeilenzahl,
Sortierung oder Wechselkurse nicht stimmen.

## Einmaliges Deployment auf STRATO

### 1. Dateien hochladen

Den aktualisierten Inhalt des Backtester-Ordners per SFTP hochladen. Für MSCI
World müssen insbesondere diese neuen oder geänderten Dateien auf dem Server
ankommen:

- `assets/css/asset-backtester.css`
- `assets/js/asset-backtester/app.js`
- `assets/js/asset-backtester/charts.js`
- `includes/backtester/Services/AnnualDividendAdjustment.php`
- `includes/backtester/Services/BacktestService.php`
- `includes/backtester/Services/SummaryService.php`
- `includes/backtester/Repositories/AssetRepository.php`
- `includes/backtester/Providers/CsvImportProvider.php`
- `sql/backtester/010_msci_world.sql`
- `var/import/msci_world.csv`
- `index.html`

Die beiden Roh-CSV-Dateien können zusätzlich archiviert werden. Für den Import
auf dem Server wird nur `var/import/msci_world.csv` benötigt.

### 2. Datenbank erweitern

In phpMyAdmin die produktive Backtester-Datenbank auswählen und danach
`sql/backtester/010_msci_world.sql` importieren. Die Migration legt das Asset an
und speichert die 15 Jahresdividendenrenditen.

### 3. Per SSH in den Backtester wechseln

```bash
cd /mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
pwd
```

`pwd` muss diesen Backtester-Pfad ausgeben.

### 4. PHP-Dateien prüfen

```bash
/usr/bin/php -l includes/backtester/Services/AnnualDividendAdjustment.php
/usr/bin/php -l includes/backtester/Services/BacktestService.php
/usr/bin/php -l includes/backtester/Services/SummaryService.php
/usr/bin/php -l includes/backtester/Providers/CsvImportProvider.php
```

Jeder Befehl muss `No syntax errors detected` melden.

### 5. MSCI-World-Kurse importieren

```bash
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=msci_world --from=2016-07-31 --to=2026-08-14
```

Beim ersten Lauf werden 1.115 Kurszeilen erwartet. Ein erneuter Lauf ist sicher:
Bereits identische Zeilen werden nicht doppelt angelegt.

### 6. Ergebnis prüfen

Im Browser öffnen und mit `Strg+F5` neu laden:

```text
https://smstark-projekt.de/dashboards/backtester/
```

Danach prüfen:

1. In der Marktübersicht steht eine Zeile `MSCI World inkl. Dividenden`.
2. Unter Einzelverläufe sind Preisindex und `Preis + Dividenden` getrennt.
3. Im Gesamtvergleich sind standardmäßig weiterhin nur S&P 500, Bitcoin und
   Gold ausgewählt.
4. `MSCI World` und `MSCI World ohne Div.` lassen sich getrennt einschalten.
5. Am 31.12.2016 steigt nur die Dividendenlinie um 2,69 %, nicht der Preisindex.
