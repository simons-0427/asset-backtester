import test from 'node:test';
import assert from 'node:assert/strict';
import {defaults as portfolioDefaults,evaluate,optimize,referenceVolatility,volatilityLimit,validateConfig,cleanConfig} from '../../assets/js/asset-backtester/portfolio-engine.js';
const defaults=(overrides={})=>portfolioDefaults({enabledLimits:{alternatives:true,position:true,volatility:true},rules:{},...overrides});
import {PortfolioLab} from '../../assets/js/asset-backtester/portfolio-ui.js';
import {exportPortfolios,parsePortfolioFile} from '../../assets/js/asset-backtester/portfolio-store.js';
const market={
 dates:Array.from({length:13},(_,i)=>new Date(Date.UTC(2020,i+1,0)).toISOString().slice(0,10)),
 slugs:['msci_world','global_ig_bonds'],frequency:'monthly',annualization:12,
 returns:{msci_world:Array.from({length:12},(_,i)=>0.01+Math.sin(i)*0.06),global_ig_bonds:Array.from({length:12},(_,i)=>0.002+Math.cos(i)*0.01)}
};
test('absolute volatility limits are annual percentage points, including zero',()=>{
 for(const value of [0,6,150]){
  const config=defaults({volatilityLimitPct:value});
  const result=evaluate(market,config);
  const check=result.checks.find(c=>c.key==='volatility');
  assert.equal(check.limit,value);
  assert.equal(check.passed,check.value<=value+1e-6);
 }
 assert.equal(volatilityLimit(defaults({volatilityLimitPct:8.5}),40),8.5);
});
test('automatic defaults use the actual reference on each data window and preserve legacy restrictions',()=>{
 const config=defaults();
 const first=evaluate(market,config);
 const changed={...market,returns:Object.fromEntries(Object.entries(market.returns).map(([slug,rs])=>[slug,rs.map(r=>r*1.7)]))};
 const second=evaluate(changed,config);
 assert.equal(first.checks.find(c=>c.key==='volatility').limit,referenceVolatility(market,config));
 assert.equal(second.checks.find(c=>c.key==='volatility').limit,referenceVolatility(changed,config));
 assert.notEqual(first.reference.metrics.annualizedVolatilityPct,second.reference.metrics.annualizedVolatilityPct);
 const legacy=defaults({limits:{alternatives:30,position:10,volatility:80}});
 assert.equal(evaluate(market,legacy).checks.find(c=>c.key==='volatility').limit,referenceVolatility(market,legacy)*80/100);
 assert.equal(referenceVolatility(market,defaults({assetSettings:{msci_world:{leverage:3,terPct:2}},terPct:1,financingPct:7})),referenceVolatility(market,config));
});
test('manual limits and automatic mode survive JSON while malformed limits are rejected',()=>{
 for(const value of [null,0,8.25,150]){
  const config=defaults({volatilityLimitPct:value});
  assert.equal(cleanConfig(config).volatilityLimitPct,value);
  assert.equal(parsePortfolioFile(exportPortfolios([config]))[0].volatilityLimitPct,value);
 }
 for(const value of [-1,1001,NaN,Infinity,'8'])assert.ok(validateConfig({...defaults(),volatilityLimitPct:value}).length);
});
test('an older reference request cannot overwrite the current period calculation',async()=>{
 const changed={...market,returns:Object.fromEntries(Object.entries(market.returns).map(([slug,rs])=>[slug,rs.map(r=>r*2)]))};
 let resolveOld,calls=0;
 const oldMarket=new Promise(resolve=>{resolveOld=resolve;});
 const state={config:defaults(),volatilityReferenceRevision:0,renderVolatilityControl(){},marketFor(){return calls++===0?oldMarket:Promise.resolve(changed);}};
 const old=PortfolioLab.prototype.refreshVolatilityReference.call(state);
 state.config.end='2025-12-31';
 await PortfolioLab.prototype.refreshVolatilityReference.call(state);
 const expected=referenceVolatility(changed,state.config);
 resolveOld(market);await old;
 assert.equal(state.volatilityReferencePct,expected);
 state.config.volatilityLimitPct=7.25;
 await PortfolioLab.prototype.refreshVolatilityReference.call(state);
 assert.equal(calls,2);
 assert.equal(state.config.volatilityLimitPct,7.25);
});
test('English absolute limits and dynamic defaults agree numerically',async()=>{
 const en=await import('../../../../en/dashboards/backtester/assets/js/asset-backtester/portfolio-engine.js');
 for(const value of [null,5,125]){
  const config=defaults({volatilityLimitPct:value});
  assert.equal(en.evaluate(market,config).checks.find(c=>c.key==='volatility').limit,evaluate(market,config).checks.find(c=>c.key==='volatility').limit);
 }
});

test('optimisation enforces the absolute annual volatility threshold',()=>{
  const config=defaults({volatilityLimitPct:4,optimizationAssets:['msci_world','global_ig_bonds']});
  const found=optimize(market,config,80);
  assert.equal(found.result.valid,true);
  assert.equal(found.result.checks.find(c=>c.key==='volatility').limit,4);
  assert.ok(found.result.metrics.annualizedVolatilityPct<=4+1e-6);
});
test('failed reference data can be retried instead of remaining cached as unavailable',async()=>{
 let calls=0;
 const state={config:defaults(),volatilityReferenceRevision:0,renderVolatilityControl(){},marketFor(){return calls++===0?Promise.reject(Error('temporary')):Promise.resolve(market);}};
 await PortfolioLab.prototype.refreshVolatilityReference.call(state);
 assert.equal(state.volatilityReferenceKey,null);
 await PortfolioLab.prototype.refreshVolatilityReference.call(state);
 assert.equal(state.volatilityReferencePct,referenceVolatility(market,state.config));
 assert.equal(calls,2);
});