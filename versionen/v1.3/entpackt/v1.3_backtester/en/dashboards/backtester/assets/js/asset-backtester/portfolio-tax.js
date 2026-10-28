import { assetLeverage,rebalanceDue,sleeveReturnsFor,metrics } from './portfolio-engine.js?v=20261028-v13y';
import { TAX_DEFAULTS,TAX_PROFILES,defaultTaxProfile,firstTaxFreeDate } from './tax-model.js?v=20261028-v13y';
const EPS=1e-8;
const sum=values=>values.reduce((a,b)=>a+b,0);
export function portfolioTaxKey(settings) {
  return JSON.stringify(Object.keys(TAX_DEFAULTS).map(key=>key==='taxProfiles'?Object.entries(settings.taxProfiles||{}).sort(([a],[b])=>a.localeCompare(b)):settings[key]??TAX_DEFAULTS[key]));
}
export function positionTaxProfile(slug,config,settings) {
  if(settings.taxProfiles?.[slug])return settings.taxProfiles[slug];
  const profile=defaultTaxProfile(slug);
  return assetLeverage(config,slug)>1 && ['private1','private10','own_home'].includes(profile)?'capital':profile;
}
export function emptyTaxYear(year,capitalLoss=0,privateLoss=0) {
  return {year,capitalLoss,privateLoss,capitalNet:0,privateNet:0,rawGain:0,exemptGain:0,paid:0};
}
export function annualPortfolioTax(book,settings) {
  const capitalLossUsed=Math.min(Math.max(0,book.capitalNet),book.capitalLoss);
  const capitalAfterLoss=Math.max(0,book.capitalNet-capitalLossUsed);
  const allowanceUsed=Math.min(capitalAfterLoss,settings.capitalAllowance??1000);
  const capitalTaxable=Math.max(0,capitalAfterLoss-allowanceUsed),church=(settings.churchTaxPct||0)/100;
  const capitalIncomeTax=capitalTaxable/(4+church),capitalSoli=capitalIncomeTax*.055,capitalChurch=capitalIncomeTax*church;
  const privateLossUsed=Math.min(Math.max(0,book.privateNet),book.privateLoss);
  const privateAfterLoss=Math.max(0,book.privateNet-privateLossUsed);
  const privateTaxable=privateAfterLoss+(settings.otherPrivateGains||0)<1000?0:privateAfterLoss;
  const privateIncomeTax=privateTaxable*(settings.personalIncomeTaxPct??42)/100,privateSoli=privateIncomeTax*(settings.privateSoliPct||0)/100,privateChurch=privateIncomeTax*church;
  return {capitalLossUsed,privateLossUsed,lossUsed:capitalLossUsed+privateLossUsed,allowanceUsed,capitalTaxable,privateTaxable,taxableGain:capitalTaxable+privateTaxable,incomeTax:capitalIncomeTax+privateIncomeTax,solidarity:capitalSoli+privateSoli,churchTax:capitalChurch+privateChurch,taxAmount:capitalIncomeTax+capitalSoli+capitalChurch+privateIncomeTax+privateSoli+privateChurch};
}
export function nextTaxYear(book,year) {
  return emptyTaxYear(year,Math.max(0,book.capitalLoss-book.capitalNet),Math.max(0,book.privateLoss-book.privateNet));
}
export function lotSale(lots,units,price,date,profile,execute=false) {
  let remaining=units,costBasis=0,gain=0,exemptGain=0,capitalGain=0,privateGain=0;
  const used=[];
  for(const lot of lots){
    if(remaining<=0)break;const quantity=Math.min(lot.units,remaining);if(quantity<=0)continue;
    const cost=quantity*lot.unitCost,profit=quantity*price-cost;
    const freeDate=profile==='private1'||profile==='private10'?firstTaxFreeDate(lot.date,profile==='private1'?1:10):null;
    const exempt=profile==='own_home'||(freeDate&&date>=freeDate);
    gain+=profit;costBasis+=cost;
    if(exempt)exemptGain+=profit;
    else if(freeDate)privateGain+=profit;
    else{const exemptPart=profit*(TAX_PROFILES[profile]||0)/100;exemptGain+=exemptPart;capitalGain+=profit-exemptPart;}
    used.push({date:lot.date,units:quantity,costBasis:cost,gain:profit,firstTaxFreeDate:freeDate,exempt});
    if(execute)lot.units-=quantity;remaining-=quantity;
  }
  if(remaining>Math.max(Number.MIN_VALUE,units*Number.EPSILON*16))throw Error('FIFO sale exceeds the available position.');
  if(execute)while(lots.length&&lots[0].units<=0)lots.shift();
  return {units:units-remaining,saleProceeds:(units-remaining)*price,costBasis,gain,exemptGain,capitalGain,privateGain,lots:used};
}
function addSale(book,sale){book.capitalNet+=sale.capitalGain;book.privateNet+=sale.privateGain;book.rawGain+=sale.gain;book.exemptGain+=sale.exemptGain;}
function combinedSales(sales) {
  const grouped=new Map();
  for(const sale of sales){
    if(!grouped.has(sale.slug))grouped.set(sale.slug,{slug:sale.slug,profile:sale.profile,units:0,saleProceeds:0,costBasis:0,gain:0,exemptGain:0,capitalGain:0,privateGain:0,lots:[]});
    const group=grouped.get(sale.slug);for(const key of ['units','saleProceeds','costBasis','gain','exemptGain','capitalGain','privateGain'])group[key]+=sale[key];
    for(const lot of sale.lots){const found=group.lots.find(item=>item.date===lot.date&&item.exempt===lot.exempt);if(found){found.units+=lot.units;found.costBasis+=lot.costBasis;found.gain+=lot.gain;}else group.lots.push({...lot});}
  }
  return [...grouped.values()];
}
export function simulatePortfolioTaxes(market,config,settings,grossResult) {
  if(!settings.taxesEnabled)return grossResult;
  const valuation=market.calendar||market,slugs=Object.keys(config.weights),targets=slugs.map(slug=>config.weights[slug]/100);
  const profiles=slugs.map(slug=>positionTaxProfile(slug,config,settings));
  const prices=slugs.map(()=>1),units=targets.map(weight=>10000*weight),lots=units.map(quantity=>quantity>0?[{units:quantity,unitCost:1,date:valuation.dates[0]}]:[]);
  let cash=0,book=emptyTaxYear(valuation.dates[0].slice(0,4),settings.capitalLoss||0,settings.privateLoss||0),pastPaid=0,peak=10000;
  const rebalances=[],points=[],rawByDate=new Map(grossResult.points.map(point=>[point.date,point]));
  const sell=(index,value,date)=>{
    const quantity=Math.min(units[index],value/prices[index]);
    const sale=lotSale(lots[index],quantity,prices[index],date,profiles[index],true);units[index]-=quantity;cash+=sale.saleProceeds;addSale(book,sale);return {slug:slugs[index],profile:profiles[index],...sale};
  };
  for(let day=0;day<valuation.dates.length;day++){
    const date=valuation.dates[day],year=date.slice(0,4);
    if(year!==book.year){pastPaid+=book.paid;book=nextTaxYear(book,year);}
    if(day>0){const returns=sleeveReturnsFor(valuation,day-1,slugs,config);for(let i=0;i<slugs.length;i++)prices[i]*=1+returns[i];}
    let values=units.map((quantity,i)=>quantity*prices[i]),nav=sum(values)+cash;
    if(!(nav>0)||!Number.isFinite(nav))throw Error('The portfolio cannot fund its tax liability.');
    const actual=values.map(value=>value/nav);
    if(day>0&&day<valuation.dates.length-1&&rebalanceDue(date,valuation.dates[day-1],config,actual,targets)){
      const requested=sum(actual.map((weight,i)=>Math.abs(weight-targets[i])*assetLeverage(config,slugs[i])))/2;
      const cap=config.rules?.turnover?.value/100,fraction=Number.isFinite(cap)&&requested>cap?cap/requested:1;
      const executed=actual.map((weight,i)=>weight+(targets[i]-weight)*fraction),grossValue=nav,sales=[];
      let pending=0,settled=false;
      for(let round=0;round<100;round++){
        const desiredValue=grossValue-pending;if(!(desiredValue>0))throw Error('The portfolio cannot fund its tax liability.');
        let sold=0;
        for(let i=0;i<slugs.length;i++){
          const value=units[i]*prices[i],excess=value-executed[i]*desiredValue;
          if(excess>EPS){const sale=sell(i,excess,date);sales.push(sale);sold+=sale.saleProceeds;}
        }
        const next=annualPortfolioTax(book,settings).taxAmount-book.paid;
        if(Math.abs(next-pending)<=EPS&&cash+EPS>=next){pending=next;settled=true;break;}
        pending=next;
        if(sold<=EPS&&cash+EPS>=pending){settled=true;break;}
      }
      if(!settled)throw Error('Tax-funded rebalancing did not converge.');
      cash-=pending;book.paid+=pending;const netValue=grossValue-pending,purchases=[];
      const deficits=units.map((quantity,i)=>Math.max(0,executed[i]*netValue-quantity*prices[i]));
      const needed=sum(deficits),available=Math.max(0,cash);
      for(let i=0;i<slugs.length;i++)if(deficits[i]>EPS){
        const amount=needed>available?deficits[i]*available/needed:deficits[i],quantity=amount/prices[i];
        lots[i].push({units:quantity,unitCost:prices[i],date});units[i]+=quantity;cash-=amount;purchases.push({slug:slugs[i],value:amount,units:quantity});
      }
      if(Math.abs(cash)<EPS)cash=0;
      const soldValue=sum(sales.map(sale=>sale.saleProceeds));
      if(sales.length||purchases.length)rebalances.push({date,turnoverPct:requested*fraction*100,salesValue:soldValue,taxPaid:pending,grossValue,netValue,sales:combinedSales(sales),purchases,partial:fraction<1,taxFundingSales:soldValue>grossValue*requested*fraction+1e-5});
      values=units.map((quantity,i)=>quantity*prices[i]);nav=sum(values)+cash;
    }
    const liquidationBook={...book},positions=slugs.map((slug,i)=>{
      const sale=lotSale(lots[i],units[i],prices[i],date,profiles[i]);addSale(liquidationBook,sale);
      return {slug,profile:profiles[i],...sale};
    }).filter(position=>position.saleProceeds>EPS);
    const liability=annualPortfolioTax(liquidationBook,settings),additionalTax=liability.taxAmount-book.paid;
    const net=nav-additionalTax,totalTax=pastPaid+liability.taxAmount;
    peak=Math.max(peak,net);
    const original=rawByDate.get(date)||{};
    const detail={date,start:valuation.dates[0],profile:'portfolio',status:'portfolio_sale',initialAmount:10000,beforeTaxValue:nav,gain:liquidationBook.rawGain,exemptionPct:0,exemptGain:liquidationBook.exemptGain,...liability,taxAmount:additionalTax,afterTaxValue:net,year,paidInYear:book.paid,paidBeforeYear:pastPaid,paidTaxAmount:pastPaid+book.paid,totalTaxAmount:totalTax,yearTaxLiability:liability.taxAmount,capitalLossAvailable:book.capitalLoss,privateLossAvailable:book.privateLoss,positions,grossCounterfactualValue:original.portfolioValue,taxDrag:(original.portfolioValue??nav)-net};
    points.push({...original,date,portfolioValue:net,returnPct:(net/10000-1)*100,drawdownPct:(net/peak-1)*100,accountValue:nav,beforeTaxPortfolioValue:nav,taxAmount:totalTax,taxStatus:'portfolio_sale',taxDetail:detail,weights:Object.fromEntries(slugs.map((slug,i)=>[slug,nav>0?values[i]/nav*100:0]))});
  }
  const index=new Map(points.map((point,i)=>[point.date,i])),riskIndices=market.dates.map(date=>index.get(date)).filter(i=>i!==undefined);
  const riskReturns=riskIndices.slice(1).map((current,i)=>points[current].portfolioValue/points[riskIndices[i]].portfolioValue-1);
  const riskDates=riskIndices.map(i=>points[i].date),last=points.at(-1);
  return {...grossResult,points,returns:riskReturns,riskDates,rebalances,taxAdjustment:{applied:true,method:'fifo_rebalancing_and_independent_liquidation_v1'},taxRebalances:rebalances,metrics:{...grossResult.metrics,...metrics(points,riskReturns,market.annualization,riskDates),lastRawObservationDate:grossResult.metrics.lastRawObservationDate,taxAdjustmentApplied:true,taxAmount:last.taxAmount,paidTaxAmount:last.taxDetail.paidTaxAmount,liquidationTaxAmount:last.taxDetail.taxAmount,taxDrag:last.taxDetail.taxDrag,beforeTaxFinalValue:last.accountValue}};
}
