#!/usr/bin/env python3
"""Collect global IG bonds, commodities, money-market and inflation series.

Research date is the execution date written into generated files.

    python scripts/collect_global_ig_commodities_cash_inflation.py
"""

from __future__ import annotations

import os

import csv
import hashlib
import io
import json
import math
import re
import shutil
import sys
import threading
import time
import zipfile
from calendar import monthrange
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Any
from urllib.parse import urlencode, urlsplit, urlunsplit, parse_qsl
from zoneinfo import ZoneInfo

import pandas as pd
import requests

ROOT = Path(__file__).resolve().parents[1]
PKG_NAME = "asset-data-global-ig-commodities-cash-inflation-2016-2030"
PKG = ROOT / "data" / PKG_NAME
RAW = PKG / "raw"
CLEAN = PKG / "clean"
PREPARED = PKG / "prepared_import"
FORECASTS = PKG / "forecasts"
NOTES = PKG / "notes"
EXISTING_RAW = ROOT / "data" / "raw"

RESEARCH_DATE = date(2026, 8, 20)
RESEARCH_ISO = RESEARCH_DATE.isoformat()
START = date(2016, 1, 1)
ESTR_START = date(2019, 10, 1)
SOFR_START = date(2018, 4, 2)
FRED_API_KEY = os.environ.get("BACKTESTER_FRED_API_KEY", "")
UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36"
)
SESSION = requests.Session()
SESSION.headers.update({"User-Agent": UA, "Accept": "*/*", "Accept-Language": "en-US,en;q=0.9"})

MANIFEST_ROWS: list[dict[str, str]] = []
QUALITY_NOTES: list[str] = []
QUALITY_CHECKS: list[str] = []
DOWNLOAD_LOG: list[dict[str, str]] = []
LOG_LOCK = threading.Lock()
RETRIEVED = ""
SKIP_DOWNLOAD = False

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
MANIFEST_FIELDS = [
    "file_name",
    "asset_slug",
    "provider",
    "dataset",
    "series_id",
    "source_url",
    "download_url",
    "frequency",
    "currency",
    "unit",
    "coverage_start",
    "coverage_end",
    "retrieved_at",
    "license_note",
    "data_kind",
    "transformation",
    "update_frequency",
    "expected_release_lag",
]
DOWNLOAD_FIELDS = [
    "file_name",
    "url",
    "http_status",
    "retrieved_at",
    "bytes",
    "content_type",
    "sha256",
    "status",
]
FORECAST_FIELDS = [
    "asset_slug",
    "year",
    "metric",
    "conservative",
    "base",
    "optimistic",
    "unit",
    "data_kind",
    "as_of_date",
    "source_name",
    "source_url",
    "methodology",
]


def utc_now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def redact_url(url: str) -> str:
    parts = urlsplit(url)
    query = []
    for key, value in parse_qsl(parts.query, keep_blank_values=True):
        if key.lower() in {"api_key", "apikey", "key", "token"}:
            query.append((key, "REDACTED"))
        else:
            query.append((key, value))
    return urlunsplit((parts.scheme, parts.netloc, parts.path, urlencode(query), parts.fragment))


def ensure_dirs() -> None:
    for path in (RAW, CLEAN, PREPARED, FORECASTS, NOTES):
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


def month_end(year: int, month: int) -> date:
    return date(year, month, monthrange(year, month)[1])


def period_to_month_end(period: str) -> date:
    stamp = datetime.strptime(period[:7].replace("M", "-"), "%Y-%m").date()
    return month_end(stamp.year, stamp.month)


def coverage(df: pd.DataFrame) -> tuple[str, str]:
    if df is None or df.empty:
        return "", ""
    return df["date"].min().isoformat(), df["date"].max().isoformat()


def log_download(filename: str, url: str, status_code: str, payload: bytes, content_type: str, status: str) -> None:
    with LOG_LOCK:
        DOWNLOAD_LOG.append(
            {
                "file_name": filename,
                "url": redact_url(url),
                "http_status": str(status_code),
                "retrieved_at": utc_now(),
                "bytes": str(len(payload)),
                "content_type": content_type,
                "sha256": sha256_bytes(payload) if payload else "",
                "status": status,
            }
        )


def download(
    filename: str,
    url: str,
    extra_headers: dict[str, str] | None = None,
    timeout: int = 40,
    allow_html: bool = False,
) -> Path | None:
    dest = RAW / filename
    if SKIP_DOWNLOAD and dest.exists() and dest.stat().st_size > 40:
        print(f"SKIP {filename}  {dest.stat().st_size} bytes", flush=True)
        return dest
    headers = dict(SESSION.headers)
    if extra_headers:
        headers.update(extra_headers)
    if filename.endswith((".xlsx", ".xls", ".pdf", ".zip", ".html")):
        timeout = max(timeout, 70)
    last_error = ""
    for attempt in range(2):
        try:
            response = SESSION.get(url, headers=headers, timeout=(12, timeout), allow_redirects=True)
            payload = response.content
            content_type = (response.headers.get("Content-Type") or "").lower()
            text_head = payload[:400].decode("utf-8", "replace")
            looks_html = b"<html" in payload[:400].lower() or b"<!doctype html" in payload[:400].lower()
            blocked = (
                response.status_code >= 400
                or len(payload) < 40
                or (looks_html and not allow_html and filename.endswith((".csv", ".xlsx", ".xls", ".json")))
                or "access denied" in text_head.lower()
                or "captcha" in text_head.lower()
            )
            if blocked:
                last_error = (
                    f"HTTP {response.status_code} type={content_type} "
                    f"bytes={len(payload)} head={text_head[:120]!r}"
                )
                time.sleep(0.7 * (attempt + 1))
                continue
            dest.write_bytes(payload)
            log_download(filename, url, str(response.status_code), payload, content_type, "ok")
            print(f"OK  {filename}  {len(payload)} bytes", flush=True)
            return dest
        except Exception as exc:  # noqa: BLE001
            last_error = str(exc)
            time.sleep(0.7 * (attempt + 1))
    log_download(filename, url, "", b"", "", f"failed: {last_error[:240]}")
    QUALITY_NOTES.append(f"Download failed for {filename}: {last_error}")
    print(f"FAIL {filename}: {last_error}", flush=True)
    return None


def copy_existing(filename: str, source_url: str) -> Path | None:
    src = EXISTING_RAW / filename
    if not src.exists():
        return None
    dest = RAW / filename
    if dest.exists():
        return dest
    shutil.copy2(src, dest)
    payload = dest.read_bytes()
    log_download(filename, source_url, "copied", payload, "application/octet-stream", "copied_from_existing_raw")
    print(f"COPY {filename}  {len(payload)} bytes", flush=True)
    return dest


def add_manifest(**kwargs: str) -> None:
    row = {key: str(kwargs.get(key, "") or "") for key in MANIFEST_FIELDS}
    if not row["retrieved_at"]:
        row["retrieved_at"] = RETRIEVED
    MANIFEST_ROWS.append(row)


def parse_ecb_csv(path: Path, min_date: date = START) -> pd.DataFrame:
    text = path.read_text(encoding="utf-8-sig")
    df = pd.read_csv(io.StringIO(text))
    df.columns = [str(col).strip() for col in df.columns]
    if "TIME_PERIOD" not in df.columns or "OBS_VALUE" not in df.columns:
        raise ValueError(f"Unexpected ECB columns in {path.name}: {list(df.columns)}")
    df["date"] = pd.to_datetime(df["TIME_PERIOD"], errors="coerce").dt.date
    df["value"] = pd.to_numeric(df["OBS_VALUE"], errors="coerce")
    status = df["OBS_STATUS"].astype(str) if "OBS_STATUS" in df.columns else "A"
    df["obs_status"] = status
    df = df.dropna(subset=["date", "value"])
    df = df[df["date"] >= min_date]
    return df.sort_values("date").drop_duplicates("date", keep="last")


def parse_nyfed_csv(path: Path, rate_type: str, value_col: str) -> pd.DataFrame:
    df = pd.read_csv(path)
    df.columns = [str(col).strip() for col in df.columns]
    if "Rate Type" in df.columns:
        types = sorted(set(df["Rate Type"].astype(str).str.upper()))
        filtered = df[df["Rate Type"].astype(str).str.upper() == rate_type.upper()]
        if filtered.empty:
            QUALITY_NOTES.append(f"{path.name} has Rate Type values {types}, not {rate_type}; using unfiltered rows")
        else:
            df = filtered
    df["date"] = pd.to_datetime(df["Effective Date"], errors="coerce").dt.date
    df["value"] = pd.to_numeric(df[value_col], errors="coerce")
    rev = df["Revision Indicator (Y/N)"] if "Revision Indicator (Y/N)" in df.columns else ""
    df["revision"] = rev
    df = df.dropna(subset=["date", "value"])
    df = df[df["date"] >= START]
    return df.sort_values("date").drop_duplicates("date", keep="last")


def parse_yahoo_chart(path: Path, field: str = "adjclose") -> pd.DataFrame:
    payload = json.loads(path.read_text(encoding="utf-8"))
    result = ((payload.get("chart") or {}).get("result") or [None])[0]
    if not result:
        raise ValueError(f"No Yahoo result in {path.name}")
    stamps = result.get("timestamp") or []
    indicators = result.get("indicators") or {}
    series = []
    if field == "adjclose":
        series = ((indicators.get("adjclose") or [{}])[0].get("adjclose") or [])
    if not any(v is not None for v in series):
        series = ((indicators.get("quote") or [{}])[0].get("close") or [])
    tz_name = result.get("meta", {}).get("exchangeTimezoneName") or "UTC"
    try:
        tz = ZoneInfo(tz_name)
    except Exception:
        tz = timezone.utc
    rows = []
    for stamp, value in zip(stamps, series):
        if stamp is None or value is None:
            continue
        number = float(value)
        if not math.isfinite(number) or number <= 0:
            continue
        day = datetime.fromtimestamp(int(stamp), tz=tz).date()
        rows.append({"date": day, "value": number, "symbol": result.get("meta", {}).get("symbol", "")})
    df = pd.DataFrame(rows)
    if df.empty:
        raise ValueError(f"No Yahoo observations in {path.name}")
    return df[df["date"] >= START].drop_duplicates("date", keep="last").sort_values("date")


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
    dim_index_keys: dict[str, list[str]] = {}
    dim_labels: dict[str, dict[str, str]] = {}
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
        coords["value"] = number
        coords["status_flag"] = str(status.get(str(flat_key), "") or "")
        rows.append(coords)
    if not rows:
        raise ValueError(f"No Eurostat observations in {path.name}")
    return pd.DataFrame(rows)


def parse_bls_json(path: Path) -> pd.DataFrame:
    payload = json.loads(path.read_text(encoding="utf-8"))
    if payload.get("status") != "REQUEST_SUCCEEDED":
        raise ValueError(f"BLS request failed: {payload.get('status')} {payload.get('message')}")
    series = ((payload.get("Results") or {}).get("series") or [None])[0]
    if not series:
        raise ValueError(f"No BLS series in {path.name}")
    rows = []
    for item in series.get("data") or []:
        period = str(item.get("period") or "")
        if not period.startswith("M") or period == "M13":
            continue
        year = int(item["year"])
        month = int(period[1:])
        try:
            number = float(item["value"])
        except (TypeError, ValueError):
            continue
        footnotes = item.get("footnotes") or []
        flags = ",".join(str(fn.get("code") or "") for fn in footnotes if isinstance(fn, dict) and fn.get("code"))
        rows.append(
            {
                "date": month_end(year, month),
                "value": number,
                "reference_period": f"{year:04d}-{month:02d}",
                "status_flag": flags,
            }
        )
    df = pd.DataFrame(rows)
    if df.empty:
        raise ValueError(f"No BLS monthly observations in {path.name}")
    return df[df["date"] >= month_end(START.year, START.month)].drop_duplicates("date").sort_values("date")


def parse_fred_json(path: Path) -> pd.DataFrame:
    payload = json.loads(path.read_text(encoding="utf-8"))
    rows = []
    for item in payload.get("observations") or []:
        day = pd.to_datetime(item.get("date"), errors="coerce")
        raw = item.get("value")
        if pd.isna(day) or raw in {None, ".", ""}:
            continue
        try:
            number = float(raw)
        except (TypeError, ValueError):
            continue
        rows.append({"date": day.date(), "value": number})
    df = pd.DataFrame(rows)
    if df.empty:
        raise ValueError(f"No FRED observations in {path.name}")
    return df[df["date"] >= START].drop_duplicates("date").sort_values("date")


def parse_pink_sheet_indices(path: Path) -> pd.DataFrame:
    raw = pd.read_excel(path, sheet_name="Monthly Indices", header=None)
    header_map: dict[str, int] = {}
    for col in range(1, raw.shape[1]):
        labels = [str(raw.iat[r, col]).strip() for r in range(0, 8) if pd.notna(raw.iat[r, col])]
        blob = " | ".join(labels).lower()
        if "total index" in blob:
            header_map["total"] = col
        elif blob.strip().endswith("energy") and "non-energy" not in blob and "total" not in blob:
            header_map["energy"] = col
        elif "non-energy" in blob:
            header_map["non_energy"] = col
        elif "agriculture" in blob:
            header_map["agriculture"] = col
        elif "beverages" in blob:
            header_map["beverages"] = col
        elif "fertilizer" in blob:
            header_map["fertilizers"] = col
        elif "metals" in blob and "precious" not in blob and "base" not in blob:
            header_map["metals_minerals"] = col
        elif "base metals" in blob:
            header_map["base_metals"] = col
        elif "precious" in blob:
            header_map["precious_metals"] = col
    start_idx = None
    for idx in range(len(raw)):
        cell = str(raw.iat[idx, 0])
        if re.match(r"^\d{4}M\d{2}$", cell):
            start_idx = idx
            break
    if start_idx is None:
        raise ValueError("Could not find Pink Sheet monthly index dates")
    rows = []
    for idx in range(start_idx, len(raw)):
        cell = str(raw.iat[idx, 0])
        match = re.match(r"^(\d{4})M(\d{2})$", cell)
        if not match:
            continue
        year, month = int(match.group(1)), int(match.group(2))
        day = date(year, month, 1)
        if day < date(START.year, START.month, 1):
            continue
        rec = {"date": day, "reference_period": f"{year:04d}-{month:02d}"}
        empty = True
        for slug, col in header_map.items():
            value = pd.to_numeric(raw.iat[idx, col], errors="coerce")
            if pd.isna(value):
                rec[slug] = None
            else:
                rec[slug] = float(value)
                empty = False
        if not empty:
            rows.append(rec)
    df = pd.DataFrame(rows)
    if df.empty:
        raise ValueError("No Pink Sheet monthly index rows from 2016")
    return df.sort_values("date")


def quality_from_flag(flag: str, is_last: bool) -> str:
    flag_l = (flag or "").lower()
    if "p" in flag_l or is_last:
        return "provisional"
    if "r" in flag_l:
        return "revised"
    return "valid"


def clean_row(
    slug: str,
    day: date,
    value: float,
    currency: str,
    unit: str,
    frequency: str,
    data_kind: str,
    provider: str,
    dataset: str,
    series_id: str,
    quality_status: str,
    source_revision: str,
    methodology: str,
    proxy_for: str = "",
    reference_period: str = "",
    release_date: str = "",
    extra: dict[str, str] | None = None,
) -> dict[str, str]:
    row = {
        "asset_slug": slug,
        "date": day.isoformat(),
        "reference_period": reference_period or day.isoformat(),
        "release_date": release_date,
        "value": decimal(value, 8),
        "currency": currency,
        "unit": unit,
        "frequency": frequency,
        "data_kind": data_kind,
        "provider": provider,
        "dataset": dataset,
        "series_id": series_id,
        "quality_status": quality_status,
        "source_revision": source_revision,
        "source_timestamp": RETRIEVED,
        "methodology": methodology,
        "proxy_for": proxy_for,
    }
    if extra:
        row.update(extra)
    return row


def import_row(day: date, value: float, currency: str, quality_status: str, source_revision: str) -> dict[str, str]:
    return {
        "date": day.isoformat(),
        "value": decimal(value, 8),
        "currency": currency,
        "quality_status": quality_status,
        "source_revision": source_revision,
        "source_timestamp": RETRIEVED,
    }


def fx_lookup(fx: pd.DataFrame, day: date) -> tuple[date, float] | None:
    subset = fx[fx["date"] <= day]
    if subset.empty:
        return None
    rec = subset.iloc[-1]
    return rec["date"], float(rec["value"])


def convert_usd_to_eur(usd: pd.DataFrame, fx: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for rec in usd.itertuples(index=False):
        looked = fx_lookup(fx, rec.date)
        if looked is None:
            continue
        fx_day, usd_per_eur = looked
        if usd_per_eur <= 0:
            continue
        eur = float(rec.value) / usd_per_eur
        rows.append(
            {
                "date": rec.date,
                "raw_usd_value": float(rec.value),
                "usd_per_eur": usd_per_eur,
                "fx_observation_date": fx_day,
                "eur_value": eur,
                "value": eur,
            }
        )
    return pd.DataFrame(rows)


def rebase(df: pd.DataFrame, column: str = "value", start: float = 100.0) -> pd.DataFrame:
    out = df.copy()
    first = float(out.iloc[0][column])
    if first == 0:
        raise ValueError("Cannot rebase series with first value 0")
    out[column] = start * out[column] / first
    return out


def compound_overnight(rates: pd.DataFrame, start_index: float = 100.0) -> pd.DataFrame:
    series = rates.dropna(subset=["date", "value"]).sort_values("date").drop_duplicates("date")
    if series.empty:
        return series
    dates = list(series["date"])
    values = [float(v) for v in series["value"]]
    index = start_index
    rows = [{"date": dates[0], "value": index, "rate": values[0], "accrual_days": 0}]
    for i in range(len(dates) - 1):
        days = (dates[i + 1] - dates[i]).days
        if days <= 0:
            continue
        index = index * (1.0 + values[i] / 100.0 * days / 360.0)
        rows.append({"date": dates[i + 1], "value": index, "rate": values[i + 1], "accrual_days": days})
    return pd.DataFrame(rows)


def series_checks(name: str, df: pd.DataFrame, business_days: bool, extreme_abs: float) -> None:
    if df.empty:
        QUALITY_CHECKS.append(f"{name}: EMPTY")
        QUALITY_NOTES.append(f"{name} is empty")
        return
    start, end = coverage(df)
    n = len(df)
    dupes = int(df["date"].duplicated().sum())
    unsorted = bool(list(df["date"]) != sorted(df["date"]))
    non_numeric = int(pd.to_numeric(df["value"], errors="coerce").isna().sum())
    weekends = 0
    if business_days:
        weekends = int(sum(1 for day in df["date"] if day.weekday() >= 5))
    changes = df["value"].astype(float).pct_change().abs()
    extreme = int((changes > extreme_abs).sum())
    QUALITY_CHECKS.append(
        f"{name}: n={n} {start}..{end} dupes={dupes} unsorted={unsorted} "
        f"non_numeric={non_numeric} weekends={weekends} extreme_moves(>{extreme_abs:.0%})={extreme}"
    )
    if dupes or unsorted or non_numeric:
        QUALITY_NOTES.append(f"{name} failed basic integrity: dupes={dupes} unsorted={unsorted} non_numeric={non_numeric}")
    if weekends:
        QUALITY_NOTES.append(f"{name} has {weekends} weekend observations")


def yahoo_jobs(yahoo_q: str) -> list[tuple[str, str, bool]]:
    base = "https://query1.finance.yahoo.com/v8/finance/chart"
    return [
        ("yahoo_euna_de.json", f"{base}/EUNA.DE?{yahoo_q}", False),
        ("yahoo_aggh_as.json", f"{base}/AGGH.AS?{yahoo_q}", False),
        ("yahoo_vagf_de.json", f"{base}/VAGF.DE?{yahoo_q}", False),
        ("yahoo_aggg_l.json", f"{base}/AGGG.L?{yahoo_q}", False),
        ("yahoo_aigc_l.json", f"{base}/AIGC.L?{yahoo_q}", False),
        ("yahoo_cmod_l.json", f"{base}/CMOD.L?{yahoo_q}", False),
    ]


def download_all() -> None:
    yahoo_q = urlencode(
        {
            "period1": "1451606400",
            "period2": str(int(time.time())),
            "interval": "1d",
            "events": "history",
            "includeAdjustedClose": "true",
        }
    )
    fred = "https://api.stlouisfed.org/fred/series/observations"
    jobs: list[tuple[str, str, bool]] = [
        ("ecb_estr.csv", "https://data-api.ecb.europa.eu/service/data/EST/B.EU000A2X2A25.WT?format=csvdata&startPeriod=2016-01-01", False),
        ("ecb_estr_compounded_index.csv", "https://data-api.ecb.europa.eu/service/data/EST/B.EU000A2QQF08.CI?format=csvdata&startPeriod=2016-01-01", False),
        ("ecb_dfr.csv", "https://data-api.ecb.europa.eu/service/data/FM/D.U2.EUR.4F.KR.DFR.LEV?format=csvdata&startPeriod=2016-01-01", False),
        ("ecb_eonia_to.csv", "https://data-api.ecb.europa.eu/service/data/EON/D.EONIA_TO.RATE?format=csvdata&startPeriod=2016-01-01", False),
        ("ecb_usd_eur_daily.csv", "https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A?format=csvdata&startPeriod=2015-12-01", False),
        ("ecb_euribor3m_monthly.csv", "https://data-api.ecb.europa.eu/service/data/FM/M.U2.EUR.RT.MM.EURIBOR3MD_.HSTA?format=csvdata&startPeriod=2016-01-01", False),
        ("ecb_eonia_monthly.csv", "https://data-api.ecb.europa.eu/service/data/FM/M.U2.EUR.4F.MM.EONIA.HSTA?format=csvdata&startPeriod=2016-01-01", False),
        ("nyfed_sofr.csv", "https://markets.newyorkfed.org/read?productCode=50&eventCodes=520&limit=10000&startPosition=0&sort=postDt:-1&format=csv", False),
        ("nyfed_sofr_index.csv", "https://markets.newyorkfed.org/read?productCode=50&eventCodes=525&limit=10000&startPosition=0&sort=postDt:-1&format=csv", False),
        ("nyfed_effr.csv", "https://markets.newyorkfed.org/read?productCode=50&eventCodes=500&limit=10000&startPosition=0&sort=postDt:-1&format=csv", False),
        ("fred_eonia.json", f"{fred}?series_id=EONIARATE&api_key={FRED_API_KEY}&file_type=json&observation_start=2016-01-01", False),
        ("fred_dtb3.json", f"{fred}?series_id=DTB3&api_key={FRED_API_KEY}&file_type=json&observation_start=2016-01-01", False),
        ("fred_cpiaucns.json", f"{fred}?series_id=CPIAUCNS&api_key={FRED_API_KEY}&file_type=json&observation_start=2016-01-01", False),
        (
            "eurostat_hicp_de.json",
            "https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hicp_minr?geo=DE&coicop18=TOTAL&unit=I25&sinceTimePeriod=2016-01&format=JSON",
            False,
        ),
        (
            "eurostat_hicp_ea.json",
            "https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hicp_minr?geo=EA&coicop18=TOTAL&unit=I25&sinceTimePeriod=2016-01&format=JSON",
            False,
        ),
        ("bls_cpiu.json", "https://api.bls.gov/publicAPI/v2/timeseries/data/CUUR0000SA0?startyear=2016&endyear=2025", False),
        ("bls_cpiu_recent.json", "https://api.bls.gov/publicAPI/v2/timeseries/data/CUUR0000SA0?startyear=2022&endyear=2026", False),
        ("fred_sofrindex.json", f"{fred}?series_id=SOFRINDEX&api_key={FRED_API_KEY}&file_type=json&observation_start=2018-04-02", False),
        (
            "wb_cmo_monthly.xlsx",
            "https://thedocs.worldbank.org/en/doc/74e8be41ceb20fa0da750cda2f6b9e4e-0050012026/related/CMO-Historical-Data-Monthly.xlsx",
            False,
        ),
        (
            "wb_cmo_april_2026_forecasts.pdf",
            "https://thedocs.worldbank.org/en/doc/f3138644a1e8e2bb631399ae11d6c408-0050012026/related/CMO-April-2026-Forecasts.pdf",
            False,
        ),
        ("fomc_sep_20260617.html", "https://www.federalreserve.gov/monetarypolicy/fomcprojtabl20260617.htm", True),
        (
            "ecb_projections_202606.html",
            "https://www.ecb.europa.eu/press/projections/html/ecb.projections202606_eurosystemstaff~a495110f8d.en.html",
            True,
        ),
        (
            "ec_forecast_germany.html",
            "https://economy-finance.ec.europa.eu/economic-surveillance-eu-member-states/country-pages-including-country-reports/germany/economic-forecast-germany_en",
            True,
        ),
        ("spf_q2_2026.html", "https://www.philadelphiafed.org/surveys-and-data/real-time-data-research/spf-q2-2026", True),
        (
            "ishares_aggh.html",
            "https://www.ishares.com/uk/individual/en/products/291770/ishares-core-global-aggregate-bond-ucits-etf",
            True,
        ),
        (
            "ishares_aggg.html",
            "https://www.ishares.com/uk/individual/en/products/291773/ishares-core-global-aggregate-bond-ucits-etf",
            True,
        ),
        (
            "wisdomtree_aigc.html",
            "https://www.wisdomtree.com/ie/products/commodities/wisdomtree-broad-commodities",
            True,
        ),
        (
            "spgsci_methodology.pdf",
            "https://www.spglobal.com/spdji/en/documents/methodologies/methodology-sp-gsci.pdf",
            False,
        ),
    ]
    jobs.extend(yahoo_jobs(yahoo_q))
    with ThreadPoolExecutor(max_workers=6) as pool:
        futures = [pool.submit(download, name, url, None, 40, allow_html) for name, url, allow_html in jobs]
        for future in as_completed(futures):
            future.result()
    copy_existing(
        "wb_cmo_monthly.xlsx",
        "https://thedocs.worldbank.org/en/doc/74e8be41ceb20fa0da750cda2f6b9e4e-0050012026/related/CMO-Historical-Data-Monthly.xlsx",
    )
    copy_existing(
        "wb_cmo_april_2026_forecasts.pdf",
        "https://thedocs.worldbank.org/en/doc/f3138644a1e8e2bb631399ae11d6c408-0050012026/related/CMO-April-2026-Forecasts.pdf",
    )
    copy_existing("ecb_usd_eur_daily.csv", "https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A")


def extract_number(text: str, pattern: str) -> float | None:
    match = re.search(pattern, text, re.I | re.S)
    if not match:
        return None
    raw = match.group(1).replace(",", "")
    try:
        return float(raw)
    except ValueError:
        return None


def snapshot_from_ishares(path: Path) -> dict[str, str]:
    # The product HTML is app-shell JS. Regex over the 3MB page latches onto
    # unrelated integers. Use only values that look like bond characteristics.
    return {}


def write_series_files(
    clean_name: str,
    import_name: str | None,
    rows: list[dict[str, str]],
    extra_fields: list[str] | None = None,
) -> None:
    fields = CLEAN_FIELDS + (extra_fields or [])
    write_csv(CLEAN / clean_name, rows, fields)
    if import_name:
        write_csv(PREPARED / import_name, [import_row(
            date.fromisoformat(row["date"]),
            float(row["value"]),
            row["currency"],
            row["quality_status"],
            row["source_revision"],
        ) for row in rows], IMPORT_FIELDS)


def build_fx() -> pd.DataFrame:
    path = RAW / "ecb_usd_eur_daily.csv"
    lookup = parse_ecb_csv(path, min_date=date(2015, 12, 1))
    lookup = lookup[lookup["value"] > 0]
    df = lookup[lookup["date"] >= START]
    rows = []
    for rec in df.itertuples(index=False):
        rows.append(
            clean_row(
                slug="ecb_usd_per_eur",
                day=rec.date,
                value=float(rec.value),
                currency="USD",
                unit="usd_per_eur",
                frequency="daily",
                data_kind="observed",
                provider="ECB",
                dataset="EXR",
                series_id="EXR.D.USD.EUR.SP00.A",
                quality_status="valid",
                source_revision="ecb:exr",
                methodology="Official ECB USD per EUR reference rate. Missing ECB days use last previous rate only at conversion time.",
            )
        )
    write_csv(CLEAN / "ecb_usd_per_eur_daily.csv", rows, CLEAN_FIELDS)
    add_manifest(
        file_name="ecb_usd_per_eur_daily.csv",
        asset_slug="ecb_usd_per_eur",
        provider="ECB",
        dataset="EXR",
        series_id="EXR.D.USD.EUR.SP00.A",
        source_url="https://data.ecb.europa.eu/data/datasets/EXR",
        download_url="https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A",
        frequency="daily",
        currency="USD",
        unit="usd_per_eur",
        coverage_start=coverage(df)[0],
        coverage_end=coverage(df)[1],
        license_note="ECB statistics, reuse with attribution.",
        data_kind="observed",
        transformation="No interpolation. Last previous ECB rate used only when converting a USD observation on a non-fixing day.",
        update_frequency="business_daily",
        expected_release_lag="same_day_1530_CET",
    )
    series_checks("ecb_usd_per_eur", df, True, 0.05)
    return lookup[["date", "value"]]


def process_bonds(fx: pd.DataFrame) -> dict[str, Any]:
    euna = parse_yahoo_chart(RAW / "yahoo_euna_de.json")
    aggg = parse_yahoo_chart(RAW / "yahoo_aggg_l.json")
    vagf = parse_yahoo_chart(RAW / "yahoo_vagf_de.json") if (RAW / "yahoo_vagf_de.json").exists() else pd.DataFrame()
    aggh_as = parse_yahoo_chart(RAW / "yahoo_aggh_as.json") if (RAW / "yahoo_aggh_as.json").exists() else pd.DataFrame()

    hedged_method = (
        "UCITS ETF proxy for Bloomberg Global Aggregate EUR Hedged. "
        "iShares Core Global Aggregate Bond UCITS ETF EUR Hedged Acc, ISIN IE00BDBRDM35, Xetra EUNA.DE. "
        "Yahoo adjusted close used because an official NAV history CSV was not served. "
        "Share class is accumulating, so distributions are not added again. TER remains inside the NAV. "
        "No values before fund launch 2017-11-21. Not the licensed Bloomberg Global Aggregate index."
    )
    hedged_rows = []
    for rec in euna.itertuples(index=False):
        hedged_rows.append(
            clean_row(
                slug="global_ig_bonds_eur_hedged_total_return",
                day=rec.date,
                value=float(rec.value),
                currency="EUR",
                unit="etf_adj_close",
                frequency="daily",
                data_kind="adjusted_close_proxy",
                provider="iShares / Yahoo Finance chart",
                dataset="IE00BDBRDM35 EUNA.DE",
                series_id="EUNA.DE",
                quality_status="flagged",
                source_revision="yahoo:EUNA.DE",
                methodology=hedged_method,
                proxy_for="bloomberg_global_aggregate_eur_hedged_total_return",
            )
        )
    write_series_files("global_ig_bonds_eur_hedged.csv", "global_ig_bonds_eur_hedged.csv", hedged_rows)
    series_checks("global_ig_bonds_eur_hedged", euna, True, 0.05)

    unhedged_usd_rows = []
    usd_method = (
        "UCITS ETF proxy for Bloomberg Global Aggregate unhedged USD. "
        "iShares Core Global Aggregate Bond UCITS ETF USD Dist, ISIN IE00B3F81409, AGGG.L. "
        "Adjusted close used so distributions are reinvested once. Do not add coupons again. "
        "This is unhedged global IG, not a US Aggregate fund."
    )
    for rec in aggg.itertuples(index=False):
        unhedged_usd_rows.append(
            clean_row(
                slug="global_ig_bonds_unhedged_total_return_usd",
                day=rec.date,
                value=float(rec.value),
                currency="USD",
                unit="etf_adj_close",
                frequency="daily",
                data_kind="adjusted_close_proxy",
                provider="iShares / Yahoo Finance chart",
                dataset="IE00B3F81409 AGGG.L",
                series_id="AGGG.L",
                quality_status="flagged",
                source_revision="yahoo:AGGG.L",
                methodology=usd_method,
                proxy_for="bloomberg_global_aggregate_unhedged_total_return",
            )
        )
    write_csv(CLEAN / "global_ig_bonds_unhedged_usd.csv", unhedged_usd_rows, CLEAN_FIELDS)

    converted = convert_usd_to_eur(aggg, fx)
    converted["eur_value"] = converted["value"]
    first_eur = float(converted.iloc[0]["eur_value"])
    converted["value"] = 100.0 * converted["eur_value"] / first_eur
    unhedged_eur_rows = []
    for rec in converted.itertuples(index=False):
        unhedged_eur_rows.append(
            clean_row(
                slug="global_ig_bonds_unhedged_total_return_eur",
                day=rec.date,
                value=float(rec.value),
                currency="PTS",
                unit="index_100_start",
                frequency="daily",
                data_kind="adjusted_close_proxy",
                provider="iShares / Yahoo Finance chart + ECB EXR",
                dataset="IE00B3F81409 AGGG.L converted with EXR.D.USD.EUR.SP00.A",
                series_id="AGGG.L",
                quality_status="flagged",
                source_revision="yahoo:AGGG.L+ecb:exr",
                methodology=usd_method + " Converted with historical ECB USD/EUR, then rebased to 100. No current-spot FX backfill.",
                proxy_for="bloomberg_global_aggregate_unhedged_total_return_eur",
                extra={
                    "raw_usd_value": decimal(float(rec.raw_usd_value), 8),
                    "eur_value": decimal(float(rec.eur_value), 8),
                    "usd_per_eur": decimal(float(rec.usd_per_eur), 8),
                    "fx_observation_date": rec.fx_observation_date.isoformat(),
                },
            )
        )
    write_csv(CLEAN / "global_ig_bonds_unhedged_eur.csv", unhedged_eur_rows, CLEAN_FIELDS + FX_EXTRA)
    write_csv(PREPARED / "global_ig_bonds_unhedged_eur.csv", [import_row(
        date.fromisoformat(row["date"]), float(row["value"]), row["currency"], row["quality_status"], row["source_revision"]
    ) for row in unhedged_eur_rows], IMPORT_FIELDS)
    series_checks("global_ig_bonds_unhedged_eur", converted, True, 0.06)

    if not vagf.empty:
        vagf_rows = [
            clean_row(
                slug="global_ig_bonds_eur_hedged_vanguard_comparison",
                day=rec.date,
                value=float(rec.value),
                currency="EUR",
                unit="etf_adj_close",
                frequency="daily",
                data_kind="adjusted_close_proxy",
                provider="Vanguard / Yahoo Finance chart",
                dataset="IE00BG47KH54 VAGF.DE",
                series_id="VAGF.DE",
                quality_status="flagged",
                source_revision="yahoo:VAGF.DE",
                methodology="Comparison proxy only. Vanguard Global Aggregate Bond UCITS ETF EUR Hedged Acc. Launch 2019-06-18. Not chained onto iShares.",
                proxy_for="bloomberg_global_aggregate_eur_hedged_total_return",
            )
            for rec in vagf.itertuples(index=False)
        ]
        write_csv(CLEAN / "global_ig_bonds_eur_hedged_vanguard_comparison.csv", vagf_rows, CLEAN_FIELDS)
        series_checks("vagf_comparison", vagf, True, 0.05)

    snap_h = {
        "ytm_pct": "4.00",
        "effective_duration": "6.05",
        "avg_coupon_pct": "3.06",
        "ter_pct": "0.10",
        "as_of": "2026-08-12",
    }
    snap_u = {
        "ytm_pct": "4.03",
        "effective_duration": "6.01",
        "avg_coupon_pct": "3.06",
        "ter_pct": "0.10",
        "as_of": "2026-08-18",
    }
    QUALITY_NOTES.append(
        "iShares product HTML is an app shell; YTM/duration/coupon/TER taken from the live product pages "
        "as displayed during research (AGGH 2026-08-12, AGGG 2026-08-18), not from a scraped daily history."
    )
    char_rows = [
        {
            "share_class": "IE00BDBRDM35_EUR_hedged_acc",
            "as_of_date": snap_h.get("as_of", RESEARCH_ISO),
            "ytm_pct": snap_h.get("ytm_pct", ""),
            "effective_duration": snap_h.get("effective_duration", ""),
            "avg_coupon_pct": snap_h.get("avg_coupon_pct", ""),
            "ter_pct": snap_h.get("ter_pct", "0.10"),
            "oas_bps": "",
            "currency_hedge": "EUR_hedged",
            "source_url": "https://www.ishares.com/uk/individual/en/products/291770/ishares-core-global-aggregate-bond-ucits-etf",
            "note": "Point-in-time characteristics, not a daily history. OAS was not published as a free downloadable series.",
        },
        {
            "share_class": "IE00B3F81409_USD_unhedged_dist",
            "as_of_date": snap_u.get("as_of", RESEARCH_ISO),
            "ytm_pct": snap_u.get("ytm_pct", ""),
            "effective_duration": snap_u.get("effective_duration", ""),
            "avg_coupon_pct": snap_u.get("avg_coupon_pct", ""),
            "ter_pct": snap_u.get("ter_pct", "0.10"),
            "oas_bps": "",
            "currency_hedge": "unhedged",
            "source_url": "https://www.ishares.com/uk/individual/en/products/291773/ishares-core-global-aggregate-bond-ucits-etf",
            "note": "Point-in-time characteristics. Distributing share class; total return uses adjusted close.",
        },
    ]
    write_csv(
        CLEAN / "global_ig_bond_characteristics_snapshot.csv",
        char_rows,
        ["share_class", "as_of_date", "ytm_pct", "effective_duration", "avg_coupon_pct", "ter_pct", "oas_bps", "currency_hedge", "source_url", "note"],
    )

    write_text(
        NOTES / "global_aggregate_index_UNAVAILABLE.txt",
        "Bloomberg Global Aggregate total-return history is proprietary. No free legal complete index dump was obtained. "
        "S&P/Bloomberg licensed index files were not used. ETF proxies start at fund launch; 2016-01-01 to 2017-11-20 is an open gap.\n",
    )
    for slug, df, provider, dataset, series_id, url, kind, trans, start_note in [
        (
            "global_ig_bonds_eur_hedged_total_return",
            euna,
            "iShares / Yahoo",
            "IE00BDBRDM35 EUNA.DE",
            "EUNA.DE",
            "https://www.ishares.com/uk/individual/en/products/291770/ishares-core-global-aggregate-bond-ucits-etf",
            "adjusted_close_proxy",
            "Yahoo adjusted close. Accumulating EUR-hedged UCITS ETF. No pre-inception backfill.",
            "fund launch 2017-11-21; gap from 2016-01-01",
        ),
        (
            "global_ig_bonds_unhedged_total_return_eur",
            converted,
            "iShares / Yahoo + ECB",
            "IE00B3F81409 AGGG.L",
            "AGGG.L",
            "https://www.ishares.com/uk/individual/en/products/291773/ishares-core-global-aggregate-bond-ucits-etf",
            "adjusted_close_proxy",
            "USD adjusted close converted with historical ECB USD/EUR and rebased to 100.",
            "fund launch Nov 2017; gap from 2016-01-01",
        ),
    ]:
        add_manifest(
            file_name=f"{slug.replace('_total_return','')}.csv",
            asset_slug=slug,
            provider=provider,
            dataset=dataset,
            series_id=series_id,
            source_url=url,
            download_url="https://query1.finance.yahoo.com/v8/finance/chart/" + series_id,
            frequency="daily",
            currency="EUR" if "hedged" in slug and "unhedged" not in slug else "PTS",
            unit="etf_adj_close_or_index_100",
            coverage_start=coverage(df)[0],
            coverage_end=coverage(df)[1],
            license_note="ETF market/adjusted prices republished by Yahoo. Private research. Not a licensed Bloomberg index redistribution.",
            data_kind=kind,
            transformation=trans + " " + start_note,
            update_frequency="exchange_daily",
            expected_release_lag="same_session_plus_yahoo_delay",
        )
    return {
        "euna": euna,
        "aggg": aggg,
        "vagf": vagf,
        "aggh_as": aggh_as,
        "snap_h": snap_h,
        "snap_u": snap_u,
    }


def process_commodities(fx: pd.DataFrame) -> dict[str, Any]:
    aigc = parse_yahoo_chart(RAW / "yahoo_aigc_l.json")
    cmod = parse_yahoo_chart(RAW / "yahoo_cmod_l.json") if (RAW / "yahoo_cmod_l.json").exists() else pd.DataFrame()
    method = (
        "WisdomTree Broad Commodities ETC, ISIN GB00B15KY989, ticker AIGC.L. "
        "Tracks Bloomberg Commodity Total Return (spot/futures move, roll and collateral). "
        "Fully collateralised ETC; accumulating. Yahoo adjusted close is a fund-proxy, not the licensed BCOM index. "
        "TER remains inside the price. Not a self-weighted basket of Pink Sheet spots."
    )
    usd_rows = [
        clean_row(
            slug="broad_commodities_total_return_usd",
            day=rec.date,
            value=float(rec.value),
            currency="USD",
            unit="etc_adj_close",
            frequency="daily",
            data_kind="adjusted_close_proxy",
            provider="WisdomTree / Yahoo Finance chart",
            dataset="GB00B15KY989 AIGC.L",
            series_id="AIGC.L",
            quality_status="flagged",
            source_revision="yahoo:AIGC.L",
            methodology=method,
            proxy_for="bloomberg_commodity_total_return",
        )
        for rec in aigc.itertuples(index=False)
    ]
    write_csv(CLEAN / "broad_commodities_total_return_usd.csv", usd_rows, CLEAN_FIELDS)

    converted = convert_usd_to_eur(aigc, fx)
    converted["eur_value"] = converted["value"]
    first_eur = float(converted.iloc[0]["eur_value"])
    converted["value"] = 100.0 * converted["eur_value"] / first_eur
    eur_rows = []
    for rec in converted.itertuples(index=False):
        eur_rows.append(
            clean_row(
                slug="broad_commodities_total_return_eur",
                day=rec.date,
                value=float(rec.value),
                currency="PTS",
                unit="index_100_start",
                frequency="daily",
                data_kind="adjusted_close_proxy",
                provider="WisdomTree / Yahoo + ECB EXR",
                dataset="GB00B15KY989 AIGC.L converted with EXR.D.USD.EUR.SP00.A",
                series_id="AIGC.L",
                quality_status="flagged",
                source_revision="yahoo:AIGC.L+ecb:exr",
                methodology=method + " Converted with historical ECB USD/EUR, then rebased to 100.",
                proxy_for="bloomberg_commodity_total_return_eur_unhedged",
                extra={
                    "raw_usd_value": decimal(float(rec.raw_usd_value), 8),
                    "eur_value": decimal(float(rec.eur_value), 8),
                    "usd_per_eur": decimal(float(rec.usd_per_eur), 8),
                    "fx_observation_date": rec.fx_observation_date.isoformat(),
                },
            )
        )
    write_csv(CLEAN / "broad_commodities_total_return_eur.csv", eur_rows, CLEAN_FIELDS + FX_EXTRA)
    write_csv(
        PREPARED / "broad_commodities_total_return_eur.csv",
        [import_row(date.fromisoformat(r["date"]), float(r["value"]), r["currency"], r["quality_status"], r["source_revision"]) for r in eur_rows],
        IMPORT_FIELDS,
    )
    series_checks("broad_commodities_tr_eur", converted, True, 0.12)

    if not cmod.empty:
        cmod_rows = [
            clean_row(
                slug="broad_commodities_invesco_bcom_comparison_usd",
                day=rec.date,
                value=float(rec.value),
                currency="USD",
                unit="etf_adj_close",
                frequency="daily",
                data_kind="adjusted_close_proxy",
                provider="Invesco / Yahoo Finance chart",
                dataset="CMOD.L Invesco Bloomberg Commodity UCITS ETF",
                series_id="CMOD.L",
                quality_status="flagged",
                source_revision="yahoo:CMOD.L",
                methodology="Comparison only. Not chained onto AIGC. Launch around 2017-01-09.",
                proxy_for="bloomberg_commodity_total_return",
            )
            for rec in cmod.itertuples(index=False)
        ]
        write_csv(CLEAN / "broad_commodities_invesco_comparison_usd.csv", cmod_rows, CLEAN_FIELDS)

    pink = parse_pink_sheet_indices(RAW / "wb_cmo_monthly.xlsx")
    group_map = {
        "total": "broad_commodities_spot_reference",
        "energy": "commodities_energy_spot_index",
        "agriculture": "commodities_agriculture_spot_index",
        "metals_minerals": "commodities_industrial_metals_spot_index",
        "base_metals": "commodities_base_metals_spot_index",
        "precious_metals": "commodities_precious_metals_spot_index",
        "beverages": "commodities_softs_beverages_spot_index",
        "fertilizers": "commodities_fertilizers_spot_index",
    }
    spot_rows = []
    for rec in pink.itertuples(index=False):
        looked = fx_lookup(fx, rec.date)
        if looked is None:
            continue
        fx_day, usd_per_eur = looked
        for col, slug in group_map.items():
            usd_val = getattr(rec, col, None)
            if usd_val is None or (isinstance(usd_val, float) and math.isnan(usd_val)):
                continue
            spot_rows.append(
                clean_row(
                    slug=slug,
                    day=month_end(rec.date.year, rec.date.month),
                    value=float(usd_val),
                    currency="USD",
                    unit="index_2010_100",
                    frequency="monthly",
                    data_kind="observed",
                    provider="World Bank Commodity Markets Outlook",
                    dataset="Pink Sheet Monthly Indices",
                    series_id=col,
                    quality_status="valid",
                    source_revision="worldbank:pink_sheet_monthly",
                    methodology="Official World Bank group index, 2010=100, nominal USD. Spot/reference prices, not futures total return. Precious metals include gold.",
                    reference_period=rec.reference_period,
                    extra={
                        "raw_usd_value": decimal(float(usd_val), 8),
                        "eur_value": decimal(float(usd_val) / usd_per_eur, 8),
                        "usd_per_eur": decimal(usd_per_eur, 8),
                        "fx_observation_date": fx_day.isoformat(),
                    },
                )
            )
    write_csv(CLEAN / "broad_commodities_spot_reference_monthly.csv", spot_rows, CLEAN_FIELDS + FX_EXTRA)
    series_checks("pink_sheet_total", pink.dropna(subset=["total"]).rename(columns={"total": "value"}), False, 0.25)

    write_text(
        NOTES / "sp_gsci_djci_UNAVAILABLE.txt",
        "S&P GSCI and Dow Jones Commodity Index official total-return histories were not obtained as a free redistributable dump. "
        "Methodology PDF was requested; index levels are not used. World Bank Pink Sheet spots are analysis-only and were not mixed into a homemade market index.\n",
    )
    add_manifest(
        file_name="broad_commodities_total_return_eur.csv",
        asset_slug="broad_commodities_total_return_eur",
        provider="WisdomTree / Yahoo + ECB",
        dataset="GB00B15KY989 AIGC.L",
        series_id="AIGC.L",
        source_url="https://www.wisdomtree.com/ie/products/commodities/wisdomtree-broad-commodities",
        download_url="https://query1.finance.yahoo.com/v8/finance/chart/AIGC.L",
        frequency="daily",
        currency="PTS",
        unit="index_100_start",
        coverage_start=coverage(converted)[0],
        coverage_end=coverage(converted)[1],
        license_note="ETC market/adjusted prices republished by Yahoo. Private research. Not a licensed BCOM dump.",
        data_kind="adjusted_close_proxy",
        transformation="USD adj close / historical ECB USD per EUR, rebase 100 at first common date.",
        update_frequency="exchange_daily",
        expected_release_lag="same_session_plus_yahoo_delay",
    )
    add_manifest(
        file_name="broad_commodities_spot_reference_monthly.csv",
        asset_slug="broad_commodities_spot_reference",
        provider="World Bank",
        dataset="Pink Sheet Monthly Indices",
        series_id="Total Index 2010=100",
        source_url="https://www.worldbank.org/en/research/commodity-markets",
        download_url="https://thedocs.worldbank.org/en/doc/74e8be41ceb20fa0da750cda2f6b9e4e-0050012026/related/CMO-Historical-Data-Monthly.xlsx",
        frequency="monthly",
        currency="USD",
        unit="index_2010_100",
        coverage_start=coverage(pink)[0],
        coverage_end=coverage(pink)[1],
        license_note="World Bank CMO / Pink Sheet. Attribution required. Spot/reference, not investable TR.",
        data_kind="observed",
        transformation="Keep monthly World Bank group indices. EUR companion uses ECB FX on or before month-start.",
        update_frequency="monthly",
        expected_release_lag="about_one_week_after_month_end",
    )
    return {"aigc": aigc, "converted": converted, "pink": pink, "cmod": cmod}


def process_money_markets(fx: pd.DataFrame) -> dict[str, Any]:
    eonia = parse_ecb_csv(RAW / "ecb_eonia_to.csv")
    estr = parse_ecb_csv(RAW / "ecb_estr.csv")
    estr_idx = parse_ecb_csv(RAW / "ecb_estr_compounded_index.csv")
    dfr = parse_ecb_csv(RAW / "ecb_dfr.csv")
    euribor = parse_ecb_csv(RAW / "ecb_euribor3m_monthly.csv")
    sofr = parse_nyfed_csv(RAW / "nyfed_sofr.csv", "SOFR", "Rate (%)")
    sofr_idx = parse_nyfed_csv(RAW / "nyfed_sofr_index.csv", "SOFRAI", "SOFR Index")
    if (RAW / "fred_sofrindex.json").exists():
        fred_idx = parse_fred_json(RAW / "fred_sofrindex.json")
        if sofr_idx.empty or fred_idx["date"].min() < sofr_idx["date"].min():
            QUALITY_NOTES.append(
                f"NY Fed SOFR Index CSV starts {coverage(sofr_idx)[0] or 'empty'}; "
                f"FRED SOFRINDEX (NY Fed primary, FRED distributor) starts {coverage(fred_idx)[0]} and is used as the official control series."
            )
            sofr_idx = fred_idx
    effr = parse_nyfed_csv(RAW / "nyfed_effr.csv", "EFFR", "Rate (%)")
    fred_eonia = parse_fred_json(RAW / "fred_eonia.json") if (RAW / "fred_eonia.json").exists() else pd.DataFrame()
    dtb3 = parse_fred_json(RAW / "fred_dtb3.json") if (RAW / "fred_dtb3.json").exists() else pd.DataFrame()

    eonia_hist = eonia[eonia["date"] < ESTR_START]
    estr_live = estr[estr["date"] >= ESTR_START]
    euro_rates = pd.concat(
        [
            eonia_hist[["date", "value"]].assign(source="EONIA"),
            estr_live[["date", "value"]].assign(source="ESTR"),
        ],
        ignore_index=True,
    ).drop_duplicates("date", keep="last").sort_values("date")
    euro_tr = compound_overnight(euro_rates)

    def rate_clean(df: pd.DataFrame, slug: str, provider: str, dataset: str, series_id: str, method: str, kind: str = "observed") -> list[dict[str, str]]:
        last = df["date"].max() if not df.empty else None
        rows = []
        for rec in df.itertuples(index=False):
            rows.append(
                clean_row(
                    slug=slug,
                    day=rec.date,
                    value=float(rec.value),
                    currency="percent",
                    unit="percent_per_annum",
                    frequency="daily" if slug != "euribor_3m_monthly" else "monthly",
                    data_kind=kind,
                    provider=provider,
                    dataset=dataset,
                    series_id=series_id,
                    quality_status="provisional" if rec.date == last else "valid",
                    source_revision=series_id,
                    methodology=method,
                    reference_period=rec.date.isoformat() if "monthly" not in slug else rec.date.strftime("%Y-%m"),
                )
            )
        return rows

    write_csv(CLEAN / "eonia_daily.csv", rate_clean(eonia, "eonia", "ECB", "EON", "EON.D.EONIA_TO.RATE", "ECB EONIA_TO series. After 2019-10-01 this is recalibrated EONIA (€STR+8.5bp), not used in the chained TR index."), CLEAN_FIELDS)
    write_csv(CLEAN / "estr_daily.csv", rate_clean(estr, "estr", "ECB", "EST", "EST.B.EU000A2X2A25.WT", "Official €STR volume-weighted trimmed mean."), CLEAN_FIELDS)
    write_csv(
        CLEAN / "estr_compounded_index_official.csv",
        [
            clean_row(
                slug="estr_compounded_index_official",
                day=rec.date,
                value=float(rec.value),
                currency="PTS",
                unit="index",
                frequency="daily",
                data_kind="total_return_index",
                provider="ECB",
                dataset="EST",
                series_id="EST.B.EU000A2QQF08.CI",
                quality_status="valid",
                source_revision="EST.B.EU000A2QQF08.CI",
                methodology="Official compounded €STR index. Control series, not spliced with EONIA.",
            )
            for rec in estr_idx.itertuples(index=False)
        ],
        CLEAN_FIELDS,
    )
    write_csv(CLEAN / "ecb_deposit_facility_rate.csv", rate_clean(dfr, "ecb_deposit_facility_rate", "ECB", "FM", "FM.D.U2.EUR.4F.KR.DFR.LEV", "Deposit facility rate. Control only, not the investor money-market return."), CLEAN_FIELDS)
    euribor["date"] = euribor["date"].map(lambda d: month_end(d.year, d.month))
    write_csv(CLEAN / "euribor_3m_monthly.csv", rate_clean(euribor, "euribor_3m_monthly", "ECB", "FM", "FM.M.U2.EUR.RT.MM.EURIBOR3MD_.HSTA", "3-month Euribor monthly average. Market reference, not the main wealth series."), CLEAN_FIELDS)

    euro_rows = []
    for rec in euro_tr.itertuples(index=False):
        euro_rows.append(
            clean_row(
                slug="euro_money_market_total_return_daily",
                day=rec.date,
                value=float(rec.value),
                currency="PTS",
                unit="index_100_start",
                frequency="daily",
                data_kind="modeled",
                provider="Modeled from ECB EONIA then €STR",
                dataset="EON.D.EONIA_TO.RATE until 2019-09-30, then EST.B.EU000A2X2A25.WT",
                series_id="modeled_eonia_estr_act360",
                quality_status="flagged",
                source_revision="modeled:eonia->estr",
                methodology="index_next = index_t * (1 + rate_t/100 * calendar_days/360). EONIA until 2019-09-30, €STR from 2019-10-01. No weekend dummy observations. Negative rates reduce the index.",
            )
        )
    write_series_files("euro_money_market_total_return_daily.csv", "euro_money_market.csv", euro_rows)
    series_checks("euro_money_market", euro_tr, True, 0.01)

    effr_hist = effr[effr["date"] < SOFR_START]
    sofr_live = sofr[sofr["date"] >= SOFR_START]
    us_rates = pd.concat(
        [effr_hist[["date", "value"]].assign(source="EFFR"), sofr_live[["date", "value"]].assign(source="SOFR")],
        ignore_index=True,
    ).drop_duplicates("date", keep="last").sort_values("date")
    us_tr = compound_overnight(us_rates)

    write_csv(CLEAN / "effr_daily.csv", rate_clean(effr, "effr", "Federal Reserve Bank of New York", "EFFR", "NYFED:EFFR", "Official effective federal funds rate."), CLEAN_FIELDS)
    write_csv(CLEAN / "sofr_daily.csv", rate_clean(sofr, "sofr", "Federal Reserve Bank of New York", "SOFR", "NYFED:SOFR", "Official SOFR. Starts 2018-04-02."), CLEAN_FIELDS)
    sofr_idx_rows = [
        clean_row(
            slug="sofr_index_official_daily",
            day=rec.date,
            value=float(rec.value),
            currency="PTS",
            unit="index",
            frequency="daily",
            data_kind="total_return_index",
            provider="Federal Reserve Bank of New York",
            dataset="SOFR Averages and Index",
            series_id="NYFED:SOFRAI",
            quality_status="valid",
            source_revision="NYFED:SOFRAI",
            methodology="Official SOFR Index. Unaltered control series from its actual start.",
        )
        for rec in sofr_idx.itertuples(index=False)
    ]
    write_csv(CLEAN / "sofr_index_official_daily.csv", sofr_idx_rows, CLEAN_FIELDS)
    if not dtb3.empty:
        write_csv(CLEAN / "us_3m_tbill_daily.csv", rate_clean(dtb3, "us_3m_tbill", "Federal Reserve Board via FRED", "H.15", "DTB3", "3-month Treasury bill secondary market rate. Control only."), CLEAN_FIELDS)

    us_usd_rows = [
        clean_row(
            slug="us_money_market_total_return_usd_daily",
            day=rec.date,
            value=float(rec.value),
            currency="PTS",
            unit="index_100_start",
            frequency="daily",
            data_kind="modeled",
            provider="Modeled from NY Fed EFFR then SOFR",
            dataset="EFFR until 2018-04-01, SOFR from 2018-04-02",
            series_id="modeled_effr_sofr_act360",
            quality_status="flagged",
            source_revision="modeled:effr->sofr",
            methodology="index_next = index_t * (1 + rate_t/100 * calendar_days/360). Methods change at SOFR start, not a market event. Unlinked raw EFFR and SOFR are stored separately.",
        )
        for rec in us_tr.itertuples(index=False)
    ]
    write_csv(CLEAN / "us_money_market_total_return_usd_daily.csv", us_usd_rows, CLEAN_FIELDS)

    converted = convert_usd_to_eur(us_tr[["date", "value"]], fx)
    converted["eur_value"] = converted["value"]
    first_eur = float(converted.iloc[0]["eur_value"])
    converted["value"] = 100.0 * converted["eur_value"] / first_eur
    us_eur_rows = []
    for rec in converted.itertuples(index=False):
        us_eur_rows.append(
            clean_row(
                slug="us_money_market_total_return_eur_daily",
                day=rec.date,
                value=float(rec.value),
                currency="PTS",
                unit="index_100_start",
                frequency="daily",
                data_kind="modeled",
                provider="Modeled NY Fed rates + ECB EXR",
                dataset="EFFR/SOFR TR converted with EXR.D.USD.EUR.SP00.A",
                series_id="modeled_effr_sofr_act360_eur",
                quality_status="flagged",
                source_revision="modeled:effr->sofr+ecb:exr",
                methodology="Unhedged USD money-market wealth index converted with historical ECB USD/EUR and rebased to 100.",
                extra={
                    "raw_usd_value": decimal(float(rec.raw_usd_value), 8),
                    "eur_value": decimal(float(rec.eur_value), 8),
                    "usd_per_eur": decimal(float(rec.usd_per_eur), 8),
                    "fx_observation_date": rec.fx_observation_date.isoformat(),
                },
            )
        )
    write_csv(CLEAN / "us_money_market_total_return_eur_daily.csv", us_eur_rows, CLEAN_FIELDS + FX_EXTRA)
    write_csv(
        PREPARED / "us_money_market_eur.csv",
        [import_row(date.fromisoformat(r["date"]), float(r["value"]), r["currency"], r["quality_status"], r["source_revision"]) for r in us_eur_rows],
        IMPORT_FIELDS,
    )
    series_checks("us_money_market_eur", converted, True, 0.03)
    series_checks("sofr_index", sofr_idx, True, 0.01)
    series_checks("estr", estr, True, 0.02)

    # Compounding audit: Friday to Monday vs midweek.
    audit_lines = ["Money-market compounding audit", "formula: index_next = index_t * (1 + rate_t/100 * calendar_days/360)", ""]
    for label, tr in [("EUR", euro_tr), ("USD", us_tr)]:
        found = False
        for i in range(len(tr) - 1):
            d0 = tr.iloc[i]["date"]
            d1 = tr.iloc[i + 1]["date"]
            days = int(tr.iloc[i + 1]["accrual_days"])
            if days >= 3 and d0.weekday() == 4:
                rate = float(tr.iloc[i]["rate"])
                expected = float(tr.iloc[i]["value"]) * (1 + rate / 100.0 * days / 360.0)
                actual = float(tr.iloc[i + 1]["value"])
                audit_lines.append(f"{label} weekend {d0} -> {d1} days={days} rate={rate} expected={expected:.10f} actual={actual:.10f} diff={actual-expected:.2e}")
                found = True
                break
        mid = False
        for i in range(len(tr) - 1):
            days = int(tr.iloc[i + 1]["accrual_days"])
            if days == 1:
                rate = float(tr.iloc[i]["rate"])
                expected = float(tr.iloc[i]["value"]) * (1 + rate / 100.0 * 1 / 360.0)
                actual = float(tr.iloc[i + 1]["value"])
                audit_lines.append(f"{label} weekday {tr.iloc[i]['date']} -> {tr.iloc[i+1]['date']} days=1 rate={rate} expected={expected:.10f} actual={actual:.10f} diff={actual-expected:.2e}")
                mid = True
                break
        if not found or not mid:
            QUALITY_NOTES.append(f"{label} compounding audit incomplete")
    # SOFR index vs modeled SOFR-only TR from official start
    if not sofr_live.empty and not sofr_idx.empty:
        start_day = sofr_idx.iloc[0]["date"]
        sofr_from = sofr_live[sofr_live["date"] >= start_day]
        sofr_only = compound_overnight(sofr_from, start_index=float(sofr_idx.iloc[0]["value"]))
        merged = pd.merge(sofr_only, sofr_idx, on="date", suffixes=("_m", "_o"))
        if len(merged) > 5:
            rel = (merged["value_m"] / merged["value_o"] - 1.0).abs()
            QUALITY_CHECKS.append(
                f"SOFR modeled vs official index: n={len(merged)} median_abs_rel={rel.median():.6e} p99={rel.quantile(0.99):.6e} max={rel.max():.6e}"
            )
    if not fred_eonia.empty:
        cmp = pd.merge(eonia, fred_eonia, on="date", suffixes=("_ecb", "_fred"))
        if not cmp.empty:
            diff = (cmp["value_ecb"] - cmp["value_fred"]).abs()
            QUALITY_CHECKS.append(f"EONIA ECB vs FRED overlap n={len(cmp)} median_abs_diff={diff.median():.6f} max={diff.max():.6f}")
    write_text(NOTES / "money_market_methodology.txt", "\n".join(audit_lines) + "\n")

    add_manifest(
        file_name="euro_money_market.csv",
        asset_slug="euro_money_market_total_return_daily",
        provider="Modeled from ECB",
        dataset="EONIA then €STR ACT/360",
        series_id="modeled_eonia_estr_act360",
        source_url="https://data.ecb.europa.eu/data/datasets/EST",
        download_url="https://data-api.ecb.europa.eu/service/data/EST/B.EU000A2X2A25.WT",
        frequency="daily",
        currency="PTS",
        unit="index_100_start",
        coverage_start=coverage(euro_tr)[0],
        coverage_end=coverage(euro_tr)[1],
        license_note="Modeled from official ECB rates. ECB reuse with attribution.",
        data_kind="modeled",
        transformation="EONIA < 2019-10-01, €STR from 2019-10-01, ACT/360, no weekend dummy rows.",
        update_frequency="business_daily",
        expected_release_lag="next_business_morning_CET",
    )
    add_manifest(
        file_name="us_money_market_eur.csv",
        asset_slug="us_money_market_total_return_eur_daily",
        provider="Modeled from NY Fed + ECB FX",
        dataset="EFFR then SOFR ACT/360, unhedged EUR",
        series_id="modeled_effr_sofr_act360_eur",
        source_url="https://www.newyorkfed.org/markets/reference-rates",
        download_url="https://markets.newyorkfed.org/read?productCode=50&eventCodes=520&format=csv",
        frequency="daily",
        currency="PTS",
        unit="index_100_start",
        coverage_start=coverage(converted)[0],
        coverage_end=coverage(converted)[1],
        license_note="NY Fed reference rates subject to NY Fed terms of use. Private research. ECB FX reuse with attribution.",
        data_kind="modeled",
        transformation="EFFR until day before 2018-04-02, then SOFR. Convert USD wealth index with historical ECB FX, rebase 100.",
        update_frequency="business_daily",
        expected_release_lag="next_business_morning_ET",
    )
    add_manifest(
        file_name="eonia_daily.csv",
        asset_slug="eonia",
        provider="ECB",
        dataset="EON",
        series_id="EON.D.EONIA_TO.RATE",
        source_url="https://data.ecb.europa.eu/data/datasets/EON/EON.D.EONIA_TO.RATE",
        download_url="https://data-api.ecb.europa.eu/service/data/EON/D.EONIA_TO.RATE",
        frequency="daily",
        currency="percent",
        unit="percent_per_annum",
        coverage_start=coverage(eonia)[0],
        coverage_end=coverage(eonia)[1],
        license_note="ECB statistics. After Oct 2019 this series is recalibrated EONIA (€STR+8.5bp).",
        data_kind="observed",
        transformation="Unaltered. Recalibrated segment is not used in the chained wealth index.",
        update_frequency="static_after_2021-12-31",
        expected_release_lag="none",
    )
    add_manifest(
        file_name="estr_daily.csv",
        asset_slug="estr",
        provider="ECB",
        dataset="EST",
        series_id="EST.B.EU000A2X2A25.WT",
        source_url="https://data.ecb.europa.eu/data/datasets/EST/EST.B.EU000A2X2A25.WT",
        download_url="https://data-api.ecb.europa.eu/service/data/EST/B.EU000A2X2A25.WT",
        frequency="daily",
        currency="percent",
        unit="percent_per_annum",
        coverage_start=coverage(estr)[0],
        coverage_end=coverage(estr)[1],
        license_note="ECB statistics, reuse with attribution.",
        data_kind="observed",
        transformation="Official €STR rate, no interpolation.",
        update_frequency="business_daily",
        expected_release_lag="next_business_morning_CET",
    )
    add_manifest(
        file_name="estr_compounded_index_official.csv",
        asset_slug="estr_compounded_index_official",
        provider="ECB",
        dataset="EST",
        series_id="EST.B.EU000A2QQF08.CI",
        source_url="https://data.ecb.europa.eu/data/datasets/EST",
        download_url="https://data-api.ecb.europa.eu/service/data/EST/B.EU000A2QQF08.CI",
        frequency="daily",
        currency="PTS",
        unit="index",
        coverage_start=coverage(estr_idx)[0],
        coverage_end=coverage(estr_idx)[1],
        license_note="ECB statistics, reuse with attribution.",
        data_kind="total_return_index",
        transformation="Unaltered official compounded €STR index.",
        update_frequency="business_daily",
        expected_release_lag="next_business_morning_CET",
    )
    add_manifest(
        file_name="effr_daily.csv",
        asset_slug="effr",
        provider="Federal Reserve Bank of New York",
        dataset="EFFR",
        series_id="NYFED:EFFR",
        source_url="https://www.newyorkfed.org/markets/reference-rates/effr",
        download_url="https://markets.newyorkfed.org/read?productCode=50&eventCodes=500&format=csv",
        frequency="daily",
        currency="percent",
        unit="percent_per_annum",
        coverage_start=coverage(effr)[0],
        coverage_end=coverage(effr)[1],
        license_note="NY Fed terms of use apply.",
        data_kind="observed",
        transformation="Unaltered official EFFR.",
        update_frequency="business_daily",
        expected_release_lag="next_business_morning_ET",
    )
    add_manifest(
        file_name="sofr_daily.csv",
        asset_slug="sofr",
        provider="Federal Reserve Bank of New York",
        dataset="SOFR",
        series_id="NYFED:SOFR",
        source_url="https://www.newyorkfed.org/markets/reference-rates/sofr",
        download_url="https://markets.newyorkfed.org/read?productCode=50&eventCodes=520&format=csv",
        frequency="daily",
        currency="percent",
        unit="percent_per_annum",
        coverage_start=coverage(sofr)[0],
        coverage_end=coverage(sofr)[1],
        license_note="NY Fed terms of use apply.",
        data_kind="observed",
        transformation="Unaltered official SOFR from 2018-04-02.",
        update_frequency="business_daily",
        expected_release_lag="next_business_morning_ET",
    )
    add_manifest(
        file_name="sofr_index_official_daily.csv",
        asset_slug="sofr_index_official_daily",
        provider="Federal Reserve Bank of New York",
        dataset="SOFR Index",
        series_id="NYFED:SOFRAI",
        source_url="https://www.newyorkfed.org/markets/reference-rates/sofr-averages-and-index",
        download_url="https://markets.newyorkfed.org/read?productCode=50&eventCodes=525&format=csv",
        frequency="daily",
        currency="PTS",
        unit="index",
        coverage_start=coverage(sofr_idx)[0],
        coverage_end=coverage(sofr_idx)[1],
        license_note="NY Fed SOFR Index, terms of use apply.",
        data_kind="total_return_index",
        transformation="Unaltered official index.",
        update_frequency="business_daily",
        expected_release_lag="next_business_morning_ET",
    )
    return {
        "euro_tr": euro_tr,
        "us_tr": us_tr,
        "estr": estr,
        "sofr": sofr,
        "effr": effr,
        "eonia": eonia,
        "dfr": dfr,
        "estr_idx": estr_idx,
        "sofr_idx": sofr_idx,
    }


def process_inflation() -> dict[str, Any]:
    de = parse_eurostat_jsonstat(RAW / "eurostat_hicp_de.json")
    ea = parse_eurostat_jsonstat(RAW / "eurostat_hicp_ea.json")
    us_parts = [parse_bls_json(RAW / "bls_cpiu.json")]
    if (RAW / "bls_cpiu_recent.json").exists():
        us_parts.append(parse_bls_json(RAW / "bls_cpiu_recent.json"))
    us = pd.concat(us_parts, ignore_index=True).drop_duplicates("date").sort_values("date")
    us_fred = parse_fred_json(RAW / "fred_cpiaucns.json") if (RAW / "fred_cpiaucns.json").exists() else pd.DataFrame()
    if not us_fred.empty:
        extra = us_fred.copy()
        extra["date"] = extra["date"].map(lambda d: month_end(d.year, d.month))
        extra["reference_period"] = extra["date"].map(lambda d: d.strftime("%Y-%m"))
        extra["status_flag"] = "fred_distributor"
        have = set(us["date"])
        missing = extra[~extra["date"].isin(have)]
        if not missing.empty:
            QUALITY_NOTES.append(
                f"US CPI-U: BLS unregistered API covers 10 years; added {len(missing)} months from FRED CPIAUCNS (BLS primary via FRED). "
                "October 2025 is omitted in both sources (BLS footnote: lapse in appropriations). Not interpolated."
            )
            us = pd.concat([us, missing[["date", "value", "reference_period", "status_flag"]]], ignore_index=True)
            us = us.drop_duplicates("date").sort_values("date")
    oct2025 = month_end(2025, 10)
    if oct2025 not in set(us["date"]):
        QUALITY_NOTES.append("US CPI-U October 2025 has no published index. Gap left open.")

    def hicp_rows(df: pd.DataFrame, slug: str, geo: str) -> tuple[list[dict[str, str]], pd.DataFrame]:
        time_col = "time" if "time" in df.columns else "TIME_PERIOD"
        out_df_rows = []
        rows = []
        last_period = max(str(v) for v in df[time_col])
        for rec in df.itertuples(index=False):
            period = str(getattr(rec, time_col))
            day = period_to_month_end(period)
            flag = str(getattr(rec, "status_flag", "") or "")
            quality = quality_from_flag(flag, period == last_period)
            rows.append(
                clean_row(
                    slug=slug,
                    day=day,
                    value=float(rec.value),
                    currency="PTS",
                    unit="index_2025_100",
                    frequency="monthly",
                    data_kind="observed",
                    provider="Eurostat",
                    dataset="prc_hicp_minr",
                    series_id=f"prc_hicp_minr/M.I25.TOTAL.{geo}",
                    quality_status=quality,
                    source_revision=f"eurostat:prc_hicp_minr:{flag or 'final'}",
                    methodology="Official HICP all-items, not seasonally adjusted, ECOICOP ver.2, unit I25 (2025=100). Eurostat published a consistent back series from 2016 after the 2026 rebase. Inflation is not an asset class.",
                    reference_period=period[:7],
                    proxy_for="",
                )
            )
            out_df_rows.append({"date": day, "value": float(rec.value), "period": period[:7], "quality": quality})
        return rows, pd.DataFrame(out_df_rows).sort_values("date")

    de_rows, de_df = hicp_rows(de, "inflation_germany_hicp_monthly", "DE")
    ea_rows, ea_df = hicp_rows(ea, "inflation_euro_area_hicp_monthly", "EA")
    write_series_files("inflation_germany_hicp_monthly.csv", "inflation_germany.csv", de_rows)
    write_series_files("inflation_euro_area_hicp_monthly.csv", "inflation_euro_area.csv", ea_rows)

    us_rows = []
    last = us["date"].max()
    for rec in us.itertuples(index=False):
        quality = quality_from_flag(rec.status_flag, rec.date == last)
        us_rows.append(
            clean_row(
                slug="inflation_us_cpi_u_monthly",
                day=rec.date,
                value=float(rec.value),
                currency="PTS",
                unit="index_1982_84_100",
                frequency="monthly",
                data_kind="observed",
                provider="U.S. Bureau of Labor Statistics",
                dataset="CPI-U NSA All items",
                series_id="CUUR0000SA0",
                quality_status=quality,
                source_revision=f"bls:CUUR0000SA0:{rec.status_flag or 'final'}",
                methodology="CPI-U, U.S. city average, all items, not seasonally adjusted. Price index levels, not y/y rates. FOMC PCE projections are a different concept and are not stored here.",
                reference_period=rec.reference_period,
            )
        )
    write_series_files("inflation_us_cpi_u_monthly.csv", "inflation_us.csv", us_rows)
    series_checks("inflation_germany", de_df, False, 0.03)
    series_checks("inflation_euro_area", ea_df, False, 0.03)
    series_checks("inflation_us", us, False, 0.03)
    if not us_fred.empty:
        us_month = us.copy()
        us_month["period"] = us_month["reference_period"]
        fred_m = us_fred.copy()
        fred_m["period"] = fred_m["date"].map(lambda d: d.strftime("%Y-%m"))
        merged = pd.merge(us_month, fred_m, on="period", suffixes=("_bls", "_fred"))
        if not merged.empty:
            rel = (merged["value_bls"] / merged["value_fred"] - 1).abs()
            QUALITY_CHECKS.append(f"US CPI BLS vs FRED CPIAUCNS n={len(merged)} median_abs_rel={rel.median():.6e} max={rel.max():.6e}")

    write_text(
        NOTES / "real_return_formula.txt",
        "Inflation is not added to nominal returns.\n"
        "real_growth_factor = nominal_growth_factor / inflation_index_factor\n"
        "real_return = real_growth_factor - 1\n"
        "Do not create a synthetic daily inflation path. Use month-end index levels only.\n"
        "Default personal EUR purchasing-power series for this backtester: German HICP (prc_hicp_minr, TOTAL, I25).\n"
        "Euro-area HICP and US CPI-U are optional comparison series.\n"
        "FOMC projections are PCE, not CPI, and must stay labeled PCE.\n",
    )
    for slug, df, provider, dataset, series_id, url, dl, unit, lag in [
        (
            "inflation_germany_hicp_monthly",
            de_df,
            "Eurostat",
            "prc_hicp_minr",
            "M.I25.TOTAL.DE",
            "https://ec.europa.eu/eurostat/web/hicp/information-data",
            "https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hicp_minr?geo=DE&coicop18=TOTAL&unit=I25",
            "index_2025_100",
            "flash_end_of_month_final_mid_next_month",
        ),
        (
            "inflation_euro_area_hicp_monthly",
            ea_df,
            "Eurostat",
            "prc_hicp_minr",
            "M.I25.TOTAL.EA",
            "https://ec.europa.eu/eurostat/web/hicp/information-data",
            "https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hicp_minr?geo=EA&coicop18=TOTAL&unit=I25",
            "index_2025_100",
            "flash_end_of_month_final_mid_next_month",
        ),
        (
            "inflation_us_cpi_u_monthly",
            us,
            "BLS",
            "CPI-U NSA",
            "CUUR0000SA0",
            "https://www.bls.gov/cpi/factsheets/cpi-series-ids.htm",
            "https://api.bls.gov/publicAPI/v2/timeseries/data/CUUR0000SA0",
            "index_1982_84_100",
            "about_10_to_15_days_after_month_end",
        ),
    ]:
        add_manifest(
            file_name=slug + ".csv",
            asset_slug=slug,
            provider=provider,
            dataset=dataset,
            series_id=series_id,
            source_url=url,
            download_url=dl,
            frequency="monthly",
            currency="PTS",
            unit=unit,
            coverage_start=coverage(df)[0],
            coverage_end=coverage(df)[1],
            license_note="Official statistics, reuse with attribution / US government public domain for BLS.",
            data_kind="observed",
            transformation="Month-end calendar date assigned to the reference month. No daily interpolation. Official rebased I25 used for HICP.",
            update_frequency="monthly",
            expected_release_lag=lag,
        )
    return {"de": de_df, "ea": ea_df, "us": us}


def last_rate(df: pd.DataFrame) -> float | None:
    if df is None or df.empty:
        return None
    return float(df.sort_values("date").iloc[-1]["value"])


def add_forecast(rows: list[dict[str, str]], **kwargs: Any) -> None:
    row = {key: "" for key in FORECAST_FIELDS}
    for key, value in kwargs.items():
        if key in row:
            row[key] = str(value)
    rows.append(row)


def build_forecasts(bonds: dict[str, Any], mm: dict[str, Any], comm: dict[str, Any]) -> None:
    rows: list[dict[str, str]] = []
    ytm = float(bonds["snap_h"].get("ytm_pct") or 4.00)
    duration = float(bonds["snap_h"].get("effective_duration") or 6.05)
    ter = float(bonds["snap_h"].get("ter_pct") or 0.10)
    estr = last_rate(mm["estr"]) or 1.92
    sofr = last_rate(mm["sofr"]) or 3.62
    hedge = estr - sofr
    roll = 0.15
    credit = 0.10
    # expected_total_return ≈ carry + roll_down - duration * yield_change - credit_loss - fees + hedge_effect
    def ig_tr(yield_change: float, extra_credit: float, extra_hedge: float) -> float:
        return ytm + roll - duration * yield_change - (credit + extra_credit) - ter + (hedge + extra_hedge)

    ig_method = (
        f"scenario, not a published CMA. carry=YTM {ytm:.2f}%, roll_down={roll:.2f}pp assumption, "
        f"credit_loss={credit:.2f}pp, TER={ter:.2f}%, hedge_effect=€STR({estr:.2f})-SOFR({sofr:.2f})={hedge:.2f}pp, "
        f"duration={duration:.2f}. 2026 conservative = +100bp yield and +20bp extra credit; "
        f"optimistic = -50bp yield. Later years earn the new carry with no further duration hit. "
        f"YTM is local-currency portfolio yield, so EUR-hedged expected return subtracts the USD-EUR hedge cost."
    )
    for year in range(2026, 2031):
        if year == 2026:
            cons, base, opt = ig_tr(0.01, 0.20, -0.20), ig_tr(0.0, 0.0, 0.0), ig_tr(-0.005, -0.05, 0.10)
        else:
            cons, base, opt = ig_tr(0.01, 0.20, -0.20) + duration * 0.01, ig_tr(0.0, 0.0, 0.0), ig_tr(-0.005, -0.05, 0.10) - duration * 0.005
        add_forecast(
            rows,
            asset_slug="global_ig_bonds_eur_hedged_total_return",
            year=year,
            metric="expected_total_return_pct",
            conservative=decimal(cons, 4),
            base=decimal(base, 4),
            optimistic=decimal(opt, 4),
            unit="percent_per_annum",
            data_kind="scenario",
            as_of_date=RESEARCH_ISO,
            source_name="Carry-roll-duration scenario on iShares Global Aggregate EUR Hedged characteristics",
            source_url="https://www.ishares.com/uk/individual/en/products/291770/ishares-core-global-aggregate-bond-ucits-etf",
            methodology=ig_method,
        )

    # World Bank CMO April 2026 spot index forecasts 2026-2027 only
    wb_url = "https://thedocs.worldbank.org/en/doc/f3138644a1e8e2bb631399ae11d6c408-0050012026/related/CMO-April-2026-Forecasts.pdf"
    add_forecast(
        rows,
        asset_slug="broad_commodities_spot_reference",
        year=2026,
        metric="spot_index_change_pct",
        conservative="8.0",
        base="15.5",
        optimistic="23.0",
        unit="percent_per_annum",
        data_kind="forecast",
        as_of_date="2026-04-01",
        source_name="World Bank CMO April 2026 Table 1 Total index",
        source_url=wb_url,
        methodology="Official WB Total index 2026f = +15.5% (conservative/optimistic use the published October-2025 revision gap of 8.0/23.0 only as a documented band around the official base, still labeled from Table 1 differences). This is a spot/reference index change, not futures total return.",
    )
    add_forecast(
        rows,
        asset_slug="broad_commodities_spot_reference",
        year=2027,
        metric="spot_index_change_pct",
        conservative="-18.0",
        base="-12.3",
        optimistic="-5.0",
        unit="percent_per_annum",
        data_kind="forecast",
        as_of_date="2026-04-01",
        source_name="World Bank CMO April 2026 Table 1 Total index",
        source_url=wb_url,
        methodology="Official WB Total index 2027f = -12.3%. Band is a scenario around the official point, not an official fan chart.",
    )
    comm_method = (
        "scenario for an unhedged EUR investor in a BCOM-style TR ETC. "
        "Not a conversion of Pink Sheet spots into futures TR. "
        "Components: assumed excess return (spot + roll) + USD collateral (SOFR) - TER 0.49% + USD/EUR effect. "
        "2026-2027 base excess return uses WB total-index change as a rough spot term only, then subtracts a 4pp roll drag assumption. "
        "2028-2030 have no official WB column and are scenarios around 0% real plus collateral."
    )
    ter_c = 0.49
    coll = sofr
    for year, spot_base, roll_drag in [(2026, 15.5, -4.0), (2027, -12.3, -3.0), (2028, 0.0, -2.0), (2029, 0.0, -2.0), (2030, 0.0, -2.0)]:
        fx_cons, fx_base, fx_opt = -6.0, 0.0, 4.0
        base = spot_base + roll_drag + coll - ter_c + fx_base
        cons = (spot_base - 8) + (roll_drag - 2) + (coll - 1) - ter_c + fx_cons
        opt = (spot_base + 8) + (roll_drag + 1) + (coll + 0.5) - ter_c + fx_opt
        add_forecast(
            rows,
            asset_slug="broad_commodities_total_return_eur",
            year=year,
            metric="expected_total_return_pct",
            conservative=decimal(cons, 4),
            base=decimal(base, 4),
            optimistic=decimal(opt, 4),
            unit="percent_per_annum",
            data_kind="scenario",
            as_of_date=RESEARCH_ISO,
            source_name="Scenario around World Bank CMO spot outlook plus roll, SOFR collateral, TER and USD/EUR",
            source_url=wb_url,
            methodology=comm_method,
        )

    fomc_url = "https://www.federalreserve.gov/monetarypolicy/fomcprojtabl20260617.htm"
    # Median, CT low, CT high, range low, range high for fed funds 2026-2028 and longer run
    feds = {
        2026: (3.8, 3.6, 4.1, 3.4, 4.4),
        2027: (3.6, 3.1, 3.9, 2.9, 4.4),
        2028: (3.4, 3.1, 3.6, 2.9, 3.9),
    }
    for year, (med, ct_lo, ct_hi, r_lo, r_hi) in feds.items():
        add_forecast(
            rows,
            asset_slug="us_money_market",
            year=year,
            metric="federal_funds_rate_median_pct",
            conservative=decimal(r_lo, 2),
            base=decimal(med, 2),
            optimistic=decimal(r_hi, 2),
            unit="percent_per_annum",
            data_kind="forecast",
            as_of_date="2026-06-17",
            source_name="FOMC SEP 17 June 2026 median / range of appropriate fed funds",
            source_url=fomc_url,
            methodology="Official FOMC median in base; conservative/optimistic are the published range, not a modeled path. End-of-year midpoint of target range.",
        )
        add_forecast(
            rows,
            asset_slug="us_money_market",
            year=year,
            metric="federal_funds_rate_central_tendency_pct",
            conservative=decimal(ct_lo, 2),
            base=decimal(med, 2),
            optimistic=decimal(ct_hi, 2),
            unit="percent_per_annum",
            data_kind="forecast",
            as_of_date="2026-06-17",
            source_name="FOMC SEP 17 June 2026 central tendency",
            source_url=fomc_url,
            methodology="Official central tendency. Base repeats the median for a single-column layout.",
        )
        add_forecast(
            rows,
            asset_slug="us_money_market",
            year=year,
            metric="expected_wealth_growth_pct",
            conservative=decimal(r_lo, 2),
            base=decimal(med, 2),
            optimistic=decimal(r_hi, 2),
            unit="percent_per_annum",
            data_kind="scenario",
            as_of_date="2026-06-17",
            source_name="Modeled ACT/360 wealth growth from FOMC fed-funds path",
            source_url=fomc_url,
            methodology="Separate modeled future wealth path. Uses the SEP rate as the annualised overnight rate; not an official index forecast.",
        )
    for year in (2029, 2030):
        add_forecast(
            rows,
            asset_slug="us_money_market",
            year=year,
            metric="federal_funds_rate_median_pct",
            conservative="2.9",
            base="3.1",
            optimistic="3.9",
            unit="percent_per_annum",
            data_kind="scenario",
            as_of_date="2026-06-17",
            source_name="FOMC longer-run value used only as a scenario after the SEP horizon",
            source_url=fomc_url,
            methodology="SEP has no 2029 or 2030 column. Longer-run median 3.1% and range 2.9-3.9 are scenarios, not year-specific forecasts.",
        )
    pce = {2026: (3.6, 3.5, 3.7, 2.7, 4.1), 2027: (2.3, 2.2, 2.5, 1.9, 2.8), 2028: (2.0, 2.0, 2.1, 2.0, 2.3)}
    for year, (med, ct_lo, ct_hi, r_lo, r_hi) in pce.items():
        add_forecast(
            rows,
            asset_slug="inflation_us_pce",
            year=year,
            metric="pce_inflation_pct",
            conservative=decimal(r_lo, 2),
            base=decimal(med, 2),
            optimistic=decimal(r_hi, 2),
            unit="percent_per_annum",
            data_kind="forecast",
            as_of_date="2026-06-17",
            source_name="FOMC SEP 17 June 2026 PCE inflation. Not CPI.",
            source_url=fomc_url,
            methodology="Headline PCE, Q4/Q4. Must not be treated as CPI-U.",
        )

    ecb_url = "https://www.ecb.europa.eu/press/projections/html/ecb.projections202606_eurosystemstaff~a495110f8d.en.html"
    # baseline / milder / adverse from June 2026 Eurosystem staff
    ea_inf = {2026: (3.0, 2.9, 3.3), 2027: (2.3, 1.8, 3.0), 2028: (2.0, 1.8, 2.3)}
    for year, (base, mild, adv) in ea_inf.items():
        add_forecast(
            rows,
            asset_slug="inflation_euro_area_hicp_monthly",
            year=year,
            metric="hicp_inflation_pct",
            conservative=decimal(mild, 2),
            base=decimal(base, 2),
            optimistic=decimal(adv, 2),
            unit="percent_per_annum",
            data_kind="forecast",
            as_of_date="2026-06-11",
            source_name="Eurosystem staff macroeconomic projections June 2026",
            source_url=ecb_url,
            methodology="Official baseline in base. Conservative uses the published milder scenario, optimistic the adverse scenario. Severe scenario is not used as optimistic.",
        )
    add_forecast(
        rows,
        asset_slug="inflation_euro_area_hicp_monthly",
        year=2029,
        metric="hicp_inflation_pct",
        conservative="1.7",
        base="2.0",
        optimistic="2.4",
        unit="percent_per_annum",
        data_kind="scenario",
        as_of_date=RESEARCH_ISO,
        source_name="Scenario around the ECB 2% aim after the 2028 projection horizon",
        source_url=ecb_url,
        methodology="ECB staff projections end in 2028. 2% is the aim, not a 2029 point forecast.",
    )
    add_forecast(
        rows,
        asset_slug="inflation_euro_area_hicp_monthly",
        year=2030,
        metric="hicp_inflation_pct",
        conservative="1.7",
        base="2.0",
        optimistic="2.4",
        unit="percent_per_annum",
        data_kind="scenario",
        as_of_date=RESEARCH_ISO,
        source_name="Scenario around the ECB 2% aim after the 2028 projection horizon",
        source_url=ecb_url,
        methodology="ECB staff projections end in 2028. 2% is the aim, not a 2030 point forecast.",
    )
    add_forecast(
        rows,
        asset_slug="euro_money_market",
        year=2026,
        metric="short_rate_assumption_pct",
        conservative=decimal(max(estr - 0.5, 0), 2),
        base=decimal(estr, 2),
        optimistic=decimal(estr + 0.5, 2),
        unit="percent_per_annum",
        data_kind="scenario",
        as_of_date=RESEARCH_ISO,
        source_name="No official ECB policy-rate path; scenario around latest €STR",
        source_url="https://data.ecb.europa.eu/data/datasets/EST",
        methodology="The ECB does not publish a staff policy-rate path. This is not an official forecast. Band +/-50bp around latest official €STR.",
    )
    for year in range(2027, 2031):
        add_forecast(
            rows,
            asset_slug="euro_money_market",
            year=year,
            metric="short_rate_assumption_pct",
            conservative="1.5",
            base="2.0",
            optimistic="2.5",
            unit="percent_per_annum",
            data_kind="scenario",
            as_of_date=RESEARCH_ISO,
            source_name="Scenario fading toward the ECB 2% inflation aim, not a published rate path",
            source_url=ecb_url,
            methodology="Not an official ECB rate forecast. 2% is the inflation aim used only as a long-run cash-rate scenario.",
        )

    de_url = "https://economy-finance.ec.europa.eu/economic-surveillance-eu-member-states/country-pages-including-country-reports/germany/economic-forecast-germany_en"
    add_forecast(
        rows,
        asset_slug="inflation_germany_hicp_monthly",
        year=2026,
        metric="hicp_inflation_pct",
        conservative="2.5",
        base="2.9",
        optimistic="3.4",
        unit="percent_per_annum",
        data_kind="forecast",
        as_of_date="2026-05-19",
        source_name="European Commission Spring 2026 economic forecast for Germany HICP",
        source_url=de_url,
        methodology="Official EC base 2.9% for 2026. Conservative/optimistic are a 40bp/50bp scenario band, not an official fan chart.",
    )
    add_forecast(
        rows,
        asset_slug="inflation_germany_hicp_monthly",
        year=2027,
        metric="hicp_inflation_pct",
        conservative="2.3",
        base="2.7",
        optimistic="3.1",
        unit="percent_per_annum",
        data_kind="forecast",
        as_of_date="2026-05-19",
        source_name="European Commission Spring 2026 economic forecast for Germany HICP",
        source_url=de_url,
        methodology="Official EC base 2.7% for 2027. Band is a scenario around that point.",
    )
    for year in (2028, 2029, 2030):
        add_forecast(
            rows,
            asset_slug="inflation_germany_hicp_monthly",
            year=year,
            metric="hicp_inflation_pct",
            conservative="1.7",
            base="2.0",
            optimistic="2.4",
            unit="percent_per_annum",
            data_kind="scenario",
            as_of_date=RESEARCH_ISO,
            source_name="Scenario after the Commission two-year horizon, around 2%",
            source_url=de_url,
            methodology="EC forecast for Germany in this vintage ends in 2027. 2% is a scenario, not Destatis official.",
        )

    spf_url = "https://www.philadelphiafed.org/surveys-and-data/real-time-data-research/spf-q2-2026"
    # SPF Q2 2026 CPI annual: 2026 3.5, 2027 2.5, 2028 2.4, long-term 2026-2030 2.60
    spf = {2026: 3.5, 2027: 2.5, 2028: 2.4}
    for year, base in spf.items():
        add_forecast(
            rows,
            asset_slug="inflation_us_cpi_u_monthly",
            year=year,
            metric="cpi_u_inflation_pct",
            conservative=decimal(base - 0.4, 2),
            base=decimal(base, 2),
            optimistic=decimal(base + 0.5, 2),
            unit="percent_per_annum",
            data_kind="forecast",
            as_of_date="2026-05-15",
            source_name="Philadelphia Fed Survey of Professional Forecasters Q2 2026, annual CPI",
            source_url=spf_url,
            methodology="SPF median CPI in base. Band is a scenario around that median, not the SPF probability bins. Distinct from FOMC PCE.",
        )
    for year in (2029, 2030):
        add_forecast(
            rows,
            asset_slug="inflation_us_cpi_u_monthly",
            year=year,
            metric="cpi_u_inflation_pct",
            conservative="2.2",
            base="2.6",
            optimistic="3.0",
            unit="percent_per_annum",
            data_kind="scenario",
            as_of_date="2026-05-15",
            source_name="SPF long-term 2026-2030 average 2.60% used as a scenario for missing years",
            source_url=spf_url,
            methodology="SPF publishes a 2026-2030 average, not separate 2029 and 2030 annual CPI forecasts. Treated as scenario.",
        )

    write_csv(FORECASTS / "forecasts_2026_2030.csv", rows, FORECAST_FIELDS)
    add_manifest(
        file_name="forecasts_2026_2030.csv",
        asset_slug="multi",
        provider="FOMC, ECB, European Commission, Philadelphia Fed, World Bank, local scenarios",
        dataset="forecasts_2026_2030",
        series_id="",
        source_url="",
        download_url="",
        frequency="annual",
        currency="mixed",
        unit="mixed",
        coverage_start="2026",
        coverage_end="2030",
        license_note="Forecasts and scenarios only. Not mixed into historical import files.",
        data_kind="forecast_and_scenario",
        transformation="Direct official values kept as data_kind=forecast. Self-derived bands and years beyond a source horizon are data_kind=scenario.",
        update_frequency="with_source_publications",
        expected_release_lag="see_source",
    )


def sample_check(name: str, df: pd.DataFrame, expected: list[tuple[str, float, float]]) -> None:
    indexed = {rec.date.isoformat(): float(rec.value) for rec in df.itertuples(index=False)}
    ok = 0
    for day, target, tol in expected:
        if day not in indexed:
            QUALITY_NOTES.append(f"{name} sample {day} missing")
            continue
        if abs(indexed[day] - target) <= tol:
            ok += 1
        else:
            QUALITY_NOTES.append(f"{name} sample {day} got {indexed[day]} expected ~{target} tol={tol}")
    QUALITY_CHECKS.append(f"{name} source samples matched {ok}/{len(expected)}")


def fx_conversion_check(converted: pd.DataFrame, n: int = 5) -> None:
    if converted.empty or not {"raw_usd_value", "eur_value", "usd_per_eur"} <= set(converted.columns):
        QUALITY_NOTES.append("FX conversion check skipped, empty frame or missing columns")
        return
    pick = converted.iloc[:: max(len(converted) // n, 1)].head(n)
    bad = 0
    for rec in pick.itertuples(index=False):
        usd = float(rec.raw_usd_value)
        fx_val = float(rec.usd_per_eur)
        eur = float(rec.eur_value)
        expected = usd / fx_val
        if abs(expected - eur) > 1e-6 * max(1.0, abs(expected)):
            bad += 1
    QUALITY_CHECKS.append(f"FX conversion identities failed={bad} of {len(pick)}")


def write_docs(bonds: dict[str, Any], comm: dict[str, Any], mm: dict[str, Any], inf: dict[str, Any]) -> None:
    failed = [row for row in DOWNLOAD_LOG if not str(row.get("status", "")).startswith("ok") and "copied" not in str(row.get("status", ""))]
    ok = [row for row in DOWNLOAD_LOG if str(row.get("status", "")).startswith("ok") or "copied" in str(row.get("status", ""))]
    files = sorted(p.relative_to(PKG).as_posix() for p in PKG.rglob("*") if p.is_file())
    table = [
        ("global_ig_bonds_eur_hedged", "iShares EUNA.DE proxy IE00BDBRDM35", coverage(bonds["euna"])[0], coverage(bonds["euna"])[1], "daily", "EUR", "adjusted_close_proxy", str(len(bonds["euna"]))),
        ("global_ig_bonds_unhedged_eur", "iShares AGGG.L adj close + ECB FX", "", "", "daily", "PTS", "adjusted_close_proxy", ""),
        ("broad_commodities_total_return_eur", "WisdomTree AIGC.L + ECB FX", coverage(comm["converted"])[0], coverage(comm["converted"])[1], "daily", "PTS", "adjusted_close_proxy", str(len(comm["converted"]))),
        ("broad_commodities_spot_reference", "World Bank Pink Sheet Total Index", coverage(comm["pink"])[0], coverage(comm["pink"])[1], "monthly", "USD", "observed", str(len(comm["pink"]))),
        ("euro_money_market", "Modeled EONIA then €STR ACT/360", coverage(mm["euro_tr"])[0], coverage(mm["euro_tr"])[1], "daily", "PTS", "modeled", str(len(mm["euro_tr"]))),
        ("us_money_market_eur", "Modeled EFFR then SOFR, unhedged EUR", coverage(mm["us_tr"])[0], coverage(mm["us_tr"])[1], "daily", "PTS", "modeled", str(len(mm["us_tr"]))),
        ("sofr_index_official", "NY Fed SOFR Index", coverage(mm["sofr_idx"])[0], coverage(mm["sofr_idx"])[1], "daily", "PTS", "total_return_index", str(len(mm["sofr_idx"]))),
        ("inflation_germany", "Eurostat HICP I25 TOTAL DE", coverage(inf["de"])[0], coverage(inf["de"])[1], "monthly", "PTS", "observed", str(len(inf["de"]))),
        ("inflation_euro_area", "Eurostat HICP I25 TOTAL EA", coverage(inf["ea"])[0], coverage(inf["ea"])[1], "monthly", "PTS", "observed", str(len(inf["ea"]))),
        ("inflation_us", "BLS CPI-U CUUR0000SA0", coverage(inf["us"])[0], coverage(inf["us"])[1], "monthly", "PTS", "observed", str(len(inf["us"]))),
    ]
    # fix unhedged coverage using bonds converted file
    unhedged_path = CLEAN / "global_ig_bonds_unhedged_eur.csv"
    if unhedged_path.exists():
        u = pd.read_csv(unhedged_path, parse_dates=["date"])
        table[1] = (
            "global_ig_bonds_unhedged_eur",
            "iShares AGGG.L adj close + ECB FX",
            u["date"].min().date().isoformat(),
            u["date"].max().date().isoformat(),
            "daily",
            "PTS",
            "adjusted_close_proxy",
            str(len(u)),
        )

    quality = [
        f"# DATA_QUALITY_REPORT",
        "",
        f"Research date: {RESEARCH_ISO}",
        f"Generated at: {RETRIEVED}",
        "",
        "## Downloads",
        "",
        f"- successful or copied: {len(ok)}",
        f"- failed: {len(failed)}",
        "",
    ]
    if failed:
        quality.append("### Failed downloads")
        quality.append("")
        for row in failed:
            quality.append(f"- `{row['file_name']}` — {row['status'][:220]} — {row['url']}")
        quality.append("")
    quality += [
        "## What is a published total-return index?",
        "",
        "- Official compounded €STR index (`EST.B.EU000A2QQF08.CI`) and official SOFR Index are published total-return / compounded indices.",
        "- Bloomberg Global Aggregate and S&P GSCI / BCOM official histories were **not** obtained as free redistributable TR indices.",
        "",
        "## What is a fund/ETF proxy?",
        "",
        "- `global_ig_bonds_eur_hedged`: iShares Core Global Aggregate Bond UCITS ETF EUR Hedged Acc, ISIN IE00BDBRDM35, EUNA.DE adjusted close.",
        "- Comparison: Vanguard Global Aggregate EUR Hedged Acc, ISIN IE00BG47KH54, VAGF.DE, stored separately, not chained.",
        "- `global_ig_bonds_unhedged_eur`: iShares Core Global Aggregate Bond UCITS ETF USD Dist, ISIN IE00B3F81409, AGGG.L adjusted close, ECB FX, rebased 100.",
        "- `broad_commodities_total_return_eur`: WisdomTree Broad Commodities ETC, ISIN GB00B15KY989, AIGC.L, ECB FX, rebased 100.",
        "- Invesco CMOD.L stored as comparison only.",
        "",
        "## What is modeled?",
        "",
        "- Euro money-market wealth index: EONIA until 2019-09-30, €STR from 2019-10-01, ACT/360.",
        "- US money-market wealth index: EFFR until 2018-04-01, SOFR from 2018-04-02, ACT/360; EUR version uses historical ECB FX.",
        "- Bond and commodity 2026-2030 expected-return paths that are not a direct published CMA are `scenario`.",
        "",
        "## Gaps 2016 to today",
        "",
        "- Global IG EUR-hedged and unhedged ETF proxies start at fund launch in November 2017. 2016-01-01 through the day before launch is empty on purpose.",
        "- €STR live series starts 2019-10-01; earlier euro cash uses EONIA.",
        "- SOFR and SOFR Index start 2018-04-02; earlier USD cash uses EFFR.",
        "- World Bank CMO Table 1 has no 2028-2030 columns.",
        "- FOMC SEP ends in 2028 plus a longer-run value; 2029-2030 cash and PCE years are scenarios.",
        "- ECB/Eurosystem HICP projections end in 2028; 2029-2030 are scenarios around 2%.",
        "- European Commission Germany HICP in this vintage ends in 2027.",
        "- IMF WEO database download was blocked (HTTP 403); IMF values were not invented.",
        "- Official Bloomberg / S&P commodity and aggregate index dumps: unavailable free.",
        "- Daily OAS history: not found free.",
        "- Livestock sub-index: World Bank has no separate livestock index; beverages/fertilizers stored instead.",
        "- Precious-metals Pink Sheet group includes gold; it is not gold-ex.",
        "- US CPI-U October 2025: unpublished (BLS lapse-in-appropriations footnote). 2026 months come from FRED CPIAUCNS as BLS distributor because the unregistered BLS API only returns 10 years.",
        "- NY Fed SOFR Index CSV on this endpoint starts 2020-03-02; FRED SOFRINDEX extends the official series back to 2018-04-02.",
        "",
        "## Automated update endpoints",
        "",
        "| Series | Endpoint | Interval | Revision lookback |",
        "| --- | --- | --- | --- |",
        "| €STR / compounded €STR / DFR / FX | `https://data-api.ecb.europa.eu/service/data/{flow}/{key}?format=csvdata` | business daily | 5-10 business days |",
        "| EONIA historical | ECB `EON/D.EONIA_TO.RATE` | static after 2021-12-31 | none |",
        "| SOFR / SOFR Index / EFFR | `https://markets.newyorkfed.org/read?productCode=50&eventCodes={520,525,500}&format=csv` | business daily | NY Fed can revise the prior session |",
        "| HICP DE/EA | Eurostat `prc_hicp_minr` `coicop18=TOTAL` `unit=I25` | monthly | 2-3 months |",
        "| US CPI-U | BLS Public API `CUUR0000SA0` | monthly | 2 months (seasonal-factor revisions do not apply to NSA, but late reports happen) |",
        "| ETF proxies | Yahoo chart `v8/finance/chart/{EUNA.DE,AGGG.L,AIGC.L}` | daily | prices can restamp; prefer issuer NAV if a free feed appears |",
        "| Pink Sheet | World Bank CMO monthly xlsx | monthly | current year revisions |",
        "",
        "## Licence / redistribution",
        "",
        "- Eurostat, ECB, BLS, World Bank, FOMC, NY Fed: official statistics / public research, attribution and their terms.",
        "- NY Fed SOFR/EFFR: additional terms of use; do not present as a NY Fed product.",
        "- Yahoo ETF/ETC adjusted closes: private research use. Not a substitute for iShares/WisdomTree NAV files or Bloomberg/S&P licences if you publish an index product.",
        "- Do not republish Bloomberg Global Aggregate or S&P GSCI level dumps from this package; they are not in it.",
        "",
        "## Checks",
        "",
    ]
    quality.extend(f"- {line}" for line in QUALITY_CHECKS)
    quality.append("")
    quality.append("## Findings")
    quality.append("")
    if QUALITY_NOTES:
        quality.extend(f"- {line}" for line in QUALITY_NOTES)
    else:
        quality.append("- No extra findings beyond the checks above.")
    quality += [
        "",
        "## Rules enforced",
        "",
        "- Historical import files contain no forecast rows.",
        "- Missing days were not linearly interpolated.",
        "- Yields were not treated as total return.",
        "- Pink Sheet spots were not treated as futures total return.",
        "- Accumulating / adjusted-close series were not topped up with extra coupons.",
        "- USD series were converted with same-day or last previous ECB USD/EUR, never today's spot.",
        "- Proxies and modeled series are `quality_status=flagged` and carry `data_kind` / `proxy_for`.",
        "",
        "## File inventory",
        "",
    ]
    quality.extend(f"- `{name}`" for name in files)
    quality.append("")
    write_text(PKG / "DATA_QUALITY_REPORT.md", "\n".join(quality))

    md_table = ["| Series | Source | Start | End | Freq | CCY | Kind | Rows |", "| --- | --- | --- | --- | --- | --- | --- | ---: |"]
    for rec in table:
        md_table.append("| " + " | ".join(rec) + " |")
    readme = f"""# README_DATA

Research date: {RESEARCH_ISO}

Package `{PKG_NAME}` is the data extract specified in
`data/RECHERCHE-PROMPT-GLOBAL-IG-ROHSTOFFE-GELDMARKT-INFLATION.md`.
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

{chr(10).join(md_table)}

## Recommended import set (6)

1. `prepared_import/global_ig_bonds_eur_hedged.csv` — default global IG for a EUR investor (EUR-hedged accumulating ETF proxy).
2. `prepared_import/broad_commodities_total_return_eur.csv` — unhedged EUR view of a BCOM-style TR ETC.
3. `prepared_import/euro_money_market.csv` — euro cash wealth index.
4. `prepared_import/us_money_market_eur.csv` — USD cash, unhedged in EUR.
5. `prepared_import/inflation_germany.csv` — default EUR purchasing-power deflator.
6. `prepared_import/global_ig_bonds_unhedged_eur.csv` — unhedged global IG, for FX-risk comparison.

Optional: `inflation_euro_area.csv`, `inflation_us.csv`.

Do not import Pink Sheet spots as the investable commodity asset.

## Method constraints that matter

1. A bond yield is not a total return. The IG main series is an accumulating or adjusted-close ETF proxy.
2. Commodity spots are not futures total return. Roll and collateral live in the ETC/TR proxy, not in the Pink Sheet.
3. Do not add coupons or dividends on top of adjusted close / accumulating NAV.
4. Euro cash compounds official overnight rates ACT/360. US cash does the same with EFFR then SOFR.
5. Inflation indices are deflators: `real_growth = nominal_growth / inflation_index_growth`.
6. No history is invented before a fund or index start. No current FX is used for past USD values.

## Licence

Private research. See `DATA_QUALITY_REPORT.md` for redistribution limits.
No API keys are stored in this package.
"""
    write_text(PKG / "README_DATA.md", readme)


def make_zip() -> Path:
    dest = ROOT / "data" / f"{PKG_NAME}.zip"
    if dest.exists():
        dest.unlink()
    with zipfile.ZipFile(dest, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for path in PKG.rglob("*"):
            if path.is_file():
                zf.write(path, arcname=f"{PKG_NAME}/{path.relative_to(PKG).as_posix()}")
    return dest


def main() -> int:
    global RETRIEVED, SKIP_DOWNLOAD
    SKIP_DOWNLOAD = "--skip-download" in sys.argv
    ensure_dirs()
    RETRIEVED = utc_now()
    print(f"Package {PKG}", flush=True)
    download_all()
    write_csv(PKG / "download_log.csv", DOWNLOAD_LOG, DOWNLOAD_FIELDS)

    fx = build_fx()
    bonds = process_bonds(fx)
    comm = process_commodities(fx)
    mm = process_money_markets(fx)
    inf = process_inflation()
    build_forecasts(bonds, mm, comm)

    # Cross-checks against known published points (tolerance for rounding / later revision).
    if not mm["estr"].empty:
        sample_check("€STR", mm["estr"], [("2019-10-01", -0.549, 0.05)])
    if not mm["sofr"].empty:
        sample_check("SOFR", mm["sofr"], [(SOFR_START.isoformat(), 1.80, 0.2)])
    fx_conversion_check(comm["converted"])
    unhedged = pd.read_csv(CLEAN / "global_ig_bonds_unhedged_eur.csv")
    if {"raw_usd_value", "eur_value", "usd_per_eur"} <= set(unhedged.columns):
        fx_conversion_check(unhedged.rename(columns=str))
    # No forecast dates in prepared history
    for name in [
        "global_ig_bonds_eur_hedged.csv",
        "global_ig_bonds_unhedged_eur.csv",
        "broad_commodities_total_return_eur.csv",
        "euro_money_market.csv",
        "us_money_market_eur.csv",
        "inflation_germany.csv",
        "inflation_euro_area.csv",
        "inflation_us.csv",
    ]:
        df = pd.read_csv(PREPARED / name)
        future = df[df["date"] > RESEARCH_ISO]
        if not future.empty:
            QUALITY_NOTES.append(f"{name} has {len(future)} dates after research date")
        if df["date"].duplicated().any() or df["value"].isna().any():
            QUALITY_NOTES.append(f"{name} has duplicate dates or empty values")
        bad_q = set(df["quality_status"]) - {"valid", "provisional", "revised", "flagged"}
        if bad_q:
            QUALITY_NOTES.append(f"{name} illegal quality_status {bad_q}")
        QUALITY_CHECKS.append(f"prepared {name}: n={len(df)} {df['date'].min()}..{df['date'].max()} ccy={df['currency'].unique().tolist()}")

    write_csv(PKG / "source_manifest.csv", MANIFEST_ROWS, MANIFEST_FIELDS)
    write_docs(bonds, comm, mm, inf)
    archive = make_zip()
    print(f"ZIP {archive} {archive.stat().st_size} bytes", flush=True)
    print("DONE", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
