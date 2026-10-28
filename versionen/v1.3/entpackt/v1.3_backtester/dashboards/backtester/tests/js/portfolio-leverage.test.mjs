import test from 'node:test';
import assert from 'node:assert/strict';
import { defaults as portfolioDefaults, simulate, evaluate, optimize, validateConfig, cleanConfig } from '../../assets/js/asset-backtester/portfolio-engine.js';
const defaults=(overrides={})=>portfolioDefaults({enabledLimits:{alternatives:true,position:true,volatility:true},rules:{},...overrides});
import { PortfolioStore } from '../../assets/js/asset-backtester/portfolio-store.js';
import { buildPortfolioComparison } from '../../assets/js/asset-backtester/portfolio-comparison.js';
import { ChartManager } from '../../assets/js/asset-backtester/charts.js';

const market = { dates: ['2020-01-01','2021-01-01','2022-01-01'], annualization: 1,
  returns: { msci_world: [0.1,-0.1], global_ig_bonds: [0.02,0.01] } };
test('leverage compounds each observed period rather than multiplying the final return', () => {
  const result = simulate(market,{msci_world:100},defaults({amount:100, leverage:2, rebalancing:'none'}));
  assert.ok(Math.abs(result.metrics.finalValue - 96) < 1e-10);
  assert.ok(Math.abs(result.returns[0] - 0.2) < 1e-10);
  assert.ok(Math.abs(result.returns[1] + 0.2) < 1e-10);
});
test('annual costs use calendar time and financing applies only to borrowed exposure', () => {
  const flat = {...market, returns: {msci_world:[0,0]}};
  const years = (Date.parse('2022-01-01') - Date.parse('2020-01-01')) / 86400000 / 365.2425;
  const result = simulate(flat,{msci_world:100},defaults({amount:100,leverage:2,terPct:1,financingPct:5}));
  assert.ok(Math.abs(result.metrics.finalValue - 100 * 0.99 ** years * Math.exp(-0.05 * years)) < 1e-10);
  const noDebt = simulate(flat,{msci_world:100},defaults({amount:100,leverage:1,financingPct:5}));
  assert.equal(noDebt.metrics.finalValue,100);
});
test('the 60/40 reference always stays unleveraged and free of user costs', () => {
  const plain=evaluate(market,defaults());
  const levered=evaluate(market,defaults({leverage:2,terPct:1,financingPct:5}));
  assert.deepEqual(levered.reference.metrics,plain.reference.metrics);
  assert.ok(levered.checks.find(c=>c.key==='volatility').passed===false);
});
test('alternative limits apply to market exposure after leverage', () => {
  assert.ok(validateConfig(defaults({leverage:2,weights:{msci_world:94,bitcoin:6}})).length);
  assert.deepEqual(validateConfig(defaults({leverage:2,weights:{msci_world:95,bitcoin:5}})),[]);
  assert.ok(validateConfig(defaults({leverage:3.1})).length);
  assert.ok(validateConfig(defaults({terPct:-1})).length);
  assert.ok(validateConfig(defaults({financingPct:NaN})).length);
});
test('a total loss is explicit and cannot turn into a negative portfolio', () => {
  assert.throws(()=>simulate({...market,returns:{msci_world:[-0.6,0.5]}},{msci_world:100},defaults({leverage:2})),/Totalverlust/);
});
test('legacy saved portfolios acquire safe unleveraged defaults', () => {
  const old=defaults(); delete old.leverage; delete old.terPct; delete old.financingPct;
  const loaded=cleanConfig(old);
  assert.equal(loaded.leverage,1); assert.equal(loaded.terPct,0); assert.equal(loaded.financingPct,0);
});
test('overall comparison rebases on actual dates and never extends outside the test', () => {
  const entry={config:{name:'Example'},result:{points:[{date:'2020-01-31',portfolioValue:100},{date:'2020-02-29',portfolioValue:120},{date:'2020-03-31',portfolioValue:132}]}};
  const series=buildPortfolioComparison([['test',entry]],{start:'2020-02-01',end:'2020-03-31'},500);
  assert.equal(series.length,1); assert.equal(series[0].points[0].date,'2020-02-29');
  assert.equal(series[0].points[0].portfolioValue,10000); assert.equal(series[0].points[1].portfolioValue,11000);
  assert.equal(series[0].displayName,'Example / Portfolio');
  assert.equal(buildPortfolioComparison([['test',entry]],{start:'2021-01-01'},500).length,0);
});
test('English and German models produce identical numeric results and English validation', async()=>{
  const english=await import('../../../../en/dashboards/backtester/assets/js/asset-backtester/portfolio-engine.js');
  const config=defaults({leverage:1.2,terPct:0.3,financingPct:4});
  const a=evaluate(market,config); const b=english.evaluate(market,config);
  assert.deepEqual(a.metrics,b.metrics); assert.deepEqual(a.returns,b.returns);
  assert.deepEqual(a.reference.metrics,b.reference.metrics);
  assert.match(english.validateConfig({...config,leverage:4}).join(),/Leverage/);
});
test('the overall chart aligns monthly portfolios with daily assets without moving dates',()=>{
  class FakeChart { static defaults={font:{}}; constructor(_ctx,config){this.config=config;} destroy(){} }
  const manager=new ChartManager(FakeChart);
  const chart=manager.comparison({id:'comparison',getContext:()=>({})},[
    {slug:'gold',displayName:'Gold',points:[{date:'2020-01-01',portfolioValue:100,sourceValue:100},{date:'2020-01-03',portfolioValue:110,sourceValue:110}]},
    {slug:'portfolio-test',displayName:'Portfolio',isPortfolio:true,portfolioStyle:{color:'#34d399'},points:[{date:'2020-01-02',portfolioValue:100,returnPct:0},{date:'2020-01-03',portfolioValue:105,returnPct:5}]}
  ]);
  assert.deepEqual(chart.config.data.labels,['2020-01-01','2020-01-02','2020-01-03']);
  assert.deepEqual(chart.config.data.datasets[0].data,[100,null,110]);
  assert.deepEqual(chart.config.data.datasets[1].data,[null,100,105]);
  assert.deepEqual(chart.config.data.datasets[1].borderDash,[7,3]);
});
test('each base rule can be disabled independently without bypassing enabled rules',()=>{
  const volatile = defaults({weights:{msci_world:100},enabledLimits:{volatility:false}});
  assert.equal(evaluate(market,volatile).valid,true);
  assert.equal(evaluate(market,defaults({weights:{msci_world:100}})).valid,false);
  const alternatives = {...market,returns:{...market.returns,bitcoin:[0.5,-0.4]}};
  const unrestricted=defaults({weights:{bitcoin:100},enabledLimits:{alternatives:false,position:false,volatility:false}});
  assert.equal(evaluate(alternatives,unrestricted).valid,true);
  assert.ok(evaluate(alternatives,unrestricted).checks.every(c=>!c.enabled));
  assert.ok(validateConfig({...unrestricted,enabledLimits:{...unrestricted.enabledLimits,position:true}}).length);
  assert.ok(validateConfig({...unrestricted,enabledLimits:{...unrestricted.enabledLimits,alternatives:true}}).length);
  assert.ok(validateConfig({...unrestricted,enabledLimits:{position:'false'}}).length);
});
test('different asset leverages compound in their own sleeves and change weight drift',()=>{
  const config=defaults({amount:100,rebalancing:'none',weights:{msci_world:50,global_ig_bonds:50},assetSettings:{msci_world:{leverage:2,terPct:0},global_ig_bonds:{leverage:1,terPct:0}}});
  const result=simulate(market,config.weights,config);
  const expected=50*1.2*0.8 + 50*1.02*1.01;
  assert.ok(Math.abs(result.metrics.finalValue-expected)<1e-10);
  assert.ok(Math.abs(result.points[1].weights.msci_world - 60/111*100)<1e-10);
  assert.ok(Math.abs(result.realizedContributions[0][0] - 0.1)<1e-10);
});
test('per-asset TER and financing are charged only to their respective sleeves',()=>{
  const flat={...market,returns:{msci_world:[0,0],global_ig_bonds:[0,0]}};
  const config=defaults({amount:100,rebalancing:'none',weights:{msci_world:50,global_ig_bonds:50},financingPct:5,assetSettings:{msci_world:{leverage:2,terPct:1},global_ig_bonds:{leverage:1,terPct:0}}});
  const years=(Date.parse('2022-01-01')-Date.parse('2020-01-01'))/86400000/365.2425;
  const expected=50*0.99**years*Math.exp(-0.05*years)+50;
  assert.ok(Math.abs(simulate(flat,config.weights,config).metrics.finalValue-expected)<1e-10);
});
test('individual leverage applies to active alternative exposure limits',()=>{
  const base=defaults({weights:{msci_world:94,bitcoin:6},assetSettings:{msci_world:{leverage:1,terPct:0},bitcoin:{leverage:2,terPct:0}}});
  assert.ok(validateConfig(base).length);
  assert.deepEqual(validateConfig({...base,enabledLimits:{position:false}}),[]);
  const config={...base,weights:{msci_world:80,bitcoin:20},enabledLimits:{position:false,alternatives:false,volatility:false}};
  assert.deepEqual(validateConfig(config),[]);
  const result=evaluate({...market,returns:{...market.returns,bitcoin:[0.1,-0.1]}},config);
  assert.equal(result.checks.find(c=>c.key==='alternatives').value,40);
  assert.equal(result.checks.find(c=>c.key==='position').value,40);
});
test('per-asset settings cannot contaminate the unleveraged cost-free reference',()=>{
  const config=defaults({assetSettings:{msci_world:{leverage:3,terPct:1},global_ig_bonds:{leverage:2,terPct:0.5}}});
  assert.deepEqual(evaluate(market,config).reference.metrics,evaluate(market,defaults()).reference.metrics);
});
test('optimisation permits alternatives-only portfolios when allocation rules are off',()=>{
  const config=defaults({weights:{bitcoin:100},enabledLimits:{alternatives:false,position:false,volatility:false},assetSettings:{bitcoin:{leverage:1.2,terPct:0.2}}});
  const extended={...market,returns:{...market.returns,bitcoin:[0.1,-0.1]}};
  const found=optimize(extended,config,20);
  assert.equal(found.result.valid,true); assert.equal(found.config.weights.bitcoin,100);
  assert.equal(found.config.assetSettings.bitcoin.leverage,1.2);
});
test('storage and old portfolio migration preserve toggles and per-asset settings',()=>{
  const migrated=cleanConfig(defaults({leverage:2}));
  assert.equal(migrated.leverage,1);
  assert.equal(migrated.assetSettings.msci_world.leverage,2);
  assert.equal(migrated.assetSettings.global_ig_bonds.leverage,2);
  const memory=new Map();const store=new PortfolioStore({getItem:k=>memory.get(k)??null,setItem:(k,v)=>memory.set(k,v)});
  const c=defaults({enabledLimits:{alternatives:false,position:false,volatility:true},assetSettings:{msci_world:{leverage:1.5,terPct:0.3},global_ig_bonds:{leverage:1,terPct:0.1}}});
  const saved=store.save([],c).portfolios;
  assert.deepEqual(store.load(),saved);
  assert.equal(store.load()[0].enabledLimits.position,false);
  assert.equal(store.load()[0].assetSettings.msci_world.leverage,1.5);
  assert.equal(store.load()[0].assetSettings.global_ig_bonds.terPct,0.1);
  assert.ok(validateConfig({...c,assetSettings:{msci_world:{leverage:4,terPct:0}}}).length);
});
test('disabled base rules never disable optional rules or structural validation',()=>{
  const c=defaults({weights:{msci_world:100},enabledLimits:{alternatives:false,position:false,volatility:false},rules:{tracking:{mode:'absolute',value:0}}});
  const result=evaluate(market,c);
  assert.equal(result.valid,false);
  assert.equal(result.checks.find(x=>x.key==='tracking').enabled,true);
  assert.equal(result.checks.find(x=>x.key==='tracking').passed,false);
  assert.ok(validateConfig({...c,weights:{msci_world:101}}).length);
});
