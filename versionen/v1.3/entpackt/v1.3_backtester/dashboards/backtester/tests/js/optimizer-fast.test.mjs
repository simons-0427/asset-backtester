import test from 'node:test';
import assert from 'node:assert/strict';
import {defaults,standardPortfolios,cleanConfig,createOptimizationEvaluator,evaluate,optimize,REFERENCE} from '../../assets/js/asset-backtester/portfolio-engine.js';
const off={alternatives:false,position:false,volatility:false};
const near=(a,b,label)=>{if(a==null || b==null || !Number.isFinite(a) || !Number.isFinite(b))assert.equal(a,b,label);else assert.ok(Math.abs(a-b)<=1e-7*Math.max(1,Math.abs(b)),`${label}: ${a} != ${b}`);};
function fixture(calendar=true,frequency='monthly'){
 const slugs=['msci_world','global_ig_bonds','gold','bitcoin','euro_money_market'];
 const dates=Array.from({length:calendar?810:73},(_,i)=>new Date(calendar?Date.UTC(2021,0,1+i):Date.UTC(2018,i+1,0)).toISOString().slice(0,10));
 const returns=Object.fromEntries(slugs.map((s,k)=>[s,dates.slice(1).map((_,i)=>[.0005,.0001,.0003,.001,.00006][k]+Math.sin(i*(k+1)*.63)*[.012,.002,.01,.04,.00001][k])]));
 const prices=Object.fromEntries(slugs.map(s=>{let v=100;return [s,[v,...returns[s].map(r=>(v*=1+r))]];}));
 const valuation={dates,returns,prices,sourceDates:Object.fromEntries(slugs.map(s=>[s,dates.at(-1)])),observationDates:Object.fromEntries(slugs.map(s=>[s,dates]))};
 const riskDates=calendar?dates.filter((date,i)=>frequency==='daily'?i%7!==0:date.slice(8)===(frequency==='quarterly'?'28':'28')&&(frequency!=='quarterly'||Number(date.slice(5,7))%3===0)):dates;
 const market={...valuation,slugs,dates:riskDates,frequency,annualization:frequency==='daily'?252:frequency==='quarterly'?4:12,dataEnd:riskDates.at(-1)};
 if(calendar)market.calendar=valuation;
 return market;
}
test('new drafts and templates use an absolute 25 percent drawdown instead of volatility',async()=>{
 for(const c of [defaults(),...standardPortfolios()]){assert.equal(c.enabledLimits.volatility,false);assert.deepEqual(c.rules.drawdown,{mode:'absolute',value:25});assert.equal(c.limits.alternatives,30);assert.equal(c.limits.position,10);}
 const en=await import('../../../../en/dashboards/backtester/assets/js/asset-backtester/portfolio-engine.js');assert.deepEqual(en.defaults().rules,defaults().rules);assert.equal(en.defaults().enabledLimits.volatility,false);
 const legacy=defaults({enabledLimits:{alternatives:true,position:true,volatility:true},rules:{},volatilityLimitPct:9});assert.deepEqual(cleanConfig(legacy).rules,{});assert.equal(cleanConfig(legacy).enabledLimits.volatility,true);
 delete legacy.enabledLimits;assert.equal(cleanConfig(legacy).enabledLimits.volatility,true);
 const market={dates:['2024-01-01','2024-12-31'],annualization:12,returns:{msci_world:[-.251],global_ig_bonds:[0]}};
 assert.equal(evaluate(market,defaults({weights:{msci_world:100}})).valid,false);
 market.returns.msci_world=[-.25];assert.equal(evaluate(market,defaults({weights:{msci_world:100}})).valid,true);
});
test('numeric candidate scoring agrees with full simulation across risk grids, costs and every rebalancing mode',()=>{
 for(const calendar of [false,true])for(const frequency of ['daily','monthly','quarterly'])for(const rebalancing of ['none','monthly','quarterly','yearly','threshold']){
   const market=fixture(calendar,frequency),c=defaults({weights:{msci_world:30,global_ig_bonds:30,gold:10,bitcoin:10,euro_money_market:20},enabledLimits:off,rebalancing,terPct:.2,financingPct:3,threshold:2,assetSettings:{msci_world:{leverage:1.5,terPct:.3},gold:{leverage:1.2,terPct:.1}},rules:{drawdown:{mode:'absolute',value:40},shortfall:{mode:'reference',value:100},contribution:{mode:'absolute',value:100},tracking:{mode:'absolute',value:50},turnover:{mode:'absolute',value:3}}});
   const score=createOptimizationEvaluator(market,c);
   for(let n=0;n<4;n++){
     const weights=n===0?c.weights:Object.fromEntries(Object.keys(c.weights).map((s,i)=>[s,10+((n+i)%3)*10]));const sum=Object.values(weights).reduce((a,b)=>a+b,0);for(const s of Object.keys(weights))weights[s]*=100/sum;
     const full=evaluate(market,{...c,weights}),fast=score(weights),prefix=`${calendar}/${frequency}/${rebalancing}`;
     for(const key of Object.keys(full.metrics))if(typeof full.metrics[key]==='number'||full.metrics[key]===null)near(fast.metrics[key],full.metrics[key],prefix+'/'+key);
     assert.equal(fast.valid,full.valid,prefix);assert.equal(fast.checks.length,full.checks.length);
     for(let i=0;i<full.checks.length;i++){near(fast.checks[i].value,full.checks[i].value,prefix+'/'+full.checks[i].key);near(fast.checks[i].limit,full.checks[i].limit,'limit');assert.equal(fast.checks[i].passed,full.checks[i].passed,prefix);}
   }
 }
});
test('a total loss invalidates only candidates holding the affected leveraged asset',()=>{
 const market=fixture(false),c=defaults({enabledLimits:off,weights:{msci_world:50,global_ig_bonds:40,bitcoin:10},assetSettings:{bitcoin:{leverage:3,terPct:0}},rules:{}});market.returns.bitcoin[3]=-.5;
 const score=createOptimizationEvaluator(market,c);assert.throws(()=>score(c.weights),/Totalverlust/);assert.equal(score({...REFERENCE}).valid,true);
});
test('deduplication retains the candidate budget and the winner passes an independent full evaluation',()=>{
 const market=fixture(false),c=defaults({enabledLimits:off,rules:{},optimizationAssets:['msci_world','global_ig_bonds'],weights:{msci_world:60,global_ig_bonds:40}});
 const found=optimize(market,c,80);assert.ok(found.optimization.duplicatesSkipped>0);assert.equal(found.optimization.evaluated+found.optimization.duplicatesSkipped,found.optimization.candidates);assert.deepEqual(found.result,evaluate(market,found.config));
});
