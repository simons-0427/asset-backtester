import test from 'node:test';
import assert from 'node:assert/strict';
import {
  chooseLongestCommonPreset,
  classifyReturn,
  constrainDayRange,
  dayNumberToIso,
  isoToDayNumber,
  rangeForPreset,
  sortSummaryRows,
  subtractCalendar,
  todayIso,
} from '../../assets/js/asset-backtester/calculations.js';
import { ChartManager, singleTooltipLines } from '../../assets/js/asset-backtester/charts.js';
import {
  formatInteger,
  formatPlainPercent,
  formatRatio,
} from '../../assets/js/asset-backtester/formatters.js';

test('calendar presets handle month ends and leap years', () => {
  assert.equal(todayIso(new Date(2026, 7, 18, 13, 0, 0)), '2026-08-18');
  assert.equal(subtractCalendar('2024-02-29', '1y'), '2023-02-28');
  assert.equal(subtractCalendar('2024-03-31', '1m'), '2024-02-29');
  assert.deepEqual(rangeForPreset('1w', '2024-03-10'), { start: '2024-03-03', end: '2024-03-10' });
});

test('date range slider uses stable UTC day numbers across month and DST boundaries', () => {
  const day = isoToDayNumber('2024-03-31');
  assert.equal(dayNumberToIso(day), '2024-03-31');
  assert.equal(dayNumberToIso(day + 1), '2024-04-01');
  assert.throws(() => isoToDayNumber('2024-02-31'), /ISO-Datum/);
});

test('date range slider keeps both handles inside the bounds and prevents crossing', () => {
  assert.deepEqual(constrainDayRange(20, 10, 0, 30, 'start'), { start: 9, end: 10 });
  assert.deepEqual(constrainDayRange(20, 10, 0, 30, 'end'), { start: 20, end: 21 });
  assert.deepEqual(constrainDayRange(-5, 40, 0, 30), { start: 0, end: 30 });
});

test('longest common preset never hides a shorter asset history', () => {
  assert.equal(chooseLongestCommonPreset(['2018-01-01', '2022-01-01'], '2026-08-02'), '3y');
  assert.equal(rangeForPreset('max', '2026-08-02', ['2010-01-01', '2016-01-01']).start, '2016-01-01');
});

test('return classes are semantic in addition to their sign text', () => {
  assert.equal(classifyReturn(3), 'is-positive');
  assert.equal(classifyReturn(-3), 'is-negative');
  assert.equal(classifyReturn(0), 'is-neutral');
});

test('market summary sorts by return and keeps unavailable rows last', () => {
  const rows = [
    { slug: 'a', periods: { '1Y': { returnPct: 5 } } },
    { slug: 'b', periods: { '1Y': { returnPct: 12 } } },
    { slug: 'c', periods: { '1Y': null } },
  ];
  assert.deepEqual(sortSummaryRows(rows, '1Y', 'desc').map(({ slug }) => slug), ['b', 'a', 'c']);
  assert.deepEqual(sortSummaryRows(rows, '1Y', 'asc').map(({ slug }) => slug), ['a', 'b', 'c']);
});

test('risk metrics use German formatting and keep missing values empty', () => {
  assert.equal(formatPlainPercent(12.345), '12,35 %');
  assert.equal(formatRatio(1.2345), '1,23');
  assert.equal(formatInteger(2513), '2.513');
  assert.equal(formatPlainPercent(null), '–');
  assert.equal(formatRatio(undefined), '–');
});

test('single-chart tooltip uses the precomputed interval return', () => {
  const lines = singleTooltipLines(
    { currency: 'EUR' },
    { sourceValue: 121, returnPct: 21, portfolioValue: 12100 },
  );
  assert.match(lines[0], /21,00\s%/);
  assert.match(lines[1], /12\.100,00\s€/);
});

test('charts use a positive logarithmic value axis', () => {
  class FakeChart {
    static defaults = { font: {}, color: '' };

    constructor(_context, config) {
      this.config = config;
    }

    destroy() {}
  }

  const manager = new ChartManager(FakeChart);
  const canvas = { id: 'chart-test', getContext: () => ({}) };
  const series = {
    slug: 'gold',
    displayName: 'Gold',
    currency: 'EUR',
    points: [
      { date: '2026-08-01', sourceValue: 10, portfolioValue: 10000 },
      { date: '2026-08-02', sourceValue: -1, portfolioValue: 0 },
    ],
  };

  const single = manager.single(canvas, series);
  assert.equal(single.config.options.scales.y.type, 'logarithmic');
  assert.deepEqual(single.config.data.datasets[0].data, [10, null]);
  assert.deepEqual(single.config.data.datasets[0].borderDash, []);

  const comparison = manager.comparison(canvas, [series]);
  assert.equal(comparison.config.options.scales.y.type, 'logarithmic');
  assert.deepEqual(comparison.config.data.datasets[0].data, [10000, null]);
  assert.deepEqual(comparison.config.data.datasets[0].borderDash, []);
});

test('S&P single chart keeps the price and adds a separate dividend line', () => {
  class FakeChart {
    static defaults = { font: {}, color: '' };

    constructor(_context, config) {
      this.config = config;
    }

    destroy() {}
  }

  const manager = new ChartManager(FakeChart);
  const canvas = { id: 'chart-sp500-test', getContext: () => ({}) };
  const series = {
    slug: 'sp500',
    displayName: 'S&P 500',
    currency: 'EUR',
    dividendAdjustment: { applied: true },
    points: [
      { date: '2026-08-01', sourceValue: 100, dividendAdjustedSourceValue: 100 },
      { date: '2026-08-02', sourceValue: 110, dividendAdjustedSourceValue: 115 },
    ],
  };

  const chart = manager.single(canvas, series);
  assert.equal(chart.config.data.datasets.length, 2);
assert.equal(chart.config.data.datasets[0].label, 'Preisindex');
  assert.deepEqual(chart.config.data.datasets[0].data, [100, 110]);
  assert.equal(chart.config.data.datasets[0].borderColor, '#38bdf8');
  assert.equal(chart.config.data.datasets[1].label, 'Preis + Dividenden');
  assert.deepEqual(chart.config.data.datasets[1].data, [100, 115]);
  assert.equal(chart.config.data.datasets[1].borderColor, '#6ee7b7');
  assert.deepEqual(chart.config.data.datasets[0].borderDash, []);
  assert.deepEqual(chart.config.data.datasets[1].borderDash, []);
  assert.equal(chart.config.options.plugins.legend.display, true);
});

test('comparison selects S&P with dividends by default and price only on request', () => {
  class FakeChart {
    static defaults = { font: {}, color: '' };

    constructor(_context, config) {
      this.config = config;
    }

    destroy() {}
  }

  const manager = new ChartManager(FakeChart);
  const canvas = { id: 'comparison-sp500-test', getContext: () => ({}) };
  const series = {
    slug: 'sp500',
    displayName: 'S&P 500',
    currency: 'EUR',
    dividendAdjustment: { applied: true },
    points: [
      { date: '2026-08-01', sourceValue: 100, portfolioValue: 10000, returnPct: 0 },
      { date: '2026-08-02', sourceValue: 110, portfolioValue: 11500, returnPct: 15 },
    ],
  };

  const defaultChart = manager.comparison(canvas, [series]);
  assert.equal(defaultChart.config.data.datasets.length, 1);
  assert.equal(defaultChart.config.data.datasets[0].label, 'S&P 500');
  assert.deepEqual(defaultChart.config.data.datasets[0].data, [10000, 11500]);
  assert.equal(defaultChart.config.data.datasets[0].borderColor, '#6ee7b7');

  const chartWithPrice = manager.comparison(canvas, [series], {
    showSp500Dividend: true,
    showSp500Price: true,
  });
  assert.equal(chartWithPrice.config.data.datasets.length, 2);
  assert.equal(chartWithPrice.config.data.datasets[0].label, 'S&P 500');
  assert.deepEqual(chartWithPrice.config.data.datasets[0].data, [10000, 11500]);
  assert.equal(chartWithPrice.config.data.datasets[0].borderColor, '#6ee7b7');
  assert.equal(chartWithPrice.config.data.datasets[1].label, 'S&P500 ohne Div.');
  assert.deepEqual(chartWithPrice.config.data.datasets[1].data, [10000, 11000]);
  assert.equal(chartWithPrice.config.data.datasets[1].borderColor, '#38bdf8');
  assert.deepEqual(chartWithPrice.config.data.datasets[0].borderDash, []);
  assert.deepEqual(chartWithPrice.config.data.datasets[1].borderDash, []);
});
