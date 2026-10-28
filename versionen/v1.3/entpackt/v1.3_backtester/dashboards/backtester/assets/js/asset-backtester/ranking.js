export const RANK_METRICS = Object.freeze({
 totalReturnPct:{de:'Rendite',en:'Return',direction:'desc'},
 cagrPct:{de:'CAGR',en:'CAGR',direction:'desc'},
 sharpeRatio:{de:'Sharpe',en:'Sharpe',direction:'desc'},
 calmarRatio:{de:'Calmar',en:'Calmar',direction:'desc'},
 annualizedVolatilityPct:{de:'Volatilität',en:'Volatility',direction:'asc'},
 maxDrawdownPct:{de:'Max. Drawdown',en:'Max. drawdown',direction:'desc'},
 finalValue:{de:'Endwert',en:'Final value',direction:'desc'},
 absoluteReturn:{de:'Gewinn / Verlust',en:'Profit / loss',direction:'desc'},
 bestDayPct:{de:'Beste Periode',en:'Best period',direction:'desc'},
 worstDayPct:{de:'Schwächste Periode',en:'Worst period',direction:'desc'},
 positiveDaysPct:{de:'Positive Perioden',en:'Positive periods',direction:'desc'},
 observedReturnCount:{de:'Beobachtungen',en:'Observations',direction:'desc'}
});
export const initialRanking=()=>[{key:'totalReturnPct',direction:'desc'}];
export function metricNumber(value){
 if(value==null || value==='' || typeof value==='boolean')return null;
 const n=Number(value);return Number.isFinite(n)?n:null;
}
export function compareRankedMetrics(a,b,criteria=initialRanking()){
 for(const {key,direction} of criteria.slice(0,1)){
  const x=metricNumber(a?.[key]),y=metricNumber(b?.[key]);
  if(x===null && y===null)continue;
  if(x===null)return 1;if(y===null)return -1;
  const difference=x-y;
  if(Math.abs(difference)<=Number.EPSILON*16*Math.max(1,Math.abs(x),Math.abs(y)))continue;
  return difference*(direction==='asc'?1:-1);
 }
 return 0;
}
export function rankItems(items,criteria=initialRanking()){
 return items.map((item,index)=>({item,index})).sort((a,b)=>compareRankedMetrics(a.item.metrics,b.item.metrics,criteria)||a.index-b.index).map(({item})=>item);
}
export function nextRanking(criteria,key){
 if(!Object.hasOwn(RANK_METRICS,key))return criteria;
 if(criteria[0]?.key===key)return [{key,direction:criteria[0].direction==='desc'?'asc':'desc'}];
 return [{key,direction:RANK_METRICS[key].direction}];
}
function storedMetrics(row,key){
 try{return JSON.parse(row.dataset[key] || '{}');}catch{return {};}
}
export function sortMetricRows(body,criteria){
 if(!body)return;
 const rows=[...body.querySelectorAll('tr')];
 const sorted=rows.map((row,index)=>({row,index,metrics:storedMetrics(row,'rankMetrics')})).sort((a,b)=>compareRankedMetrics(a.metrics,b.metrics,criteria)||a.index-b.index);
 sorted.forEach(({row})=>body.append(row));
}
export function sortSummaryTable(body,period='1Y',direction='desc'){
 if(!body)return;
 const rows=[...body.querySelectorAll('tr')].map((row,index)=>({row,index,metrics:{totalReturnPct:storedMetrics(row,'summaryRank')[period]}}));
 rows.sort((a,b)=>compareRankedMetrics(a.metrics,b.metrics,[{key:'totalReturnPct',direction}])||a.index-b.index).forEach(({row})=>body.append(row));
}
