import {readFile,writeFile} from 'node:fs/promises';
import {pathToFileURL,fileURLToPath} from 'node:url';
import {performance} from 'node:perf_hooks';
import assert from 'node:assert/strict';
const root=fileURLToPath(new URL('../..',import.meta.url));
const current=await import(pathToFileURL(root+'/assets/js/asset-backtester/portfolio-engine.js'));
const previous=await import('./portfolio-engine-before-v1.3.js');
const slugs=Object.keys(current.ASSETS),series=[];
for(const slug of slugs){
 const text=await readFile(root+'/var/import/'+slug+'.csv','utf8');
 const points=text.trim().split(/\r?\n/).slice(1).map(line=>{const [date,value]=line.replaceAll('"','').split(',');return {date,portfolioValue:Number(value),lastRawObservationDate:date,isCarriedForward:false};}).filter(p=>p.portfolioValue>0);
 series.push({slug,nativeFrequency:slug==='de_real_estate'?'quarterly':slug==='us_real_estate'?'monthly':'daily',points});
}
const profiles=[
 {name:'13 Anlageklassen, Buy & Hold, CAGR',pool:slugs,extra:{}},
 {name:'13 Anlageklassen, monatliches Rebalancing, Sharpe, Hebel/Kosten',pool:slugs,extra:{objective:'sharpe',rebalancing:'monthly',assetSettings:{msci_world:{leverage:1.5,terPct:.25}},terPct:.1,financingPct:3}},
 {name:'5 liquide Anlageklassen, tägliches Risikoraster, CAGR',pool:['msci_world','global_ig_bonds','sp500','gold','bitcoin'],extra:{frequency:'daily'}},
 {name:'5 Anlageklassen, Gewichtsband, Sharpe und zusätzliche Risikoregeln',pool:['msci_world','global_ig_bonds','sp500','gold','euro_money_market'],extra:{frequency:'daily',objective:'sharpe',rebalancing:'threshold',threshold:3,rules:{drawdown:{mode:'absolute',value:25},shortfall:{mode:'absolute',value:10},tracking:{mode:'absolute',value:30},contribution:{mode:'absolute',value:100},turnover:{mode:'absolute',value:10}}}},
];
const results=[];
for(const p of profiles){
 const c=current.optimizationConfig(current.defaults({start:'2016-10-28',end:'2026-10-28',optimizationAssets:p.pool,...p.extra}));
 const firstDates=p.pool.map(s=>series.find(row=>row.slug===s).points[0].date);c.start=[c.start,...firstDates].sort().at(-1);
 const market=current.prepareMarket(series,c),oldStart=performance.now(),old=previous.optimize(market,c,500),oldMs=performance.now()-oldStart;
 const newStart=performance.now(),found=current.optimize(market,c,500),newMs=performance.now()-newStart;
 assert.equal(previous.evaluate(market,found.config).valid,true);
 assert.equal(found.optimization.candidates,old.optimization.candidates);
 const difference=found.optimization.value-old.optimization.value;
 assert.ok(Math.abs(difference)<1e-7,`Different objective for ${p.name}: ${difference}`);
 const r={profile:p.name,days:market.calendar.dates.length,riskFrequency:market.frequency,candidates:found.optimization.candidates,uniqueEvaluations:found.optimization.evaluated,duplicates:found.optimization.duplicatesSkipped,oldMs:Math.round(oldMs),newMs:Math.round(newMs),speedup:Number((oldMs/newMs).toFixed(2)),oldObjective:old.optimization.value,newObjective:found.optimization.value,sameWeights:JSON.stringify(old.config.weights)===JSON.stringify(found.config.weights),rulesPassed:true};
 results.push(r);console.log(JSON.stringify(r));
 await writeFile(new URL('../../docs/SOLVER-BENCHMARK-v1.3.json',import.meta.url),JSON.stringify({runtime:process.version,source:'Actual local CSV imports without dividend model. Serial single-run comparison, identical 500-search budget. No production timing.',results},null,2));
}
