import test from 'node:test';
import assert from 'node:assert/strict';
import {TAX_DEFAULTS,calculateSaleTax,applySaleTaxes,firstTaxFreeDate,taxSettingsFromQuery,writeTaxQuery} from '../../assets/js/asset-backtester/tax-model.js';
const settings=(extra={})=>({...TAX_DEFAULTS,taxesEnabled:true,taxProfiles:{},...extra});
const near=(actual,expected)=>assert.ok(Math.abs(actual-expected)<1e-7,`${actual} != ${expected}`);
const sale=(slug,value,extra={},date='2025-06-30')=>calculateSaleTax(slug,'2025-01-01',date,10000,value,settings(extra));
const series=(slug='sp500')=>({slug,effectiveStartDate:'2025-01-01',effectiveEndDate:'2026-01-02',lastRawObservationDate:'2026-01-02',metrics:{annualizationPeriods:252},points:[{date:'2025-01-01',portfolioValue:10000,priceOnlyPortfolioValue:10000},{date:'2025-06-30',portfolioValue:20000,priceOnlyPortfolioValue:15000},{date:'2026-01-01',portfolioValue:20000,priceOnlyPortfolioValue:15000},{date:'2026-01-02',portfolioValue:20000,priceOnlyPortfolioValue:15000,isCarriedForward:true}]});
test('missing and malformed tax query values retain defaults, explicit zero is valid',()=>{
 const defaults=taxSettingsFromQuery(new URLSearchParams());assert.equal(defaults.personalIncomeTaxPct,42);assert.equal(defaults.capitalAllowance,1000);assert.equal(defaults.taxesEnabled,false);
 const invalid=taxSettingsFromQuery(new URLSearchParams('personalIncomeTaxPct=NaN&capitalAllowance=-1&churchTaxPct=7&taxProfile_gold=unknown'));assert.equal(invalid.capitalAllowance,1000);assert.equal(invalid.personalIncomeTaxPct,42);assert.deepEqual(invalid.taxProfiles,{});
 const zero=taxSettingsFromQuery(new URLSearchParams('taxes=1&personalIncomeTaxPct=0&capitalAllowance=0'));assert.equal(zero.personalIncomeTaxPct,0);assert.equal(zero.capitalAllowance,0);assert.equal(zero.taxesEnabled,true);
});
test('tax enable state and all options round trip through URL',()=>{
 const input=settings({churchTaxPct:9,capitalAllowance:2000,capitalLoss:1200,privateLoss:500,otherPrivateGains:100,privateSoliPct:5.5,taxProfiles:{gold:'capital'}}),query=new URLSearchParams();writeTaxQuery(query,input);assert.deepEqual(taxSettingsFromQuery(query),input);
});
test('equity fund sale applies partial exemption then one available allowance and exact tax components',()=>{
 const value=sale('sp500',20000);near(value.exemptGain,3000);near(value.allowanceUsed,1000);near(value.taxableGain,6000);near(value.incomeTax,1500);near(value.solidarity,82.5);near(value.taxAmount,1582.5);near(value.afterTaxValue,18417.5);
});
test('joint allowance and available capital losses are used in their proper order',()=>{
 const value=sale('sp500',20000,{capitalAllowance:2000,capitalLoss:1000});near(value.lossUsed,1000);near(value.allowanceUsed,2000);near(value.taxableGain,4000);near(value.taxAmount,1055);
 assert.equal(sale('sp500',11000).taxAmount,0);
});
test('church tax uses deductible capital income tax formula, not 25 percent plus naive surcharge',()=>{
 const value=sale('global_ig_bonds',20000,{capitalAllowance:0,churchTaxPct:9});near(value.incomeTax,10000/4.09);near(value.churchTax,value.incomeTax*.09);near(value.solidarity,value.incomeTax*.055);near(value.taxAmount,value.incomeTax*1.145);
});
test('losses never create a fictitious refund and disabled taxes preserve gross values',()=>{
 near(sale('sp500',9000).afterTaxValue,9000);near(sale('sp500',9000).taxAmount,0);near(calculateSaleTax('sp500','2025-01-01','2026-01-02',10000,20000,TAX_DEFAULTS).afterTaxValue,20000);
});
test('private sale exemption is strictly below 1000 and other annual gains count',()=>{
 near(sale('bitcoin',10999).taxAmount,0);near(sale('bitcoin',11000).taxAmount,420);near(sale('bitcoin',10500,{otherPrivateGains:500}).taxAmount,210);near(sale('bitcoin',11500,{privateLoss:600}).taxAmount,0);near(sale('bitcoin',12000,{privateLoss:600}).taxAmount,588);
});
test('one-year anniversary is still taxable and exemption starts the following day',()=>{
 near(sale('bitcoin',12000,{},'2026-01-01').taxAmount,840);near(sale('bitcoin',12000,{},'2026-01-02').taxAmount,0);near(sale('gold',12000,{},'2026-01-02').taxAmount,0);
 assert.equal(firstTaxFreeDate('2024-02-29',1),'2025-03-01');assert.equal(firstTaxFreeDate('2023-02-28',1),'2024-02-29');
});
test('property exemption starts after ten years, with explicit owner-occupation exception',()=>{
 near(sale('de_real_estate',20000,{},'2035-01-01').taxAmount,4200);near(sale('de_real_estate',20000,{},'2035-01-02').taxAmount,0);near(sale('de_real_estate',20000,{taxProfiles:{de_real_estate:'own_home'}}).taxAmount,0);
});
test('product selection distinguishes direct Bitcoin and gold from capital-tax instruments',()=>{
 near(sale('bitcoin',20000,{},'2026-01-02').taxAmount,0);near(sale('bitcoin',20000,{taxProfiles:{bitcoin:'capital'}},'2026-01-02').taxAmount,2373.75);near(sale('sp500',20000,{taxProfiles:{sp500:'mixed_fund'}}).exemptGain,1500);near(sale('de_real_estate',20000,{taxProfiles:{de_real_estate:'foreign_property_fund'}}).exemptGain,8000);
});
test('private-sale personal rate and explicitly selected Soli and church assumptions change net proceeds',()=>{
 const value=sale('bitcoin',12000,{personalIncomeTaxPct:30,privateSoliPct:5.5,churchTaxPct:8});near(value.incomeTax,600);near(value.solidarity,33);near(value.churchTax,48);near(value.afterTaxValue,11319);
});
test('each day is an independent sale, without daily repeated tax withdrawals or accumulated annual allowances',()=>{
 const raw=series(),copy=structuredClone(raw),adjusted=applySaleTaxes(raw,settings());
 for(const point of adjusted.points.slice(1)){near(point.portfolioValue,18417.5);near(point.taxAmount,1582.5);}
 assert.deepEqual(raw,copy);near(adjusted.metrics.finalValue,18417.5);near(adjusted.metrics.totalReturnPct,84.175);assert.ok(adjusted.metrics.cagrPct<100);assert.equal(adjusted.points[0].portfolioValue,10000);
});
test('inflation is applied after nominal EUR taxation and price-only variants get their own gains',()=>{
 const raw=series();raw.points[1]={...raw.points[1],portfolioValue:20000/1.1,nominalBeforeTaxPortfolioValue:20000,inflationFactor:1.1,priceOnlyPortfolioValue:15000/1.1};
 const result=applySaleTaxes(raw,settings());near(result.points[1].portfolioValue,18417.5/1.1);near(result.points[1].taxDetail.gain,10000);near(result.points[1].priceOnlyTaxAmount,659.375);
});
test('holding-period change is reflected in the net path even without a new market observation',()=>{
 const adjusted=applySaleTaxes(series('bitcoin'),settings());near(adjusted.points[2].portfolioValue,15800);near(adjusted.points[3].portfolioValue,20000);assert.equal(adjusted.points[3].taxStatus,'holding_exempt');assert.ok(adjusted.metrics.observedReturnCount>=3);
});
test('German and English tax calculations agree, and tax-off restores the original series',async()=>{
 const english=await import('../../../../en/dashboards/backtester/assets/js/asset-backtester/tax-model.js');near(english.applySaleTaxes(series(),settings(),true).metrics.finalValue,applySaleTaxes(series(),settings()).metrics.finalValue);assert.equal(applySaleTaxes(series(),TAX_DEFAULTS).taxAdjustment,undefined);
});
test('both app variants request gross data so the new browser tax model cannot double-charge',async()=>{
 const {readFile}=await import('node:fs/promises');for(const locale of ['../../','../../../../en/dashboards/backtester/']){const source=await readFile(new URL(locale+'assets/js/asset-backtester/app.js',import.meta.url),'utf8');assert.ok(!source.includes('includeTaxes: state.taxesEnabled'));assert.ok(source.includes('applySaleTaxes(series,state'));assert.ok(source.includes('writeTaxQuery(params,state)'));assert.ok(source.includes('mountTaxControls('));}
});
