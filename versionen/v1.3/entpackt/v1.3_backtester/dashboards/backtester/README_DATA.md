# README_DATA

Research date: 2026-08-20

This folder is the data package specified in `datencollecting.md`. It is a
research extract for a private asset-class backtester. It is **not** yet wired
into the live PHP providers.

## What you can use immediately

- `data/clean/` — UTF-8 CSVs, comma separated, decimal point, ISO dates
- `data/raw/` — untouched downloads plus `_download_log.csv` with SHA-256
- `data/forecasts/forecasts_2026_2030.csv` — labeled scenarios, never mixed into history
- `data/source_manifest.csv` — one row per published series
- `DATA_QUALITY_REPORT.md` — gaps, proxies, modeled series, cross-checks

Rebuild:

```
python scripts/collect_european_asset_data.py
python scripts/collect_european_asset_data.py --skip-download
```

The second form reuses `data/raw/` and only refreshes a few index files.

## Asset coverage

| Slug / file | History | Notes |
| --- | --- | --- |
| DAXK `dax_price_daily.csv` | daily EUR | Price index, ISIN DE0008467440 |
| DAX `dax_total_return_daily.csv` | daily EUR | Performance index, ISIN DE0008469008, dividends reinvested |
| DAX net-return index | not found free | Do not treat DAX as net-of-tax |
| EURO STOXX 50 price | daily EUR | Republished SX5E / ^STOXX50E levels, not a licensed STOXX dump |
| EURO STOXX 50 net return | ETF proxy or SX5T if present | See quality report |
| EURO STOXX 50 gross return | usually unavailable | File may be header-only |
| German house prices | quarterly | Eurostat `prc_hpi_q`, not daily |
| German rents | monthly HICP CP041 | Tenant rent index, not a market yield |
| German net rental yield | modeled | Absolute level is an assumption; path follows official P/R |
| Commodities | monthly USD and EUR | World Bank Pink Sheet + ECB FX |
| Bund yields | daily | Bundesbank Svensson + on-the-run + ECB AAA |
| Bund total return | modeled | Constant-maturity, marked `modeled` |

## Method notes that matter for the backtester

1. DAX already includes reinvested dividends. Do not add the derived dividend
   yield on top of the performance index.
2. DAXK plus the derived annual dividend factor is the split the spec asked for.
3. Commodity files are **spot or reference prices**, not excess-return or
   futures roll indices.
4. Bond yields are not returns. Use `bond_total_returns.csv` only with the
   modeled flag.
5. Forecasts live in a separate file and are scenarios.

## Licence / use

Private research. Republished index levels from Stooq/Yahoo are not a
substitute for a Deutsche Boerse or STOXX licence if you publish the index
as a product. Eurostat, ECB, Bundesbank and World Bank series require
attribution and keep their own terms.
