// Read-only compatibility check against the existing public PHP API. No sync or writes.
import assert from 'node:assert/strict';
import { standardPortfolios, prepareMarket, evaluate, optimize, OBJECTIVES, ASSETS, defaults, optimizationConfig } from '../assets/js/asset-backtester/portfolio-engine.js';
import { rangeForPreset,todayIso } from '../assets/js/asset-backtester/calculations.js';
const base = 'https://smstark-projekt.de/dashboards/backtester/api/backtester/';
const assetsResponse = await fetch(`${base}assets.php`);
assert.equal(assetsResponse.status, 200); const assets = await assetsResponse.json(); assert.equal(assets.success, true);
const templates = standardPortfolios(); const slugs = ['msci_world', 'global_ig_bonds', 'euro_money_market'];
const series = await Promise.all(slugs.map(async (slug) => {
  const query = new URLSearchParams({ assets: slug, start: templates[0].start, end: templates[0].end,
    amount: '10000', includeRentalCashflow: '0', includeTer: '0', includeTaxes: '0', inflation: 'none' });
  const response = await fetch(`${base}series.php?${query}`); const payload = await response.json();
  assert.equal(response.status, 200); assert.equal(payload.success, true);
  assert.equal(payload.series.length, 1); return payload.series[0];
}));
for (const config of templates) {
  const market = prepareMarket(series, config); const result = evaluate(market, config);
  assert.equal(result.valid, true, JSON.stringify(result.checks));
  console.log(JSON.stringify({ portfolio: config.name, frequency: market.frequency, start: market.dates[0], end: market.dataEnd,
    observations: result.returns.length, volatilityPct: result.metrics.annualizedVolatilityPct,
    referenceVolatilityPct: result.reference.metrics.annualizedVolatilityPct,
    expectedShortfallPct: result.metrics.expectedShortfallPct, valid: result.valid }));
}
const config = templates[1]; const market = prepareMarket(series, config); const found = optimize(market, config, 250);
assert.equal(evaluate(market, found.config).valid, true);
console.log(JSON.stringify({ optimizedWeights: found.config.weights, checked: true, apiAssets: assets.assets.length }));
for (const objective of Object.keys(OBJECTIVES)) {
  const c = { ...config, objective, optimizationAssets: slugs };
  const grid = prepareMarket(series, optimizationConfig(c));
  const model = optimize(grid,c,250);
  assert.equal(evaluate(grid,model.config).valid,true);
  assert.equal(model.optimization.objective,objective);
  assert.ok(Number.isFinite(model.optimization.value));
  console.log(JSON.stringify({objective,value:model.optimization.value,weights:model.config.weights,valid:true,candidates:model.optimization.candidates}));
}
const range=rangeForPreset('10y',todayIso(),[]);
const allConfig=defaults({...range,optimizationAssets:Object.keys(ASSETS)});
const allSeries=await Promise.all(Object.keys(ASSETS).map(async(slug)=>{
  const query=new URLSearchParams({assets:slug,...range,amount:'10000',includeRentalCashflow:'0',includeTer:'0',includeTaxes:'0',inflation:'none'});
  const response=await fetch(`${base}series.php?${query}`);const payload=await response.json();
  assert.equal(response.status,200);assert.equal(payload.success,true);assert.equal(payload.series.length,1);return payload.series[0];
}));
const allMarket=prepareMarket(allSeries,optimizationConfig(allConfig));
const allModel=optimize(allMarket,allConfig,500);
assert.equal(evaluate(allMarket,allModel.config).valid,true);
console.log(JSON.stringify({allAssetDefault:true,eligible:allConfig.optimizationAssets.length,requestedStart:range.start,effectiveStart:allMarket.dates[0],frequency:allMarket.frequency,checked:true}));
