# Assetklassen-Backtester v1.3

Der Backtester vergleicht 13 Anlageklassen und selbst zusammengestellte Portfolios. Marktübersicht, Einzelverläufe, Gesamtvergleich und Ergebnis verwenden dieselben Kursreihen. Eigene Portfolios starten mit 10.000 EUR.

## Inhalt

- `dashboards/backtester/`: deutsche Oberfläche, PHP-API, Datenimport, SQL-Migrationen, Kursdateien und Tests.
- `en/dashboards/backtester/`: englische Oberfläche mit derselben Berechnung.
- `dashboards/weltmarkt/`: gemeinsamer Kursdienst für die historischen Anzeigewährungen, einschließlich Konfigurationsvorlage und SQL-Schema.
- `assets/` und `images/favicons/`: gemeinsam verwendete Kopfzeile und Symbole.
- `datenschutz.html` und `en/privacy.html`: Datenschutzhinweise für Browser-Speicherung, Import, Export und Steuerannahmen.

Die Ordnerstruktur entspricht dem Website-Hauptverzeichnis. Den Inhalt dieses Ordners dort einordnen, nicht den gesamten Ordner unter `dashboards/backtester/` ablegen. Die Links zur übrigen Website setzen deren vorhandene Navigation voraus.

## Installation

1. PHP 8.1 oder neuer mit PDO MySQL, cURL, JSON und mbstring sowie MySQL 8 oder MariaDB 10.5 bereitstellen.
2. Eine eigene Datenbank anlegen. In dieser ausgewählten Datenbank die Migrationen aus `dashboards/backtester/sql/backtester/` in Dateireihenfolge anwenden. Die SQL-Dateien enthalten keine fest eingetragene Betreiber-Datenbank. Optionale Scraper nur bei vorhandenen Nutzungsrechten aktivieren.
3. `dashboards/backtester/config/backtester.example.php` als private Konfiguration kopieren, Zugangsdaten und gegebenenfalls API-Schlüssel setzen. Außerhalb des Webroots kann `BACKTESTER_CONFIG` auf die Datei zeigen. Die Variablen stehen zusätzlich in `.env.example`. Eine .env-Datei wird nicht automatisch eingelesen; die Variablen müssen dem PHP-Prozess bereitstehen.
4. Die kontrollierten CSV-Dateien aus `var/import/` nach der Anleitung in `docs/MARKET-CSV-IMPORT.md`, `docs/BITCOIN-CSV-IMPORT.md` und `docs/MSCI-WORLD-IMPORT.md` importieren. Laufende Aktualisierungen und Cronjobs sind in `docs/CRON.md` beschrieben.
5. Für andere Anzeigewährungen den mitgelieferten Weltmarkt-Kursdienst konfigurieren. Dazu dessen Konfigurationsvorlage kopieren, SQL-Schema anlegen und Kursbeobachtungen importieren. Der Backtester liest dort die EUR/USD-, GBP/USD-, USD/JPY- und USD/CNY-Historien. Ohne verfügbare Währungshistorie bleibt die betreffende Auswahl gesperrt; EUR funktioniert unabhängig davon.
6. Die deutsche Seite unter `/dashboards/backtester/` und die englische Seite unter `/en/dashboards/backtester/` öffnen. Die Anwendung benötigt eine gefüllte Datenbank; das Archiv enthält keinen Datenbank-Dump.

Produktive Konfigurationen, Passwörter, API-Schlüssel, Cron-Zugangstoken und Laufzeitprotokolle sind nicht enthalten. Eine installierte private Konfiguration nicht durch die Vorlage ersetzen. Die vorhandenen Schutzdateien für interne Verzeichnisse mit übertragen.

## Portfolios

Gewichte lassen sich manuell festlegen oder über „Ideale Gewichte suchen“ bestimmen. Neue Entwürfe und Vorlagen beginnen mit drei Regeln:

- Alternative Anlagen zusammen höchstens 30 %.
- Jede einzelne alternative Anlage höchstens 10 %.
- Maximaler historischer Drawdown höchstens 25 %.

Die Volatilitätsregel ist standardmäßig aus und bleibt hinzufügbar. Alle Regeln lassen sich entfernen und erneut hinzufügen. Gespeicherte Konfigurationen behalten ihre Regeln. Rebalancing ist zunächst ausgeschaltet. Die Optimierung darf standardmäßig alle 13 Anlageklassen verwenden. Als Ziele stehen CAGR, Sharpe, Calmar, Volatilität, maximaler Drawdown und Expected Shortfall zur Auswahl.

Je Position lassen sich ein Hebel von 1 bis 3 und zusätzliche jährliche Kosten einschalten. Die Positions-TER erscheint nur bei eingeschaltetem Hebel. Ihr Hilfetext erläutert die zusätzlichen Hebelkosten.

Bis zu fünf eigene Portfolios werden nach ausdrücklichem Speichern im Browser abgelegt. Fertiggestellte und gespeicherte Portfolios erscheinen in den Einzelverläufen. Im Gesamtvergleich werden sie unter Einstellungen ausgewählt und sind zunächst abgewählt. Import und Export verwenden JSON-Dateien. Einzelheiten stehen in [PORTFOLIOS-V1.3.md](dashboards/backtester/docs/PORTFOLIOS-V1.3.md).

## Ladeverhalten und Handyansicht

Die Marktübersicht startet vor den langen Kursverläufen. Sichtbare Charts und neue Bedienaktionen haben Vorrang. Die übrigen Verläufe und gespeicherten Portfolios werden automatisch vollständig nachgeladen. Der Ladehinweis steht unter Datenstand und Währung und verschwindet nach Abschluss. Während des Ladens ist der Punkt gelb. Fehlende Daten können erneut geladen werden.

Die Marktübersicht hat auf kleinen Bildschirmen eine schmale Namensspalte. Bei 320 Pixeln sind daneben zwei Renditespalten sichtbar. Weitere Zeiträume lassen sich seitlich erreichen. Einstellungen, Portfoliofelder und Steuerdetails passen sich dem verfügbaren Platz an.

## Steuermodell

Der Steuerschalter gilt für Anlageklassen, eigene Portfolios und die 60/40-Referenz. Im Chart wird für jeden Tag ein unabhängiger vollständiger Verkauf gerechnet. Die hypothetische Schlusssteuer wird nicht täglich vom investierten Vermögen abgezogen. Bei Portfolio-Umschichtungen werden tatsächliche modellierte Verkäufe mit FIFO-Anschaffungstranchen versteuert; diese Zahlungen mindern die Wiederanlage.

Einstellbar sind Steuerprofil je Anlageklasse, persönlicher Steuersatz, Pauschbetrag, getrennte Verlustverrechnung, Kirchensteuer und persönliche Soli-Annahme. Die Detailansicht zeigt Gewinn, Befreiungen, Freibeträge und Steuerbestandteile. Bei direktem Bitcoin-/Goldbesitz und direkten Immobilien gelten die modellierten Haltefristen. Fonds-Teilfreistellungen werden gesondert berücksichtigt.

Es handelt sich um ein Verkaufsmodell nach heutigen Regeln. Vorabpauschale, laufende Ausschüttungs- und Zinsbesteuerung, ausländische Quellensteuer, Steuerabkommen, AfA und historische Gesetzeswechsel werden nicht vollständig abgebildet. Die Allokationsregeln und die Gewichtssuche prüfen Werte vor persönlichen Steuern.

## Prüfung

Im Browserlogik-Testlauf bestanden 172 Tests. Geprüft wurden unter anderem Kalenderbewertung, Hebel, Kosten, Rebalancing, Risikogrenzen, FIFO-Steuern, Speicherung, Sprachgleichheit und die schnelle Kandidatenbewertung. PHP-/Datenbankbetrieb auf einem neuen Hosting muss mit dessen eigener Konfiguration geprüft werden.

`node --test dashboards/backtester/tests/js/*.test.mjs`

`node dashboards/backtester/tests/portfolio-release-check.mjs`

Die PHP-Tests liegen unter `dashboards/backtester/tests/backtester/`. Für die Laufzeitmessung: `node dashboards/backtester/tests/benchmarks/optimizer.mjs`. Sie vergleicht die aktuelle Suche mit der mitgelieferten früheren Bewertungsfunktion auf denselben CSV-Reihen und mit demselben Suchbudget. Messwerte stehen in [SOLVER-BENCHMARK-v1.3.json](dashboards/backtester/docs/SOLVER-BENCHMARK-v1.3.json).

Alle Dateien sind in `MANIFEST.sha256` verzeichnet. Die Datei selbst ist von ihrer Liste ausgenommen.
