import test from 'node:test';
import assert from 'node:assert/strict';
import {simulate,defaults} from '../../assets/js/asset-backtester/portfolio-engine.js';
import {TAX_DEFAULTS} from '../../assets/js/asset-backtester/tax-model.js';
import {annualPortfolioTax,emptyTaxYear,nextTaxYear,lotSale,simulatePortfolioTaxes,positionTaxProfile,portfolioTaxKey} from '../../assets/js/asset-backtester/portfolio-tax.js';
const near=(a,b,tol=1e-6)=>assert.ok(Math.abs(a-b)<tol,`${a} != ${b}`);
const settings=(extra={})=>({...TAX_DEFAULTS,taxesEnabled:true,taxProfiles:{},...extra});
function scenario(dates,prices,options={}){
 const slugs=Object.keys(prices),weights=Object.fromEntries(slugs.map(slug=>[slug,100/slugs.length]));
 const market={dates,prices,slugs,returns:Object.fromEntries(slugs.map(slug=>[slug,prices[slug].slice(1).map((value,i)=>value/prices[slug][i]-1)])),frequency:'daily',annualization:252,dataEnd:dates.at(-1)};
 const config={...defaults(),...options,amount:10000,weights:options.weights||weights,start:dates[0],end:dates.at(-1),enabledLimits:{alternatives:false,position:false,volatility:false}};
 const gross=simulate(market,config.weights,config);
 return {market,config,gross,run:extra=>simulatePortfolioTaxes(market,config,settings(extra),gross)};
}
test('one portfolio shares one allowance across all equity-fund positions',()=>{
 const s=scenario(['2025-01-01','2025-12-31'],{sp500:[1,2],msci_world:[1,2]});const result=s.run();near(result.metrics.taxAmount,1582.5);near(result.metrics.finalValue,18417.5);near(result.points.at(-1).taxDetail.allowanceUsed,1000);
});
test('capital gains and losses net within their pool, with fund losses partially exempt too',()=>{
 const s=scenario(['2025-01-01','2025-12-31'],{sp500:[1,2],global_ig_bonds:[1,.5]});const result=s.run();near(result.metrics.taxAmount,0);near(result.metrics.finalValue,12500);
 const mixed=scenario(['2025-01-01','2025-12-31'],{sp500:[1,2],msci_world:[1,.5]}).run({capitalAllowance:0});near(mixed.metrics.taxAmount,1750*.26375);
});
test('private losses cannot erase taxable capital gains',()=>{
 const s=scenario(['2025-01-01','2025-06-30'],{sp500:[1,2],bitcoin:[1,.5]});near(s.run().metrics.taxAmount,659.375);
});
test('private-sale threshold is tested on the combined portfolio private-sale gain',()=>{
 const s=scenario(['2025-01-01','2025-06-30'],{bitcoin:[1,1.12],gold:[1,1.12]});near(s.run().metrics.taxAmount,504);
 const free=scenario(['2025-01-01','2026-01-02'],{bitcoin:[1,1.12],gold:[1,1.12]});near(free.run().metrics.taxAmount,0);
});
test('FIFO sale separates old exempt lots from recently purchased taxable lots',()=>{
 const lots=[{date:'2024-01-01',units:2,unitCost:100},{date:'2025-06-01',units:3,unitCost:200}];
 const preview=lotSale(lots,3,400,'2025-12-01','private1');near(preview.exemptGain,600);near(preview.privateGain,200);near(preview.costBasis,400);assert.equal(lots[0].units,2);
 lotSale(lots,3,400,'2025-12-01','private1',true);assert.equal(lots.length,1);near(lots[0].units,2);assert.equal(lots[0].date,'2025-06-01');
});
test('annual book resets allowance once per year and carries separate unused losses forward',()=>{
 const book={...emptyTaxYear('2025',1000,500),capitalNet:-250,privateNet:200};
 const next=nextTaxYear(book,'2026');near(next.capitalLoss,1250);near(next.privateLoss,300);near(next.paid,0);near(next.capitalNet,0);
 const liability=annualPortfolioTax({...next,capitalNet:5000},settings());near(liability.allowanceUsed,1000);near(liability.taxAmount,2750*.26375);
});
test('a current-year loss refund cannot exceed previously paid tax, and pools remain separate',()=>{
 const book={...emptyTaxYear('2025'),capitalNet:4000};const first=annualPortfolioTax(book,settings());book.paid=first.taxAmount;
 const second=annualPortfolioTax({...book,capitalNet:500},settings());near(second.taxAmount-book.paid,-791.25);
 const privateOnly=annualPortfolioTax({...book,privateNet:-10000},settings());near(privateOnly.taxAmount,first.taxAmount);
});
test('tax-funded rebalancing converges to target weights and deducts tax from reinvestable capital',()=>{
 const s=scenario(['2025-01-01','2025-02-01','2025-02-02'],{sp500:[1,2,2],global_ig_bonds:[1,1,1]},{rebalancing:'monthly'});const result=s.run({capitalAllowance:0});
 const expected=230.78125/(1-.04615625);near(result.taxRebalances[0].taxPaid,expected);near(result.taxRebalances[0].netValue,15000-expected);
 near(result.points[1].weights.sp500,50);near(result.points[1].weights.global_ig_bonds,50);near(result.metrics.finalValue,14076.875);near(result.metrics.taxAmount,923.125);
});
test('hypothetical liquidation is never repeated as an actual daily withdrawal',()=>{
 const s=scenario(['2025-01-01','2025-02-01','2025-02-02','2025-02-03'],{sp500:[1,2,2,2],global_ig_bonds:[1,1,1,1]},{rebalancing:'monthly'});const result=s.run({capitalAllowance:0});
 near(result.points[1].portfolioValue,result.points[2].portfolioValue);near(result.points[2].portfolioValue,result.points[3].portfolioValue);assert.equal(result.taxRebalances.length,1);near(result.points[2].taxDetail.paidTaxAmount,result.points[3].taxDetail.paidTaxAmount);
});
test('a Bitcoin purchase during rebalancing gets its own holding period',()=>{
 const s=scenario(['2024-01-01','2025-02-01','2025-02-02'],{bitcoin:[1,1,2],global_ig_bonds:[1,2,2]},{rebalancing:'monthly'});const result=s.run({capitalAllowance:2000});
 near(result.taxRebalances[0].taxPaid,0);near(result.metrics.taxAmount,1841.25);near(result.metrics.finalValue,20658.75);
 const btc=result.points.at(-1).taxDetail.positions.find(p=>p.slug==='bitcoin');near(btc.exemptGain,5000);near(btc.privateGain,2500);assert.equal(btc.lots.length,2);
});
test('tax payments have an opportunity cost on later growth, without taxing contributions',()=>{
 const s=scenario(['2025-01-01','2025-02-01','2025-02-02'],{sp500:[1,2,4],global_ig_bonds:[1,1,1]},{rebalancing:'monthly'});const result=s.run({capitalAllowance:0});
 assert.ok(result.metrics.paidTaxAmount>0);assert.ok(result.metrics.taxDrag>result.metrics.taxAmount);near(result.points[0].portfolioValue,10000);near(result.points[0].taxAmount,0);
});
test('tax-off leaves the gross result untouched and tax-free rebalancing reproduces the original path',()=>{
 const s=scenario(['2025-01-01','2025-02-01','2025-03-01','2025-03-02'],{sp500:[1,1.01,.99,1.01],global_ig_bonds:[1,1.005,1.01,1.005]},{rebalancing:'monthly'});const copy=structuredClone(s.gross);
 assert.equal(simulatePortfolioTaxes(s.market,s.config,TAX_DEFAULTS,s.gross),s.gross);const result=s.run();result.points.forEach((p,i)=>near(p.portfolioValue,s.gross.points[i].portfolioValue));assert.deepEqual(s.gross,copy);
});
test('leveraged private-asset sleeves default to capital instruments, with explicit overrides available',()=>{
 const config={assetSettings:{bitcoin:{leverage:2}}};assert.equal(positionTaxProfile('bitcoin',config,settings()),'capital');assert.equal(positionTaxProfile('bitcoin',config,settings({taxProfiles:{bitcoin:'private1'}})),'private1');
});
test('portfolio tax cache keys depend only on model inputs and are stable across profile order',()=>{
 assert.equal(portfolioTaxKey(settings({taxProfiles:{bitcoin:'capital',gold:'private1'}})),portfolioTaxKey(settings({taxProfiles:{gold:'private1',bitcoin:'capital'}})));assert.notEqual(portfolioTaxKey(settings()),portfolioTaxKey(settings({capitalAllowance:0})));
});
test('German and English portfolio tax models agree numerically',async()=>{
 const english=await import('../../../../en/dashboards/backtester/assets/js/asset-backtester/portfolio-tax.js');const s=scenario(['2025-01-01','2025-02-01','2025-02-02'],{sp500:[1,2,2],global_ig_bonds:[1,1,1]},{rebalancing:'monthly'});near(english.simulatePortfolioTaxes(s.market,s.config,settings({capitalAllowance:0}),s.gross).metrics.finalValue,s.run({capitalAllowance:0}).metrics.finalValue);
});
test('small unit quantities retain their full cash value and taxable gain',()=>{
 const sale=lotSale([{date:'2025-01-01',units:1e-9,unitCost:1e12}],1e-9,2e12,'2025-06-01','private1');near(sale.saleProceeds,2000);near(sale.costBasis,1000);near(sale.privateGain,1000);
});
test('portfolio comparison and single charts use the same net path and preserve tax details',async()=>{
 const {buildPortfolioComparison}=await import('../../assets/js/asset-backtester/portfolio-comparison.js');
 const {portfolioSingleSeries}=await import('../../assets/js/asset-backtester/portfolio-singles.js');
 const s=scenario(['2025-01-01','2025-02-01','2025-02-02'],{sp500:[1,2,2],global_ig_bonds:[1,1,1]},{rebalancing:'monthly'}),result=s.run({capitalAllowance:0}),entry={config:{...s.config,name:'FIFO'},result};
 const comparison=buildPortfolioComparison([['test',entry]],{})[0],single=portfolioSingleSeries({entry});
 near(comparison.points.at(-1).portfolioValue,single.points.at(-1).portfolioValue);near(comparison.metrics.finalValue,result.metrics.finalValue);near(comparison.points.at(-1).taxDetail.totalTaxAmount,result.metrics.taxAmount);assert.equal(comparison.taxAdjustment.applied,true);assert.ok(comparison.taxRebalances.length>0);assert.equal(comparison.taxRebalances[0].sales.length,1);
});
test('multi-year paths conserve cash, paid tax and liquidation liability across different rebalancing schedules',()=>{
 let seed=20261028;const random=()=>{seed=(1664525*seed+1013904223)>>>0;return seed/4294967296;};
 const dates=Array.from({length:800},(_,i)=>new Date(Date.UTC(2023,0,1+i)).toISOString().slice(0,10));
 const prices=Object.fromEntries(['msci_world','global_ig_bonds','gold','bitcoin'].map(slug=>{const values=[1];for(let i=1;i<dates.length;i++)values.push(values.at(-1)*(1+(random()-.48)*(slug==='bitcoin'?.08:.025)));return [slug,values];}));
 for(const rebalancing of ['none','monthly','quarterly','threshold']){
  const s=scenario(dates,prices,{rebalancing,threshold:3,rules:{turnover:{value:15}}});const result=s.run({capitalAllowance:0,capitalLoss:100,privateLoss:50,otherPrivateGains:999,churchTaxPct:9,privateSoliPct:5.5});
  for(const point of result.points){const detail=point.taxDetail;assert.ok(Number.isFinite(point.portfolioValue)&&point.portfolioValue>0);near(point.accountValue-detail.taxAmount,point.portfolioValue);near(detail.yearTaxLiability-detail.paidInYear,detail.taxAmount);near(detail.paidTaxAmount+detail.taxAmount,point.taxAmount);assert.ok(detail.taxAmount>=-detail.paidInYear-1e-6);}
  for(const trade of result.taxRebalances)near(trade.grossValue-trade.taxPaid,trade.netValue);
 }
});
