# Multpl-Dividendenrendite: Backfill und Monatsbetrieb

## Zweck

Der Backtester ergänzt die bestehende tägliche S&P-500-Preisindexreihe um eine
reinvestierte Dividendenrechnung. Quelle ist die Monats-Tabelle:

```text
https://www.multpl.com/s-p-500-dividend-yield/table/by-month
```

Multpl definiert den Tabellenwert als 12-Monats-Dividende je Aktie geteilt durch
den Preis. Der Wert ist annualisiert. Mit `†` markierte Einträge sind Schätzungen.

Die Anwendung speichert pro Kalendermonat genau eine Zeile. Ein späterer
endgültiger Wert ersetzt damit idempotent die Schätzung desselben Monats. Die
täglichen S&P-Preisbeobachtungen in `backtester_observations` werden nicht
verändert.

## Berechnung

Für ein Intervall zwischen zwei echten S&P-Preisbeobachtungen wird der zum
Kalendermonat gehörende annualisierte Wert zeitanteilig aufgezinst:

```text
Dividendenfaktor = (1 + Dividendenrendite / 100) ^ (Kalendertage / 365,2425)
Gesamtfaktor      = Preisfaktor × Dividendenfaktor
```

Fehlt in einem späteren Monat noch ein neuer Wert, wird der zuletzt bekannte
Monatswert vorübergehend fortgeführt. Die Dividendenkomponente läuft bis
zum gewählten Kalendertag weiter, also auch dann, wenn das Enddatum auf ein
Wochenende fällt. Für Volatilität, Sharpe sowie den besten und schlechtesten Tag
zählen trotzdem nur Übergänge zwischen echten S&P-Preisbeobachtungen;
fortgeschriebene Tage werden dort nicht als eigene Tagesrenditen gewertet.

Das Verfahren unterstellt eine gleichmäßige Dividendenentstehung und sofortige
Wiederanlage. Es ist deshalb eine geglättete Modellrechnung, keine offizielle
S&P-500-Total-Return-Reihe und keine Rekonstruktion einzelner Ausschüttungstage.

## Einmaliges Deployment auf STRATO

### 1. Sicherung

Vor dem Upload:

1. Die Backtester-Datenbank in phpMyAdmin exportieren.
2. Den bestehenden Serverordner `dashboards/backtester` sichern.
3. Die echte `config/backtester.php` separat sichern und niemals veröffentlichen.

### 2. Migration importieren

In phpMyAdmin die richtige produktive Datenbank auswählen und anschließend
importieren:

```text
sql/backtester/008_multpl_sp500_dividend_yields.sql
```

Die Migration erstellt ausschließlich neue Dividenden- und Sync-Tabellen und
aktualisiert die sichtbare S&P-Methodik. Vorhandene Kurszeilen werden nicht
gelöscht oder überschrieben.

Die Migration kommt vor dem Programm-Upload. Damit existieren die neuen Tabellen
bereits, sobald der neue PHP-Code aktiv wird.

### 3. Dateien hochladen

Den aktualisierten Backtester-Ordner per SFTP/FileZilla hochladen. Insbesondere
müssen die neuen Dateien unter `Contracts`, `Domain`, `Providers`,
`Repositories`, `Services` sowie das neue Backfill-Skript vorhanden sein.

Die produktive `config/backtester.php` dabei **nicht blind überschreiben**. Sie
enthält Datenbankzugang, Provider-Schlüssel und das Cron-Geheimnis. Diese Datei
auf dem Server behalten und nur die unten genannten neuen Konfigurationswerte
ergänzen.

### 4. Konfiguration prüfen

In `config/backtester.php` muss unter `providers` der Abschnitt
`multpl_dividend_yield` vorhanden sein. Er benötigt keinen API-Schlüssel. Unter
`features` muss `dividend_adjustments` auf `true` stehen.

Der neue Provider-Abschnitt lautet:

```php
'multpl_dividend_yield' => [
    'enabled' => true,
    'url' => 'https://www.multpl.com/s-p-500-dividend-yield/table/by-month',
    'source_slug' => 'multpl-sp500-dividend-yield',
    'backfill_from' => '2016-07-01',
    'recent_months' => 3,
    'timeout_seconds' => 20,
    'max_retries' => 2,
    'retry_base_ms' => 750,
],
```

Im bereits vorhandenen `features`-Block ergänzen:

```php
'dividend_adjustments' => true,
```

Keine Datenbankpasswörter, Provider-Schlüssel oder Cron-Geheimnisse in Chats,
Screenshots, URLs oder Dokumente kopieren.

### 5. Backfill über SSH ausführen

Zuerst in das Backtester-Verzeichnis wechseln:

```bash
cd /mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
```

Danach:

```bash
/usr/bin/php -q -f cron/backtester/backfill-dividend-yields.php --from=2016-07-01
```

Erwartet wird eine JSON-Antwort mit:

```text
"success": true
"status": "success"
"mode": "backfill"
"rows_inserted": eine Zahl größer als 100
```

Der Juli 2016 wird als Startanker mit importiert, damit Zeiträume ab August 2016
sofort eine Dividendenrendite besitzen.

### 6. Daten in phpMyAdmin kontrollieren

```sql
SELECT COUNT(*) AS monate,
       MIN(observation_month) AS erster_monat,
       MAX(observation_month) AS letzter_monat,
       SUM(is_estimate) AS schaetzungen
FROM backtester_dividend_yields;
```

```sql
SELECT observation_month, source_observation_date, annual_yield_pct, is_estimate
FROM backtester_dividend_yields
ORDER BY observation_month DESC
LIMIT 5;
```

### 7. Gesamtabgleich testen

```bash
/usr/bin/php -q -f cron/backtester/update-daily.php
```

Der JSON-Block `dividend_yield` zeigt normalerweise `not_due`, wenn der Backfill
gerade erfolgreich war. Das ist korrekt.

Anschließend prüfen:

```text
https://smstark-projekt.de/dashboards/backtester/api/backtester/status.php
```

Unter `status.dividendYield` müssen der letzte Monat, der erfolgreiche Sync und
der nächste Prüftermin stehen.

## Automatischer Monatsbetrieb

Es ist kein zweiter Cronjob nötig. Der vorhandene tägliche Aufruf von
`update-daily.php` prüft den Dividendenstatus, ruft Multpl aber nur auf, wenn der
Monatswert fällig ist. Nach einem Erfolg liegt der nächste Termin am ersten Tag
des Folgemonats. Bei einem Fehler greift ein begrenzter Backoff; der nächste
tägliche Cronlauf versucht es später erneut.

Bei jedem Monatsabruf werden die jüngsten drei Monate erneut eingelesen. Dadurch
können vorläufige Angaben durch spätere Multpl-Werte ersetzt werden.

## Abschalten ohne Datenverlust

In `config/backtester.php`:

```php
'enabled' => false,
```

stoppt den Scraper. Zusätzlich:

```php
'dividend_adjustments' => false,
```

schaltet die Dividendenrechnung aus. Die importierten
Monatswerte und alle ursprünglichen S&P-Preisdaten bleiben erhalten.
