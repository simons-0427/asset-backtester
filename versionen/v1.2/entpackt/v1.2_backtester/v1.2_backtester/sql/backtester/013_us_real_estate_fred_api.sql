-- Switch the FHFA monthly series from the blocked FRED graph CSV host to the
-- official FRED API transport already used for the S&P 500.
UPDATE backtester_data_sources
SET provider_type = 'fred',
    display_name = 'U.S. Federal Housing Finance Agency via FRED',
    base_url = 'https://fred.stlouisfed.org/series/HPIPONM226S'
WHERE slug = 'fhfa-us-house-price-index';

UPDATE backtester_assets
SET provider_dataset = 'fred/series/observations: HPIPONM226S'
WHERE slug = 'us_real_estate';

UPDATE backtester_sync_state
SET lock_until = NULL,
    next_check_at = NULL,
    consecutive_failures = 0,
    last_error_at = NULL,
    last_error_code = NULL,
    last_error_message = NULL
WHERE asset_id = (
    SELECT id FROM backtester_assets WHERE slug = 'us_real_estate'
);
