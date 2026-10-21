import test from 'node:test';
import assert from 'node:assert/strict';
import {
  chooseLongestCommonPreset,
  classifyReturn,
  keepAtLeastOne,
  rangeForPreset,
  subtractCalendar,
} from '../../assets/js/asset-backtester/calculations.js';
import { ChartManager, singleTooltipLines } from '../../assets/js/asset-backtester/charts.js';
import {
  formatInteger,
  formatPlainPercent,
  formatRatio,
} from '../../assets/js/asset-backtester/formatters.js';

test('calendar presets handle month ends and leap years', () => {
  assert.equal(subtractCalendar('2024-02-29', '1y'), '2023-02-28');
  assert.equal(subtractCalendar('2024-03-31', '1m'), '2024-02-29');
  assert.deepEqual(rangeForPreset('1w', '2024-03-10'), { start: '2024-03-03', end: '2024-03-10' });
});

test('longest common preset never hides a shorter asset history', () => {
  assert.equal(chooseLongestCommonPreset(['2018-01-01', '2022-01-01'], '2026-08-02'), '3y');
  assert.equal(rangeForPreset('max', '2026-08-02', ['2010-01-01', '2016-01-01']).start, '2016-01-01');
});

test('at least one comparison asset remains selected', () => {
  const rejected = keepAtLeastOne(new Set(['gold']), 'gold', false);
  assert.equal(rejected.accepted, false);
  assert.deepEqual([...rejected.selected], ['gold']);
  const accepted = keepAtLeastOne(new Set(['gold', 'bitcoin']), 'gold', false);
  assert.equal(accepted.accepted, true);
  assert.deepEqual([...accepted.selected], ['bitcoin']);
});

test('return classes are semantic in addition to their sign text', () => {
  assert.equal(classifyReturn(3), 'is-positive');
  assert.equal(classifyReturn(-3), 'is-negative');
  assert.equal(classifyReturn(0), 'is-neutral');
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
  assert.match(lines[1], /21,00\s%/);
  assert.match(lines[2], /12\.100,00\s€/);
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
