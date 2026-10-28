# Portfolios in v1.3

## Zusammenstellen und Auswerten

Der Portfolioeditor steht unter dem Ergebnis. Manuelle Zielgewichte ergeben zusammen 100 %. Alternativ sucht die Optimierung Gewichte anhand des gewählten Ziels und der aktiven Regeln. Als Ziele sind CAGR, Sharpe, Calmar, Volatilität, Drawdown und Expected Shortfall verfügbar. Neue Entwürfe erlauben alle Anlageklassen für die Suche; ein ausdrücklich gespeichertes Anlageuniversum bleibt erhalten.

Jedes Portfolio startet mit 10.000 EUR. Der Zeitraum folgt dem Gesamtvergleich und wird auf die tatsächlich gemeinsame Historie begrenzt. Es gibt keine eigenen Start- oder Enddatumsfelder. Rebalancing ist zunächst aus; monatliche, quartalsweise, jährliche und durch Gewichtsband ausgelöste Umschichtungen sind auswählbar.

Neue Entwürfe und Vorlagen begrenzen alternative Anlagen zusammen auf 30 %, jede einzelne alternative Anlage auf 10 % und den maximalen Drawdown auf absolut 25 %. Die Volatilitätsregel bleibt zuschaltbar. Ihr automatisch vorgeschlagener Wert wird aus der 60/40-Referenz für den aktuellen Zeitraum und das gewählte Risikoraster berechnet. Eine eigene Volatilitätseingabe ist ein tatsächlicher Prozentwert pro Jahr. Bestehende Portfolios behalten ihre ausdrücklich gespeicherten Regeln.

Zusätzliche Grenzen betreffen Expected Shortfall 95 %, Risikobeitrag, Tracking Error und Turnover. Alle Regeln können entfernt werden. Ein nicht berechenbarer Wert kann keine eingeschaltete Grenze bestehen. Alternative Anlagen werden nach Marktexposition einschließlich Positionshebel begrenzt. Marktbewegungen können zwischen Umschichtungen zu Überschreitungen führen. Verhindert ein Turnover-Limit die erforderliche Korrektur beim Rebalancing, wird die Allokation abgelehnt.

Der Hebel wird je Position eingeschaltet. Ohne Hebel steht die zusätzliche Positions-TER nicht im Editor. Ausschalten setzt den Hebel auf 1 und diese Zusatzkosten auf 0. Die TER-Einstellungen der einzelnen Anlageklassen in ihren eigenen Verläufen bleiben davon getrennt.

## Kurse und Risikomessung

Die Bewertung erfolgt auf einem täglichen Kalender. Eine Anlageklasse ändert ihren Kurs nur an tatsächlich beobachteten Tagen; dazwischen gilt ihr letzter bekannter Kurs. Kosten laufen zeitanteilig weiter. Es werden weder frühere Kurse aus zukünftigen Beobachtungen ergänzt noch Zwischenkurse interpoliert.

Das Risikoraster steuert die Renditebeobachtungen für Volatilität, Sharpe, Risikobeiträge und Expected Shortfall. Das automatische Raster berücksichtigt langsamere Reihen. Drawdown und historische Stressfenster verwenden die Tageskurve. Expected Shortfall benötigt mindestens 40 Renditebeobachtungen. Bei Nullvarianz ist ein prozentualer Risikobeitrag nicht definiert.

Die Referenz besteht aus 60 % MSCI World und 40 % EUR-gesicherten globalen IG-Anleihen mit quartalsweisem Rebalancing, ohne persönliche Kosten oder Hebel. Portfolio-Renditen verwenden die API-Reihen einschließlich gekennzeichneter Ausschüttungsmodelle. Immobilien verwenden im Portfolio den Preisindex ohne zusätzlichen Mietcashflow.

## Gewichtssuche

Die Suche prüft die bestehende Mischung, die Referenz, Einzelpositionen, gleichmäßige Mischungen, paarweise Kombinationen und 500 weitere deterministische Suchschritte. Alle aktiven Regeln gelten weiterhin. Die Zahl der Schritte wurde bei der Beschleunigung nicht verringert.

Kursfaktoren, Zeitabstände, Kosten und Rebalancing-Termine werden einmal vorbereitet. Auch die 60/40-Referenz wird einmal berechnet. Für einzelne Gewichtsvorschläge werden zunächst nur Zahlenreihen und Kennzahlen ausgewertet. Chartobjekte entstehen erst für das endgültige Ergebnis. Identische Gewichtungen werden wiederverwendet. Abschließend wird der Gewinner mit der vollständigen Simulation und sämtlichen Regeln erneut geprüft.

Vier lokale Vergleiche mit Zehnjahres-CSV-Reihen lieferten identische Gewichte und Zielwerte. Die reine Suche sank je nach Szenario von 7,8 bis 13,2 Sekunden auf 0,15 bis 0,61 Sekunden. Kursabrufe sind darin nicht enthalten. Die Suche ist begrenzt und beweist weder ein globales Optimum noch die Unlösbarkeit einer Regelkombination. Historische Optimierung verwendet den ausgewählten Zeitraum rückblickend. Walk-forward gehört nicht zur Anwendung.

## Darstellung und Speicherung

Fertiggestellte und gespeicherte Portfolios erscheinen in den Einzelverläufen. Im Gesamtvergleich sind sie unter Einstellungen einzeln auswählbar und zunächst ausgeblendet. Die Ergebnisüberschriften sortieren jeweils nach einer Kennzahl. Der Gesamtvergleich besitzt keine eigene Sortierung.

Bis zu fünf Konfigurationen werden erst nach ausdrücklichem Speichern unter dem Schlüssel `sms.asset-backtester.portfolios.v1` abgelegt. Deutsch und Englisch verwenden auf derselben Website denselben Speicher. Gewichte, Regeln, Hebel, Kosten und Berechnungsparameter werden gemeinsam geladen. JSON-Import und -Export laufen im Browser. Kursverläufe und Steuerbücher werden neu berechnet; sie sind kein dauerhaft gespeichertes Kursarchiv.

Bei aktivierten Steuern verwenden Portfolios und Referenz FIFO-Tranchen und eine gemeinsame Jahresrechnung je verglichener Alternative. Rebalancing-Verkäufe lösen tatsächliche modellierte Zahlungen aus. Die zusätzliche Steuer bei einer vollständigen Liquidation wird für jeden Charttag unabhängig berechnet. Sie verbraucht keinen Freibetrag für spätere Tage und führt nicht zu täglichen Abbuchungen. Einzelverlauf, Gesamtvergleich und Ergebnis verwenden denselben Nettopfad. Regeln und Gewichtssuche bleiben vor persönlichen Steuern.

Historische Stressfenster, rollierende Risiken, Drawdown, Periodenverteilungen und Umschichtungen bleiben auswertbar, sofern ausreichend gemeinsame Daten vorliegen. Die Steuerdetails nennen Annahmen und die nicht abgebildeten Bestandteile des Modells.
