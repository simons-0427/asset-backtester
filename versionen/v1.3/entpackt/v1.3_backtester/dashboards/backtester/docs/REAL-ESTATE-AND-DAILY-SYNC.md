# Immobilien USA, Netto-Mietcashflow und geplanter Datencheck

Stand: 18.08.2026

## Was produktiv verwendet wird

Für `Immobilien USA` wird der **FHFA Purchase Only House Price Index for the
United States**, saisonbereinigt und monatlich, verwendet:

- FRED-Reihe: `HPIPONM226S`
- Einheit: Index, Januar 1991 = 100
- Frequenz: monatlich
- Quelle: U.S. Federal Housing Finance Agency via FRED
- Nutzungshinweis: Public Domain, Quellenangabe erwünscht
- Daten: mehr als sechs Millionen Wiederverkäufe derselben Einfamilienhäuser

Quellseite:
https://fred.stlouisfed.org/series/HPIPONM226S

Der tägliche Serverjob fragt die Reihe nach einem erfolgreichen Abruf erst
nach sieben Tagen wieder ab. Das macht die Rohdaten **nicht täglich**:
Ein neuer echter Datenpunkt wird nur veröffentlicht, wenn die FHFA einen neuen
Monatswert liefert. Dazwischen führt der Backtester den letzten Monatswert nur
für die gemeinsame Kalenderachse fort. Der Abruf lädt einen begrenzten
Revisionszeitraum erneut, damit spätere FHFA-Korrekturen als Revision gespeichert
werden.

Der vorhandene Case-Shiller-Datensatz wird nicht angeschlossen. FRED kennzeichnet
`CSUSHPISA` als `Copyrighted: Pre-Approval Required`. Eine öffentliche Anzeige
würde daher vorher eine Freigabe von S&P Dow Jones Indices erfordern.

## Netto-Mietcashflow

Die Eingabe ist ein jährlicher Prozentsatz nach laufenden Objektkosten. Vorgabe:
`5,0 % p.a.`. Die Rechnung bleibt bewusst einfach:

```text
Immobilienwert(t)
= Startkapital × Preisindex(t) / Preisindex(Start)
+ Startkapital × Netto-Mietcashflow p.a. × Kalendertage / 365,2425
```

Der Cashflow wird als Cash gehalten und nicht reinvestiert. Er verändert den
FHFA-Preisindex nicht. Finanzierung, Kauf- und Verkaufskosten sowie persönliche
Steuern werden nicht zusätzlich berechnet; sie müssen bei der eigenen
Netto-Cashflow-Eingabe bereits berücksichtigt sein. Die Oberfläche verlinkt dazu
auf:

https://smstark-projekt.de/projekte/immobilienkalkulation

## MSCI World: technisch vorbereitet, standardmäßig gesperrt

Der technische Abruf verwendet ausschließlich den exakten USD-Preisindex:

- MSCI-Indexcode: `990100`
- Yahoo-Symbol: `^990100-USD-STRD`
- keine Total-Return- oder Gross-Return-Variante
- nur kurzer aktueller Überlappungszeitraum, kein neuer historischer Backfill
- EUR-Umrechnung mit dem letzten verfügbaren EZB-Referenzkurs

Der Abruf ist in `config/backtester.php` standardmäßig deaktiviert. MSCI erklärt
auf seiner öffentlichen Indexseite, dass eine Weitergabe der Indexdaten ohne
vorherige schriftliche Zustimmung unzulässig ist. Außerdem ist der Yahoo-Chart-
Endpunkt nicht als stabile öffentliche API zugesichert. Erst nach Klärung der
Rechte werden die Umgebungsvariable
`BACKTESTER_MSCI_WORLD_SCRAPER_ENABLED=true` und die optionale Migration
`012_enable_msci_world_scraper.sql` aktiviert.

## Deutschland: recherchiert, nicht eingebaut

Für Deutschland ist die offizielle Eurostat-Reihe `prc_hpi_q` vorbereitet:

- Gebiet: `DE`
- Käufe: `TOTAL`
- Einheit: `I15_Q`, Index 2015 = 100
- Frequenz: quartalsweise
- Zeitraum der vorbereiteten Datei: 2016-Q1 bis 2026-Q1
- letzter Wert: 2026-Q1 = 153,40, vorläufig

Datei: `docs/research/germany-house-price-index-2016-now.csv`

Offizieller maschinenlesbarer Abruf:
https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hpi_q?geo=DE&purchase=TOTAL&unit=I15_Q&sinceTimePeriod=2016-Q1

Destatis meldet für das 1. Quartal 2026 zusätzlich +1,4 % gegenüber dem
Vorjahresquartal und +0,3 % gegenüber dem Vorquartal. Die Deutschland-Reihe ist
absichtlich weder als Asset registriert noch im Frontend sichtbar.

## Täglicher Lauf um 13:00 Uhr

Ein einziger Aufruf aktualisiert die fälligen Preisreihen, Immobilien USA und den
separaten Dividendenstrom:

```bash
/usr/bin/php -q cron/backtester/update-daily.php
```

Der externe Scheduler muss auf `13:00 Europe/Berlin` eingestellt sein. Wenn der
Scheduler nur UTC unterstützt, entspricht das 11:00 UTC während der Sommerzeit
und 12:00 UTC während der Winterzeit. Eine feste UTC-Uhrzeit würde den Lauf nach
der Zeitumstellung verschieben.
