# Aufbereitung von S&P 500 und Gold

Die gelieferten Dateien werden für einen internen historischen Probelauf auf
eine einheitliche EUR-Methodik gebracht:

- `SP500.csv` ist die FRED-formatierte Reihe `SP500`: täglicher S&P-500-
  Preisindexschluss, ohne Dividenden.
- `gold_prices_10y.csv` wurde anhand aller überlappenden Tage als
  `SPDR Gold Shares` (`GLD`, NYSE Arca, USD) identifiziert.
- Für Gold verwendet die fertige Reihe über den gesamten Zeitraum das
  offizielle historische GLD-Archiv. Dadurch werden die 130 fehlenden
  Handelstage nach dem 23.01.2026 ohne Quellenbruch ergänzt.
- Beide USD-Reihen werden je Handelstag durch den historischen EZB-Kurs
  `USD je EUR` geteilt. Fehlt an einem US-Handelstag ein neuer EZB-Kurs, wird
  der letzte vorher veröffentlichte Referenzkurs verwendet.
- Leere FRED-Platzhalter, Wochenenden und Börsenfeiertage werden nicht als
  künstliche Beobachtungen gespeichert.

## Lokale, reproduzierbare Erzeugung

Unter Windows aus dem Backtester-Verzeichnis:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File cron\backtester\prepare-market-history.ps1
```

Das Programm lädt das offizielle
[GLD Historical Archive](https://www.spdrgoldshares.com/usa/gld/) und die
[EZB-Reihe EXR.D.USD.EUR.SP00.A](https://data.ecb.europa.eu/data/datasets/EXR/EXR.D.USD.EUR.SP00.A),
prüft die gelieferte Gold-Datei gegen GLD und erzeugt:

- `var/import/sp500.csv`
- `var/import/gold.csv`

Die Rohdateien und das PowerShell-Skript müssen nicht auf den Webserver
hochgeladen werden. Für den später freigegebenen Datenbankimport werden nur
die beiden kleinen Dateien unter `var/import/` benötigt.

## Geprüftes Ergebnis vom 03.08.2026

- Zielzeitraum: 02.08.2016 bis 02.08.2026
- letzter echter US-Handelstag: 31.07.2026
- S&P-500-Beobachtungen: 2.513
- entfernte leere FRED-Nichtbeobachtungen: 96
- GLD-Beobachtungen: 2.513
- gegen das offizielle GLD-Archiv geprüfte überlappende Eingabezeilen: 2.383
- nach dem Ende der gelieferten Gold-Datei ergänzte GLD-Handelstage: 130
- US-Handelstage mit fortgeführtem vorherigem EZB-Kurs: 24
- SHA-256 `sp500.csv`:
  `732071D34AE7799685B89BA97F76D6DA8E6A72553BBC6E9BC3CF0745B21520E3`
- SHA-256 `gold.csv`:
  `9156F735BB24D49F085A66E7E9F35828637A312CBF0CDD2EF01D56BFCA448FBD`

## Rechte und laufende Fortschreibung

Nach Angabe des Betreibers liegen die schriftlichen Rechte für die verwendeten
historischen S&P-500- und GLD-Daten vor. Die vertraulichen Nachweise bleiben
außerhalb des Webroots und des Repositorys.

Ab dem 03.08.2026 setzt die Live-Anbindung die Reihen methodisch fort:

- `SP500` über die FRED-API, derselbe tägliche Preisindexschluss wie in der Historie
- `GLD` über Alpha Vantage `TIME_SERIES_DAILY`, unbereinigter Schluss
- Umrechnung beider USD-Werte mit `EXR.D.USD.EUR.SP00.A` der EZB
- fehlt am US-Handelstag ein neuer EZB-Wert, gilt der letzte zuvor
  veröffentlichte Referenzkurs

Die FRED-API-Bedingungen und die Alpha-Vantage-Bedingungen für Speicherung,
Attribution und öffentliche Darstellung müssen separat bestätigt werden. Die
technische Anbindung ersetzt diese Vertragsprüfung nicht.
