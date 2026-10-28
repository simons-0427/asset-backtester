import test from 'node:test';
import assert from 'node:assert/strict';
import {defaults,evaluate,REFERENCE} from '../../assets/js/asset-backtester/portfolio-engine.js';
import {simulatePortfolioTaxes,portfolioTaxKey} from '../../assets/js/asset-backtester/portfolio-tax.js';
import {TAX_DEFAULTS} from '../../assets/js/asset-backtester/tax-model.js';
function sample(){
 const dates=Array.from({length:400},(_,i)=>new Date(Date.UTC(2024,0,1+i)).toISOString().slice(0,10));
 const prices={msci_world:dates.map((_,i)=>1+i*.002),global_ig_bonds:dates.map((_,i)=>1+i*.0002)};
 const returns=Object.fromEntries(Object.entries(prices).map(([s,p])=>[s,p.slice(1).map((v,i)=>v/p[i]-1)]));
 return {dates,prices,returns,slugs:Object.keys(prices),frequency:'daily',annualization:252,dataEnd:dates.at(-1)};
}
test('worker restoration preserves exact gross results, diagnostics and portfolio taxes in both languages',async()=>{
 const market=sample(),config={...defaults(),weights:REFERENCE,start:market.dates[0],end:market.dataEnd,enabledLimits:{alternatives:false,position:false,volatility:false}};
 const taxSettings={...TAX_DEFAULTS,taxesEnabled:true,taxProfiles:{}};
 const gross=evaluate(market,config),net=simulatePortfolioTaxes(market,config,taxSettings,gross);
 const referenceConfig={...config,weights:REFERENCE,assetSettings:{},leverage:1,terPct:0,financingPct:0,rebalancing:'quarterly',rules:{}};
 net.reference=simulatePortfolioTaxes(market,referenceConfig,taxSettings,gross.reference);
 for(const path of ['../../assets/js/asset-backtester/portfolio-worker.js','../../../../en/dashboards/backtester/assets/js/asset-backtester/portfolio-worker.js']){
   let response;globalThis.self={postMessage:value=>{response=value;}};
   try {await import(path);self.onmessage({data:{type:'restore',market,config,taxSettings}});assert.equal(response.success,true,response.message);assert.deepEqual(response.result.result.points,gross.points);assert.deepEqual(response.result.result.metrics,gross.metrics);assert.deepEqual(response.result.result.checks.map(({label,...check})=>check),gross.checks.map(({label,...check})=>check));assert.deepEqual(response.result.taxView.result.points,net.points);assert.deepEqual(response.result.taxView.result.metrics,net.metrics);assert.deepEqual(response.result.taxView.result.reference,net.reference);assert.equal(response.result.taxKey,portfolioTaxKey(taxSettings));assert.ok(response.result.diagnostics);}
   finally{delete globalThis.self;}
 }
});
