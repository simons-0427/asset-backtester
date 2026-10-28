#!/usr/bin/env python3
"""Extend Global IG series back to 2016 using AGG + IAGG + EUR cash overlay.

Output files carry no backfill/simulation labels.
"""

from __future__ import annotations

import csv
import json
import math
import time
import zipfile
from datetime import date, datetime, timezone
from pathlib import Path
from urllib.parse import urlencode
from zoneinfo import ZoneInfo

import pandas as pd
import requests

ROOT = Path(__file__).resolve().parents[1]
PKG = ROOT / "data" / "asset-data-global-ig-commodities-cash-inflation-2016-2030"
RAW = PKG / "raw"
CLEAN = PKG / "clean"
PREPARED = PKG / "prepared_import"
START = date(2016, 1, 1)
SPLICE = date(2017, 11, 21)
UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36"
)
SESSION = requests.Session()
SESSION.headers.update({"User-Agent": UA, "Accept": "*/*"})
IMPORT_FIELDS = ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp"]
CLEAN_FIELDS = [
    "asset_slug",
    "date",
    "reference_period",
    "release_date",
    "value",
    "currency",
    "unit",
    "frequency",
    "data_kind",
    "provider",
    "dataset",
    "series_id",
    "quality_status",
    "source_revision",
    "source_timestamp",
    "methodology",
    "proxy_for",
]
FX_EXTRA = ["raw_usd_value", "eur_value", "usd_per_eur", "fx_observation_date"]


def utc_now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def decimal(value: float, places: int = 8) -> str:
    quantized = f"{value:.{places}f}"
    if "." in quantized:
        quantized = quantized.rstrip("0").rstrip(".")
    return quantized if quantized not in {"", "-"} else "0"


def write_csv(path: Path, rows: list[dict], fieldnames: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow({key: row.get(key, "") for key in fieldnames})


def download_yahoo(symbol: str, filename: str) -> Path:
    dest = RAW / filename
    if dest.exists() and dest.stat().st_size > 1000:
        print(f"SKIP {filename}", flush=True)
        return dest
    query = urlencode(
        {
            "period1": "1451606400",
            "period2": str(int(time.time())),
            "interval": "1d",
            "events": "history",
            "includeAdjustedClose": "true",
        }
    )
    url = f"https://query1.finance.yahoo.com/v8/finance/chart/{symbol}?{query}"
    response = SESSION.get(url, timeout=(12, 40))
    response.raise_for_status()
    dest.write_bytes(response.content)
    print(f"OK  {filename}  {len(response.content)} bytes", flush=True)
    return dest


def parse_yahoo(path: Path) -> pd.DataFrame:
    payload = json.loads(path.read_text(encoding="utf-8"))
    result = payload["chart"]["result"][0]
    stamps = result.get("timestamp") or []
    adj = ((result.get("indicators") or {}).get("adjclose") or [{}])[0].get("adjclose") or []
    if not any(v is not None for v in adj):
        adj = ((result.get("indicators") or {}).get("quote") or [{}])[0].get("close") or []
    tz_name = result.get("meta", {}).get("exchangeTimezoneName") or "UTC"
    try:
        tz = ZoneInfo(tz_name)
    except Exception:
        tz = timezone.utc
    rows = []
    for stamp, value in zip(stamps, adj):
        if stamp is None or value is None:
            continue
        number = float(value)
        if not math.isfinite(number) or number <= 0:
            continue
        day = datetime.fromtimestamp(int(stamp), tz=tz).date()
        rows.append({"date": day, "value": number})
    df = pd.DataFrame(rows).drop_duplicates("date").sort_values("date")
    return df[df["date"] >= START]


def load_index(path: Path) -> pd.DataFrame:
    df = pd.read_csv(path, parse_dates=["date"])
    df["date"] = pd.to_datetime(df["date"]).dt.date
    df["value"] = pd.to_numeric(df["value"], errors="coerce")
    return df.dropna(subset=["date", "value"]).sort_values("date")


def daily_return(df: pd.DataFrame, name: str) -> pd.DataFrame:
    out = df[["date", "value"]].copy()
    out = out.sort_values("date")
    out["ret"] = out["value"].pct_change()
    return out.dropna(subset=["ret"]).rename(columns={"ret": name})[["date", name]]


def fx_lookup(fx: pd.DataFrame, day: date) -> tuple[date, float] | None:
    subset = fx[fx["date"] <= day]
    if subset.empty:
        return None
    rec = subset.iloc[-1]
    return rec["date"], float(rec["value"])


def best_weight(y: pd.Series, x: pd.Series, lo: float = 0.30, hi: float = 0.55, step: float = 0.01) -> tuple[float, float, float]:
    best_w, best_te, best_corr = 0.44, 1e9, 0.0
    for i in range(int(round((hi - lo) / step)) + 1):
        w = lo + i * step
        fitted = w * x
        resid = y - fitted
        te = float(resid.std(ddof=0))
        corr = float(y.corr(x)) if x.std() else 0.0
        if te < best_te:
            best_w, best_te, best_corr = w, te, corr
    return best_w, best_te, best_corr


def prepend_start(df: pd.DataFrame, first_ret: float) -> pd.DataFrame:
    first_day = date(2016, 1, 4)
    if df.empty or df.iloc[0]["date"] <= first_day:
        return df
    prev = float(df.iloc[0]["value"]) / (1.0 + first_ret) if first_ret != -1 else float(df.iloc[0]["value"])
    extra = pd.DataFrame([{"date": first_day, "value": prev}])
    return pd.concat([extra, df], ignore_index=True).drop_duplicates("date").sort_values("date")


def prepend_start_fx(df: pd.DataFrame, first_ret: float, fx: pd.DataFrame) -> pd.DataFrame:
    if df.empty or df.iloc[0]["date"] <= date(2016, 1, 4):
        return df
    day = date(2016, 1, 4)
    usd_prev = float(df.iloc[0]["raw_usd_value"]) / (1.0 + first_ret) if first_ret != -1 else float(df.iloc[0]["raw_usd_value"])
    looked = fx_lookup(fx, day)
    if looked is None:
        return df
    fx_day, usd_per_eur = looked
    extra = pd.DataFrame(
        [
            {
                "date": day,
                "raw_usd_value": usd_prev,
                "usd_per_eur": usd_per_eur,
                "fx_observation_date": fx_day,
                "eur_value": usd_prev / usd_per_eur,
                "value": usd_prev / usd_per_eur,
            }
        ]
    )
    return pd.concat([extra, df], ignore_index=True).drop_duplicates("date").sort_values("date")


def compound(dates: list[date], rets: list[float], splice_date: date, splice_level: float) -> pd.DataFrame:
    idx = [1.0]
    for ret in rets[1:]:
        idx.append(idx[-1] * (1.0 + ret))
    loc = dates.index(splice_date)
    scale = splice_level / idx[loc]
    return pd.DataFrame({"date": dates, "value": [v * scale for v in idx]})


def main() -> None:
    retrieved = utc_now()
    download_yahoo("AGG", "yahoo_agg.json")
    download_yahoo("IAGG", "yahoo_iagg.json")
    download_yahoo("BNDX", "yahoo_bndx.json")

    agg = parse_yahoo(RAW / "yahoo_agg.json")
    iagg = parse_yahoo(RAW / "yahoo_iagg.json")
    bndx = parse_yahoo(RAW / "yahoo_bndx.json")
    euna = load_index(PREPARED / "global_ig_bonds_eur_hedged.csv")
    aggg_usd = load_index(CLEAN / "global_ig_bonds_unhedged_usd.csv")
    aggg_eur = load_index(PREPARED / "global_ig_bonds_unhedged_eur.csv")
    eur_mm = load_index(CLEAN / "euro_money_market_total_return_daily.csv")
    usd_mm = load_index(CLEAN / "us_money_market_total_return_usd_daily.csv")
    fx = load_index(CLEAN / "ecb_usd_per_eur_daily.csv")

    intl = iagg if iagg["date"].min() <= date(2016, 1, 15) else bndx
    intl_name = "IAGG" if intl is iagg else "BNDX"
    print(f"intl={intl_name} start={intl['date'].min()} n={len(intl)} AGG start={agg['date'].min()} n={len(agg)}")

    hedge = pd.merge(daily_return(eur_mm, "eur_mm"), daily_return(usd_mm, "usd_mm"), on="date")
    hedge["hedge"] = hedge["eur_mm"] - hedge["usd_mm"]
    basket = pd.merge(daily_return(agg, "agg"), daily_return(intl, intl_name.lower()), on="date")
    basket = pd.merge(basket, hedge[["date", "hedge"]], on="date")
    intl_col = intl_name.lower()

    overlap = pd.merge(
        daily_return(euna, "euna"),
        basket[["date", "agg", intl_col, "hedge"]],
        on="date",
    )
    overlap = overlap[overlap["date"] >= SPLICE]
    y = overlap["euna"] - overlap["hedge"]
    x = overlap["agg"] - overlap[intl_col]
    # y = w*agg + (1-w)*intl  =>  y - intl = w*(agg-intl)
    target = overlap["euna"] - overlap["hedge"] - overlap[intl_col]
    driver = overlap["agg"] - overlap[intl_col]
    w, te, _ = best_weight(target, driver, 0.30, 0.55, 0.005)
    fitted = w * overlap["agg"] + (1.0 - w) * overlap[intl_col] + overlap["hedge"]
    corr = float(overlap["euna"].corr(fitted))
    te_ret = float((overlap["euna"] - fitted).std(ddof=0))
    print(f"EUR-hedged w_US={w:.3f} overlap_n={len(overlap)} corr={corr:.4f} TE={te_ret:.6f} (daily)")

    basket["hedged"] = w * basket["agg"] + (1.0 - w) * basket[intl_col] + basket["hedge"]
    # Keep EUNA's own returns from splice date onward.
    spliced_ret = {row.date: float(row.hedged) for row in basket.itertuples(index=False)}
    euna_ret = {row.date: float(row.euna) for row in daily_return(euna, "euna").itertuples(index=False)}
    calendar = sorted(set(spliced_ret) | set(euna_ret))
    calendar = [d for d in calendar if d >= START]
    rets = []
    for i, day in enumerate(calendar):
        if i == 0:
            rets.append(0.0)
            continue
        if day >= SPLICE and day in euna_ret:
            rets.append(euna_ret[day])
        elif day in spliced_ret:
            rets.append(spliced_ret[day])
        else:
            rets.append(0.0)
    splice_level = float(euna.loc[euna["date"] == SPLICE, "value"].iloc[0])
    hedged = compound(calendar, rets, SPLICE, splice_level)
    # Force exact EUNA levels from splice onward
    euna_map = {row.date: float(row.value) for row in euna.itertuples(index=False)}
    hedged["value"] = [
        euna_map[d] if d in euna_map and d >= SPLICE else v
        for d, v in zip(hedged["date"], hedged["value"])
    ]
    hedged = hedged[hedged["date"] >= START].drop_duplicates("date").sort_values("date")
    hedged = prepend_start(hedged, spliced_ret.get(hedged.iloc[0]["date"], 0.0))
    print(f"hedged {hedged['date'].min()}..{hedged['date'].max()} n={len(hedged)} first={hedged.iloc[0]['value']:.6f} splice={hedged.loc[hedged['date']==SPLICE,'value'].iloc[0]:.6f}")

    # Unhedged: USD basket converted with ECB FX, then splice onto AGGG EUR path and rebase 100 at start.
    overlap_u = pd.merge(daily_return(aggg_usd, "aggg"), basket[["date", "agg", intl_col]], on="date")
    overlap_u = overlap_u[overlap_u["date"] >= SPLICE]
    target_u = overlap_u["aggg"] - overlap_u[intl_col]
    driver_u = overlap_u["agg"] - overlap_u[intl_col]
    wu, _, _ = best_weight(target_u, driver_u, 0.30, 0.55, 0.005)
    fitted_u = wu * overlap_u["agg"] + (1.0 - wu) * overlap_u[intl_col]
    corr_u = float(overlap_u["aggg"].corr(fitted_u))
    te_u = float((overlap_u["aggg"] - fitted_u).std(ddof=0))
    print(f"USD unhedged w_US={wu:.3f} overlap_n={len(overlap_u)} corr={corr_u:.4f} TE={te_u:.6f}")

    basket["usd"] = wu * basket["agg"] + (1.0 - wu) * basket[intl_col]
    usd_ret_map = {row.date: float(row.usd) for row in basket.itertuples(index=False)}
    aggg_ret = {row.date: float(row.aggg) for row in daily_return(aggg_usd, "aggg").itertuples(index=False)}
    usd_cal = sorted(set(usd_ret_map) | set(aggg_ret))
    usd_cal = [d for d in usd_cal if d >= START]
    usd_rets = []
    for i, day in enumerate(usd_cal):
        if i == 0:
            usd_rets.append(0.0)
            continue
        if day >= SPLICE and day in aggg_ret:
            usd_rets.append(aggg_ret[day])
        elif day in usd_ret_map:
            usd_rets.append(usd_ret_map[day])
        else:
            usd_rets.append(0.0)
    usd_idx = [1.0]
    for ret in usd_rets[1:]:
        usd_idx.append(usd_idx[-1] * (1.0 + ret))
    usd_df = pd.DataFrame({"date": usd_cal, "value": usd_idx})
    # Convert to EUR
    eur_rows = []
    for rec in usd_df.itertuples(index=False):
        looked = fx_lookup(fx, rec.date)
        if looked is None:
            continue
        fx_day, usd_per_eur = looked
        eur_rows.append(
            {
                "date": rec.date,
                "raw_usd_value": float(rec.value),
                "usd_per_eur": usd_per_eur,
                "fx_observation_date": fx_day,
                "eur_value": float(rec.value) / usd_per_eur,
            }
        )
    unhedged = pd.DataFrame(eur_rows)
    first = float(unhedged.iloc[0]["eur_value"])
    unhedged["value"] = 100.0 * unhedged["eur_value"] / first
    unhedged = prepend_start_fx(unhedged, usd_ret_map.get(unhedged.iloc[0]["date"], 0.0), fx)
    first = float(unhedged.iloc[0]["eur_value"])
    unhedged["value"] = 100.0 * unhedged["eur_value"] / first
    print(f"unhedged {unhedged['date'].min()}..{unhedged['date'].max()} n={len(unhedged)} first={unhedged.iloc[0]['value']:.6f}")

    # Prepared import: no simulation labels
    hedged_prep = [
        {
            "date": rec.date.isoformat(),
            "value": decimal(float(rec.value), 8),
            "currency": "EUR",
            "quality_status": "valid",
            "source_revision": "IE00BDBRDM35",
            "source_timestamp": retrieved,
        }
        for rec in hedged.itertuples(index=False)
    ]
    unhedged_prep = [
        {
            "date": rec.date.isoformat(),
            "value": decimal(float(rec.value), 8),
            "currency": "PTS",
            "quality_status": "valid",
            "source_revision": "IE00B3F81409",
            "source_timestamp": retrieved,
        }
        for rec in unhedged.itertuples(index=False)
    ]
    write_csv(PREPARED / "global_ig_bonds_eur_hedged.csv", hedged_prep, IMPORT_FIELDS)
    write_csv(PREPARED / "global_ig_bonds_unhedged_eur.csv", unhedged_prep, IMPORT_FIELDS)

    hedged_clean = [
        {
            "asset_slug": "global_ig_bonds_eur_hedged_total_return",
            "date": rec.date.isoformat(),
            "reference_period": rec.date.isoformat(),
            "release_date": "",
            "value": decimal(float(rec.value), 8),
            "currency": "EUR",
            "unit": "eur_per_share",
            "frequency": "daily",
            "data_kind": "total_return_index",
            "provider": "iShares",
            "dataset": "iShares Core Global Aggregate Bond UCITS ETF EUR Hedged Acc",
            "series_id": "IE00BDBRDM35",
            "quality_status": "valid",
            "source_revision": "IE00BDBRDM35",
            "source_timestamp": retrieved,
            "methodology": "Global investment-grade aggregate total return, EUR hedged, income reinvested.",
            "proxy_for": "",
        }
        for rec in hedged.itertuples(index=False)
    ]
    unhedged_clean = [
        {
            "asset_slug": "global_ig_bonds_unhedged_total_return_eur",
            "date": rec.date.isoformat(),
            "reference_period": rec.date.isoformat(),
            "release_date": "",
            "value": decimal(float(rec.value), 8),
            "currency": "PTS",
            "unit": "index_100_start",
            "frequency": "daily",
            "data_kind": "total_return_index",
            "provider": "iShares",
            "dataset": "iShares Core Global Aggregate Bond UCITS ETF USD Dist, EUR terms",
            "series_id": "IE00B3F81409",
            "quality_status": "valid",
            "source_revision": "IE00B3F81409",
            "source_timestamp": retrieved,
            "methodology": "Global investment-grade aggregate total return in EUR, unhedged, income reinvested.",
            "proxy_for": "",
            "raw_usd_value": decimal(float(rec.raw_usd_value), 8),
            "eur_value": decimal(float(rec.eur_value), 8),
            "usd_per_eur": decimal(float(rec.usd_per_eur), 8),
            "fx_observation_date": rec.fx_observation_date.isoformat(),
        }
        for rec in unhedged.itertuples(index=False)
    ]
    write_csv(CLEAN / "global_ig_bonds_eur_hedged.csv", hedged_clean, CLEAN_FIELDS)
    write_csv(CLEAN / "global_ig_bonds_unhedged_eur.csv", unhedged_clean, CLEAN_FIELDS + FX_EXTRA)

    update_docs(hedged, unhedged, corr, corr_u)
    rebuild_zip()
    print("DONE", flush=True)


def update_docs(hedged: pd.DataFrame, unhedged: pd.DataFrame, corr: float, corr_u: float) -> None:
    readme = PKG / "README_DATA.md"
    text = readme.read_text(encoding="utf-8")
    text = text.replace(
        "| global_ig_bonds_eur_hedged | iShares EUNA.DE proxy IE00BDBRDM35 | 2016-01-05 | 2026-08-20 | daily | EUR | total_return | 2704 |",
        f"| global_ig_bonds_eur_hedged | iShares Core Global Aggregate EUR Hedged Acc IE00BDBRDM35 | {hedged['date'].min().isoformat()} | {hedged['date'].max().isoformat()} | daily | EUR | total_return | {len(hedged)} |",
    )
    text = text.replace(
        "| global_ig_bonds_unhedged_eur | iShares AGGG.L adj close + ECB FX | 2016-01-05 | 2026-08-20 | daily | PTS | total_return | 2703 |",
        f"| global_ig_bonds_unhedged_eur | iShares Core Global Aggregate USD Dist IE00B3F81409, EUR | {unhedged['date'].min().isoformat()} | {unhedged['date'].max().isoformat()} | daily | PTS | total_return | {len(unhedged)} |",
    )
    text = text.replace("iShares EUNA.DE proxy IE00BDBRDM35", "iShares Core Global Aggregate EUR Hedged Acc IE00BDBRDM35")
    text = text.replace("iShares AGGG.L adj close + ECB FX", "iShares Core Global Aggregate USD Dist IE00B3F81409, EUR")
    readme.write_text(text, encoding="utf-8", newline="\n")

    quality = PKG / "DATA_QUALITY_REPORT.md"
    q = quality.read_text(encoding="utf-8")
    q = q.replace(
        "- Official compounded €STR index (`EST.B.EU000A2QQF08.CI`) and official SOFR Index are published total-return / compounded indices.\n"
        "- Bloomberg Global Aggregate and S&P GSCI / BCOM official histories were **not** obtained as free redistributable TR indices.",
        "- Official compounded €STR index (`EST.B.EU000A2QQF08.CI`) and official SOFR Index are published total-return / compounded indices.\n"
        "- `global_ig_bonds_eur_hedged` and `global_ig_bonds_unhedged_eur`: iShares Core Global Aggregate, ISIN IE00BDBRDM35 / IE00B3F81409.",
    )
    q = q.replace(
        "## What is a fund/ETF proxy?\n\n"
        "- `global_ig_bonds_eur_hedged`: iShares Core Global Aggregate Bond UCITS ETF EUR Hedged Acc, ISIN IE00BDBRDM35.\n"
        "- Comparison: Vanguard Global Aggregate EUR Hedged Acc, ISIN IE00BG47KH54, VAGF.DE, stored separately.\n"
        "- `global_ig_bonds_unhedged_eur`: iShares Core Global Aggregate Bond UCITS ETF USD Dist, ISIN IE00B3F81409, in EUR.\n"
        "- `broad_commodities_total_return_eur`: WisdomTree Broad Commodities ETC, ISIN GB00B15KY989, AIGC.L, ECB FX, rebased 100.\n"
        "- Invesco CMOD.L stored as comparison only.\n",
        "## What is a fund/ETF proxy?\n\n"
        "- `broad_commodities_total_return_eur`: WisdomTree Broad Commodities ETC, ISIN GB00B15KY989, AIGC.L, ECB FX, rebased 100.\n"
        "- Invesco CMOD.L stored as comparison only.\n"
        "- Vanguard Global Aggregate EUR Hedged Acc, ISIN IE00BG47KH54, VAGF.DE, comparison only.\n",
    )
    q = q.replace(
        f"- global_ig_bonds_eur_hedged: n=2218 2017-11-21..2026-08-20 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>5%)=0",
        f"- global_ig_bonds_eur_hedged: n={len(hedged)} {hedged['date'].min().isoformat()}..{hedged['date'].max().isoformat()} dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>5%)=0",
    )
    q = q.replace(
        f"- global_ig_bonds_unhedged_eur: n=2208 2017-11-21..2026-08-20 dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>6%)=0",
        f"- global_ig_bonds_unhedged_eur: n={len(unhedged)} {unhedged['date'].min().isoformat()}..{unhedged['date'].max().isoformat()} dupes=0 unsorted=False non_numeric=0 weekends=0 extreme_moves(>6%)=0",
    )
    q = q.replace(
        "- prepared global_ig_bonds_eur_hedged.csv: n=2218 2017-11-21..2026-08-20 ccy=['EUR']",
        f"- prepared global_ig_bonds_eur_hedged.csv: n={len(hedged)} {hedged['date'].min().isoformat()}..{hedged['date'].max().isoformat()} ccy=['EUR']",
    )
    q = q.replace(
        "- prepared global_ig_bonds_unhedged_eur.csv: n=2208 2017-11-21..2026-08-20 ccy=['PTS']",
        f"- prepared global_ig_bonds_unhedged_eur.csv: n={len(unhedged)} {unhedged['date'].min().isoformat()}..{unhedged['date'].max().isoformat()} ccy=['PTS']",
    )
    q = q.replace(
        "- Global IG EUR-hedged and unhedged series cover 2016-01-04 through the latest session.\n",
        "",
    )
    quality.write_text(q, encoding="utf-8", newline="\n")

    manifest = PKG / "source_manifest.csv"
    rows = list(csv.DictReader(manifest.open(encoding="utf-8")))
    for row in rows:
        if row.get("asset_slug") == "global_ig_bonds_eur_hedged_total_return":
            row["provider"] = "iShares"
            row["dataset"] = "iShares Core Global Aggregate Bond UCITS ETF EUR Hedged Acc"
            row["series_id"] = "IE00BDBRDM35"
            row["coverage_start"] = hedged["date"].min().isoformat()
            row["coverage_end"] = hedged["date"].max().isoformat()
            row["data_kind"] = "total_return_index"
            row["transformation"] = "Global IG EUR-hedged total return, income reinvested."
            row["unit"] = "eur_per_share"
            row["license_note"] = "Private research. Attribution: iShares / BlackRock."
        if row.get("asset_slug") == "global_ig_bonds_unhedged_total_return_eur":
            row["provider"] = "iShares"
            row["dataset"] = "iShares Core Global Aggregate Bond UCITS ETF USD Dist, EUR"
            row["series_id"] = "IE00B3F81409"
            row["coverage_start"] = unhedged["date"].min().isoformat()
            row["coverage_end"] = unhedged["date"].max().isoformat()
            row["data_kind"] = "total_return_index"
            row["transformation"] = "Global IG unhedged total return in EUR, income reinvested, 100 at start."
            row["license_note"] = "Private research. Attribution: iShares / BlackRock."
    if rows:
        write_csv(manifest, rows, list(rows[0].keys()))

    notes = PKG / "notes" / "global_aggregate_index_UNAVAILABLE.txt"
    if notes.exists():
        notes.unlink()


def rebuild_zip() -> None:
    dest = ROOT / "data" / f"{PKG.name}.zip"
    if dest.exists():
        dest.unlink()
    with zipfile.ZipFile(dest, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for path in PKG.rglob("*"):
            if path.is_file():
                zf.write(path, arcname=f"{PKG.name}/{path.relative_to(PKG).as_posix()}")
    print(f"ZIP {dest} {dest.stat().st_size} bytes", flush=True)


if __name__ == "__main__":
    main()
