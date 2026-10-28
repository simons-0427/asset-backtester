import test from 'node:test';
import assert from 'node:assert/strict';
import {defaults,prepareMarket} from '../../assets/js/asset-backtester/portfolio-engine.js';
function rows(lastDate='2019-12-31',native='daily',slug='gold'){
  return ['msci_world','global_ig_bonds',slug].map((s,k)=>({slug:s,nativeFrequency:s===slug?native:'daily',points:Array.from({length:24},(_,i)=>{
    let date=new Date(Date.UTC(2018,i+1,0)).toISOString().slice(0,10);
    if(s===slug && i===23)date=lastDate;
    return {date,lastRawObservationDate:date,isCarriedForward:false,portfolioValue:100+i*(k+1)};
  })}));
}
const config=defaults({start:'2018-01-01',end:'2020-01-10',frequency:'monthly',weights:{msci_world:50,global_ig_bonds:40,gold:10}});
test('a daily series ending mid-month cannot create a fictional month-end price',()=>{
  const market=prepareMarket(rows('2019-12-08'),config);
  assert.equal(market.dataEnd,'2019-11-30');
  assert.equal(market.sourceDates.gold,'2019-11-30');
  assert.equal(market.dates.length,23);
});
test('short weekend and holiday gaps retain the observed source date',()=>{
  const market=prepareMarket(rows('2019-12-27'),config);
  assert.equal(market.dataEnd,'2019-12-31');
  assert.equal(market.sourceDates.gold,'2019-12-27');
});
test('native monthly indices dated at the start of the period remain valid historical periods',()=>{
  const series=rows('2019-12-01','monthly','us_real_estate');
  series[2].points=series[2].points.map(p=>({...p,date:p.date.slice(0,8)+'01',lastRawObservationDate:p.date.slice(0,8)+'01'}));
  const market=prepareMarket(series,{...config,weights:{msci_world:50,global_ig_bonds:40,us_real_estate:10}});
  assert.equal(market.dataEnd,'2019-12-31');
  assert.equal(market.sourceDates.us_real_estate,'2019-12-01');
  assert.equal(market.hasSlowSeries,true);
});
test('English and German period coverage exclude the same incomplete month',async()=>{
  const english=await import('../../../../en/dashboards/backtester/assets/js/asset-backtester/portfolio-engine.js');
  assert.deepEqual(english.prepareMarket(rows('2019-12-08'),config),prepareMarket(rows('2019-12-08'),config));
});
