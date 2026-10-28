import test from 'node:test';
import assert from 'node:assert/strict';
import {LoadingProgress} from '../../assets/js/asset-backtester/loading.js';
test('loading status disappears without leftover text and becomes visible again on a new request',async()=>{
 const elements={button:{addEventListener(){}},progress:{},'[data-load-text]':{}},badge={dataset:{}};
 const root={ownerDocument:{getElementById:()=>badge},dataset:{},querySelector:key=>elements[key],setAttribute(){}};
 const status=new LoadingProgress(root,['summary','single-gold']);
 assert.equal(root.hidden,false);assert.equal(badge.dataset.loadState,'loading');
 await status.track('summary',()=>true);assert.equal(badge.dataset.loadState,'loading');
 await status.track('single-gold',()=>true);assert.equal(root.hidden,true);assert.equal(elements['[data-load-text]'].textContent,'');assert.equal(badge.dataset.loadState,'ready');
 const token=status.begin('single-gold',()=>{});assert.equal(root.hidden,false);assert.equal(badge.dataset.loadState,'loading');
 status.end('single-gold',token,false);assert.equal(root.hidden,false);assert.equal(badge.dataset.loadState,'error');assert.equal(elements.progress.hidden,true);assert.equal(elements.button.hidden,false);assert.ok(!elements['[data-load-text]'].textContent.includes('wird geladen'));
});
