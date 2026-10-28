import test from 'node:test';
import assert from 'node:assert/strict';

import { ChartManager, comparisonTooltipReturn, singleTooltipLines } from '../../assets/js/asset-backtester/charts.js';

class FakeChart {
  static defaults = { font: {}, color: '' };

  constructor(_context, config) {
    this.config = config;
    this.destroyed = false;
  }

  destroy() {
    this.destroyed = true;
  }
}

function canvas(id) {
  return { id, getContext: () => ({}) };
}

function msciSeries() {
  return {
    slug: 'msci_world',
    displayName: 'MSCI World',
    currency: 'EUR',
    dividendAdjustment: { applied: true, method: 'annual_yield_december_31' },
    points: [
      {
        date: '2016-08-31', sourceValue: 100, dividendAdjustedSourceValue: 100,
        portfolioValue: 10000, priceOnlyPortfolioValue: 10000, returnPct: 0, isCarriedForward: false,
        lastRawObservationDate: '2016-08-31',
      },
      {
        date: '2016-12-31', sourceValue: 100, dividendAdjustedSourceValue: 102.69,
        portfolioValue: 10269, priceOnlyPortfolioValue: 10000, returnPct: 2.69, isCarriedForward: true,
        lastRawObservationDate: '2016-12-30',
      },
    ],
  };
}

test('MSCI-World-Einzelchart trennt Preis und Dividendenlinie', () => {
  const manager = new ChartManager(FakeChart);
  const chart = manager.single(canvas('chart-msci_world'), msciSeries());

  assert.deepEqual(chart.config.data.datasets.map(({ label }) => label), [
  'Preisindex',
    'Preis + Dividenden',
  ]);
  assert.equal(chart.config.data.datasets[0].borderColor, '#22d3ee');
  assert.equal(chart.config.data.datasets[1].borderColor, '#f472b6');
  assert.equal(chart.config.data.datasets[1].data.at(-1), 102.69);
  assert.ok(singleTooltipLines(msciSeries(), msciSeries().points[1])
    .includes('Methode: Jahresdividende am 31.12.'));
});

test('Einzelchart zeigt bei Fremdwährung den wechselkursbereinigten Portfoliowert', () => {
  const series = msciSeries();
  series.currencyAdjustment = { applied: true, currency: 'USD' };
  series.points[1].priceOnlyPortfolioValue = 19000;
  series.points[1].portfolioValue = 20000;

  const manager = new ChartManager(FakeChart);
  const chart = manager.single(canvas('chart-msci-world-usd'), series);

  assert.equal(chart.config.data.datasets[0].data.at(-1), 19000);
  assert.equal(chart.config.data.datasets[1].data.at(-1), 20000);
});

test('Gesamtvergleich kann MSCI World mit und ohne Dividenden getrennt zeigen', () => {
  const manager = new ChartManager(FakeChart);
  const chart = manager.comparison(canvas('chart-comparison'), [msciSeries()], {
    showMsciWorldDividend: true,
    showMsciWorldPrice: true,
  });

  assert.deepEqual(chart.config.data.datasets.map(({ label }) => label), [
    'MSCI World',
    'MSCI World ohne Div.',
  ]);
  assert.equal(chart.config.data.datasets[0].data.at(-1), 10269);
  assert.equal(chart.config.data.datasets[1].data.at(-1), 10000);
});

test('Immobilienchart trennt Preisindex und Netto-Mietcashflow', () => {
  const series = {
    slug: 'us_real_estate',
    displayName: 'Immobilien USA',
    currency: 'PTS',
    dividendAdjustment: { applied: false },
    rentalCashflowAdjustment: {
      applied: true,
      annualNetCashflowPct: 2,
      method: 'simple_net_cashflow_on_initial_capital',
    },
    points: [
      {
        date: '2025-01-01', sourceValue: 100, rentalCashflowAdjustedSourceValue: 100,
        portfolioValue: 10000, returnPct: 0, isCarriedForward: false,
        lastRawObservationDate: '2025-01-01',
      },
      {
        date: '2026-01-01', sourceValue: 110, rentalCashflowAdjustedSourceValue: 112,
        portfolioValue: 11200, returnPct: 12, isCarriedForward: false,
        lastRawObservationDate: '2026-01-01',
      },
    ],
  };
  const manager = new ChartManager(FakeChart);
  const single = manager.single(canvas('chart-us-real-estate'), series);
  assert.deepEqual(single.config.data.datasets.map(({ label }) => label), [
  'Preisindex',
    'Preis + Netto-Mietcashflow',
  ]);
  assert.equal(single.config.data.datasets[1].data.at(-1), 112);

  const comparison = manager.comparison(canvas('chart-comparison-property'), [series]);
  assert.deepEqual(comparison.config.data.datasets.map(({ label }) => label), [
    'Immobilien USA',
  ]);
  assert.equal(comparison.config.data.datasets[0].data.at(-1), 11000);
});

test('DAX kann im Gesamtvergleich mit und ohne Dividenden erscheinen', () => {
  const series = {
    ...msciSeries(),
    slug: 'dax',
    displayName: 'DAX',
    currency: 'PTS',
  };
  const manager = new ChartManager(FakeChart);
  const comparison = manager.comparison(canvas('chart-comparison-dax'), [series], {
    showDaxDividend: true,
    showDaxPrice: true,
  });
  assert.deepEqual(comparison.config.data.datasets.map(({ label }) => label), [
    'DAX',
    'DAX ohne Div.',
  ]);
  assert.equal(comparison.config.data.datasets[0].borderColor, '#ef4444');
  assert.equal(comparison.config.data.datasets[1].borderColor, '#fca5a5');
});

test('Immobilien DE verwendet eine eigene Vergleichsreihe', () => {
  const series = {
    slug: 'de_real_estate',
    displayName: 'Immobilien DE',
    currency: 'PTS',
    dividendAdjustment: { applied: false },
    rentalCashflowAdjustment: { applied: true, annualNetCashflowPct: 3 },
    points: [
      {
        date: '2025-01-01', sourceValue: 100, rentalCashflowAdjustedSourceValue: 100,
        portfolioValue: 10000, returnPct: 0, isCarriedForward: false,
        lastRawObservationDate: '2025-01-01',
      },
      {
        date: '2026-01-01', sourceValue: 102, rentalCashflowAdjustedSourceValue: 105,
        portfolioValue: 10500, returnPct: 5, isCarriedForward: true,
        lastRawObservationDate: '2025-12-31',
      },
    ],
  };
  const manager = new ChartManager(FakeChart);
  const comparison = manager.comparison(canvas('chart-comparison-de-property'), [series]);
  assert.deepEqual(comparison.config.data.datasets.map(({ label }) => label), [
    'Immobilien DE',
  ]);
  assert.equal(comparison.config.data.datasets[0].data.at(-1), 10200);
});

test('Inflationsbereinigte Vergleichslinie wird eindeutig als real markiert', () => {
  const series = {
    ...msciSeries(),
    inflationAdjustment: { applied: true, displayName: 'Inflation Deutschland' },
  };
  const manager = new ChartManager(FakeChart);
  const comparison = manager.comparison(canvas('chart-comparison-real'), [series], {
    showMsciWorldDividend: true,
  });
  assert.equal(comparison.config.data.datasets[0].label, 'MSCI World (real)');
});

test('Vergleichstooltip sortiert am Hover-Tag nach Rendite absteigend', () => {
  const manager = new ChartManager(FakeChart);
  const slow = {
    ...msciSeries(),
    slug: 'gold',
    displayName: 'Gold',
    dividendAdjustment: { applied: false },
    points: msciSeries().points.map((point, index) => ({ ...point, portfolioValue: 10000 + index * 100, returnPct: index })),
  };
  const fast = {
    ...msciSeries(),
    slug: 'bitcoin',
    displayName: 'Bitcoin',
    dividendAdjustment: { applied: false },
    points: msciSeries().points.map((point, index) => ({ ...point, portfolioValue: 10000 + index * 500, returnPct: index * 5 })),
  };
  const chart = manager.comparison(canvas('chart-tooltip-order'), [slow, fast]);
  const slowItem = { dataset: chart.config.data.datasets.find(d=>d.label==='Gold'), dataIndex: 1, raw: 10100 };
  const fastItem = { dataset: chart.config.data.datasets.find(d=>d.label==='Bitcoin'), dataIndex: 1, raw: 10500 };
  assert.equal(comparisonTooltipReturn(fastItem), 5);
  assert.ok(chart.config.options.plugins.tooltip.itemSort(fastItem, slowItem) < 0);
});

test('Einzelchart zieht TER in einer eigenen Linie ab', () => {
  const series = {
    ...msciSeries(),
    terAdjustment: { applied: true, annualTerPct: 0.12 },
    points: msciSeries().points.map((point, index) => ({
      ...point,
      terAdjustedSourceValue: index === 0 ? 100 : 102.5,
      terCostAmount: index === 0 ? 0 : 19,
    })),
  };
  const manager = new ChartManager(FakeChart);
  const chart = manager.single(canvas('chart-ter'), series);
  assert.equal(chart.config.data.datasets[1].label, 'Preis + Dividenden nach TER');
  assert.equal(chart.config.data.datasets[1].data.at(-1), 102.5);
  assert.ok(singleTooltipLines(series, series.points[1]).some((line) => line.startsWith('TER:')));
});
