export const DISPLAY_CURRENCIES = Object.freeze(['EUR', 'GBP', 'USD', 'JPY', 'CNY']);

const FX_METRICS = Object.freeze({
  eurusd: 'usdPerEur',
  gbpusd: 'usdPerGbp',
  usdjpy: 'jpyPerUsd',
  usdcny: 'cnyPerUsd',
});

const FX_HISTORY_METRICS = Object.freeze({
  EUR: [],
  USD: ['eurusd'],
  GBP: ['eurusd', 'gbpusd'],
  JPY: ['eurusd', 'usdjpy'],
  CNY: ['eurusd', 'usdcny'],
});

const POINT_MONEY_FIELDS = Object.freeze([
  'portfolioValue',
  'nominalPortfolioValue',
  'beforeTaxPortfolioValue',
  'nominalBeforeTaxPortfolioValue',
  'priceOnlyPortfolioValue',
  'taxAmount',
  'taxableGain',
  'priceOnlyTaxAmount',
  'terCostAmount',
]);

const POINT_SOURCE_VALUE_FIELDS = Object.freeze([
  'sourceValue',
  'dividendAdjustedSourceValue',
  'rentalCashflowAdjustedSourceValue',
  'totalAdjustedSourceValue',
  'terAdjustedSourceValue',
  'inflationAdjustedSourceValue',
]);

function positiveNumber(value) {
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? number : null;
}

export function currencyRatesFromMetrics(metrics = []) {
  const quotes = {};
  metrics.forEach((metric) => {
    const key = FX_METRICS[String(metric?.id || '').toLowerCase()];
    const value = positiveNumber(metric?.latest?.value);
    if (key && value !== null) quotes[key] = value;
  });

  const rates = { EUR: 1 };
  if (quotes.usdPerEur) {
    rates.USD = quotes.usdPerEur;
    if (quotes.usdPerGbp) rates.GBP = quotes.usdPerEur / quotes.usdPerGbp;
    if (quotes.jpyPerUsd) rates.JPY = quotes.usdPerEur * quotes.jpyPerUsd;
    if (quotes.cnyPerUsd) rates.CNY = quotes.usdPerEur * quotes.cnyPerUsd;
  }
  return rates;
}

export async function loadStoredCurrencyRates(
  fetchImpl = globalThis.fetch,
  endpoint = '/dashboards/weltmarkt/api/weltmarkt/metrics.php',
) {
  if (typeof fetchImpl !== 'function') throw new Error('Exchange rates are unavailable.');

  let response;
  try {
    response = await fetchImpl(endpoint, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    });
  } catch (error) {
    if (error?.name === 'AbortError') throw error;
    throw new Error('Exchange rates are unavailable.');
  }

  let payload;
  try {
    payload = await response.json();
  } catch {
    throw new Error('Exchange rates are unavailable.');
  }

  if (!response.ok || payload?.success !== true || !Array.isArray(payload.metrics)) {
    throw new Error('Exchange rates are unavailable.');
  }

  return currencyRatesFromMetrics(payload.metrics);
}

function metricPoints(seriesByMetric, metric) {
  const value = seriesByMetric?.[metric];
  const points = Array.isArray(value) ? value : value?.points;
  return Array.isArray(points)
    ? points
      .map((point) => ({ date: String(point?.date || ''), value: positiveNumber(point?.value) }))
      .filter((point) => /^\d{4}-\d{2}-\d{2}$/.test(point.date) && point.value !== null)
      .sort((left, right) => left.date.localeCompare(right.date))
    : [];
}

export function buildEurCurrencyHistory(currency, seriesByMetric = {}) {
  const normalized = String(currency || '').toUpperCase();
  if (normalized === 'EUR') return [];
  const required = FX_HISTORY_METRICS[normalized];
  if (!required) return [];

  const histories = Object.fromEntries(required.map((metric) => [metric, metricPoints(seriesByMetric, metric)]));
  if (required.some((metric) => histories[metric].length === 0)) return [];

  const dates = [...new Set(required.flatMap((metric) => histories[metric].map((point) => point.date)))].sort();
  const indexes = Object.fromEntries(required.map((metric) => [metric, 0]));
  const latest = {};
  const result = [];

  dates.forEach((date) => {
    required.forEach((metric) => {
      const points = histories[metric];
      while (indexes[metric] < points.length && points[indexes[metric]].date <= date) {
        latest[metric] = points[indexes[metric]].value;
        indexes[metric] += 1;
      }
    });
    if (required.some((metric) => !positiveNumber(latest[metric]))) return;

    const eurUsd = latest.eurusd;
    const value = ({
      USD: () => eurUsd,
      GBP: () => eurUsd / latest.gbpusd,
      JPY: () => eurUsd * latest.usdjpy,
      CNY: () => eurUsd * latest.usdcny,
    })[normalized]?.();
    if (positiveNumber(value)) result.push({ date, value });
  });
  return result;
}

export function currencyRateAtDate(history, date) {
  if (!Array.isArray(history) || history.length === 0 || !date) return null;
  let low = 0;
  let high = history.length - 1;
  let match = -1;
  while (low <= high) {
    const middle = Math.floor((low + high) / 2);
    if (history[middle].date <= date) {
      match = middle;
      low = middle + 1;
    } else {
      high = middle - 1;
    }
  }
  return match >= 0 ? positiveNumber(history[match].value) : null;
}

export function currencyGrowthFactor(history, startDate, endDate) {
  const startRate = currencyRateAtDate(history, startDate);
  const endRate = currencyRateAtDate(history, endDate);
  return startRate && endRate ? endRate / startRate : null;
}

async function loadStoredMetricHistory(metric, fetchImpl, endpoint) {
  let response;
  try {
    const query = new URLSearchParams({ metric });
    response = await fetchImpl(`${endpoint}?${query.toString()}`, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    });
  } catch (error) {
    if (error?.name === 'AbortError') throw error;
    throw new Error('Currency history is unavailable.');
  }

  let payload;
  try {
    payload = await response.json();
  } catch {
    throw new Error('Currency history is unavailable.');
  }
  if (!response.ok || payload?.success !== true || !Array.isArray(payload.points)) {
    throw new Error('Currency history is unavailable.');
  }
  return payload.points;
}

export async function loadStoredCurrencyHistory(
  currency,
  fetchImpl = globalThis.fetch,
  endpoint = '/dashboards/weltmarkt/api/weltmarkt/series.php',
) {
  const normalized = String(currency || '').toUpperCase();
  const required = FX_HISTORY_METRICS[normalized];
  if (!required) throw new Error('Currency history is unavailable.');
  if (normalized === 'EUR') return [];
  if (typeof fetchImpl !== 'function') throw new Error('Currency history is unavailable.');

  const entries = await Promise.all(required.map(async (metric) => [
    metric,
    await loadStoredMetricHistory(metric, fetchImpl, endpoint),
  ]));
  const history = buildEurCurrencyHistory(normalized, Object.fromEntries(entries));
  if (history.length === 0) throw new Error('Currency history is unavailable.');
  return history;
}

function finiteNumber(value) {
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function round(value, digits = 6) {
  return Number(Number(value).toFixed(digits));
}

function yearsBetween(startDate, endDate) {
  const start = Date.parse(`${startDate}T00:00:00Z`);
  const end = Date.parse(`${endDate}T00:00:00Z`);
  return Number.isFinite(start) && Number.isFinite(end) && end > start
    ? (end - start) / (365.2425 * 24 * 60 * 60 * 1000)
    : null;
}

function pathMetrics(points, series, metrics) {
  const firstValue = finiteNumber(points[0]?.portfolioValue);
  const lastValue = finiteNumber(points.at(-1)?.portfolioValue);
  if (!(firstValue > 0) || !(lastValue > 0)) return metrics;

  let peak = firstValue;
  let maxDrawdownPct = 0;
  points.forEach((point) => {
    const value = finiteNumber(point.portfolioValue);
    if (!(value > 0)) return;
    peak = Math.max(peak, value);
    maxDrawdownPct = Math.min(maxDrawdownPct, (value / peak - 1) * 100);
  });

  const observed = points.filter((point, index) => index === 0
    || !point.isCarriedForward
    || point.currencyRate !== points[index - 1].currencyRate);
  const returns = [];
  for (let index = 1; index < observed.length; index += 1) {
    const previous = finiteNumber(observed[index - 1].portfolioValue);
    const current = finiteNumber(observed[index].portfolioValue);
    if (previous > 0 && current > 0) returns.push({
      date: observed[index].date,
      value: current / previous - 1,
    });
  }

  const periods = series.slug === 'bitcoin' ? 365.2425 : 252;
  const mean = returns.length ? returns.reduce((sum, item) => sum + item.value, 0) / returns.length : null;
  const variance = returns.length > 1
    ? returns.reduce((sum, item) => sum + ((item.value - mean) ** 2), 0) / (returns.length - 1)
    : null;
  const deviation = variance !== null ? Math.sqrt(variance) : null;
  const cagrYears = yearsBetween(series.effectiveStartDate, series.effectiveEndDate);
  const cagrPct = cagrYears ? ((lastValue / firstValue) ** (1 / cagrYears) - 1) * 100 : null;
  const best = returns.length ? returns.reduce((current, item) => item.value > current.value ? item : current) : null;
  const worst = returns.length ? returns.reduce((current, item) => item.value < current.value ? item : current) : null;
  const annualizedVolatilityPct = deviation !== null ? deviation * Math.sqrt(periods) * 100 : null;

  return {
    ...metrics,
    finalValue: round(lastValue, 2),
    absoluteReturn: round(lastValue - firstValue, 2),
    totalReturnPct: round((lastValue / firstValue - 1) * 100, 4),
    cagrPct: cagrPct === null ? null : round(cagrPct, 4),
    maxDrawdownPct: round(maxDrawdownPct, 4),
    beforeTaxFinalValue: finiteNumber(points.at(-1)?.beforeTaxPortfolioValue),
    taxAmount: finiteNumber(points.at(-1)?.taxAmount) || 0,
    estimatedTerCost: finiteNumber(points.at(-1)?.terCostAmount) || 0,
    annualizedVolatilityPct: annualizedVolatilityPct === null ? null : round(annualizedVolatilityPct, 4),
    annualizationPeriods: periods,
    sharpeRatio: deviation > 0 ? round((mean / deviation) * Math.sqrt(periods), 4) : null,
    calmarRatio: cagrPct !== null && maxDrawdownPct < 0 ? round(cagrPct / Math.abs(maxDrawdownPct), 4) : null,
    bestDayPct: best ? round(best.value * 100, 4) : null,
    bestDayDate: best?.date || null,
    worstDayPct: worst ? round(worst.value * 100, 4) : null,
    worstDayDate: worst?.date || null,
    positiveDaysPct: returns.length ? round((returns.filter((item) => item.value > 0).length / returns.length) * 100, 4) : null,
    observedReturnCount: returns.length,
  };
}

export function applyHistoricalCurrencyToSeries(series, currency, history) {
  const normalized = String(currency || '').toUpperCase();
  if (normalized === 'EUR') return series;
  const points = Array.isArray(series?.points) ? series.points : [];
  const sourceCurrency = String(series?.currency || '').toUpperCase();
  const isPointSeries = sourceCurrency === 'PTS';
  const startDate = points[0]?.date || series?.effectiveStartDate;
  const startRate = currencyRateAtDate(history, startDate);
  if (!startRate) return series;

  const adjustedPoints = points.map((point) => {
    const rate = currencyRateAtDate(history, point.date);
    const factor = rate ? rate / startRate : 1;
    const adjusted = { ...point, currencyRate: rate || startRate };
    POINT_MONEY_FIELDS.forEach((field) => {
      const value = finiteNumber(point[field]);
      if (value !== null) adjusted[field] = round(value * factor);
    });
    if (!isPointSeries) {
      const sourceRate = rate || startRate;
      POINT_SOURCE_VALUE_FIELDS.forEach((field) => {
        const value = finiteNumber(point[field]);
        if (value !== null) adjusted[field] = round(value * sourceRate);
      });
    }
    return adjusted;
  });

  const firstPortfolio = finiteNumber(adjustedPoints[0]?.portfolioValue);
  const firstPriceOnly = finiteNumber(adjustedPoints[0]?.priceOnlyPortfolioValue);
  adjustedPoints.forEach((point) => {
    if (firstPortfolio > 0 && finiteNumber(point.portfolioValue) !== null) {
      point.returnPct = round((point.portfolioValue / firstPortfolio - 1) * 100);
    }
    if (firstPriceOnly > 0 && finiteNumber(point.priceOnlyPortfolioValue) !== null) {
      point.priceOnlyReturnPct = round((point.priceOnlyPortfolioValue / firstPriceOnly - 1) * 100);
    }
  });

  const adjusted = {
    ...series,
    currency: isPointSeries ? series.currency : normalized,
    points: adjustedPoints,
    currencyAdjustment: {
      applied: true,
      currency: normalized,
      startRate,
      endRate: currencyRateAtDate(history, adjustedPoints.at(-1)?.date),
      sourceValuesConverted: !isPointSeries,
    },
  };
  adjusted.metrics = pathMetrics(adjustedPoints, adjusted, series.metrics || {});
  return adjusted;
}

export function applyHistoricalCurrencyToSummary(summary, currency, history, initialAmount = 10000) {
  const normalized = String(currency || '').toUpperCase();
  if (normalized === 'EUR') return summary;
  return {
    ...summary,
    rows: (summary?.rows || []).map((row) => ({
      ...row,
      currency: String(row.currency || '').toUpperCase() === 'PTS' ? row.currency : normalized,
      periods: Object.fromEntries(Object.entries(row.periods || {}).map(([label, period]) => {
        const isPointSeries = String(row.currency || '').toUpperCase() === 'PTS';
        const startRate = currencyRateAtDate(history, period.effectiveStartDate);
        const endRate = currencyRateAtDate(history, period.effectiveEndDate);
        const factor = currencyGrowthFactor(history, period.effectiveStartDate, period.effectiveEndDate);
        const converted = { ...period };
        if (!isPointSeries) {
          const startValue = finiteNumber(period.startValue);
          const endValue = finiteNumber(period.endValue);
          if (startValue !== null && startRate) converted.startValue = round(startValue * startRate);
          if (endValue !== null && endRate) converted.endValue = round(endValue * endRate);
        }
        if (!factor) return [label, converted];
        const originalValue = finiteNumber(period.hypotheticalValue);
        if (originalValue === null) return [label, converted];
        const assetFactor = originalValue / initialAmount;
        const hypotheticalValue = initialAmount * assetFactor * factor;
        return [label, {
          ...converted,
          hypotheticalValue: round(hypotheticalValue, 2),
          returnPct: round((hypotheticalValue / initialAmount - 1) * 100, 4),
        }];
      })),
    })),
  };
}
