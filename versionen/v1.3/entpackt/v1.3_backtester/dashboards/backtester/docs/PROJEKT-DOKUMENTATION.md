# Projektdokumentation: Assetklassen-Backtester

Stand: 16. August 2026

## Ergebnis

Der Assetklassen-Backtester vergleicht S&P 500, MSCI World, Bitcoin und Gold über denselben Zeitraum und mit demselben Startkapital. Die öffentliche Seite liest ihre Werte ausschließlich aus einer eigenen MySQL-Datenbank. Externe Anbieter werden nur serverseitig für Import und Aktualisierung angesprochen.

Produktiv erreichbar:

```text
https://smstark-projekt.de/dashboards/backtester/
```

Der aktuelle technische Aufbau läuft auf STRATO mit PHP 8.3, MySQL 8.0, Apache, Vanilla JavaScript und einer lokal eingebundenen Chart.js-Version. Der tägliche Bitcoin-Abruf wird durch einen externen HTTPS-Cronjob ausgelöst.

## Funktionsumfang

- Vergleich von S&P 500, MSCI World, Bitcoin und Gold auf einer gemeinsamen Kalenderachse
- frei wählbares Startkapital zwischen 100 Euro und 10 Millionen Euro
- feste Zeiträume von einer Woche bis zehn Jahre, zusätzlich Maximalzeitraum und eigene Datumswahl
- Einzelcharts mit den gespeicherten Schluss- beziehungsweise Referenzwerten
- getrennte Preis- und Dividendenlinien für S&P 500 und MSCI World
- Gesamtchart mit normierten Portfoliowerten
- logarithmische, ausschließlich positive Y-Achsen in allen Charts
- Mehrperiodenübersicht für 1W, 1M, 6M, 1Y, 3Y, 5Y und 10Y
- Endwert, absoluter Gewinn oder Verlust, Gesamtrendite, CAGR und maximaler Drawdown
- annualisierte Volatilität, Sharpe-Wert bei 0 % risikofreiem Zins und Calmar-Verhältnis
- bester Tag, schwächster Tag, Anteil positiver Tage und Zahl echter Tagesänderungen
- sichtbarer letzter echter Beobachtungstag
- URL-Zustand für Zeitraum, Startkapital und ausgewählte Anlageklassen
- verständliche Fehlerzustände, ohne technische Interna an Besucher auszugeben

## Datenstand und Methodik

| Reihe | Historische Quelle | EUR-Umrechnung | Importierter Zeitraum | Werte |
|---|---|---|---:|---:|
| Bitcoin | BTC/USD-Minutenwerte aus dem Kaggle-Datensatz „Bitcoin Historical Data“; laufend CoinGecko BTC/EUR | historische EZB-Referenzkurse für die importierte USD-Historie | 02.08.2016 bis 02.08.2026 | 3.653 vorbereitete Tageswerte |
| S&P 500 | FRED-Reihe `SP500`; zusätzliche annualisierte Monats-Dividendenrendite von Multpl | EZB-Kurs USD je EUR; Multpl-Rendite wird zeitanteilig reinvestiert | Preisreihe ab 02.08.2016; Dividendenanker ab 07/2016 | tägliche Preise plus eine Dividendenzeile je Monat |
| Gold | Historie unverändert; zukünftig GoldAPI `XAU/USD` mit festem Referenzverhältnis zur bestehenden Reihe | EZB-Kurs USD je EUR desselben Tages oder letzter zuvor veröffentlichter Wert | historische Daten bis 07.08.2026 unverändert; neue Werte ab dem nächsten erfolgreichen Lauf | Vorwärtsfortschreibung |
| MSCI World | manueller Startwert 31.07.2016 und bereitgestellte MSCI-World-Preisindex-Exporte in USD; hinterlegte Jahresdividendenrenditen | EZB-Kurs USD je EUR desselben Tages oder letzter zuvor veröffentlichter Wert | Monatswerte 31.07.2016 bis 29.07.2022; Tageswerte 15.08.2022 bis 14.08.2026 | 1.115 Preiswerte plus 15 Jahresdividendenwerte |

Für Bitcoin wird pro abgeschlossenem UTC-Tag der letzte gültige Minutenschluss verwendet. S&P 500, MSCI World und Gold enthalten nur echte Handelstage beziehungsweise die gelieferten Monatsstichtage. Wochenenden, Börsenfeiertage und leere Platzhalter werden nicht als künstliche Datenbankzeilen gespeichert.

Die Multpl-Reihe ist keine Total-Return-Indexreihe. Sie enthält die
annualisierte 12-Monats-Dividendenrendite. Der Backtester verteilt diesen Satz
zeitanteilig zwischen echten S&P-Preisbeobachtungen und unterstellt sofortige
Wiederanlage. Schätzwerte bleiben in der Datenbank markiert und werden beim
monatlichen Neuabruf idempotent revidiert.

Beim MSCI World wird der Preisindex ebenfalls nicht verändert. Die vollständige
Jahresdividendenrendite wird am 31.12. reinvestiert. Für 2016 gilt der komplette
Wert von 2,69 % ab dem Startwert vom 31.07.2016.

Die Berechnung verwendet:

```text
Portfoliowert(t) = Startkapital × Wert(t) / Wert(Start)
CAGR = (Endwert / Startwert)^(1 / (Kalendertage / 365,2425)) - 1
Drawdown(t) = Wert(t) / bisheriges Hoch(t) - 1
Volatilität p.a. = Stichproben-Standardabweichung der echten Tagesrenditen × √Perioden pro Jahr
Sharpe (0 %) = mittlere Tagesrendite / Tagesvolatilität × √Perioden pro Jahr
Calmar = CAGR / |maximaler Drawdown|
```

Fehlt auf der gemeinsamen Kalenderachse ein neuer Wert, führt die API den letzten echten Wert nur für die Darstellung fort. Der Punkt erhält `isCarriedForward = true`, `qualityStatus = carried_forward` und das Datum der letzten echten Beobachtung. Die Datenbank bleibt unverändert. Volatilität und Tageskennzahlen verwenden nur echte aufeinanderfolgende Beobachtungen. S&P 500, MSCI World und Gold werden mit 252 Börsentagen, Bitcoin mit 365,2425 Kalendertagen annualisiert. Liegt am oder vor dem gewählten Start kein Wert vor, meldet die API die betreffende Anlageklasse als nicht verfügbar.

Nach Angabe des Betreibers liegen für die verwendeten historischen S&P-500- und Gold-Daten schriftliche Rechte vor. Die Nachweise selbst gehören nicht in das öffentliche Repository oder den Webroot und müssen separat aufbewahrt werden. Für Gold müssen zusätzlich die GoldAPI-Tarif- und Nutzungsbedingungen geprüft werden.

## Aufbereitung der historischen Daten

### Bitcoin

`cron/backtester/prepare-btcusd-daily.php` verarbeitet die große Minuten-CSV zeilenweise. Das Programm:

1. interpretiert Unix-Zeitstempel als UTC,
2. wählt den letzten positiven Minutenschluss jedes vollständigen UTC-Tages,
3. rechnet BTC/USD mit dem historischen EZB-Kurs in BTC/EUR um,
4. übernimmt an EZB-Schließtagen den zuletzt veröffentlichten Wechselkurs,
5. schreibt Quellzeitstempel, Revision und verwendeten Wechselkurstag in die kleine Importdatei.

Die geprüfte Datei `var/import/bitcoin.csv` enthält 3.653 Datenzeilen. Sie wurde mit SHA-256 geprüft. Die große Rohdatei wird nicht auf den Webserver übertragen.

Beim kontrollierten Datenbankimport wurden 3.288 neue Zeilen eingefügt und 364 bereits vorhandene CoinGecko-Zeilen aktualisiert. CoinGecko übernimmt die laufenden BTC/EUR-Aktualisierungen ab dem 03.08.2026 und darf die vorbereitete Historie davor nicht überschreiben.

### S&P 500 und Gold

`cron/backtester/prepare-market-history.ps1` erzeugt die beiden EUR-Dateien reproduzierbar:

- Die FRED-S&P-500-Reihe wird auf positive, echte Beobachtungen reduziert.
- Die gelieferte Gold-Datei wurde gegen das offizielle GLD-Archiv geprüft.
- 130 nach dem 23.01.2026 fehlende GLD-Handelstage wurden aus demselben offiziellen Archiv ergänzt.
- Beide USD-Reihen werden für jeden Handelstag mit dem historischen EZB-Kurs in Euro umgerechnet.
- An 24 US-Handelstagen ohne neuen EZB-Kurs wird der zuletzt veröffentlichte Kurs verwendet.
- Beide fertigen Dateien besitzen dieselben 2.513 Handelstage.

Die kontrollierten Importe lieferten:

```text
S&P 500: 2.513 eingefügt, 0 aktualisiert, 0 unverändert
Gold:    2.513 eingefügt, 0 aktualisiert, 0 unverändert
```

### MSCI World

`scripts/prepare-msci-world-data.mjs` liest die beiden binären XLS-Exporte ohne
Tabellenprogramm, validiert Reihe, Währung, Datumsfolge und erwartete Grenzen und
schreibt drei CSV-Dateien. Dem XLS-Export mit 72 Monatswerten wird der manuelle
Startwert 31.07.2016 mit 1.716 USD vorangestellt; dazu kommen 1.042 Tageswerte.
Die Reihen überschneiden sich nicht. Die zusammengeführte EUR-Datei enthält
1.115 eindeutige, positive Beobachtungen vom 31.07.2016 bis 14.08.2026. Für den
Startwert wird der letzte verfügbare EZB-Kurs vom 29.07.2016 verwendet.

```text
msci_world_monthly.csv  E657F7338675DC889753EA990145A9ADC935B310CC742CFF1A2BB241EFD1A8F1
msci_world_daily.csv    2E2E42BE1839B76D402F33FBF9201F5079FD5F2C2E86B91CBD672EC626E56E11
msci_world.csv          82D6C4926DAC36AE3F6DA961BAD27B1487B4932265E80A0C0DB34694415A743F
```

Prüfsummen:

```text
bitcoin.csv  38CE146B00E944AAED915CA4A51DF3B658393C6BE3C86F701071846716C5F865
sp500.csv    732071D34AE7799685B89BA97F76D6DA8E6A72553BBC6E9BC3CF0745B21520E3
gold.csv     9156F735BB24D49F085A66E7E9F35828637A312CBF0CDD2EF01D56BFCA448FBD
```

## Datenbank

Die Migrationen wurden in phpMyAdmin in der ausgewählten MySQL-8.0-Datenbank ausgeführt:

1. `001_schema.sql`: Tabellen, Schlüssel und Grundstruktur
2. `002_seed_assets.sql`: Quellen, ursprüngliche Anlageklassen und Sync-Status
3. `003_indexes.sql`: zusätzliche Indizes für Asset-, Datums- und Sync-Abfragen
4. `004_bitcoin_composite_source.sql`: dokumentierte Bitcoin-Quellenkette und Methodenwechsel
5. `005_free_market_live_sources.sql`: FRED-SP500- und ursprüngliche GLD-Zuordnung
6. `006_gold_gap_2026_08.sql`: kontrollierte Ergänzung der Goldhistorie
7. `007_goldapi_forward_calibrated.sql`: GoldAPI-XAU/USD-Zuordnung für zukünftige Werte mit Kalibrierungsverhältnis
8. `008_multpl_sp500_dividend_yields.sql`: separate Monatswerte und eigener Hilfs-Sync-Status
9. `009_sp500_dividend_wording.sql`: aktualisierte Bezeichnungen der S&P-Dividendenrechnung
10. `010_msci_world.sql`: MSCI-World-Asset und Jahresdividendenrenditen 2016 bis 2030

Die anfängliche MySQL-Meldung `#1046 - Keine Datenbank ausgewählt` wurde gelöst, indem die Zieldatenbank vor dem Import ausgewählt wurde. Die Warnungen zur veralteten MySQL-Funktion `VALUES()` verhinderten den Seed-Import nicht; die Datensätze wurden erfolgreich angelegt.

Tabellen:

- `backtester_data_sources`: Anbieter, URLs, Lizenz- und Attributionstexte
- `backtester_assets`: Definition, Währung, Kalender und Providerzuordnung der Anlageklassen
- `backtester_observations`: echte Tagesbeobachtungen mit Revision und Quellhash
- `backtester_sync_state`: Cursor, Fälligkeit, Backoff und auslaufender Lock je Asset
- `backtester_sync_runs`: Laufstatistik und Fehlerübersicht

`ObservationRepository` schreibt idempotent. Derselbe Import kann erneut laufen: neue Werte werden eingefügt, geänderte Revisionen aktualisiert und unveränderte Werte gezählt.

## Backend und API

Das Backend ist in kleine PHP-Komponenten aufgeteilt:

```text
Provider -> ValidationService -> SyncService -> Repository -> MySQL
MySQL -> BacktestService / SummaryService -> JSON-API -> Browser
```

Öffentliche Endpunkte:

```text
GET  api/backtester/assets.php
GET  api/backtester/summary.php
GET  api/backtester/series.php
GET  api/backtester/status.php
POST api/backtester/sync-if-stale.php
```

Die API begrenzt Anlageklassen, Zeitraum und Startkapital. Sie akzeptiert nur bekannte Slugs und ISO-Daten, blockiert Zukunftsdaten und gibt Fehler in einem festen JSON-Format zurück. Stacktraces, Datenbankzugänge und API-Keys erscheinen nicht in öffentlichen Antworten.

## Provider und behobene Fehler

- Der CoinGecko-Demo-Key wird als reiner Schlüssel im Header `x-cg-demo-api-key` übertragen. Eine komplette Beispiel-URL als Key führte zuvor zu HTTP 401 und wurde ersetzt.
- Der MySQL-Fehler `SQLSTATE[HY093]: Invalid parameter number` im Sync-Repository wurde behoben.
- Der HTTP-Client unterscheidet Netzwerkfehler, HTTP 401 und andere Providerantworten, ohne Keys oder vollständige sensible URLs zu protokollieren.
- Der CoinGecko-Parser nimmt den letzten gelieferten Punkt je UTC-Tag.
- Der S&P-500-Adapter verwendet weiterhin die FRED-Reihe `SP500`; dadurch bleibt die Preisindexmethodik ohne Quellen- oder Proxywechsel erhalten.
- Der Gold-Adapter lädt tägliche `XAU/USD`-Werte über GoldAPI.io und sendet den Key ausschließlich als `x-access-token`-Header.
- Neue SP500- und XAU-Werte werden als USD-Rohwert gespeichert und mit dem datierten EZB-Referenzkurs in einen getrennten EUR-Wert umgerechnet.
- Die täglichen FRED- und GoldAPI-Abrufe sind auf Daten ab dem 03.08.2026 begrenzt; die vorhandene Goldhistorie bleibt beim GoldAPI-Wechsel unverändert.
- Retry-Backoff wächst exponentiell. Tagesreihen warten höchstens 24 Stunden,
  langsamere Reihen höchstens 48 Stunden.
- Auslaufende Datenbank-Locks verhindern parallele Schreibläufe und können nach Ablauf übernommen werden.

## Betrieb auf STRATO

Der Backtester liegt auf dem Server unter:

```text
/mnt/web118/d1/95/5221695/htdocs/smstark-projekt.de/dashboards/backtester
```

Der eingerichtete Zugang unterstützt SFTP und SSH. FileZilla überträgt die Website- und Importdateien; SSH startet Tests und Importprogramme.

STRATO stellt PHP 8.3 als CGI/FastCGI-Binary bereit. Diese Variante unterstützt `php -r` nicht. Außerdem müssen Skriptargumente ohne `-f` übergeben werden:

```bash
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=gold --from=2016-08-02 --to=2026-08-02
/usr/bin/php -q cron/backtester/import-prepared-csv.php --asset=msci_world --from=2016-07-31 --to=2026-08-14
```

Der Test-Runner wurde so angepasst, dass er nicht von der in diesem SAPI fehlenden Konstante `STDERR` abhängt. Die Kommandozeilenoptionen funktionieren auch dann, wenn PHP nicht als klassisches CLI-SAPI läuft.

## Tägliche Aktualisierung

Da der STRATO-Tarif keinen Hosting-Cronjob enthält, ruft ein externer Zeitplaner den HTTPS-Endpunkt auf:

```text
GET https://smstark-projekt.de/dashboards/backtester/cron/backtester/update-daily.php
X-Backtester-Cron-Token: [geheim]
```

Das Geheimnis steht im HTTP-Header, nicht in der URL. Request-Body und HTTP-Basic-Authentifizierung bleiben leer. Der Testlauf antwortete mit HTTP 200. Benachrichtigungen sind für den ersten Fehler, die Erholung nach einem Fehler, eine automatische Deaktivierung und das bevorstehende TLS-Zertifikatsende eingerichtet.

Der Seitenbesuch-Fallback sendet zusätzlich einen begrenzten same-origin-POST an `sync-if-stale.php`. Datenbank-Fälligkeiten, Locks und ein globales Zeitfenster verhindern parallele Abrufwellen. Der externe Zeitplaner bleibt der primäre Mechanismus. Nach Aktivierung der FRED- und GoldAPI-Keys aktualisiert derselbe Lauf Bitcoin, SP500 und XAU.

Der gleiche tägliche Lauf prüft den Multpl-Hilfsstatus. Die Website wird nur
einmal pro fälligem Monat abgerufen; es ist kein zusätzlicher externer Cronjob
nötig. Bei jedem Monatslauf werden die jüngsten drei Monate erneut geprüft.

## Sicherheit

- `config/backtester.php` enthält echte Zugangsdaten, wird nicht versioniert und darf nicht öffentlich ausgeliefert werden.
- `.htaccess` sperrt Verzeichnisauflistung sowie Konfiguration, Includes, Logs, SQL, Tests, Dokumentation und Importdateien.
- CSP, `nosniff`, Referrer-Policy und Permissions-Policy werden als HTTP-Header gesetzt.
- Cron-Geheimnisse werden timing-safe verglichen und bevorzugt aus einem eigenen Header gelesen.
- Logs entfernen Keys aus strukturierten Kontextdaten.
- API-Abfragen nutzen feste Parameter, vorbereitete SQL-Anweisungen und serverseitige Validierung.
- Die öffentliche Seite kontaktiert keinen Finanzanbieter direkt.

## Frontend

Die Oberfläche verwendet HTML, gekapseltes CSS, ES-Module und Chart.js 4.4.1 aus dem eigenen Asset-Ordner. Sie bietet:

- responsive Navigation und Tabellen
- Tastaturzustände und Live-Regionen für Meldungen
- textliche Chart-Zusammenfassungen für Screenreader
- Tooltips mit Kurs, Rendite, Portfoliowert und letztem echten Beobachtungstag
- logarithmische Y-Achsen für Einzel- und Vergleichscharts
- ausschließlich positive Chartwerte; Null, negative und ungültige Werte werden nicht gezeichnet
- deutsche Zahlen-, Prozent-, Währungs- und Datumsformate
- MSCI World als eigener Einzelverlauf sowie optional mit und ohne Dividenden im Gesamtvergleich
- Immobilien USA als eigener Einzelverlauf sowie optional mit und ohne Netto-Mietcashflow im Gesamtvergleich

Der sichtbare Text wurde mit dem Skill `NO AI SLOP` geprüft und gezielt gekürzt. Dabei wurden keine Layout-, Struktur- oder Verhaltensänderungen als Textarbeit ausgegeben.

## Verwendete Skills und Arbeitsbereiche

### Anforderungsanalyse

Die Ausgangsspezifikation wurde in konkrete Daten-, API-, Frontend-, Sicherheits- und Betriebsanforderungen zerlegt. Nicht freigegebene Funktionen blieben deaktiviert, statt Platzhalterberechnungen vorzutäuschen.

### Datenengineering

Millionen Minutenzeilen wurden speicherschonend auf tägliche Werte verdichtet. Mehrere Datenquellen wurden über Datum, Zeitzone, Währung, Revision und Prüfsumme nachvollziehbar verbunden.

### Finanzmethodik

Preisindex, ETF/ETC-Proxy, Spot-Referenz und normierter Portfoliowert wurden getrennt behandelt. Historische USD-Werte werden mit historischen EZB-Kursen umgerechnet; ein heutiger Wechselkurs wird nicht rückwirkend verwendet.

### PHP- und API-Entwicklung

Provideradapter, Domänenobjekte, Repositories, Services, Request-Validierung und JSON-Antworten bilden klar getrennte Verantwortlichkeiten.

### MySQL-Datenmodellierung

Das Schema unterstützt idempotente Upserts, Quellenrevisionen, schnelle Bereichsabfragen, Sync-Cursor, Fehlerstatistiken und konkurrierende Jobs mit auslaufenden Locks.

### Debugging

Behoben wurden unter anderem eine fehlende Datenbankauswahl, die STRATO-CGI-Abweichungen, ein PDO-Parameterfehler, ein falsch eingetragener API-Key und zu wenig aussagekräftige Providerfehler.

### Deployment und Betrieb

Die Anwendung wurde für klassisches Shared Hosting ohne Buildsystem und ohne eingebauten Cronjob eingerichtet. SFTP, SSH, phpMyAdmin und ein externer HTTPS-Zeitplaner bilden den Betriebsweg.

### Erweiterung vom 18.08.2026

Die US-Immobilienreihe verwendet die monatliche, saisonbereinigte FHFA-Reihe
`HPIPONM226S` über den offiziellen FRED-Endpunkt. Der tägliche 13-Uhr-Lauf prüft
die Reihe nach einem Erfolg erst wieder nach sieben Tagen und lädt dann einen
Revisionszeitraum. Gespeichert werden ausschließlich echte Monatsbeobachtungen. Der einstellbare
Netto-Mietcashflow wird auf das ursprüngliche Startkapital gerechnet, als Cash
gehalten und nicht reinvestiert. Er verändert den Preisindex nicht.

Der vorhandene Case-Shiller-Datensatz bleibt wegen der von FRED ausgewiesenen
Zustimmungspflicht unverbunden. Deutsche Immobilienpreise wurden über Eurostat
`prc_hpi_q` von 2016-Q1 bis 2026-Q1 recherchiert, aber nicht als Asset eingebaut.
Der MSCI-World-Abruf für `^990100-USD-STRD` ist technisch vorbereitet und bis
zur Klärung der Weitergaberechte deaktiviert.

### Testengineering

Deterministische Tests prüfen Berechnung, Forward-Fill, Wochenenden, Feiertage, Providerparser, CSV-Import, Request-Sicherheit, Backoff und Locks. Die JavaScript-Tests prüfen zusätzlich die positive logarithmische Chartachse und die deutsche Ausgabe der Risikokennzahlen einschließlich fehlender Werte.

### NO AI SLOP

Der Skill wurde ausschließlich redaktionell eingesetzt. Er entfernte rhetorische Fragen, abgehackte Überschriften, unnötige Wiederholungen und passive Formulierungen. Fachbegriffe, Zahlen und Einschränkungen blieben erhalten.

## Nachgewiesene Prüfungen

- STRATO-PHP-Testlauf: 22 bestanden, 0 fehlgeschlagen, 1 optionaler MySQL-Test übersprungen
- Lokaler JavaScript-Testlauf einschließlich Immobilien-CSV, MSCI-World-CSV, Dividendenplan und Charts: 18 bestanden, 0 fehlgeschlagen
- PHP-Syntaxprüfungen der geänderten Serverdateien ohne Fehler
- CoinGecko-Testbackfill mit 3 eingefügten Tageswerten erfolgreich
- längerer CoinGecko-Backfill mit 350 Einfügungen und 14 Aktualisierungen erfolgreich
- externer Cron-Testlauf mit HTTP 200 erfolgreich
- S&P-500-Import mit 2.513 Einfügungen erfolgreich
- Gold-Import mit 2.513 Einfügungen erfolgreich

Die neue FRED-/GoldAPI-/EZB-Anbindung besitzt zusätzliche deterministische Parser- und Umrechnungstests. Da lokal kein PHP-Binary verfügbar ist, muss der vollständige aktualisierte PHP-Testlauf nach dem Upload erneut über STRATO ausgeführt werden; er ist in `OFFENE-PUNKTE.md` noch nicht als erledigt markiert.

Der MySQL-Integrationstest für Upsert, Revision und Lock-Race bleibt ohne separate Testdatenbank bewusst übersprungen. Ein statischer oder automatisierter Test ersetzt außerdem keine vollständige Browser- und Mobilgeräteabnahme.
