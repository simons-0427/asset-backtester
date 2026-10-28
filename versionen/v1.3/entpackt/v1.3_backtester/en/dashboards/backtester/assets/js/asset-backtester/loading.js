// A spare request slot keeps clicks responsive while full histories load.
export class RequestQueue {
  constructor({limit=3, backgroundLimit=2, ttl=60000, maxBytes=16*1024*1024}={}) {
    Object.assign(this,{limit,backgroundLimit,ttl,maxBytes});
    this.pending=new Map(); this.cache=new Map(); this.active=0; this.backgroundActive=0; this.bytes=0;
  }
  run(key, execute, {signal, priority=0, cache=true}={}) {
    if(signal?.aborted)return Promise.reject(new DOMException('Aborted','AbortError'));
    const hit=this.cache.get(key);
    if(hit && hit.expires>Date.now())return Promise.resolve(structuredClone(hit.value));
    if(hit)this.evict(key);
    let job=this.pending.get(key);
    if(!job){job={key,execute,cache,priority,controller:new AbortController(),consumers:new Set(),started:false};this.pending.set(key,job);}
    else if(this.rank(priority)<this.rank(job.priority))job.priority=priority;
    const result=new Promise((resolve,reject)=>{
      const consumer={resolve,reject,signal,abort:null};
      consumer.abort=()=>{
        job.consumers.delete(consumer); signal?.removeEventListener('abort',consumer.abort);
        reject(new DOMException('Aborted','AbortError'));
        if(!job.consumers.size){job.controller.abort();if(this.pending.get(key)===job)this.pending.delete(key);this.drain();}
      };
      job.consumers.add(consumer);signal?.addEventListener('abort',consumer.abort,{once:true});
    });
    queueMicrotask(()=>this.drain());return result;
  }
  rank(priority){return typeof priority==='function'?priority():priority;}
  evict(key){const entry=this.cache.get(key);if(entry)this.bytes-=entry.bytes;this.cache.delete(key);}
  drain(){
    while(this.active<this.limit){
      const jobs=[...this.pending.values()].filter(j=>!j.started && j.consumers.size)
        .sort((a,b)=>this.rank(a.priority)-this.rank(b.priority));
      const job=jobs.find(j=>this.rank(j.priority)<10 || this.backgroundActive<this.backgroundLimit);
      if(!job)return;
      job.started=true;const background=this.rank(job.priority)>=10;
      this.active++;if(background)this.backgroundActive++;
      Promise.resolve().then(()=>job.execute(job.controller.signal)).then(value=>{
        if(this.pending.get(job.key)===job)this.pending.delete(job.key);
        // Cache a bounded private copy. Callers may transform their response.
        if(job.cache && job.consumers.size){
          const bytes=JSON.stringify(value).length*2;
          if(bytes<=this.maxBytes){
            while(this.cache.size && (this.bytes+bytes>this.maxBytes || this.cache.size>=24))this.evict(this.cache.keys().next().value);
            this.cache.set(job.key,{value:structuredClone(value),bytes,expires:Date.now()+this.ttl});this.bytes+=bytes;
          }
        }
        for(const c of job.consumers){c.signal?.removeEventListener('abort',c.abort);c.resolve(structuredClone(value));}
        job.consumers.clear();
      }).catch(error=>{if(this.pending.get(job.key)===job)this.pending.delete(job.key);for(const c of job.consumers){c.signal?.removeEventListener('abort',c.abort);c.reject(error);}job.consumers.clear();})
      .finally(()=>{if(this.pending.get(job.key)===job)this.pending.delete(job.key);this.active--;if(background)this.backgroundActive--;this.drain();});
    }
  }
}

export const nextPaint=()=>new Promise(resolve=>{
  // The timer also completes in background tabs, where animation frames pause.
  let finished=false;const done=()=>{if(!finished){finished=true;resolve();}};
  if(typeof requestAnimationFrame==='function')requestAnimationFrame(()=>setTimeout(done,0));
  setTimeout(done,80);
});

export function loadChartLibrary() {
  if(globalThis.Chart)return Promise.resolve();
  return new Promise((resolve,reject)=>{
    const script=document.createElement('script');
    script.src=new URL('../../vendor/chart.umd.min.js',import.meta.url).href;
    script.async=true;
    script.onload=()=>{if(globalThis.Chart)resolve();else{script.remove();reject(Error('Chart library is unavailable.'));}};
    script.onerror=()=>{script.remove();reject(Error('Chart library could not be loaded.'));};
    document.head.append(script);
  });
}

export class LoadingProgress {
  constructor(root, ids, english=false){
    this.root=root;this.badge=root.ownerDocument?.getElementById('global-data-status');this.english=english;this.tasks=new Map(ids.map(id=>[id,{status:'pending'}]));
    root.querySelector('button').addEventListener('click',()=>this.retry());this.render();
  }
  begin(id,retry){const token={status:'loading',retry};this.tasks.set(id,token);this.render();return token;}
  end(id,token,success){if(this.tasks.get(id)!==token)return;token.status=success?'ready':'error';this.render();}
  async track(id,run){const token=this.begin(id,()=>this.track(id,run));try{const result=await run();this.end(id,token,result!==false);return result;}catch(error){this.end(id,token,false);throw error;}}
  retry(){for(const task of this.tasks.values())if(task.status==='error')void task.retry?.().catch(()=>{});}
  render(){
    const tasks=[...this.tasks.values()],ready=tasks.filter(t=>t.status==='ready').length,failed=tasks.filter(t=>t.status==='error').length;
    const singles=[...this.tasks].filter(([id])=>id.startsWith('single-'));
    const charts=singles.filter(([,task])=>task.status==='ready').length;
    const complete=ready===tasks.length,overview=this.tasks.get('summary')?.status==='ready';
    const active=tasks.some(task=>task.status==='loading'||task.status==='pending');
    const text=complete?'':!active && failed?(this.english?'Some data could not be loaded.':'Einige Daten konnten nicht geladen werden.'):
      [(overview?(this.english?'Overview ready':'Übersicht bereit'):(this.english?'Loading overview':'Übersicht wird geladen')),
        `${this.english?'Charts':'Einzelverläufe'} ${charts}/${singles.length}`,
        `${this.english?'Completed':'Abgeschlossen'} ${ready}/${tasks.length}`,
        ...(failed?[`${failed} ${this.english?'could not be loaded':'nicht geladen'}`]:[])].join('. ');
    this.root.hidden=complete;
    if(this.badge)this.badge.dataset.loadState=active?'loading':failed?'error':'ready';
    this.root.querySelector('progress').hidden=!active;
    this.root.querySelector('[data-load-text]').textContent=text;
    this.root.querySelector('progress').value=ready;this.root.querySelector('progress').max=tasks.length;
    this.root.querySelector('button').hidden=!failed;this.root.dataset.state=complete?'ready':failed?'error':'loading';
    this.root.setAttribute('aria-busy',String(active));
  }
}
