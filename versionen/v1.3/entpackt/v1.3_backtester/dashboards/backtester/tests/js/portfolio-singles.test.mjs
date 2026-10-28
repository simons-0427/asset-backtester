import test from 'node:test';
import assert from 'node:assert/strict';
import {portfolioSingleRecords,portfolioSingleSeries} from '../../assets/js/asset-backtester/portfolio-singles.js';
const record=(key='saved')=>({key,config:{id:key,name:'Portfolio'},entry:{result:{points:[{date:'2024-01-01',portfolioValue:10000,lastRawObservationDate:'2024-01-01'},{date:'2024-01-02',portfolioValue:11000,lastRawObservationDate:'2024-01-02'},{date:'2024-01-03',portfolioValue:11000,lastRawObservationDate:'2024-01-02',isCarriedForward:true},{date:'2024-01-09',portfolioValue:12100,lastRawObservationDate:'2024-01-09'}]}}});
test('saved portfolios and completed drafts appear independently of comparison selection',()=>{
 const saved={id:'saved',name:'Saved'},draft=record('preview-1');
 const results=new Map([['saved',{...record().entry,config:saved}],['preview-1',{...draft.entry,config:draft.config}]]);
 assert.deepEqual(portfolioSingleRecords([saved],results).map(r=>r.key),['saved','preview-1']);
 assert.equal(portfolioSingleRecords([saved],results).length,2);
});
test('saving a draft replaces its temporary chart and deleting removes the saved chart',()=>{
 const saved={id:'saved',name:'Saved'},results=new Map([['saved',{...record().entry,config:saved}]]);
 assert.deepEqual(portfolioSingleRecords([saved],results).map(r=>r.key),['saved']);
 results.clear();assert.deepEqual(portfolioSingleRecords([],results),[]);
});
test('unavailable saved configurations keep an explicit error without fabricating a curve',()=>{
 const records=portfolioSingleRecords([{id:'saved',name:'Saved'}],new Map(),new Map([['saved','No history']]));
 assert.equal(records[0].error,'No history');assert.equal(portfolioSingleSeries(records[0]),null);
});
test('each portfolio period starts at 10000 EUR and preserves actual dates and carried quotes',()=>{
 const source=record(),original=JSON.stringify(source),series=portfolioSingleSeries(source,'1w');
 assert.equal(series.start,'2024-01-02');assert.equal(series.points[0].portfolioValue,10000);
 assert.equal(series.points[1].portfolioValue,10000);assert.equal(series.points[1].lastRawObservationDate,'2024-01-02');
 assert.ok(Math.abs(series.points.at(-1).portfolioValue-11000)<1e-8);
 assert.equal(JSON.stringify(source),original);
});
test('periods without history are unavailable and a total loss remains visible as zero',()=>{
 const r=record();assert.equal(portfolioSingleSeries(r,'1y'),null);
 r.entry.result.points.at(-1).portfolioValue=0;
 const series=portfolioSingleSeries(r);assert.equal(series.points.at(-1).portfolioValue,0);assert.equal(series.points.at(-1).returnPct,-100);
});
test('German and English portfolio single-chart calculations match',async()=>{
 const english=await import('../../../../en/dashboards/backtester/assets/js/asset-backtester/portfolio-singles.js');
 assert.deepEqual(english.portfolioSingleSeries(record(),'1w'),portfolioSingleSeries(record(),'1w'));
});
