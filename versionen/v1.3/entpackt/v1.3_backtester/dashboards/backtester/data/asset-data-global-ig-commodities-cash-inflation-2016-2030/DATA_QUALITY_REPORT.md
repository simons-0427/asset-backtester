# DATA_QUALITY_REPORT

Research date: 2026-08-20
Generated at: 2026-08-20T17:33:47Z

## Downloads

- successful or copied: 32
- failed: 2

### Failed downloads

- `spgsci_methodology.pdf` — failed: HTTP 403 type=text/html bytes=2011 head='<!DOCTYPE html>\n\n<html>\n\n<head>\n\t<meta charset="utf-8" />\n\t<meta http-equiv="X-UA-Compatible" content="IE=edge" />\n\t<met' — https://www.spglobal.com/spdji/en/documents/methodologies/methodology-sp-gsci.pdf
- `wisdomtree_aigc.html` — failed: HTTP 403 type=text/html; charset=utf-8 bytes=5885 head='<!DOCTYPE html><html lang="en-US"><head><title>Just a moment...</title><meta http-equiv="Content-Type" content="text/htm' — https://www.wisdomtree.com/ie/products/commodities/wisdomtree-broad-commodities

## What is a published total-return index?

- Official compounded €STR index (`EST.B.EU000A2QQF08.CI`) and official SOFR Index are published total-return / compounded indices.
- `global_ig_bonds_eur_hedged` and `global_ig_bonds_unhedged_eur`: iShares Core Global Aggregate, ISIN IE00BDBRDM35 / IE00B3F81409.

## What is a fund/ETF proxy?

- `broad_commodities_total_return_eur`: WisdomTree Broad Commodities ETC, ISIN GB00B15KY989, AIGC.L, ECB FX, rebased 100.
- Invesco CMOD.L stored as comparison only.
- Vanguard Global Aggregate EUR Hedged Acc, ISIN IE00BG47KH54, VAGF.DE, comparison only.

## What is modeled?

- Euro money-market wealth index: EONIA until 2019-09-30, €STR from 2019-10-01, ACT/360.
- US money-market wealth index: EFFR until 2018-04-01, SOFR from 2018-04-02, ACT/360; EUR version uses historical ECB FX.
- Bond and commodity 2026-2030 expected-return paths that are not a direct published CMA are `scenario`.

## Gaps 2016 to today

- €STR live series starts 2019-10-01; earlier euro cash uses EONIA.
- SOFR and SOFR Index start 2018-04-02; earlier USD cash uses EFFR.
- World Bank CMO Table 1 has no 2028-2030 columns.
- FOMC SEP ends in 2028 plus a longer-run value; 2029-2030 cash and PCE years are scenarios.
- ECB/Eurosystem HICP projections end in 2028; 2029-2030 are scenarios around 2%.
- European Commission Germany HICP in this vintage ends in 2027.
- IMF WEO database download was blocked (HTTP 403); IMF values were not invented.
- Official Bloomberg / S&P commodity and aggregate index dumps: unavailable free.
- Daily OAS history: not found free.
- Livestock sub-index: World Bank has no separate livestock index; beverages/fertilizers stored instead.
- Precious-metals Pink Sheet group includes gold; it is not gold-ex.
- US CPI-U October 2025: unpublished (BLS lapse-in-appropriations footnote). 2026 months come from FRED CPIAUCNS as BLS distributor because the unregistered BLS API only returns 10 years.
- NY Fed SOFR Index CSV on this endpoint starts 2020-03-02; FRED SOFRINDEX extends the official series back to 2018-04-02.

## Automated update endpoints

| Series | Endpoint | Interval | Revision lookback |
| --- | --- | --- | --- |
| €STR / compounded €STR / DFR / FX | `https://data-api.ecb.europa.eu/service/data/{flow}/{key}?format=csvdata` | business daily | 5-10 business days |
| EONIA historical | ECB `EON/D.EONIA_TO.RATE` | static after 2021-12-31 | none |
| SOFR / SOFR Index / EFFR | `https://markets.newyorkfed.org/read?productCode=50&eventCodes={520,525,500}&format=csv` | business daily | NY Fed can revise the prior session |
| HICP DE/EA | Eurostat `prc_hicp_minr` `coicop18=TOTAL` `unit=I25` | monthly | 2-3 months |
| US CPI-U | BLS Public API `CUUR0000SA0` | monthly | 2 months (seasonal-factor revisions do not apply to NSA, but late reports happen) |
| ETF proxies | Yahoo chart `v8/finance/chart/{EUNA.DE,AGGG.L,AIGC.L}` | daily | prices can restamp; prefer issuer NAV if a free feed appears |
| Pink Sheet | World Bank CMO monthly xlsx | monthly | current year revisions |

## Licence / redistribution

- Eurostat, ECB, BLS, World Bank, FOMC, NY Fed: official statistics / public research, attribution and their terms.
- NY Fed SOFR/EFFR: additional terms of use; do not present as a NY Fed product.
- Yahoo ETF/ETC adjusted closes: private research use. Not a substitute for iShares/WisdomTree NAV files or Bloomberg/S&P licences if you publish an index product.
- Do not republish Bloomberg Global Aggregate or S&P GSCI level dumps from this package; they are not in it.

## Checks

- ecb_usd_per_eur: n=2722 2016-01-04..2026-08-20 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>5%)=0
- global_ig_bonds_eur_hedged: n=2705 2016-01-04..2026-08-20 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>5%)=0
- global_ig_bonds_unhedged_eur: n=2704 2016-01-04..2026-08-20 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>6%)=0
- vagf_comparison: n=1824 2019-06-18..2026-08-20 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>5%)=0
- broad_commodities_tr_eur: n=2686 2016-01-04..2026-08-20 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>12%)=0
- pink_sheet_total: n=127 2016-01-01..2026-07-01 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>25%)=1
- euro_money_market: n=2721 2016-01-04..2026-08-19 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>1%)=0
- us_money_market_eur: n=2658 2016-01-04..2026-08-19 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>3%)=1
- sofr_index: n=2095 2018-04-02..2026-08-20 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>1%)=0
- estr: n=1763 2019-10-01..2026-08-19 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>2%)=53
- SOFR modeled vs official index: n=2094 median_abs_rel=2.270191e-09 p99=4.749405e-09 max=4.871888e-09
- EONIA ECB vs FRED overlap n=1535 median_abs_diff=0.000000 max=0.000000
- inflation_germany: n=127 2016-01-31..2026-07-31 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>3%)=0
- inflation_euro_area: n=127 2016-01-31..2026-07-31 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>3%)=0
- inflation_us: n=126 2016-01-31..2026-07-31 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>3%)=0
- US CPI BLS vs FRED CPIAUCNS n=126 median_abs_rel=0.000000e+00 max=0.000000e+00
- €STR source samples matched 1/1
- SOFR source samples matched 1/1
- FX conversion identities failed=0 of 5
- FX conversion identities failed=0 of 5
- prepared global_ig_bonds_eur_hedged.csv: n=2705 2016-01-04..2026-08-20 ccy=['EUR']
- prepared global_ig_bonds_unhedged_eur.csv: n=2704 2016-01-04..2026-08-20 ccy=['PTS']
- prepared broad_commodities_total_return_eur.csv: n=2686 2016-01-04..2026-08-20 ccy=['PTS']
- prepared euro_money_market.csv: n=2721 2016-01-04..2026-08-19 ccy=['PTS']
- prepared us_money_market_eur.csv: n=2658 2016-01-04..2026-08-19 ccy=['PTS']
- prepared inflation_germany.csv: n=127 2016-01-31..2026-07-31 ccy=['PTS']
- prepared inflation_euro_area.csv: n=127 2016-01-31..2026-07-31 ccy=['PTS']
- prepared inflation_us.csv: n=126 2016-01-31..2026-07-31 ccy=['PTS']

## Findings

- Download failed for spgsci_methodology.pdf: HTTP 403 type=text/html bytes=2011 head='<!DOCTYPE html>\n\n<html>\n\n<head>\n\t<meta charset="utf-8" />\n\t<meta http-equiv="X-UA-Compatible" content="IE=edge" />\n\t<met'
- Download failed for wisdomtree_aigc.html: HTTP 403 type=text/html; charset=utf-8 bytes=5885 head='<!DOCTYPE html><html lang="en-US"><head><title>Just a moment...</title><meta http-equiv="Content-Type" content="text/htm'
- iShares product HTML is an app shell; YTM/duration/coupon/TER taken from the live product pages as displayed during research (AGGH 2026-08-12, AGGG 2026-08-18), not from a scraped daily history.
- NY Fed SOFR Index CSV starts 2020-03-02; FRED SOFRINDEX (NY Fed primary, FRED distributor) starts 2018-04-02 and is used as the official control series.
- US CPI-U October 2025 has no published index. Gap left open.

## Rules enforced

- Historical import files contain no forecast rows.
- Missing days were not linearly interpolated.
- Yields were not treated as total return.
- Pink Sheet spots were not treated as futures total return.
- Accumulating / adjusted-close series were not topped up with extra coupons.
- USD series were converted with same-day or last previous ECB USD/EUR, never today's spot.
- Proxies and modeled series are `quality_status=flagged` and carry `data_kind` / `proxy_for`.

## File inventory

- `DATA_QUALITY_REPORT.md`
- `README_DATA.md`
- `clean/broad_commodities_invesco_comparison_usd.csv`
- `clean/broad_commodities_spot_reference_monthly.csv`
- `clean/broad_commodities_total_return_eur.csv`
- `clean/broad_commodities_total_return_usd.csv`
- `clean/ecb_deposit_facility_rate.csv`
- `clean/ecb_usd_per_eur_daily.csv`
- `clean/effr_daily.csv`
- `clean/eonia_daily.csv`
- `clean/estr_compounded_index_official.csv`
- `clean/estr_daily.csv`
- `clean/euribor_3m_monthly.csv`
- `clean/euro_money_market_total_return_daily.csv`
- `clean/global_ig_bond_characteristics_snapshot.csv`
- `clean/global_ig_bonds_eur_hedged.csv`
- `clean/global_ig_bonds_eur_hedged_vanguard_comparison.csv`
- `clean/global_ig_bonds_unhedged_eur.csv`
- `clean/global_ig_bonds_unhedged_usd.csv`
- `clean/inflation_euro_area_hicp_monthly.csv`
- `clean/inflation_germany_hicp_monthly.csv`
- `clean/inflation_us_cpi_u_monthly.csv`
- `clean/sofr_daily.csv`
- `clean/sofr_index_official_daily.csv`
- `clean/us_3m_tbill_daily.csv`
- `clean/us_money_market_total_return_eur_daily.csv`
- `clean/us_money_market_total_return_usd_daily.csv`
- `download_log.csv`
- `forecasts/forecasts_2026_2030.csv`
- `notes/global_aggregate_index_UNAVAILABLE.txt`
- `notes/money_market_methodology.txt`
- `notes/real_return_formula.txt`
- `notes/sp_gsci_djci_UNAVAILABLE.txt`
- `prepared_import/broad_commodities_total_return_eur.csv`
- `prepared_import/euro_money_market.csv`
- `prepared_import/global_ig_bonds_eur_hedged.csv`
- `prepared_import/global_ig_bonds_unhedged_eur.csv`
- `prepared_import/inflation_euro_area.csv`
- `prepared_import/inflation_germany.csv`
- `prepared_import/inflation_us.csv`
- `prepared_import/us_money_market_eur.csv`
- `raw/bls_cpiu.json`
- `raw/bls_cpiu_recent.json`
- `raw/ec_forecast_germany.html`
- `raw/ecb_dfr.csv`
- `raw/ecb_eonia_monthly.csv`
- `raw/ecb_eonia_to.csv`
- `raw/ecb_estr.csv`
- `raw/ecb_estr_compounded_index.csv`
- `raw/ecb_euribor3m_monthly.csv`
- `raw/ecb_projections_202606.html`
- `raw/ecb_usd_eur_daily.csv`
- `raw/eurostat_hicp_de.json`
- `raw/eurostat_hicp_ea.json`
- `raw/fomc_sep_20260617.html`
- `raw/fred_cpiaucns.json`
- `raw/fred_dtb3.json`
- `raw/fred_eonia.json`
- `raw/fred_sofrindex.json`
- `raw/ishares_aggg.html`
- `raw/ishares_aggh.html`
- `raw/nyfed_effr.csv`
- `raw/nyfed_sofr.csv`
- `raw/nyfed_sofr_index.csv`
- `raw/spf_q2_2026.html`
- `raw/wb_cmo_april_2026_forecasts.pdf`
- `raw/wb_cmo_monthly.xlsx`
- `raw/yahoo_aggg_l.json`
- `raw/yahoo_aggh_as.json`
- `raw/yahoo_aigc_l.json`
- `raw/yahoo_cmod_l.json`
- `raw/yahoo_euna_de.json`
- `raw/yahoo_vagf_de.json`
- `source_manifest.csv`
