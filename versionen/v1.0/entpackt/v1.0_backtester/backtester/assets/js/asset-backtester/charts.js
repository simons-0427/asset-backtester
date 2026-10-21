import { formatDate, formatEuro, formatPercent, formatSourceValue } from './formatters.js';

const STYLES = {
  sp500: { color: '#6ee7b7', pointStyle: 'circle' },
  bitcoin: { color: '#818cf8', pointStyle: 'rectRot' },
  gold: { color: '#f6c85f', pointStyle: 'rect' },
};

function positiveChartValue(value) {
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? number : null;
}

export function singleTooltipLines(series, point) {
  return [
    `Schluss-/Referenzwert: ${formatSourceValue(point.sourceValue, series.currency)}`,
    `Seit Intervallbeginn: ${formatPercent(point.returnPct)}`,
    `Aus 10.000 €: ${formatEuro(point.portfolioValue)}`,
  ];
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
    const dataset = {
      label: series.displayName,
      data: points.map((point) => positiveChartValue(point.sourceValue)),
      borderColor: style.color,
      borderDash: [],
      backgroundColor: `${style.color}18`,
      borderWidth: 2,
      pointRadius: 0,
      pointHoverRadius: 4,
      pointHoverBorderWidth: 2,
      pointHoverBackgroundColor: '#16161f',
      pointHoverBorderColor: style.color,
      fill: true,
      tension: 0,
      spanGaps: true,
      metaPoints: points,
    };

    return this.#replace(canvas, {
      type: 'line',
      data: { labels: points.map((point) => point.date), datasets: [dataset] },
      options: this.#options({
        currencyAxis: false,
        tooltip: {
          callbacks: {
            title: (items) => formatDate(points[items[0]?.dataIndex]?.date),
            label: (item) => {
              const point = points[item.dataIndex];
              return singleTooltipLines(series, point);
            },
            afterLabel: (item) => {
              const point = points[item.dataIndex];
              return point.isCarriedForward
                ? `Fortgeführt seit dem letzten echten Wert vom ${formatDate(point.lastRawObservationDate)}`
                : `Echte Beobachtung: ${formatDate(point.lastRawObservationDate)}`;
            },
          },
        },
      }),
    });
  }

  comparison(canvas, seriesList) {
    const labels = seriesList[0]?.points.map((point) => point.date) || [];
    const datasets = seriesList.map((series) => {
      const style = STYLES[series.slug] || STYLES.sp500;
      return {
        label: series.displayName,
        data: series.points.map((point) => positiveChartValue(point.portfolioValue)),
        borderColor: style.color,
        backgroundColor: 'transparent',
        borderWidth: 2.2,
        borderDash: [],
        pointStyle: style.pointStyle,
        pointRadius: 0,
        pointHoverRadius: 4,
        pointHoverBorderWidth: 2,
        pointHoverBackgroundColor: '#16161f',
        pointHoverBorderColor: style.color,
        tension: 0,
        spanGaps: true,
        metaSeries: series,
      };
    });

    return this.#replace(canvas, {
      type: 'line',
      data: { labels, datasets },
      options: this.#options({
        currencyAxis: true,
        showLegend: true,
        tooltip: {
          callbacks: {
            title: (items) => formatDate(labels[items[0]?.dataIndex]),
            label: (item) => {
              const series = item.dataset.metaSeries;
              const point = series.points[item.dataIndex];
              return `${series.displayName}: ${formatEuro(point.portfolioValue)}; ${formatPercent(point.returnPct)}; Kurs ${formatSourceValue(point.sourceValue, series.currency)}`;
            },
            afterLabel: (item) => {
              const point = item.dataset.metaSeries.points[item.dataIndex];
              return point.isCarriedForward ? `  fortgeführt seit ${formatDate(point.lastRawObservationDate)}` : '';
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
