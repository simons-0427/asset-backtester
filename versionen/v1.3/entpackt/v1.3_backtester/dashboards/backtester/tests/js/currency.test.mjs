import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import {
  applyHistoricalCurrencyToSeries,
  buildEurCurrencyHistory,
  currencyGrowthFactor,
  DISPLAY_CURRENCIES,
  currencyRatesFromMetrics,
  loadStoredCurrencyHistory,
  loadStoredCurrencyRates,
} from '../../assets/js/asset-backtester/currency.js';
import {
  formatCompactMoney,
  formatEuro,
  setDisplayCurrency,
} from '../../assets/js/asset-backtester/formatters.js';

const storedMetrics = [
  { id: 'eurusd', latest: { value: 1.2 } },
  { id: 'gbpusd', latest: { value: 1.5 } },
  { id: 'usdjpy', latest: { value: 150 } },
  { id: 'usdcny', latest: { value: 7.2 } },
];

test('gespeicherte Devisenwerte ergeben kohärente EUR-Kreuzkurse', () => {
  assert.deepEqual(DISPLAY_CURRENCIES, ['EUR', 'GBP', 'USD', 'JPY', 'CNY']);
  const rates = currencyRatesFromMetrics(storedMetrics);
  assert.equal(rates.EUR, 1);
  assert.equal(rates.USD, 1.2);
  assert.ok(Math.abs(rates.GBP - 0.8) < 1e-12);
  assert.equal(rates.JPY, 180);
  assert.equal(rates.CNY, 8.64);
});

test('fehlende Devisenreihen deaktivieren nur die davon abhängige Währung', () => {
  const partialRates = currencyRatesFromMetrics(storedMetrics.slice(0, 2));
  assert.deepEqual(Object.keys(partialRates), ['EUR', 'USD', 'GBP']);
  assert.ok(Math.abs(partialRates.GBP - 0.8) < 1e-12);
  assert.deepEqual(currencyRatesFromMetrics([]), { EUR: 1 });
});

test('Devisenwerte werden ausschließlich lesend aus der Weltmarkt-Momentaufnahme geladen', async () => {
  let request = null;
  const rates = await loadStoredCurrencyRates(async (url, options) => {
    request = { url, options };
    return {
      ok: true,
      json: async () => ({ success: true, metrics: storedMetrics }),
    };
  });

  assert.equal(request.url, '../weltmarkt/api/weltmarkt/metrics.php');
  assert.equal(request.options.method, 'GET');
  assert.equal(request.options.credentials, 'same-origin');
  assert.equal(rates.JPY, 180);
});

test('Devisenhistorie wird ausschließlich lesend aus der gespeicherten Weltmarkt-Zeitreihe geladen', async () => {
  const requests = [];
  const history = await loadStoredCurrencyHistory('USD', async (url, options) => {
    requests.push({ url, options });
    return {
      ok: true,
      json: async () => ({
        success: true,
        points: [
          { date: '2020-01-01', value: 1 },
          { date: '2021-01-01', value: 2 },
        ],
      }),
    };
  });

  assert.equal(requests.length, 1);
  assert.match(requests[0].url, /series\.php\?metric=eurusd$/);
  assert.equal(requests[0].options.method, 'GET');
  assert.equal(currencyGrowthFactor(history, '2020-01-01', '2021-01-01'), 2);
});

test('Geldbeträge und kompakte Achsenwerte verwenden dieselbe Anzeigewährung', () => {
  assert.equal(setDisplayCurrency('USD', 1), true);
  assert.match(formatEuro(12000), /12\.000,00.*\$/);
  assert.match(formatCompactMoney(12000), /12.*\$/);

  assert.equal(setDisplayCurrency('JPY', 1), true);
  assert.match(formatEuro(10000), /10\.000.*¥/);

  assert.equal(setDisplayCurrency('EUR', 1), true);
});

test('historische Wechselkursentwicklung fließt in die Fremdwährungsperformance ein', () => {
  const history = buildEurCurrencyHistory('USD', {
    eurusd: [
      { date: '2020-01-01', value: 1 },
      { date: '2021-01-01', value: 2 },
    ],
  });
  assert.equal(currencyGrowthFactor(history, '2020-01-01', '2021-01-01'), 2);

  const original = {
    slug: 'sp500',
    effectiveStartDate: '2020-01-01',
    effectiveEndDate: '2021-01-01',
    points: [
      { date: '2020-01-01', portfolioValue: 1, priceOnlyPortfolioValue: 1, isCarriedForward: false },
      { date: '2021-01-01', portfolioValue: 1, priceOnlyPortfolioValue: 1, isCarriedForward: false },
    ],
    metrics: {},
  };
  const adjusted = applyHistoricalCurrencyToSeries(original, 'USD', history);
  assert.equal(adjusted.points[0].portfolioValue, 1);
  assert.equal(adjusted.points[1].portfolioValue, 2);
  assert.equal(adjusted.metrics.totalReturnPct, 100);
  assert.equal(applyHistoricalCurrencyToSeries(original, 'EUR', history), original);
});

test('historische Kreuzkurse werden pro Datum kohärent aus den gespeicherten Paaren gebildet', () => {
  const gbp = buildEurCurrencyHistory('GBP', {
    eurusd: [
      { date: '2020-01-01', value: 1.2 },
      { date: '2021-01-01', value: 1.4 },
    ],
    gbpusd: [
      { date: '2020-01-01', value: 1.5 },
      { date: '2021-01-01', value: 1.4 },
    ],
  });
  assert.ok(Math.abs(gbp[0].value - 0.8) < 1e-12);
  assert.equal(gbp[1].value, 1);
  assert.equal(currencyGrowthFactor(gbp, '2020-01-01', '2021-01-01'), 1.25);
});

test('Währungsauswahl steht oben direkt neben dem Datenstand und zeigt Symbol plus Code', () => {
  const html = readFileSync(new URL('../../index.html', import.meta.url), 'utf8');
  const pageHead = html.match(/<header class="asset-backtester__page-head">([\s\S]*?)<\/header>/)?.[1] || '';
  const metricsSection = html.match(/aria-labelledby="metrics-title"([\s\S]*?)<\/section>/)?.[1] || '';

  assert.match(pageHead, /id="global-data-status"[\s\S]*id="display-currency"/);
  ['€ EUR', '£ GBP', '$ USD', '¥ JPY', '¥ CNY'].forEach((label) => {
    assert.ok(pageHead.includes(label));
  });
  assert.doesNotMatch(pageHead, /Quelle|Herkunft|Weltmarkt/);
  assert.doesNotMatch(metricsSection, /id="display-currency"/);
});
