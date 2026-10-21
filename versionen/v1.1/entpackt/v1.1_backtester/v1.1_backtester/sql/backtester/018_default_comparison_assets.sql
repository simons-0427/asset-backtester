-- Keep the stored default selection aligned with the comparison UI.
UPDATE backtester_assets
SET is_default_selected = CASE
    WHEN slug IN ('sp500', 'bitcoin', 'gold', 'us_real_estate', 'euro_money_market') THEN 1
    ELSE 0
END
WHERE slug IN (
    'sp500', 'bitcoin', 'gold', 'msci_world',
    'de_real_estate', 'us_real_estate', 'dax', 'eurostoxx50',
    'global_ig_bonds', 'global_ig_bonds_unhedged', 'commodities',
    'euro_money_market', 'us_money_market'
);
