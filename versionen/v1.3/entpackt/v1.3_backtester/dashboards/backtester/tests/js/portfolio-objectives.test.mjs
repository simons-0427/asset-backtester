import test from 'node:test';
import assert from 'node:assert/strict';
import {defaults,optimize,evaluate,objectiveValue,optimizationConfig,validateConfig,cleanConfig,OBJECTIVES} from '../../assets/js/asset-backtester/portfolio-engine.js';
import {PortfolioStore} from '../../assets/js/asset-backtester/portfolio-store.js';
function fixture(count=72){
  const dates=Array.from({length:count+1},(_,i)=>new Date(Date.UTC(2018,i+1,0)).toISOString().slice(0,10));
  return {dates,annualization:12,frequency:'monthly',dataEnd:dates.at(-1),sourceDates:{},returns:{
    msci_world:Array.from({length:count},(_,i)=>[0.1,-0.05,0.08,-0.03,0.07,-0.02][i%6]),
    global_ig_bonds:Array.from({length:count},(_,i)=>[0.007,-0.001,0.006,0.001,0.004,0.002][i%6]),
    euro_money_market:Array.from({length:count},(_,i)=>0.001+Math.sin(i)*0.0004),
  }};
}
const off={alternatives:false,position:false,volatility:false};
const config=(objective)=>defaults({objective,start:'2018-01-01',end:'2024-01-01',enabledLimits:off,weights:{msci_world:0,global_ig_bonds:0,euro_money_market:0}});
test('return and Sharpe optimisation select measurably different allocations',()=>{
  const market=fixture();const growth=optimize(market,config('cagr'),220);const sharpe=optimize(market,config('sharpe'),220);
  assert.ok(growth.result.metrics.cagrPct > sharpe.result.metrics.cagrPct);
  assert.ok(sharpe.result.metrics.sharpeRatio > growth.result.metrics.sharpeRatio);
  assert.equal(growth.config.weights.msci_world,100);
  assert.equal(sharpe.optimization.objective,'sharpe');
  assert.equal(sharpe.optimization.value,sharpe.result.metrics.sharpeRatio);
});
test('all six objectives are valid, deterministic and independently feasible',()=>{
  const market=fixture();
  for(const objective of Object.keys(OBJECTIVES)){
    const a=optimize(market,config(objective),120);const b=optimize(market,config(objective),120);
    assert.deepEqual(a.config.weights,b.config.weights);
    assert.equal(evaluate(market,a.config).valid,true);
    assert.equal(a.optimization.value,objectiveValue(a.result,objective));
    assert.ok(a.optimization.candidates>=a.optimization.feasible);
    assert.ok(a.optimization.feasible>0);
  }
});
test('lowest volatility includes the exact low-risk vertex, and drawdown uses absolute loss',()=>{
  const market=fixture();const low=optimize(market,config('volatility'),80);
  const baseline=evaluate(market,{...config('volatility'),weights:{euro_money_market:100}});
  assert.ok(low.result.metrics.annualizedVolatilityPct <= baseline.metrics.annualizedVolatilityPct+1e-10);
  assert.equal(objectiveValue({metrics:{maxDrawdownPct:-27}},'drawdown'),27);
});
test('independent asset universe ignores initial weights and never uses excluded assets',()=>{
  const c={...config('cagr'),weights:{global_ig_bonds:100},optimizationAssets:['msci_world','euro_money_market']};
  const found=optimize(fixture(),c,60);
  assert.equal(found.config.weights.global_ig_bonds,undefined);
  assert.equal(found.config.weights.msci_world,100);
  assert.deepEqual(found.config.optimizationAssets,c.optimizationAssets);
  assert.deepEqual(optimizationConfig(c).weights,{msci_world:0,euro_money_market:0});
});
test('undefined ratios and insufficient tail history cannot silently turn into another objective',()=>{
  const flat={...fixture(),returns:{msci_world:Array(72).fill(0),global_ig_bonds:Array(72).fill(0),euro_money_market:Array(72).fill(0)}};
  assert.throws(()=>optimize(flat,config('sharpe'),20),/nicht berechenbar/);
  assert.throws(()=>optimize(fixture(24),config('shortfall'),20),/nicht berechenbar/);
  assert.equal(objectiveValue({metrics:{sharpeRatio:null}},'sharpe'),null);
});
test('every objective continues to enforce enabled risk and optional rules',()=>{
  const market=fixture();
  for(const objective of Object.keys(OBJECTIVES)){
    const c={...config(objective),enabledLimits:{...off,volatility:true},limits:{alternatives:30,position:10,volatility:50},rules:{drawdown:{mode:'absolute',value:1}}};
    const found=optimize(market,c,100);
    assert.equal(evaluate(market,found.config).valid,true);
    const check=found.result.checks.find(x=>x.key==='volatility');assert.ok(check.value<=check.limit+1e-6);
    assert.ok(found.result.checks.find(x=>x.key==='drawdown').value<=1+1e-6);
  }
});
test('objective, full universe and settings for unused candidates survive storage',()=>{
  const c=defaults({objective:'sharpe',optimizationAssets:['msci_world','euro_money_market'],assetSettings:{euro_money_market:{leverage:2,terPct:0.2}}});
  const memory=new Map();const store=new PortfolioStore({getItem:k=>memory.get(k)??null,setItem:(k,v)=>memory.set(k,v)});
  store.save([],c);const loaded=store.load()[0];
  assert.equal(loaded.objective,'sharpe');assert.deepEqual(loaded.optimizationAssets,c.optimizationAssets);
  assert.equal(loaded.assetSettings.euro_money_market.leverage,2);
  assert.equal(cleanConfig(defaults()).objective,'cagr');
  assert.ok(validateConfig({...c,objective:'unknown'}).length);
  assert.ok(validateConfig({...c,optimizationAssets:['unknown']}).length);
  assert.throws(()=>optimize(fixture(),{...c,optimizationAssets:[]},10),/auswählen/);
});
test('English and German objective optimisers agree numerically',async()=>{
  const english=await import('../../../../en/dashboards/backtester/assets/js/asset-backtester/portfolio-engine.js');
  const c=config('sharpe');const a=optimize(fixture(),c,90);const b=english.optimize(fixture(),c,90);
  assert.deepEqual(a.config.weights,b.config.weights);assert.deepEqual(a.optimization,b.optimization);
});
