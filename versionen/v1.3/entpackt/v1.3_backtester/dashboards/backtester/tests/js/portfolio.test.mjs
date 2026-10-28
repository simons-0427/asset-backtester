import test from 'node:test';
import assert from 'node:assert/strict';
import { defaults, validateConfig, prepareMarket, evaluate, simulate, covariance, optimize, standardPortfolios, portfolioPeriod, metrics } from '../../assets/js/asset-backtester/portfolio-engine.js';
import { PortfolioStore, parsePortfolioFile, exportPortfolios, STORAGE_KEY } from '../../assets/js/asset-backtester/portfolio-store.js';

function fixture(months = 110, mutate = () => {}) {
  const rows = ['msci_world', 'global_ig_bonds', 'gold', 'bitcoin', 'euro_money_market'].map((slug, s) => {
    let value = 100;
    const points = Array.from({ length: months + 1 }, (_, i) => {
      const date = new Date(Date.UTC(2015, i + 1, 0)).toISOString().slice(0, 10);
      value *= 1 + [0.007 + Math.sin(i * 1.3) * 0.04, 0.002 + Math.cos(i) * 0.009,
        0.003 + Math.sin(i * 0.9) * 0.035, 0.03 + Math.sin(i) * 0.3, 0.001][s];
      return { date, portfolioValue: value, lastRawObservationDate: date, isCarriedForward: false };
    });
    return { slug, nativeFrequency: 'daily', points };
  });
  mutate(rows);
  const c = defaults({ start: '2015-01-01', end: '2024-12-31', frequency: 'monthly' });
  return { rows, config: c, market: prepareMarket(rows, c) };
}
test('three mandatory rules reject alternative concentration and unknown/nonfinite input', () => {
  assert.deepEqual(validateConfig(defaults()), []);
  assert.match(validateConfig(defaults({ weights: { bitcoin: 11, msci_world: 89 } })).join(), /10 %/);
  assert.match(validateConfig(defaults({ weights: { bitcoin: 10, gold: 10, commodities: 10, de_real_estate: 10, msci_world: 60 } })).join(), /30 %/);
  assert.ok(validateConfig(defaults({ weights: { msci_world: NaN } })).length);
  assert.ok(validateConfig(defaults({ weights: { unknown: 100 } })).length);
});
test('reference has exactly matching returns and passes all three mandatory rules', () => {
  const { market, config } = fixture(); const result = evaluate(market, { ...config, rebalancing: 'quarterly' });
  assert.equal(result.valid, true); assert.deepEqual(result.returns, result.reference.returns);
  assert.equal(result.trackingErrorPct, 0);
  assert.ok(Math.abs(Object.values(result.riskContributions).reduce((a, b) => a + b, 0) - 100) < 1e-7);
});
test('buy and hold uses drifting holdings instead of rebalancing each period', () => {
  const market = { dates: ['2020-01-01', '2020-02-01', '2020-03-01'], annualization: 12,
    returns: { msci_world: [1, -0.5], global_ig_bonds: [0, 0] } };
  const config = defaults({ amount: 100, rebalancing: 'none' });
  assert.equal(simulate(market, { msci_world: 50, global_ig_bonds: 50 }, config).metrics.finalValue, 100);
  assert.equal(simulate(market, { msci_world: 50, global_ig_bonds: 50 }, { ...config, rebalancing: 'monthly' }).metrics.finalValue, 112.5);
});
test('monthly data never create synthetic daily zero returns', () => {
  const { rows, config } = fixture(60);
  const expanded = rows.map((s) => ({ ...s, points: s.points.flatMap((p) => [p,
    { ...p, date: p.date.slice(0, 8) + '15', isCarriedForward: true }]) }));
  const market = prepareMarket(expanded, { ...config, frequency: 'auto' });
  assert.equal(market.frequency, 'monthly'); assert.equal(market.returns.msci_world.length, 60);
});
test('a missing asset update retains its known price without dropping the period', () => {
  const {market}=fixture(60,rows=>rows[1].points.splice(20,1));
  assert.equal(market.dates.length,61);
  assert.equal(market.prices.global_ig_bonds[20],market.prices.global_ig_bonds[19]);
  assert.equal(market.returns.global_ig_bonds[19],0);
});
test('ES is unavailable with insufficient observations and cannot pass an active rule', () => {
  const { market, config } = fixture(24);
  const result = evaluate(market, { ...config, rules: { shortfall: { mode: 'reference', value: 5 } } });
  assert.equal(result.metrics.expectedShortfallPct, null); assert.equal(result.valid, false);
  assert.equal(result.checks.at(-1).available, false);
});
test('zero variance produces no fictional risk contributions', () => {
  const { rows, config } = fixture(60, (rows) => rows.forEach((s) => s.points.forEach((p) => { p.portfolioValue = 100; })));
  const result = evaluate(prepareMarket(rows, config), config);
  assert.equal(result.metrics.annualizedVolatilityPct, 0);
  assert.deepEqual(Object.values(result.riskContributions), [null, null]);
});
test('optimisation only returns portfolios that independently pass rules', () => {
  const { rows, config } = fixture();
  const c = { ...config, weights: { msci_world: 50, global_ig_bonds: 40, gold: 10 } };
  const market = prepareMarket(rows, c); const found = optimize(market, c, 80);
  assert.deepEqual(validateConfig(found.config), []);
  assert.equal(evaluate(market, found.config).valid, true);
});
test('browser storage round trips five slots, protects capacity and reports write failures', () => {
  const data = new Map(); const store = new PortfolioStore({ getItem: (k) => data.get(k) ?? null, setItem: (k, v) => data.set(k, v) });
  let saved = [];
  for (let i = 0; i < 5; i++) saved = store.save(saved, defaults({ name: `Portfolio ${i}` })).portfolios;
  assert.deepEqual(store.load(), saved); assert.ok(data.has(STORAGE_KEY));
  assert.throws(() => store.save(saved, defaults()), /fünf/);
  assert.equal(store.save(saved, { ...saved[0], name: 'Geändert' }).portfolios.length, 5);
  assert.throws(() => store.import(saved, exportPortfolios([defaults()])), /fünf/);
  const broken = new PortfolioStore({ getItem: () => null, setItem: () => { throw Error('quota'); } });
  assert.throws(() => broken.save([], defaults()), /fehlgeschlagen/);
});
test('invalid imports are rejected atomically and standard templates use no personal slot', () => {
  assert.throws(() => parsePortfolioFile('{broken'), /JSON/);
  assert.throws(() => parsePortfolioFile(exportPortfolios([defaults()]).replace('"version": 1', '"version": 99')), /format/i);
  standardPortfolios().forEach((p) => assert.deepEqual(validateConfig(p), []));
});
test('allocation sliders allow up to 100 percent while enforcing chosen limits', () => {
  const c = defaults({ enabledLimits:{alternatives:true,position:true,volatility:true}, limits: { alternatives: 15, position: 5, volatility: 80 } });
  assert.deepEqual(validateConfig(c), []);
  assert.ok(validateConfig({ ...c, limits: { ...c.limits, alternatives: 101 } }).length);
  assert.ok(validateConfig({ ...c, weights: { msci_world: 90, gold: 10 } }).length);
  const { rows } = fixture(); const model = { ...c, weights: { msci_world: 40, global_ig_bonds: 40, euro_money_market: 20 } };
  const market = prepareMarket(rows, model);
  const found = optimize(market, model, 150);
  const result = evaluate(market, found.config);
  assert.equal(result.valid, true);
  assert.ok(result.metrics.annualizedVolatilityPct <= result.reference.metrics.annualizedVolatilityPct * 0.8 + 1e-6);
});
test('automatic modelling accepts an unbalanced draft but outputs a fully valid allocation', () => {
  const c = defaults({ weights: { msci_world: 60, global_ig_bonds: 40, gold: 10 }, limits: { alternatives: 5, position: 2, volatility: 100 } });
  assert.ok(validateConfig(c).length);
  assert.deepEqual(validateConfig(c, { allocation: false }), []);
  const { rows } = fixture(); const market = prepareMarket(rows, c); const found = optimize(market, c, 150);
  assert.deepEqual(validateConfig(found.config), []);
  assert.ok((found.config.weights.gold || 0) <= 2);
});
test('reference-relative loss sliders are applied as a fraction of the reference loss', () => {
  const { market, config } = fixture();
  const result = evaluate(market, { ...config, rules: { drawdown: { mode: 'reference', value: 50 } } });
  assert.equal(result.checks.at(-1).limit, Math.abs(result.reference.metrics.maxDrawdownPct) / 2);
  assert.equal(result.valid, false);
});
test('monthly portfolio summaries have no fictional weekly return and use one actual month', () => {
  const result = { points: [ { date: '2023-07-31', portfolioValue: 100 }, { date: '2023-08-31', portfolioValue: 110 }, { date: '2023-09-30', portfolioValue: 121 } ] };
  assert.equal(portfolioPeriod(result, 'monthly', '1W'), null);
  assert.deepEqual(portfolioPeriod(result, 'monthly', '1M'), { start: '2023-08-31', end: '2023-09-30', factor: 1.1 });
  assert.equal(portfolioPeriod(result, 'monthly', '6M'), null);
  assert.equal(portfolioPeriod(result, 'quarterly', '1M'), null);
});
test('volatility, drawdown and expected shortfall agree with independently known examples', () => {
  const points = [100, 110, 99, 99].map((value, i) => ({ date: `2020-0${i + 1}-01`, portfolioValue: value, drawdownPct: [0, 0, -10, -10][i] }));
  const result = metrics(points, [0.1, -0.1, 0], 12);
  assert.ok(Math.abs(result.annualizedVolatilityPct - Math.sqrt(0.12) * 100) < 1e-8);
  assert.equal(result.maxDrawdownPct, -10);
  const tail = [-0.2, -0.1, ...Array(38).fill(0)];
  assert.ok(Math.abs(metrics(points, tail, 12).expectedShortfallPct - 15) < 1e-8);
});
test('a failed allocation search identifies the conflicting rule without relaxing it', () => {
  const { market, config } = fixture();
  assert.throws(() => optimize(market, { ...config, rules: { contribution: { mode: 'absolute', value: 1 } } }, 20), /Keine zulässige Allokation.*Risikobeitrag/);
});
