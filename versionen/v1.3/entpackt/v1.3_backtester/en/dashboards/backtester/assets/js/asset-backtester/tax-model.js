import { metrics } from './portfolio-engine.js?v=20261028-v13y';

export const TAX_DEFAULTS = Object.freeze({taxesEnabled:false,personalIncomeTaxPct:42,capitalAllowance:1000,churchTaxPct:0,capitalLoss:0,privateLoss:0,otherPrivateGains:0,privateSoliPct:0,taxProfiles:{}});
export const TAX_PROFILES = Object.freeze({equity_fund:30,mixed_fund:15,capital:0,property_fund:60,foreign_property_fund:80,private1:null,private10:null,own_home:null});
export const TAX_ASSETS = ['sp500','msci_world','dax','eurostoxx50','global_ig_bonds','global_ig_bonds_unhedged','euro_money_market','us_money_market','gold','bitcoin','commodities','de_real_estate','us_real_estate'];
export function defaultTaxProfile(slug) {
  if(['sp500','msci_world','dax','eurostoxx50'].includes(slug))return 'equity_fund';
  if(['gold','bitcoin'].includes(slug))return 'private1';
  if(['de_real_estate','us_real_estate'].includes(slug))return 'private10';
  return 'capital';
}
const bounded=(value,min,max,fallback)=>value!==null && value!==undefined && value!=='' && Number.isFinite(Number(value)) && Number(value)>=min && Number(value)<=max?Number(value):fallback;
export function taxSettingsFromQuery(params) {
  const settings={...TAX_DEFAULTS,taxProfiles:{},taxesEnabled:params.get('taxes')==='1'};
  for(const [key,max] of [['personalIncomeTaxPct',45],['capitalAllowance',2000],['capitalLoss',1e9],['privateLoss',1e9],['otherPrivateGains',1e9]])settings[key]=bounded(params.get(key),0,max,TAX_DEFAULTS[key]);
  for(const [key,allowed] of [['churchTaxPct',[0,8,9]],['privateSoliPct',[0,5.5]]]){const value=params.get(key);if(value!==null && allowed.includes(Number(value)))settings[key]=Number(value);}
  for(const slug of TAX_ASSETS){const value=params.get('taxProfile_'+slug);if(Object.hasOwn(TAX_PROFILES,value))settings.taxProfiles[slug]=value;}
  return settings;
}
export function writeTaxQuery(params,settings) {
  for(const key of Object.keys(TAX_DEFAULTS))if(key!=='taxProfiles' && key!=='taxesEnabled')params.set(key,String(settings[key]??TAX_DEFAULTS[key]));
  params.set('taxes',settings.taxesEnabled?'1':'0');
  for(const [slug,profile] of Object.entries(settings.taxProfiles||{}))if(TAX_ASSETS.includes(slug) && Object.hasOwn(TAX_PROFILES,profile))params.set('taxProfile_'+slug,profile);
}
export function firstTaxFreeDate(start,years) {
  const [year,month,day]=start.split('-').map(Number),target=year+years;
  const anniversary=new Date(Date.UTC(target,month-1,Math.min(day,new Date(Date.UTC(target,month,0)).getUTCDate())));
  anniversary.setUTCDate(anniversary.getUTCDate()+1);return anniversary.toISOString().slice(0,10);
}
export function calculateSaleTax(slug,start,date,initialAmount,beforeTaxValue,settings={}) {
  const profile=settings.taxProfiles?.[slug]||defaultTaxProfile(slug),gain=beforeTaxValue-initialAmount;
  const result={date,start,profile,initialAmount,beforeTaxValue,gain,exemptionPct:TAX_PROFILES[profile]??0,exemptGain:0,lossUsed:0,allowanceUsed:0,taxableGain:0,incomeTax:0,solidarity:0,churchTax:0,taxAmount:0,afterTaxValue:beforeTaxValue,status:'disabled',firstTaxFreeDate:null};
  if(!settings.taxesEnabled)return result;
  if(profile==='private1'||profile==='private10')result.firstTaxFreeDate=firstTaxFreeDate(start,profile==='private1'?1:10);
  if(gain<=0)return {...result,status:'no_gain'};
  if(profile==='own_home')return {...result,status:'own_home',exemptGain:gain};
  if(result.firstTaxFreeDate){
    if(date>=result.firstTaxFreeDate)return {...result,status:'holding_exempt',exemptGain:gain};
    result.lossUsed=Math.min(gain,settings.privateLoss||0);
    const taxable=Math.max(0,gain-result.lossUsed);
    if(taxable+(settings.otherPrivateGains||0)<1000)return {...result,status:'private_threshold',exemptGain:taxable};
    result.taxableGain=taxable;result.incomeTax=taxable*(settings.personalIncomeTaxPct??42)/100;
    result.solidarity=result.incomeTax*(settings.privateSoliPct||0)/100;
    result.churchTax=result.incomeTax*(settings.churchTaxPct||0)/100;result.status='private_tax';
  }else{
    result.exemptGain=gain*result.exemptionPct/100;
    const afterExemption=gain-result.exemptGain;result.lossUsed=Math.min(afterExemption,settings.capitalLoss||0);
    result.allowanceUsed=Math.min(afterExemption-result.lossUsed,settings.capitalAllowance??1000);
    result.taxableGain=Math.max(0,afterExemption-result.lossUsed-result.allowanceUsed);
    const church=(settings.churchTaxPct||0)/100;
    result.incomeTax=result.taxableGain/(4+church);result.solidarity=result.incomeTax*.055;result.churchTax=result.incomeTax*church;
    result.status=result.taxableGain>0?'capital_tax':'capital_allowance';
  }
  result.taxAmount=result.incomeTax+result.solidarity+result.churchTax;result.afterTaxValue=beforeTaxValue-result.taxAmount;
  return result;
}
export function taxStatusLabel(result,english=false) {
  return (english?{portfolio_sale:'Portfolio: FIFO and shared annual tax book',disabled:'Tax model off',no_gain:'No taxable gain',own_home:'Owner occupation: exemption assumed',holding_exempt:'Holding period completed',private_threshold:'Below the EUR 1,000 private-sale threshold',private_tax:'Private sale at personal tax rate',capital_tax:'Capital income tax, solidarity surcharge and optional church tax',capital_allowance:'Covered by allowance / loss offset'}:{portfolio_sale:'Portfolio: FIFO und gemeinsame Jahressteuerrechnung',disabled:'Steuermodell aus',no_gain:'Kein steuerpflichtiger Gewinn',own_home:'Eigennutzung: Ausnahme vorausgesetzt',holding_exempt:'Haltefrist abgelaufen',private_threshold:'Unter der 1.000-€-Freigrenze',private_tax:'Privatverkauf zum persönlichen Steuersatz',capital_tax:'Abgeltungsteuer, Soli und ggf. Kirchensteuer',capital_allowance:'Durch Pauschbetrag / Verlustverrechnung gedeckt'})[result.status]||result.status;
}
export function taxedPathMetrics(points,series) {
  let peak=points[0].portfolioValue;
  const path=points.map(point=>{peak=Math.max(peak,point.portfolioValue);return {...point,drawdownPct:peak>0?(point.portfolioValue/peak-1)*100:0};});
  const observed=path.filter((point,index)=>index===0||!point.isCarriedForward||point.taxStatus!==path[index-1].taxStatus);
  const returns=observed.slice(1).map((point,index)=>observed[index].portfolioValue>0?point.portfolioValue/observed[index].portfolioValue-1:0);
  const annualization=series.metrics?.annualizationPeriods||(series.nativeFrequency==='monthly'?12:series.nativeFrequency==='quarterly'?4:series.slug==='bitcoin'?365.2425:252);
  return {points:path,metrics:{...series.metrics,...metrics(path,returns,annualization,observed.map(p=>p.date)),lastRawObservationDate:series.lastRawObservationDate||series.metrics?.lastRawObservationDate}};
}
export function applySaleTaxes(series,settings,english=false) {
  if(!settings.taxesEnabled||!series.points?.length)return series;
  const start=series.effectiveStartDate||series.points[0].date,initialAmount=series.points[0].portfolioValue;
  const points=series.points.map(point=>{
    const inflation=point.inflationFactor||1;
    const gross=point.nominalBeforeTaxPortfolioValue??point.beforeTaxPortfolioValue*inflation;
    const nominal=Number.isFinite(gross)?gross:point.portfolioValue*inflation;
    const detail=calculateSaleTax(series.slug,start,point.date,initialAmount,nominal,settings);
    const priceGross=(point.priceOnlyPortfolioValue??point.portfolioValue)*inflation;
    const priceDetail=calculateSaleTax(series.slug,start,point.date,initialAmount,priceGross,settings);
    return {...point,portfolioValue:detail.afterTaxValue/inflation,nominalPortfolioValue:detail.afterTaxValue,beforeTaxPortfolioValue:nominal/inflation,nominalBeforeTaxPortfolioValue:nominal,returnPct:(detail.afterTaxValue/inflation/initialAmount-1)*100,taxAmount:detail.taxAmount,taxableGain:detail.taxableGain,taxStatus:detail.status,taxLabel:taxStatusLabel(detail,english),taxDetail:detail,priceOnlyPortfolioValue:priceDetail.afterTaxValue/inflation,priceOnlyReturnPct:(priceDetail.afterTaxValue/inflation/initialAmount-1)*100,priceOnlyTaxAmount:priceDetail.taxAmount,priceOnlyTaxLabel:taxStatusLabel(priceDetail,english),priceOnlyTaxDetail:priceDetail};
  });
  const path=taxedPathMetrics(points,series),last=path.points.at(-1);
  const pricePath=taxedPathMetrics(points.map(point=>({...point,portfolioValue:point.priceOnlyPortfolioValue,taxStatus:point.priceOnlyTaxDetail.status})),series);
  return {...series,...path,priceOnlyMetrics:pricePath.metrics,taxAdjustment:{applied:true,method:'independent_sale_date_v2',profile:last.taxDetail.profile},metrics:{...path.metrics,taxAdjustmentApplied:true,taxAmount:last.taxAmount,taxStatus:last.taxStatus,taxRule:last.taxLabel,beforeTaxFinalValue:last.beforeTaxPortfolioValue}};
}
