import test from 'node:test';
import assert from 'node:assert/strict';
import {defaults,prepareMarket,simulate,evaluate,optimize,calendarMarket,validateConfig,referenceVolatility} from '../../assets/js/asset-backtester/portfolio-engine.js';
import {buildPortfolioComparison} from '../../assets/js/asset-backtester/portfolio-comparison.js';
function rows({slow=false,dip=false}={}){
 const slugs=['msci_world','global_ig_bonds','bitcoin',...(slow?['us_real_estate']:[])];
 return slugs.map(slug=>{
  const points=[];
  for(let t=Date.parse('2018-01-01');t<=Date.parse('2021-12-31');t+=86400000){
   const date=new Date(t).toISOString().slice(0,10),day=new Date(t).getUTCDay();
   if(slug==='us_real_estate'?date.slice(-2)!=='01':slug!=='bitcoin' && (day===0||day===6))continue;
   let value=100;
   if(dip && slug==='msci_world' && date>='2020-01-15' && date<'2020-01-31')value=50;
   if(!dip){
    if(slug==='msci_world' && date>='2020-01-06')value=date>='2020-01-07'?99:110;
    if(slug==='bitcoin' && date>='2020-01-04')value=date>='2020-01-05'?150:120;
    if(slug==='us_real_estate' && date>='2020-02-01')value=130;
   }
   points.push({date,portfolioValue:value,isCarriedForward:false,lastRawObservationDate:date});
  }
  return {slug,nativeFrequency:slug==='us_real_estate'?'monthly':'daily',points};
 });
}
function config(extra={}){return defaults({start:'2018-01-01',end:'2021-12-31',weights:{msci_world:50,bitcoin:50},limits:{alternatives:100,position:100,volatility:100},enabledLimits:{volatility:false},...extra});}
test('every calendar day is valued and weekend crypto changes do not move closed stock holdings',()=>{
 const c=config({frequency:'monthly'}),market=prepareMarket(rows(),c),result=simulate(market,c.weights,c);
 assert.equal(result.points.length,1461);
 const at=date=>result.points.find(p=>p.date===date).portfolioValue;
 assert.equal(at('2020-01-03'),10000);
 assert.ok(Math.abs(at('2020-01-04')-11000)<1e-8);
 assert.ok(Math.abs(at('2020-01-05')-12500)<1e-8);
 assert.ok(Math.abs(at('2020-01-06')-13000)<1e-8);
 assert.ok(Math.abs(at('2020-01-07')-12450)<1e-8);
 assert.equal(result.returns.length,47);
 assert.equal(result.metrics.observedReturnCount,47);
 assert.equal(result.calendarReturns.length,1460);
 const shown=buildPortfolioComparison([['x',{config:c,result}]],{});
 assert.equal(shown[0].points.length,1461);
 assert.ok(shown[0].points.some(p=>p.date==='2020-02-29'));
 const stocks=simulate(market,{msci_world:100},c);
 const saturday=stocks.points.find(p=>p.date==='2020-01-04');
 assert.equal(saturday.isCarriedForward,true);
 assert.equal(saturday.lastRawObservationDate,'2020-01-03');
 assert.ok(stocks.points.every(p=>p.lastRawObservationDate<=p.date));
});
test('the valuation curve is independent of the chosen risk grid',()=>{
 const curves=['daily','monthly','quarterly'].map(frequency=>{const c=config({frequency});return simulate(prepareMarket(rows(),c),c.weights,c).points.map(p=>[p.date,p.portfolioValue]);});
 assert.deepEqual(curves[0],curves[1]);assert.deepEqual(curves[1],curves[2]);
 const daily=prepareMarket(rows(),config({frequency:'daily'}));
 assert.ok(daily.dates.includes('2020-01-04'));
});
test('slow series change on their observed date without being interpolated across the month',()=>{
 const c=config({weights:{msci_world:50,us_real_estate:50},frequency:'quarterly'}),m=prepareMarket(rows({slow:true}),c),r=simulate(m,c.weights,c);
 const p=date=>r.points.find(p=>p.date===date).portfolioValue;
 assert.equal(p('2020-01-31'),9950);
 assert.ok(Math.abs(p('2020-02-01')-11450)<1e-8);
 assert.equal(p('2020-02-02'),p('2020-02-01'));
 assert.equal(r.returns.length,15);
});
test('intramonth drawdowns remain visible even with a monthly risk grid',()=>{
 const c=config({weights:{msci_world:100},frequency:'monthly'}),r=simulate(prepareMarket(rows({dip:true}),c),c.weights,c);
 assert.equal(r.metrics.maxDrawdownPct,-50);
 assert.equal(r.metrics.annualizedVolatilityPct,0);
 assert.equal(r.points.find(p=>p.date==='2020-01-15').portfolioValue,5000);
});
test('a missing initial price never gets filled from a future quote',()=>{
 const source=rows();const gold={slug:'gold',points:[{date:'2020-01-10',portfolioValue:100,lastRawObservationDate:'2020-01-10',isCarriedForward:false},{date:'2020-01-12',portfolioValue:110,lastRawObservationDate:'2020-01-12',isCarriedForward:false}]};
 const c=config({start:'2020-01-01',end:'2020-01-12',weights:{gold:100}});
 const calendar=calendarMarket([...source,gold],c);
 assert.equal(calendar.dates[0],'2020-01-10');
 assert.equal(calendar.prices.gold[0],100);
 assert.equal(calendar.prices.gold.at(-1),110);
});
test('alternative exposure limits accept 100 percent and still enforce the chosen limit',()=>{
 const c=config({weights:{bitcoin:100}});
 assert.deepEqual(validateConfig(c),[]);
 const found=optimize(prepareMarket(rows(),c),c,12);
 assert.equal(found.config.weights.bitcoin,100);
 assert.equal(found.result.valid,true);
 assert.ok(validateConfig({...c,limits:{...c.limits,alternatives:101}}).length);
 assert.ok(validateConfig({...c,limits:{...c.limits,position:101}}).length);
 assert.ok(validateConfig({...c,limits:{...c.limits,position:90}}).length);
});
test('calendar valuation, daily drawdown and risk reference agree across DE and EN',async()=>{
 const en=await import('../../../../en/dashboards/backtester/assets/js/asset-backtester/portfolio-engine.js');
 const c=config({frequency:'monthly'}),source=rows(),deMarket=prepareMarket(source,c),enMarket=en.prepareMarket(source,c);
 assert.deepEqual(enMarket,deMarket);
 assert.equal(en.referenceVolatility(enMarket,c),referenceVolatility(deMarket,c));
 assert.deepEqual(en.evaluate(enMarket,c).points,evaluate(deMarket,c).points);
});
