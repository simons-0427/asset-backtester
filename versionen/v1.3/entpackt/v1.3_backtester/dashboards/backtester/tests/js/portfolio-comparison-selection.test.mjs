import test from 'node:test';
import assert from 'node:assert/strict';
import {PortfolioLab} from '../../assets/js/asset-backtester/portfolio-ui.js';
import {defaults,cleanConfig} from '../../assets/js/asset-backtester/portfolio-engine.js';
import {exportPortfolios} from '../../assets/js/asset-backtester/portfolio-store.js';
const taxState=state=>Object.assign(state,{getTaxSettings:()=>({taxesEnabled:false}),taxErrors:new Map(),taxViewCache:new WeakMap(),taxEntries:PortfolioLab.prototype.taxEntries});
const points=[{date:'2020-01-31',portfolioValue:500},{date:'2020-02-29',portfolioValue:550}];
const entry=(name)=>({config:{name,amount:500},result:{points,reference:{points}}});
test('portfolio comparison defaults to hidden and only explicitly selected lines are returned',()=>{
  const state={results:new Map([['a',entry('A')],['b',entry('B')]]),comparisonSelection:new Set()};
  assert.deepEqual(PortfolioLab.prototype.comparisonSeries.call(taxState(state),{}),[]);
  state.comparisonSelection.add('b');
  const selected=PortfolioLab.prototype.comparisonSeries.call(taxState(state),{},25000);
  assert.deepEqual(selected.map(s=>s.slug),['portfolio-b']);
  assert.equal(selected[0].points[0].portfolioValue,10000);
  assert.equal(selected[0].points[1].portfolioValue,11000);
  state.comparisonSelection.add('reference');
  assert.deepEqual(PortfolioLab.prototype.comparisonSeries.call(taxState(state),{}).map(s=>s.slug),['portfolio-b','portfolio-reference']);
  state.comparisonSelection.clear();
  assert.equal(PortfolioLab.prototype.comparisonSeries.call(taxState(state),{}).length,0);
});
test('drafts and stored or imported portfolios normalize old capital to EUR 10000',()=>{
  const original=defaults({amount:25000});
  const draft=PortfolioLab.prototype.draftConfig.call({comparisonStart:'2020-01-01',comparisonEnd:'2025-12-31'},original);
  assert.equal(draft.amount,10000);
  assert.equal(original.amount,25000);
  assert.equal(cleanConfig(original).amount,10000);
  assert.equal(JSON.parse(exportPortfolios([original])).portfolios[0].amount,10000);
});
test('select all and clear all include portfolio options and the independent reference',()=>{
  const state={results:new Map([['a',entry('A')]]),comparisonSelection:new Set(),renderComparisonOptions(){},comparisonOptions:PortfolioLab.prototype.comparisonOptions};
  PortfolioLab.prototype.selectAllComparisons.call(state,true);
  assert.deepEqual([...state.comparisonSelection],['a','reference']);
  PortfolioLab.prototype.selectAllComparisons.call(state,false);
  assert.equal(state.comparisonSelection.size,0);
});
test('English portfolio comparison applies the same selection and fixed capital',async()=>{
  const {PortfolioLab:English}=await import('../../../../en/dashboards/backtester/assets/js/asset-backtester/portfolio-ui.js');
  const state={results:new Map([['a',entry('A')]]),comparisonSelection:new Set(['a'])};
  const selected=English.prototype.comparisonSeries.call(taxState(state),{},50000);
  assert.equal(selected.length,1);
  assert.equal(selected[0].points[0].portfolioValue,10000);
  assert.equal(English.prototype.draftConfig.call({},defaults({amount:500})).amount,10000);
});
