-- Updates only public S&P 500 wording. No market or dividend values are changed.

UPDATE backtester_assets
SET proxy_description = 'S&P-500-Preisindex in EUR einschließlich reinvestierter monatlicher Dividenden auf Basis der annualisierten Multpl-Dividendenrendite.',
    methodology_note = 'Der tägliche Preisindex kommt über FRED und wird mit historischen EZB-Kursen in EUR umgerechnet. Für jeden Kalendermonat wird die von Multpl veröffentlichte annualisierte S&P-500-Dividendenrendite zeitanteilig zwischen echten Preisbeobachtungen aufgezinst. Das ist eine geglättete Modellrechnung und keine offizielle Total-Return-Indexreihe. Als Schätzung markierte Multpl-Werte bleiben gekennzeichnet und können später revidiert werden.'
WHERE slug = 'sp500';
