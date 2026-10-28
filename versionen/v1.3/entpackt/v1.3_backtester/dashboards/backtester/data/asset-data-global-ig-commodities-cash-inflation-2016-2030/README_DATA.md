# README_DATA

Research date: 2026-08-20

This package contains the research extract for bonds, commodities, money-market and inflation series.
It is research input for a private EUR asset-class backtester. It is **not**
wired into the live PHP providers by this work package.

Rebuild:

```
python scripts/collect_global_ig_commodities_cash_inflation.py
```

## Layout

- `raw/` untouched downloads
- `clean/` rich daily/monthly series
- `prepared_import/` six-column backtester CSVs
- `forecasts/forecasts_2026_2030.csv` labeled `forecast` or `scenario`
- `source_manifest.csv`
- `download_log.csv`
- `DATA_QUALITY_REPORT.md`

## Main series

| Series | Source | Start | End | Freq | CCY | Kind | Rows |
| --- | --- | --- | --- | --- | --- | --- | ---: |
| global_ig_bonds_eur_hedged | iShares Core Global Aggregate EUR Hedged Acc IE00BDBRDM35 | 2016-01-04 | 2026-08-20 | daily | EUR | total_return | 2705 |
| global_ig_bonds_unhedged_eur | iShares Core Global Aggregate USD Dist IE00B3F81409, EUR | 2016-01-04 | 2026-08-20 | daily | PTS | total_return | 2704 |
| broad_commodities_total_return_eur | WisdomTree AIGC.L + ECB FX | 2016-01-04 | 2026-08-20 | daily | PTS | adjusted_close_proxy | 2686 |
| broad_commodities_spot_reference | World Bank Pink Sheet Total Index | 2016-01-01 | 2026-07-01 | monthly | USD | observed | 127 |
| euro_money_market | Modeled EONIA then €STR ACT/360 | 2016-01-04 | 2026-08-19 | daily | PTS | modeled | 2721 |
| us_money_market_eur | Modeled EFFR then SOFR, unhedged EUR | 2016-01-04 | 2026-08-19 | daily | PTS | modeled | 2658 |
| sofr_index_official | NY Fed SOFR Index | 2018-04-02 | 2026-08-20 | daily | PTS | total_return_index | 2095 |
| inflation_germany | Eurostat HICP I25 TOTAL DE | 2016-01-31 | 2026-07-31 | monthly | PTS | observed | 127 |
| inflation_euro_area | Eurostat HICP I25 TOTAL EA | 2016-01-31 | 2026-07-31 | monthly | PTS | observed | 127 |
| inflation_us | BLS CPI-U CUUR0000SA0 | 2016-01-31 | 2026-07-31 | monthly | PTS | observed | 126 |

## Recommended import set (6)

1. `prepared_import/global_ig_bonds_eur_hedged.csv`; default global IG for a EUR investor, EUR-hedged, accumulating.
2. `prepared_import/broad_commodities_total_return_eur.csv`; unhedged EUR view of a BCOM-style TR ETC.
3. `prepared_import/euro_money_market.csv`; euro cash wealth index.
4. `prepared_import/us_money_market_eur.csv`; USD cash, unhedged in EUR.
5. `prepared_import/inflation_germany.csv`; default EUR purchasing-power deflator.
6. `prepared_import/global_ig_bonds_unhedged_eur.csv`; unhedged global IG in EUR, for FX-risk comparison.

Optional: `inflation_euro_area.csv`, `inflation_us.csv`.

Do not import Pink Sheet spots as the investable commodity asset.

## Method constraints that matter

1. A bond yield is not a total return. The IG main series is a total-return path with income reinvested.
2. Commodity spots are not futures total return. Roll and collateral live in the ETC/TR proxy, not in the Pink Sheet.
3. Do not add coupons or dividends on top of adjusted close / accumulating NAV.
4. Euro cash compounds official overnight rates ACT/360. US cash does the same with EFFR then SOFR.
5. Inflation indices are deflators: `real_growth = nominal_growth / inflation_index_growth`.
6. No history is invented before a fund or index start. No current FX is used for past USD values.

## Licence

Private research. See `DATA_QUALITY_REPORT.md` for redistribution limits.
No API keys are stored in this package.
