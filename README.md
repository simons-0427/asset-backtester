# Assetklassen-Backtester

Vergleich von 13 Anlageklassen und eigenen Portfolios auf derselben Kursbasis. Das Dashboard zeigt Marktübersicht, Einzelverläufe, Gesamtvergleich sowie Rendite- und Risikokennzahlen auf Deutsch und Englisch.

Version 1.3 enthält den Portfolioeditor, regelgebundene Gewichtssuche, Positionshebel, Steuermodelle und lokale Speicherung. Neue Portfolios starten mit 10.000 EUR und den Grenzen 30 % Alternatives insgesamt, 10 % je alternative Position und 25 % maximaler Drawdown. Volatilität bleibt zuschaltbar. Gespeicherte Portfolios behalten ihre Einstellungen.

[v1.3 herunterladen](versionen/v1.3/v1.3_backtester.zip) | [Installation und Bedienung](versionen/v1.3/entpackt/v1.3_backtester/README.md) | [Änderungen](versionen/v1.3/entpackt/v1.3_backtester/dashboards/backtester/docs/RELEASE-v1.3.md)

Das vollständige Paket enthält Frontend, PHP-Backend, Migrationen, Importdaten, Datenbeschaffung und Tests. Die Ordner folgen der Website-Struktur. Zugangsdaten sind durch Vorlagen ersetzt. Konfiguration und Datenbank müssen auf dem eigenen Server eingerichtet werden. 172 Browserlogik-Tests bestanden; der PHP-/Datenbankbetrieb auf einem neuen Hosting ist gesondert zu prüfen.

Die Gewichtssuche bereitet gemeinsame Daten einmal vor und prüft den Gewinner abschließend mit der vollständigen Simulation. Vier lokale Zehnjahresvergleiche lieferten dieselben Gewichte und Zielwerte wie zuvor. Im Standardfall mit 13 Anlageklassen sank die reine Suchzeit von rund 10,1 Sekunden auf 0,15 Sekunden. Kursabrufe sind darin nicht enthalten.

## Versionen

| Version | Archiv | Quellcode |
| --- | --- | --- |
| v1.3 | [ZIP](versionen/v1.3/v1.3_backtester.zip) | [Dateien](versionen/v1.3/entpackt/v1.3_backtester/) |
| v1.2 | [ZIP](versionen/v1.2/v1.2_backtester.zip) | [Dateien](versionen/v1.2/entpackt/v1.2_backtester/) |
| v1.1 | [ZIP](versionen/v1.1/v1.1_backtester.zip) | [Dateien](versionen/v1.1/entpackt/v1.1_backtester/) |
| v1.0 | [ZIP](versionen/v1.0/v1.0_backtester.zip) | [Dateien](versionen/v1.0/entpackt/v1.0_backtester/) |

Die älteren Versionsordner bleiben erhalten. SHA-256-Prüfsummen liegen neben dem aktuellen Archiv und als Dateimanifest im Paket.

[Projektseite](https://smstark-projekt.de/projekte/backtester) | [Website](https://smstark-projekt.de/dashboards/backtester/)
