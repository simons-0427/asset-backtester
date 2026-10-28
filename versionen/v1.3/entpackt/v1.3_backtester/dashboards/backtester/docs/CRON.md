# Cron- und Backfill-Betrieb

Alle Beispiele werden aus dem Backtester-Verzeichnis beziehungsweise mit absoluten Hosterpfaden ausgeführt.

## Initialer Backfill

```bash
php cron/backtester/backfill-history.php
php cron/backtester/backfill-history.php --asset=bitcoin
php cron/backtester/backfill-history.php --asset=sp500 --from=2005-01-01 --to=2026-08-02

# STRATO CGI/FastCGI-Binary über SSH
/usr/bin/php -q cron/backtester/backfill-history.php --asset=bitcoin --from=2013-04-28
```

Der Import arbeitet in providerabhängigen Zeitfenstern, speichert nach jedem erfolgreichen Chunk einen Fortsetzungs-Cursor, upsertet idempotent und bricht einen fehlgeschlagenen Asset-Chunk isoliert ab. Derselbe Bereich kann mit `--from` erneut ausgeführt werden. Die historischen Reihen bleiben importiert; ein historischer Gold-Backfill ist für die GoldAPI-Umstellung nicht erforderlich. Laufende Werte beginnen am 03.08.2026. S&P 500 nutzt FRED `SP500`; zukünftige Goldwerte nutzt GoldAPI `XAU/USD` mit der konfigurierten Vorwärtskalibrierung.

## Täglicher Job

```cron
0 13 * * * /usr/bin/php /ABSOLUTER/PFAD/dashboards/backtester/cron/backtester/update-daily.php
25 7 * * 1 /usr/bin/php /ABSOLUTER/PFAD/dashboards/backtester/cron/backtester/repair-gaps.php
```

Den Daily-Job in der Zeitzone `Europe/Berlin` planen. Die Datenbank entscheidet
pro Reihe, ob ein Abruf fällig ist: Bitcoin täglich, Marktwerte am nächsten
Werktag, Monatsreihen nach sieben Tagen und Quartalsreihen nach 30 Tagen.
Details stehen in `REAL-ESTATE-AND-DAILY-SYNC.md`.

Auf STRATO-Webhosting wird PHP über das CGI/FastCGI-Binary gestartet. Dort
`/usr/bin/php -q cron/backtester/update-daily.php` verwenden. Optionen für das
Skript müssen hinter dem Skriptnamen stehen; `-f` darf dort nicht vor solchen
Optionen verwendet werden.

Der tägliche Job lädt nur nach ihrer Quellfrequenz fällige Assets, wiederholt höchstens den geschützten Live-Zeitraum ab dem 03.08.2026, übernimmt Providerkorrekturen und nutzt pro Asset einen auslaufenden Datenbank-Lock. Der S&P-500-USD-Schluss kommt von FRED. Der GoldAPI-XAU/USD-Wert wird vor der gemeinsamen EZB-Umrechnung mit dem festgelegten GLD/XAU-Verhältnis kalibriert. Der Lückenjob ignoriert kleine Börsenlücken als mögliche Feiertage und repariert nur größere Kandidaten; Bitcoin wird kalendertäglich geprüft.

Zusätzlich prüft `update-daily.php` den separaten Multpl-Monatsstatus. Ein HTTP-Abruf erfolgt nur bei Fälligkeit; nach Erfolg ist der nächste Termin der erste Tag des Folgemonats. Für den einmaligen Backfill:

```bash
/usr/bin/php -q -f cron/backtester/backfill-dividend-yields.php --from=2016-07-01
```

Details und Kontrollabfragen stehen in `DIVIDEND-YIELD-SCRAPER.md`.

## Zusaetzliche globale Livequellen

Nach Migration `019_automate_global_live_sources.sql` verarbeitet derselbe
13-Uhr-Job auch EUNA.DE, AGGG.L, AIGC.L, den offiziellen EURSTR-Index, den
offiziellen SOFR-Index, Eurostat HICP Deutschland und Euroraum sowie BLS CPI-U.
Der Job läuft täglich, überspringt aber noch nicht fällige Monats- und Quartalsreihen.
Die Markt-Proxys ueberlappen die letzten Handelstage; die amtlichen Monatsreihen
ueberlappen ihr Revisionsfenster. Vorhandene historische CSV-Werte bleiben die
Basis und werden nicht neu erzeugt.

## Externer HTTPS-Cron ohne Hosting-Cronjob

```text
URL: https://smstark-projekt.de/dashboards/backtester/cron/backtester/update-daily.php
Header: X-Backtester-Cron-Token: LANGES_GEHEIMNIS
```

Den externen Job nur über HTTPS aufrufen und den Header gegenüber einem Geheimnis in der URL bevorzugen. Secret ausschließlich in `config/backtester.php`, als Umgebungsvariable und in der geschützten Konfiguration des Zeitplaners ablegen. Der Vergleich ist timing-safe; sensible Fehlerdetails gehen nur in das Serverlog. Falls ein Zeitplaner keine eigenen Header unterstützt, bleibt `?token=LANGES_GEHEIMNIS` als kompatibler Rückfall verfügbar.

## Page-Visit-Fallback

Das Frontend sendet weiterhin einen kleinen same-origin-POST an
`api/backtester/sync-if-stale.php`. In der Produktionskonfiguration beantwortet
der Endpunkt ihn mit `scheduled_cron_only` und startet keinen Providerabruf.
Damit erfolgen die Datenabrufe planbar über den täglichen 13-Uhr-Job und nicht
durch zufällige Seitenbesuche.
