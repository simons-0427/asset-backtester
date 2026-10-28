# STRATO-Deployment: laufende Werte für alle globalen Reihen

Stand: 20. August 2026

Diese Erweiterung verändert keine bestehende Historie. Sie schaltet nur die acht
bisher manuellen Reihen auf laufende Abrufe um. `config/backtester.php` wird
nicht überschrieben und es werden keine neuen API-Keys benötigt.

## 1. Mit FileZilla hochladen

Lokale und entfernte Ordnerstruktur müssen identisch bleiben. Diese Dateien
hochladen beziehungsweise überschreiben:

```text
includes/backtester/App.php
includes/backtester/Providers/HttpClient.php
includes/backtester/Providers/YahooFundProvider.php
includes/backtester/Providers/EcbCompoundedIndexProvider.php
includes/backtester/Providers/NyFedSofrIndexProvider.php
includes/backtester/Providers/EurostatInflationProvider.php
includes/backtester/Providers/EurostatHousePriceProvider.php
includes/backtester/Providers/BlsInflationProvider.php
config/cacert.pem
sql/backtester/019_automate_global_live_sources.sql
tests/backtester/ProviderParserTest.php
```

Optional für die Dokumentation:

```text
README.md
docs/CRON.md
docs/STRATO-DEPLOYMENT-GLOBAL-LIVE-2026-08-20.md
```

`config/backtester.php` ausdrücklich nicht hochladen.

## 2. SQL-Migration ausführen

In phpMyAdmin links `REDACTED_PRIVATE_VALUE` anklicken, danach **Importieren** wählen und
diese Datei ausführen:

```text
sql/backtester/019_automate_global_live_sources.sql
```

Die Datei enthält selbst `USE REDACTED_PRIVATE_VALUE;`. Sie löscht keine Kurswerte. Sie
ändert nur die Provider-Zuordnung und setzt den nächsten Prüfungstermin auf
sofort fällig.

## 3. Per SSH in den richtigen Ordner wechseln

```bash
ssh stu969337242@5221695.ssh.w1.strato.hosting
```

Nach der Anmeldung diesen Befehl vollständig in einer Zeile ausführen:

```bash
cd /mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
```

Dann:

```bash
pwd
```

Erwartet:

```text
/mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
```

## 4. PHP-Syntax prüfen

Jeden Befehl einzeln und vollständig in genau einer Zeile ausführen:

```bash
/usr/bin/php -l includes/backtester/Providers/HttpClient.php
/usr/bin/php -l includes/backtester/Providers/YahooFundProvider.php
/usr/bin/php -l includes/backtester/Providers/EcbCompoundedIndexProvider.php
/usr/bin/php -l includes/backtester/Providers/NyFedSofrIndexProvider.php
/usr/bin/php -l includes/backtester/Providers/EurostatInflationProvider.php
/usr/bin/php -l includes/backtester/Providers/BlsInflationProvider.php
/usr/bin/php -l includes/backtester/App.php
/usr/bin/php -l tests/backtester/ProviderParserTest.php
ls -l config/cacert.pem
```

Jede Ausgabe muss mit `No syntax errors detected` enden.

## 5. Tests ausführen

```bash
/usr/bin/php -q tests/backtester/run.php
```

Erwartet:

```text
Result: 62 passed, 0 failed, 1 skipped.
```

Der übersprungene MySQL-Test ist ohne separate Testdatenbank weiterhin normal.

## 6. Alle fälligen Quellen sofort einmal abrufen

```bash
/usr/bin/php -q cron/backtester/update-daily.php
```

Der Lauf verarbeitet neben den bisherigen Quellen auch:

- Globale IG-Anleihen EUR-gesichert: Yahoo `EUNA.DE`, adjustierter Schluss
- Globale IG-Anleihen ungesichert: Yahoo `AGGG.L`, adjustierter Schluss plus EZB-FX
- Breite Rohstoffe: Yahoo `AIGC.L`, adjustierter Schluss plus EZB-FX
- Euro-Geldmarkt: offizieller zusammengesetzter €STR-Index der EZB
- US-Geldmarkt: offizieller SOFR-Index der New York Fed plus EZB-FX
- Inflation Deutschland und Euroraum: Eurostat HICP
- Inflation USA: BLS CPI-U

Bei monatlichen Reihen ist `rows_inserted: 0` normal, solange der Anbieter noch
keinen neuen Monat veröffentlicht hat. Bereits bekannte Revisionsmonate können
als `updated` erscheinen.

## 7. Ergebnis kontrollieren

```bash
tail -n 25 logs/backtester.log
```

Für jede neue Reihe sollte entweder `Asset synchronisiert` erscheinen oder ein
konkreter Fehlercode. Danach in phpMyAdmin diese Abfrage ausführen:

```sql
SELECT a.slug, ds.provider_type, MAX(o.observation_date) AS letzter_wert, ss.last_successful_sync_at, ss.next_check_at, ss.last_error_code FROM REDACTED_PRIVATE_VALUE.backtester_assets a INNER JOIN REDACTED_PRIVATE_VALUE.backtester_data_sources ds ON ds.id = a.source_id LEFT JOIN REDACTED_PRIVATE_VALUE.backtester_observations o ON o.asset_id = a.id LEFT JOIN REDACTED_PRIVATE_VALUE.backtester_sync_state ss ON ss.asset_id = a.id WHERE a.slug IN ('global_ig_bonds','global_ig_bonds_unhedged','commodities','euro_money_market','us_money_market','inflation_de','inflation_euro_area','inflation_us') GROUP BY a.id, a.slug, ds.provider_type, ss.last_successful_sync_at, ss.next_check_at, ss.last_error_code ORDER BY a.sort_order;
```

Die drei Markt-Proxys und beide Geldmärkte dürfen börsen- oder
veröffentlichungsbedingt einen unterschiedlichen letzten Tag besitzen. Die
Inflationsreihen bleiben echte Monatsendwerte.

## 8. Täglichen 13-Uhr-Job prüfen

Im STRATO-Cronbereich muss weiterhin genau dieser tägliche Job in der Zeitzone
`Europe/Berlin` aktiv sein:

```text
Zeit: täglich 13:00 Uhr
Befehl: /usr/bin/php -q /mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester/cron/backtester/update-daily.php
```

Keinen zweiten Cronjob für die acht Reihen anlegen. Migration 019 sorgt dafür,
dass sie vom bestehenden Job automatisch mitgenommen werden.
