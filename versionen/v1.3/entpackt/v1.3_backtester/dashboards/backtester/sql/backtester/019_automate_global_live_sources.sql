-- Enable live updates for the imported bond, commodity, money-market and inflation histories.
-- Existing observations stay untouched; only source routing and sync state change.
UPDATE backtester_data_sources
SET provider_type = CASE slug
        WHEN 'ishares-global-aggregate-eur-hedged' THEN 'yahoo_fund_scraper'
        WHEN 'ishares-global-aggregate-unhedged' THEN 'yahoo_fund_scraper'
        WHEN 'wisdomtree-broad-commodities' THEN 'yahoo_fund_scraper'
        WHEN 'ecb-euro-money-market-model' THEN 'ecb_compounded_index'
        WHEN 'nyfed-us-money-market-model' THEN 'nyfed_sofr_index_eur'
        WHEN 'eurostat-hicp-germany' THEN 'eurostat_hicp_api'
        WHEN 'eurostat-hicp-euro-area' THEN 'eurostat_hicp_api'
        WHEN 'bls-cpi-us' THEN 'bls_public_api'
        ELSE provider_type
    END,
    base_url = CASE slug
        WHEN 'ishares-global-aggregate-eur-hedged' THEN 'https://query1.finance.yahoo.com/v8/finance/chart/EUNA.DE'
        WHEN 'ishares-global-aggregate-unhedged' THEN 'https://query1.finance.yahoo.com/v8/finance/chart/AGGG.L'
        WHEN 'wisdomtree-broad-commodities' THEN 'https://query1.finance.yahoo.com/v8/finance/chart/AIGC.L'
        WHEN 'ecb-euro-money-market-model' THEN 'https://data.ecb.europa.eu/data/datasets/EST/EST.B.EU000A2QQF08.CI'
        WHEN 'nyfed-us-money-market-model' THEN 'https://markets.newyorkfed.org/read?productCode=50&eventCodes=525&format=csv'
        WHEN 'eurostat-hicp-germany' THEN 'https://ec.europa.eu/eurostat/databrowser/view/prc_hicp_minr/default/table'
        WHEN 'eurostat-hicp-euro-area' THEN 'https://ec.europa.eu/eurostat/databrowser/view/prc_hicp_minr/default/table'
        WHEN 'bls-cpi-us' THEN 'https://www.bls.gov/cpi/data.htm'
        ELSE base_url
    END
WHERE slug IN (
    'ishares-global-aggregate-eur-hedged',
    'ishares-global-aggregate-unhedged',
    'wisdomtree-broad-commodities',
    'ecb-euro-money-market-model',
    'nyfed-us-money-market-model',
    'eurostat-hicp-germany',
    'eurostat-hicp-euro-area',
    'bls-cpi-us'
);

UPDATE backtester_assets
SET provider_symbol = CASE slug
        WHEN 'euro_money_market' THEN 'EST.B.EU000A2QQF08.CI'
        WHEN 'us_money_market' THEN 'NYFED:SOFRAI'
        ELSE provider_symbol
    END,
    provider_dataset = CASE slug
        WHEN 'euro_money_market' THEN 'ECB official compounded EURSTR index, connected to imported EONIA/EURSTR history'
        WHEN 'us_money_market' THEN 'NY Fed official SOFR Index, unhedged EUR view, connected to imported EFFR/SOFR history'
        ELSE provider_dataset
    END,
    identifier_type = CASE slug
        WHEN 'euro_money_market' THEN 'ecb_series'
        WHEN 'us_money_market' THEN 'nyfed_series'
        ELSE identifier_type
    END,
    identifier_value = CASE slug
        WHEN 'euro_money_market' THEN 'EST.B.EU000A2QQF08.CI'
        WHEN 'us_money_market' THEN 'SOFRAI'
        ELSE identifier_value
    END
WHERE slug IN ('euro_money_market', 'us_money_market');

UPDATE backtester_sync_state ss
INNER JOIN backtester_assets a ON a.id = ss.asset_id
SET ss.next_check_at = NULL,
    ss.lock_token = NULL,
    ss.lock_until = NULL,
    ss.consecutive_failures = 0,
    ss.last_error_at = NULL,
    ss.last_error_code = NULL,
    ss.last_error_message = NULL
WHERE a.slug IN (
    'global_ig_bonds',
    'global_ig_bonds_unhedged',
    'commodities',
    'euro_money_market',
    'us_money_market',
    'inflation_de',
    'inflation_euro_area',
    'inflation_us'
);
