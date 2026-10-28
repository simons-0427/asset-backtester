# STRATO-Upload: automatische Europa-Aktualisierung

Dieses Update erhält die bereits importierten Historien. Es ändert nur die
laufenden Provider für DAX, EURO STOXX 50 und Immobilien DE. Der vorhandene
13-Uhr-Cronjob `cron/backtester/update-daily.php` bleibt unverändert.

## 1. Mit FileZilla hochladen

Zielordner:

```text
/mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
```

Diese Dateien in die gleichnamigen Serverordner laden und vorhandene Dateien
überschreiben:

```text
includes/backtester/App.php
includes/backtester/Providers/YahooIndexProvider.php
includes/backtester/Providers/EurostatHousePriceProvider.php
tests/backtester/ProviderParserTest.php
sql/backtester/015_automate_european_assets.sql
```

`config/backtester.php` muss für dieses Update nicht hochgeladen werden. Der
Yahoo-Provider übernimmt die bereits funktionierende MSCI-World-Transport-
konfiguration; Eurostat besitzt sichere interne Standardwerte.

## 2. Migration importieren

In phpMyAdmin links zuerst `REDACTED_PRIVATE_VALUE` auswählen und dann
`sql/backtester/015_automate_european_assets.sql` importieren. Die Migration:

- stellt DAX und EURO STOXX 50 von `manual_csv` auf `yahoo_chart_scraper`,
- stellt Immobilien DE auf `eurostat_statistics_api`,
- setzt nur die Synchronisationsfehler und Sperren dieser drei Reihen zurück,
- setzt `next_check_at` auf `NULL`, damit der nächste Lauf sie sicher prüft.

Historische Beobachtungen und Dividendenwerte werden nicht gelöscht oder
überschrieben.

## 3. Syntax und Tests per SSH

```bash
cd /mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
/usr/bin/php -l includes/backtester/Providers/YahooIndexProvider.php
/usr/bin/php -l includes/backtester/Providers/EurostatHousePriceProvider.php
/usr/bin/php -l includes/backtester/App.php
/usr/bin/php -q tests/backtester/run.php
```

Alle Syntaxprüfungen müssen `No syntax errors detected` melden. Der Testlauf
muss `52 passed, 0 failed, 1 skipped` melden; der optionale MySQL-Test darf
weiter übersprungen sein.

## 4. Jeden Provider einmal einzeln testen

Die Befehle einzeln und mit den dargestellten Zeilenumbrüchen ausführen:

```bash
/usr/bin/php -q cron/backtester/backfill-history.php \
--asset=dax \
--from=2026-08-20 \
--to=2026-08-20
```

```bash
/usr/bin/php -q cron/backtester/backfill-history.php \
--asset=eurostoxx50 \
--from=2026-08-20 \
--to=2026-08-20
```

```bash
/usr/bin/php -q cron/backtester/backfill-history.php \
--asset=de_real_estate \
--from=2026-01-01 \
--to=2026-08-20
```

Jeder Lauf muss `assets_succeeded: 1` und `assets_failed: 0` melden. Bei den
beiden Börsenindizes ist am selben Handelstag `updated: 1` oder `unchanged`
normal. Eurostat liefert bis zu einer neuen Veröffentlichung nur bereits
bekannte Quartale; deshalb sind dort null neue Zeilen ebenfalls korrekt.

## 5. Daily-Lauf kontrollieren

Der bestehende Cronjob bleibt täglich um 13:00 Uhr aktiv. Nach dem nächsten
Lauf:

```bash
tail -n 20 logs/backtester.log
```

Für `dax`, `eurostoxx50` und `de_real_estate` darf kein neuer ERROR-Eintrag
erscheinen. DAX und EURO STOXX 50 erhalten börsentägliche Beobachtungen.
Immobilien DE bleibt eine echte Quartalsreihe und wird nicht künstlich mit
täglichen Rohwerten aufgefüllt.
