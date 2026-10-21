import { formatDate, formatEuro, formatPercent, formatPlainPercent, formatSourceValue } from './formatters.js';

const STYLES = {
  sp500: { color: '#6ee7b7', dividendColor: '#38bdf8', pointStyle: 'circle' },
  bitcoin: { color: '#818cf8', pointStyle: 'rectRot' },
  gold: { color: '#f6c85f', pointStyle: 'rect' },
  msci_world: { color: '#f472b6', dividendColor: '#22d3ee', pointStyle: 'triangle' },
  de_real_estate: { color: '#a3e635', dividendColor: '#d9f99d', pointStyle: 'crossRot' },
  us_real_estate: { color: '#fb923c', dividendColor: '#fdba74', pointStyle: 'star' },
  dax: { color: '#ef4444', dividendColor: '#fca5a5', pointStyle: 'cross' },
  eurostoxx50: { color: '#3b82f6', dividendColor: '#93c5fd', pointStyle: 'triangle' },
  global_ig_bonds: { color: '#c4b5fd', pointStyle: 'rectRounded' },
  global_ig_bonds_unhedged: { color: '#a78bfa', pointStyle: 'rectRot' },
  commodities: { color: '#2dd4bf', pointStyle: 'crossRot' },
  euro_money_market: { color: '#e2e8f0', pointStyle: 'circle' },
  us_money_market: { color: '#60a5fa', pointStyle: 'star' },
};

function positiveChartValue(value) {
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? number : null;
}

export function singleTooltipLines(series, point) {
  const hasRentalCashflow = Boolean(series.rentalCashflowAdjustment?.applied);
  const lines = [
    `${series.dividendAdjustment?.applied
      ? 'Gesamtrendite inkl. Dividenden'
      : (hasRentalCashflow ? 'Gesamtrendite inkl. Netto-Mietcashflow' : 'Seit Intervallbeginn')}: ${formatPercent(point.returnPct)}`,
  ];
  lines.push(`Aus 10.000 €: ${formatEuro(point.portfolioValue)}`);
  if (series.dividendAdjustment?.applied) {
    lines.push(series.dividendAdjustment.method === 'annual_yield_december_31'
      ? 'Methode: Jahresdividende am 31.12.'
      : 'Methode: monatliche Multpl-Dividendenrechnung');
  }
  if (hasRentalCashflow) {
    lines.push(`Netto-Mietcashflow: ${formatPlainPercent(series.rentalCashflowAdjustment.annualNetCashflowPct)} p.a.; nicht reinvestiert`);
  }
  if (series.terAdjustment?.applied) {
    lines.push(`TER: ${formatPlainPercent(series.terAdjustment.annualTerPct)} p.a.`);
    lines.push(`Geschätzter TER-Effekt bis hier: −${formatEuro(point.terCostAmount || 0)}`);
  }
  return lines;
}

export function comparisonTooltipReturn(item) {
  const point = item?.dataset?.metaSeries?.points?.[item.dataIndex];
  if (item?.dataset?.comparisonVariant === 'price') {
    const provided = Number(point?.priceOnlyReturnPct);
    if (Number.isFinite(provided)) return provided;
    const startValue = Number(item?.dataset?.data?.[0]);
    const currentValue = Number(item?.raw);
    return startValue > 0 && Number.isFinite(currentValue)
      ? (currentValue / startValue - 1) * 100
      : Number.NEGATIVE_INFINITY;
  }
  const value = Number(point?.returnPct);
  return Number.isFinite(value) ? value : Number.NEGATIVE_INFINITY;
}

export class ChartManager {
  constructor(ChartClass = window.Chart) {
    if (!ChartClass) throw new Error('Chart.js wurde nicht geladen.');
    this.Chart = ChartClass;
    this.instances = new Map();
    this.Chart.defaults.font.family = "'DM Sans', sans-serif";
    this.Chart.defaults.color = '#64748b';
  }

  single(canvas, series) {
    const style = STYLES[series.slug] || STYLES.sp500;
    const points = series.points;
    const hasDividendLine = Boolean(series.dividendAdjustment?.applied);
    const hasRentalCashflowLine = Boolean(series.rentalCashflowAdjustment?.applied);
    const hasTerLine = Boolean(series.terAdjustment?.applied);
    const hasAdjustedLine = hasDividendLine || hasRentalCashflowLine || hasTerLine;
    const priceColor = hasAdjustedLine ? (style.dividendColor || '#94a3b8') : style.color;
    const datasets = [{
      label: hasAdjustedLine ? 'Preisindex' : series.displayName,
      data: points.map((point) => positiveChartValue(point.sourceValue)),
      borderColor: priceColor,
      borderDash: [],
      backgroundColor: hasAdjustedLine ? 'transparent' : `${priceColor}18`,
      borderWidth: 2.2,
      pointRadius: 0,
      pointHoverRadius: 4,
      pointHoverBorderWidth: 2,
      pointHoverBackgroundColor: '#16161f',
      pointHoverBorderColor: priceColor,
      fill: !hasAdjustedLine,
      tension: 0,
      spanGaps: true,
      metaPoints: points,
    }];
    if (hasAdjustedLine) {
      const adjustedLabel = hasRentalCashflowLine
        ? 'Preis + Netto-Mietcashflow'
        : (hasDividendLine ? 'Preis + Dividenden' : series.displayName);
      datasets.push({
        label: hasTerLine ? `${adjustedLabel} nach TER` : adjustedLabel,
        data: points.map((point) => positiveChartValue(
          hasTerLine
            ? point.terAdjustedSourceValue
            : (hasRentalCashflowLine
            ? point.rentalCashflowAdjustedSourceValue
            : point.dividendAdjustedSourceValue),
        )),
        borderColor: style.color,
        borderDash: [],
        backgroundColor: 'transparent',
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
        pointHoverBorderWidth: 2,
        pointHoverBackgroundColor: '#16161f',
        pointHoverBorderColor: style.color,
        fill: false,
        tension: 0,
        spanGaps: true,
        metaPoints: points,
      });
    }

    return this.#replace(canvas, {
      type: 'line',
      data: { labels: points.map((point) => point.date), datasets },
      options: this.#options({
        currencyAxis: false,
        showLegend: hasAdjustedLine,
        tooltip: {
          callbacks: {
            title: (items) => formatDate(points[items[0]?.dataIndex]?.date),
            label: (item) => `${item.dataset.label}: ${formatSourceValue(item.raw, series.currency)}`,
            afterBody: (items) => singleTooltipLines(
              series,
              points[items[0]?.dataIndex],
            ),
            footer: (items) => {
              const point = points[items[0]?.dataIndex];
              return point.isCarriedForward
                ? `Fortgeführt seit dem letzten echten Wert vom ${formatDate(point.lastRawObservationDate)}`
                : `Echte Beobachtung: ${formatDate(point.lastRawObservationDate)}`;
            },
          },
        },
      }),
    });
  }

  comparison(canvas, seriesList, {
    showSp500Dividend = true,
    showSp500Price = false,
    showMsciWorldDividend = false,
    showMsciWorldPrice = false,
    showDaxDividend = false,
    showDaxPrice = false,
    showEuroStoxx50Dividend = false,
    showEuroStoxx50Price = false,
  } = {}) {
    const labels = seriesList[0]?.points.map((point) => point.date) || [];
    const datasets = seriesList.flatMap((series) => {
      const style = STYLES[series.slug] || STYLES.sp500;
      const qualifiers = [];
      if (series.inflationAdjustment?.applied) qualifiers.push('real');
      if (series.taxAdjustment?.applied) qualifiers.push('nach Steuern');
      const createDataset = (label, data, color, variant = 'total') => ({
        label: qualifiers.length ? `${label} (${qualifiers.join(', ')})` : label,
        data,
        borderColor: color,
        backgroundColor: 'transparent',
        borderWidth: variant === 'dividend' ? 2.6 : 2.2,
        borderDash: [],
        pointStyle: style.pointStyle,
        pointRadius: 0,
        pointHoverRadius: 4,
        pointHoverBorderWidth: 2,
        pointHoverBackgroundColor: '#16161f',
        pointHoverBorderColor: color,
        tension: 0,
        spanGaps: true,
        metaSeries: series,
        comparisonVariant: variant,
      });

      const firstPoint = series.points[0];
      const firstSourceValue = Number(firstPoint?.sourceValue);
      const firstPortfolioValue = Number(firstPoint?.portfolioValue);
      const priceOnlyData = series.points.map((point) => {
        const provided = positiveChartValue(point.priceOnlyPortfolioValue);
        if (provided !== null) return provided;
        const sourceValue = Number(point.sourceValue);
        return firstSourceValue > 0 && firstPortfolioValue > 0 && sourceValue > 0
          ? positiveChartValue(firstPortfolioValue * sourceValue / firstSourceValue)
          : null;
      });
      const totalData = ['de_real_estate', 'us_real_estate'].includes(series.slug)
        ? priceOnlyData
        : series.points.map((point) => positiveChartValue(point.portfolioValue));
      const dividendView = ({
        sp500: {
          showTotal: showSp500Dividend,
          showPrice: showSp500Price,
          totalLabel: 'S&P 500',
          priceLabel: 'S&P500 ohne Div.',
        },
        msci_world: {
          showTotal: showMsciWorldDividend,
          showPrice: showMsciWorldPrice,
          totalLabel: 'MSCI World',
          priceLabel: 'MSCI World ohne Div.',
        },
        dax: {
          showTotal: showDaxDividend,
          showPrice: showDaxPrice,
          totalLabel: 'DAX',
          priceLabel: 'DAX ohne Div.',
        },
        eurostoxx50: {
          showTotal: showEuroStoxx50Dividend,
          showPrice: showEuroStoxx50Price,
          totalLabel: 'EURO STOXX 50',
          priceLabel: 'EURO STOXX 50 ohne Div.',
        },
      })[series.slug] || null;
      const dividendApplied = series.dividendAdjustment?.applied;
      if (!dividendView || !dividendApplied) {
        return [createDataset(series.displayName, totalData, style.color)];
      }

      const dividendDatasets = [];
      if (dividendView.showTotal) {
        dividendDatasets.push(createDataset(dividendView.totalLabel, totalData, style.color, 'dividend'));
      }
      if (dividendView.showPrice) {
        dividendDatasets.push(createDataset(dividendView.priceLabel, priceOnlyData, style.dividendColor, 'price'));
      }
      return dividendDatasets;
    });

    return this.#replace(canvas, {
      type: 'line',
      data: { labels, datasets },
      options: this.#options({
        currencyAxis: true,
        showLegend: true,
        tooltip: {
          itemSort: (a, b) => comparisonTooltipReturn(b) - comparisonTooltipReturn(a),
          callbacks: {
            title: (items) => formatDate(labels[items[0]?.dataIndex]),
            label: (item) => {
              const series = item.dataset.metaSeries;
              const point = series.points[item.dataIndex];
              const returnKind = series.inflationAdjustment?.applied ? 'reale Rendite' : 'Rendite';
              if (item.dataset.comparisonVariant === 'price') {
                const lines = [`${item.dataset.label}: ${formatEuro(item.raw)}; ${returnKind} ${formatPercent(comparisonTooltipReturn(item))}; Index ${formatSourceValue(point.sourceValue, series.currency)}`];
                if (series.taxAdjustment?.applied) {
                  lines.push(`Steuermodell: ${point.priceOnlyTaxLabel}; ${formatEuro(point.priceOnlyTaxAmount || 0)}`);
                }
                if (series.terAdjustment?.applied) {
                  lines.push(`TER: ${formatPlainPercent(series.terAdjustment.annualTerPct)} p.a.`);
                }
                return lines;
              }
              const lines = [item.dataset.comparisonVariant === 'dividend'
                ? `${item.dataset.label}: ${formatEuro(point.portfolioValue)}; ${returnKind} ${formatPercent(point.returnPct)}`
                : `${item.dataset.label}: ${formatEuro(point.portfolioValue)}; ${returnKind} ${formatPercent(point.returnPct)}; Kurs ${formatSourceValue(point.sourceValue, series.currency)}`];
              if (series.taxAdjustment?.applied) {
                lines.push(`Steuermodell: ${point.taxLabel}; ${formatEuro(point.taxAmount || 0)}`);
              }
              if (series.terAdjustment?.applied) {
                lines.push(`TER: ${formatPlainPercent(series.terAdjustment.annualTerPct)} p.a.; Effekt −${formatEuro(point.terCostAmount || 0)}`);
              }
              return lines;
            },
          },
        },
      }),
    });
  }

  destroyAll() {
    this.instances.forEach((chart) => chart.destroy());
    this.instances.clear();
  }

  #replace(canvas, config) {
    const key = canvas.id;
    this.instances.get(key)?.destroy();
    const instance = new this.Chart(canvas.getContext('2d'), config);
    this.instances.set(key, instance);
    return instance;
  }

  #options({ currencyAxis, showLegend = false, tooltip }) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 180 },
      normalized: true,
      interaction: { mode: 'index', intersect: false, axis: 'x' },
      plugins: {
        legend: {
          display: showLegend,
          position: 'bottom',
          labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8, padding: 18, color: '#94a3b8' },
          onClick: () => {},
        },
        tooltip: {
          enabled: true,
          mode: 'index',
          intersect: false,
          backgroundColor: 'rgba(12,12,15,.96)',
          titleColor: '#e2e8f0',
          bodyColor: '#cbd5e1',
          borderColor: '#2b2b40',
          borderWidth: 1,
          padding: 11,
          displayColors: showLegend,
          usePointStyle: true,
          ...tooltip,
        },
        decimation: { enabled: true, algorithm: 'min-max' },
      },
      scales: {
        x: {
          grid: { display: false },
          border: { color: '#1e1e2e' },
          ticks: {
            color: '#64748b',
            maxTicksLimit: 7,
            maxRotation: 0,
            callback(_value, index) { return formatDate(this.getLabelForValue(index)).slice(3); },
          },
        },
        y: {
          type: 'logarithmic',
          grid: { color: 'rgba(100,116,139,.12)' },
          border: { display: false },
          ticks: {
            color: '#64748b',
            maxTicksLimit: 6,
            callback: (value) => currencyAxis
              ? Number(value).toLocaleString('de-DE', { notation: 'compact', maximumFractionDigits: 1 }) + ' €'
              : Number(value).toLocaleString('de-DE', { notation: 'compact', maximumFractionDigits: 2 }),
          },
        },
      },
    };
  }
}
