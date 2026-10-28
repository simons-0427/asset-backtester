# Deployment-Checkliste für klassisches Webhosting

## Vor dem Upload

- [ ] PHP 8.1 oder neuer bestätigt.
- [ ] Erweiterungen `pdo_mysql`, `curl`, `json` und `mbstring` aktiv.
- [ ] MySQL/MariaDB mit InnoDB und utf8mb4 verfügbar.
- [ ] Kostenlosen FRED-API-Key für die Reihe `SP500` erstellt.
- [ ] GoldAPI-Key für `XAU/USD` im GoldAPI-Dashboard erstellt.
- [ ] FRED- und GoldAPI-Bedingungen für Speicherung, Attribution, historische Abrufe und öffentliche Darstellung bestätigt.
- [ ] Private Multpl-Nutzung und automatischer Monatsabruf mit dem Anbieter geklärt.
- [ ] MSCI-/Yahoo-Weitergaberechte geklärt oder MSCI-Scraper deaktiviert gelassen.
- [ ] `config/backtester.example.php` nach `config/backtester.php` kopiert und ausgefüllt.
- [ ] Langes Cron-Secret gesetzt; echte Config und `.env` nicht in einem öffentlichen Archiv enthalten.

## Datenbank über phpMyAdmin

- [ ] `sql/backtester/001_schema.sql` importiert.
- [ ] `sql/backtester/002_seed_assets.sql` importiert.
- [ ] `sql/backtester/003_indexes.sql` einmalig importiert.
- [ ] `sql/backtester/004_bitcoin_composite_source.sql` importiert.
- [ ] `sql/backtester/005_free_market_live_sources.sql` nach Code-Upload importiert.
- [ ] Bei einer manuellen GLD-Lücke `sql/backtester/006_gold_gap_2026_08.sql` einmalig importiert.
- [ ] `sql/backtester/007_goldapi_forward_calibrated.sql` nach GoldAPI-Key-Konfiguration importiert.
- [ ] `sql/backtester/008_multpl_sp500_dividend_yields.sql` vor Aktivierung der neuen PHP-Dateien importiert.
- [ ] `sql/backtester/009_sp500_dividend_wording.sql` nach Migration 008 importiert.
- [ ] `sql/backtester/010_msci_world.sql` importiert.
- [ ] `sql/backtester/011_us_real_estate.sql` importiert.
- [ ] `sql/backtester/012_enable_msci_world_scraper.sql` nur nach Rechteklärung importiert.
- [ ] In `backtester_assets` stehen anschließend `SP500`/`fred/series/observations` und `XAU`/`USD` mit `provider_type = goldapi`; beide besitzen `raw_currency = USD` und `display_currency = EUR`.
- [ ] Die bestehenden Goldwerte bis 07.08.2026 wurden nicht verändert; nur zukünftige Werte werden über GoldAPI fortgeschrieben.
- [ ] Datenbankbenutzer besitzt nur SELECT/INSERT/UPDATE/DELETE auf den Backtester-Tabellen.

## Upload per FileZilla

- [ ] Gesamten Ordner `backtester` nach `/dashboards/backtester` übertragen.
- [ ] Bei einem Teilupload mindestens diese Laufzeitdateien übertragen:
  - `includes/backtester/App.php`
  - `includes/backtester/Domain/HistoricalObservation.php`
  - `includes/backtester/Providers/GoldApiProvider.php`
  - `includes/backtester/Providers/EcbExchangeRateProvider.php`
  - `includes/backtester/Providers/FredProvider.php`
  - `includes/backtester/Providers/FredCsvProvider.php`
  - `includes/backtester/Providers/MsciWorldProvider.php`
  - `includes/backtester/Providers/UsdToEurConverter.php`
  - `includes/backtester/Providers/Sp500Provider.php`
  - `includes/backtester/Providers/GoldProvider.php`
  - `includes/backtester/Providers/CsvImportProvider.php`
  - `includes/backtester/Repositories/ObservationRepository.php`
  - `includes/backtester/Services/ValidationService.php`
  - `includes/backtester/Services/RentalCashflowAdjustment.php`
  - `cron/backtester/backfill-history.php`
  - `index.html`
- [ ] Versteckte `.htaccess`-Dateien mit übertragen.
- [ ] `logs` ist für PHP schreibbar, aber per Web gesperrt.
- [ ] `includes`, `config`, `sql`, `tests`, `docs` und `var` liefern per HTTP 403.
- [ ] `index.html` und `assets/vendor/chart.umd.min.js` liefern 200.

## Daten und Automatisierung

- [ ] S&P-500-Historie unverändert bis 02.08.2026 kontrolliert.
- [ ] XAU-Historie nach GoldAPI-Backfill bis zum letzten erfolgreichen Handelstag kontrolliert.
- [ ] Manueller FRED-Live-Test für `sp500` erfolgreich; erster neuer Wert liegt nicht vor dem 03.08.2026.
- [ ] Manueller GoldAPI-Live-Test für `gold` erfolgreich; erster neuer XAU-Wert liegt nicht vor dem 03.08.2026.
- [ ] `raw_value`, `raw_currency`, `eur_value`, `fx_rate_to_eur` und `fx_observation_date` eines neuen Marktwerts in phpMyAdmin plausibilisiert.
- [ ] Keine `flagged`-Werte ungeprüft veröffentlicht.
- [ ] `api/backtester/assets.php`, `summary.php`, `series.php` und `status.php` liefern gültiges UTF-8-JSON.
- [ ] `var/import/us_real_estate.csv` vom 01.01.2016 bis 01.05.2026 einmalig importiert.
- [ ] Täglichen Cron um 13:00 Europe/Berlin eingerichtet.
- [ ] Einmaligen Multpl-Backfill mit `backfill-dividend-yields.php --from=2016-07-01` erfolgreich ausgeführt.
- [ ] `status.php` enthält `status.dividendYield` ohne `public_error`.
- [ ] Der bestehende tägliche Cron meldet für `dividend_yield` `success` oder `not_due`.
- [ ] Lückenprüfung wöchentlich eingerichtet.

## Abnahme

- [ ] Mehrperiodentabelle enthält echte berechnete Werte oder korrektes „Nicht verfügbar“.
- [ ] Fünf Einzelcharts und Gesamtchart funktionieren mit Maus, Tastatur und Touch.
- [ ] Beide Netto-Mietcashflow-Eingaben bleiben synchron und aktualisieren Immobilienwerte.
- [ ] Mindestens ein Asset bleibt aktiv; Benutzerdefiniert blockiert Zukunft und falsche Reihenfolge.
- [ ] Tests ausgeführt; MySQL-Integrationstest mit separater Testkonfiguration ausgeführt.
- [ ] 320 px, 640 px, 960 px und Desktop im echten Browser geprüft.
- [ ] Kein horizontales Seiten-Scrolling; nur Tabellen dürfen lokal scrollen.
- [ ] Header, Navigation und Footer entsprechen der bestehenden Website.
- [ ] Providerausfall getestet: alte Daten bleiben sichtbar.
- [ ] Impressum, Datenschutz, Quellen, Warnhinweis und Attributionspflichten geprüft.
