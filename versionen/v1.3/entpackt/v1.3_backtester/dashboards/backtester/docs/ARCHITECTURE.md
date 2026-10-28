# Architektur des Assetklassen-Backtesters

## Abgrenzung der V1

Die produktive V1 unterstützt ausschließlich S&P 500, Bitcoin in Euro und Gold in Euro. DAX, MSCI World, Emerging Markets, Immobilien, Inflation, Kosten, Steuern, Sparpläne und Rebalancing sind weder in der API noch als scheinbar aktive Schalter vorhanden. Datenmodell, Asset-Seed und Provider-Registry bleiben für spätere Reihen erweiterbar.

## Datenfluss

```text
Externer Provider
  -> Provider-Adapter (USD-Marktdaten werden mit datiertem EZB-Kurs in EUR umgerechnet)
  -> ValidationService
  -> SyncService mit DB-Lock
  -> backtester_observations (nur echte Beobachtungen)
  -> BacktestService / SummaryService
  -> same-origin JSON-API
  -> Vanilla-JavaScript und lokale Chart.js-Datei
```

Die Multpl-Dividendenrendite läuft als separater Hilfsdatenstrom:

```text
Multpl-HTML-Monatstabelle
  -> MultplDividendYieldProvider
  -> backtester_dividend_yields (eine Zeile je Asset und Monat)
  -> DividendAdjustment
  -> BacktestService / SummaryService
```

Die Browserseite ruft nie einen fremden Finanzanbieter auf. Schlüssel, Provider-Symbole und Rohantworten bleiben auf dem Server. Alte Daten werden bei einem Providerfehler nicht gelöscht.

## Erkennbare Website-Konventionen

- Farben: `#0c0c0f`, `#13131a`, `#16161f`, `#1e1e2e`, Mint `#6ee7b7`, Indigo `#818cf8`.
- Typografie: Syne für Marke und Überschriften, DM Sans für Bedienung und Text.
- Fixierte 68-px-Hauptnavigation, Hamburger-Menü unter 960 px und dreispaltiger Standardfooter.
- Cards mit 1-px-Rahmen, 8/16-px-Radien, wenig Schatten und funktionalen Statusfarben.
- Responsive Schwellen bei 1200, 960, 720/640 und 480 px; nur Datentabellen scrollen horizontal.

## Verantwortlichkeiten

- `Providers`: externe Antwortformate, FRED-SP500-Abruf, GoldAPI-XAU/USD-Abruf, historische EZB-Umrechnung und Normalisierung in `HistoricalObservation`.
- `MultplDividendYieldProvider`: eng begrenzter Parser für `table#datatable`; normalisiert Monatswerte und erkennt Schätzungen.
- `ValidationService`: Datum, positiver Wert, Währung, Duplikat und Ausreißerstatus.
- `ObservationRepository`: Bereichsabfragen und idempotenter MySQL-Upsert.
- `DividendYieldRepository`: monatlicher Upsert, Revisionsschutz und separater Hilfs-Sync-Status.
- `SyncRepository`: DB-Locks, Sync-State, Runs und Page-Visit-Rate-Limit.
- `BacktestService`: tägliche Vergleichsachse, Forward-Fill, Normierung, CAGR und Drawdown.
- `SummaryService`: 1W, 1M, 6M, 1Y, 3Y, 5Y und 10Y effizient aus Stichtagswerten.
- Frontend: Eingaben, URL-Zustand, Statusanzeigen, Tabelle und Charts; keine Providerlogik.

## Methodik

```text
Portfoliowert(t) = Startkapital * Wert(t) / Wert(Start)
S&P-Gesamtfaktor = Preisfaktor * zeitanteiliger Multpl-Dividendenfaktor
CAGR = (Endwert / Startwert)^(1 / (Kalendertage / 365,2425)) - 1
Drawdown(t) = Wert(t) / laufendes Hoch(t) - 1
```

Forward-Fill wird ausschließlich in der API-Antwort für die gemeinsame Kalenderachse erzeugt. Die Rohdatentabelle enthält keine künstlichen Wochenend- oder Feiertagszeilen. Einzelcharts zeigen Rohwerte, der Gesamtchart zeigt normierte Portfoliowerte.

Der S&P-Dividendenfaktor behandelt die annualisierte Monatsrendite als
Effektivsatz und verteilt sie über reale Kalendertage. Das ist eine geglättete
Modellrechnung mit unterstellter Wiederanlage, keine offizielle Total-Return-Reihe.
