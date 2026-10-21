-- Range, status and operational indexes kept in a separate migration so they
-- can be added online after the core tables exist.

CREATE INDEX idx_backtester_assets_active_sort
    ON backtester_assets (is_active, sort_order);

CREATE INDEX idx_backtester_observation_date
    ON backtester_observations (observation_date);

CREATE INDEX idx_backtester_observation_asset_range
    ON backtester_observations (asset_id, observation_date, eur_value);

CREATE INDEX idx_backtester_sync_state_due
    ON backtester_sync_state (next_check_at, lock_until);

CREATE INDEX idx_backtester_sync_runs_started
    ON backtester_sync_runs (started_at);

CREATE INDEX idx_backtester_sync_runs_status
    ON backtester_sync_runs (status, trigger_type, started_at);

