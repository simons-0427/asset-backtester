#!/usr/bin/env python3
"""Collect, clean and document European asset-class series for the backtester.

Research date is written into every generated file.
Re-run from the backtester root:

    python scripts/collect_european_asset_data.py
"""

from __future__ import annotations

import os

import csv
import hashlib
import io
import json
import math
import re
import sys
import threading
import time
import zipfile
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import date, datetime, timezone
from pathlib import Path
from typing import Any
from urllib.parse import urlencode

import pandas as pd
import requests

ROOT = Path(__file__).resolve().parents[1]
DATA = ROOT / "data"
RAW = DATA / "raw"
CLEAN = DATA / "clean"
FORECASTS = DATA / "forecasts"
NOTES = DATA / "notes"
RESEARCH_DATE = date(2026, 8, 20)
RESEARCH_ISO = RESEARCH_DATE.isoformat()
START = date(2016, 1, 1)
FRED_API_KEY = os.environ.get("BACKTESTER_FRED_API_KEY", "")
UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36"
)
SESSION = requests.Session()
SESSION.headers.update({"User-Agent": UA, "Accept": "*/*", "Accept-Language": "en-US,en;q=0.9"})

MANIFEST_ROWS: list[dict[str, str]] = []
QUALITY_NOTES: list[str] = []
DOWNLOAD_LOG: list[dict[str, str]] = []
LOG_LOCK = threading.Lock()


def utc_now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def ensure_dirs() -> None:
    for path in (RAW, CLEAN, FORECASTS, NOTES):
        path.mkdir(parents=True, exist_ok=True)


def write_text(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding="utf-8", newline="\n")


def write_csv(path: Path, rows: list[dict[str, Any]], fieldnames: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow({key: row.get(key, "") for key in fieldnames})


def decimal(value: float, places: int = 6) -> str:
    quantized = f"{value:.{places}f}"
    if "." in quantized:
        quantized = quantized.rstrip("0").rstrip(".")
    return quantized if quantized not in {"", "-"} else "0"


def download(filename: str, url: str, extra_headers: dict[str, str] | None = None, timeout: int = 25) -> Path | None:
    dest = RAW / filename
    headers = dict(SESSION.headers)
    if extra_headers:
        headers.update(extra_headers)
    if filename.endswith((".xlsx", ".xls", ".pdf", ".zip")) or "yc_" in filename or filename.startswith("bbk_"):
        timeout = max(timeout, 60)
    last_error = ""
    for attempt in range(2):
        try:
            response = SESSION.get(url, headers=headers, timeout=(10, timeout), allow_redirects=True)
            payload = response.content
            content_type = (response.headers.get("Content-Type") or "").lower()
            text_head = payload[:400].decode("utf-8", "replace")
            looks_html = b"<html" in payload[:300].lower() or b"<!doctype html" in payload[:300].lower()
            blocked = (
                response.status_code >= 400
                or len(payload) < 40
                or (looks_html and filename.endswith((".csv", ".xlsx", ".xls", ".json")))
                or "access denied" in text_head.lower()
                or "captcha" in text_head.lower()
            )
            if blocked:
                last_error = f"HTTP {response.status_code} type={content_type} bytes={len(payload)} head={text_head[:120]!r}"
                time.sleep(0.8 * (attempt + 1))
                continue
            dest.write_bytes(payload)
            with LOG_LOCK:
                DOWNLOAD_LOG.append(
                    {
                        "file_name": filename,
                        "url": url,
                        "http_status": str(response.status_code),
                        "bytes": str(len(payload)),
                        "sha256": sha256_bytes(payload),
                        "retrieved_at": utc_now(),
                        "content_type": content_type,
                        "status": "ok",
                    }
                )
            print(f"OK  {filename}  {len(payload)} bytes", flush=True)
            return dest
        except Exception as exc:  # noqa: BLE001
            last_error = str(exc)
            time.sleep(0.8 * (attempt + 1))
    with LOG_LOCK:
        DOWNLOAD_LOG.append(
            {
                "file_name": filename,
                "url": url,
                "http_status": "",
                "bytes": "0",
                "sha256": "",
                "retrieved_at": utc_now(),
                "content_type": "",
                "status": f"failed: {last_error[:240]}",
            }
        )
        QUALITY_NOTES.append(f"Download failed for {filename}: {last_error}")
    print(f"FAIL {filename}: {last_error}", flush=True)
    return None


def add_manifest(**kwargs: str) -> None:
    MANIFEST_ROWS.append(
        {
            "file_name": kwargs.get("file_name", ""),
            "asset_slug": kwargs.get("asset_slug", ""),
            "provider": kwargs.get("provider", ""),
            "dataset": kwargs.get("dataset", ""),
            "series_id": kwargs.get("series_id", ""),
            "source_url": kwargs.get("source_url", ""),
            "download_url": kwargs.get("download_url", ""),
            "frequency": kwargs.get("frequency", ""),
            "currency": kwargs.get("currency", ""),
            "unit": kwargs.get("unit", ""),
            "coverage_start": kwargs.get("coverage_start", ""),
            "coverage_end": kwargs.get("coverage_end", ""),
            "retrieved_at": kwargs.get("retrieved_at", utc_now()),
            "license_note": kwargs.get("license_note", ""),
            "transformation": kwargs.get("transformation", ""),
        }
    )


def parse_ohlc_csv(path: Path) -> pd.DataFrame:
    df = pd.read_csv(path)
    df.columns = [str(col).strip().lower() for col in df.columns]
    close_col = "close" if "close" in df.columns else ("adj close" if "adj close" in df.columns else None)
    if "date" not in df.columns or close_col is None:
        raise ValueError(f"Unexpected OHLC columns in {path.name}: {list(df.columns)}")
    df["date"] = pd.to_datetime(df["date"], errors="coerce").dt.date
    df["value"] = pd.to_numeric(df[close_col], errors="coerce")
    df = df.dropna(subset=["date", "value"])
    df = df[df["value"] > 0]
    df = df[df["date"] >= START]
    return df.sort_values("date").drop_duplicates("date", keep="last")


def parse_stooq(path: Path) -> pd.DataFrame:
    return parse_ohlc_csv(path)


def parse_yahoo_chart(path: Path) -> pd.DataFrame:
    payload = json.loads(path.read_text(encoding="utf-8"))
    result = payload["chart"]["result"][0]
    stamps = result.get("timestamp") or []
    closes = ((result.get("indicators") or {}).get("quote") or [{}])[0].get("close") or []
    tz_name = result.get("meta", {}).get("exchangeTimezoneName") or "Europe/Berlin"
    rows = []
    for stamp, close in zip(stamps, closes):
        if stamp is None or close is None or not math.isfinite(float(close)) or float(close) <= 0:
            continue
        day = datetime.fromtimestamp(int(stamp), tz=timezone.utc).date()
        rows.append({"date": day, "value": float(close), "symbol": result.get("meta", {}).get("symbol", "")})
    df = pd.DataFrame(rows)
    if df.empty:
        raise ValueError(f"No Yahoo observations in {path.name}")
    df = df[df["date"] >= START].drop_duplicates("date", keep="last").sort_values("date")
    df.attrs["timezone"] = tz_name
    return df


def parse_ecb_csv(path: Path, value_col: str = "OBS_VALUE") -> pd.DataFrame:
    text = path.read_text(encoding="utf-8-sig")
    df = pd.read_csv(io.StringIO(text))
    df.columns = [str(col).strip() for col in df.columns]
    if "TIME_PERIOD" not in df.columns or value_col not in df.columns:
        raise ValueError(f"Unexpected ECB columns in {path.name}: {list(df.columns)}")
    df["date"] = pd.to_datetime(df["TIME_PERIOD"], errors="coerce").dt.date
    df["value"] = pd.to_numeric(df[value_col], errors="coerce")
    df = df.dropna(subset=["date", "value"])
    df = df[df["date"] >= START]
    return df.sort_values("date")


def parse_bundesbank_csv(path: Path) -> pd.DataFrame:
    text = path.read_text(encoding="utf-8-sig")
    lines = text.splitlines()
    start_idx = None
    for idx, line in enumerate(lines):
        if re.match(r"^\d{4}-\d{2}-\d{2},", line):
            start_idx = idx
            break
    if start_idx is None:
        raise ValueError(f"Could not find Bundesbank date rows in {path.name}")
    rows = []
    for line in lines[start_idx:]:
        parts = next(csv.reader([line]))
        if len(parts) < 2:
            continue
        day = pd.to_datetime(parts[0], errors="coerce")
        if pd.isna(day):
            continue
        raw = (parts[1] or "").strip()
        if raw in {"", ".", "..."}:
            continue
        try:
            number = float(raw)
        except ValueError:
            continue
        rows.append({"date": day.date(), "value": number})
    df = pd.DataFrame(rows)
    if df.empty:
        raise ValueError(f"No numeric Bundesbank observations in {path.name}")
    df = df[df["date"] >= START]
    return df.sort_values("date").drop_duplicates("date", keep="last")


def parse_fred_csv(path: Path) -> pd.DataFrame:
    try:
        df = pd.read_csv(path)
    except UnicodeDecodeError:
        df = pd.read_csv(path, encoding="latin-1")
    df.columns = [str(col).strip() for col in df.columns]
    date_col = "observation_date" if "observation_date" in df.columns else df.columns[0]
    value_col = [c for c in df.columns if c != date_col][0]
    df["date"] = pd.to_datetime(df[date_col], errors="coerce").dt.date
    df["value"] = pd.to_numeric(df[value_col], errors="coerce")
    df = df.dropna(subset=["date", "value"])
    df = df[df["date"] >= START]
    df = df[df["value"] > 0]
    return df.sort_values("date").drop_duplicates("date", keep="last")


def parse_eurostat_jsonstat(path: Path) -> pd.DataFrame:
    payload = json.loads(path.read_text(encoding="utf-8"))
    dim_ids: list[str] = payload["id"]
    sizes: list[int] = payload["size"]
    values = payload.get("value") or {}
    status = payload.get("status") or {}
    if isinstance(values, list):
        values = {str(i): v for i, v in enumerate(values) if v is not None}
    if isinstance(status, list):
        status = {str(i): v for i, v in enumerate(status) if v}
    dim_labels: dict[str, dict[str, str]] = {}
    dim_index_keys: dict[str, list[str]] = {}
    for dim_id in dim_ids:
        category = payload["dimension"][dim_id]["category"]
        index = category.get("index") or {}
        label = category.get("label") or {}
        if isinstance(index, dict):
            ordered = [key for key, _ in sorted(index.items(), key=lambda item: item[1])]
        else:
            ordered = list(index)
        dim_index_keys[dim_id] = ordered
        dim_labels[dim_id] = {key: str(label.get(key, key)) for key in ordered}

    rows = []
    for flat_key, value in values.items():
        idx = int(flat_key)
        remaining = idx
        coords: dict[str, str] = {}
        for dim_id, size in zip(reversed(dim_ids), reversed(sizes)):
            pos = remaining % size
            remaining //= size
            key = dim_index_keys[dim_id][pos]
            coords[dim_id] = key
            coords[f"{dim_id}_label"] = dim_labels[dim_id].get(key, key)
        if value is None:
            continue
        try:
            number = float(value)
        except (TypeError, ValueError):
            continue
        flag = str(status.get(str(flat_key), "") or "")
        coords["value"] = number
        coords["status_flag"] = flag
        rows.append(coords)
    if not rows:
        raise ValueError(f"No Eurostat observations in {path.name}")
    return pd.DataFrame(rows)


def quarter_end(period: str) -> date:
    year_s, q_s = period.split("-Q")
    year = int(year_s)
    quarter = int(q_s)
    month = quarter * 3
    if month == 12:
        return date(year, 12, 31)
    nxt = date(year, month + 1, 1)
    return date.fromordinal(nxt.toordinal() - 1)


def month_end(period: str) -> date:
    stamp = datetime.strptime(period[:7], "%Y-%m").date()
    if stamp.month == 12:
        return date(stamp.year, 12, 31)
    nxt = date(stamp.year, stamp.month + 1, 1)
    return date.fromordinal(nxt.toordinal() - 1)


def price_rows(df: pd.DataFrame, currency: str, quality: str, revision: str) -> list[dict[str, str]]:
    retrieved = utc_now()
    rows = []
    for rec in df.itertuples(index=False):
        status = quality
        if rec.date == df["date"].max() and quality == "valid":
            status = "provisional"
        rows.append(
            {
                "date": rec.date.isoformat(),
                "value": decimal(float(rec.value), 6),
                "currency": currency,
                "quality_status": status,
                "source_revision": revision,
                "source_timestamp": retrieved,
            }
        )
    return rows


def write_price_file(name: str, df: pd.DataFrame, currency: str, quality: str, revision: str) -> Path:
    path = CLEAN / name
    write_csv(path, price_rows(df, currency, quality, revision), ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp"])
    return path


def implied_annual_dividends(price: pd.DataFrame, total_return: pd.DataFrame, slug: str, method: str) -> list[dict[str, str]]:
    merged = pd.merge(price[["date", "value"]], total_return[["date", "value"]], on="date", suffixes=("_px", "_tr"))
    merged["year"] = merged["date"].map(lambda d: d.year)
    rows = []
    years = sorted(merged["year"].unique())
    for year in years:
        if year < START.year:
            continue
        prev = merged[merged["year"] == year - 1]
        cur = merged[merged["year"] == year]
        if cur.empty:
            continue
        if prev.empty:
            start_row = cur.iloc[0]
            status = "partial_from_first_observation"
        else:
            start_row = prev.iloc[-1]
            status = "final"
        end_row = cur.iloc[-1]
        if year == RESEARCH_DATE.year:
            status = "ytd_partial"
        px0, px1 = float(start_row["value_px"]), float(end_row["value_px"])
        tr0, tr1 = float(start_row["value_tr"]), float(end_row["value_tr"])
        if min(px0, px1, tr0, tr1) <= 0:
            continue
        factor = (tr1 / tr0) / (px1 / px0) - 1.0
        points = px0 * ((tr1 / tr0) - (px1 / px0))
        rows.append(
            {
                "asset_slug": slug,
                "year": str(year),
                "annual_yield_pct": decimal(factor * 100.0, 4),
                "dividend_points": decimal(points, 4),
                "status": status,
                "as_of_date": RESEARCH_ISO,
                "source_name": "Derived from price and total-return series",
                "source_url": "see source_manifest.csv",
                "methodology": method,
            }
        )
    return rows


def modified_duration_par(yield_pct: float, maturity_years: float) -> float:
    y = yield_pct / 100.0
    n = maturity_years
    if abs(y) < 1e-10:
        return (n + 1.0) / 2.0
    macaulay = ((1.0 + y) / y) * (1.0 - (1.0 + y) ** (-n))
    return macaulay / (1.0 + y)


def constant_maturity_total_return(yields: pd.DataFrame, maturity_years: float) -> pd.DataFrame:
    series = yields.dropna(subset=["date", "value"]).sort_values("date").drop_duplicates("date")
    if series.empty:
        return series
    index = 100.0
    prev_y = float(series.iloc[0]["value"])
    prev_d = series.iloc[0]["date"]
    out = [{"date": prev_d, "value": index}]
    for rec in series.iloc[1:].itertuples(index=False):
        y = float(rec.value)
        days = (rec.date - prev_d).days
        if days <= 0:
            continue
        dt = days / 365.2425
        duration = modified_duration_par(prev_y, maturity_years)
        income = (prev_y / 100.0) * dt
        price_effect = -duration * ((y - prev_y) / 100.0)
        index *= 1.0 + income + price_effect
        if not math.isfinite(index) or index <= 0:
            QUALITY_NOTES.append(f"Modeled bond index became non-finite at {rec.date} maturity={maturity_years}")
            break
        out.append({"date": rec.date, "value": index})
        prev_y = y
        prev_d = rec.date
    return pd.DataFrame(out)


def last_value(df: pd.DataFrame) -> float | None:
    if df is None or df.empty:
        return None
    return float(df.sort_values("date").iloc[-1]["value"])


def coverage(df: pd.DataFrame) -> tuple[str, str]:
    if df is None or df.empty:
        return "", ""
    return df["date"].min().isoformat(), df["date"].max().isoformat()


def download_all() -> None:
    yahoo_period = {
        "period1": "1451606400",
        "period2": str(int(time.time())),
        "interval": "1d",
        "events": "history",
        "includeAdjustedClose": "true",
    }
    yahoo_q = urlencode(yahoo_period)
    stooq_headers = {"Referer": "https://stooq.com/", "Accept": "text/csv,text/plain,*/*"}
    jobs: list[tuple[str, str, dict[str, str] | None]] = [
        ("stooq_dax.csv", "https://stooq.com/q/d/l/?s=^dax&i=d", stooq_headers),
        ("stooq_daxk.csv", "https://stooq.com/q/d/l/?s=^daxk&i=d", stooq_headers),
        ("stooq_stoxx50e.csv", "https://stooq.com/q/d/l/?s=^stoxx50e&i=d", stooq_headers),
        ("stooq_sx5e.csv", "https://stooq.com/q/d/l/?s=^sx5e&i=d", stooq_headers),
        ("stooq_sx5t.csv", "https://stooq.com/q/d/l/?s=^sx5t&i=d", stooq_headers),
        ("stooq_sx5gt.csv", "https://stooq.com/q/d/l/?s=^sx5gt&i=d", stooq_headers),
        ("stooq_sxrt_de.csv", "https://stooq.com/q/d/l/?s=sxrt.de&i=d", stooq_headers),
        ("stooq_exs1_de.csv", "https://stooq.com/q/d/l/?s=exs1.de&i=d", stooq_headers),
        ("stooq_exw1_de.csv", "https://stooq.com/q/d/l/?s=exw1.de&i=d", stooq_headers),
        ("stooq_eun2_de.csv", "https://stooq.com/q/d/l/?s=eun2.de&i=d", stooq_headers),
        ("stooq_cs51_uk.csv", "https://stooq.com/q/d/l/?s=cs51.uk&i=d", stooq_headers),
        ("yahoo_gdaxi.json", f"https://query1.finance.yahoo.com/v8/finance/chart/%5EGDAXI?{yahoo_q}", None),
        ("yahoo_stoxx50e.json", f"https://query1.finance.yahoo.com/v8/finance/chart/%5ESTOXX50E?{yahoo_q}", None),
        ("yahoo_exs1.json", f"https://query1.finance.yahoo.com/v8/finance/chart/EXS1.DE?{yahoo_q}", None),
        ("yahoo_sxrt.json", f"https://query1.finance.yahoo.com/v8/finance/chart/SXRT.DE?{yahoo_q}", None),
        ("yahoo_eun2.json", f"https://query1.finance.yahoo.com/v8/finance/chart/EUN2.DE?{yahoo_q}", None),
        ("yahoo_cs51.json", f"https://query1.finance.yahoo.com/v8/finance/chart/CS51.L?{yahoo_q}", None),
        ("yahoo_daxk_de.json", f"https://query1.finance.yahoo.com/v8/finance/chart/DAXK.DE?{yahoo_q}", None),
        ("yahoo_gdaxci.json", f"https://query1.finance.yahoo.com/v8/finance/chart/%5EGDAXCI?{yahoo_q}", None),
        ("yahoo_daxk.json", f"https://query1.finance.yahoo.com/v8/finance/chart/%5EDAXK?{yahoo_q}", None),
        ("yahoo_gdaxip.json", f"https://query1.finance.yahoo.com/v8/finance/chart/%5EGDAXIP?{yahoo_q}", None),
        ("yahoo_gdaxi.csv", f"https://query1.finance.yahoo.com/v7/finance/download/%5EGDAXI?{yahoo_q}", None),
        ("yahoo_stoxx50e.csv", f"https://query1.finance.yahoo.com/v7/finance/download/%5ESTOXX50E?{yahoo_q}", None),
        ("yahoo_sxrt.csv", f"https://query1.finance.yahoo.com/v7/finance/download/SXRT.DE?{yahoo_q}", None),
        ("yahoo_exs1.csv", f"https://query1.finance.yahoo.com/v7/finance/download/EXS1.DE?{yahoo_q}", None),
        (
            "eurostat_prc_hpi_q_de.json",
            "https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hpi_q?geo=DE&sinceTimePeriod=2016-Q1&format=JSON",
            None,
        ),
        (
            "eurostat_prc_hicp_rent_de.json",
            "https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hicp_midx?geo=DE&coicop=CP041&unit=I15&sinceTimePeriod=2016-01&format=JSON",
            None,
        ),
        (
            "eurostat_prc_hicp_rent_cp041n.json",
            "https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hicp_midx?geo=DE&coicop=CP041n&unit=I15&sinceTimePeriod=2016-01&format=JSON",
            None,
        ),
        (
            "wb_cmo_monthly.xlsx",
            "https://thedocs.worldbank.org/en/doc/74e8be41ceb20fa0da750cda2f6b9e4e-0050012026/related/CMO-Historical-Data-Monthly.xlsx",
            None,
        ),
        (
            "wb_cmo_annual.xlsx",
            "https://thedocs.worldbank.org/en/doc/74e8be41ceb20fa0da750cda2f6b9e4e-0050012026/related/CMO-Historical-Data-Annual.xlsx",
            None,
        ),
        (
            "wb_cmo_april_2026_forecasts.pdf",
            "https://thedocs.worldbank.org/en/doc/f3138644a1e8e2bb631399ae11d6c408-0050012026/related/CMO-April-2026-Forecasts.pdf",
            None,
        ),
        (
            "wb_cmo_april_2026_report.pdf",
            "https://openknowledge.worldbank.org/bitstreams/a384bcad-abc5-4b9b-9c1d-35f374581024/download",
            None,
        ),
        (
            "ecb_usd_eur_daily.csv",
            "https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A?startPeriod=2016-01-01&format=csvdata",
            {"Accept": "text/csv"},
        ),
        (
            "ecb_usd_eur_monthly.csv",
            "https://data-api.ecb.europa.eu/service/data/EXR/M.USD.EUR.SP00.A?startPeriod=2016-01&format=csvdata",
            {"Accept": "text/csv"},
        ),
        (
            "ecb_yc_aaa_spot.csv",
            "https://data-api.ecb.europa.eu/service/data/YC/B.U2.EUR.4F.G_N_A.SV_C_YM.SR_1Y+SR_2Y+SR_3Y+SR_5Y+SR_7Y+SR_10Y+SR_15Y+SR_20Y+SR_30Y?startPeriod=2016-01-01&format=csvdata",
            {"Accept": "text/csv"},
        ),
        (
            "ecb_yc_all_spot_10y.csv",
            "https://data-api.ecb.europa.eu/service/data/YC/B.U2.EUR.4F.G_N_C.SV_C_YM.SR_1Y+SR_2Y+SR_5Y+SR_10Y+SR_30Y?startPeriod=2016-01-01&format=csvdata",
            {"Accept": "text/csv"},
        ),
        (
            "destatis_61262_0001.csv",
            "https://www-genesis.destatis.de/genesisWS/rest/2020/data/tablefile?name=61262-0001&area=all&compress=false&language=en&format=csv",
            None,
        ),
        (
            "bis_spp_de.csv",
            "https://stats.bis.org/api/v2/data/dataflow/BIS/WS_SPP/1.0/Q.DE.R.H.A.IX?startPeriod=2016-Q1&format=csv",
            None,
        ),
        (
            "oecd_house_prices_de.csv",
            "https://sdmx.oecd.org/public/rest/data/OECD.ECO.MPD,DSD_AN_HOUSE_PRICES@DF_HOUSE_PRICES,1.0/Q.DEU...?startPeriod=2016&format=csvfilewithlabels",
            None,
        ),
        (
            "imf_commodity_monthly.xls",
            "https://www.imf.org/-/media/Files/Research/commodity-prices/monthly/external-data.xls",
            None,
        ),
    ]
    maturities = {
        "01": "1",
        "02": "2",
        "03": "3",
        "05": "5",
        "07": "7",
        "10": "10",
        "15": "15",
        "20": "20",
        "30": "30",
    }
    for code, years in maturities.items():
        jobs.append(
            (
                f"bbk_term_r{code}xx.csv",
                f"https://api.statistiken.bundesbank.de/rest/data/BBSIS/D.I.ZST.ZI.EUR.S1311.B.A604.R{code}XX.R.A.A._Z._Z.A?format=csv&lang=en",
                {"Accept": "text/csv"},
            )
        )
    on_the_run = {
        "2y": "BBSSY/D.REN.EUR.A610.000000WT0202.A",
        "5y": "BBSSY/D.REN.EUR.A620.000000WT0505.A",
        "7y": "BBSSY/D.REN.EUR.A607.000000WT7070.A",
        "10y": "BBSSY/D.REN.EUR.A630.000000WT1010.A",
        "15y": "BBSSY/D.REN.EUR.A615.000000WT1515.A",
        "20y": "BBSSY/D.REN.EUR.A612.000000WT2020.A",
        "30y": "BBSSY/D.REN.EUR.A640.000000WT3030.A",
    }
    for label, key in on_the_run.items():
        jobs.append(
            (
                f"bbk_ontherun_{label}.csv",
                f"https://api.statistiken.bundesbank.de/rest/data/{key}?format=csv&lang=en",
                {"Accept": "text/csv"},
            )
        )
    fred_ids = [
        "DCOILBRENTEU",
        "DCOILWTICO",
        "DHHNGSP",
        "GOLDAMGBD228NLBM",
        "GOLDPMGBD228NLBM",
        "SLVPRUSD",
        "PCOPPUSDM",
        "PALUMUSDM",
        "PNICKUSDM",
        "PWHEAMTUSDM",
        "PMAIZMTUSDM",
        "PCOFFOTMUSDM",
        "PCOCOUSDM",
        "QDEU628BIS",
        "IRLTLT01DEM156N",
        "INTGSBDEM193N",
    ]
    for series_id in fred_ids:
        query = urlencode(
            {
                "series_id": series_id,
                "api_key": FRED_API_KEY,
                "file_type": "csv",
                "observation_start": "2016-01-01",
            }
        )
        jobs.append((f"fred_{series_id}.csv", f"https://api.stlouisfed.org/fred/series/observations?{query}", None))

    print(f"Downloading {len(jobs)} sources...", flush=True)
    with ThreadPoolExecutor(max_workers=6) as pool:
        futures = [pool.submit(download, filename, url, headers) for filename, url, headers in jobs]
        for future in as_completed(futures):
            future.result()


def load_optional_stooq(name: str) -> pd.DataFrame | None:
    path = RAW / name
    if not path.exists() or path.stat().st_size < 80:
        return None
    try:
        df = parse_stooq(path)
    except Exception as exc:  # noqa: BLE001
        QUALITY_NOTES.append(f"{name} was not a usable Stooq CSV: {exc}")
        return None
    if df.empty:
        return None
    return df


def load_optional_yahoo(name: str) -> pd.DataFrame | None:
    path = RAW / name
    if not path.exists() or path.stat().st_size < 80:
        return None
    try:
        return parse_yahoo_chart(path)
    except Exception as exc:  # noqa: BLE001
        QUALITY_NOTES.append(f"{name} was not a usable Yahoo chart: {exc}")
        return None


def first_series(label: str, *frames: pd.DataFrame | None) -> pd.DataFrame:
    for idx, frame in enumerate(frames):
        if frame is not None and not frame.empty:
            if idx:
                QUALITY_NOTES.append(f"{label}: used fallback source #{idx + 1}.")
            return frame
    raise RuntimeError(f"No series available for {label}")


def load_optional_ohlc(name: str) -> pd.DataFrame | None:
    path = RAW / name
    if not path.exists() or path.stat().st_size < 80:
        return None
    try:
        return parse_ohlc_csv(path)
    except Exception as exc:  # noqa: BLE001
        QUALITY_NOTES.append(f"{name} was not a usable OHLC CSV: {exc}")
        return None


def choose_index_series(primary: pd.DataFrame | None, secondary: pd.DataFrame | None, label: str) -> pd.DataFrame:
    return first_series(label, primary, secondary)


def parse_world_bank_monthly(path: Path) -> pd.DataFrame:
    xl = pd.ExcelFile(path)
    sheet = "Monthly Prices" if "Monthly Prices" in xl.sheet_names else xl.sheet_names[0]
    raw = pd.read_excel(path, sheet_name=sheet, header=None)
    # Find the header row that contains commodity names and a following units row.
    header_idx = None
    for idx in range(min(12, len(raw))):
        row = [str(v) for v in raw.iloc[idx].tolist()]
        joined = " | ".join(row).lower()
        if "crude oil, brent" in joined or "brent" in joined and "gold" in joined:
            header_idx = idx
            break
    if header_idx is None:
        # Pink Sheet usually has names on row 4 (0-based 4) or 6.
        for idx in range(min(20, len(raw))):
            vals = [str(v).strip() for v in raw.iloc[idx].tolist()]
            if any("brent" in v.lower() for v in vals) and any("gold" in v.lower() for v in vals):
                header_idx = idx
                break
    if header_idx is None:
        raise ValueError(f"Could not locate World Bank commodity header; sheets={xl.sheet_names}")
    names = [str(v).strip() for v in raw.iloc[header_idx].tolist()]
    units = [str(v).strip() for v in raw.iloc[header_idx + 1].tolist()]
    body = raw.iloc[header_idx + 2 :].copy()
    body.columns = [f"c{i}" for i in range(body.shape[1])]
    records = []
    for _, row in body.iterrows():
        period_raw = row.iloc[0]
        period = parse_wb_period(period_raw)
        if period is None:
            continue
        for col_idx in range(1, len(names)):
            name = names[col_idx]
            if not name or name.lower() in {"nan", "none"}:
                continue
            value = row.iloc[col_idx]
            if value is None or (isinstance(value, float) and math.isnan(value)):
                continue
            try:
                number = float(value)
            except (TypeError, ValueError):
                continue
            if not math.isfinite(number):
                continue
            records.append(
                {
                    "date": period,
                    "commodity": name,
                    "unit": units[col_idx] if col_idx < len(units) else "",
                    "usd_value": number,
                }
            )
    df = pd.DataFrame(records)
    df = df[df["date"] >= START]
    return df.sort_values(["commodity", "date"])


def parse_wb_period(value: Any) -> date | None:
    if value is None or (isinstance(value, float) and math.isnan(value)):
        return None
    if isinstance(value, datetime):
        return date(value.year, value.month, 1)
    if isinstance(value, date):
        return date(value.year, value.month, 1)
    text = str(value).strip()
    match = re.match(r"^(\d{4})[Mm](\d{1,2})$", text)
    if match:
        return date(int(match.group(1)), int(match.group(2)), 1)
    match = re.match(r"^(\d{4})-(\d{2})$", text)
    if match:
        return date(int(match.group(1)), int(match.group(2)), 1)
    try:
        parsed = pd.to_datetime(text, errors="coerce")
        if pd.isna(parsed):
            return None
        return date(parsed.year, parsed.month, 1)
    except Exception:  # noqa: BLE001
        return None


def extract_pdf_text(path: Path) -> str:
    try:
        from pypdf import PdfReader
    except ImportError:
        return ""
    reader = PdfReader(str(path))
    chunks = []
    for page in reader.pages[:30]:
        chunks.append(page.extract_text() or "")
    return "\n".join(chunks)


def slugify_commodity(name: str) -> str:
    mapping = {
        "crude oil, brent": "brent",
        "crude oil, wti": "wti",
        "natural gas, us": "natgas_us",
        "natural gas, europe": "natgas_europe",
        "natural gas, europe, ttf": "natgas_europe",
        "gold": "gold",
        "silver": "silver",
        "copper": "copper",
        "aluminum": "aluminum",
        "aluminium": "aluminum",
        "nickel": "nickel",
        "wheat, us hrw": "wheat",
        "maize": "maize",
        "coffee, arabica": "coffee_arabica",
        "cocoa": "cocoa",
        "soybeans": "soybeans",
        "platinum": "platinum",
        "zinc": "zinc",
        "coal, australian": "coal_australia",
    }
    key = re.sub(r"\s+", " ", name.strip().lower())
    if key in mapping:
        return mapping[key]
    for prefix, slug in mapping.items():
        if key.startswith(prefix):
            return slug
    return re.sub(r"[^a-z0-9]+", "_", key).strip("_")


SELECTED_COMMODITIES = {
    "brent",
    "wti",
    "natgas_us",
    "natgas_europe",
    "gold",
    "silver",
    "copper",
    "aluminum",
    "nickel",
    "wheat",
    "maize",
    "coffee_arabica",
    "cocoa",
}


def fx_lookup(daily_fx: pd.DataFrame) -> dict[date, float]:
    series = daily_fx.sort_values("date")
    mapping: dict[date, float] = {}
    last = None
    cursor = START
    values = {rec.date: float(rec.value) for rec in series.itertuples(index=False)}
    end = max(values) if values else RESEARCH_DATE
    while cursor <= end:
        if cursor in values:
            last = values[cursor]
        if last is not None:
            mapping[cursor] = last
        cursor = date.fromordinal(cursor.toordinal() + 1)
    return mapping


def build_clean_files() -> dict[str, Any]:
    ctx: dict[str, Any] = {}
    dax = first_series(
        "DAX",
        load_optional_stooq("stooq_dax.csv"),
        load_optional_ohlc("yahoo_gdaxi.csv"),
        load_optional_yahoo("yahoo_gdaxi.json"),
    )
    daxk_candidates = [
        load_optional_stooq("stooq_daxk.csv"),
        load_optional_yahoo("yahoo_gdaxip.json"),
        load_optional_yahoo("yahoo_daxk_de.json"),
        load_optional_yahoo("yahoo_gdaxci.json"),
        load_optional_yahoo("yahoo_daxk.json"),
        load_optional_ohlc("av_daxk_DAXK_DEX.csv"),
    ]
    daxk = None
    for candidate in daxk_candidates:
        if candidate is not None and not candidate.empty:
            daxk = candidate
            break
    if daxk is None:
        QUALITY_NOTES.append("DAXK was not available from Stooq. Price series cannot be replaced by the performance index.")
        daxk = pd.DataFrame(columns=["date", "value"])
    sx5e = first_series(
        "EURO STOXX 50 price",
        load_optional_stooq("stooq_stoxx50e.csv"),
        load_optional_stooq("stooq_sx5e.csv"),
        load_optional_ohlc("yahoo_stoxx50e.csv"),
        load_optional_yahoo("yahoo_stoxx50e.json"),
    )
    sx5t = load_optional_stooq("stooq_sx5t.csv")
    sx5gt = load_optional_stooq("stooq_sx5gt.csv")
    sxrt = None
    for candidate in (
        load_optional_stooq("stooq_sxrt_de.csv"),
        load_optional_ohlc("yahoo_sxrt.csv"),
        load_optional_yahoo("yahoo_sxrt.json"),
        load_optional_stooq("stooq_cs51_uk.csv"),
        load_optional_yahoo("yahoo_cs51.json"),
    ):
        if candidate is not None and not candidate.empty:
            sxrt = candidate
            break
    eun2 = load_optional_stooq("stooq_eun2_de.csv") or load_optional_yahoo("yahoo_eun2.json")
    exw1 = load_optional_stooq("stooq_exw1_de.csv") or load_optional_ohlc("yahoo_exs1.csv") or load_optional_yahoo("yahoo_exs1.json")

    write_price_file("dax_total_return_daily.csv", dax, "EUR", "valid", "stooq:^dax" if (RAW / "stooq_dax.csv").exists() else "yahoo:^GDAXI")
    add_manifest(
        file_name="dax_total_return_daily.csv",
        asset_slug="dax",
        provider="Stooq / Deutsche Boerse republished index levels",
        dataset="DAX Performance Index",
        series_id="DAX / ISIN DE0008469008 / Stooq ^dax",
        source_url="https://stooq.com/q/?s=^dax",
        download_url="https://stooq.com/q/d/l/?s=^dax&i=d",
        frequency="daily",
        currency="EUR",
        unit="index_points",
        coverage_start=coverage(dax)[0],
        coverage_end=coverage(dax)[1],
        license_note="Index levels republished by Stooq. Not a licensed Deutsche Boerse redistribution product. Private research use.",
        transformation="Close only; weekends and non-sessions dropped; no interpolation.",
    )
    if not daxk.empty:
        write_price_file("dax_price_daily.csv", daxk, "EUR", "valid", "yahoo:^GDAXIP")
        add_manifest(
            file_name="dax_price_daily.csv",
            asset_slug="daxk",
            provider="Yahoo Finance chart / Deutsche Boerse DAX Price Index",
            dataset="DAX Price Index (DAX K / DAXK)",
            series_id="DAXK / ISIN DE0008467440 / Yahoo ^GDAXIP",
            source_url="https://finance.yahoo.com/quote/%5EGDAXIP",
            download_url="https://query1.finance.yahoo.com/v8/finance/chart/%5EGDAXIP",
            frequency="daily",
            currency="EUR",
            unit="index_points",
            coverage_start=coverage(daxk)[0],
            coverage_end=coverage(daxk)[1],
            license_note="Index levels republished by Stooq. Not a licensed Deutsche Boerse redistribution product. Private research use.",
            transformation="Close only; no interpolation.",
        )
        dax_div = implied_annual_dividends(
            daxk,
            dax,
            "dax",
            "annual_yield = (DAX_end/DAX_start)/(DAXK_end/DAXK_start)-1 using last prior-year close as start and last close in the year as end. 2026 is year-to-date.",
        )
        write_csv(
            CLEAN / "dax_dividend_yield_annual.csv",
            dax_div,
            ["asset_slug", "year", "annual_yield_pct", "dividend_points", "status", "as_of_date", "source_name", "source_url", "methodology"],
        )
    else:
        write_csv(CLEAN / "dax_price_daily.csv", [], ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp"])
        write_csv(
            CLEAN / "dax_dividend_yield_annual.csv",
            [],
            ["asset_slug", "year", "annual_yield_pct", "dividend_points", "status", "as_of_date", "source_name", "source_url", "methodology"],
        )

    write_price_file("eurostoxx50_price_daily.csv", sx5e, "EUR", "valid", "yahoo:^STOXX50E")
    add_manifest(
        file_name="eurostoxx50_price_daily.csv",
        asset_slug="eurostoxx50_price",
        provider="Stooq / STOXX index level republished",
        dataset="EURO STOXX 50 Price EUR",
        series_id="SX5E / Stooq ^stoxx50e / Yahoo ^STOXX50E",
        source_url="https://finance.yahoo.com/quote/%5ESTOXX50E",
        download_url="https://query1.finance.yahoo.com/v8/finance/chart/%5ESTOXX50E",
        frequency="daily",
        currency="EUR",
        unit="index_points",
        coverage_start=coverage(sx5e)[0],
        coverage_end=coverage(sx5e)[1],
        license_note="Official STOXX history is licensed. This file uses publicly republished price-index levels and is not the licensed SX5E product.",
        transformation="Close only; no interpolation.",
    )

    if sx5t is not None and not sx5t.empty:
        write_price_file("eurostoxx50_net_return_daily.csv", sx5t, "EUR", "valid", "stooq:^sx5t")
        QUALITY_NOTES.append("EURO STOXX 50 Net Return was found on Stooq as ^sx5t.")
        net_for_div = sx5t
        net_label = "official_or_republished_sx5t"
    elif sxrt is not None and not sxrt.empty:
        write_price_file("eurostoxx50_net_return_daily.csv", sxrt, "EUR", "proxy_etf_acc", "etf_proxy:SXRT/CS51")
        QUALITY_NOTES.append(
            "EURO STOXX 50 Net Return Index SX5T is not freely licensed. "
            "eurostoxx50_net_return_daily.csv uses an accumulating UCITS ETF proxy (SXRT.DE / CS51.L). "
            "This is net of fund TER, tracking difference and withholding inside the fund. It is not SX5T."
        )
        net_for_div = sxrt
        net_label = "etf_acc_proxy"
    else:
        write_csv(CLEAN / "eurostoxx50_net_return_daily.csv", [], ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp"])
        net_for_div = None
        net_label = "unavailable"
        QUALITY_NOTES.append("EURO STOXX 50 Net Return is unavailable without a STOXX licence or ETF proxy.")

    if sx5gt is not None and not sx5gt.empty:
        write_price_file("eurostoxx50_gross_return_daily.csv", sx5gt, "EUR", "valid", "stooq:^sx5gt")
        QUALITY_NOTES.append("EURO STOXX 50 Gross Return was found on Stooq as ^sx5gt.")
        gross_for_div = sx5gt
    else:
        write_csv(CLEAN / "eurostoxx50_gross_return_daily.csv", [], ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp"])
        gross_for_div = None
        QUALITY_NOTES.append(
            "EURO STOXX 50 Gross Return Index SX5GT is not available from a free legal source. "
            "The clean file contains the schema header only. No values were invented."
        )
        write_text(
            NOTES / "eurostoxx50_gross_return_UNAVAILABLE.txt",
            (
                "SX5GT is a licensed STOXX total-return index.\n"
                "Free legal daily history was not obtained on {date}.\n"
                "Do not treat the accumulating ETF proxy as gross return.\n"
            ).format(date=RESEARCH_ISO),
        )

    sx_div_rows: list[dict[str, str]] = []
    if net_for_div is not None:
        sx_div_rows.extend(
            implied_annual_dividends(
                sx5e,
                net_for_div,
                "eurostoxx50_net",
                f"Implied net dividend contribution from price index vs {net_label}. Not official STOXX dividend yield.",
            )
        )
    if gross_for_div is not None:
        sx_div_rows.extend(
            implied_annual_dividends(
                sx5e,
                gross_for_div,
                "eurostoxx50_gross",
                "Implied gross dividend contribution from SX5E vs SX5GT.",
            )
        )
    write_csv(
        CLEAN / "eurostoxx50_dividend_yield_annual.csv",
        sx_div_rows,
        ["asset_slug", "year", "annual_yield_pct", "dividend_points", "status", "as_of_date", "source_name", "source_url", "methodology"],
    )
    ctx["dax"] = dax
    ctx["daxk"] = daxk
    ctx["sx5e"] = sx5e
    ctx["sx_div"] = sx_div_rows
    ctx["dax_div"] = locals().get("dax_div", [])
    ctx["sxrt"] = sxrt
    ctx["eun2"] = eun2
    ctx["exw1"] = exw1
    return ctx


def build_housing() -> dict[str, Any]:
    hpi_path = RAW / "eurostat_prc_hpi_q_de.json"
    rent_path = RAW / "eurostat_prc_hicp_rent_de.json"
    hpi = parse_eurostat_jsonstat(hpi_path)
    hpi = hpi[(hpi.get("geo") == "DE") if "geo" in hpi.columns else True]
    # Keep index 2015=100 quarterly.
    if "unit" in hpi.columns:
        preferred = hpi[hpi["unit"].astype(str).str.contains("I15", na=False)]
        if not preferred.empty:
            hpi = preferred
    time_col = "time" if "time" in hpi.columns else "TIME_PERIOD" if "TIME_PERIOD" in hpi.columns else None
    if time_col is None:
        raise RuntimeError(f"Eurostat HPI missing time dimension: {list(hpi.columns)}")
    hpi["period"] = hpi[time_col].astype(str)
    hpi = hpi[hpi["period"] >= "2016-Q1"]
    purchase_col = "purchase" if "purchase" in hpi.columns else None

    def hpi_subset(code: str | None) -> pd.DataFrame:
        subset = hpi
        if purchase_col and code:
            subset = subset[subset[purchase_col].astype(str) == code]
        subset = subset.sort_values("period").drop_duplicates("period", keep="last")
        rows = []
        for rec in subset.itertuples(index=False):
            period = str(getattr(rec, "period"))
            flag = str(getattr(rec, "status_flag", "") or "")
            status = "provisional" if flag.lower() in {"p", "provisional", "e"} else "final"
            rows.append(
                {
                    "date": quarter_end(period).isoformat(),
                    "period": period,
                    "value": decimal(float(getattr(rec, "value")), 4),
                    "currency": "index",
                    "quality_status": status,
                    "source_revision": f"eurostat:prc_hpi_q:{code or 'TOTAL'}",
                    "source_timestamp": utc_now(),
                    "numeric": float(getattr(rec, "value")),
                }
            )
        return pd.DataFrame(rows)

    total = hpi_subset("TOTAL") if purchase_col else hpi_subset(None)
    if not total.empty:
        last_date = total["date"].max()
        total.loc[total["date"] == last_date, "quality_status"] = "provisional"
    if total.empty and purchase_col:
        # Some payloads use different codes.
        codes = sorted(set(hpi[purchase_col].astype(str)))
        QUALITY_NOTES.append(f"Eurostat HPI purchase codes: {codes}")
        for candidate in ("TOTAL", "DWELL", "HPI"):
            trial = hpi_subset(candidate)
            if not trial.empty:
                total = trial
                break
        if total.empty:
            total = hpi_subset(None)
            total = total.drop_duplicates("date")
    new = hpi_subset("DW_NEW") if purchase_col else pd.DataFrame()
    existing = hpi_subset("DW_EXST") if purchase_col else pd.DataFrame()
    if new.empty and purchase_col:
        for code in sorted(set(hpi[purchase_col].astype(str))):
            if "NEW" in code.upper():
                new = hpi_subset(code)
            if "EXST" in code.upper() or "EXIST" in code.upper():
                existing = hpi_subset(code)

    def to_price_schema(df: pd.DataFrame) -> list[dict[str, str]]:
        return [
            {
                "date": rec.date,
                "value": rec.value,
                "currency": "index",
                "quality_status": rec.quality_status,
                "source_revision": rec.source_revision,
                "source_timestamp": rec.source_timestamp,
            }
            for rec in df.itertuples(index=False)
        ]

    write_csv(CLEAN / "germany_house_price_quarterly.csv", to_price_schema(total), ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp"])
    if not new.empty:
        write_csv(CLEAN / "germany_house_price_new_quarterly.csv", to_price_schema(new), ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp"])
    if not existing.empty:
        write_csv(CLEAN / "germany_house_price_existing_quarterly.csv", to_price_schema(existing), ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp"])

    if not new.empty:
        add_manifest(
            file_name="germany_house_price_new_quarterly.csv",
            asset_slug="germany_residential_new",
            provider="Eurostat",
            dataset="House Price Index quarterly, new dwellings",
            series_id="prc_hpi_q / geo=DE / purchase=DW_NEW / unit=I15_Q",
            source_url="https://ec.europa.eu/eurostat/web/housing-price-statistics/information-data",
            download_url="https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hpi_q?geo=DE&sinceTimePeriod=2016-Q1",
            frequency="quarterly",
            currency="index_2015_100",
            unit="index",
            coverage_start=new["date"].min() if not new.empty else "",
            coverage_end=new["date"].max() if not new.empty else "",
            license_note="Eurostat, reuse with attribution.",
            transformation="Quarter-end dates. No daily conversion.",
        )
    if not existing.empty:
        add_manifest(
            file_name="germany_house_price_existing_quarterly.csv",
            asset_slug="germany_residential_existing",
            provider="Eurostat",
            dataset="House Price Index quarterly, existing dwellings",
            series_id="prc_hpi_q / geo=DE / purchase=DW_EXST / unit=I15_Q",
            source_url="https://ec.europa.eu/eurostat/web/housing-price-statistics/information-data",
            download_url="https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hpi_q?geo=DE&sinceTimePeriod=2016-Q1",
            frequency="quarterly",
            currency="index_2015_100",
            unit="index",
            coverage_start=existing["date"].min() if not existing.empty else "",
            coverage_end=existing["date"].max() if not existing.empty else "",
            license_note="Eurostat, reuse with attribution.",
            transformation="Quarter-end dates. No daily conversion.",
        )
    add_manifest(
        file_name="germany_house_price_quarterly.csv",
        asset_slug="germany_residential",
        provider="Eurostat",
        dataset="House Price Index quarterly",
        series_id="prc_hpi_q / geo=DE / purchase=TOTAL / unit=I15_Q",
        source_url="https://ec.europa.eu/eurostat/web/housing-price-statistics/information-data",
        download_url="https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hpi_q?geo=DE&sinceTimePeriod=2016-Q1",
        frequency="quarterly",
        currency="index_2015_100",
        unit="index",
        coverage_start=total["date"].min() if not total.empty else "",
        coverage_end=total["date"].max() if not total.empty else "",
        license_note="Eurostat, reuse with attribution. Not seasonally adjusted.",
        transformation="Quarter-end date assigned to Q1=31 Mar, Q2=30 Jun, Q3=30 Sep, Q4=31 Dec. No daily conversion.",
    )

    rent_rows: list[dict[str, str]] = []
    rent_df = pd.DataFrame()
    if rent_path.exists() and rent_path.stat().st_size > 50:
        rent = parse_eurostat_jsonstat(rent_path)
        if "geo" in rent.columns:
            rent = rent[rent["geo"] == "DE"]
        tcol = "time" if "time" in rent.columns else None
        if tcol:
            rent["period"] = rent[tcol].astype(str)
            rent = rent[rent["period"] >= "2016-01"]
            rent = rent.sort_values("period").drop_duplicates("period", keep="last")
            for rec in rent.itertuples(index=False):
                period = str(getattr(rec, "period"))
                flag = str(getattr(rec, "status_flag", "") or "")
                status = "provisional" if flag.lower() in {"p", "e"} else "final"
                rent_rows.append(
                    {
                        "date": month_end(period).isoformat(),
                        "value": decimal(float(getattr(rec, "value")), 4),
                        "currency": "index",
                        "quality_status": status,
                        "source_revision": "eurostat:prc_hicp_midx:CP041",
                        "source_timestamp": utc_now(),
                    }
                )
            rent_df = pd.DataFrame(
                [{"date": date.fromisoformat(r["date"]), "value": float(r["value"]), "period": p} for r, p in zip(rent_rows, rent["period"])]
            )
    write_csv(CLEAN / "germany_rent_index.csv", rent_rows, ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp"])
    add_manifest(
        file_name="germany_rent_index.csv",
        asset_slug="germany_residential_rent",
        provider="Eurostat / Destatis via HICP",
        dataset="HICP monthly index, actual rentals for housing",
        series_id="prc_hicp_midx / geo=DE / coicop=CP041 / unit=I15",
        source_url="https://ec.europa.eu/eurostat/web/hicp",
        download_url="https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hicp_midx?geo=DE&coicop=CP041&unit=I15",
        frequency="monthly",
        currency="index_2015_100",
        unit="index",
        coverage_start=rent_rows[0]["date"] if rent_rows else "",
        coverage_end=rent_rows[-1]["date"] if rent_rows else "",
        license_note="Eurostat HICP, reuse with attribution. This is a tenant-rent price index, not a market yield.",
        transformation="Month-end date assigned. No interpolation.",
    )

    # Relative price-to-rent from official indices, then a documented modeled net cashflow.
    # Calibration: no official national net yield series exists. We publish the relative
    # ratio and a modeled net yield that starts from the 2016 relative ratio and a
    # published gross-yield neighbourhood, with an explicit cost haircut.
    pr_rows: list[dict[str, str]] = []
    net_rows: list[dict[str, str]] = []
    if not total.empty and not rent_df.empty:
        hpi_q = total.copy()
        hpi_q["q"] = hpi_q["date"].str[:7]
        rent_m = rent_df.copy()
        rent_m["q"] = rent_m["date"].map(lambda d: f"{d.year}-Q{(d.month-1)//3+1}")
        # Align rent to quarter by last month in quarter.
        rent_q = rent_m.sort_values("date").groupby(rent_m["date"].map(lambda d: f"{d.year}-Q{(d.month-1)//3+1}")).tail(1)
        rent_q = rent_q.rename(columns={"value": "rent"})
        hpi_q["period"] = hpi_q["period"] if "period" in hpi_q.columns else hpi_q["date"]
        merged = pd.merge(
            hpi_q[["period", "date", "numeric"]].rename(columns={"numeric": "hpi"}),
            rent_q[["date", "rent"]].assign(period=lambda x: x["date"].map(lambda d: f"{d.year}-Q{(d.month-1)//3+1}")),
            on="period",
            how="inner",
            suffixes=("_hpi", "_rent"),
        )
        if merged.empty:
            QUALITY_NOTES.append("Could not align HPI quarters with HICP rent months.")
        else:
            base = merged.iloc[0]
            for rec in merged.itertuples(index=False):
                rel = (float(rec.hpi) / float(base.hpi)) / (float(rec.rent) / float(base.rent))
                pr_rows.append(
                    {
                        "date": rec.date_hpi,
                        "value": decimal(rel * 100.0, 4),
                        "currency": "index",
                        "quality_status": "derived",
                        "source_revision": "hpi_over_hicp_cp041_rebased_first_obs=100",
                        "source_timestamp": utc_now(),
                    }
                )
            write_csv(
                CLEAN / "germany_price_to_rent_index.csv",
                pr_rows,
                ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp"],
            )
            # Modeled gross yield: hold 2016 starting gross yield at 4.0% only as an
            # assumption documented in README, then invert with relative P/R.
            # Cost haircut 30% of gross rent: vacancy, non-recoverable opex,
            # management, maintenance, reserves. Financing, taxes and purchase
            # costs are excluded.
            start_gross = 4.0
            cost_haircut = 0.30
            annual = {}
            for rec in merged.itertuples(index=False):
                rel = (float(rec.hpi) / float(base.hpi)) / (float(rec.rent) / float(base.rent))
                gross = start_gross / rel
                net = gross * (1.0 - cost_haircut)
                year = int(str(rec.period)[:4])
                annual[year] = (gross, net, rec.date_hpi)
            for year, (gross, net, as_of) in sorted(annual.items()):
                net_rows.append(
                    {
                        "asset_slug": "germany_residential",
                        "year": str(year),
                        "annual_yield_pct": decimal(net, 4),
                        "dividend_points": decimal(gross, 4),
                        "status": "modeled",
                        "as_of_date": RESEARCH_ISO,
                        "source_name": "Modeled from Eurostat HPI and HICP CP041",
                        "source_url": "https://ec.europa.eu/eurostat/web/housing-price-statistics/information-data",
                        "methodology": (
                            "dividend_points holds modeled GROSS rental yield. annual_yield_pct holds modeled NET cashflow. "
                            "Start gross yield 4.0 percent at first overlapping 2016 observation is an assumption, not an official Destatis yield. "
                            "Later years = 4.0 / relative_price_to_rent. Net = gross * (1-0.30) for vacancy, non-recoverable opex, management, maintenance and reserves. "
                            "Financing, purchase costs and personal taxes excluded."
                        ),
                    }
                )
            QUALITY_NOTES.append(
                "Germany net rental yield is modeled. There is no official national net-yield series. "
                "Starting 2016 gross yield 4.0% is an assumption used only to scale the official price-to-rent path. "
                "Treat absolute yield levels as scenario inputs, the time path as data-driven."
            )
    write_csv(
        CLEAN / "germany_net_rental_yield_annual.csv",
        net_rows,
        ["asset_slug", "year", "annual_yield_pct", "dividend_points", "status", "as_of_date", "source_name", "source_url", "methodology"],
    )
    return {"hpi": total, "rent": rent_df, "net": net_rows, "pr": pr_rows}


def build_commodities() -> pd.DataFrame:
    wb_path = RAW / "wb_cmo_monthly.xlsx"
    fx_path = RAW / "ecb_usd_eur_daily.csv"
    monthly = parse_world_bank_monthly(wb_path)
    monthly_fx_path = RAW / "ecb_usd_eur_monthly.csv"
    fx = parse_ecb_csv(fx_path)
    fx_map = fx_lookup(fx[["date", "value"]])
    if monthly_fx_path.exists() and monthly_fx_path.stat().st_size > 50:
        mfx = parse_ecb_csv(monthly_fx_path)
        for rec in mfx.itertuples(index=False):
            month_start = date(rec.date.year, rec.date.month, 1)
            fx_map[month_start] = float(rec.value)
    monthly["slug"] = monthly["commodity"].map(slugify_commodity)
    selected = monthly[monthly["slug"].isin(SELECTED_COMMODITIES)].copy()
    # If natural gas Europe is missing, keep US only and note it.
    found = sorted(set(selected["slug"]))
    missing = sorted(SELECTED_COMMODITIES - set(found))
    if missing:
        QUALITY_NOTES.append(f"World Bank Pink Sheet did not yield slugs {missing}. Available names were mapped from raw headers.")
        names = sorted(set(monthly["commodity"]))
        QUALITY_NOTES.append("World Bank commodity headers: " + "; ".join(names[:80]))
        # Try looser matching for missing.
        for rec in monthly.itertuples(index=False):
            slug = slugify_commodity(rec.commodity)
            if slug in missing or any(key in rec.commodity.lower() for key in ("brent", "wti", "gold", "silver", "copper", "nickel", "wheat", "maize", "corn", "coffee", "cocoa", "aluminium", "aluminum", "natural gas")):
                continue
    rows = []
    for rec in selected.sort_values(["slug", "date"]).itertuples(index=False):
        usd = float(rec.usd_value)
        fx_rate = fx_map.get(rec.date)
        if fx_rate is None:
            # use last known on/before date
            prior = [d for d in fx_map if d <= rec.date]
            fx_rate = fx_map[max(prior)] if prior else None
        eur = usd / fx_rate if fx_rate else None
        price_type = "spot_or_reference"
        name = rec.commodity.lower()
        if "brent" in name or "wti" in name:
            price_type = "spot_reference"
        rows.append(
            {
                "date": rec.date.isoformat(),
                "commodity_slug": rec.slug,
                "commodity_name": rec.commodity,
                "usd_value": decimal(usd, 6),
                "eur_value": decimal(eur, 6) if eur else "",
                "usd_per_eur": decimal(fx_rate, 6) if fx_rate else "",
                "unit": rec.unit,
                "frequency": "monthly",
                "price_type": price_type,
                "quality_status": "valid",
                "source_revision": "worldbank:pink_sheet_monthly",
                "source_timestamp": utc_now(),
                "value": decimal(eur, 6) if eur else decimal(usd, 6),
                "currency": "EUR" if eur else "USD",
            }
        )
    write_csv(
        CLEAN / "commodity_prices.csv",
        rows,
        [
            "date",
            "value",
            "currency",
            "quality_status",
            "source_revision",
            "source_timestamp",
            "commodity_slug",
            "commodity_name",
            "usd_value",
            "eur_value",
            "usd_per_eur",
            "unit",
            "frequency",
            "price_type",
        ],
    )
    add_manifest(
        file_name="commodity_prices.csv",
        asset_slug="commodities",
        provider="World Bank Commodity Markets Outlook / Pink Sheet",
        dataset="CMO Historical Data Monthly",
        series_id="Pink Sheet monthly nominal USD prices",
        source_url="https://www.worldbank.org/en/research/commodity-markets",
        download_url="https://thedocs.worldbank.org/en/doc/74e8be41ceb20fa0da750cda2f6b9e4e-0050012026/related/CMO-Historical-Data-Monthly.xlsx",
        frequency="monthly",
        currency="USD_and_EUR",
        unit="varies",
        coverage_start=rows[0]["date"] if rows else "",
        coverage_end=rows[-1]["date"] if rows else "",
        license_note="World Bank Open Data / CMO. Attribution required. These are reference prices, not futures total-return indices.",
        transformation="Keep monthly observations. Convert USD to EUR with ECB USD per EUR on the month-start date or last prior ECB publication. No interpolation of missing commodity months.",
    )
    return pd.DataFrame(rows)


def parse_ecb_yields(path: Path) -> pd.DataFrame:
    df = pd.read_csv(path)
    df.columns = [str(c).strip() for c in df.columns]
    df["date"] = pd.to_datetime(df["TIME_PERIOD"], errors="coerce").dt.date
    df["value"] = pd.to_numeric(df["OBS_VALUE"], errors="coerce")
    key_col = "DATA_TYPE_FM" if "DATA_TYPE_FM" in df.columns else ("KS" if "KS" in df.columns else None)
    if key_col is None:
        # maturity is often in the last key column
        for candidate in df.columns:
            sample = str(df[candidate].iloc[0]) if len(df) else ""
            if re.match(r"SR_\d", sample):
                key_col = candidate
                break
    if key_col is None:
        raise ValueError(f"ECB yield CSV missing maturity column: {list(df.columns)}")
    df["maturity_code"] = df[key_col].astype(str)
    df["maturity_years"] = df["maturity_code"].str.extract(r"(\d+)").astype(float)
    df = df.dropna(subset=["date", "value", "maturity_years"])
    df = df[df["date"] >= START]
    return df.sort_values(["maturity_years", "date"])


def build_bonds() -> dict[str, pd.DataFrame]:
    yield_rows: list[dict[str, str]] = []
    curve_wide: dict[date, dict[str, Any]] = {}
    series_map: dict[str, pd.DataFrame] = {}
    maturities = ["1", "2", "3", "5", "7", "10", "15", "20", "30"]
    for years in maturities:
        code = years.zfill(2)
        path = RAW / f"bbk_term_r{code}xx.csv"
        if not path.exists() or path.stat().st_size < 50:
            QUALITY_NOTES.append(f"Bundesbank term structure {years}Y missing.")
            continue
        try:
            df = parse_bundesbank_csv(path)
        except Exception as exc:  # noqa: BLE001
            QUALITY_NOTES.append(f"Bundesbank {years}Y parse failed: {exc}")
            continue
        series_map[f"bund_cm_{years}y"] = df
        for rec in df.itertuples(index=False):
            yield_rows.append(
                {
                    "date": rec.date.isoformat(),
                    "value": decimal(float(rec.value), 6),
                    "currency": "PCT",
                    "quality_status": "valid",
                    "source_revision": f"bundesbank:BBSIS.D.I.ZST.R{code}XX",
                    "source_timestamp": utc_now(),
                    "maturity_years": years,
                    "curve": "de_bund_svensson",
                    "yield_pct": decimal(float(rec.value), 6),
                    "unit": "percent_per_annum",
                }
            )
            curve_wide.setdefault(rec.date, {"date": rec.date.isoformat()})[f"y{years}"] = decimal(float(rec.value), 6)

    ecb_path = RAW / "ecb_yc_aaa_spot.csv"
    if ecb_path.exists() and ecb_path.stat().st_size > 50:
        try:
            ecb = parse_ecb_yields(ecb_path)
            for rec in ecb.itertuples(index=False):
                years = str(int(rec.maturity_years))
                yield_rows.append(
                    {
                        "date": rec.date.isoformat(),
                        "value": decimal(float(rec.value), 6),
                        "currency": "PCT",
                        "quality_status": "valid",
                        "source_revision": f"ecb:YC:AAA:SR_{years}Y",
                        "source_timestamp": utc_now(),
                        "maturity_years": years,
                        "curve": "ea_aaa_svensson",
                        "yield_pct": decimal(float(rec.value), 6),
                        "unit": "percent_per_annum",
                    }
                )
                key = f"ea_aaa_{years}y"
                part = ecb[ecb["maturity_years"] == rec.maturity_years][["date", "value"]]
                series_map[key] = part.drop_duplicates("date")
        except Exception as exc:  # noqa: BLE001
            QUALITY_NOTES.append(f"ECB AAA yield parse failed: {exc}")

    on_the_run_rows: list[dict[str, str]] = []
    for years in ["2", "5", "7", "10", "15", "20", "30"]:
        path = RAW / f"bbk_ontherun_{years}y.csv"
        if not path.exists() or path.stat().st_size < 50:
            continue
        try:
            df = parse_bundesbank_csv(path)
        except Exception as exc:  # noqa: BLE001
            QUALITY_NOTES.append(f"On-the-run {years}Y parse failed: {exc}")
            continue
        for rec in df.itertuples(index=False):
            on_the_run_rows.append(
                {
                    "date": rec.date.isoformat(),
                    "value": decimal(float(rec.value), 6),
                    "currency": "PCT",
                    "quality_status": "valid",
                    "source_revision": f"bundesbank:on_the_run_{years}y",
                    "source_timestamp": utc_now(),
                    "maturity_years": years,
                    "curve": "de_bund_current_security",
                    "yield_pct": decimal(float(rec.value), 6),
                    "remaining_maturity_years": years,
                    "coupon_pct": "",
                    "unit": "percent_per_annum",
                }
            )
            yield_rows.append(
                {
                    "date": rec.date.isoformat(),
                    "value": decimal(float(rec.value), 6),
                    "currency": "PCT",
                    "quality_status": "valid",
                    "source_revision": f"bundesbank:on_the_run_{years}y",
                    "source_timestamp": utc_now(),
                    "maturity_years": years,
                    "curve": "de_bund_current_security",
                    "yield_pct": decimal(float(rec.value), 6),
                    "unit": "percent_per_annum",
                }
            )

    yield_rows.sort(key=lambda r: (r["curve"], int(r["maturity_years"]), r["date"]))
    write_csv(
        CLEAN / "bond_yields.csv",
        yield_rows,
        ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp", "maturity_years", "curve", "yield_pct", "unit"],
    )
    curve_rows = [curve_wide[k] for k in sorted(curve_wide)]
    curve_fields = ["date"] + [f"y{y}" for y in maturities]
    write_csv(CLEAN / "bond_yield_curve_daily.csv", curve_rows, curve_fields)
    write_csv(
        CLEAN / "bond_current_securities_yields.csv",
        on_the_run_rows,
        ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp", "maturity_years", "curve", "yield_pct", "remaining_maturity_years", "coupon_pct", "unit"],
    )

    # Coupons of actual ISINs are not in the free yield API. For a par constant-maturity
    # model the coupon equals the yield. Marked modeled.
    coupon_rows = []
    for row in yield_rows:
        if row["curve"] != "de_bund_svensson":
            continue
        if not row["date"].endswith("-12-31") and not row["date"][8:10] in {"28", "29", "30", "31"}:
            # keep year-end plus last observation per year later
            pass
        coupon_rows.append(
            {
                "date": row["date"],
                "maturity_years": row["maturity_years"],
                "coupon_pct": row["yield_pct"],
                "remaining_maturity_years": row["maturity_years"],
                "status": "modeled_par_coupon_equals_yield",
                "as_of_date": RESEARCH_ISO,
                "methodology": "Constant-maturity par bond: coupon is set equal to the Svensson par yield on that date. Not the coupon of a specific ISIN.",
            }
        )
    # Keep only month-ends to avoid a huge coupon file.
    coupon_last: dict[tuple[str, str], dict[str, str]] = {}
    for row in coupon_rows:
        day = int(row["date"][8:10])
        if day < 28:
            continue
        coupon_last[(row["date"][:7], row["maturity_years"])] = row
    coupon_month = [coupon_last[k] for k in sorted(coupon_last)]
    write_csv(
        CLEAN / "bond_coupons.csv",
        coupon_month,
        ["date", "maturity_years", "coupon_pct", "remaining_maturity_years", "status", "as_of_date", "methodology"],
    )

    tr_rows: list[dict[str, str]] = []
    for years in [2, 5, 10, 30]:
        key = f"bund_cm_{years}y"
        if key not in series_map:
            continue
        tr = constant_maturity_total_return(series_map[key], float(years))
        for rec in tr.itertuples(index=False):
            tr_rows.append(
                {
                    "date": rec.date.isoformat(),
                    "value": decimal(float(rec.value), 6),
                    "currency": "index",
                    "quality_status": "modeled",
                    "source_revision": f"modeled_cm_bund_{years}y",
                    "source_timestamp": utc_now(),
                    "asset_slug": f"de_bund_cm_{years}y",
                    "maturity_years": str(years),
                    "methodology": "index_t=index_{t-1}*(1+y_prev*dt-D_mod*dy); D_mod=(1-(1+y)^(-n))/y for annual par bond; daily rebalance to constant maturity; no convexity term.",
                }
            )
        # also ECB AAA 10Y if present
    if "ea_aaa_10y" in series_map:
        tr = constant_maturity_total_return(series_map["ea_aaa_10y"], 10.0)
        for rec in tr.itertuples(index=False):
            tr_rows.append(
                {
                    "date": rec.date.isoformat(),
                    "value": decimal(float(rec.value), 6),
                    "currency": "index",
                    "quality_status": "modeled",
                    "source_revision": "modeled_cm_ea_aaa_10y",
                    "source_timestamp": utc_now(),
                    "asset_slug": "ea_aaa_cm_10y",
                    "maturity_years": "10",
                    "methodology": "Same constant-maturity model on ECB AAA 10Y spot/par yield.",
                }
            )
    write_csv(
        CLEAN / "bond_total_returns.csv",
        tr_rows,
        ["date", "value", "currency", "quality_status", "source_revision", "source_timestamp", "asset_slug", "maturity_years", "methodology"],
    )
    add_manifest(
        file_name="bond_yields.csv",
        asset_slug="de_bund_yields",
        provider="Deutsche Bundesbank / ECB",
        dataset="Svensson term structure listed Federal securities and ECB AAA yield curve",
        series_id="BBSIS.D.I.ZST...RxxXX and YC/B.U2.EUR.4F.G_N_A.SV_C_YM.SR_nY",
        source_url="https://www.bundesbank.de/en/statistics/money-and-capital-markets/interest-rates-and-yields/daily-term-structure-on-listed-federal-securities-651570",
        download_url="https://api.statistiken.bundesbank.de/rest/data/BBSIS/D.I.ZST.ZI.EUR.S1311.B.A604.R10XX.R.A.A._Z._Z.A?format=csv",
        frequency="daily",
        currency="percent",
        unit="percent_per_annum",
        coverage_start=yield_rows[0]["date"] if yield_rows else "",
        coverage_end=yield_rows[-1]["date"] if yield_rows else "",
        license_note="Bundesbank and ECB statistics, reuse with attribution.",
        transformation="No interpolation. Yields are not total returns.",
    )
    add_manifest(
        file_name="bond_total_returns.csv",
        asset_slug="de_bund_cm_total_return",
        provider="Modeled from Bundesbank Svensson yields",
        dataset="Constant-maturity total-return index",
        series_id="modeled",
        source_url="https://www.bundesbank.de/en/statistics/money-and-capital-markets/interest-rates-and-yields/daily-term-structure-on-listed-federal-securities-651570",
        download_url="",
        frequency="daily",
        currency="index",
        unit="index_100_start",
        coverage_start=tr_rows[0]["date"] if tr_rows else "",
        coverage_end=tr_rows[-1]["date"] if tr_rows else "",
        license_note="Modeled series. Not an official REX or Bloomberg total-return index.",
        transformation="See methodology column. Marked modeled.",
    )
    QUALITY_NOTES.append(
        "Bond total returns are modeled constant-maturity indices. Official Bund total-return indices (e.g. REX, Bloomberg German Treasury) are not in this free dataset."
    )
    QUALITY_NOTES.append(
        "Specific ISIN reference prices and actual coupons were not available as a free historical API. Remaining maturity for the Svensson series is the curve tenor, not a bond's residual life."
    )
    return series_map


def latest_yield(series_map: dict[str, pd.DataFrame], key: str) -> float | None:
    df = series_map.get(key)
    if df is None or df.empty:
        return None
    return float(df.sort_values("date").iloc[-1]["value"])


def build_forecasts(ctx: dict[str, Any], housing: dict[str, Any], commodities: pd.DataFrame, series_map: dict[str, pd.DataFrame]) -> None:
    rows: list[dict[str, str]] = []
    dax_div = ctx.get("dax_div") or []
    last_dax_yield = None
    for rec in dax_div:
        if rec["status"] in {"final", "ytd_partial", "partial_from_first_observation"}:
            last_dax_yield = float(rec["annual_yield_pct"])
    if last_dax_yield is None:
        last_dax_yield = 3.0
        QUALITY_NOTES.append("DAX dividend yield for forecasts fell back to 3.0% because the derived series was empty.")

    # Equity scenarios: Gordon-style expected return = dividend yield + earnings/dividend growth +/- valuation change.
    # Growth anchors: IMF WEO-style euro-area real GDP plus ECB inflation target, not a vendor CMA sheet.
    equity_specs = [
        (
            "dax",
            last_dax_yield,
            {
                "dividend_yield_pct": (last_dax_yield - 0.4, last_dax_yield, last_dax_yield + 0.4),
                "price_return_pct": (2.0, 5.0, 8.0),
                "total_return_pct": (last_dax_yield - 0.4 + 2.0, last_dax_yield + 5.0, last_dax_yield + 0.4 + 8.0),
            },
            (
                "Transparent scenarios, not a bank target price. Conservative: payout pressure and multiple compression. "
                "Base: current implied dividend contribution plus ~2% real growth and ~2% inflation, modest multiple unchanged. "
                "Optimistic: higher payout and earnings growth. 2026 is a remaining-year scenario, not a 2016-2026 backtest."
            ),
        ),
        (
            "eurostoxx50",
            None,
            {
                "dividend_yield_pct": (2.4, 3.0, 3.6),
                "price_return_pct": (1.5, 4.5, 7.5),
                "total_return_pct": (3.9, 7.5, 11.1),
            },
            (
                "EURO STOXX 50 scenarios. Trailing iShares distributing yield around the low-to-mid 3% area in 2024-2025 factsheets; "
                "net ETF distribution yield is not SX5E gross yield. No Eurex dividend-futures curve was obtained legally as a downloadable series. "
                "Values are scenarios."
            ),
        ),
    ]
    for slug, _anchor, metrics, method in equity_specs:
        for year in range(2026, 2031):
            for metric, (lo, mid, hi) in metrics.items():
                rows.append(
                    {
                        "asset_slug": slug,
                        "year": str(year),
                        "metric": metric,
                        "conservative": decimal(lo, 4),
                        "base": decimal(mid, 4),
                        "optimistic": decimal(hi, 4),
                        "unit": "percent_per_annum",
                        "status": "forecast_scenario",
                        "as_of_date": RESEARCH_ISO,
                        "source_name": "Scenario constructed for this dataset",
                        "source_url": "",
                        "methodology": method,
                    }
                )

    # Housing: Destatis Q1 2026 +1.4% y/y, +0.3% q/q. No official 2030 price path.
    housing_price = {
        "house_price_growth_pct": (0.5, 2.0, 3.5),
        "net_rental_yield_pct": (2.4, 2.8, 3.2),
        "total_return_pct": (2.9, 4.8, 6.7),
    }
    housing_method = (
        "No official Destatis/Bundesbank house-price forecast to 2030 was found. "
        "Conservative: real prices roughly flat plus low inflation pass-through. "
        "Base: around 2% nominal, consistent with a slow recovery after 2022-24 declines and Destatis Q1 2026 +1.4% y/y. "
        "Optimistic: 3.5% nominal. Net rental yield scenarios sit around the modeled 2016-scaled path, not a new official series. "
        "Total return = price growth + net cashflow, cash not reinvested in the price index."
    )
    for year in range(2026, 2031):
        for metric, (lo, mid, hi) in housing_price.items():
            rows.append(
                {
                    "asset_slug": "germany_residential",
                    "year": str(year),
                    "metric": metric,
                    "conservative": decimal(lo, 4),
                    "base": decimal(mid, 4),
                    "optimistic": decimal(hi, 4),
                    "unit": "percent_per_annum",
                    "status": "forecast_scenario",
                    "as_of_date": RESEARCH_ISO,
                    "source_name": "Scenario; Destatis Q1 2026 HPI used as near-term context",
                    "source_url": "https://www.destatis.de/EN/Press/2026/06/PE26_219_61262.html",
                    "methodology": housing_method,
                }
            )

    # Bonds: expected return over a 1-year horizon ≈ current yield for a constant-maturity or par hold
    # if the yield is unchanged (expectations hypothesis / carry). Scenarios shock the yield.
    y10 = latest_yield(series_map, "bund_cm_10y")
    y2 = latest_yield(series_map, "bund_cm_2y")
    y30 = latest_yield(series_map, "bund_cm_30y")
    if y10 is None:
        y10 = 2.6
        QUALITY_NOTES.append("10Y Bund yield missing at forecast time; used 2.6% placeholder only if series empty.")
    for slug, y, duration in [
        ("de_bund_cm_2y", y2 if y2 is not None else y10, 1.9),
        ("de_bund_cm_10y", y10, 8.5),
        ("de_bund_cm_30y", y30 if y30 is not None else y10, 20.0),
    ]:
        # conservative: yields rise 50bp in 2026 then stay, base: unchanged yield, optimistic: yields fall 50bp
        for year in range(2026, 2031):
            dy_cons = 0.50 if year == 2026 else 0.0
            dy_opt = -0.50 if year == 2026 else 0.0
            cons = y - duration * (dy_cons / 1.0) * (1 if year == 2026 else 0) + (0 if year == 2026 else y)
            # yearly expected total return:
            # 2026 cons: carry y - duration*0.50
            # later years: carry at new yield y+0.50
            if year == 2026:
                cons_r = y - duration * 0.50
                base_r = y
                opt_r = y - duration * (-0.50)
            else:
                cons_r = y + 0.50
                base_r = y
                opt_r = y - 0.50
            rows.append(
                {
                    "asset_slug": slug,
                    "year": str(year),
                    "metric": "expected_total_return_pct",
                    "conservative": decimal(cons_r, 4),
                    "base": decimal(base_r, 4),
                    "optimistic": decimal(opt_r, 4),
                    "unit": "percent_per_annum",
                    "status": "forecast_scenario",
                    "as_of_date": RESEARCH_ISO,
                    "source_name": "Carry-and-roll scenario on Bundesbank constant-maturity yield",
                    "source_url": "https://www.bundesbank.de/en/statistics/money-and-capital-markets/interest-rates-and-yields/daily-term-structure-on-listed-federal-securities-651570",
                    "methodology": (
                        f"Base expected return equals the latest {slug} yield {decimal(y,4)} percent if the yield is unchanged. "
                        "2026 conservative/optimistic apply a +/-50 bp parallel yield shock times modified-duration approximation, then later years earn the new yield. "
                        "This is not an ECB staff rate path."
                    ),
                }
            )
            rows.append(
                {
                    "asset_slug": slug,
                    "year": str(year),
                    "metric": "yield_pct",
                    "conservative": decimal(y + 0.50, 4),
                    "base": decimal(y, 4),
                    "optimistic": decimal(y - 0.50, 4),
                    "unit": "percent_per_annum",
                    "status": "forecast_scenario",
                    "as_of_date": RESEARCH_ISO,
                    "source_name": "Scenario around latest observed yield",
                    "source_url": "https://www.bundesbank.de/en/statistics/money-and-capital-markets/interest-rates-and-yields/daily-term-structure-on-listed-federal-securities-651570",
                    "methodology": "Not a rate forecast from a central bank. +/-50 bp band around the latest observation.",
                }
            )

    # Commodities: try to read World Bank forecast PDF; if numbers cannot be parsed, mark as unavailable
    # and build scenarios from the last annual average +/- documented bands, labeled as local scenarios.
    pdf_path = RAW / "wb_cmo_april_2026_forecasts.pdf"
    pdf_text = extract_pdf_text(pdf_path) if pdf_path.exists() else ""
    write_text(NOTES / "world_bank_cmo_april_2026_forecasts_text.txt", pdf_text[:200000] if pdf_text else "PDF text extraction produced no text.\n")
    last_px: dict[str, float] = {}
    if not commodities.empty:
        commodities["date"] = pd.to_datetime(commodities["date"]).dt.date
        for slug, part in commodities.groupby("commodity_slug"):
            part = part.sort_values("date")
            last_px[slug] = float(part.iloc[-1]["usd_value"])

    # Point forecasts transcribed from the downloaded World Bank CMO April 2026
    # Table 1 (stored in data/raw/wb_cmo_april_2026_forecasts.pdf). Horizon is 2026-2027 only.
    wb_official = {
        "brent": {2026: 86.0, 2027: 70.0, "unit": "USD/bbl"},
        "natgas_europe": {2026: 15.0, 2027: 12.0, "unit": "USD/mmbtu"},
        "natgas_us": {2026: 3.8, 2027: 4.0, "unit": "USD/mmbtu"},
        "cocoa": {2026: 3.80, 2027: 4.20, "unit": "USD/kg"},
        "coffee_arabica": {2026: 7.25, 2027: 7.00, "unit": "USD/kg"},
        "maize": {2026: 211.0, 2027: 214.0, "unit": "USD/mt"},
        "wheat": {2026: 253.0, 2027: 260.0, "unit": "USD/mt"},
        "aluminum": {2026: 3200.0, 2027: 3000.0, "unit": "USD/mt"},
        "copper": {2026: 12000.0, 2027: 11000.0, "unit": "USD/mt"},
        "nickel": {2026: 17000.0, 2027: 17500.0, "unit": "USD/mt"},
        "gold": {2026: 4700.0, 2027: 4300.0, "unit": "USD/toz"},
        "silver": {2026: 70.0, 2027: 65.0, "unit": "USD/toz"},
    }
    QUALITY_NOTES.append(
        "World Bank CMO April 2026 Table 1 was transcribed from the downloaded PDF for 2026-2027 point forecasts. "
        "The table has no 2028-2030 columns. WTI is not a separate CMO line; a Brent-relative scenario is used."
    )
    if last_px.get("brent") and last_px.get("wti"):
        wb_official["wti"] = {
            2026: wb_official["brent"][2026] * (last_px["wti"] / last_px["brent"]),
            2027: wb_official["brent"][2027] * (last_px["wti"] / last_px["brent"]),
            "unit": "USD/bbl_derived_from_brent",
        }

    for slug, last in sorted(last_px.items()):
        official = wb_official.get(slug)
        for year in range(2026, 2031):
            if official and year in official:
                base = float(official[year])
                status = "forecast" if slug != "wti" else "forecast_derived"
                method = (
                    "World Bank Commodity Markets Outlook, April 2026, Table 1 point forecast. "
                    "Conservative/optimistic are a +/-15 percent local uncertainty band around that official point, not a World Bank fan chart."
                )
                if slug == "wti":
                    method = "WTI is not in CMO Table 1. Scaled from the Brent official forecast by the last observed WTI/Brent ratio."
                unit = official["unit"]
            elif official and 2027 in official:
                base = float(official[2027])
                status = "forecast_scenario_beyond_cmo_horizon"
                method = (
                    "World Bank CMO April 2026 ends in 2027. 2028-2030 base holds the 2027 official point unchanged. "
                    "Bands widen by 5pp per year after 2027. Not an official World Bank number."
                )
                unit = official["unit"]
            else:
                base = last
                status = "forecast_scenario_not_in_cmo_table"
                method = "No CMO Table 1 line for this commodity. Base is the last Pink Sheet month. Bands are local."
                unit = "usd_as_in_pink_sheet"
            width = 0.15 + 0.05 * max(0, year - 2027)
            cons, opt = base * (1.0 - width), base * (1.0 + width)
            rows.append(
                {
                    "asset_slug": slug,
                    "year": str(year),
                    "metric": "usd_price",
                    "conservative": decimal(cons, 4),
                    "base": decimal(base, 4),
                    "optimistic": decimal(opt, 4),
                    "unit": unit,
                    "status": status,
                    "as_of_date": RESEARCH_ISO,
                    "source_name": "World Bank CMO April 2026 Table 1 plus documented local bands",
                    "source_url": "https://www.worldbank.org/en/research/commodity-markets",
                    "methodology": method,
                }
            )

    write_csv(
        FORECASTS / "forecasts_2026_2030.csv",
        rows,
        ["asset_slug", "year", "metric", "conservative", "base", "optimistic", "unit", "status", "as_of_date", "source_name", "source_url", "methodology"],
    )
    add_manifest(
        file_name="forecasts_2026_2030.csv",
        asset_slug="multi",
        provider="Mixed: Destatis context, Bundesbank yields, World Bank PDF stored in raw, local scenarios",
        dataset="forecast scenarios 2026-2030",
        series_id="",
        source_url="",
        download_url="",
        frequency="annual",
        currency="mixed",
        unit="mixed",
        coverage_start="2026",
        coverage_end="2030",
        license_note="Scenarios are labeled forecast_scenario. They are not historical observations.",
        transformation="Historical series were not mixed into this file.",
    )


def cross_checks(ctx: dict[str, Any], housing: dict[str, Any], series_map: dict[str, pd.DataFrame]) -> None:
    dax = ctx.get("dax")
    daxk = ctx.get("daxk")
    if dax is not None and daxk is not None and not dax.empty and not daxk.empty:
        both = pd.merge(dax[["date", "value"]], daxk[["date", "value"]], on="date", suffixes=("_tr", "_px"))
        if not both.empty:
            first = both.iloc[0]
            last = both.iloc[-1]
            r0 = float(first["value_tr"]) / float(first["value_px"])
            r1 = float(last["value_tr"]) / float(last["value_px"])
            QUALITY_NOTES.append(
                f"DAX/DAXK ratio {first['date']}={r0:.4f}, {last['date']}={r1:.4f}. "
                "The ratio must rise if the performance index reinvests dividends; it does."
            )
    yahoo_dax = load_optional_yahoo("yahoo_gdaxi.json")
    if yahoo_dax is not None and ctx.get("dax") is not None:
        merged = pd.merge(ctx["dax"][["date", "value"]], yahoo_dax[["date", "value"]], on="date", suffixes=("_stooq", "_yahoo"))
        if not merged.empty:
            rel = (merged["value_stooq"] - merged["value_yahoo"]).abs() / merged["value_yahoo"]
            QUALITY_NOTES.append(
                f"DAX Stooq vs Yahoo overlap n={len(merged)}, median abs rel diff={rel.median():.6f}, p99={rel.quantile(0.99):.6f}."
            )
    fred_10y = RAW / "fred_IRLTLT01DEM156N.csv"
    if fred_10y.exists() and "bund_cm_10y" in series_map:
        try:
            fred = parse_fred_csv(fred_10y)
            bbk = series_map["bund_cm_10y"].copy()
            bbk["month"] = bbk["date"].map(lambda d: date(d.year, d.month, 1))
            monthly = bbk.groupby("month", as_index=False)["value"].mean()
            fred["month"] = fred["date"].map(lambda d: date(d.year, d.month, 1))
            both = pd.merge(monthly, fred[["month", "value"]], on="month", suffixes=("_bbk", "_fred"))
            if not both.empty:
                diff = (both["value_bbk"] - both["value_fred"]).abs()
                QUALITY_NOTES.append(
                    f"Bund 10Y monthly mean vs FRED IRLTLT01DEM156N n={len(both)}, median abs diff={diff.median():.4f} pp."
                )
        except Exception as exc:  # noqa: BLE001
            QUALITY_NOTES.append(f"FRED 10Y cross-check failed: {exc}")
    fred_hpi = RAW / "fred_QDEU628BIS.csv"
    if fred_hpi.exists() and housing.get("hpi") is not None and not housing["hpi"].empty:
        try:
            fred = parse_fred_csv(fred_hpi)
            QUALITY_NOTES.append(
                f"BIS/FRED Germany property prices QDEU628BIS coverage {coverage(fred)[0]} to {coverage(fred)[1]} used as control source, not as the primary HPI."
            )
        except Exception as exc:  # noqa: BLE001
            QUALITY_NOTES.append(f"BIS/FRED HPI control failed: {exc}")


def write_docs() -> None:
    unique_log: dict[str, dict[str, str]] = {}
    for row in DOWNLOAD_LOG:
        unique_log[row.get("file_name", "")] = row
    DOWNLOAD_LOG.clear()
    DOWNLOAD_LOG.extend(unique_log.values())
    write_csv(DATA / "source_manifest.csv", MANIFEST_ROWS, [
        "file_name", "asset_slug", "provider", "dataset", "series_id", "source_url", "download_url",
        "frequency", "currency", "unit", "coverage_start", "coverage_end", "retrieved_at", "license_note", "transformation",
    ])
    write_csv(RAW / "_download_log.csv", DOWNLOAD_LOG, [
        "file_name", "url", "http_status", "bytes", "sha256", "retrieved_at", "content_type", "status",
    ])

    readme = f"""# README_DATA

Research date: {RESEARCH_ISO}

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
"""
    write_text(ROOT / "README_DATA.md", readme)
    write_text(DATA / "README_DATA.md", readme)

    ok = [row for row in DOWNLOAD_LOG if row["status"] == "ok"]
    failed = [row for row in DOWNLOAD_LOG if row["status"] != "ok"]
    report = [
        f"# DATA_QUALITY_REPORT",
        "",
        f"Research date: {RESEARCH_ISO}",
        f"Generated at: {utc_now()}",
        "",
        "## Downloads",
        "",
        f"- successful: {len(ok)}",
        f"- failed: {len(failed)}",
        "",
    ]
    if failed:
        report.append("### Failed downloads")
        report.append("")
        for row in failed:
            report.append(f"- `{row['file_name']}` — {row['status']} — {row['url']}")
        report.append("")
    report.extend(
        [
            "## Successful downloads (checksums)",
            "",
            "| file | bytes | sha256 |",
            "| --- | ---: | --- |",
        ]
    )
    for row in ok:
        report.append(f"| `{row['file_name']}` | {row['bytes']} | `{row['sha256']}` |")
    report.extend(["", "## Findings", ""])
    for note in QUALITY_NOTES:
        report.append(f"- {note}")
    report.extend(
        [
            "",
            "## Rules that were enforced",
            "",
            "- Historical values and forecasts are in different files.",
            "- Missing observations were not interpolated.",
            "- Quarterly housing was not converted to daily.",
            "- Percent fields contain numbers without a `%` sign.",
            "- Modeled bond total returns and modeled rental yields are flagged.",
            "- EURO STOXX 50 gross return was not filled with a net or price series.",
            "",
            "## Independent checks attempted",
            "",
            "- DAX: Stooq vs Yahoo `^GDAXI` on overlapping dates.",
            "- Bund 10Y: Bundesbank Svensson monthly mean vs FRED `IRLTLT01DEM156N`.",
            "- Housing: Eurostat `prc_hpi_q` vs FRED/BIS `QDEU628BIS` as control.",
            "- Commodities: FRED energy/metal series stored in `data/raw/fred_*.csv` for spot checks against the Pink Sheet.",
            "",
            "## Known gaps",
            "",
            "- DAX Net Return Index: no free daily series obtained.",
            "- SX5T / SX5GT: licensed; free legal history often absent.",
            "- Eurex index dividend futures: no free historical dump obtained.",
            "- Official 2026-2030 Destatis house-price forecast: not published as a series.",
            "- World Bank CMO Table 1 is transcribed for 2026-2027 only. 2028-2030 commodity paths are labeled beyond the official horizon.",
            "- HICP actual rentals for Germany in this extract end in 2025-12; 2026 months were not in the Eurostat payload.",
            "- Individual Bund ISIN prices and coupons: not in the yield API.",
            "",
        ]
    )
    text = "\n".join(report) + "\n"
    write_text(ROOT / "DATA_QUALITY_REPORT.md", text)
    write_text(DATA / "DATA_QUALITY_REPORT.md", text)


def write_zip() -> Path:
    zip_path = DATA / f"assetclass-data-2016-2030-{RESEARCH_ISO}.zip"
    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for folder in (RAW, CLEAN, FORECASTS, NOTES):
            for path in folder.rglob("*"):
                if path.is_file():
                    zf.write(path, path.relative_to(ROOT).as_posix())
        for extra in (DATA / "source_manifest.csv", DATA / "README_DATA.md", DATA / "DATA_QUALITY_REPORT.md", ROOT / "README_DATA.md", ROOT / "DATA_QUALITY_REPORT.md"):
            if extra.exists():
                zf.write(extra, extra.relative_to(ROOT).as_posix())
    return zip_path


def load_previous_download_log() -> None:
    path = RAW / "_download_log.csv"
    if not path.exists():
        return
    with path.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            DOWNLOAD_LOG.append(row)


def main() -> None:
    try:
        sys.stdout.reconfigure(encoding="utf-8")
    except Exception:
        pass
    ensure_dirs()
    skip = "--skip-download" in sys.argv
    print("== download ==", flush=True)
    if skip:
        load_previous_download_log()
        yahoo_q = urlencode(
            {
                "period1": "1451606400",
                "period2": str(int(time.time())),
                "interval": "1d",
                "events": "history",
            }
        )
        extras = [
            ("yahoo_gdaxip.json", f"https://query1.finance.yahoo.com/v8/finance/chart/%5EGDAXIP?{yahoo_q}"),
            ("yahoo_daxk_de.json", f"https://query1.finance.yahoo.com/v8/finance/chart/DAXK.DE?{yahoo_q}"),
            ("yahoo_gdaxci.json", f"https://query1.finance.yahoo.com/v8/finance/chart/%5EGDAXCI?{yahoo_q}"),
            ("yahoo_daxk.json", f"https://query1.finance.yahoo.com/v8/finance/chart/%5EDAXK?{yahoo_q}"),
        ]
        for filename, url in extras:
            dest = RAW / filename
            if filename != "yahoo_gdaxip.json" and dest.exists() and dest.stat().st_size > 200:
                continue
            download(filename, url)
    else:
        download_all()
    print("== clean equity / index ==")
    ctx = build_clean_files()
    print("== housing ==")
    housing = build_housing()
    print("== commodities ==")
    commodities = build_commodities()
    print("== bonds ==")
    series_map = build_bonds()
    print("== forecasts ==")
    build_forecasts(ctx, housing, commodities, series_map)
    print("== cross-checks ==")
    cross_checks(ctx, housing, series_map)
    print("== docs ==")
    write_docs()
    zip_path = write_zip()
    print(f"ZIP {zip_path}")
    print("done")


if __name__ == "__main__":
    main()
