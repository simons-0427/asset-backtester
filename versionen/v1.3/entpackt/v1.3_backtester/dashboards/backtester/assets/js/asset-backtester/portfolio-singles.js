import { rangeForPreset } from './calculations.js?v=20261028-v13y';
import { escapeHtml } from './formatters.js?v=20261028-v13y';

export const SINGLE_PERIODS=['1w','1m','6m','1y','3y','5y','10y','max'];
export function portfolioSingleRecords(saved,results,errors=new Map()) {
  const records=new Map(saved.map(config=>[config.id,{key:config.id,config,entry:results.get(config.id),error:errors.get(config.id)}]));
  for(const [key,entry] of results)records.set(key,{key,config:entry.config,entry});
  return [...records.values()];
}
export function portfolioSingleSeries(record,preset='max') {
  const source=record.entry?.result.points;
  if(!source?.length || !SINGLE_PERIODS.includes(preset))return null;
  const end=source.at(-1).date,start=rangeForPreset(preset,end,[source[0].date]).start;
  if(start<source[0].date)return null;
  const selected=source.filter(p=>p.date>=start && p.date<=end);
  if(selected.length<2 || !(selected[0].portfolioValue>0))return null;
  const initial=selected[0].portfolioValue;
  return {start:selected[0].date,end,points:selected.map(p=>({...p,portfolioValue:10000*p.portfolioValue/initial,returnPct:100*(p.portfolioValue/initial-1)}))};
}

export class PortfolioSingles {
  constructor(host,language='de') {
    this.host=host;this.english=language==='en';this.periods=new Map();this.charts=[];
    this.money=new Intl.NumberFormat(this.english?'en-GB':'de-DE',{style:'currency',currency:'EUR',maximumFractionDigits:2});
    this.percent=new Intl.NumberFormat(this.english?'en-GB':'de-DE',{maximumFractionDigits:2,signDisplay:'exceptZero'});
    host?.addEventListener('click',event=>{
      const button=event.target.closest('[data-portfolio-single-period]');
      if(!button || button.disabled)return;
      this.periods.set(button.dataset.portfolioKey,button.dataset.portfolioSinglePeriod);this.render(this.records);
    });
  }
  render(records) {
    if(!this.host)return;
    this.records=records;
    const focused=this.host.contains(document.activeElement)?document.activeElement.dataset:null;
    for(const chart of this.charts)chart.destroy();this.charts=[];
    const activeKeys=new Set(records.map(r=>r.key));
    for(const key of this.periods.keys())if(!activeKeys.has(key))this.periods.delete(key);
    this.host.hidden=!records.length;this.host.replaceChildren();
    for(const [index,record] of records.entries()){
      let period=this.periods.get(record.key)||'max',series=portfolioSingleSeries(record,period);
      if(!series && period!=='max'){period='max';series=portfolioSingleSeries(record);this.periods.set(record.key,period);}
      const card=document.createElement('article');card.className='asset-backtester__chart-card';card.dataset.portfolioSingle=record.key;
      const last=series?.points.at(-1),label=this.english?'Saved portfolio':'Gespeichertes Portfolio',temporary=this.english?'Completed portfolio':'Fertig zusammengestelltes Portfolio';
      const saved=!record.key.startsWith('preview-');
      const model=record.entry?.result.taxAdjustment?.applied?(this.english?'After tax, including FIFO and rebalancing tax. Period buttons show a rebased excerpt; acquisition dates remain unchanged.':'Nach Steuern, einschließlich FIFO und Rebalancing-Steuer. Zeitraumwahl zeigt einen normierten Ausschnitt; Anschaffungsdaten bleiben erhalten.'):(this.english?'Daily valuation, including configured leverage and costs.':'Tägliche Bewertung einschließlich eingestellter Hebel und Kosten.');
      card.innerHTML=`<div class="asset-backtester__chart-head"><div><p class="asset-backtester__chart-kicker">${saved?label:temporary}</p><h3>${escapeHtml(record.config.name)}</h3><p class="asset-backtester__proxy">${model}</p></div><div class="asset-backtester__quote"><strong>${last?this.money.format(last.portfolioValue):'–'}</strong><span>${last?this.percent.format(last.returnPct)+' %':'–'}</span></div></div>`;
      if(series){
        const controls=document.createElement('div');controls.className='asset-backtester__intervals';controls.setAttribute('role','group');controls.setAttribute('aria-label',(this.english?'Time range for ':'Zeitraum für ')+record.config.name);
        for(const preset of SINGLE_PERIODS){const button=document.createElement('button');button.type='button';button.className='asset-backtester__interval-button';button.dataset.portfolioSinglePeriod=preset;button.dataset.portfolioKey=record.key;button.textContent=preset==='max'?'Max':preset.toUpperCase();button.disabled=!portfolioSingleSeries(record,preset);button.setAttribute('aria-pressed',String(preset===period));controls.append(button);}
        card.append(controls);
        const frame=document.createElement('div');frame.className='asset-backtester__chart-frame is-loaded';const canvas=document.createElement('canvas');canvas.id='portfolio-single-'+index;canvas.setAttribute('role','img');canvas.setAttribute('aria-label',(this.english?'Historical portfolio value: ':'Historischer Portfoliowert: ')+record.config.name);frame.append(canvas);card.append(frame);
        const foot=document.createElement('div');foot.className='asset-backtester__chart-foot';
        const basis=document.createElement('span');basis.textContent=(this.english?'Starting capital: ':'Startkapital: ')+this.money.format(10000);
        const dates=document.createElement('span');dates.textContent=series.start+' / '+series.end;foot.append(basis,dates);card.append(foot);
        const source=document.createElement('p');source.className='asset-backtester__proxy';source.textContent=(this.english?'Latest source price: ':'Letzter Quellkurs: ')+(last.lastRawObservationDate||record.entry.result.metrics.lastRawObservationDate||'–');card.append(source);
        this.host.append(card);
        if(window.Chart)this.charts.push(new window.Chart(canvas,{type:'line',data:{labels:series.points.map(p=>p.date),datasets:[{label:record.config.name,data:series.points.map(p=>p.portfolioValue),borderColor:'#6ee7b7',backgroundColor:'#6ee7b714',borderWidth:2,pointRadius:0,pointHoverRadius:4,fill:true,tension:0}]},options:{responsive:true,maintainAspectRatio:false,animation:false,interaction:{mode:'index',intersect:false},scales:{x:{ticks:{maxTicksLimit:3,maxRotation:0,minRotation:0}},y:{type:series.points.every(p=>p.portfolioValue>0)?'logarithmic':'linear',afterBuildTicks:axis=>{const project=value=>axis.type==='logarithmic'?Math.log(value):value,spacing=(project(axis.max)-project(axis.min))/4;let previous=-Infinity;axis.ticks=axis.ticks.filter(tick=>{const position=project(tick.value);if(position-previous<spacing)return false;previous=position;return true;});},ticks:{autoSkip:true,maxTicksLimit:5,callback:value=>this.money.format(value)}}},plugins:{legend:{display:false},tooltip:{callbacks:{label:item=>this.money.format(item.raw)+' / '+this.percent.format(series.points[item.dataIndex].returnPct)+' %'}}}}}));
      }else{
        const status=document.createElement('p');status.className='asset-backtester__proxy';status.setAttribute('role','status');status.textContent=record.error || (this.english?'No valid result for the current period. Load and complete this portfolio to calculate its chart.':'Kein gültiges Ergebnis für den aktuellen Zeitraum. Portfolio laden und fertigstellen, um den Verlauf zu berechnen.');card.append(status);this.host.append(card);
      }
    }
    if(focused?.portfolioKey)for(const button of this.host.querySelectorAll('[data-portfolio-single-period]'))if(button.dataset.portfolioKey===focused.portfolioKey && button.dataset.portfolioSinglePeriod===focused.portfolioSinglePeriod)button.focus();
  }
}
