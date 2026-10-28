import test from 'node:test';
import assert from 'node:assert/strict';
import {RequestQueue,LoadingProgress} from '../../assets/js/asset-backtester/loading.js';
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};};
test('cold requests prioritize the overview and reserve a slot for interaction',async()=>{
 const q=new RequestQueue(),started=[],gates=Array.from({length:7},deferred);
 const jobs=[...Array(5)].map((_,i)=>q.run('b'+i,()=>{started.push(i);return gates[i].promise;},{priority:10}));
 const overview=q.run('summary',()=>{started.push(5);return gates[5].promise;});await tick();
 assert.deepEqual(started,[5,0,1]);gates[5].resolve({ready:true});await overview;await tick();
 assert.equal(started.length,3);
 const click=q.run('click',()=>{started.push(6);return gates[6].promise;});await tick();assert.equal(started.at(-1),6);
 for(const gate of gates)gate.resolve({ready:true});await Promise.all([...jobs,click]);await tick();assert.equal(q.active,0);
});
test('scrolling reprioritizes queued charts while all remaining charts eventually finish',async()=>{
 const q=new RequestQueue({limit:1,backgroundLimit:1}),gate=deferred(),order=[];let visible='first';
 const blocker=q.run('block',()=>gate.promise);
 const jobs=['first','second','third'].map(name=>q.run(name,()=>{order.push(name);return {};},{priority:()=>visible===name?2:10}));
 await tick();visible='third';gate.resolve({});await Promise.all([blocker,...jobs]);assert.deepEqual(order,['third','first','second']);
});
test('identical in-flight requests share network work and return independent objects',async()=>{
 const q=new RequestQueue(),gate=deferred();let calls=0;
 const execute=()=>{calls++;return gate.promise;};const a=q.run('same',execute),b=q.run('same',execute);
 await tick();gate.resolve({series:[{value:42}]});const [left,right]=await Promise.all([a,b]);left.series[0].value=0;
 assert.equal(right.series[0].value,42);const hit=await q.run('same',execute);assert.equal(hit.series[0].value,42);assert.equal(calls,1);
});
test('cancelling one consumer preserves the shared request; cancelling all aborts it',async()=>{
 const q=new RequestQueue(),gate=deferred(),a=new AbortController(),b=new AbortController();let shared;
 const run=signal=>{shared=signal;return gate.promise;};
 const first=q.run('shared',run,{signal:a.signal}),second=q.run('shared',run,{signal:b.signal});await tick();
 const aborted=assert.rejects(first,{name:'AbortError'});a.abort();await aborted;assert.equal(shared.aborted,false);
 gate.resolve({ok:true});assert.deepEqual(await second,{ok:true});
 const c=new AbortController(),pending=q.run('cancel',signal=>new Promise((_,reject)=>{signal.addEventListener('abort',()=>reject(new DOMException('Aborted','AbortError')));} ),{signal:c.signal});await tick();
 const stopped=assert.rejects(pending,{name:'AbortError'});c.abort();await stopped;await tick();assert.equal(q.active,0);
});
test('failed requests can be retried and canceled queued work never starts',async()=>{
 const q=new RequestQueue({limit:1}),gate=deferred();const first=q.run('block',()=>gate.promise);await tick();
 const c=new AbortController();let calls=0;const canceled=q.run('queued',()=>{calls++;return {};},{signal:c.signal});const aborted=assert.rejects(canceled,{name:'AbortError'});c.abort();await aborted;
 gate.resolve({});await first;await assert.rejects(q.run('fail',()=>Promise.reject(Error('offline'))));
 assert.deepEqual(await q.run('fail',()=>({recovered:true})),{recovered:true});assert.equal(calls,0);
});
test('cache expires, bounds memory, and never mixes different parameter keys',async()=>{
 const q=new RequestQueue({ttl:0,maxBytes:50});let calls=0;
 await q.run('EUR',()=>({value:++calls}));await q.run('EUR',()=>({value:++calls}));assert.equal(calls,2);
 const bounded=new RequestQueue({maxBytes:100});await bounded.run('EUR',()=>({v:'EUR'}));await bounded.run('USD',()=>({v:'USD'}));
 assert.equal((await bounded.run('EUR',()=>({v:'wrong'}))).v,'EUR');await bounded.run('large',()=>({v:'x'.repeat(100)}));assert.ok(bounded.bytes<=100);
});
test('an immediate request after a completed uncached request never attaches to a settled job',async()=>{
 const q=new RequestQueue();let calls=0;
 await q.run('key',()=>({n:++calls}),{cache:false});
 assert.deepEqual(await q.run('key',()=>({n:++calls}),{cache:false}),{n:2});
});
test('a request replacing an aborted request with the same key survives old cleanup',async()=>{
 const q=new RequestQueue(),controller=new AbortController(),gate=deferred();
 const first=q.run('same',()=>gate.promise,{signal:controller.signal});await tick();const stopped=assert.rejects(first,{name:'AbortError'});controller.abort();await stopped;
 const second=q.run('same',()=>({fresh:true}));gate.resolve({old:true});assert.deepEqual(await second,{fresh:true});
});
test('completion requires every task, failures stay visible, and retry completes only missing work',async()=>{
 const elements={'button':{hidden:true,addEventListener(){}},'progress':{},'[data-load-text]':{textContent:''}};
 const root={dataset:{},querySelector:key=>elements[key],setAttribute(){}};
 const progress=new LoadingProgress(root,['summary','single-gold'],true);let attempts=0;
 await progress.track('summary',()=>true);await progress.track('single-gold',()=>++attempts>1);
 assert.equal(root.dataset.state,'error');assert.equal(elements.button.hidden,false);assert.equal(elements.progress.value,1);
 progress.retry();await tick();assert.equal(root.dataset.state,'ready');assert.equal(elements['[data-load-text]'].textContent,'');assert.equal(root.hidden,true);assert.equal(attempts,2);
});
test('outdated completion cannot overwrite the newer range being loaded',async()=>{
 const elements={'button':{addEventListener(){}},'progress':{},'[data-load-text]':{}};
 const root={dataset:{},querySelector:key=>elements[key],setAttribute(){}};
 const progress=new LoadingProgress(root,['single-gold']),old=deferred(),current=deferred();
 const a=progress.track('single-gold',()=>old.promise),b=progress.track('single-gold',()=>current.promise);
 old.resolve(false);await a;assert.equal(root.dataset.state,'loading');current.resolve(true);await b;assert.equal(root.dataset.state,'ready');
});
