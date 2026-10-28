import test from 'node:test';
import assert from 'node:assert/strict';
import {initialRanking,nextRanking,rankItems,compareRankedMetrics,sortMetricRows,sortSummaryTable} from '../../assets/js/asset-backtester/ranking.js';
import {comparisonTooltipLines,compareComparisonTooltipItems,tooltipRankingMetrics,ChartManager} from '../../assets/js/asset-backtester/charts.js';
import {PortfolioLab} from '../../assets/js/asset-backtester/portfolio-ui.js';
test('assets and portfolios share the same return ranking without rounded display strings',()=>{
 const items=[{id:'asset-low',metrics:{totalReturnPct:2.003}},{id:'portfolio',isPortfolio:true,metrics:{totalReturnPct:2.004}},{id:'asset-high',metrics:{totalReturnPct:8}}];
 assert.deepEqual(rankItems(items).map(x=>x.id),['asset-high','portfolio','asset-low']);
 assert.equal(items[0].id,'asset-low');
});
test('the clicked metric replaces the previous metric and ties remain stable',()=>{
 let criteria=nextRanking(initialRanking(),'sharpeRatio');
 assert.deepEqual(criteria,[{key:'sharpeRatio',direction:'desc'}]);
 const items=[{id:'a',metrics:{sharpeRatio:1,totalReturnPct:10}},{id:'portfolio',metrics:{sharpeRatio:1,totalReturnPct:20}},{id:'b',metrics:{sharpeRatio:2,totalReturnPct:5}}];
 assert.deepEqual(rankItems(items,criteria).map(x=>x.id),['b','a','portfolio']);
 criteria=nextRanking(criteria,'calmarRatio');
 assert.deepEqual(criteria,[{key:'calmarRatio',direction:'desc'}]);
 assert.equal(compareRankedMetrics(items[0].metrics,items[1].metrics,[{key:'sharpeRatio',direction:'desc'},{key:'totalReturnPct',direction:'desc'}]),0);
});

test('clicking the active metric reverses direction without adding another metric',()=>{
 const before=nextRanking(initialRanking(),'sharpeRatio'),after=nextRanking(before,'sharpeRatio');
 assert.deepEqual(after,[{key:'sharpeRatio',direction:'asc'}]);
});

test('missing, null and nonfinite values stay last in either direction while zero remains valid',()=>{
 const items=[{id:'missing',metrics:{sharpeRatio:null}},{id:'zero',metrics:{sharpeRatio:0}},{id:'two',metrics:{sharpeRatio:2}},{id:'bad',metrics:{sharpeRatio:NaN}}];
 assert.deepEqual(rankItems(items,[{key:'sharpeRatio',direction:'desc'}]).map(x=>x.id),['two','zero','missing','bad']);
 assert.deepEqual(rankItems(items,[{key:'sharpeRatio',direction:'asc'}]).map(x=>x.id),['zero','two','missing','bad']);
});
test('lower volatility and a less negative drawdown are preferred by default',()=>{
 assert.equal(nextRanking(initialRanking(),'annualizedVolatilityPct')[0].direction,'asc');
 assert.ok(compareRankedMetrics({annualizedVolatilityPct:5},{annualizedVolatilityPct:10},nextRanking(initialRanking(),'annualizedVolatilityPct'))<0);
 assert.ok(compareRankedMetrics({maxDrawdownPct:-5},{maxDrawdownPct:-30},nextRanking(initialRanking(),'maxDrawdownPct'))<0);
});
function body(rows){return {rows:[...rows],querySelectorAll(){return [...this.rows];},append(row){this.rows=this.rows.filter(x=>x!==row);this.rows.push(row);}};}
test('rendered asset and portfolio rows are interleaved by raw metrics',()=>{
 const rows=[{id:'low',dataset:{rankMetrics:JSON.stringify({totalReturnPct:5})}},{id:'high',dataset:{rankMetrics:JSON.stringify({totalReturnPct:20})}},{id:'portfolio',dataset:{rankMetrics:JSON.stringify({totalReturnPct:10})}}];
 const tbody=body(rows);sortMetricRows(tbody,initialRanking());assert.deepEqual(tbody.rows.map(x=>x.id),['high','portfolio','low']);
});
test('overview portfolios use the clicked period and missing periods remain last',()=>{
 const tbody=body([{id:'asset',dataset:{summaryRank:'{"1Y":10,"5Y":100}'}},{id:'portfolio',dataset:{summaryRank:'{"1Y":20,"5Y":80}'}},{id:'missing',dataset:{summaryRank:'{"1Y":null,"5Y":null}'}}]);
 sortSummaryTable(tbody,'1Y','desc');assert.deepEqual(tbody.rows.map(x=>x.id),['portfolio','asset','missing']);
 sortSummaryTable(tbody,'5Y','desc');assert.deepEqual(tbody.rows.map(x=>x.id),['asset','portfolio','missing']);
});
function item(name,ret,sharpe,isPortfolio=false){
 const points=[{date:'2020-01-01',portfolioValue:10000,returnPct:0},{date:'2020-02-01',portfolioValue:10000*(1+ret/100),returnPct:ret}];
 return {dataset:{label:name,data:points.map(p=>p.portfolioValue),metaSeries:{displayName:name,isPortfolio,currency:'EUR',metrics:{sharpeRatio:sharpe,totalReturnPct:999},points},comparisonVariant:'total'},dataIndex:1,raw:points[1].portfolioValue};
}
test('portfolio hover contains the actual portfolio value and hover-date return',()=>{
 const portfolio=item('My portfolio / Portfolio',12.5,1,true),lines=comparisonTooltipLines(portfolio);
 assert.ok(lines[0].includes('My portfolio / Portfolio'));assert.ok(lines[0].includes('12,5')||lines[0].includes('12.5'));
 assert.ok(compareComparisonTooltipItems(portfolio,item('Asset',5,2))<0);
 assert.ok(compareComparisonTooltipItems(portfolio,item('Asset',5,2),nextRanking(initialRanking(),'sharpeRatio'))>0);
});
test('price-only hover never borrows risk metrics from the dividend variant',()=>{
 const price=item('Price only',10,20);price.dataset.comparisonVariant='price';price.dataset.metaSeries.points[1].priceOnlyReturnPct=10;
 assert.equal(tooltipRankingMetrics(price).sharpeRatio,undefined);
 assert.ok(compareComparisonTooltipItems(price,item('Portfolio',5,1,true),nextRanking(initialRanking(),'sharpeRatio'))>0);
});
test('comparison hover is external and portfolio datasets are retained',()=>{
 class FakeChart{static defaults={font:{}};constructor(ctx,config){this.config=config;}destroy(){}}
 const manager=new ChartManager(FakeChart),portfolio=item('Portfolio',10,1,true).dataset.metaSeries;
 const chart=manager.comparison({id:'rank-chart',getContext:()=>({})},[portfolio]);
 assert.equal(chart.config.options.plugins.tooltip.enabled,false);
 assert.equal(typeof chart.config.options.plugins.tooltip.external,'function');
 assert.equal(chart.config.data.datasets[0].metaSeries.isPortfolio,true);
});
test('portfolio overview returns stop at the same comparison end as the asset overview',()=>{
 const points=[];for(let time=Date.parse('2019-12-01');time<=Date.parse('2020-02-01');time+=86400000){const date=new Date(time).toISOString().slice(0,10);points.push({date,portfolioValue:date>='2020-02-01'?220:date>='2020-01-01'?110:100});}
 const entry={market:{frequency:'monthly'},result:{points,calendarValuation:true}};
 const actual=PortfolioLab.prototype.summaryPeriod.call({getSummaryEnd:()=> '2020-01-01'},entry,'1M');
 assert.ok(Math.abs(actual.factor-1.1)<1e-12);assert.equal(actual.end,'2020-01-01');
});
test('English and German ranking rules agree numerically',async()=>{
 const english=await import('../../../../en/dashboards/backtester/assets/js/asset-backtester/ranking.js');
 const items=[{metrics:{calmarRatio:1,totalReturnPct:10}},{metrics:{calmarRatio:2,totalReturnPct:5}}],criteria=nextRanking(initialRanking(),'calmarRatio');
 assert.deepEqual(english.rankItems(items,criteria),rankItems(items,criteria));
});


test('sorting controls belong only to results and do not redraw the overall comparison',async()=>{
 const {readFile}=await import('node:fs/promises');
 for(const locale of ['../../','../../../../en/dashboards/backtester/']){
  const html=await readFile(new URL(locale+'index.html',import.meta.url),'utf8');
  const comparison=html.slice(html.indexOf('aria-labelledby="comparison-title"'),html.indexOf('aria-labelledby="metrics-title"'));
  const results=html.slice(html.indexOf('aria-labelledby="metrics-title"'));
  assert.ok(!comparison.includes('asset-backtester__ranking'));
  assert.ok(!comparison.includes('data-result-rank'));
  assert.ok(!results.includes('asset-backtester__ranking'));
  assert.ok(results.includes('class="asset-backtester__rank-header" data-result-rank="sharpeRatio"'));
  assert.ok(results.includes('class="asset-backtester__rank-header" data-result-rank="totalReturnPct"'));
  const app=await readFile(new URL(locale+'assets/js/asset-backtester/app.js',import.meta.url),'utf8');
  const handler=app.slice(app.indexOf('function installRankingControls()'),app.indexOf('function installComparisonControls()'));
  assert.ok(handler.includes('renderMetrics(state.comparisonData)'));
  assert.ok(!handler.includes('renderComparison('));
  assert.ok(!app.includes('ranking: state.resultRanking'));
 }
});
